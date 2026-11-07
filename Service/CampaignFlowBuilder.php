<?php

declare(strict_types=1);

namespace MauticPlugin\IntellectItMcpBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CampaignBundle\Entity\Campaign;
use Mautic\CampaignBundle\Entity\Event;
use Mautic\CampaignBundle\EventCollector\EventCollector;
use Mautic\CampaignBundle\Form\Type\EventType;
use Mautic\CampaignBundle\Model\CampaignModel;
use Mautic\CampaignBundle\Model\EventModel;
use Mautic\LeadBundle\Entity\Tag;
use Mautic\LeadBundle\Model\TagModel;
use Mcp\Exception\ToolCallException;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Builds a campaign's event tree (actions, decisions, conditions, waits)
 * from a simple step list, the same way Mautic's campaign builder and API do:
 *
 * - every step is submitted through the step type's own Symfony form, so
 *   properties are validated and normalised exactly as in the UI (works for
 *   plugin-provided step types too);
 * - connections follow the builder's rules (campaignBuilderConnectionsMap,
 *   connectionRestrictions of the step types);
 * - events and canvas are written with CampaignModel::setEvents() and
 *   setCanvasSettings(), so the result opens in the campaign builder.
 */
class CampaignFlowBuilder
{
    private const MAX_STEPS = 100;
    private const UNITS     = ['i', 'h', 'd', 'm', 'y'];

    /** Step types that reach people or systems outside Mautic when executed. */
    private const OUTBOUND_TYPES = [
        'email.send'           => true,
        'email.send.to.user'   => true,
        'message.send'         => true,
        'campaign.sendwebhook' => true,
        'plugin.leadpush'      => true,
        'focus.show'           => true,
        'dwc.push_content'     => true,
    ];

    public function __construct(
        private readonly EventCollector $eventCollector,
        private readonly FormFactoryInterface $formFactory,
        private readonly TranslatorInterface $translator,
        private readonly CampaignModel $campaigns,
        private readonly EventModel $events,
        private readonly ValidatorInterface $validator,
        private readonly EntityManagerInterface $em,
        private readonly TagModel $tags,
    ) {
    }

    /** @return array<string, list<array{type: string, label: string, description: string}>> */
    public function stepTypes(): array
    {
        $out = [];
        foreach ($this->eventCollector->getEventsArray() as $eventType => $types) {
            foreach ($types as $type => $settings) {
                $out[$eventType.'s'][] = [
                    'type'        => (string) $type,
                    'label'       => $this->translator->trans((string) ($settings['label'] ?? $type)),
                    'description' => isset($settings['description']) ? strip_tags($this->translator->trans((string) $settings['description'])) : '',
                ];
            }
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public function describe(string $type): array
    {
        [$eventType, $settings] = $this->settings($type);
        $form                   = $this->form($type, $eventType, $settings, []);

        $fields = [];
        if ($form->has('properties')) {
            foreach ($form->get('properties') as $name => $child) {
                $fields[] = $this->describeField((string) $name, $child);
            }
        }
        $restrictions = $settings['connectionRestrictions'] ?? [];

        return [
            'type'              => $type,
            'kind'              => $eventType,
            'label'             => $this->translator->trans((string) ($settings['label'] ?? $type)),
            'description'       => isset($settings['description']) ? strip_tags($this->translator->trans((string) $settings['description'])) : '',
            'properties'        => $fields,
            'allowed_after'     => $restrictions['source'] ?? null,
            'paths'             => in_array($eventType, ['decision', 'condition'], true) ? ['yes', 'no'] : null,
            'wait'              => 'decision' === $eventType ? 'not applicable (decisions wait for the contact)' : 'optional: {interval, unit} or {at}',
        ];
    }

    /**
     * The campaign's flow in the same format mautic_set_campaign_steps accepts.
     * Keys of existing steps are their Mautic event IDs.
     *
     * @return list<array<string, mixed>>
     */
    public function currentSteps(Campaign $campaign): array
    {
        $events = $this->liveEvents($campaign);
        $stats  = $this->eventStats(array_keys($events));
        // Parents before children (Mautic's order is breadth-first by level).
        uasort($events, fn (Event $a, Event $b): int => [$a->getOrder(), $a->getId()] <=> [$b->getOrder(), $b->getId()]);

        $steps = [];
        foreach ($events as $id => $event) {
            $step = [
                'key'        => (string) $id,
                'type'       => $event->getType(),
                'kind'       => $event->getEventType(),
                'name'       => $event->getName(),
            ];
            if ($event->getParent()) {
                $step['parent'] = (string) $event->getParent()->getId();
                if (null !== $event->getDecisionPath()) {
                    $step['path'] = $event->getDecisionPath();
                }
            }
            if ($wait = $this->waitOf($event)) {
                $step['wait'] = $wait;
            }
            // Empty properties are left out: PHP would encode them as [] (not {}).
            if ([] !== ($properties = $this->tagIdsToNames($event->getType(), $this->settingsOf($event->getType(), $event->getProperties())))) {
                $step['properties'] = $properties;
            }
            $step['contacts']   = $stats[$id] ?? ['executed' => 0, 'scheduled' => 0];
            $steps[]            = $step;
        }

        return $steps;
    }

    /**
     * Apply a complete flow to a campaign as a diff, like Mautic's API (PUT):
     * steps whose key is the ID of an existing step are updated in place and
     * keep their ID and contact history; new keys create steps; existing
     * steps that are left out are deleted (optionally redirecting contacts
     * waiting on them to another step).
     *
     * Published campaigns: steps may be added and changed (name, wait,
     * properties), but not moved; deleting needs $allowDeletions.
     *
     * @param list<array<string, mixed>> $steps
     * @param array<string, string>      $redirects deleted key => key of the step its waiting contacts continue at
     *
     * @return array<string, mixed>
     */
    public function applySteps(Campaign $campaign, array $steps, bool $dryRun = false, bool $allowDeletions = false, array $redirects = []): array
    {
        if ([] === $steps || count($steps) > self::MAX_STEPS) {
            throw McpError::from(sprintf('Provide 1 to %d steps.', self::MAX_STEPS));
        }
        $existing           = $this->liveEvents($campaign);
        $published          = $campaign->isPublished();
        $this->dryRun       = $dryRun;
        $this->tagsToCreate = [];

        // 1. Normalise and check the tree.
        $byKey = [];
        foreach (array_values($steps) as $i => $step) {
            if (!is_array($step)) {
                throw McpError::from(sprintf('Step %d must be an object.', $i + 1));
            }
            $key = trim((string) ($step['key'] ?? ''));
            if ('' === $key || isset($byKey[$key])) {
                throw McpError::from(sprintf('Step %d: "key" must be set and unique.', $i + 1));
            }
            $event = ctype_digit($key) ? ($existing[(int) $key] ?? null) : null;
            if (ctype_digit($key) && null === $event) {
                throw McpError::from(sprintf('Step "%s": there is no step with this ID in the campaign. Use a non-numeric key (e.g. "reminder") for new steps.', $key));
            }
            $type = (string) ($step['type'] ?? $event?->getType() ?? '');
            if (null !== $event && $type !== $event->getType()) {
                throw McpError::from(sprintf('Step "%s": the type of an existing step cannot change (%s -> %s). Remove it and add a new step instead.', $key, $event->getType(), $type));
            }
            [$eventType, $settings] = $this->settings($type);
            $parent                 = isset($step['parent']) && '' !== $step['parent'] ? (string) $step['parent'] : null;
            if (null !== $parent && !isset($byKey[$parent])) {
                throw McpError::from(sprintf('Step "%s": parent "%s" must be listed before it.', $key, $parent));
            }
            $byKey[$key] = ['type' => $type] + $step + [
                '_eventType' => $eventType,
                '_settings'  => $settings,
                '_parent'    => $parent,
                '_event'     => $event,
                '_id'        => null !== $event ? (string) $event->getId() : 'new'.bin2hex(random_bytes(8)),
            ];
        }

        foreach ($byKey as $key => $step) {
            $key = (string) $key; // numeric keys become ints in PHP arrays
            $parent = null !== $step['_parent'] ? $byKey[$step['_parent']] : null;
            $this->checkConnection($key, $step, $parent);

            // Running campaigns: contacts are on their way through the tree;
            // moving steps would change paths under their feet.
            if ($published && null !== $step['_event']) {
                $wasParent = $step['_event']->getParent() ? (string) $step['_event']->getParent()->getId() : null;
                $nowParent = null !== $parent ? $parent['_id'] : null;
                if ($wasParent !== $nowParent || ($step['_event']->getDecisionPath() ?? null) !== ($step['path'] ?? null)) {
                    throw McpError::from(sprintf('Step "%s": steps of a published campaign cannot be moved (parent/path). Change it in a draft copy, or unpublish first.', $key));
                }
            }
        }

        // 2. Submit every step through its form (validation as in the UI).
        $eventData = [];
        $changes   = ['added' => [], 'changed' => [], 'unchanged' => [], 'removed' => []];
        foreach ($byKey as $key => $step) {
            $key = (string) $key; // numeric keys become ints in PHP arrays
            $parent                      = null !== $step['_parent'] ? $byKey[$step['_parent']] : null;
            $data                        = $this->submitStep($campaign, $key, $step, $parent);
            $eventData[$step['_id']]     = $data;
            if (null === $step['_event']) {
                $changes['added'][] = ['key' => $key, 'type' => $step['type'], 'name' => $data['name']];
            } elseif ([] !== ($diff = $this->diff($step['_event'], $data, $parent['_id'] ?? null, $step['path'] ?? null))) {
                $changes['changed'][] = ['key' => $key, 'name' => $data['name'], 'fields' => $diff];
            } else {
                $changes['unchanged'][] = $key;
            }
        }

        // 3. Deleted steps, their waiting contacts and where they continue.
        $deleted = [];
        $stats   = $this->eventStats(array_keys($existing));
        foreach ($existing as $id => $event) {
            if (isset($byKey[(string) $id])) {
                continue;
            }
            $redirect = null;
            if (isset($redirects[(string) $id])) {
                $target = $byKey[$redirects[(string) $id]] ?? null;
                if (null === $target || null === $target['_event']) {
                    throw McpError::from(sprintf('Redirect of step %d: "%s" must be an existing step that is kept.', $id, $redirects[(string) $id]));
                }
                $redirect = (int) $target['_id'];
            }
            $deleted[]             = ['id' => (string) $id, 'redirectEvent' => $redirect];
            $changes['removed'][]  = [
                'key'                => (string) $id,
                'name'               => $event->getName(),
                'contacts_scheduled' => $stats[$id]['scheduled'] ?? 0,
                'contacts_executed'  => $stats[$id]['executed'] ?? 0,
                'redirect_to'        => null !== $redirect ? (string) $redirect : null,
            ];
        }
        $result = [
            'dry_run'   => $dryRun,
            'published' => $published,
            'changes'   => $changes,
        ];
        if ($published && $changes['changed'] !== [] && array_sum(array_map(fn ($c) => (int) ($stats[(int) $c['key']]['scheduled'] ?? 0), $changes['changed'])) > 0) {
            $result['note'] = 'Contacts already scheduled on a changed step keep their scheduled time; the change applies to contacts reaching it from now on.';
        }
        if ($published) {
            $result['live_effects'] = $this->liveEffects($campaign, $byKey, $changes);
        }
        if ([] !== $this->tagsToCreate) {
            $result['tags_to_create'] = array_values(array_unique($this->tagsToCreate));
        }
        if ($dryRun) {
            return $result;
        }
        if ($published && [] !== $deleted && !$allowDeletions) {
            throw McpError::from('This would delete steps of a published campaign: '.json_encode($changes['removed']).'. Check the affected contacts (dry_run) and repeat with allow_deletions=true, optionally with redirects for waiting contacts.');
        }

        // 4. Canvas: sources, nodes and connections the builder understands.
        $sources = [];
        if ($campaign->getLists()->count() > 0) {
            $sources[] = 'lists';
        }
        if ($campaign->getForms()->count() > 0) {
            $sources[] = 'forms';
        }
        if ([] === $sources) {
            throw McpError::from('The campaign has no source. Add segments first (mautic_update_campaign_draft segmentIds).');
        }
        $connections = [];
        foreach ($byKey as $step) {
            if (null === $step['_parent']) {
                foreach ($sources as $source) {
                    $connections[] = ['sourceId' => $source, 'targetId' => $step['_id'], 'anchors' => ['source' => 'leadsource', 'target' => 'top']];
                }
                continue;
            }
            $parent        = $byKey[$step['_parent']];
            $anchor        = 'action' === $parent['_eventType'] ? 'bottom' : (string) $step['path'];
            $connections[] = ['sourceId' => $parent['_id'], 'targetId' => $step['_id'], 'anchors' => ['source' => $anchor, 'target' => 'top']];
        }
        $canvas = ['nodes' => $this->layout($byKey, $sources), 'connections' => $connections];

        // 5. Write, as CampaignApiController does, inside one transaction.
        $conn = $this->em->getConnection();
        $conn->beginTransaction();
        try {
            $this->campaigns->setEvents($campaign, $eventData, $canvas, $deleted);
            foreach ($campaign->getEvents() as $event) {
                $violations = $this->validator->validate($event);
                if (count($violations) > 0) {
                    throw McpError::from(sprintf('Step "%s": %s', $event->getName(), $violations->get(0)->getMessage()));
                }
            }
            $this->campaigns->saveEntity($campaign);
            $this->campaigns->setCanvasSettings($campaign, $canvas);
            if ([] !== $deleted) {
                $this->events->deleteEvents($campaign->getEvents()->toArray(), $deleted);
            }
            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollBack();
            $this->em->clear();
            throw $e instanceof ToolCallException ? $e : McpError::from('Saving the campaign steps failed: '.$e->getMessage());
        }

        $result['steps'] = $this->currentSteps($campaign);

        return $result;
    }

    /** @var array<string, list<string>|null> */
    private array $fieldCache = [];

    /** @var array<string, list<string>> */
    private array $tagFieldCache = [];

    private bool $dryRun = false;

    /** @var list<string> */
    private array $tagsToCreate = [];

    /**
     * Tag fields (Mautic's lead_tag type) of a step type's properties form.
     * The form takes tag IDs and stores tag names. The builder's widget
     * creates missing tags while typing; here names are accepted, resolved
     * to IDs for the form, and missing tags created the same way.
     *
     * @return list<string>
     */
    private function tagFields(string $type): array
    {
        if (!isset($this->tagFieldCache[$type])) {
            $this->tagFieldCache[$type] = [];
            try {
                [$eventType, $settings] = $this->settings($type);
                $form                   = $this->form($type, $eventType, $settings, []);
                if ($form->has('properties')) {
                    foreach ($form->get('properties') as $name => $child) {
                        if ('lead_tag' === $child->getConfig()->getType()->getBlockPrefix()) {
                            $this->tagFieldCache[$type][] = (string) $name;
                        }
                    }
                }
            } catch (ToolCallException) {
            }
        }

        return $this->tagFieldCache[$type];
    }

    /**
     * @param array<string, mixed> $properties
     *
     * @return array<string, mixed>
     */
    private function tagNamesToIds(string $type, array $properties): array
    {
        foreach ($this->tagFields($type) as $field) {
            if (!isset($properties[$field])) {
                continue;
            }
            $ids = [];
            foreach ((array) $properties[$field] as $value) {
                if (is_int($value) || ctype_digit((string) $value)) {
                    $ids[] = (int) $value;
                    continue;
                }
                $name = trim((string) $value);
                if ('' === $name) {
                    continue;
                }
                $existing = $this->em->getRepository(Tag::class)->findOneBy(['tag' => $name]);
                if ($existing instanceof Tag) {
                    $ids[] = (int) $existing->getId();
                } elseif ($this->dryRun) {
                    $this->tagsToCreate[] = $name;
                } else {
                    $tag = $this->tags->getRepository()->getTagByNameOrCreateNewOne($name);
                    $this->tags->getRepository()->saveEntity($tag);
                    $ids[] = (int) $tag->getId();
                }
            }
            $properties[$field] = array_values(array_unique($ids));
        }

        return $properties;
    }

    /**
     * @param array<string, mixed> $properties
     *
     * @return array<string, mixed>
     */
    private function tagIdsToNames(string $type, array $properties): array
    {
        foreach ($this->tagFields($type) as $field) {
            if (!empty($properties[$field]) && is_array($properties[$field])) {
                // Mautic stores tag names (TagEntityModelTransformer); map
                // any numeric IDs from older data as well.
                $names = [];
                foreach ($properties[$field] as $value) {
                    if (is_int($value) || ctype_digit((string) $value)) {
                        $tag     = $this->em->getRepository(Tag::class)->find((int) $value);
                        $names[] = $tag instanceof Tag ? (string) $tag->getTag() : (string) $value;
                    } else {
                        $names[] = (string) $value;
                    }
                }
                $properties[$field] = $names;
            }
        }

        return $properties;
    }

    /**
     * Property field names of a step type's form (null: no properties form).
     * Mautic merges event fields (name, triggerMode, ...) into stored
     * properties when a step is edited; only these names are real settings.
     *
     * @return list<string>|null
     */
    private function propertyFields(string $type): ?array
    {
        if (!array_key_exists($type, $this->fieldCache)) {
            try {
                [$eventType, $settings] = $this->settings($type);
                $form                   = $this->form($type, $eventType, $settings, []);
                $this->fieldCache[$type] = $form->has('properties') ? array_map('strval', array_keys($form->get('properties')->all())) : null;
            } catch (ToolCallException) {
                $this->fieldCache[$type] = null; // type of a disabled plugin
            }
        }

        return $this->fieldCache[$type];
    }

    /**
     * @param array<string, mixed> $properties
     *
     * @return array<string, mixed>
     */
    private function settingsOf(string $type, array $properties): array
    {
        $fields = $this->propertyFields($type);

        return null === $fields ? [] : array_intersect_key($this->cleanProperties($properties), array_flip($fields));
    }

    /**
     * What saving means for a running campaign: Mautic's scheduler executes
     * added and changed steps for the contacts in the campaign, which can
     * send emails or other messages. Reported in dry runs and results.
     *
     * @param array<string, array<string, mixed>>                   $byKey
     * @param array<string, list<array<string, mixed>|string>>      $changes
     *
     * @return array<string, mixed>
     */
    private function liveEffects(Campaign $campaign, array $byKey, array $changes): array
    {
        $prefix   = defined('MAUTIC_TABLE_PREFIX') ? (string) MAUTIC_TABLE_PREFIX : '';
        $contacts = (int) $this->em->getConnection()->fetchOne(
            sprintf('SELECT COUNT(*) FROM %scampaign_leads WHERE campaign_id = ? AND manually_removed = 0', $prefix),
            [$campaign->getId()]
        );
        $outbound = [];
        foreach (['added', 'changed'] as $kind) {
            foreach ($changes[$kind] as $change) {
                $step = $byKey[$change['key']] ?? null;
                if (null !== $step && (self::OUTBOUND_TYPES[$step['type']] ?? false)) {
                    $outbound[] = ['key' => $change['key'], 'type' => $step['type'], 'name' => $change['name'], 'change' => $kind];
                }
            }
        }

        return [
            'contacts_in_campaign' => $contacts,
            'outbound_steps'       => $outbound,
            'warning'              => [] === $outbound
                ? 'The campaign is running: changes apply to its contacts as soon as they are saved.'
                : sprintf('The campaign is running: after saving, Mautic\'s scheduler will execute these steps for contacts reaching them (%d contacts are in the campaign), which sends messages to real recipients. Confirm with the user before saving.', $contacts),
        ];
    }

    /** @return array<int, Event> */
    private function liveEvents(Campaign $campaign): array
    {
        $events = [];
        foreach ($campaign->getEvents() as $event) {
            if (!$event->isDeleted() && $event->getId()) {
                $events[(int) $event->getId()] = $event;
            }
        }

        return $events;
    }

    /**
     * Contacts per step: executed (history) and scheduled (waiting to run).
     *
     * @param list<int> $ids
     *
     * @return array<int, array{executed: int, scheduled: int}>
     */
    private function eventStats(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }
        $prefix = defined('MAUTIC_TABLE_PREFIX') ? (string) MAUTIC_TABLE_PREFIX : '';
        $rows   = $this->em->getConnection()->fetchAllAssociative(
            sprintf('SELECT event_id, SUM(is_scheduled = 0) AS executed, SUM(is_scheduled = 1) AS scheduled FROM %scampaign_lead_event_log WHERE event_id IN (?) GROUP BY event_id', $prefix),
            [$ids],
            [\Doctrine\DBAL\ArrayParameterType::INTEGER]
        );
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['event_id']] = ['executed' => (int) $row['executed'], 'scheduled' => (int) $row['scheduled']];
        }

        return $out;
    }

    /** @return array<string, mixed>|null */
    private function waitOf(Event $event): ?array
    {
        return match ($event->getTriggerMode()) {
            'interval' => ['interval' => (int) $event->getTriggerInterval(), 'unit' => (string) $event->getTriggerIntervalUnit()],
            'date'     => $event->getTriggerDate() ? ['at' => $event->getTriggerDate()->format(DATE_ATOM)] : null,
            default    => null,
        };
    }

    /**
     * @param array<string, mixed> $properties
     *
     * @return array<string, mixed>
     */
    private function cleanProperties(array $properties): array
    {
        // Mautic stores some bookkeeping next to the form values.
        foreach (['properties', 'settings', 'type', 'eventType', 'anchor', 'anchorEventType', 'campaignId', 'buttons', '_token'] as $internal) {
            unset($properties[$internal]);
        }

        return $properties;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<string>
     */
    private function diff(Event $event, array $data, ?string $parentId, ?string $path): array
    {
        $fields = [];
        if ($event->getName() !== $data['name']) {
            $fields[] = 'name';
        }
        $before = $this->waitOf($event);
        $after  = match ($data['triggerMode'] ?? null) {
            'interval' => ['interval' => (int) $data['triggerInterval'], 'unit' => (string) $data['triggerIntervalUnit']],
            'date'     => $data['triggerDate'] instanceof \DateTimeInterface ? ['at' => $data['triggerDate']->format(DATE_ATOM)] : null,
            default    => null,
        };
        if ($before != $after) {
            $fields[] = 'wait';
        }
        if ($this->settingsOf($event->getType(), $event->getProperties()) != $this->settingsOf($event->getType(), (array) ($data['properties'] ?? []))) {
            $fields[] = 'properties';
        }
        $wasParent = $event->getParent() ? (string) $event->getParent()->getId() : null;
        if ($wasParent !== $parentId || $event->getDecisionPath() !== $path) {
            $fields[] = 'position';
        }

        return $fields;
    }

    /** @param array<string, mixed>|null $parent */
    private function checkConnection(string $key, array $step, ?array $parent): void
    {
        $kind = $step['_eventType'];
        $path = $step['path'] ?? null;

        if (null === $parent) {
            if (null !== $path) {
                throw McpError::from(sprintf('Step "%s": "path" is only used after a decision or condition.', $key));
            }
            $allowed = $step['_settings']['connectionRestrictions']['source'] ?? [];
            if ([] !== array_filter($allowed)) {
                throw McpError::from(sprintf('Step "%s" (%s) cannot be the first step; it must follow: %s.', $key, $step['type'], json_encode($allowed)));
            }

            return;
        }

        $parentKind = $parent['_eventType'];
        if (in_array($parentKind, ['decision', 'condition'], true) && !in_array($path, ['yes', 'no'], true)) {
            throw McpError::from(sprintf('Step "%s": after the %s "%s" set "path" to "yes" or "no".', $key, $parentKind, $parent['key']));
        }
        if ('action' === $parentKind && null !== $path) {
            throw McpError::from(sprintf('Step "%s": "path" is only used after a decision or condition.', $key));
        }
        // Mautic's builder: a decision cannot directly follow a decision.
        if ('decision' === $parentKind && 'decision' === $kind) {
            throw McpError::from(sprintf('Step "%s": a decision cannot directly follow the decision "%s". Put an action or condition in between.', $key, $parent['key']));
        }
        // connectionRestrictions of the step types.
        $mustFollow = $step['_settings']['connectionRestrictions']['source'][$parentKind] ?? [];
        if ([] !== $mustFollow && !in_array($parent['type'], $mustFollow, true)) {
            throw McpError::from(sprintf('Step "%s" (%s) can only follow: %s.', $key, $step['type'], implode(', ', $mustFollow)));
        }
        $mayLead = $parent['_settings']['connectionRestrictions']['target'][$kind] ?? [];
        if ([] !== $mayLead && !in_array($step['type'], $mayLead, true)) {
            throw McpError::from(sprintf('Step "%s" (%s) cannot follow "%s" (%s).', $key, $step['type'], $parent['key'], $parent['type']));
        }
        // "No" path of a decision = contact did not act: needs a wait.
        $hasWait = !empty($step['wait']) || (!array_key_exists('wait', $step) && null !== $step['_event'] && null !== $this->waitOf($step['_event']));
        if ('decision' === $parentKind && 'no' === $path && 'decision' !== $kind && !$hasWait) {
            throw McpError::from(sprintf('Step "%s": on the "no" path of a decision set "wait" (how long to wait for the contact), e.g. {"interval": 3, "unit": "d"}.', $key));
        }
    }

    /**
     * @param array<string, mixed>      $step
     * @param array<string, mixed>|null $parent
     *
     * @return array<string, mixed>
     */
    private function submitStep(Campaign $campaign, string $key, array $step, ?array $parent): array
    {
        $kind  = $step['_eventType'];
        /** @var Event|null $event */
        $event = $step['_event'];
        $data  = [
            'type'            => $step['type'],
            'eventType'       => $kind,
            'campaignId'      => $campaign->getId(),
            'anchor'          => $step['path'] ?? ('action' === ($parent['_eventType'] ?? null) ? 'bottom' : 'leadsource'),
            'anchorEventType' => $parent['_eventType'] ?? 'source',
        ];
        if (null !== $event) {
            // Existing step: start from its current values, so omitted
            // fields stay as they are.
            $data += [
                'name'                => $event->getName(),
                'properties'          => $this->settingsOf($event->getType(), $event->getProperties()),
                'triggerMode'         => $event->getTriggerMode(),
                'triggerInterval'     => $event->getTriggerInterval(),
                'triggerIntervalUnit' => $event->getTriggerIntervalUnit(),
                'triggerDate'         => $event->getTriggerDate(),
            ];
        }
        $form = $this->form($step['type'], $kind, $step['_settings'], $data);

        $submit = ['anchor' => $data['anchor']];
        if (array_key_exists('name', $step) || null === $event) {
            $submit['name'] = (string) ($step['name'] ?? '');
        }
        $properties = $this->tagNamesToIds($step['type'], is_array($step['properties'] ?? null) ? $step['properties'] : []);
        if ($form->has('properties')) {
            // Values echoed back from mautic_get_campaign that the form does not
            // know (Mautic bookkeeping) are dropped if unchanged.
            $stored = null !== $event ? $this->cleanProperties($event->getProperties()) : [];
            foreach ($properties as $name => $value) {
                if (!$form->get('properties')->has((string) $name) && array_key_exists($name, $stored) && $stored[$name] == $value) {
                    unset($properties[$name]);
                }
            }
            $submit['properties'] = $properties;
        } elseif ([] !== array_diff_key($properties, null !== $event ? $this->cleanProperties($event->getProperties()) : [])) {
            throw McpError::from(sprintf('Step "%s": %s has no properties.', $key, $step['type']));
        }

        if (in_array($kind, ['action', 'condition'], true) && (array_key_exists('wait', $step) || null === $event)) {
            $wait = is_array($step['wait'] ?? null) ? $step['wait'] : [];
            if (isset($wait['at'])) {
                $at = date_create_immutable((string) $wait['at']);
                if (false === $at) {
                    throw McpError::from(sprintf('Step "%s": wait.at is not a valid date.', $key));
                }
                $submit += ['triggerMode' => 'date', 'triggerDate' => $at->format('Y-m-d H:i')];
            } elseif (isset($wait['interval'])) {
                $unit = (string) ($wait['unit'] ?? 'd');
                if (!in_array($unit, self::UNITS, true) || (int) $wait['interval'] < 1) {
                    throw McpError::from(sprintf('Step "%s": wait needs interval >= 1 and unit one of i (minutes), h, d, m (months), y.', $key));
                }
                $submit += ['triggerMode' => 'interval', 'triggerInterval' => (int) $wait['interval'], 'triggerIntervalUnit' => $unit];
            } else {
                $submit += ['triggerMode' => 'immediate'];
            }
            // Like the builder, always post interval fields (defaults 1 d);
            // some handlers read them even for immediate steps.
            $submit += [
                'triggerInterval'     => (int) ($data['triggerInterval'] ?? 0) ?: 1,
                'triggerIntervalUnit' => (string) ($data['triggerIntervalUnit'] ?? '') ?: 'd',
            ];
        }
        // Only fields this step type's form has (e.g. decisions have no wait).
        $submit = array_filter($submit, fn (string $field): bool => $form->has($field), ARRAY_FILTER_USE_KEY);

        $form->submit($submit, false);
        if (!$form->isValid()) {
            $errors = [];
            foreach ($form->getErrors(true) as $error) {
                $origin   = $error->getOrigin();
                $errors[] = ($origin ? $origin->getName().': ' : '').$error->getMessage();
            }
            throw McpError::from(sprintf('Step "%s" (%s) is invalid: %s. Use mautic_campaign_step_types for its properties.', $key, $step['type'], implode('; ', array_unique($errors))));
        }

        $result = array_merge($data, (array) $form->getData());
        if ($form->has('properties')) {
            // The builder posts every field; empty multi-selects arrive as []
            // and event handlers rely on that (e.g. campaign.addremovelead).
            foreach ($form->get('properties') as $name => $child) {
                $config = $child->getConfig();
                if ($config->hasOption('multiple') && $config->getOption('multiple') && null === ($result['properties'][$name] ?? null)) {
                    $result['properties'][$name] = [];
                }
            }
            $result['properties'] = $this->settingsOf($step['type'], (array) ($result['properties'] ?? []));
        }
        foreach (['anchor', 'anchorEventType', 'campaignId', 'buttons', 'canvasSettings', 'settings'] as $drop) {
            unset($result[$drop]);
        }
        if ('' === trim((string) ($result['name'] ?? ''))) {
            $result['name'] = $this->translator->trans((string) ($step['_settings']['label'] ?? $step['type']));
        }
        $result['id'] = $step['_id'];

        return $result;
    }

    /** @return array{0: string, 1: array<string, mixed>} */
    private function settings(string $type): array
    {
        foreach ($this->eventCollector->getEventsArray() as $eventType => $types) {
            if (isset($types[$type])) {
                return [(string) $eventType, $types[$type]];
            }
        }

        throw McpError::from(sprintf('Unknown step type "%s". See mautic_campaign_step_types.', $type));
    }

    /**
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $data
     */
    private function form(string $type, string $eventType, array $settings, array $data): FormInterface
    {
        return $this->formFactory->create(EventType::class, $data + ['type' => $type, 'eventType' => $eventType], [
            'settings'        => $settings,
            'csrf_protection' => false,
        ]);
    }

    /** @return array<string, mixed> */
    private function describeField(string $name, FormInterface $field): array
    {
        $config  = $field->getConfig();
        $out     = [
            'name'     => $name,
            'label'    => is_string($label = $config->getOption('label')) ? $this->translator->trans($label) : null,
            'kind'     => $config->getType()->getBlockPrefix(),
            'required' => (bool) $config->getOption('required'),
        ];
        if ($config->hasOption('multiple') && $config->getOption('multiple')) {
            $out['multiple'] = true;
        }
        if ('lead_tag' === $out['kind']) {
            $out['hint'] = 'Tag names, e.g. ["customer", "newsletter"]; missing tags are created.';
        }
        if ($config->hasOption('choices') && is_array($choices = $config->getOption('choices'))) {
            $flat = [];
            array_walk_recursive($choices, function ($value, $label) use (&$flat): void {
                $flat[] = ['value' => $value, 'label' => is_string($label) ? $this->translator->trans($label) : (string) $label];
            });
            $out['choices'] = array_slice($flat, 0, 50);
            if (count($flat) > 50) {
                $out['choices_truncated'] = count($flat);
            }
        }
        if (count($field) > 0) {
            $out['fields'] = [];
            foreach ($field as $childName => $child) {
                $out['fields'][] = $this->describeField((string) $childName, $child);
            }
        }

        return $out;
    }

    /**
     * Tree layout for the builder canvas: one row per depth, leaves spread
     * horizontally, parents centred above their children.
     *
     * @param array<string, array<string, mixed>> $byKey
     * @param list<string>                        $sources
     *
     * @return list<array{id: string, positionX: string, positionY: string}>
     */
    private function layout(array $byKey, array $sources): array
    {
        $children = [];
        foreach ($byKey as $key => $step) {
            $key = (string) $key; // numeric keys become ints in PHP arrays
            $children[$step['_parent'] ?? ''][] = (string) $key;
        }
        $x    = [];
        $next = 0;
        $place = function (string $key, int $depth) use (&$place, &$x, &$next, $children): array {
            $xs = [];
            foreach ($children[$key] ?? [] as $child) {
                $xs[] = $place((string) $child, $depth + 1)[0];
            }
            $x[$key] = [[] === $xs ? $next++ : (min($xs) + max($xs)) / 2, $depth];

            return $x[$key];
        };
        $rootXs = [];
        foreach ($children[''] ?? [] as $root) {
            $rootXs[] = $place((string) $root, 1)[0];
        }

        $nodes  = [];
        $center = [] === $rootXs ? 0 : (min($rootXs) + max($rootXs)) / 2;
        foreach ($sources as $i => $source) {
            $nodes[] = ['id' => $source, 'positionX' => (string) (int) (100 + ($center + $i) * 320), 'positionY' => '20'];
        }
        foreach ($byKey as $key => $step) {
            $key = (string) $key; // numeric keys become ints in PHP arrays
            [$col, $depth] = $x[$key];
            $nodes[]       = ['id' => $step['_id'], 'positionX' => (string) (int) (100 + $col * 320), 'positionY' => (string) (20 + $depth * 160)];
        }

        return $nodes;
    }
}

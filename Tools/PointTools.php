<?php

declare(strict_types=1);

namespace MauticPlugin\IntellectItMcpBundle\Tools;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\CoreBundle\Helper\IpLookupHelper;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Entity\PointsChangeLog;
use Mautic\LeadBundle\Model\LeadModel;
use Mautic\PointBundle\Entity\Group;
use Mautic\PointBundle\Entity\GroupContactScore;
use Mautic\PointBundle\Entity\Point;
use Mautic\PointBundle\Entity\Trigger;
use Mautic\PointBundle\Entity\TriggerEvent;
use Mautic\PointBundle\Form\Type\TriggerEventType;
use Mautic\PointBundle\Model\PointGroupModel;
use Mautic\PointBundle\Model\PointModel;
use Mautic\PointBundle\Model\TriggerModel;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Server\RequestContext;
use MauticPlugin\IntellectItMcpBundle\Service\FormDescriber;
use MauticPlugin\IntellectItMcpBundle\Service\McpScopes;
use MauticPlugin\IntellectItMcpBundle\Service\McpUserContext;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

const TRIGGER_EVENTS_SCHEMA = [
    'type'     => 'array',
    'maxItems' => 20,
    'items'    => [
        'type'                 => 'object',
        'required'             => ['type'],
        'additionalProperties' => false,
        'properties'           => [
            'key'        => ['type' => 'string', 'description' => 'ID of an existing event of this trigger (from list_point_triggers) to keep or change it; omit for new events'],
            'type'       => ['type' => 'string', 'description' => 'Event type from list_point_trigger_event_types, e.g. "lead.changetags", "email.send_to_user"'],
            'name'       => ['type' => 'string'],
            'properties' => ['type' => ['object', 'array'], 'description' => 'Settings, see describe_point_trigger_event_type'],
        ],
    ],
];

/**
 * Lead scoring: point groups, point actions (what earns points), point
 * triggers (what happens at a score) and contact points.
 *
 * Like emails and campaigns, new actions and triggers are created
 * unpublished; publishing stays with a person in Mautic. Published triggers
 * can send emails and push contacts to other systems as soon as contacts
 * reach the score, so changing one needs the opt-in scope
 * mcp:campaigns:live (and the instance switch for live edits); the result
 * and a dryRun report live_effects. Changing a contact's points can fire
 * published triggers right away; dryRun says which.
 */
class PointTools
{
    /** Trigger events that reach the contact or other systems. */
    private const OUTBOUND_EVENTS = ['email.send', 'email.send_to_user', 'plugin.leadpush', 'campaign.changecampaign'];

    public function __construct(
        private readonly McpUserContext $auth,
        private readonly PointModel $points,
        private readonly TriggerModel $triggers,
        private readonly PointGroupModel $groups,
        private readonly LeadModel $leads,
        private readonly FormFactoryInterface $formFactory,
        private readonly FormDescriber $describer,
        private readonly TranslatorInterface $translator,
        private readonly IpLookupHelper $ipLookup,
        private readonly EntityManagerInterface $em,
        private readonly Connection $connection,
        private readonly CoreParametersHelper $parameters,
    ) {
    }

    // ---------------------------------------------------------------- groups

    /**
     * List point groups (separate scores per topic, e.g. "Product interest"), with how many contacts have a score in each.
     *
     * @return array<string, mixed>
     */
    public function listPointGroups(RequestContext $context): array
    {
        $this->auth->require($context, ['point:groups:view']);
        $counts = [];
        foreach ($this->connection->fetchAllAssociative(sprintf('SELECT group_id, COUNT(*) n, MAX(score) max_score FROM %s%s GROUP BY group_id', $this->prefix(), GroupContactScore::TABLE_NAME)) as $r) {
            $counts[(int) $r['group_id']] = ['contacts' => (int) $r['n'], 'max_score' => (int) $r['max_score']];
        }

        return ['groups' => array_map(fn (Group $g): array => [
            'id'          => $g->getId(),
            'name'        => $g->getName(),
            'description' => $g->getDescription(),
            'published'   => $g->isPublished(),
        ] + ($counts[(int) $g->getId()] ?? ['contacts' => 0, 'max_score' => 0]), $this->all($this->groups))];
    }

    /**
     * Create a point group. Point actions, triggers and contact points can then use it.
     *
     * @return array<string, mixed>
     */
    public function createPointGroup(RequestContext $context, #[Schema(minLength: 1, maxLength: 191)] string $name, string $description = ''): array
    {
        $this->auth->require($context, 'point:groups:create');
        $group = new Group();
        $group->setName($name);
        $group->setDescription('' !== $description ? $description : null);
        $group->setIsPublished(true);
        $this->groups->saveEntity($group);
        $this->auth->audit('point_group', (int) $group->getId(), 'create', ['name' => $name]);

        return ['id' => $group->getId(), 'name' => $group->getName(), 'message' => 'Point group created.'];
    }

    /**
     * Rename a point group or change its description.
     *
     * @return array<string, mixed>
     */
    public function updatePointGroup(RequestContext $context, int $id, ?string $name = null, ?string $description = null): array
    {
        $this->auth->require($context, 'point:groups:edit');
        $group = $this->groups->getEntity($id);
        if (!$group instanceof Group || !$group->getId()) {
            throw new ToolCallException(sprintf('Point group %d not found.', $id));
        }
        if (null !== $name) {
            $group->setName($name);
        }
        if (null !== $description) {
            $group->setDescription($description);
        }
        $this->groups->saveEntity($group);
        $this->auth->audit('point_group', $id, 'update', array_keys(array_filter(['name' => $name, 'description' => $description], fn ($v) => null !== $v)));

        return ['id' => $id, 'name' => $group->getName(), 'message' => 'Point group saved.'];
    }

    // --------------------------------------------------------------- actions

    /**
     * List the point action types of this Mautic (what can earn points: email opens, page visits, form submissions, downloads, ... including plugins).
     *
     * @return array<string, mixed>
     */
    public function listPointActionTypes(RequestContext $context): array
    {
        $this->auth->require($context, ['point:points:view']);
        $types = [];
        foreach ($this->points->getPointActions()['actions'] ?? [] as $type => $def) {
            $types[] = array_filter([
                'type'         => $type,
                'label'        => $this->trans($def['label'] ?? $type),
                'description'  => isset($def['description']) ? strip_tags($this->trans($def['description'])) : null,
                'group'        => isset($def['group']) ? $this->trans($def['group']) : null,
                'has_settings' => !empty($def['formType']),
            ], fn ($v) => null !== $v);
        }

        return ['types' => $types];
    }

    /**
     * Settings of a point action type (field names, required, choices), for create_point_action / update_point_action.
     *
     * @return array<string, mixed>
     */
    public function describePointActionType(RequestContext $context, string $type): array
    {
        $this->auth->require($context, ['point:points:view']);
        $def  = $this->actionDef($type);
        $form = $this->actionForm((new Point())->setType($type));

        return [
            'type'        => $type,
            'label'       => $this->trans($def['label'] ?? $type),
            'description' => isset($def['description']) ? strip_tags($this->trans($def['description'])) : '',
            'properties'  => $form->has('properties') ? $this->describer->fields($form->get('properties')) : [],
        ];
    }

    /**
     * List point actions (what earns how many points), with type, points, group and settings.
     *
     * @return array<string, mixed>
     */
    public function listPointActions(RequestContext $context, string $search = ''): array
    {
        $this->auth->require($context, ['point:points:view']);

        return ['actions' => array_map(fn (Point $p): array => $this->actionSummary($p), $this->all($this->points, $search))];
    }

    /**
     * Create an unpublished point action: contacts get `delta` points (negative to subtract) when the action happens, optionally in a point group. A person publishes it in Mautic.
     *
     * @param array<string, mixed> $properties Settings of the type (describe_point_action_type)
     * @param bool                 $repeatable Award the points every time, not only once per contact
     *
     * @return array<string, mixed>
     */
    public function createPointAction(
        RequestContext $context,
        #[Schema(minLength: 1, maxLength: 191)] string $name,
        string $type,
        int $delta,
        #[Schema(definition: ['type' => ['object', 'array'], 'description' => 'Settings of the type (an object), see describe_point_action_type'])] array $properties = [],
        ?int $groupId = null,
        bool $repeatable = false,
        string $description = '',
    ): array {
        $this->auth->require($context, 'point:points:create');
        $this->actionDef($type);
        $point = (new Point())->setType($type);
        $this->submitAction($point, [
            'name'        => $name,
            'description' => $description,
            'type'        => $type,
            'delta'       => $delta,
            'repeatable'  => $repeatable ? '1' : '0',
            'group'       => null !== $groupId ? (string) $this->group($groupId)->getId() : '',
            'properties'  => $properties,
            'isPublished' => '0',
        ]);
        $point->setIsPublished(false);
        $this->points->saveEntity($point);
        $this->auth->audit('point_action', (int) $point->getId(), 'create', ['type' => $type, 'delta' => $delta]);

        return $this->actionSummary($point) + ['message' => 'Point action created (unpublished). Publish it in Mautic to start scoring.', 'url' => $this->url('/s/points/edit/'.$point->getId())];
    }

    /**
     * Change a point action (name, points, settings, group, repeatable). Published actions change scoring from now on (no emails are sent by this).
     *
     * @param array<string, mixed>|null $properties Replace the settings
     *
     * @return array<string, mixed>
     */
    public function updatePointAction(
        RequestContext $context,
        int $id,
        ?string $name = null,
        ?int $delta = null,
        #[Schema(definition: ['type' => ['object', 'array', 'null'], 'description' => 'Replace the settings (an object)'])] ?array $properties = null,
        ?int $groupId = null,
        ?bool $repeatable = null,
        ?string $description = null,
    ): array {
        $this->auth->require($context, 'point:points:edit');
        $point = $this->points->getEntity($id);
        if (!$point instanceof Point || !$point->getId()) {
            throw new ToolCallException(sprintf('Point action %d not found.', $id));
        }
        $data = array_filter([
            'name'        => $name,
            'description' => $description,
            'delta'       => $delta,
            'repeatable'  => null === $repeatable ? null : ($repeatable ? '1' : '0'),
            'group'       => null === $groupId ? null : (0 === $groupId ? '' : (string) $this->group($groupId)->getId()),
            'properties'  => $properties,
        ], fn ($v) => null !== $v);
        if ([] === $data) {
            throw new ToolCallException('Nothing to change.');
        }
        $this->submitAction($point, $data);
        $this->points->saveEntity($point);
        $this->auth->audit('point_action', $id, 'update', ['fields' => array_keys($data), 'published' => $point->isPublished()]);

        return $this->actionSummary($point) + ['message' => $point->isPublished() ? 'Point action saved; it is published, so scoring changes from now on.' : 'Point action saved (unpublished).'];
    }

    // -------------------------------------------------------------- triggers

    /**
     * List the trigger event types (what can happen when a contact reaches a score: change tags, segments, stage, add to a campaign, send an email, push to an integration, ...). "outbound" marks events that reach the contact or other systems.
     *
     * @return array<string, mixed>
     */
    public function listPointTriggerEventTypes(RequestContext $context): array
    {
        $this->auth->require($context, ['point:triggers:view']);
        $types = [];
        foreach ($this->triggers->getEvents() as $type => $def) {
            $types[] = array_filter([
                'type'        => $type,
                'label'       => $this->trans($def['label'] ?? $type),
                'description' => isset($def['description']) ? strip_tags($this->trans($def['description'])) : null,
                'group'       => isset($def['group']) ? $this->trans($def['group']) : null,
            ], fn ($v) => null !== $v && '' !== $v) + ['outbound' => in_array($type, self::OUTBOUND_EVENTS, true)];
        }

        return ['types' => $types];
    }

    /**
     * Settings of a trigger event type, for create_point_trigger / update_point_trigger.
     *
     * @return array<string, mixed>
     */
    public function describePointTriggerEventType(RequestContext $context, string $type): array
    {
        $this->auth->require($context, ['point:triggers:view']);
        $def  = $this->eventDef($type);
        $form = $this->eventForm($type, $def, []);

        return [
            'type'        => $type,
            'label'       => $this->trans($def['label'] ?? $type),
            'description' => isset($def['description']) ? strip_tags($this->trans($def['description'])) : '',
            'outbound'    => in_array($type, self::OUTBOUND_EVENTS, true),
            'properties'  => $form->has('properties') ? $this->describer->fields($form->get('properties')) : [],
        ];
    }

    /**
     * List point triggers with their score threshold, group and events.
     *
     * @return array<string, mixed>
     */
    public function listPointTriggers(RequestContext $context, string $search = ''): array
    {
        $this->auth->require($context, ['point:triggers:view']);

        return ['triggers' => array_map(fn (Trigger $t): array => $this->triggerSummary($t), $this->all($this->triggers, $search))];
    }

    /**
     * Create an unpublished point trigger: when a contact reaches `points` (in total, or in a point group), its events run. A person publishes it in Mautic. With triggerExistingLeads, publishing it runs the events for contacts that are already at the score.
     *
     * @param list<array<string, mixed>> $events Events in order
     *
     * @return array<string, mixed>
     */
    public function createPointTrigger(
        RequestContext $context,
        #[Schema(minLength: 1, maxLength: 191)] string $name,
        int $points,
        #[Schema(definition: TRIGGER_EVENTS_SCHEMA)] array $events,
        ?int $groupId = null,
        bool $triggerExistingLeads = false,
        string $description = '',
    ): array {
        $this->auth->require($context, 'point:triggers:create');
        if ([] === $events) {
            throw new ToolCallException('A trigger needs at least one event.');
        }
        $trigger = new Trigger();
        $trigger->setName($name);
        $trigger->setDescription('' !== $description ? $description : null);
        $trigger->setPoints($points);
        $trigger->setTriggerExistingLeads($triggerExistingLeads);
        $trigger->setColor('a0acb8');
        if (null !== $groupId) {
            $trigger->setGroup($this->group($groupId));
        }
        $trigger->setIsPublished(false);
        $this->applyEvents($trigger, $events);
        $this->triggers->saveEntity($trigger);
        $this->auth->audit('point_trigger', (int) $trigger->getId(), 'create', ['points' => $points, 'events' => array_column($events, 'type')]);

        return $this->triggerSummary($trigger) + [
            'message' => 'Point trigger created (unpublished). Publish it in Mautic to activate it.'.($triggerExistingLeads ? ' On publishing, its events run for the '.$this->contactsAtScore($trigger).' contact(s) already at the score.' : ''),
            'url'     => $this->url('/s/points/triggers/edit/'.$trigger->getId()),
        ];
    }

    /**
     * Change a point trigger: name, threshold, group, events (replace the list; keep existing events by their key). A PUBLISHED trigger is live: contacts reaching the score get its events (emails, pushes), and with triggerExistingLeads new events run at once for contacts already at the score. That needs the scope mcp:campaigns:live: call with dryRun first and show the user live_effects.
     *
     * @param list<array<string, mixed>>|null $events Replace the events
     *
     * @return array<string, mixed>
     */
    public function updatePointTrigger(
        RequestContext $context,
        int $id,
        ?string $name = null,
        ?int $points = null,
        #[Schema(definition: ['anyOf' => [TRIGGER_EVENTS_SCHEMA, ['type' => 'null']]])] ?array $events = null,
        ?int $groupId = null,
        ?bool $triggerExistingLeads = null,
        ?string $description = null,
        bool $dryRun = false,
    ): array {
        $this->auth->require($context, 'point:triggers:edit');
        $trigger = $this->triggers->getEntity($id);
        if (!$trigger instanceof Trigger || !$trigger->getId()) {
            throw new ToolCallException(sprintf('Point trigger %d not found.', $id));
        }

        $block = null;
        if ($trigger->isPublished()) {
            if (!$this->parameters->get('intellectit_mcp_live_campaign_edits', true)) {
                $block = 'Changes to published (live) items are disabled on this Mautic instance (mautic.mcp.liveCampaignEdits). Unpublish the trigger in Mautic first.';
            } elseif (!$this->auth->hasScope($context, McpScopes::CAMPAIGNS_LIVE)) {
                $block = 'This trigger is published. Changing it needs the permission "mcp:campaigns:live" (changes are live and may send emails). Reconnect and tick it on the consent page, or unpublish the trigger in Mautic first.';
            }
        }

        $before = $this->triggerSummary($trigger);
        if ($dryRun || null !== $block) {
            // Validate the change without saving it.
            $this->em->detach($trigger);
        }
        if (null !== $name) {
            $trigger->setName($name);
        }
        if (null !== $description) {
            $trigger->setDescription($description);
        }
        if (null !== $points) {
            $trigger->setPoints($points);
        }
        if (null !== $triggerExistingLeads) {
            $trigger->setTriggerExistingLeads($triggerExistingLeads);
        }
        if (null !== $groupId && 0 !== $groupId) {
            $trigger->setGroup($this->group($groupId));
        }
        $newTypes = [];
        if (null !== $events) {
            $newTypes = $this->applyEvents($trigger, $events, $dryRun || null !== $block);
        }

        $live = $before['published'] ? [
            'save_allowed'            => null === $block,
            'outbound_events'         => array_values(array_filter(array_column(null !== $events ? $events : $before['events'], 'type'), fn ($t) => in_array($t, self::OUTBOUND_EVENTS, true))),
            'contacts_at_score'       => $this->contactsAtScore($trigger),
            'new_events_run_now_for'  => ($trigger->getTriggerExistingLeads() && [] !== $newTypes) ? $this->contactsAtScore($trigger) : 0,
            'warning'                 => 'The trigger is published: contacts reaching the score get its events from now on'.($trigger->getTriggerExistingLeads() ? ', and new events run on save for contacts already at the score' : '').'. Confirm with the user before saving.',
        ] + (null !== $block ? ['save_blocked_because' => $block] : []) : null;

        if ($dryRun) {
            return ['dry_run' => true, 'message' => 'Dry run, nothing saved.', 'before' => $before, 'after' => $this->describeTrigger($trigger, $events)] + (null !== $live ? ['live_effects' => $live] : []);
        }
        if (null !== $block) {
            throw new ToolCallException($block);
        }

        $this->triggers->saveEntity($trigger);
        $this->auth->audit('point_trigger', $id, 'update', ['published' => $trigger->isPublished(), 'events' => null !== $events ? array_column($events, 'type') : null]);

        return $this->triggerSummary($trigger) + ['message' => $trigger->isPublished() ? 'Trigger saved; it is published, the changes are live.' : 'Trigger saved (unpublished).'] + (null !== $live ? ['live_effects' => $live] : []);
    }

    // ------------------------------------------------------- contact points

    /**
     * Change a contact's points: add, subtract or set, in total or in a point group, with a reason in the points history. This can fire PUBLISHED point triggers right away (emails, pushes): call with dryRun first, it lists the triggers the change would fire.
     *
     * @param string   $operator "add", "subtract" or "set"
     * @param int|null $groupId  Point group (default: the contact's total points)
     * @param string   $reason   Shown in the contact's points history
     *
     * @return array<string, mixed>
     */
    public function adjustContactPoints(
        RequestContext $context,
        int $contactId,
        int $points,
        #[Schema(enum: ['add', 'subtract', 'set'])] string $operator = 'add',
        ?int $groupId = null,
        #[Schema(maxLength: 191)] string $reason = 'Changed via MCP',
        bool $dryRun = false,
    ): array {
        $lead = $this->leads->getEntity($contactId);
        if (!$lead instanceof Lead || !$lead->getId()) {
            throw new ToolCallException(sprintf('Contact %d not found.', $contactId));
        }
        $this->auth->requireEntityAccess($context, $lead, 'lead:leads', 'edit');
        $group = null !== $groupId ? $this->group($groupId) : null;

        $old = null !== $group ? (int) ($lead->getGroupScore($group)?->getScore() ?? 0) : (int) $lead->getPoints();
        $new = match ($operator) {
            'add'      => $old + $points,
            'subtract' => $old - $points,
            'set'      => $points,
            default    => throw new ToolCallException('operator must be add, subtract or set.'),
        };
        $fires = $new > $old ? $this->triggersCrossed($lead, $group, $old, $new) : [];

        $result = [
            'contact'  => ['id' => $lead->getId(), 'email' => $lead->getEmail()],
            'group'    => null !== $group ? ['id' => $group->getId(), 'name' => $group->getName()] : null,
            'points'   => ['before' => $old, 'after' => $new],
            'triggers' => $fires,
        ];
        if ($dryRun) {
            return ['dry_run' => true, 'message' => 'Dry run, nothing saved.'.([] !== $fires ? ' '.count($fires).' published trigger(s) would fire.' : '')] + $result;
        }
        if ($new === $old) {
            return ['message' => sprintf('Points unchanged (%d).', $old)] + $result;
        }

        // Lead::adjustPoints() has no "set"; apply every change as a delta.
        $delta          = abs($new - $old);
        $mauticOperator = $new > $old ? Lead::POINTS_ADD : Lead::POINTS_SUBTRACT;
        $log            = new PointsChangeLog();
        $log->setType('api');
        $log->setEventName('MCP');
        $log->setActionName(mb_substr($reason, 0, 191));
        $log->setIpAddress($this->ipLookup->getIpAddress());
        $log->setDateAdded(new \DateTime());
        $log->setLead($lead);
        if (null !== $group) {
            $this->groups->adjustPoints($lead, $group, $delta, $mauticOperator);
            $log->setGroup($group);
        } else {
            $lead->adjustPoints($delta, $mauticOperator);
        }
        $log->setDelta($new - $old);
        $lead->addPointsChangeLog($log);
        $this->leads->saveEntity($lead);
        $this->auth->audit('lead', $contactId, 'points', ['operator' => $operator, 'points' => $points, 'group' => $group?->getId(), 'before' => $old, 'after' => $new]);

        return ['message' => sprintf('Points %d -> %d.%s', $old, $new, [] !== $fires ? ' '.count($fires).' published trigger(s) fired.' : '')] + $result;
    }

    // --------------------------------------------------------------- helpers

    /** @param array<string, mixed> $data */
    private function submitAction(Point $point, array $data): void
    {
        $form = $this->actionForm($point);
        if ($form->has('properties')) {
            $props              = array_key_exists('properties', $data) ? (array) $data['properties'] : (array) ($point->getProperties() ?? []);
            $data['properties'] = $this->tagNamesToIds($form->get('properties'), $props);
            $this->requireFields($form->get('properties'), $data['properties'], sprintf('Point action (%s)', $point->getType()), 'describe_point_action_type');
        }
        $form->submit($data, false);
        if (!$form->isValid()) {
            throw new ToolCallException(sprintf('Point action (%s) is invalid: %s. See describe_point_action_type.', $point->getType(), $this->describer->errors($form)));
        }
    }

    private function actionForm(Point $point): \Symfony\Component\Form\FormInterface
    {
        return $this->points->createForm($point, $this->formFactory, null, ['csrf_protection' => false, 'allow_extra_fields' => true]);
    }

    /** @return array<string, mixed> */
    private function actionDef(string $type): array
    {
        $def = $this->points->getPointActions()['actions'][$type] ?? null;
        if (!is_array($def)) {
            throw new ToolCallException(sprintf('Unknown point action type "%s". See list_point_action_types.', $type));
        }

        return $def;
    }

    /** @return array<string, mixed> */
    private function eventDef(string $type): array
    {
        $def = $this->triggers->getEvents()[$type] ?? null;
        if (!is_array($def)) {
            throw new ToolCallException(sprintf('Unknown trigger event type "%s". See list_point_trigger_event_types.', $type));
        }

        return $def;
    }

    /**
     * @param array<string, mixed> $def
     * @param array<string, mixed> $data
     */
    private function eventForm(string $type, array $def, array $data): \Symfony\Component\Form\FormInterface
    {
        return $this->formFactory->create(TriggerEventType::class, $data + ['type' => $type], ['settings' => $def, 'csrf_protection' => false, 'allow_extra_fields' => true]);
    }

    /**
     * Validates events with Mautic's event forms and sets them on the trigger
     * (replacing the list). Returns the types of new events.
     *
     * @param list<array<string, mixed>> $events
     *
     * @return list<string>
     */
    private function applyEvents(Trigger $trigger, array $events, bool $validateOnly = false): array
    {
        $existing = [];
        foreach ($trigger->getEvents() as $event) {
            $existing[(string) $event->getId()] = $event;
        }
        $rows = [];
        $new  = [];
        foreach (array_values($events) as $i => $e) {
            $type = (string) ($e['type'] ?? '');
            $key  = isset($e['key']) ? (string) $e['key'] : '';
            if ('' !== $key && !isset($existing[$key])) {
                throw new ToolCallException(sprintf('Event %d: this trigger has no event "%s" (see list_point_triggers).', $i + 1, $key));
            }
            $def  = $this->eventDef($type);
            $old  = '' !== $key ? $existing[$key] : null;
            $form = $this->eventForm($type, $def, $old instanceof TriggerEvent ? ['name' => $old->getName(), 'properties' => $old->getProperties()] : []);
            $submit = ['type' => $type, 'name' => (string) ($e['name'] ?? ($old?->getName() ?: $this->trans($def['label'] ?? $type)))];
            if ($form->has('properties')) {
                $submit['properties'] = is_array($e['properties'] ?? null) ? $e['properties'] : ($old?->getProperties() ?? []);
            } elseif (!empty($e['properties'])) {
                throw new ToolCallException(sprintf('Event %d (%s) has no settings.', $i + 1, $type));
            }
            if ($form->has('properties')) {
                $submit['properties'] = $this->tagNamesToIds($form->get('properties'), (array) $submit['properties']);
                $this->requireFields($form->get('properties'), $submit['properties'], sprintf('Event %d (%s)', $i + 1, $type), 'describe_point_trigger_event_type');
            }
            $form->submit($submit, false);
            if (!$form->isValid()) {
                throw new ToolCallException(sprintf('Event %d (%s) is invalid: %s. See describe_point_trigger_event_type.', $i + 1, $type, $this->describer->errors($form)));
            }
            $data = (array) $form->getData();
            $id   = '' !== $key ? $key : 'new'.$i;
            if ('' === $key) {
                $new[] = $type;
            }
            $rows[$id] = ['id' => $id, 'type' => $type, 'name' => (string) ($data['name'] ?? $submit['name']), 'properties' => (array) ($data['properties'] ?? [])];
        }
        if ($validateOnly) {
            return $new;
        }

        foreach ($existing as $id => $event) {
            if (!isset($rows[$id])) {
                $trigger->removeTriggerEvent($event);
                $this->em->remove($event);
            }
        }
        $this->triggers->setEvents($trigger, array_values($rows));

        return $new;
    }

    /**
     * Mautic marks some settings required only in the browser; enforce them.
     *
     * @param array<string, mixed> $values
     */
    private function requireFields(\Symfony\Component\Form\FormInterface $form, array $values, string $where, string $hint): void
    {
        $missing = [];
        foreach ($form as $name => $child) {
            $config = $child->getConfig();
            if ($config->getOption('required') && 'hidden' !== $config->getType()->getBlockPrefix() && in_array($values[$name] ?? null, [null, '', []], true)) {
                $missing[] = (string) $name;
            }
        }
        if ([] !== $missing) {
            throw new ToolCallException(sprintf('%s: required setting(s) missing: %s. See %s.', $where, implode(', ', $missing), $hint));
        }
    }

    /**
     * Tag fields (lead_tag) take tag IDs; accept names and create missing tags.
     *
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    private function tagNamesToIds(\Symfony\Component\Form\FormInterface $form, array $values): array
    {
        foreach ($form as $name => $child) {
            if ('lead_tag' !== $child->getConfig()->getType()->getBlockPrefix()) {
                continue;
            }
            // Mautic's listeners read every tag field; never leave one out.
            $values[$name] ??= [];
            $ids = [];
            foreach ((array) $values[$name] as $value) {
                if (is_int($value) || ctype_digit((string) $value)) {
                    $ids[] = (string) $value;
                } elseif ('' !== trim((string) $value)) {
                    $tag = $this->leads->getTagRepository()->getTagByNameOrCreateNewOne(trim((string) $value));
                    if (!$tag->getId()) {
                        $this->leads->getTagRepository()->saveEntity($tag);
                    }
                    $ids[] = (string) $tag->getId();
                }
            }
            $values[$name] = array_values(array_unique($ids));
        }

        return $values;
    }

    /**
     * Published triggers the score change from $old to $new crosses, that
     * have not fired for this contact yet.
     *
     * @return list<array<string, mixed>>
     */
    private function triggersCrossed(Lead $lead, ?Group $group, int $old, int $new): array
    {
        $p    = $this->prefix();
        $rows = $this->connection->fetchAllAssociative(
            "SELECT t.id, t.name, t.points, GROUP_CONCAT(e.type) types
               FROM {$p}point_triggers t JOIN {$p}point_trigger_events e ON e.trigger_id = t.id
              WHERE t.is_published = 1 AND t.points > ? AND t.points <= ? AND ".(null !== $group ? 't.group_id = ?' : 't.group_id IS NULL')."
                AND e.id NOT IN (SELECT event_id FROM {$p}point_lead_event_log WHERE lead_id = ?)
              GROUP BY t.id, t.name, t.points ORDER BY t.points",
            null !== $group ? [$old, $new, $group->getId(), $lead->getId()] : [$old, $new, $lead->getId()]
        );

        return array_map(fn (array $r): array => [
            'id'       => (int) $r['id'],
            'name'     => $r['name'],
            'points'   => (int) $r['points'],
            'events'   => explode(',', (string) $r['types']),
            'outbound' => [] !== array_intersect(explode(',', (string) $r['types']), self::OUTBOUND_EVENTS),
        ], $rows);
    }

    private function contactsAtScore(Trigger $trigger): int
    {
        $p = $this->prefix();
        if (null !== $trigger->getGroup()) {
            return (int) $this->connection->fetchOne(sprintf('SELECT COUNT(*) FROM %s%s WHERE group_id = ? AND score >= ?', $p, GroupContactScore::TABLE_NAME), [$trigger->getGroup()->getId(), (int) $trigger->getPoints()]);
        }

        return (int) $this->connection->fetchOne("SELECT COUNT(*) FROM {$p}leads WHERE points >= ?", [(int) $trigger->getPoints()]);
    }

    private function group(int $id): Group
    {
        $group = $this->groups->getEntity($id);
        if (!$group instanceof Group || !$group->getId()) {
            throw new ToolCallException(sprintf('Point group %d not found (see list_point_groups).', $id));
        }

        return $group;
    }

    /** @return array<string, mixed> */
    private function actionSummary(Point $point): array
    {
        return [
            'id'         => $point->getId(),
            'name'       => $point->getName(),
            'type'       => $point->getType(),
            'delta'      => (int) $point->getDelta(),
            'group'      => $point->getGroup() ? ['id' => $point->getGroup()->getId(), 'name' => $point->getGroup()->getName()] : null,
            'repeatable' => (bool) $point->getRepeatable(),
            'published'  => $point->isPublished(),
            'properties' => $point->getProperties() ?: new \stdClass(),
        ];
    }

    /** @return array<string, mixed> */
    private function triggerSummary(Trigger $trigger): array
    {
        return [
            'id'                     => $trigger->getId(),
            'name'                   => $trigger->getName(),
            'points'                 => (int) $trigger->getPoints(),
            'group'                  => $trigger->getGroup() ? ['id' => $trigger->getGroup()->getId(), 'name' => $trigger->getGroup()->getName()] : null,
            'trigger_existing_leads' => (bool) $trigger->getTriggerExistingLeads(),
            'published'              => $trigger->isPublished(),
            'events'                 => array_values(array_map(fn (TriggerEvent $e): array => [
                'key'        => (string) $e->getId(),
                'type'       => $e->getType(),
                'name'       => $e->getName(),
                'properties' => $e->getProperties() ?: new \stdClass(),
            ], $trigger->getEvents()->toArray())),
        ];
    }

    /**
     * @param list<array<string, mixed>>|null $events
     *
     * @return array<string, mixed>
     */
    private function describeTrigger(Trigger $trigger, ?array $events): array
    {
        $summary = $this->triggerSummary($trigger);
        if (null !== $events) {
            $summary['events'] = array_map(fn (array $e): array => array_intersect_key($e, array_flip(['key', 'type', 'name', 'properties'])), $events);
        }

        return $summary;
    }

    /** @return list<object> */
    private function all(object $model, string $search = ''): array
    {
        $result = $model->getEntities(['filter' => ['string' => $search], 'ignore_paginator' => true]);
        $items  = is_array($result) && array_key_exists('results', $result) ? $result['results'] : $result;

        return is_iterable($items) ? (is_array($items) ? array_values($items) : iterator_to_array($items, false)) : [];
    }

    private function trans(string $key): string
    {
        return $this->translator->trans($key);
    }

    private function url(string $path): string
    {
        return rtrim((string) $this->parameters->get('site_url'), '/').$path;
    }

    private function prefix(): string
    {
        return defined('MAUTIC_TABLE_PREFIX') ? (string) MAUTIC_TABLE_PREFIX : '';
    }
}

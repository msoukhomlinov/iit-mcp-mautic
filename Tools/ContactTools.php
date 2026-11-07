<?php

declare(strict_types=1);

namespace MauticPlugin\IntellectItMcpBundle\Tools;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Entity\LeadField;
use Mautic\LeadBundle\Entity\LeadList;
use Mautic\LeadBundle\Field\Exception\AbortColumnCreateException;
use Mautic\LeadBundle\Field\FieldsWithUniqueIdentifier;
use Mautic\LeadBundle\Model\FieldModel;
use Mautic\LeadBundle\Model\LeadModel;
use Mautic\LeadBundle\Model\ListModel;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Server\RequestContext;
use MauticPlugin\IntellectItMcpBundle\Service\McpUserContext;
use MauticPlugin\IntellectItMcpBundle\Service\SegmentEffects;

const CONTACT_SCHEMA = [
    'type'                 => 'object',
    'additionalProperties' => false,
    'properties'           => [
        'email'  => ['type' => 'string', 'description' => 'Email address; identifies existing contacts (with the other unique fields of this Mautic)'],
        'fields' => [
            'type'                 => 'object',
            'description'          => 'Contact fields by alias (see mautic_list_contact_fields), e.g. {"firstname": "Alex", "lastname": "Weber", "company": "ACME GmbH", "position": "CISO", "phone": "+49 431 123", "city": "Kiel", "country": "Germany"}',
            'additionalProperties' => ['type' => ['string', 'number', 'boolean', 'null']],
        ],
        'tags'   => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Tags to add; prefix "-" removes a tag, e.g. "-lead"'],
        'points' => ['type' => 'integer', 'description' => 'Set the points to this value (lead scoring)'],
        'stage'  => ['type' => 'string', 'description' => 'Move the contact to this stage (name or ID, must exist in Mautic)'],
    ],
];

const FIELD_TYPES = ['text', 'textarea', 'number', 'boolean', 'select', 'multiselect', 'date', 'datetime', 'email', 'url', 'tel', 'country', 'region', 'locale', 'timezone'];

/**
 * Contact and segment tools (scope mcp:contacts:write): create and update
 * contacts, create segments, change segment membership. Built for syncing
 * contacts with other systems (CRMs such as Twenty) through an assistant.
 *
 * Contacts that join a segment can enter campaigns that use the segment
 * and receive published segment emails; the results report these
 * live_effects, and dryRun shows them before anything is saved.
 * Do-Not-Contact (unsubscribed, bounced) is never changed.
 */
class ContactTools
{
    private const MAX_CONTACTS = 100;

    /** @var array<string, string>|null alias => label */
    private ?array $fieldList = null;

    public function __construct(
        private readonly McpUserContext $auth,
        private readonly LeadModel $leads,
        private readonly ListModel $segments,
        private readonly FieldModel $fields,
        private readonly FieldsWithUniqueIdentifier $uniqueFields,
        private readonly Connection $connection,
        private readonly CoreParametersHelper $parameters,
        private readonly SegmentEffects $effects,
    ) {
    }

    /**
     * List the fields of contacts or companies (alias, label, type, choices, whether it identifies records). Use the aliases in mautic_upsert_contacts / mautic_upsert_companies and in segment filters.
     *
     * @param string $object "contact" or "company"
     *
     * @return array<string, mixed>
     */
    public function listContactFields(RequestContext $context, #[Schema(enum: ['contact', 'company'])] string $object = 'contact'): array
    {
        $this->auth->require($context, ['lead:leads:viewown', 'lead:leads:viewother']);
        $object = 'company' === $object ? 'company' : 'lead';
        $unique = $this->uniqueFields->getFieldsWithUniqueIdentifier(['object' => $object]);
        $fields = [];
        foreach ($this->fields->getFieldListWithProperties($object) as $alias => $f) {
            if (!($f['isPublished'] ?? true)) {
                continue;
            }
            $fields[] = array_filter([
                'alias'      => $alias,
                'label'      => $f['label'] ?? $alias,
                'type'       => $f['type'] ?? null,
                'group'      => $f['group'] ?? null,
                'unique'     => array_key_exists($alias, $unique) ?: null,
                'options'    => $this->fieldOptions($f),
            ], fn ($v): bool => null !== $v && [] !== $v);
        }

        return ['fields' => $fields];
    }

    /**
     * Create or update contacts (up to 100 per call), matched by email and the other unique fields: existing contacts are updated, new ones created. Optionally add them all to segments. Do-Not-Contact status (unsubscribed, bounced) is never changed. Call with dryRun first when adding to segments: live_effects lists running campaigns and segment emails these contacts would get.
     *
     * @param list<array<string, mixed>> $contacts   Contacts: {email, fields, tags}
     * @param list<int>                  $segmentIds Add every contact to these segments
     * @param bool                       $overwrite  Overwrite existing values (false: only fill empty fields of existing contacts)
     * @param bool                       $dryRun     Validate and report what would happen, save nothing
     *
     * @return array<string, mixed>
     */
    public function upsertContacts(
        RequestContext $context,
        #[Schema(definition: ['type' => 'array', 'minItems' => 1, 'maxItems' => self::MAX_CONTACTS, 'items' => CONTACT_SCHEMA])] array $contacts,
        array $segmentIds = [],
        bool $overwrite = true,
        bool $dryRun = false,
    ): array {
        $this->auth->user($context);
        if (count($contacts) > self::MAX_CONTACTS) {
            throw new ToolCallException(sprintf('At most %d contacts per call; split the list.', self::MAX_CONTACTS));
        }
        $segments = $this->segmentEntities($context, $segmentIds);
        $known    = $this->fieldList();

        $results = [];
        $counts  = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'failed' => 0];
        $seen    = [];
        foreach (array_values($contacts) as $i => $row) {
            try {
                $data = $this->contactData($row, $known);
                $key  = strtolower((string) ($data['email'] ?? ''));
                if ('' !== $key && isset($seen[$key])) {
                    throw new ToolCallException(sprintf('Duplicate of entry %d (same email).', $seen[$key]));
                }
                $seen[$key] = $i;

                $lead  = $this->leads->checkForDuplicateContact($data);
                $isNew = !$lead->getId();
                if ($isNew) {
                    $this->auth->require($context, 'lead:leads:create');
                } else {
                    $this->auth->requireEntityAccess($context, $lead, 'lead:leads', 'edit');
                }

                if (!$isNew && !$overwrite) {
                    $data = array_filter($data, fn ($v, string $alias): bool => in_array($lead->getFieldValue($alias), [null, ''], true), ARRAY_FILTER_USE_BOTH);
                }
                $changed = $isNew ? array_keys($data) : array_keys(array_filter($data, fn ($v, string $alias): bool => (string) $lead->getFieldValue($alias) !== (string) $v, ARRAY_FILTER_USE_BOTH));
                [$addTags, $removeTags] = $this->splitTags($row['tags'] ?? []);
                $current                = array_map(fn ($t): string => $t->getTag(), $lead->getTags()->toArray());
                $tagChanges             = array_values(array_merge(array_diff($addTags, $current), array_map(fn (string $t): string => '-'.$t, array_intersect($removeTags, $current))));
                $inSegments             = $isNew ? [] : $this->memberSegmentIds((int) $lead->getId(), array_map(fn (LeadList $s): int => (int) $s->getId(), $segments));
                $joins                  = array_values(array_filter($segments, fn (LeadList $s): bool => !in_array((int) $s->getId(), $inSegments, true)));

                $points = isset($row['points']) ? (int) $row['points'] : null;
                $stage  = isset($row['stage']) && '' !== (string) $row['stage'] ? (string) $row['stage'] : null;
                if (null !== $points && ($isNew || (int) $lead->getPoints() !== $points)) {
                    $changed[] = 'points';
                }
                if (null !== $stage && ($isNew || !in_array($stage, [(string) $lead->getStage()?->getName(), (string) $lead->getStage()?->getId()], true))) {
                    $changed[] = 'stage';
                    $data['stage'] = $stage;
                }
                $status = $isNew ? 'created' : ([] === $changed && [] === $tagChanges && [] === $joins ? 'unchanged' : 'updated');
                if (!$dryRun && 'unchanged' !== $status) {
                    $this->leads->setFieldValues($lead, $data, false, false);
                    if (null !== $points) {
                        $lead->setPoints($points);
                    }
                    if ([] !== $addTags || [] !== $removeTags) {
                        $this->leads->modifyTags($lead, $addTags, $removeTags, false);
                    }
                    $this->leads->saveEntity($lead);
                    foreach ($joins as $segment) {
                        $this->segments->addLead($lead, $segment, true);
                    }
                    $this->auth->audit('lead', (int) $lead->getId(), $isNew ? 'create' : 'update', ['fields' => $changed, 'tags' => $tagChanges, 'segments' => array_map(fn (LeadList $s): int => (int) $s->getId(), $joins)]);
                }
                ++$counts[$status];
                $results[] = array_filter([
                    'index'          => $i,
                    'status'         => $status,
                    'id'             => $lead->getId(),
                    'email'          => $data['email'] ?? $lead->getEmail(),
                    'changed_fields' => 'updated' === $status ? $changed : null,
                    'tags'           => $tagChanges ?: null,
                    'joins_segments' => array_map(fn (LeadList $s): int => (int) $s->getId(), $joins) ?: null,
                ], fn ($v): bool => null !== $v);
            } catch (ToolCallException $e) {
                ++$counts['failed'];
                $results[] = ['index' => $i, 'status' => 'failed', 'email' => $row['email'] ?? null, 'error' => $e->getMessage()];
            } catch (\Throwable $e) {
                ++$counts['failed'];
                $results[] = ['index' => $i, 'status' => 'failed', 'email' => $row['email'] ?? null, 'error' => 'Mautic rejected the contact: '.$e->getMessage()];
            }
        }

        return [
            'dry_run' => $dryRun,
            'message' => ($dryRun ? 'Dry run, nothing saved: ' : 'Saved: ').vsprintf('%d created, %d updated, %d unchanged, %d failed.', array_values($counts)),
            'counts'  => $counts,
            'results' => $results,
        ] + ([] !== $segments ? ['live_effects' => $this->effects->forSegments($segments)] : []);
    }

    /**
     * Create a custom field for contacts or companies, e.g. to map a CRM field that Mautic does not have yet. If the alias exists, the existing field is returned (no change). Needs the Mautic permission to manage fields. The new column is added to the database right away (or by Mautic's background job if the instance is set up that way).
     *
     * @param string       $label   Label shown in Mautic, e.g. "Deal stage"
     * @param string       $alias   Field alias (lowercase letters, digits, underscores), e.g. "deal_stage"; default: from the label
     * @param string       $type    Field type
     * @param string       $object  "contact" or "company"
     * @param list<string> $options Choices for select / multiselect
     * @param bool         $unique  Identifies records (like email); use only for real IDs, e.g. "twenty_id"
     *
     * @return array<string, mixed>
     */
    public function createContactField(
        RequestContext $context,
        #[Schema(minLength: 1, maxLength: 191)] string $label,
        string $alias = '',
        #[Schema(enum: FIELD_TYPES)] string $type = 'text',
        #[Schema(enum: ['contact', 'company'])] string $object = 'contact',
        #[Schema(maxItems: 200)] array $options = [],
        bool $unique = false,
    ): array {
        $this->auth->require($context, 'lead:fields:full');
        $object = 'company' === $object ? 'company' : 'lead';
        $alias  = strtolower(trim(preg_replace('/[^a-z0-9_]+/i', '_', '' !== $alias ? $alias : $label) ?? '', '_'));
        if ('' === $alias || ctype_digit($alias[0])) {
            throw new ToolCallException('The alias must start with a letter and contain only letters, digits and underscores.');
        }
        if (strlen($alias) > 25) {
            throw new ToolCallException('The alias may have at most 25 characters (it becomes a database column).');
        }
        if (!in_array($type, FIELD_TYPES, true)) {
            throw new ToolCallException(sprintf('Unknown type "%s". Allowed: %s.', $type, implode(', ', FIELD_TYPES)));
        }
        $existing = $this->fields->getEntityByAlias($alias);
        if ($existing instanceof LeadField) {
            if ($existing->getObject() !== $object) {
                throw new ToolCallException(sprintf('The alias "%s" is already used by a %s field.', $alias, 'company' === $existing->getObject() ? 'company' : 'contact'));
            }

            return ['alias' => $alias, 'label' => $existing->getLabel(), 'type' => $existing->getType(), 'object' => 'company' === $object ? 'company' : 'contact', 'created' => false, 'message' => 'The field already exists; nothing changed.'];
        }

        $field = new LeadField();
        $field->setLabel($label);
        $field->setAlias($alias);
        $field->setType($type);
        $field->setObject($object);
        $field->setGroup('core');
        $field->setIsPublished(true);
        $field->setIsListable(true);
        $field->setIsShortVisible(false);
        $field->setIsUniqueIdentifer($unique);
        $properties = match ($type) {
            'select', 'multiselect' => ['list' => array_map(fn ($o): array => ['label' => (string) $o, 'value' => (string) $o], array_values(array_unique($options)))],
            'boolean'               => ['no' => 'No', 'yes' => 'Yes'],
            default                 => [],
        };
        if (in_array($type, ['select', 'multiselect'], true) && [] === $options) {
            throw new ToolCallException('select and multiselect fields need "options".');
        }
        $valid = $this->fields->setFieldProperties($field, $properties);
        if (true !== $valid) {
            throw new ToolCallException('Invalid field properties: '.$valid);
        }

        $queued = false;
        try {
            $this->fields->saveEntity($field);
        } catch (AbortColumnCreateException) {
            $queued = true;
        }
        $this->fieldList = null;
        $this->auth->audit('field', (int) $field->getId(), 'create', ['alias' => $alias, 'type' => $type, 'object' => $object]);

        return [
            'id'      => $field->getId(),
            'alias'   => $alias,
            'label'   => $label,
            'type'    => $type,
            'object'  => 'company' === $object ? 'company' : 'contact',
            'created' => true,
            'message' => $queued
                ? 'Field created; Mautic adds the database column with its background job (mautic:custom-field:create-column). Values can be written once that has run.'
                : 'Field created and ready to use.',
        ];
    }

    /**
     * Add contacts to a segment (up to 100 per call). They can enter running campaigns that use the segment and receive its published segment emails: call with dryRun first and show the user live_effects.
     *
     * @param int       $segmentId  Segment ID
     * @param list<int> $contactIds Contact IDs
     * @param bool      $dryRun     Report what would happen, save nothing
     *
     * @return array<string, mixed>
     */
    public function addContactsToSegment(RequestContext $context, int $segmentId, #[Schema(minItems: 1, maxItems: self::MAX_CONTACTS)] array $contactIds, bool $dryRun = false): array
    {
        return $this->changeMembership($context, $segmentId, $contactIds, true, $dryRun);
    }

    /**
     * Remove contacts from a segment (up to 100 per call). They leave campaigns that use the segment only if those campaigns are set to remove contacts; Do-Not-Contact is not changed.
     *
     * @param int       $segmentId  Segment ID
     * @param list<int> $contactIds Contact IDs
     * @param bool      $dryRun     Report what would happen, save nothing
     *
     * @return array<string, mixed>
     */
    public function removeContactsFromSegment(RequestContext $context, int $segmentId, #[Schema(minItems: 1, maxItems: self::MAX_CONTACTS)] array $contactIds, bool $dryRun = false): array
    {
        return $this->changeMembership($context, $segmentId, $contactIds, false, $dryRun);
    }

    /**
     * @param array<mixed> $contactIds
     *
     * @return array<string, mixed>
     */
    private function changeMembership(RequestContext $context, int $segmentId, array $contactIds, bool $add, bool $dryRun): array
    {
        [$segment] = $this->segmentEntities($context, [$segmentId]);
        $ids       = array_values(array_unique(array_map('intval', $contactIds)));
        if (count($ids) > self::MAX_CONTACTS) {
            throw new ToolCallException(sprintf('At most %d contacts per call; split the list.', self::MAX_CONTACTS));
        }

        $members = $this->memberIds((int) $segment->getId(), $ids);
        $results = [];
        $changed = 0;
        foreach ($ids as $id) {
            $lead = $this->leads->getEntity($id);
            if (!$lead instanceof Lead || !$lead->getId()) {
                $results[] = ['id' => $id, 'status' => 'failed', 'error' => 'Contact not found.'];
                continue;
            }
            try {
                $this->auth->requireEntityAccess($context, $lead, 'lead:leads', 'edit');
            } catch (ToolCallException $e) {
                $results[] = ['id' => $id, 'status' => 'failed', 'error' => $e->getMessage()];
                continue;
            }
            $isMember = in_array($id, $members, true);
            if ($add === $isMember) {
                $results[] = ['id' => $id, 'status' => $add ? 'already_member' : 'not_member'];
                continue;
            }
            if (!$dryRun) {
                $add ? $this->segments->addLead($lead, $segment, true) : $this->segments->removeLead($lead, $segment, true);
            }
            ++$changed;
            $results[] = ['id' => $id, 'status' => $add ? 'added' : 'removed'];
        }
        if (!$dryRun && $changed > 0) {
            $this->auth->audit('segment', (int) $segment->getId(), $add ? 'add_contacts' : 'remove_contacts', ['contacts' => array_column(array_filter($results, fn (array $r): bool => in_array($r['status'], ['added', 'removed'], true)), 'id')]);
        }

        return [
            'dry_run'  => $dryRun,
            'segment'  => ['id' => $segment->getId(), 'name' => $segment->getName()],
            'message'  => sprintf('%s%d contact(s) %s.', $dryRun ? 'Dry run, nothing saved: ' : '', $changed, $add ? 'added' : 'removed'),
            'results'  => $results,
        ] + ($add ? ['live_effects' => $this->effects->forSegments([$segment])] : []);
    }

    /**
     * Which of these segments the contact is a member of.
     *
     * @param list<int> $segmentIds
     *
     * @return list<int>
     */
    private function memberSegmentIds(int $leadId, array $segmentIds): array
    {
        if ([] === $segmentIds) {
            return [];
        }
        $prefix = defined('MAUTIC_TABLE_PREFIX') ? (string) MAUTIC_TABLE_PREFIX : '';

        return array_map('intval', $this->connection->fetchFirstColumn(
            "SELECT leadlist_id FROM {$prefix}lead_lists_leads WHERE lead_id = ? AND manually_removed = 0 AND leadlist_id IN (?)",
            [$leadId, $segmentIds], [ParameterType::INTEGER, ArrayParameterType::INTEGER]
        ));
    }

    /**
     * @param list<int> $ids
     *
     * @return list<int>
     */
    private function memberIds(int $segmentId, array $ids): array
    {
        $prefix = defined('MAUTIC_TABLE_PREFIX') ? (string) MAUTIC_TABLE_PREFIX : '';

        return array_map('intval', $this->connection->fetchFirstColumn(
            "SELECT lead_id FROM {$prefix}lead_lists_leads WHERE leadlist_id = ? AND manually_removed = 0 AND lead_id IN (?)",
            [$segmentId, $ids], [ParameterType::INTEGER, ArrayParameterType::INTEGER]
        ));
    }

    /**
     * @param array<string, mixed>  $row
     * @param array<string, string> $known
     *
     * @return array<string, mixed>
     */
    private function contactData(array $row, array $known): array
    {
        $data = [];
        foreach ((array) ($row['fields'] ?? []) as $alias => $value) {
            $data[strtolower((string) $alias)] = is_bool($value) ? (int) $value : $value;
        }
        if (isset($row['email'])) {
            $data['email'] = $row['email'];
        }
        if (isset($data['email'])) {
            $data['email'] = trim((string) $data['email']);
            if (false === filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
                throw new ToolCallException(sprintf('Invalid email address "%s".', $data['email']));
            }
        }
        $unknown = array_diff(array_keys($data), array_keys($known));
        if ([] !== $unknown) {
            throw new ToolCallException(sprintf('Unknown contact field(s): %s. See mautic_list_contact_fields.', implode(', ', $unknown)));
        }
        if (in_array($data['email'] ?? '', ['', null], true) && [] === array_filter($data, fn ($v): bool => !in_array($v, [null, ''], true))) {
            throw new ToolCallException('A contact needs an email address or other field values.');
        }

        return $data;
    }

    /**
     * @param array<mixed> $tags
     *
     * @return array{list<string>, list<string>}
     */
    private function splitTags(array $tags): array
    {
        $add = $remove = [];
        foreach ($tags as $tag) {
            $tag = trim((string) $tag);
            if (str_starts_with($tag, '-')) {
                $remove[] = trim(substr($tag, 1));
            } elseif ('' !== $tag) {
                $add[] = $tag;
            }
        }

        return [array_values(array_unique(array_filter($add))), array_values(array_unique(array_filter($remove)))];
    }

    /** @return array<string, string> */
    private function fieldList(): array
    {
        return $this->fieldList ??= $this->fields->getFieldList(false, false, ['isPublished' => true, 'object' => 'lead']);
    }

    /**
     * @param array<string, mixed> $field
     *
     * @return list<string>|null
     */
    private function fieldOptions(array $field): ?array
    {
        $list = $field['properties']['list'] ?? null;
        if (!is_array($list)) {
            return null;
        }
        $values = [];
        foreach ($list as $k => $option) {
            $values[] = (string) (is_array($option) ? ($option['value'] ?? $k) : $k);
        }

        return array_slice($values, 0, 50) ?: null;
    }

    /**
     * @param array<mixed> $ids
     *
     * @return list<LeadList>
     */
    private function segmentEntities(RequestContext $context, array $ids): array
    {
        $segments = [];
        foreach (array_unique(array_map('intval', $ids)) as $id) {
            $segment = $this->segments->getEntity($id);
            if (!$segment instanceof LeadList || !$segment->getId()) {
                throw new ToolCallException(sprintf('Segment %d not found.', $id));
            }
            $this->auth->requireEntityAccess($context, $segment, 'lead:lists', 'view');
            $segments[] = $segment;
        }

        return $segments;
    }

    private function adminUrl(string $path): string
    {
        return rtrim((string) $this->parameters->get('site_url'), '/').$path;
    }
}

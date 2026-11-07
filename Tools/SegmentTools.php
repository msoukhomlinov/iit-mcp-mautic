<?php

declare(strict_types=1);

namespace MauticPlugin\IntellectItMcpBundle\Tools;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\LeadBundle\Entity\LeadList;
use Mautic\LeadBundle\Entity\Tag;
use Mautic\LeadBundle\Model\LeadModel;
use Mautic\LeadBundle\Model\ListModel;
use Mautic\LeadBundle\Segment\ContactSegmentService;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Server\RequestContext;
use MauticPlugin\IntellectItMcpBundle\Service\McpUserContext;
use MauticPlugin\IntellectItMcpBundle\Service\SegmentEffects;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RequestStack;

const FILTERS_SCHEMA = [
    'type'     => 'array',
    'maxItems' => 50,
    'items'    => [
        'type'                 => 'object',
        'required'             => ['field', 'operator'],
        'additionalProperties' => false,
        'properties'           => [
            'field'    => ['type' => 'string', 'description' => 'Filter field from mautic_list_segment_filter_fields, e.g. "country", "tags", "leadlist", "date_added", "companyname", a custom field alias'],
            'object'   => ['type' => 'string', 'enum' => ['contact', 'company', 'behaviors'], 'description' => 'Where the field lives (default: from mautic_list_segment_filter_fields)'],
            'operator' => ['type' => 'string', 'description' => 'Operator from mautic_list_segment_filter_fields, e.g. "=", "!=", "like", "in", "!in", "empty", "!empty", "gt", "lt", "gte", "lte", "between", "regexp"'],
            'value'    => ['description' => 'Filter value. Tags by name, segments by ID (lists for "in"/"!in"); dates absolute ("2026-01-31") or relative ("-30 days", "today", "last month"); omit for "empty"/"!empty"'],
            'glue'     => ['type' => 'string', 'enum' => ['and', 'or'], 'description' => 'How this filter joins the previous ones (default "and"; "or" starts a new group)'],
        ],
    ],
];

/**
 * Segments: static lists and dynamic segments with filters (Mautic keeps
 * dynamic segments up to date with its segment job). Scope mcp:contacts:write.
 * Filters are validated with Mautic's own segment form; dryRun shows how
 * many contacts match and what changing a segment sets in motion.
 */
class SegmentTools
{
    public function __construct(
        private readonly McpUserContext $auth,
        private readonly ListModel $segments,
        private readonly LeadModel $leads,
        private readonly ContactSegmentService $segmentService,
        private readonly FormFactoryInterface $formFactory,
        private readonly SegmentEffects $effects,
        private readonly CoreParametersHelper $parameters,
        private readonly RequestStack $requests,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Mautic offers the behaviour filters (email opens, page visits, tags,
     * segment membership, ...) only to the segment form and API routes;
     * segment tools run in that context.
     */
    private function segmentContext(): void
    {
        $request = $this->requests->getCurrentRequest();
        if (null !== $request && 'mautic_segment_action' !== $request->attributes->get('_route')) {
            $request->attributes->set('_route', 'mautic_segment_action');
            $request->attributes->set('_route_params', ['objectAction' => 'new']);
        }
    }

    /**
     * List the fields segment filters can use (contact and company fields, tags, segment membership, behaviour such as email opens and page visits), with their operators and choices.
     *
     * @param string $search Filter by label or field name
     *
     * @return array<string, mixed>
     */
    public function listSegmentFilterFields(RequestContext $context, string $search = ''): array
    {
        $this->segmentContext();
        $this->auth->require($context, ['lead:lists:viewown', 'lead:lists:viewother']);
        $out = [];
        foreach ($this->segments->getChoiceFields() as $object => $fields) {
            foreach ($fields as $field => $def) {
                $label = (string) ($def['label'] ?? $field);
                if ('' !== $search && false === stripos($label.' '.$field, $search)) {
                    continue;
                }
                $out[] = array_filter([
                    'field'     => $field,
                    'object'    => $this->publicObject((string) $object),
                    'label'     => $label,
                    'type'      => $def['properties']['type'] ?? null,
                    'operators' => array_values(array_unique(array_map('strval', array_values((array) ($def['operators'] ?? []))))),
                    'choices'   => $this->choices($def),
                ], fn ($v): bool => null !== $v && [] !== $v);
            }
        }

        return ['fields' => $out];
    }

    /**
     * Create a segment. Without filters it is a static list (fill it with mautic_upsert_contacts or mautic_change_segment_contacts). With filters it is dynamic: Mautic's segment job adds and removes contacts as they match. dryRun validates the filters and counts the matching contacts.
     *
     * @param string                     $name        Segment name, up to 191 characters
     * @param string                     $description Internal description (default "Created via MCP.")
     * @param string                     $publicName  Name shown to contacts in preference centers (default: name)
     * @param list<array<string, mixed>> $filters     Filters, see mautic_list_segment_filter_fields; empty = static segment
     * @param bool                       $dryRun      Validate and count matching contacts without saving
     *
     * @return array<string, mixed>
     */
    public function createSegment(
        RequestContext $context,
        #[Schema(minLength: 1, maxLength: 191)] string $name,
        string $description = '',
        string $publicName = '',
        #[Schema(definition: FILTERS_SCHEMA + ['description' => 'Segment filters (max 50); empty list creates a static segment'])] array $filters = [],
        bool $dryRun = false,
    ): array {
        $this->auth->require($context, 'lead:lists:create');
        $this->segmentContext();
        $segment = new LeadList();
        $this->submit($segment, [
            'name'        => $name,
            'description' => '' !== $description ? $description : 'Created via MCP.',
            'publicName'  => '' !== $publicName ? $publicName : $name,
            'isPublished' => true,
            'filters'     => $this->mauticFilters($filters),
        ]);
        if ($dryRun) {
            return ['dry_run' => true, 'message' => 'Dry run, nothing saved: the filters are valid.', 'matching_contacts' => [] !== $filters ? $this->countUnsaved($segment) : null, 'filters' => $this->describe($segment)];
        }

        $this->segments->saveEntity($segment);
        $matching = [] !== $filters ? $this->count($segment) : null;
        $this->auth->audit('segment', (int) $segment->getId(), 'create', ['name' => $name, 'filters' => count($filters)]);

        return $this->result($segment, [] === $filters
            ? 'Static segment created. Add contacts with mautic_upsert_contacts or mautic_change_segment_contacts.'
            : 'Dynamic segment created. Mautic fills it with its next segment run (cron), about '.$matching.' contact(s) match now.') + ['matching_contacts' => $matching];
    }

    /**
     * Change name, description or filters of a segment. New filters replace the old ones. If running campaigns or published segment emails use the segment, contacts that newly match enter them: call with dryRun first and show the user live_effects and the matching count.
     *
     * @param int                               $id          ID of the segment to change
     * @param string|null                       $name        New name; omitted = unchanged
     * @param string|null                       $description New description; omitted = unchanged
     * @param list<array<string, mixed>>|null $filters     Replace the filters ([] = static segment); omitted = unchanged
     * @param bool                              $dryRun      Preview matching count and live effects without saving
     *
     * @return array<string, mixed>
     */
    public function updateSegment(
        RequestContext $context,
        int $id,
        ?string $name = null,
        ?string $description = null,
        #[Schema(definition: ['description' => 'Replaces all existing filters; [] makes it static; omitted or null leaves filters unchanged', 'anyOf' => [FILTERS_SCHEMA, ['type' => 'null']]])] ?array $filters = null,
        bool $dryRun = false,
    ): array {
        $segment = $this->segments->getEntity($id);
        if (!$segment instanceof LeadList || !$segment->getId()) {
            throw new ToolCallException(sprintf('Segment %d not found.', $id));
        }
        $this->auth->requireEntityAccess($context, $segment, 'lead:lists', 'edit');
        $this->segmentContext();
        $before = $this->describe($segment);

        $data = array_filter(['name' => $name, 'description' => $description], fn ($v): bool => null !== $v);
        if (null !== $filters) {
            $data['filters'] = $this->mauticFilters($filters);
        }
        if ([] === $data) {
            throw new ToolCallException('Nothing to change.');
        }
        $current = $this->segments->getRepository()->getLeadCount([$id]);
        $this->submit($segment, $data);
        $result  = [
            'matching_contacts' => [] === $segment->getFilters() ? null : ($dryRun ? $this->countUnsaved($segment) : null),
            'current_contacts'  => (int) (is_array($current) ? ($current[$id] ?? 0) : $current),
            'filters_before'    => $before,
            'filters'           => $this->describe($segment),
            'live_effects'      => $this->effects->forSegments([$segment]),
        ];
        if ($dryRun) {
            $this->em->detach($segment);

            return ['dry_run' => true, 'message' => 'Dry run, nothing saved.'] + $result;
        }

        $this->segments->saveEntity($segment);
        if ([] !== $segment->getFilters()) {
            $result['matching_contacts'] = $this->count($segment);
        }
        $this->auth->audit('segment', $id, 'update', ['fields' => array_keys($data)]);

        return $this->result($segment, 'Segment saved. Mautic applies new filters with its next segment run (cron).') + $result;
    }

    /**
     * MCP filter format -> Mautic's segment filter structure.
     *
     * @param array<mixed> $filters
     *
     * @return list<array<string, mixed>>
     */
    private function mauticFilters(array $filters): array
    {
        $choices = $this->segments->getChoiceFields();
        $out     = [];
        foreach (array_values($filters) as $i => $f) {
            $field  = (string) ($f['field'] ?? '');
            $object = isset($f['object']) ? $this->mauticObject((string) $f['object']) : null;
            $def    = null;
            foreach ($object ? [$object => $choices[$object] ?? []] : $choices as $o => $fields) {
                if (isset($fields[$field])) {
                    [$object, $def] = [$o, $fields[$field]];
                    break;
                }
            }
            if (null === $def) {
                throw new ToolCallException(sprintf('Filter %d: unknown field "%s". See mautic_list_segment_filter_fields.', $i + 1, $field));
            }
            $operator  = (string) ($f['operator'] ?? '');
            $operators = array_map('strval', array_values((array) ($def['operators'] ?? [])));
            if (!in_array($operator, $operators, true)) {
                throw new ToolCallException(sprintf('Filter %d (%s): operator "%s" is not allowed; use one of %s.', $i + 1, $field, $operator, implode(', ', $operators)));
            }
            $type  = (string) ($def['properties']['type'] ?? 'text');
            $value = $f['value'] ?? null;
            if ('tags' === $type && null !== $value) {
                $value = array_map(fn ($tag): string => (string) $this->tagId((string) $tag), (array) $value);
            } elseif (null !== $value && null !== ($labels = $this->choiceValues($def))) {
                // Choice lists such as stage or owner store IDs; accept the labels too.
                $map   = fn ($v): string => $labels[mb_strtolower((string) $v)] ?? (string) $v;
                $value = is_array($value) || in_array($operator, ['in', '!in'], true) ? array_map($map, (array) $value) : $map($value);
            } elseif (in_array($operator, ['in', '!in'], true) && null !== $value) {
                $value = array_map('strval', (array) $value);
            } elseif (is_bool($value)) {
                $value = $value ? '1' : '0';
            } elseif (null !== $value && !is_array($value)) {
                $value = (string) $value;
            }
            if (null === $value && !in_array($operator, ['empty', '!empty'], true)) {
                throw new ToolCallException(sprintf('Filter %d (%s): "value" is required for operator "%s".', $i + 1, $field, $operator));
            }

            $out[] = [
                'glue'       => in_array($f['glue'] ?? 'and', ['and', 'or'], true) ? ($f['glue'] ?? 'and') : 'and',
                'field'      => $field,
                'object'     => $object,
                'type'       => $type,
                'operator'   => $operator,
                'properties' => ['filter' => $value],
            ];
        }

        return $out;
    }

    /** @param array<string, mixed> $data */
    private function submit(LeadList $segment, array $data): void
    {
        $form = $this->segments->createForm($segment, $this->formFactory, null, ['csrf_protection' => false, 'allow_extra_fields' => true]);
        $form->submit($data, false);
        if (!$form->isValid()) {
            throw new ToolCallException('Mautic rejected the segment: '.$this->errors($form));
        }
    }

    /** @param FormInterface<mixed> $form */
    private function errors(FormInterface $form): string
    {
        $messages = [];
        foreach ($form->getErrors(true) as $error) {
            $origin     = $error->getOrigin();
            $path       = [];
            for ($f = $origin; null !== $f && null !== $f->getParent(); $f = $f->getParent()) {
                array_unshift($path, $f->getName());
            }
            $messages[] = ([] !== $path ? implode('.', $path).': ' : '').$error->getMessage();
        }

        return implode('; ', array_unique($messages)) ?: 'invalid data';
    }

    /** Mautic counts saved segments only: save in a transaction, count, roll back. */
    private function countUnsaved(LeadList $segment): ?int
    {
        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        try {
            if (null === $segment->getId()) {
                $this->segments->saveEntity($segment);
            } else {
                $this->segments->getRepository()->saveEntity($segment);
            }

            return $this->count($segment);
        } catch (\Throwable) {
            return null;
        } finally {
            $connection->rollBack();
            if ($this->em->contains($segment)) {
                $this->em->detach($segment);
            }
        }
    }

    private function count(LeadList $segment): ?int
    {
        try {
            $result = $this->segmentService->getTotalLeadListLeadsCount($segment);
            $row    = reset($result);

            return is_array($row) ? (int) ($row['count'] ?? 0) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function tagId(string $name): int
    {
        $tag = $this->leads->getTagRepository()->getTagByNameOrCreateNewOne($name);
        if (!$tag->getId()) {
            $this->leads->getTagRepository()->saveEntity($tag);
        }

        return (int) $tag->getId();
    }

    /** @return list<array<string, mixed>> */
    private function describe(LeadList $segment): array
    {
        $tags = null;
        $out  = [];
        foreach ($segment->getFilters() as $f) {
            $value = $f['properties']['filter'] ?? $f['filter'] ?? null;
            if ('tags' === ($f['type'] ?? null) && is_array($value)) {
                $tags ??= $this->leads->getTagRepository();
                $value = array_values(array_filter(array_map(fn ($id): ?string => $tags->find((int) $id) instanceof Tag ? $tags->find((int) $id)->getTag() : null, $value)));
            }
            $out[] = array_filter([
                'glue'     => $f['glue'] ?? 'and',
                'field'    => $f['field'] ?? null,
                'object'   => $this->publicObject((string) ($f['object'] ?? 'lead')),
                'operator' => $f['operator'] ?? null,
                'value'    => $value,
            ], fn ($v): bool => null !== $v);
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function result(LeadList $segment, string $message): array
    {
        return [
            'id'      => $segment->getId(),
            'name'    => $segment->getName(),
            'alias'   => $segment->getAlias(),
            'dynamic' => [] !== $segment->getFilters(),
            'message' => $message,
            'url'     => rtrim((string) $this->parameters->get('site_url'), '/').'/s/segments/view/'.$segment->getId(),
        ];
    }

    /**
     * @param array<string, mixed> $def
     *
     * @return list<string>|null
     */
    private function choices(array $def): ?array
    {
        $list = $def['properties']['list'] ?? null;
        if (!is_array($list) || [] === $list || in_array($def['properties']['type'] ?? '', ['country', 'region', 'locale', 'timezone'], true)) {
            return null;
        }
        $values = [];
        foreach ($list as $k => $v) {
            $values[] = is_array($v) ? (string) ($v['value'] ?? $k) : (is_string($k) ? $k : (string) $v);
        }

        return array_slice($values, 0, 50);
    }

    /**
     * Label => value of a filter's choice list when they differ (e.g. stage name => stage ID).
     *
     * @return array<string, string>|null
     */
    private function choiceValues(array $def): ?array
    {
        $list = $def['properties']['list'] ?? null;
        if (!is_array($list)) {
            return null;
        }
        $map = [];
        array_walk_recursive($list, function ($v, $k) use (&$map): void {
            if (is_string($k) && (string) $v !== $k) {
                $map[mb_strtolower($k)] = (string) $v;
            }
        });

        return [] !== $map ? $map : null;
    }

    private function publicObject(string $object): string
    {
        return 'lead' === $object ? 'contact' : $object;
    }

    private function mauticObject(string $object): string
    {
        return 'contact' === $object ? 'lead' : $object;
    }
}

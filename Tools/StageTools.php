<?php

declare(strict_types=1);

namespace MauticPlugin\IntellectItMcpBundle\Tools;

use Doctrine\DBAL\Connection;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Model\LeadModel;
use Mautic\StageBundle\Entity\Stage;
use Mautic\StageBundle\Model\StageModel;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Server\RequestContext;
use MauticPlugin\IntellectItMcpBundle\Service\McpUserContext;
use MauticPlugin\IntellectItMcpBundle\Service\SegmentEffects;

/**
 * Stages: the lifecycle of a contact (e.g. Lead -> MQL -> SQL -> Customer).
 * New stages are created unpublished; contacts can only be moved into
 * published stages, as in Mautic's campaign action.
 */
class StageTools
{
    public function __construct(
        private readonly McpUserContext $auth,
        private readonly StageModel $stages,
        private readonly LeadModel $leads,
        private readonly SegmentEffects $effects,
        private readonly Connection $connection,
        private readonly CoreParametersHelper $parameters,
    ) {
    }

    /**
     * List the stages (lifecycle phases such as Lead, MQL, SQL, Customer), ordered by weight, with how many contacts are in each.
     *
     * @return array<string, mixed>
     */
    public function listStages(RequestContext $context): array
    {
        $this->auth->require($context, 'stage:stages:view');
        $counts = [];
        foreach ($this->connection->fetchAllAssociative(sprintf('SELECT stage_id, COUNT(*) n FROM %sleads WHERE stage_id IS NOT NULL GROUP BY stage_id', $this->prefix())) as $r) {
            $counts[(int) $r['stage_id']] = (int) $r['n'];
        }
        $result = $this->stages->getEntities(['ignore_paginator' => true, 'orderBy' => 's.weight', 'orderByDir' => 'ASC']);
        $items  = is_array($result) && array_key_exists('results', $result) ? $result['results'] : $result;
        $items  = is_iterable($items) ? (is_array($items) ? array_values($items) : iterator_to_array($items, false)) : [];
        usort($items, fn (Stage $a, Stage $b): int => [(int) $a->getWeight(), (int) $a->getId()] <=> [(int) $b->getWeight(), (int) $b->getId()]);

        return ['stages' => array_map(fn (Stage $s): array => $this->summary($s) + ['contacts' => $counts[(int) $s->getId()] ?? 0], $items)];
    }

    /**
     * Create a stage (unpublished). The weight orders the stages (higher = further along, e.g. Lead 10, MQL 20, SQL 30, Customer 40). A person publishes it in Mautic before contacts can be moved into it.
     *
     * @return array<string, mixed>
     */
    public function createStage(
        RequestContext $context,
        #[Schema(minLength: 1, maxLength: 191)] string $name,
        int $weight = 0,
        string $description = '',
    ): array {
        $this->auth->require($context, 'stage:stages:create');
        if ($this->stages->getRepository()->findOneBy(['name' => $name]) instanceof Stage) {
            throw new ToolCallException(sprintf('A stage named "%s" already exists (see mautic_list_stages).', $name));
        }
        $this->requireFreeWeight($weight, null);
        $stage = new Stage();
        $stage->setName($name);
        $stage->setWeight($weight);
        $stage->setDescription('' !== $description ? $description : null);
        $stage->setIsPublished(false);
        $this->stages->saveEntity($stage);
        $this->auth->audit('stage', (int) $stage->getId(), 'create', ['name' => $name, 'weight' => $weight]);

        return $this->summary($stage) + ['message' => 'Stage created (unpublished). Publish it in Mautic before moving contacts into it.', 'url' => $this->url('/s/stages/edit/'.$stage->getId())];
    }

    /**
     * Rename a stage or change its weight or description. Published stages can be changed too; contacts keep their stage.
     *
     * @return array<string, mixed>
     */
    public function updateStage(
        RequestContext $context,
        int $id,
        #[Schema(minLength: 1, maxLength: 191)] ?string $name = null,
        ?int $weight = null,
        ?string $description = null,
    ): array {
        $stage = $this->stage($id);
        $this->auth->requireEntityAccess($context, $stage, 'stage:stages', 'edit');
        $before = $this->summary($stage);
        if (null !== $name && $name !== $stage->getName()) {
            $other = $this->stages->getRepository()->findOneBy(['name' => $name]);
            if ($other instanceof Stage && $other->getId() !== $stage->getId()) {
                throw new ToolCallException(sprintf('A stage named "%s" already exists.', $name));
            }
            $stage->setName($name);
        }
        if (null !== $weight && $weight !== (int) $stage->getWeight()) {
            $this->requireFreeWeight($weight, (int) $stage->getId());
            $stage->setWeight($weight);
        }
        if (null !== $description) {
            $stage->setDescription('' !== $description ? $description : null);
        }
        $this->stages->saveEntity($stage);
        $after = $this->summary($stage);
        $this->auth->audit('stage', $id, 'update', ['before' => $before, 'after' => $after]);

        return $after + ['message' => 'Stage saved.'];
    }

    /**
     * Move a contact into a stage (name or ID of a published stage), or remove the stage with stage = "". Mautic logs the change in the contact's history. Dynamic segments filtering on the stage pick the contact up (or drop it) with their next update, which can start campaigns and segment emails: dryRun lists them under live_effects.
     *
     * @return array<string, mixed>
     */
    public function setContactStage(
        RequestContext $context,
        int $contactId,
        #[Schema(description: 'Stage name or ID; "" removes the stage')] string $stage,
        #[Schema(maxLength: 191)] string $reason = 'Changed via MCP',
        bool $dryRun = false,
    ): array {
        $lead = $this->leads->getEntity($contactId);
        if (!$lead instanceof Lead || !$lead->getId()) {
            throw new ToolCallException(sprintf('Contact %d not found.', $contactId));
        }
        $this->auth->requireEntityAccess($context, $lead, 'lead:leads', 'edit');
        $new = null;
        if ('' !== trim($stage)) {
            $new = $this->stages->getRepository()->findByIdOrName(trim($stage));
            if (!$new instanceof Stage) {
                throw new ToolCallException(sprintf('Stage "%s" not found (see mautic_list_stages).', $stage));
            }
            if (!$new->isPublished()) {
                throw new ToolCallException(sprintf('Stage "%s" is not published. A person publishes it in Mautic first.', $new->getName()));
            }
        }
        $old    = $lead->getStage();
        $result = [
            'contact' => ['id' => $lead->getId(), 'email' => $lead->getEmail()],
            'stage'   => ['before' => $old?->getName(), 'after' => $new?->getName()],
        ];
        if ($old?->getId() === $new?->getId()) {
            return ['message' => 'Stage unchanged.'] + $result;
        }
        $result['live_effects'] = $this->liveEffects();
        if ($dryRun) {
            return ['dry_run' => true, 'message' => 'Dry run, nothing saved.'] + $result;
        }

        if ($new instanceof Stage) {
            $this->leads->addToStage($lead, $new, mb_substr($reason, 0, 191));
        } else {
            $lead->setStage(null);
        }
        $this->leads->saveEntity($lead);
        $this->auth->audit('lead', $contactId, 'stage', ['before' => $old?->getId(), 'after' => $new?->getId(), 'reason' => $reason]);

        return ['message' => sprintf('Stage %s -> %s.', $old?->getName() ?? 'none', $new?->getName() ?? 'none')] + $result;
    }

    /** @return array<string, mixed> */
    private function liveEffects(): array
    {
        $segments = [];
        foreach ($this->connection->fetchAllAssociative(sprintf('SELECT id, name, filters FROM %slead_lists WHERE is_published = 1', $this->prefix())) as $r) {
            $filters = @unserialize((string) $r['filters'], ['allowed_classes' => false]);
            foreach (is_array($filters) ? $filters : [] as $f) {
                if (is_array($f) && 'stage' === ($f['field'] ?? null)) {
                    $segments[] = ['id' => (int) $r['id'], 'name' => $r['name']];
                    break;
                }
            }
        }

        return ['dynamic_segments_on_stage' => $segments] + ([] !== $segments
            ? $this->effects->forSegmentIds(array_column($segments, 'id'))
            : ['note' => 'No published segment filters on the stage.']);
    }

    /** Mautic requires a unique weight per stage (its form validator, not the model). */
    private function requireFreeWeight(int $weight, ?int $ignoreId): void
    {
        $other = $this->stages->getRepository()->findOneBy(['weight' => $weight]);
        if ($other instanceof Stage && $other->getId() !== $ignoreId) {
            $used = array_map('intval', $this->connection->fetchFirstColumn(sprintf('SELECT weight FROM %sstages ORDER BY weight', $this->prefix())));
            throw new ToolCallException(sprintf('Stage "%s" already has weight %d; each stage needs its own weight (used: %s).', $other->getName(), $weight, implode(', ', $used)));
        }
    }

    private function stage(int $id): Stage
    {
        $stage = $this->stages->getEntity($id);
        if (!$stage instanceof Stage || !$stage->getId()) {
            throw new ToolCallException(sprintf('Stage %d not found (see mautic_list_stages).', $id));
        }

        return $stage;
    }

    /** @return array<string, mixed> */
    private function summary(Stage $s): array
    {
        return [
            'id'          => $s->getId(),
            'name'        => $s->getName(),
            'weight'      => (int) $s->getWeight(),
            'description' => $s->getDescription(),
            'published'   => (bool) $s->isPublished(),
        ];
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

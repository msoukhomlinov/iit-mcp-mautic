<?php

declare(strict_types=1);

namespace MauticPlugin\IntellectItMcpBundle\Tools;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\ProjectBundle\DTO\EntityTypeConfig;
use Mautic\ProjectBundle\Entity\Project;
use Mautic\ProjectBundle\Model\ProjectModel;
use Mautic\ProjectBundle\Security\Permissions\ProjectPermissions;
use Mautic\ProjectBundle\Service\ProjectEntityLoaderService;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Server\RequestContext;
use MauticPlugin\IntellectItMcpBundle\Service\McpUserContext;

/**
 * Projects (Mautic 7): group emails, campaigns, segments, forms, pages,
 * assets, point actions and triggers, stages, companies, ... by topic, e.g.
 * "Webinar NIS2 Q1". Purely organisational: assigning items changes nothing
 * else about them.
 */
class ProjectTools
{
    private const MAX_ITEMS = 100;

    public function __construct(
        private readonly McpUserContext $auth,
        private readonly ProjectModel $projects,
        private readonly ProjectEntityLoaderService $loader,
        private readonly EntityManagerInterface $em,
        private readonly CoreParametersHelper $parameters,
    ) {
    }

    /**
     * List projects with the number of items per type.
     *
     * @param string $search Case-insensitive text matched in project name and description; empty lists all
     *
     * @return array<string, mixed>
     */
    public function listProjects(RequestContext $context, string $search = ''): array
    {
        $this->auth->require($context, ProjectPermissions::CAN_VIEW);
        // ProjectRepository's default order is broken in Mautic 7 (date_modified in DQL).
        $items = array_values(array_filter(
            $this->projects->getRepository()->findBy([], ['name' => 'ASC']),
            fn (Project $p): bool => '' === $search || false !== mb_stripos((string) $p->getName().' '.$p->getDescription(), $search)
        ));
        $types  = $this->loader->getEntityTypesWithViewPermissions();

        return [
            'projects'   => array_map(function (Project $p) use ($types): array {
                $counts = [];
                foreach ($this->loader->getProjectEntities($p, $types) as $type => $group) {
                    if ($group['count'] > 0) {
                        $counts[$type] = $group['count'];
                    }
                }

                return $this->summary($p) + ['items' => (object) $counts];
            }, $items),
            'item_types' => array_keys($types),
        ];
    }

    /**
     * Get a project with all its items (type, id, name), grouped by type.
     *
     * @param int $id Project ID
     *
     * @return array<string, mixed>
     */
    public function getProject(RequestContext $context, int $id): array
    {
        $this->auth->require($context, ProjectPermissions::CAN_VIEW);
        $project = $this->project($id);
        $items   = [];
        foreach ($this->loader->getProjectEntities($project, $this->loader->getEntityTypesWithViewPermissions()) as $type => $group) {
            foreach ($group['entities'] as $entity) {
                $items[$type][] = $this->item($entity);
            }
        }

        return $this->summary($project) + ['items' => (object) $items, 'url' => $this->url('/s/projects/view/'.$project->getId())];
    }

    /**
     * Create a project to group campaigns, emails, segments, forms, ... by topic.
     *
     * @param string $name        Project name, 1-191 characters, must be unique
     * @param string $description Optional free-text description; empty for none
     *
     * @return array<string, mixed>
     */
    public function createProject(RequestContext $context, #[Schema(minLength: 1, maxLength: 191)] string $name, string $description = ''): array
    {
        $this->auth->require($context, ProjectPermissions::CAN_CREATE);
        if ($this->projects->getRepository()->checkProjectNameExists($name)) {
            throw new ToolCallException(sprintf('A project named "%s" already exists (see mautic_list_projects).', $name));
        }
        $project = new Project();
        $project->setName($name);
        $project->setDescription('' !== $description ? $description : null);
        $this->projects->saveEntity($project);
        $this->auth->audit('project', (int) $project->getId(), 'create', ['name' => $name]);

        return $this->summary($project) + ['message' => 'Project created.', 'url' => $this->url('/s/projects/view/'.$project->getId())];
    }

    /**
     * Rename a project or change its description.
     *
     * @param int         $id          Project ID
     * @param string|null $name        New name (1-191 characters, must be unique); omit to keep
     * @param string|null $description New description; omit to keep, empty string clears it
     *
     * @return array<string, mixed>
     */
    public function updateProject(RequestContext $context, int $id, #[Schema(minLength: 1, maxLength: 191)] ?string $name = null, ?string $description = null): array
    {
        $this->auth->require($context, ProjectPermissions::CAN_EDIT);
        $project = $this->project($id);
        if (null !== $name && $name !== $project->getName()) {
            if ($this->projects->getRepository()->checkProjectNameExists($name, (int) $project->getId())) {
                throw new ToolCallException(sprintf('A project named "%s" already exists.', $name));
            }
            $project->setName($name);
        }
        if (null !== $description) {
            $project->setDescription('' !== $description ? $description : null);
        }
        $this->projects->saveEntity($project);
        $this->auth->audit('project', $id, 'update', $this->summary($project));

        return $this->summary($project) + ['message' => 'Project saved.'];
    }

    /**
     * Add items to a project. type is one of item_types from mautic_list_projects (e.g. "email", "campaign", "segment", "form", "page", "asset", "point", "pointtrigger", "stage", "company"). Needs edit rights on each item.
     *
     * @param list<int> $ids
     *
     * @return array<string, mixed>
     */
    public function addToProject(RequestContext $context, int $projectId, string $type, #[Schema(minItems: 1, maxItems: self::MAX_ITEMS)] array $ids): array
    {
        return $this->change($context, $projectId, $type, $ids, true);
    }

    /**
     * Remove items from a project (the items themselves stay unchanged).
     *
     * @param list<int> $ids
     *
     * @return array<string, mixed>
     */
    public function removeFromProject(RequestContext $context, int $projectId, string $type, #[Schema(minItems: 1, maxItems: self::MAX_ITEMS)] array $ids): array
    {
        return $this->change($context, $projectId, $type, $ids, false);
    }

    /**
     * @param list<int> $ids
     *
     * @return array<string, mixed>
     */
    private function change(RequestContext $context, int $projectId, string $type, array $ids, bool $add): array
    {
        $this->auth->require($context, ProjectPermissions::CAN_EDIT);
        $project = $this->project($projectId);
        $config  = $this->type($type);
        $done    = [];
        $skipped = [];
        foreach (array_values(array_unique(array_map('intval', $ids))) as $id) {
            $entity = $config->model->getEntity($id);
            if (!is_object($entity) || !method_exists($entity, 'getProjects') || !$entity->getId()) {
                $skipped[] = ['id' => $id, 'reason' => 'not found'];
                continue;
            }
            try {
                $this->auth->requireEntityAccess($context, $entity, $config->model->getPermissionBase(), 'edit');
            } catch (ToolCallException $e) {
                $skipped[] = ['id' => $id, 'reason' => $e->getMessage()];
                continue;
            }
            $has = $entity->getProjects()->contains($project);
            if ($add === $has) {
                $skipped[] = ['id' => $id, 'reason' => $add ? 'already in the project' : 'not in the project'];
                continue;
            }
            $add ? $entity->addProject($project) : $entity->removeProject($project);
            $this->em->persist($entity);
            $done[] = $this->item($entity);
        }
        if ([] !== $done) {
            $this->em->flush();
            $this->auth->audit('project', $projectId, $add ? 'add_items' : 'remove_items', ['type' => $type, 'ids' => array_column($done, 'id')]);
        }

        return [
            'project' => $this->summary($project),
            'type'    => $type,
            'message' => sprintf('%d item(s) %s.', count($done), $add ? 'added' : 'removed'),
            ($add ? 'added' : 'removed') => $done,
            'skipped' => $skipped,
        ];
    }

    private function type(string $type): EntityTypeConfig
    {
        $types = $this->loader->getEntityTypesWithEditPermissions();
        if (!isset($types[$type])) {
            throw new ToolCallException(sprintf('Unknown item type "%s" or your Mautic role may not edit it. Allowed: %s.', $type, implode(', ', array_keys($types))));
        }

        return $types[$type];
    }

    private function project(int $id): Project
    {
        $project = $this->projects->getEntity($id);
        if (!$project instanceof Project || !$project->getId()) {
            throw new ToolCallException(sprintf('Project %d not found (see mautic_list_projects).', $id));
        }

        return $project;
    }

    /** @return array<string, mixed> */
    private function item(object $entity): array
    {
        $name = method_exists($entity, 'getName') ? $entity->getName() : (method_exists($entity, 'getTitle') ? $entity->getTitle() : null);

        return array_filter([
            'id'        => $entity->getId(),
            'name'      => $name,
            'published' => method_exists($entity, 'isPublished') ? (bool) $entity->isPublished() : null,
        ], fn ($v) => null !== $v);
    }

    /** @return array<string, mixed> */
    private function summary(Project $p): array
    {
        return ['id' => $p->getId(), 'name' => $p->getName(), 'description' => $p->getDescription()];
    }

    private function url(string $path): string
    {
        return rtrim((string) $this->parameters->get('site_url'), '/').$path;
    }

    /**
     * Add items to a project or remove them from it. Assigning items to a project changes nothing else about them.
     *
     * @param int       $projectId Project ID
     * @param string    $action    "add" or "remove"
     * @param string    $type      Item type, one of the item_types from mautic_list_projects (e.g. "email", "campaign", "segment")
     * @param list<int> $ids       Item IDs
     *
     * @return array<string, mixed>
     */
    public function changeProjectItems(RequestContext $context, int $projectId, #[Schema(enum: ['add', 'remove'])] string $action, string $type, #[Schema(minItems: 1, maxItems: self::MAX_ITEMS)] array $ids): array
    {
        return 'add' === $action
            ? $this->addToProject($context, $projectId, $type, $ids)
            : $this->removeFromProject($context, $projectId, $type, $ids);
    }
}

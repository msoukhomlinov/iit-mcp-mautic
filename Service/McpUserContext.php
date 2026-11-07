<?php

declare(strict_types=1);

namespace MauticPlugin\IntellectItMcpBundle\Service;

use Mautic\CoreBundle\Model\AuditLogModel;
use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use Mautic\UserBundle\Entity\User;
use Mautic\UserBundle\Model\UserModel;
use Mcp\Server\RequestContext;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Turns the validated access token of an MCP request into the Mautic user
 * it was issued for, so every tool runs with that user's role and
 * permissions, like a request in the Mautic UI.
 */
class McpUserContext
{
    private ?User $user = null;
    private ?string $clientId = null;

    public function __construct(
        private readonly UserModel $users,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly CorePermissions $security,
        private readonly AuditLogModel $auditLog,
    ) {
    }

    public function user(RequestContext $context): User
    {
        $claims = $context->getAccessToken()?->getClaims() ?? [];
        $sub    = (string) ($claims['sub'] ?? '');
        if (null !== $this->user && (string) $this->user->getId() === $sub) {
            return $this->user;
        }

        $user = ctype_digit($sub) ? $this->users->getEntity((int) $sub) : null;
        if (!$user instanceof User || !$user->getId() || !$user->isPublished()) {
            throw McpError::from('The Mautic user of this access token does not exist or is disabled.');
        }

        $this->tokenStorage->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
        $this->user     = $user;
        $this->clientId = isset($claims['client_id']) ? (string) $claims['client_id'] : null;

        return $user;
    }

    /**
     * @param string|list<string> $permission e.g. "email:emails:create"
     */
    public function require(RequestContext $context, string|array $permission): void
    {
        $this->user($context);
        if (!$this->security->isGranted($permission, 'MATCH_ONE')) {
            throw McpError::from(sprintf('Your Mautic role does not allow this (%s).', implode(', ', (array) $permission)));
        }
    }

    /** Own/other permission check for an existing entity, as in the Mautic API. */
    public function requireEntityAccess(RequestContext $context, object $entity, string $base, string $action): void
    {
        $this->user($context);
        if (!$this->security->checkPermissionExists("{$base}:{$action}own")) {
            // Bundles with standard permissions only (stages, projects): no own/other.
            if (!$this->security->isGranted("{$base}:{$action}")) {
                throw McpError::from(sprintf('Your Mautic role does not allow to %s this item.', $action));
            }

            return;
        }
        $owner = method_exists($entity, 'getPermissionUser') ? $entity->getPermissionUser() : (method_exists($entity, 'getCreatedBy') ? $entity->getCreatedBy() : 0);
        if (!$this->security->hasEntityAccess("{$base}:{$action}own", "{$base}:{$action}other", $owner)) {
            throw McpError::from(sprintf('Your Mautic role does not allow to %s this item.', $action));
        }
    }

    public function hasScope(RequestContext $context, string $scope): bool
    {
        return (bool) $context->getAccessToken()?->hasScope($scope);
    }

    public function currentUserId(): int
    {
        return (int) $this->user?->getId();
    }

    /** Whether the user may see items created by others (else lists are filtered to own items). */
    public function canViewOther(string $base): bool
    {
        return !$this->security->checkPermissionExists("{$base}:viewother") || $this->security->isGranted("{$base}:viewother");
    }

    /** @param array<string, mixed> $details */
    public function audit(string $object, int|string $objectId, string $action, array $details = []): void
    {
        $this->auditLog->writeToLog([
            'bundle'   => 'intellectit_mcp',
            'object'   => $object,
            'objectId' => $objectId,
            'action'   => $action,
            'details'  => $details + ['via' => 'MCP', 'client_id' => $this->clientId, 'ref' => McpError::correlationId()],
        ]);
    }
}

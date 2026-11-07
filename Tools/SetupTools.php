<?php

declare(strict_types=1);

namespace MauticPlugin\IntellectItMcpBundle\Tools;

use MauticPlugin\IntellectItMcpBundle\Service\McpError;
use MauticPlugin\IntellectItMcpBundle\Service\McpUserContext;
use MauticPlugin\IntellectItMcpBundle\Service\SetupStatus;
use Mcp\Server\RequestContext;

/** Read-only check of the server's own setup (scope mcp:read, Mautic administrators only). */
class SetupTools
{
    public function __construct(
        private readonly McpUserContext $auth,
        private readonly SetupStatus $status,
    ) {
    }

    /**
     * Check whether this MCP server is set up correctly: site URL, OAuth keys, redirect hosts, enabled tiers and cron. Reports what is missing and how to fix it; never shows secrets and changes nothing. Mautic administrators only.
     *
     * @return array<string, mixed>
     */
    public function getSetupStatus(RequestContext $context): array
    {
        if (!$this->auth->user($context)->isAdmin()) {
            throw McpError::from('Permission denied: only Mautic administrators may check the server setup.');
        }

        return $this->status->report();
    }
}

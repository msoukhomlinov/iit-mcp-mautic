<?php

declare(strict_types=1);

namespace MauticPlugin\IntellectItMcpBundle\Service;

use MauticPlugin\KopexaOAuthBundle\Scope\ScopeDefinition;
use MauticPlugin\KopexaOAuthBundle\Scope\ScopeProviderInterface;

/**
 * The OAuth scopes of the MCP server, declared to the authorization server
 * (kopexa/mautic-oauth-bundle). McpServerFactory maps tools to them.
 */
final class McpScopes implements ScopeProviderInterface
{
    public const READ           = 'mcp:read';
    public const WRITE          = 'mcp:write';
    public const CONTACTS_WRITE = 'mcp:contacts:write';
    public const CAMPAIGNS_LIVE = 'mcp:campaigns:live';

    /** Scopes a client should hold for normal use (all except the opt-in ones). */
    public const REGULAR = [self::READ, self::WRITE, self::CONTACTS_WRITE];

    public function scopes(): iterable
    {
        yield new ScopeDefinition(self::READ, 'Read contacts, segments, emails, campaigns and statistics', default: true);
        yield new ScopeDefinition(self::WRITE, 'Create and edit unpublished emails and campaigns and their campaign flows');
        yield new ScopeDefinition(self::CONTACTS_WRITE, 'Create and update contacts, create segments, add contacts to segments and remove them (contacts added to segments can enter running campaigns and segment emails)');
        yield new ScopeDefinition(self::CAMPAIGNS_LIVE, 'Change RUNNING campaigns: changes are live and Mautic may send emails because of them', optIn: true);
    }
}

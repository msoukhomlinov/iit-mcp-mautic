<?php

declare(strict_types=1);

use MauticPlugin\IntellectItMcpBundle\Controller\McpController;

return [
    'name'        => 'MCP',
    'description' => 'Model Context Protocol server for AI assistants (Claude, ChatGPT). Read access to contacts, segments, emails and campaigns; creates unpublished email and campaign drafts and edits campaign flows, including running campaigns (live changes).',
    'version'     => '0.8.0', // x-release-please-version
    'author'      => 'IntellectIT (based on Kopexa)',
    'routes'      => [
        'public' => [
            'intellectit_mcp_endpoint' => [
                'path'       => '/mcp',
                'controller' => McpController::class.'::mcpAction',
                'method'     => 'GET|POST|DELETE|OPTIONS',
            ],
            // RFC 9728: both the plain and the path-inserted location.
            'intellectit_mcp_resource_metadata' => [
                'path'       => '/.well-known/oauth-protected-resource',
                'controller' => McpController::class.'::resourceMetadataAction',
                'method'     => 'GET|OPTIONS',
            ],
            'intellectit_mcp_resource_metadata_path' => [
                'path'       => '/.well-known/oauth-protected-resource/mcp',
                'controller' => McpController::class.'::resourceMetadataAction',
                'method'     => 'GET|OPTIONS',
            ],
        ],
    ],
    'parameters' => [
        // Operator ceiling for write tools (everything that creates or changes
        // data). Off by default: they are not listed and cannot be called, whatever
        // scopes the token holds. Turn on with intellectit_mcp_allow_write=true.
        'intellectit_mcp_allow_write' => false,
        // Maximum items a list tool returns.
        'intellectit_mcp_page_limit' => 50,
        // Allow changes to RUNNING (published) campaigns at all. The token
        // additionally needs the scope mcp:campaigns:live.
        'intellectit_mcp_live_campaign_edits' => true,
    ],
];

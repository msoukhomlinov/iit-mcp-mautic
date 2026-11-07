<?php

declare(strict_types=1);

use MauticPlugin\IntellectItMcpBundle\Controller\McpController;

return [
    'name'        => 'MCP',
    'description' => 'Model Context Protocol server for AI assistants (Claude, ChatGPT). Reads contacts, segments, emails and campaigns and offers insights. Operator-enabled tiers: writes (drafts, segments, contacts, scoring, campaign flows) and email sending with preview and confirmation.',
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
        // Real email sending (preview_email_send, send_email). Off by default; the
        // token additionally needs the opt-in scope mcp:send. Unpublished emails are never sent.
        'intellectit_mcp_allow_send' => false,
        // Sending to whole segments needs this on top of intellectit_mcp_allow_send.
        'intellectit_mcp_allow_segment_send' => false,
        // Maximum recipients of one send call (contacts given, or contacts reached in a segment).
        'intellectit_mcp_send_max_recipients' => 100,
        // DANGEROUS: also email contacts on the Do Not Contact list (direct contact sends
        // only). Leave off: it can breach unsubscribe obligations such as the Spam Act or GDPR.
        'intellectit_mcp_send_ignore_dnc' => false,
        // Maximum items a list tool returns.
        'intellectit_mcp_page_limit' => 50,
        // Allow changes to RUNNING (published) campaigns and to published point triggers.
        // Off by default: such changes are live and Mautic's scheduler can email contacts
        // because of them, outside the send tier. The token additionally needs the scope
        // mcp:campaigns:live, and the Write tier must be on.
        'intellectit_mcp_live_campaign_edits' => false,
    ],
];

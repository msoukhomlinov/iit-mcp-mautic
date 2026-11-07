<?php

declare(strict_types=1);

namespace MauticPlugin\IntellectItMcpBundle\Service;

use Doctrine\DBAL\Connection;
use Mautic\CoreBundle\Helper\CoreParametersHelper;

/**
 * What is configured and what is missing for the MCP server to work. Shared by
 * the CLI (mautic:mcp:setup) and the tool mautic_setup_status. Secrets are
 * never returned, only whether they are usable.
 */
class SetupStatus
{
    public function __construct(
        private readonly CoreParametersHelper $parameters,
        private readonly Connection $db,
    ) {
    }

    /**
     * @return array{ok: bool, endpoint: string, checks: list<array{check: string, status: string, detail: string, fix?: string}>}
     */
    public function report(): array
    {
        $siteUrl = rtrim((string) $this->parameters->get('site_url'), '/');
        $checks  = [];
        $add     = static function (string $check, string $status, string $detail, ?string $fix = null) use (&$checks): void {
            $checks[] = ['check' => $check, 'status' => $status, 'detail' => $detail] + (null !== $fix ? ['fix' => $fix] : []);
        };

        $host = (string) parse_url($siteUrl, PHP_URL_HOST);
        if ('' === $siteUrl) {
            $add('site_url', 'fail', 'Mautic has no site URL.', 'Set it in Settings > Configuration > System settings.');
        } elseif (!str_starts_with($siteUrl, 'https://') && !in_array($host, ['localhost', '127.0.0.1'], true)) {
            $add('site_url', 'warn', "$siteUrl is not https. MCP clients such as Claude and ChatGPT require https.", 'Serve Mautic over https and update the site URL.');
        } else {
            $add('site_url', 'ok', $siteUrl);
        }

        $key = trim((string) $this->parameters->get('kopexa_oauth_private_key'));
        $pk  = '' !== $key ? @openssl_pkey_get_private($key) : false;
        $det = false !== $pk ? openssl_pkey_get_details($pk) : false;
        if ('' === $key) {
            $add('oauth signing key', 'fail', 'kopexa_oauth_private_key is empty.', 'Run: php bin/console mautic:mcp:setup');
        } elseif (!is_array($det) || OPENSSL_KEYTYPE_RSA !== ($det['type'] ?? null)) {
            $add('oauth signing key', 'fail', 'kopexa_oauth_private_key is not a valid RSA key.', 'Run: php bin/console mautic:mcp:setup --force');
        } elseif (($det['bits'] ?? 0) < 2048) {
            $add('oauth signing key', 'warn', sprintf('RSA key is only %d bits.', $det['bits']), 'Run: php bin/console mautic:mcp:setup --force');
        } else {
            $add('oauth signing key', 'ok', sprintf('RSA %d bits', $det['bits']));
        }

        $enc = trim((string) $this->parameters->get('kopexa_oauth_encryption_key'));
        if ('' === $enc) {
            $add('oauth encryption key', 'fail', 'kopexa_oauth_encryption_key is empty.', 'Run: php bin/console mautic:mcp:setup');
        } elseif (strlen($enc) < 32) {
            $add('oauth encryption key', 'warn', 'The encryption key is shorter than 32 characters.', 'Run: php bin/console mautic:mcp:setup --force');
        } else {
            $add('oauth encryption key', 'ok', 'set');
        }

        $hosts = $this->parameters->get('kopexa_oauth_redirect_hosts');
        $hosts = array_values(array_filter(is_array($hosts) ? $hosts : (preg_split('/[\s,]+/', (string) $hosts) ?: [])));
        $add('oauth redirect hosts', [] === $hosts ? 'fail' : 'ok', [] === $hosts ? 'No client may complete sign-in.' : implode(', ', $hosts), [] === $hosts ? 'Run: php bin/console mautic:mcp:setup --redirect-host=claude.ai' : null);

        $tiers = [];
        foreach (['write' => 'allow_write', 'live campaign edits' => 'live_campaign_edits', 'send' => 'allow_send', 'segment send' => 'allow_segment_send', 'destructive' => 'allow_destructive'] as $label => $param) {
            $tiers[] = $label.'='.($this->parameters->get('intellectit_mcp_'.$param) ? 'on' : 'off');
        }
        $hiddenGroups = $this->parameters->get('intellectit_mcp_hidden_groups', []);
        $hiddenGroups = is_array($hiddenGroups) ? $hiddenGroups : (preg_split('/[\s,]+/', (string) $hiddenGroups, -1, PREG_SPLIT_NO_EMPTY) ?: []);
        $add('operator tiers', 'info', implode(', ', $tiers).'; caller mode '.(string) $this->parameters->get('intellectit_mcp_caller_mode', 'attended').([] === $hiddenGroups ? '' : '; hidden groups: '.implode(', ', $hiddenGroups)));

        $prefix = defined('MAUTIC_TABLE_PREFIX') ? (string) MAUTIC_TABLE_PREFIX : '';
        try {
            $last = $this->db->fetchOne("SELECT MAX(last_built_date) FROM {$prefix}lead_lists WHERE is_published = 1 AND filters <> 'a:0:{}'");
            if (!$last) {
                $add('cron', 'info', 'No dynamic segment has been rebuilt yet, so cron cannot be checked.');
            } elseif (strtotime((string) $last.' UTC') < time() - 86400) {
                $add('cron', 'warn', "Segments were last rebuilt $last UTC (over a day ago). Campaigns need cron.", 'Schedule mautic:segments:update and mautic:campaigns:update/trigger (see the README).');
            } else {
                $add('cron', 'ok', "Segments last rebuilt $last UTC.");
            }
        } catch (\Throwable) {
            $add('cron', 'info', 'Could not read segment rebuild times.');
        }

        $ok = [] === array_filter($checks, static fn (array $c): bool => 'fail' === $c['status']);

        return ['ok' => $ok, 'endpoint' => $siteUrl.'/mcp', 'checks' => $checks];
    }
}

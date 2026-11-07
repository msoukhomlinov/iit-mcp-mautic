<?php

declare(strict_types=1);

namespace MauticPlugin\IntellectItMcpBundle\Tools;

use Doctrine\DBAL\Connection;
use Mautic\CampaignBundle\Entity\Campaign;
use Mautic\CampaignBundle\Model\CampaignModel;
use Mautic\LeadBundle\Entity\LeadList;
use Mautic\LeadBundle\Model\ListModel;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Server\RequestContext;
use MauticPlugin\IntellectItMcpBundle\Service\McpUserContext;

/**
 * Read-only summaries that would otherwise take many list/get calls
 * (scope mcp:read). Counts come from SQL, not from loading entities.
 */
class InsightTools
{
    /** Columns find_duplicate_contacts may group by (never taken from user input as SQL). */
    private const DUPLICATE_COLUMNS = ['email', 'phone', 'mobile'];

    public function __construct(
        private readonly McpUserContext $auth,
        private readonly Connection $db,
        private readonly ListModel $segments,
        private readonly CampaignModel $campaigns,
    ) {
    }

    /**
     * Summarise a segment without paging through it: size, static or dynamic, what its filters are, when Mautic last rebuilt it and optionally a few sample contacts.
     *
     * @param int $id         Segment ID
     * @param int $sampleSize Sample contacts to include (0 = none)
     *
     * @return array<string, mixed>
     */
    public function getSegmentSummary(RequestContext $context, int $id, #[Schema(minimum: 0, maximum: 10)] int $sampleSize = 0): array
    {
        $segment = $this->segments->getEntity($id);
        if (!$segment instanceof LeadList || !$segment->getId()) {
            throw new ToolCallException(sprintf('Segment %d not found.', $id));
        }
        $this->auth->requireEntityAccess($context, $segment, 'lead:lists', 'view');
        $sampleSize = min(max(0, $sampleSize), 10); // the schema bound is only a hint to clients

        $members = $this->table('lead_lists_leads');
        $count   = (int) $this->db->fetchOne("SELECT COUNT(*) FROM $members WHERE leadlist_id = :id AND manually_removed = 0", ['id' => $id]);
        $filters = array_values(array_map(
            static fn (array $f): array => ['field' => $f['field'] ?? $f['glue'] ?? null, 'operator' => $f['operator'] ?? null],
            array_filter((array) $segment->getFilters(), 'is_array'),
        ));

        $summary = [
            'id'          => $segment->getId(),
            'name'        => $segment->getName(),
            'published'   => $segment->isPublished(),
            'type'        => [] === $filters ? 'static' : 'dynamic',
            'contacts'    => $count,
            'filters'     => $filters,
            'last_rebuilt' => $segment->getLastBuiltDate()?->format(DATE_ATOM),
        ];

        if ($sampleSize > 0) {
            if (!$this->auth->canViewOther('lead:leads')) {
                $summary['sample_note'] = 'Samples are only shown to users who may view contacts of others.';

                return $summary;
            }
            $leads = $this->table('leads');
            $summary['sample'] = $this->db->fetchAllAssociative(
                "SELECT l.id, l.firstname, l.lastname, l.email FROM $members m JOIN $leads l ON l.id = m.lead_id WHERE m.leadlist_id = :id AND m.manually_removed = 0 ORDER BY l.id DESC LIMIT $sampleSize",
                ['id' => $id],
            );
        }

        return $summary;
    }

    /**
     * Find contacts that share the same email, phone or mobile number (case and surrounding spaces ignored). Returns groups of contact IDs, largest first. Read-only: merging is done in Mautic.
     *
     * @param string $field Which field must match: email, phone or mobile
     * @param int    $limit Maximum number of groups
     *
     * @return array<string, mixed>
     */
    public function findDuplicateContacts(RequestContext $context, string $field = 'email', #[Schema(minimum: 1, maximum: 100)] int $limit = 20): array
    {
        $this->auth->require($context, 'lead:leads:viewother');
        if (!in_array($field, self::DUPLICATE_COLUMNS, true)) {
            throw new ToolCallException(sprintf('field must be one of: %s.', implode(', ', self::DUPLICATE_COLUMNS)));
        }
        $leads = $this->table('leads');
        $limit = min(max(1, $limit), 100);
        $rows  = $this->db->fetchAllAssociative(
            "SELECT LOWER(TRIM($field)) AS value, COUNT(*) AS contacts, GROUP_CONCAT(id ORDER BY id) AS ids
             FROM $leads WHERE $field IS NOT NULL AND TRIM($field) <> ''
             GROUP BY LOWER(TRIM($field)) HAVING COUNT(*) > 1 ORDER BY contacts DESC, value LIMIT $limit",
        );

        return [
            'field'  => $field,
            'groups' => array_map(static fn (array $r): array => [
                'value'       => $r['value'],
                'contacts'    => (int) $r['contacts'],
                'contact_ids' => array_map('intval', explode(',', (string) $r['ids'])),
            ], $rows),
            'hint'   => 'Review the contacts with get_contact, then merge them in Mautic (contact list, select, Merge). Groups are capped by limit.',
        ];
    }

    /**
     * Health check for a campaign: contacts in it, how many steps are scheduled or overdue, recent activity and failures with their reasons, plus plain-language warnings.
     *
     * @param int $id Campaign ID
     *
     * @return array<string, mixed>
     */
    public function getCampaignHealth(RequestContext $context, int $id): array
    {
        $campaign = $this->campaigns->getEntity($id);
        if (!$campaign instanceof Campaign || !$campaign->getId()) {
            throw new ToolCallException(sprintf('Campaign %d not found.', $id));
        }
        $this->auth->requireEntityAccess($context, $campaign, 'campaign:campaigns', 'view');

        $log      = $this->table('campaign_lead_event_log');
        $failed   = $this->table('campaign_lead_event_failed_log');
        $now      = gmdate('Y-m-d H:i:s');
        $overdue  = gmdate('Y-m-d H:i:s', time() - 3600);
        $day      = gmdate('Y-m-d H:i:s', time() - 86400);
        $p        = ['id' => $id];

        $contacts = (int) $this->db->fetchOne('SELECT COUNT(*) FROM '.$this->table('campaign_leads').' WHERE campaign_id = :id AND manually_removed = 0', $p);
        $steps    = (int) $this->db->fetchOne('SELECT COUNT(*) FROM '.$this->table('campaign_events').' WHERE campaign_id = :id AND deleted IS NULL', $p);
        $sched    = (int) $this->db->fetchOne("SELECT COUNT(*) FROM $log WHERE campaign_id = :id AND is_scheduled = 1", $p);
        $late     = (int) $this->db->fetchOne("SELECT COUNT(*) FROM $log WHERE campaign_id = :id AND is_scheduled = 1 AND trigger_date < :t", $p + ['t' => $overdue]);
        $recent   = (int) $this->db->fetchOne("SELECT COUNT(*) FROM $log WHERE campaign_id = :id AND is_scheduled = 0 AND date_triggered >= :d", $p + ['d' => $day]);
        $lastRun  = $this->db->fetchOne("SELECT MAX(date_triggered) FROM $log WHERE campaign_id = :id AND is_scheduled = 0", $p);
        $failTot  = (int) $this->db->fetchOne("SELECT COUNT(*) FROM $failed f JOIN $log l ON l.id = f.log_id WHERE l.campaign_id = :id", $p);
        $fail24   = (int) $this->db->fetchOne("SELECT COUNT(*) FROM $failed f JOIN $log l ON l.id = f.log_id WHERE l.campaign_id = :id AND f.date_added >= :d", $p + ['d' => $day]);
        $reasons  = $this->db->fetchAllAssociative("SELECT LEFT(f.reason, 200) AS reason, COUNT(*) AS count FROM $failed f JOIN $log l ON l.id = f.log_id WHERE l.campaign_id = :id GROUP BY LEFT(f.reason, 200) ORDER BY count DESC LIMIT 3", $p);

        $warnings = [];
        if (!$campaign->isPublished()) {
            $warnings[] = 'Campaign is unpublished: Mautic does not run it.';
        }
        if ($campaign->isPublished() && 0 === $contacts) {
            $warnings[] = 'Published, but no contacts are in it (check its segments).';
        }
        if ($late > 0) {
            $warnings[] = sprintf('%d scheduled steps are more than an hour overdue: is the campaigns:trigger cron running?', $late);
        }
        if ($fail24 > 0) {
            $warnings[] = sprintf('%d step failures in the last 24 hours.', $fail24);
        }
        if ($campaign->isPublished() && $contacts > 0 && 0 === $recent && 0 === $sched) {
            $warnings[] = 'Nothing ran in the last 24 hours and nothing is scheduled.';
        }

        return [
            'id'                 => $campaign->getId(),
            'name'               => $campaign->getName(),
            'published'          => $campaign->isPublished(),
            'status'             => [] === $warnings ? 'ok' : 'attention',
            'contacts'           => $contacts,
            'steps'              => $steps,
            'scheduled_steps'    => $sched,
            'overdue_steps'      => $late,
            'steps_run_24h'      => $recent,
            'last_step_run'      => $lastRun ? str_replace(' ', 'T', (string) $lastRun).'Z' : null,
            'failures_total'     => $failTot,
            'failures_24h'       => $fail24,
            'top_failure_reasons' => $reasons,
            'warnings'           => $warnings,
            'checked_at'         => str_replace(' ', 'T', $now).'Z',
        ];
    }

    private function table(string $name): string
    {
        return (defined('MAUTIC_TABLE_PREFIX') ? (string) MAUTIC_TABLE_PREFIX : '').$name;
    }
}

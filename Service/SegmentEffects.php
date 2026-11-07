<?php

declare(strict_types=1);

namespace MauticPlugin\IntellectItMcpBundle\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Mautic\LeadBundle\Entity\LeadList;

/**
 * What a change of segment membership sets in motion. Shared by the
 * contact and segment tools.
 */
class SegmentEffects
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * What joining these segments sets in motion: running campaigns that use
     * them (Mautic's scheduler adds new members) and published segment
     * emails (the broadcast job sends them to members who did not get them).
     *
     * @param list<LeadList> $segments
     *
     * @return array<string, mixed>
     */
    public function forSegments(array $segments): array
    {
        return $this->forSegmentIds(array_map(fn (LeadList $s): int => (int) $s->getId(), $segments));
    }

    /**
     * @param list<int> $ids
     *
     * @return array<string, mixed>
     */
    public function forSegmentIds(array $ids): array
    {
        $prefix = defined('MAUTIC_TABLE_PREFIX') ? (string) MAUTIC_TABLE_PREFIX : '';
        $campaigns = $this->connection->fetchAllAssociative(
            "SELECT DISTINCT c.id, c.name FROM {$prefix}campaigns c JOIN {$prefix}campaign_leadlist_xref x ON x.campaign_id = c.id
             WHERE x.leadlist_id IN (?) AND c.is_published = 1 AND c.deleted IS NULL ORDER BY c.id",
            [$ids], [ArrayParameterType::INTEGER]
        );
        $emails = $this->connection->fetchAllAssociative(
            "SELECT DISTINCT e.id, e.name, e.subject FROM {$prefix}emails e JOIN {$prefix}email_list_xref x ON x.email_id = e.id
             WHERE x.leadlist_id IN (?) AND e.is_published = 1 AND e.email_type = 'list'
               AND (e.publish_down IS NULL OR e.publish_down > ?) ORDER BY e.id",
            [$ids, (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s')], [ArrayParameterType::INTEGER, ParameterType::STRING]
        );
        $cast = fn (array $rows): array => array_map(fn (array $r): array => ['id' => (int) $r['id']] + $r, $rows);

        return [
            'running_campaigns'        => $cast($campaigns),
            'published_segment_emails' => $cast($emails),
            'note'                     => [] === $campaigns && [] === $emails
                ? 'No running campaign or published segment email uses these segments.'
                : 'New members enter these campaigns with the next campaign run and get these segment emails with the next broadcast run (cron), unless they are Do-Not-Contact.',
        ];
    }
}

<?php

declare(strict_types=1);

namespace MauticPlugin\IntellectItMcpBundle\Tools;

use Doctrine\DBAL\Connection;
use Mautic\CampaignBundle\Entity\Campaign;
use Mautic\CampaignBundle\Model\CampaignModel;
use Mautic\EmailBundle\Entity\Email;
use Mautic\EmailBundle\Model\EmailModel;
use Mautic\LeadBundle\Deduplicate\ContactMerger;
use Mautic\LeadBundle\Entity\Company;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Entity\LeadList;
use Mautic\LeadBundle\Model\CompanyModel;
use Mautic\LeadBundle\Model\LeadModel;
use Mautic\LeadBundle\Model\ListModel;
use Mcp\Capability\Attribute\Schema;
use Mcp\Server\RequestContext;
use MauticPlugin\IntellectItMcpBundle\Service\McpError;
use MauticPlugin\IntellectItMcpBundle\Service\McpUserContext;

/**
 * Irreversible changes (scope mcp:destructive, off unless
 * intellectit_mcp_allow_destructive): delete a record, merge two contacts.
 * Both preview first; the real call needs confirm=true plus the expected
 * name (delete) or loser id (merge). Mautic has no undo.
 */
class DestructiveTools
{
    private const TYPES = ['contact', 'company', 'segment', 'email', 'campaign'];

    public function __construct(
        private readonly McpUserContext $auth,
        private readonly Connection $db,
        private readonly LeadModel $leads,
        private readonly CompanyModel $companies,
        private readonly ListModel $segments,
        private readonly EmailModel $emails,
        private readonly CampaignModel $campaigns,
        private readonly ContactMerger $merger,
    ) {
    }

    /**
     * Permanently delete one contact, company, segment, email or campaign. Without confirm=true nothing is deleted and the preview shows the record and what depends on it. Emails and campaigns must be unpublished; a segment used by a campaign or email, or an email used by a campaign step, cannot be deleted. There is no undo.
     *
     * @param string      $type         What to delete: contact, company, segment, email or campaign
     * @param int         $id           ID of the record
     * @param bool        $confirm      true = really delete what the preview shows
     * @param string|null $expectedName With confirm=true: the "name" the preview showed (the contact's email or full name). The delete is refused if it differs.
     *
     * @return array<string, mixed>
     */
    public function deleteRecord(
        RequestContext $context,
        #[Schema(enum: self::TYPES)] string $type,
        int $id,
        bool $confirm = false,
        ?string $expectedName = null,
    ): array {
        if (!in_array($type, self::TYPES, true)) {
            throw McpError::from(sprintf('type must be one of: %s.', implode(', ', self::TYPES)));
        }
        [$model, $base, $label, $impact] = match ($type) {
            'contact'  => [$this->leads, 'lead:leads', Lead::class, fn (int $i): array => $this->counts($i, ['email_stats' => ['lead_id', 'emails received'], 'campaign_leads' => ['lead_id', 'campaign memberships'], 'lead_lists_leads' => ['lead_id', 'segment memberships']])],
            'company'  => [$this->companies, 'lead:leads', Company::class, fn (int $i): array => $this->counts($i, ['companies_leads' => ['company_id', 'contacts in it']])],
            'segment'  => [$this->segments, 'lead:lists', LeadList::class, fn (int $i): array => $this->counts($i, ['lead_lists_leads' => ['leadlist_id', 'contacts in it'], 'campaign_leadlist_xref' => ['leadlist_id', 'campaigns using it'], 'email_list_xref' => ['leadlist_id', 'emails assigned to it']])],
            'email'    => [$this->emails, 'email:emails', Email::class, fn (int $i): array => $this->counts($i, ['email_stats' => ['email_id', 'sends recorded'], 'campaign_events' => ["channel = 'email' AND deleted IS NULL AND channel_id", 'campaign steps using it']])],
            'campaign' => [$this->campaigns, 'campaign:campaigns', Campaign::class, fn (int $i): array => $this->counts($i, ['campaign_leads' => ['campaign_id', 'contacts in it'], 'campaign_events' => ['campaign_id', 'steps']])],
        };
        $entity = $model->getEntity($id);
        if (!$entity instanceof $label || !$entity->getId()) {
            throw McpError::from(sprintf('%s %d not found.', ucfirst($type), $id));
        }
        $this->auth->requireEntityAccess($context, $entity, $base, 'delete');

        $name = match (true) {
            $entity instanceof Lead    => trim((string) ($entity->getEmail() ?: $entity->getName())),
            $entity instanceof Company => (string) $entity->getName(),
            default                    => (string) $entity->getName(),
        };
        $impacts  = $impact((int) $id);
        $blockers = [];
        if (($entity instanceof Email || $entity instanceof Campaign) && $entity->isPublished()) {
            $blockers[] = sprintf('The %s is published. Unpublish it in Mautic first.', $type);
        }
        if ($entity instanceof LeadList && (($impacts['campaigns using it'] ?? 0) > 0 || ($impacts['emails assigned to it'] ?? 0) > 0)) {
            $blockers[] = 'The segment is used by a campaign or email. Remove it there first.';
        }
        if ($entity instanceof Email && ($impacts['campaign steps using it'] ?? 0) > 0) {
            // Mautic deletes the email and leaves the campaign step pointing at nothing.
            $blockers[] = 'The email is used by a campaign step. Remove it from the campaign first.';
        }
        $preview = ['type' => $type, 'id' => $id, 'name' => $name, 'impact' => $impacts, 'blockers' => $blockers, 'can_delete' => [] === $blockers];

        if (!$confirm) {
            return ['deleted' => false, 'message' => 'Nothing deleted. Check this preview (an attending person must see it); to delete, call again with confirm=true and expectedName.'] + $preview;
        }
        if ([] !== $blockers) {
            $this->auth->audit($type, $id, 'delete_blocked', ['blockers' => $blockers]);
            throw McpError::from('Not deleted: '.implode(' ', $blockers));
        }
        if (null === $expectedName || $expectedName !== $name) {
            throw McpError::from(sprintf('Pass expectedName "%s" (as the preview shows) to confirm this delete.', $name), McpError::CONFIRM_REQUIRED);
        }

        $this->auth->audit($type, $id, 'delete', ['name' => $name, 'impact' => $impacts]);
        $model->deleteEntity($entity);

        return ['deleted' => true] + $preview;
    }

    /**
     * Merge two contacts into one: the loser's activity, tags, points and missing values move to the winner and the loser is deleted. Without confirm=true nothing changes and the preview shows both contacts and the fields that differ (the newest value wins). There is no undo.
     *
     * @param int      $winnerId        Contact that stays
     * @param int      $loserId         Contact that is merged in and deleted
     * @param bool     $confirm         true = really merge what the preview shows
     * @param int|null $expectedLoserId With confirm=true: the loserId the preview showed
     *
     * @return array<string, mixed>
     */
    public function mergeContacts(RequestContext $context, int $winnerId, int $loserId, bool $confirm = false, ?int $expectedLoserId = null): array
    {
        if ($winnerId === $loserId) {
            throw McpError::from('winnerId and loserId must be different contacts.');
        }
        $winner = $this->contact($winnerId);
        $loser  = $this->contact($loserId);
        $this->auth->requireEntityAccess($context, $winner, 'lead:leads', 'edit');
        $this->auth->requireEntityAccess($context, $loser, 'lead:leads', 'delete');

        $wf = $winner->getProfileFields();
        $lf = $loser->getProfileFields();
        $differs = [];
        foreach ($lf as $field => $value) {
            if (in_array($field, ['id', 'points'], true) || '' === (string) $value || '' === (string) ($wf[$field] ?? '') || $value === $wf[$field]) {
                continue;
            }
            $differs[$field] = ['winner' => $wf[$field], 'loser' => $value];
        }
        $summary = static fn (Lead $l): array => ['id' => (int) $l->getId(), 'email' => $l->getEmail(), 'name' => $l->getName(), 'points' => (int) $l->getPoints()];
        $preview = [
            'winner'        => $summary($winner),
            'loser'         => $summary($loser),
            'points_after'  => (int) $winner->getPoints() + (int) $loser->getPoints(),
            'fields_differ' => $differs,
            'note'          => 'For fields that differ, Mautic keeps the value of the more recently modified contact. The loser is deleted.',
        ];
        if (!$confirm) {
            return ['merged' => false, 'message' => 'Nothing merged. Check this preview (an attending person must see it); to merge, call again with confirm=true and expectedLoserId.'] + $preview;
        }
        if ($expectedLoserId !== $loserId) {
            throw McpError::from(sprintf('Pass expectedLoserId %d (as the preview shows) to confirm this merge.', $loserId), McpError::CONFIRM_REQUIRED);
        }

        $this->auth->audit('contact', $winnerId, 'merge', ['loser' => $loserId, 'loser_email' => $loser->getEmail()]);
        $this->merger->merge($winner, $loser);

        return ['merged' => true] + $preview;
    }

    private function contact(int $id): Lead
    {
        $lead = $this->leads->getEntity($id);
        if (!$lead instanceof Lead || !$lead->getId()) {
            throw McpError::from(sprintf('Contact %d not found.', $id));
        }

        return $lead;
    }

    /**
     * @param array<string, array{0: string, 1: string}> $tables table => [column, label]
     *
     * @return array<string, int>
     */
    private function counts(int $id, array $tables): array
    {
        $prefix = defined('MAUTIC_TABLE_PREFIX') ? (string) MAUTIC_TABLE_PREFIX : '';
        $out    = [];
        foreach ($tables as $table => [$column, $label]) {
            $out[$label] = (int) $this->db->fetchOne("SELECT COUNT(*) FROM {$prefix}{$table} WHERE {$column} = :id", ['id' => $id]);
        }

        return $out;
    }
}

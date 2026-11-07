<?php

declare(strict_types=1);

namespace MauticPlugin\IntellectItMcpBundle\Tools;

use Doctrine\DBAL\Connection;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\EmailBundle\Entity\Email;
use Mautic\EmailBundle\Model\EmailModel;
use Mautic\LeadBundle\Entity\DoNotContact as DncEntity;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Entity\LeadList;
use Mautic\LeadBundle\Model\DoNotContact;
use Mautic\LeadBundle\Model\LeadModel;
use Mautic\LeadBundle\Model\ListModel;
use Mcp\Capability\Attribute\Schema;
use MauticPlugin\IntellectItMcpBundle\Service\McpError;
use Mcp\Server\RequestContext;
use MauticPlugin\IntellectItMcpBundle\Service\McpUserContext;

/**
 * Real email sending (scope mcp:send, off unless intellectit_mcp_allow_send).
 * Every send is previewed first: mautic_send_email without confirm=true only
 * returns the preview. Do-Not-Contact contacts are excluded unless the
 * operator set intellectit_mcp_send_ignore_dnc (direct contact sends only:
 * Mautic's own segment sending always skips them). Publishing an email
 * stays with a person: unpublished emails are never sent.
 */
class SendTools
{
    public function __construct(
        private readonly McpUserContext $auth,
        private readonly EmailModel $emails,
        private readonly LeadModel $leads,
        private readonly ListModel $segments,
        private readonly DoNotContact $dnc,
        private readonly CoreParametersHelper $parameters,
        private readonly Connection $db,
    ) {
    }

    /**
     * Preview a send without sending anything: who would receive the email, who is excluded and why (Do Not Contact, no valid address) and what could stop the send. Target either contacts (contactIds) or segments (segmentIds), not both. Call this before mautic_send_email and check the result (an attending person sees it first).
     *
     * @param int       $emailId    Email ID
     * @param list<int> $contactIds Contacts to email directly
     * @param list<int> $segmentIds Segments of a segment email (must be segments the email is assigned to)
     *
     * @return array<string, mixed>
     */
    public function previewEmailSend(RequestContext $context, int $emailId, array $contactIds = [], array $segmentIds = []): array
    {
        $preview = $this->plan($context, $emailId, $contactIds, $segmentIds, 'view')['preview'];
        $this->auditPreview($emailId, $preview, 'preview');

        return $preview;
    }

    /**
     * Record that a send was previewed (who looked at what), so a later send can be traced to its preview. Not counted against the hourly limit.
     *
     * @param array<string, mixed> $preview
     */
    private function auditPreview(int $emailId, array $preview, string $via): void
    {
        // The id lists are capped (the input can be far larger than a send), and a failing audit write must not break a preview.
        $targets = array_map(static fn (array $ids): array => array_slice($ids, 0, 100), $preview['targets']);
        try {
            $this->auth->audit('email', $emailId, 'send_preview', [
                'mode'            => $preview['mode'],
                'targets'         => $targets,
                'targets_hash'    => $preview['targets_hash'],
                'recipient_count' => $preview['recipient_count'],
                'can_send'        => $preview['can_send'],
                'blockers'        => $preview['blockers'],
                'call'            => $via,
            ]);
        } catch (\Throwable) {
        }
    }

    /**
     * Send an email to real recipients. Without confirm=true nothing is sent and the preview is returned. Check the preview (an attending person must see it and agree) and call again with confirm=true, expectedRecipientCount the preview's targets_hash and EXACTLY the same contactIds/segmentIds. The email must be published (a person publishes it in Mautic). Do-Not-Contact contacts and contacts who already got this email are skipped. Sends are capped per call; segment sends need an extra operator setting.
     *
     * @param int       $emailId    Email ID
     * @param list<int> $contactIds Contacts to email directly
     * @param list<int> $segmentIds Segments of a segment email (must be segments the email is assigned to)
     * @param bool      $confirm    true = really send what the preview shows
     * @param int|null  $expectedRecipientCount With confirm=true: the recipient_count the preview showed. The send is refused if the recipients now differ.
     * @param string    $targetsHash With confirm=true: the targets_hash the preview showed. The send is refused if the contactIds/segmentIds differ from the previewed ones.
     *
     * @return array<string, mixed>
     */
    public function sendEmail(RequestContext $context, int $emailId, array $contactIds = [], array $segmentIds = [], bool $confirm = false, #[Schema(minimum: 0)] ?int $expectedRecipientCount = null, string $targetsHash = ''): array
    {
        if (!$confirm) {
            return $this->sendPlanned($context, $emailId, $contactIds, $segmentIds, false, $expectedRecipientCount, $targetsHash);
        }
        // One confirmed send at a time: the hourly limit check and the audit row that counts it must not interleave.
        if (1 !== (int) $this->db->fetchOne("SELECT GET_LOCK('intellectit_mcp_send', 10)")) {
            throw McpError::from('Another send is in progress on this instance. Try again in a moment.', McpError::BLOCKED_BY_POLICY);
        }
        try {
            return $this->sendPlanned($context, $emailId, $contactIds, $segmentIds, true, $expectedRecipientCount, $targetsHash);
        } finally {
            $this->db->fetchOne("SELECT RELEASE_LOCK('intellectit_mcp_send')");
        }
    }

    /**
     * @param list<int> $contactIds
     * @param list<int> $segmentIds
     *
     * @return array<string, mixed>
     */
    private function sendPlanned(RequestContext $context, int $emailId, array $contactIds, array $segmentIds, bool $confirm, ?int $expectedRecipientCount, string $targetsHash): array
    {
        $plan    = $this->plan($context, $emailId, $contactIds, $segmentIds, 'edit');
        $preview = $plan['preview'];
        if (!$confirm) {
            $this->auditPreview($emailId, $preview, 'send_without_confirm');
            $next = 'unattended' === $this->parameters->get('intellectit_mcp_caller_mode', 'attended')
                ? 'Check this preview against your task, then call again with confirm=true, expectedRecipientCount and targetsHash.'
                : 'Show this preview to the user and call again with confirm=true after they agree.';

            return ['sent' => false, 'message' => 'Nothing sent. '.$next] + $preview;
        }
        if ([] !== $preview['blockers']) {
            $this->auth->audit('email', $emailId, 'send_blocked', ['mode' => $preview['mode'], 'targets_hash' => $preview['targets_hash'], 'blockers' => $preview['blockers']]);
            throw McpError::from('Not sent: '.implode(' ', $preview['blockers']));
        }

        if ('' === $targetsHash || !hash_equals($preview['targets_hash'], $targetsHash)) {
            $this->auth->audit('email', $emailId, 'send_blocked', ['mode' => $preview['mode'], 'reason' => 'targets_hash', 'targets_hash' => $targetsHash]);
            throw McpError::from(sprintf('Not sent: targetsHash does not match these contactIds/segmentIds. Preview this exact selection and confirm with its targets_hash (%s).', $preview['targets_hash']));
        }
        if (null === $expectedRecipientCount) {
            throw McpError::from(sprintf('Pass expectedRecipientCount (the preview shows %d) to confirm this send.', $preview['recipient_count']));
        }
        if ($expectedRecipientCount !== $preview['recipient_count']) {
            $this->auth->audit('email', $emailId, 'send_blocked', ['mode' => $preview['mode'], 'targets_hash' => $preview['targets_hash'], 'expected' => $expectedRecipientCount, 'actual' => $preview['recipient_count']]);
            throw McpError::from(sprintf('Not sent: you expected %d recipients but %d would receive it now. Preview again and confirm the new number (with the attending person, if any).', $expectedRecipientCount, $preview['recipient_count']));
        }

        /** @var Email $email */
        $email = $plan['email'];
        try {
            $result = $this->deliver($email, $plan, $preview);
        } catch (\Throwable $e) {
            // Mautic may have sent some before failing: count the planned recipients against the hourly limit.
            $this->auth->audit('email', (int) $email->getId(), 'send', ['mode' => $preview['mode'], 'targets_hash' => $preview['targets_hash'], 'contactIds' => $contactIds, 'segmentIds' => $segmentIds, 'sent' => (int) $preview['recipient_count'], 'failed' => 0, 'error' => $e->getMessage()]);
            throw $e;
        }
        $this->auth->audit('email', (int) $email->getId(), 'send', [
            'mode'         => $preview['mode'],
            'targets_hash' => $preview['targets_hash'],
            'contactIds'   => $contactIds,
            'segmentIds'   => $segmentIds,
            'sent'         => $result['sent_count'],
            'failed'       => $result['failed_count'],
            'cap'          => $preview['max_recipients_per_call'],
            'dnc_override' => (bool) $this->parameters->get('intellectit_mcp_send_ignore_dnc', false),
        ]);

        return $result + ['email' => ['id' => $email->getId(), 'name' => $email->getName()]];
    }

    /**
     * @param array<string, mixed> $plan
     * @param array<string, mixed> $preview
     *
     * @return array<string, mixed>
     */
    private function deliver(Email $email, array $plan, array $preview): array
    {
        if ('segments' === $preview['mode']) {
            [$sent, $failed] = $this->emails->sendEmailToLists($email, $plan['lists'], $plan['cap']);
            $result          = ['sent' => true, 'sent_count' => (int) $sent, 'failed_count' => (int) $failed];
        } else {
            $errors = $this->emails->sendEmail($email, $plan['recipients'], [
                'source'        => ['mcp', 0],
                'allowResends'  => false,
                'ignoreDNC'     => (bool) $this->parameters->get('intellectit_mcp_send_ignore_dnc', false),
                'return_errors' => true,
            ]);
            if (false === $errors) {
                throw McpError::from('Mautic did not send the email.');
            }
            $failed = is_array($errors) ? count($errors) : 0;
            $result = ['sent' => true, 'sent_count' => count($plan['recipients']) - $failed, 'failed_count' => $failed];
            if ($failed > 0) {
                $result['failures'] = array_slice(array_map('strval', $errors), 0, 10, true);
            }
        }
        return $result;
    }

    /**
     * Contacts that already have a delivery record of this email.
     *
     * @param list<int> $contactIds
     *
     * @return array<int, true>
     */
    private function alreadySent(int $emailId, array $contactIds): array
    {
        if ([] === $contactIds) {
            return [];
        }
        $prefix = defined('MAUTIC_TABLE_PREFIX') ? (string) MAUTIC_TABLE_PREFIX : '';
        $ids    = $this->db->fetchFirstColumn(
            "SELECT DISTINCT lead_id FROM {$prefix}email_stats WHERE email_id = :email AND lead_id IN (:ids)",
            ['email' => $emailId, 'ids' => $contactIds],
            ['ids' => \Doctrine\DBAL\ArrayParameterType::INTEGER],
        );

        return array_fill_keys(array_map('intval', $ids), true);
    }

    /** Recipients sent through this plugin in the last hour, from the audit log (all users). */
    private function sentInLastHour(): int
    {
        $prefix = defined('MAUTIC_TABLE_PREFIX') ? (string) MAUTIC_TABLE_PREFIX : '';
        $rows   = $this->db->fetchFirstColumn(
            "SELECT details FROM {$prefix}audit_log WHERE bundle = 'intellectit_mcp' AND object = 'email' AND action = 'send' AND date_added >= :since",
            ['since' => date('Y-m-d H:i:s', time() - 3600)], // the audit log writes date_added in PHP's default timezone
        );
        $sum = 0;
        foreach ($rows as $details) {
            $decoded = @unserialize((string) $details, ['allowed_classes' => false]); // the audit log stores details serialized
            $sum    += is_array($decoded) ? (int) ($decoded['sent'] ?? 0) : 0;
        }

        return $sum;
    }

    /**
     * @param list<int> $contactIds
     * @param list<int> $segmentIds
     *
     * @return array{preview: array<string, mixed>, email: Email, recipients: list<array<string, mixed>>, lists: list<LeadList>, cap: int}
     */
    private function plan(RequestContext $context, int $emailId, array $contactIds, array $segmentIds, string $leadAction): array
    {
        $contactIds = array_values(array_unique(array_map('intval', $contactIds)));
        $segmentIds = array_values(array_unique(array_map('intval', $segmentIds)));
        if (($contactIds === []) === ($segmentIds === [])) {
            throw McpError::from('Give either contactIds or segmentIds (exactly one of them).');
        }
        $email = $this->emails->getEntity($emailId);
        if (!$email instanceof Email || !$email->getId()) {
            throw McpError::from(sprintf('Email %d not found.', $emailId));
        }
        $this->auth->requireEntityAccess($context, $email, 'email:emails', 'view');

        $cap      = max(1, (int) $this->parameters->get('intellectit_mcp_send_max_recipients', 100));
        $blockers = [];
        $warnings = [];
        if (!$email->isPublished()) {
            $blockers[] = 'The email is not published. A person must publish it in Mautic first.';
        }

        $recipients = [];
        $lists      = [];
        $preview    = ['email' => ['id' => $email->getId(), 'name' => $email->getName(), 'subject' => $email->getSubject(), 'published' => $email->isPublished()], 'max_recipients_per_call' => $cap];

        if ([] !== $contactIds) {
            if (count($contactIds) > $cap) {
                $blockers[] = sprintf('%d contacts given, at most %d per call.', count($contactIds), $cap);
            }
            $ignoreDnc = (bool) $this->parameters->get('intellectit_mcp_send_ignore_dnc', false);
            $excluded  = ['do_not_contact' => [], 'no_valid_email' => [], 'already_sent' => []];
            $alreadySent = $this->alreadySent((int) $email->getId(), array_slice($contactIds, 0, $cap + 1));
            foreach (array_slice($contactIds, 0, $cap + 1) as $id) {
                $lead = $this->leads->getEntity($id);
                if (!$lead instanceof Lead || !$lead->getId()) {
                    throw McpError::from(sprintf('Contact %d not found.', $id));
                }
                $this->auth->requireEntityAccess($context, $lead, 'lead:leads', $leadAction);
                if (false === filter_var((string) $lead->getEmail(), FILTER_VALIDATE_EMAIL)) {
                    $excluded['no_valid_email'][] = $id;
                    continue;
                }
                if (!$ignoreDnc && DncEntity::IS_CONTACTABLE !== $this->dnc->isContactable($lead, 'email')) {
                    $excluded['do_not_contact'][] = $id;
                    continue;
                }
                if (isset($alreadySent[$id])) {
                    // Mautic would skip them (allowResends is off): list them instead of counting them as recipients.
                    $excluded['already_sent'][] = $id;
                    continue;
                }
                $fields       = array_merge($lead->getProfileFields(), ['id' => $id]); // profile fields carry id as a string
                $fields['owner_id'] = $lead->getOwner()?->getId();
                $recipients[] = $fields;
            }
            if ($ignoreDnc) {
                $warnings[] = 'The operator setting intellectit_mcp_send_ignore_dnc is on: contacts on the Do Not Contact list are NOT excluded.';
            }
            $preview += [
                'mode'             => 'contacts',
                'targets'          => ['contactIds' => $contactIds],
                'recipient_count'  => count($recipients),
                'recipients'       => array_map(static fn (array $r): array => ['id' => $r['id'], 'email' => $r['email'] ?? null], array_slice($recipients, 0, 10)),
                'excluded'         => $excluded,
            ];
            if ([] === $recipients && [] === $blockers) {
                $blockers[] = 'No eligible recipients.';
            }
        } else {
            if ('list' !== $email->getEmailType()) {
                $blockers[] = 'Only segment emails can be sent to segments; this is a template email. Send it to contacts, or use it in a campaign.';
            }
            if (!(bool) $this->parameters->get('intellectit_mcp_allow_segment_send', false)) {
                $blockers[] = 'Sending to segments is switched off on this instance (intellectit_mcp_allow_segment_send).';
            }
            // A segment holds other people's contacts too: same level as a direct send to them (view to preview, edit to send).
            $this->auth->require($context, "lead:leads:{$leadAction}other");
            $assigned = [];
            foreach ($email->getLists() as $list) {
                $assigned[(int) $list->getId()] = $list;
            }
            $pending = 0;
            $perList = [];
            foreach ($segmentIds as $sid) {
                if (!isset($assigned[$sid])) {
                    throw McpError::from(sprintf('Segment %d is not one of the segments this email is assigned to (%s).', $sid, [] === $assigned ? 'none' : implode(', ', array_keys($assigned))));
                }
                $this->auth->requireEntityAccess($context, $assigned[$sid], 'lead:lists', 'view');
                $count     = (int) $this->emails->getPendingLeads($email, $sid, true);
                $pending  += $count;
                $perList[] = ['segment_id' => $sid, 'name' => $assigned[$sid]->getName(), 'pending_contacts' => $count];
                $lists[]   = $assigned[$sid];
            }
            $preview += [
                'mode'             => 'segments',
                'targets'          => ['segmentIds' => $segmentIds],
                'recipient_count'  => min($pending, $cap),
                'pending_total'    => $pending,
                'segments'         => $perList,
                'note'             => 'Pending contacts exclude Do Not Contact and people who already got this email. A contact in several segments may be counted twice here but is emailed once.',
            ];
            $warnings[] = 'Once a person publishes a segment email, Mautic\'s own broadcast (mautic:broadcasts:send, run by cron) can also send it to ALL its segments, outside the per-call and hourly caps of this tool.';
            if ($pending > $cap) {
                $warnings[] = sprintf('%d contacts are pending but one call sends at most %d; repeat the call for the rest.', $pending, $cap);
            }
            if (0 === $pending && [] === $blockers) {
                $blockers[] = 'No pending recipients.';
            }
        }

        $perHour = max(0, (int) $this->parameters->get('intellectit_mcp_send_max_per_hour', 500));
        if ($perHour > 0) {
            $already = $this->sentInLastHour();
            $preview += ['sent_last_hour' => $already, 'max_recipients_per_hour' => $perHour];
            if ($already + (int) $preview['recipient_count'] > $perHour) {
                $blockers[] = sprintf('Hourly limit: %d already sent in the last hour, this send adds %d, the limit is %d (intellectit_mcp_send_max_per_hour). Try again later.', $already, $preview['recipient_count'], $perHour);
            }
        }

        $preview['targets_hash'] = substr(hash('sha256', json_encode([$emailId, $preview['targets']])), 0, 16);

        return [
            'preview'    => $preview + ['warnings' => $warnings, 'blockers' => $blockers, 'can_send' => [] === $blockers],
            'email'      => $email,
            'recipients' => $recipients,
            'lists'      => $lists,
            'cap'        => $cap,
        ];
    }
}

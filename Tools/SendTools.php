<?php

declare(strict_types=1);

namespace MauticPlugin\IntellectItMcpBundle\Tools;

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
use Mcp\Exception\ToolCallException;
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
    ) {
    }

    /**
     * Preview a send without sending anything: who would receive the email, who is excluded and why (Do Not Contact, no valid address) and what could stop the send. Target either contacts (contactIds) or segments (segmentIds), not both. Call this before mautic_send_email and show the result to the user.
     *
     * @param int       $emailId    Email ID
     * @param list<int> $contactIds Contacts to email directly
     * @param list<int> $segmentIds Segments of a segment email (must be segments the email is assigned to)
     *
     * @return array<string, mixed>
     */
    public function previewEmailSend(RequestContext $context, int $emailId, array $contactIds = [], array $segmentIds = []): array
    {
        return $this->plan($context, $emailId, $contactIds, $segmentIds, 'view')['preview'];
    }

    /**
     * Send an email to real recipients. Without confirm=true nothing is sent and the preview is returned. Show the preview to the user and call again with confirm=true only after they agree. The email must be published (a person publishes it in Mautic). Do-Not-Contact contacts are skipped. Sends are capped per call; segment sends need an extra operator setting.
     *
     * @param int       $emailId    Email ID
     * @param list<int> $contactIds Contacts to email directly
     * @param list<int> $segmentIds Segments of a segment email (must be segments the email is assigned to)
     * @param bool      $confirm    true = really send what the preview shows
     * @param int|null  $expectedRecipientCount With confirm=true: the recipient_count the preview showed. The send is refused if the recipients now differ.
     *
     * @return array<string, mixed>
     */
    public function sendEmail(RequestContext $context, int $emailId, array $contactIds = [], array $segmentIds = [], bool $confirm = false, #[Schema(minimum: 0)] ?int $expectedRecipientCount = null): array
    {
        $plan    = $this->plan($context, $emailId, $contactIds, $segmentIds, 'edit');
        $preview = $plan['preview'];
        if (!$confirm) {
            return ['sent' => false, 'message' => 'Nothing sent. Show this preview to the user and call again with confirm=true after they agree.'] + $preview;
        }
        if ([] !== $preview['blockers']) {
            $this->auth->audit('email', $emailId, 'send_blocked', ['mode' => $preview['mode'], 'blockers' => $preview['blockers']]);
            throw new ToolCallException('Not sent: '.implode(' ', $preview['blockers']));
        }

        if (null === $expectedRecipientCount) {
            throw new ToolCallException(sprintf('Pass expectedRecipientCount (the preview shows %d) to confirm this send.', $preview['recipient_count']));
        }
        if ($expectedRecipientCount !== $preview['recipient_count']) {
            $this->auth->audit('email', $emailId, 'send_blocked', ['mode' => $preview['mode'], 'expected' => $expectedRecipientCount, 'actual' => $preview['recipient_count']]);
            throw new ToolCallException(sprintf('Not sent: you expected %d recipients but %d would receive it now. Preview again and confirm the new number with the user.', $expectedRecipientCount, $preview['recipient_count']));
        }

        /** @var Email $email */
        $email = $plan['email'];
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
                throw new ToolCallException('Mautic did not send the email.');
            }
            $failed = is_array($errors) ? count($errors) : 0;
            $result = ['sent' => true, 'sent_count' => count($plan['recipients']) - $failed, 'failed_count' => $failed];
            if ($failed > 0) {
                $result['failures'] = array_slice(array_map('strval', $errors), 0, 10, true);
            }
        }
        $this->auth->audit('email', (int) $email->getId(), 'send', [
            'mode'       => $preview['mode'],
            'contactIds' => $contactIds,
            'segmentIds' => $segmentIds,
            'sent'       => $result['sent_count'],
            'failed'     => $result['failed_count'],
            'cap'        => $preview['max_recipients_per_call'],
            'dnc_override' => (bool) $this->parameters->get('intellectit_mcp_send_ignore_dnc', false),
        ]);

        return $result + ['email' => ['id' => $email->getId(), 'name' => $email->getName()]];
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
            throw new ToolCallException('Give either contactIds or segmentIds (exactly one of them).');
        }
        $email = $this->emails->getEntity($emailId);
        if (!$email instanceof Email || !$email->getId()) {
            throw new ToolCallException(sprintf('Email %d not found.', $emailId));
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
            $excluded  = ['do_not_contact' => [], 'no_valid_email' => []];
            foreach (array_slice($contactIds, 0, $cap + 1) as $id) {
                $lead = $this->leads->getEntity($id);
                if (!$lead instanceof Lead || !$lead->getId()) {
                    throw new ToolCallException(sprintf('Contact %d not found.', $id));
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
                $fields       = array_merge($lead->getProfileFields(), ['id' => $id]); // profile fields carry id as a string
                $fields['owner_id'] = $lead->getOwner()?->getId();
                $recipients[] = $fields;
            }
            if ($ignoreDnc) {
                $warnings[] = 'The operator setting intellectit_mcp_send_ignore_dnc is on: contacts on the Do Not Contact list are NOT excluded.';
            }
            $preview += [
                'mode'             => 'contacts',
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
                    throw new ToolCallException(sprintf('Segment %d is not one of the segments this email is assigned to (%s).', $sid, [] === $assigned ? 'none' : implode(', ', array_keys($assigned))));
                }
                $this->auth->requireEntityAccess($context, $assigned[$sid], 'lead:lists', 'view');
                $count     = (int) $this->emails->getPendingLeads($email, $sid, true);
                $pending  += $count;
                $perList[] = ['segment_id' => $sid, 'name' => $assigned[$sid]->getName(), 'pending_contacts' => $count];
                $lists[]   = $assigned[$sid];
            }
            $preview += [
                'mode'             => 'segments',
                'recipient_count'  => min($pending, $cap),
                'pending_total'    => $pending,
                'segments'         => $perList,
                'note'             => 'Pending contacts exclude Do Not Contact and people who already got this email. A contact in several segments may be counted twice here but is emailed once.',
            ];
            if ($pending > $cap) {
                $warnings[] = sprintf('%d contacts are pending but one call sends at most %d; repeat the call for the rest.', $pending, $cap);
            }
            if (0 === $pending && [] === $blockers) {
                $blockers[] = 'No pending recipients.';
            }
        }

        return [
            'preview'    => $preview + ['warnings' => $warnings, 'blockers' => $blockers, 'can_send' => [] === $blockers],
            'email'      => $email,
            'recipients' => $recipients,
            'lists'      => $lists,
            'cap'        => $cap,
        ];
    }
}

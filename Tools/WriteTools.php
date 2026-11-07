<?php

declare(strict_types=1);

namespace MauticPlugin\IntellectItMcpBundle\Tools;

use Mautic\CampaignBundle\Entity\Campaign;
use Mautic\CampaignBundle\Model\CampaignModel;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\EmailBundle\Entity\Email;
use Mautic\EmailBundle\Model\EmailModel;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Entity\LeadList;
use Mautic\LeadBundle\Helper\FakeContactHelper;
use Mautic\LeadBundle\Model\LeadModel;
use Mautic\LeadBundle\Model\ListModel;
use Mcp\Capability\Attribute\Schema;
use MauticPlugin\IntellectItMcpBundle\Service\McpError;
use Mcp\Server\RequestContext;
use MauticPlugin\IntellectItMcpBundle\Service\CampaignFlowBuilder;
use MauticPlugin\IntellectItMcpBundle\Service\McpScopes;
use MauticPlugin\IntellectItMcpBundle\Service\McpUserContext;
use MauticPlugin\IntellectItMcpBundle\Service\EmailTopics;

const STEP_SCHEMA = [
                'type'                 => 'object',
                'required'             => ['key'],
                'additionalProperties' => false,
                'properties'           => [
                    'key'        => ['type' => 'string', 'description' => 'Existing steps: their ID as returned by mautic_get_campaign (keeps the step and its contact history). New steps: any non-numeric id, e.g. "reminder". Used by "parent" of later steps.'],
                    'type'       => ['type' => 'string', 'description' => 'Step type from mautic_campaign_step_types, e.g. "email.send", "email.open", "lead.changetags". Required for new steps.'],
                    'name'       => ['type' => 'string', 'description' => 'Name shown in the builder (default: the type label)'],
                    'parent'     => ['type' => 'string', 'description' => 'Key of the step this one follows. Omit for steps that run when a contact enters the campaign. Parents must be listed before their children.'],
                    'path'       => ['type' => 'string', 'enum' => ['yes', 'no'], 'description' => 'Required after a decision or condition: which branch this step is on'],
                    'wait'       => [
                        'type'                 => 'object',
                        'additionalProperties' => false,
                        'description'          => 'When an action/condition runs, relative to its parent. Omit = immediately. Required on the "no" path of a decision (how long to wait for the contact to act).',
                        'properties'           => [
                            'interval' => ['type' => 'integer', 'minimum' => 1],
                            'unit'     => ['type' => 'string', 'enum' => ['i', 'h', 'd', 'm', 'y'], 'description' => 'i = minutes, h = hours, d = days, m = months, y = years'],
                            'at'       => ['type' => 'string', 'description' => 'Fixed date-time (ISO 8601) instead of an interval'],
                        ],
                    ],
                    'properties' => ['type' => ['object', 'array'], 'description' => 'Settings of the step type, see mautic_campaign_step_types. For email.send: {"email": <template email id>, "email_type": "marketing"|"transactional"}'],
                ],
            ];

const STEPS_SCHEMA = [
            'type'     => 'array',
            'minItems' => 1,
            'maxItems' => 100,
            'items'    => STEP_SCHEMA,
        ];

/**
 * Write tools (scope mcp:write). Emails and new campaigns are created
 * unpublished; publishing and sending stay with a person in Mautic.
 * Exception by design: the flow of a RUNNING campaign can be edited
 * (campaign step tools), and Mautic's scheduler then executes the changed
 * steps for the contacts in it. Results carry live_effects for that.
 * Published or already sent emails
 * cannot be changed. Publishing and sending stay a human decision in Mautic.
 */
class WriteTools
{
    public function __construct(
        private readonly McpUserContext $auth,
        private readonly EmailModel $emails,
        private readonly ListModel $segments,
        private readonly CampaignModel $campaigns,
        private readonly CoreParametersHelper $parameters,
        private readonly CampaignFlowBuilder $flow,
        private readonly LeadModel $leads,
        private readonly FakeContactHelper $fakeContact,
        private readonly EmailTopics $topics,
    ) {
    }

    /**
     * Create an unpublished email draft, from HTML or as a copy of an existing email (copyFromId). Write complete, email-safe HTML (tables, inline styles, max width 600px) and include an unsubscribe link in the footer, e.g. <a href="{unsubscribe_url}">Unsubscribe or manage preferences</a> (Mautic's {unsubscribe_text} renders an English text). Personalise with {contactfield=<alias>} (aliases: mautic_list_contact_fields). A copy keeps the original's theme and builder content, sender, language and topic; the segments are NOT copied (a copy never inherits an audience), pass segmentIds for them. A human reviews and publishes it in Mautic.
     *
     * @param string    $name       Internal name
     * @param string    $subject    Subject line
     * @param string    $html       Full HTML document of the email (required unless copyFromId is set; with copyFromId it replaces the copied content and the copy becomes a plain HTML email)
     * @param string    $type       "segment" (newsletter sent to segments) or "template" (used by campaigns and forms); default: the original's type for a copy, otherwise "template"
     * @param list<int> $segmentIds Segment IDs for a segment email
     * @param string    $preheader  Preview text shown after the subject
     * @param string    $language   Language code, e.g. "en" (default: the original's for a copy, otherwise the Mautic default locale)
     * @param string    $topic      Topic of the email (name or ID from mautic_list_email_topics): people who switched the topic off in their email preferences do not get it. Leave empty only for service emails the person asked for (a copy keeps the original's topic when empty)
     * @param int       $copyFromId ID of an email to copy (any status, also sent ones); you need view access to it
     *
     * @return array<string, mixed>
     */
    public function createEmailDraft(
        RequestContext $context,
        #[Schema(minLength: 1, maxLength: 191)] string $name,
        #[Schema(minLength: 1, maxLength: 191)] string $subject,
        string $html = '',
        #[Schema(enum: ['segment', 'template'])] ?string $type = null,
        array $segmentIds = [],
        string $preheader = '',
        string $language = '',
        string $topic = '',
        #[Schema(minimum: 0)] int $copyFromId = 0,
    ): array {
        $this->auth->require($context, 'email:emails:create');
        if ($copyFromId <= 0 && '' === trim($html)) {
            throw McpError::from('Provide html, or copyFromId to copy an existing email.');
        }

        if ($copyFromId > 0) {
            $source = $this->emails->getEntity($copyFromId);
            if (!$source instanceof Email || !$source->getId()) {
                throw McpError::from(sprintf('Email %d not found.', $copyFromId));
            }
            $this->auth->requireEntityAccess($context, $source, 'email:emails', 'view');
            $sourceType = $source->getEmailType() ?: 'template';
            $email      = clone $source;
            foreach ($email->getLists()->toArray() as $segment) {
                $email->removeList($segment);
            }
            $email->setEmailType(null !== $type ? ('segment' === $type ? 'list' : 'template') : $sourceType);
            if ('' !== $language) {
                $email->setLanguage($language);
            }
            if ('' !== $preheader) {
                $email->setPreheaderText($preheader);
            }
        } else {
            $email = new Email();
            $email->setEmailType('segment' === $type ? 'list' : 'template');
            $email->setPreheaderText('' !== $preheader ? $preheader : null);
            $email->setLanguage('' !== $language ? $language : (string) $this->parameters->get('locale', 'en'));
        }
        $email->setName($name);
        $email->setSubject($subject);
        if ('' !== trim($html)) {
            $email->setCustomHtml($html);
            $email->setTemplate('mautic_code_mode');
        }
        $email->setDescription($copyFromId > 0 ? sprintf('Copy of email %d created via MCP. Review before publishing.', $copyFromId) : 'Draft created via MCP. Review before publishing.');
        $email->setIsPublished(false);
        $this->topics->apply($email, $copyFromId > 0 && '' === $topic ? null : $topic);
        foreach ($this->segmentEntities($context, $segmentIds) as $segment) {
            $email->addList($segment);
        }

        $this->emails->saveEntity($email);
        $this->auth->audit('email', (int) $email->getId(), 'create', ['name' => $name] + ($copyFromId > 0 ? ['copyFrom' => $copyFromId] : []));

        return $this->emailResult($email, 'Draft created'.($copyFromId > 0 ? sprintf(' as a copy of email %d (segments not copied)', $copyFromId) : '').'. It is unpublished: review it in Mautic and publish it there.');
    }

    /**
     * Change an email draft. Only unpublished emails that were never sent can be changed. Omitted fields stay as they are.
     *
     * @param int            $id         Email ID
     * @param string|null    $name       New internal name
     * @param string|null    $subject    New subject line
     * @param string|null    $html       New full HTML document
     * @param list<int>|null $segmentIds Replace the segments (segment emails)
     * @param string|null    $preheader  New preview text
     * @param string|null    $topic      New topic (name or ID, "" removes it), see mautic_list_email_topics
     *
     * @return array<string, mixed>
     */
    public function updateEmailDraft(
        RequestContext $context,
        int $id,
        ?string $name = null,
        ?string $subject = null,
        ?string $html = null,
        ?array $segmentIds = null,
        ?string $preheader = null,
        ?string $topic = null,
    ): array {
        $email = $this->emails->getEntity($id);
        if (!$email instanceof Email || !$email->getId()) {
            throw McpError::from(sprintf('Email %d not found.', $id));
        }
        $this->auth->requireEntityAccess($context, $email, 'email:emails', 'edit');
        if ($email->isPublished() || $email->getSentCount(true) > 0) {
            throw McpError::from(sprintf('Only unpublished drafts that were never sent can be changed via MCP. To change a published or sent email, copy it with mautic_create_email_draft (copyFromId=%d) and edit the copy.', $id));
        }

        $changed = [];
        if (null !== $name) {
            $email->setName($name);
            $changed[] = 'name';
        }
        if (null !== $subject) {
            $email->setSubject($subject);
            $changed[] = 'subject';
        }
        if (null !== $html) {
            $email->setCustomHtml($html);
            $email->setTemplate('mautic_code_mode');
            $changed[] = 'html';
        }
        if (null !== $preheader) {
            $email->setPreheaderText($preheader);
            $changed[] = 'preheader';
        }
        if ($this->topics->apply($email, $topic)) {
            $changed[] = 'topic';
        }
        if (null !== $segmentIds) {
            foreach ($email->getLists()->toArray() as $segment) {
                $email->removeList($segment);
            }
            foreach ($this->segmentEntities($context, $segmentIds) as $segment) {
                $email->addList($segment);
            }
            $changed[] = 'segments';
        }

        $this->emails->saveEntity($email);
        $this->auth->audit('email', (int) $email->getId(), 'update', ['fields' => $changed]);

        return $this->emailResult($email, 'Draft updated ('.implode(', ', $changed ?: ['nothing']).').');
    }

    /**
     * Send a test of an email to yourself (the signed-in Mautic user, nobody else), like "Send example" in Mautic. Tokens are filled with a sample contact, or with a real contact (previewContactId) you may view. Works for drafts; nothing is published, no statistics are recorded.
     *
     * @param int      $id               Email ID
     * @param int|null $previewContactId Fill tokens such as {contactfield=firstname} with this contact
     *
     * @return array<string, mixed>
     */
    public function sendTestEmail(RequestContext $context, int $id, ?int $previewContactId = null): array
    {
        $email = $this->emails->getEntity($id);
        if (!$email instanceof Email || !$email->getId()) {
            throw McpError::from(sprintf('Email %d not found.', $id));
        }
        $this->auth->requireEntityAccess($context, $email, 'email:emails', 'view');
        $user    = $this->auth->user($context);
        $address = (string) $user->getEmail();
        if (false === filter_var($address, FILTER_VALIDATE_EMAIL)) {
            throw McpError::from('Your Mautic user has no valid email address.');
        }

        $fields = null;
        if (null !== $previewContactId) {
            $lead = $this->leads->getEntity($previewContactId);
            if (!$lead instanceof Lead || !$lead->getId()) {
                throw McpError::from(sprintf('Contact %d not found.', $previewContactId));
            }
            $this->auth->requireEntityAccess($context, $lead, 'lead:leads', 'view');
            $fields = $this->emails->enrichedContactWithCompanies($this->leads->getRepository()->getLead($previewContactId));
        }
        $fields ??= $this->fakeContact->prepareFakeContactWithPrimaryCompany();

        $errors = $this->emails->sendSampleEmailToUser($email, [['id' => '', 'firstname' => (string) $user->getFirstName(), 'lastname' => (string) $user->getLastName(), 'email' => $address]], $fields, [], [], false);
        if (is_array($errors) && [] !== $errors) {
            throw McpError::from('Mautic could not send the test: '.implode('; ', array_map('strval', $errors)));
        }
        $this->auth->audit('email', (int) $email->getId(), 'send_test', ['to' => 'self']);

        return ['id' => $email->getId(), 'sent_to' => $address, 'message' => sprintf('Test of "%s" sent to %s. Check the inbox (and spam folder).', $email->getName(), $address)];
    }

    /**
     * Create an unpublished campaign draft with its segments as source. Then add the flow with mautic_set_campaign_steps (or build it in Mautic's campaign builder).
     *
     * @param string    $name        Campaign name
     * @param list<int> $segmentIds  Segments whose contacts enter the campaign
     * @param string    $description What the campaign is for
     *
     * @return array<string, mixed>
     */
    public function createCampaignDraft(
        RequestContext $context,
        #[Schema(minLength: 1, maxLength: 191)] string $name,
        array $segmentIds,
        string $description = '',
    ): array {
        $this->auth->require($context, 'campaign:campaigns:create');
        if ([] === $segmentIds) {
            throw McpError::from('A campaign needs at least one segment as source.');
        }

        $campaign = new Campaign();
        $campaign->setName($name);
        $campaign->setDescription('' !== $description ? $description : 'Draft created via MCP. Review before publishing.');
        $campaign->setIsPublished(false);
        foreach ($this->segmentEntities($context, $segmentIds) as $segment) {
            $campaign->addList($segment);
        }
        // Show the segment source on the builder canvas.
        $campaign->setCanvasSettings(['nodes' => [['id' => 'lists', 'positionX' => '700', 'positionY' => '50']], 'connections' => []]);

        $this->campaigns->saveEntity($campaign);
        $this->auth->audit('campaign', (int) $campaign->getId(), 'create', ['name' => $name]);

        return $this->campaignResult($campaign, 'Campaign draft created (unpublished). Add the steps with mautic_set_campaign_steps or in the campaign builder.');
    }

    /**
     * Change name, description or segments of an unpublished campaign. Omitted fields stay as they are.
     *
     * @param int            $id          Campaign ID
     * @param string|null    $name        New name
     * @param string|null    $description New description
     * @param list<int>|null $segmentIds  Replace the source segments
     *
     * @return array<string, mixed>
     */
    public function updateCampaignDraft(RequestContext $context, int $id, ?string $name = null, ?string $description = null, ?array $segmentIds = null): array
    {
        $campaign = $this->campaigns->getEntity($id);
        if (!$campaign instanceof Campaign || !$campaign->getId()) {
            throw McpError::from(sprintf('Campaign %d not found.', $id));
        }
        $this->auth->requireEntityAccess($context, $campaign, 'campaign:campaigns', 'edit');
        if ($campaign->isPublished()) {
            throw McpError::from('Published campaigns cannot be changed via MCP. Unpublish it in Mautic first.');
        }

        $changed = [];
        if (null !== $name) {
            $campaign->setName($name);
            $changed[] = 'name';
        }
        if (null !== $description) {
            $campaign->setDescription($description);
            $changed[] = 'description';
        }
        if (null !== $segmentIds) {
            if ([] === $segmentIds) {
                throw McpError::from('A campaign needs at least one segment as source.');
            }
            foreach ($campaign->getLists()->toArray() as $segment) {
                $campaign->removeList($segment);
            }
            foreach ($this->segmentEntities($context, $segmentIds) as $segment) {
                $campaign->addList($segment);
            }
            $changed[] = 'segments';
        }

        $this->campaigns->saveEntity($campaign);
        $this->auth->audit('campaign', (int) $campaign->getId(), 'update', ['fields' => $changed]);

        return $this->campaignResult($campaign, 'Campaign draft updated ('.implode(', ', $changed ?: ['nothing']).').');
    }

    /**
     * Set the complete flow of a campaign: actions, decisions, conditions, waits and yes/no branches. CAUTION for published (running) campaigns: saved changes are live; Mautic's scheduler executes added/changed steps for the contacts in the campaign and may send emails. Always dryRun first, check live_effects (an attending person must see them and agree). Works as a diff: steps keyed with an existing step ID (from mautic_get_campaign) are updated in place and keep their contact history, new keys add steps, existing steps left out are removed. Validated like Mautic's campaign builder. Published (running) campaigns: steps can be added and changed but not moved; removing steps needs allow_deletions after checking a dry_run. Example: send welcome email; if opened -> add points; if not opened within 3 days -> send reminder.
     *
     * @param int                        $id             Campaign ID
     * @param list<array<string, mixed>> $steps          The complete flow, parents before children
     * @param bool                       $dryRun         Only report what would be added, changed and removed (and contacts affected)
     * @param bool                       $allowDeletions Needed to remove steps from a published campaign
     * @param array<string, string>      $redirects      For removed steps of a running campaign: removed step ID => step ID where its waiting contacts continue
     *
     * @return array<string, mixed>
     */
    public function setCampaignSteps(RequestContext $context, int $id, #[Schema(definition: STEPS_SCHEMA + ['description' => 'The complete flow, parents before children; omitted existing steps are removed'])] array $steps, bool $dryRun = false, bool $allowDeletions = false, #[Schema(definition: ['type' => 'object', 'description' => 'Removed step ID => step ID where its waiting contacts continue (running campaigns)', 'additionalProperties' => ['type' => 'string']])] array $redirects = []): array
    {
        return $this->applyFlow($context, $id, fn (): array => $steps, $dryRun, $allowDeletions, $redirects, 'set_steps');
    }

    /**
     * Change one step of a campaign (also a running one; then the change is live and may send emails via Mautic's scheduler, dryRun first, and an attending person must agree): its name, wait and/or properties. The step keeps its ID and contact history; omitted fields stay as they are. Contacts already scheduled on the step keep their time.
     *
     * @param int                  $campaignId Campaign ID
     * @param string               $key        Step ID from mautic_get_campaign
     * @param string|null          $name       New name
     * @param array<string, mixed> $wait       New wait {interval, unit} or {at}; {} = immediately; omit to keep
     * @param array<string, mixed> $properties Properties to change (others stay)
     * @param bool                 $dryRun     Only report the change
     *
     * @return array<string, mixed>
     */
    public function updateCampaignStep(RequestContext $context, int $campaignId, string $key, ?string $name = null, #[Schema(definition: ['type' => 'object', 'description' => 'New wait {interval, unit} or {at}; {} = immediately; omit to keep the current one', 'properties' => ['interval' => ['type' => 'integer', 'minimum' => 1], 'unit' => ['type' => 'string', 'enum' => ['i', 'h', 'd', 'm', 'y']], 'at' => ['type' => 'string']]])] ?array $wait = null, ?array $properties = null, bool $dryRun = false): array
    {
        return $this->applyFlow($context, $campaignId, function (array $current) use ($key, $name, $wait, $properties): array {
            $found = false;
            foreach ($current as &$step) {
                if ($step['key'] !== $key) {
                    continue;
                }
                $found = true;
                if (null !== $name) {
                    $step['name'] = $name;
                }
                if (null !== $wait) {
                    $step['wait'] = $wait;
                } else {
                    unset($step['wait']); // keep the stored wait
                }
                $step['properties'] = array_replace($step['properties'] ?? [], $properties ?? []);
            }
            unset($step);
            if (!$found) {
                throw McpError::from(sprintf('Step "%s" not found in this campaign. Use the step IDs from mautic_get_campaign.', $key));
            }

            return $current;
        }, $dryRun, false, [], 'update_step');
    }

    /**
     * Add one step to a campaign (also a running one; then it is live and Mautic's scheduler executes it for contacts reaching it, e.g. sends the email; dryRun first, and an attending person must agree), e.g. a reminder on the "no" path of a decision or a follow-up after an email.
     *
     * @param int                  $campaignId Campaign ID
     * @param array<string, mixed> $step       The new step (non-numeric key, type, parent, path, wait, properties)
     * @param bool                 $dryRun     Only report the change
     *
     * @return array<string, mixed>
     */
    public function addCampaignStep(RequestContext $context, int $campaignId, #[Schema(definition: STEP_SCHEMA + ['description' => 'The new step (non-numeric key, type, parent, path, wait, properties)'])] array $step, bool $dryRun = false): array
    {
        if (ctype_digit((string) ($step['key'] ?? ''))) {
            throw McpError::from('A new step needs a non-numeric key, e.g. "reminder".');
        }

        return $this->applyFlow($context, $campaignId, fn (array $current): array => [...$current, $step], $dryRun, false, [], 'add_step');
    }

    /**
     * Remove one step (that has no following steps) from a campaign. On a running campaign, call it first with dryRun to see how many contacts are scheduled on the step, then with confirm=true; redirectTo moves those contacts to another step.
     *
     * @param int         $campaignId Campaign ID
     * @param string      $key        Step ID from mautic_get_campaign
     * @param string|null $redirectTo Step ID where contacts waiting on the removed step continue
     * @param bool        $dryRun     Only report the change
     * @param bool        $confirm    Required to remove a step from a published campaign
     *
     * @return array<string, mixed>
     */
    public function removeCampaignStep(RequestContext $context, int $campaignId, string $key, ?string $redirectTo = null, bool $dryRun = false, bool $confirm = false): array
    {
        return $this->applyFlow($context, $campaignId, function (array $current) use ($key): array {
            $keys = array_column($current, 'key');
            if (!in_array($key, $keys, true)) {
                throw McpError::from(sprintf('Step "%s" not found in this campaign.', $key));
            }
            $children = array_column(array_filter($current, fn (array $s): bool => ($s['parent'] ?? null) === $key), 'key');
            if ([] !== $children) {
                throw McpError::from(sprintf('Step "%s" has following steps (%s). Remove those first, or restructure with mautic_set_campaign_steps.', $key, implode(', ', $children)));
            }

            return array_values(array_filter($current, fn (array $s): bool => $s['key'] !== $key));
        }, $dryRun, $confirm, null !== $redirectTo ? [$key => $redirectTo] : [], 'remove_step');
    }

    /**
     * @param \Closure(list<array<string, mixed>>): list<array<string, mixed>> $build current steps => new steps
     * @param array<string, string>                                          $redirects
     *
     * @return array<string, mixed>
     */
    private function applyFlow(RequestContext $context, int $id, \Closure $build, bool $dryRun, bool $allowDeletions, array $redirects, string $action): array
    {
        $campaign = $this->campaigns->getEntity($id);
        if (!$campaign instanceof Campaign || !$campaign->getId()) {
            throw McpError::from(sprintf('Campaign %d not found.', $id));
        }
        $this->auth->requireEntityAccess($context, $campaign, 'campaign:campaigns', 'edit');

        $current = array_map(function (array $s): array {
            unset($s['kind'], $s['contacts']);

            return $s;
        }, $this->flow->currentSteps($campaign));
        $steps = array_values($build($current));

        // Emails used by the flow: the user must be allowed to see them, and
        // only template emails can be sent by campaigns.
        foreach ($steps as $step) {
            $emailId = is_array($step) ? ($step['properties']['email'] ?? $step['properties']['useremail']['email'] ?? null) : null;
            if (null === $emailId || !in_array($step['type'] ?? 'email.send', ['email.send', 'email.send.to.user'], true)) {
                continue;
            }
            $email = $this->emails->getEntity((int) $emailId);
            if (!$email instanceof Email || !$email->getId()) {
                throw McpError::from(sprintf('Step "%s": email %d not found.', $step['key'] ?? '?', (int) $emailId));
            }
            $this->auth->requireEntityAccess($context, $email, 'email:emails', 'view');
            if ('list' === $email->getEmailType()) {
                throw McpError::from(sprintf('Step "%s": email %d is a segment email; campaigns can only send template emails (mautic_create_email_draft type "template").', $step['key'] ?? '?', $email->getId()));
            }
        }

        // Lock for running campaigns: allowed by the operator (Helm
        // intellectit_mcp_live_campaign_edits) AND granted by the user at consent
        // (scope mcp:campaigns:live). Dry runs are always fine.
        $liveBlock = null;
        if ($campaign->isPublished()) {
            if (!$this->parameters->get('intellectit_mcp_live_campaign_edits', false)) {
                $liveBlock = 'Changes to running campaigns are disabled on this Mautic instance (intellectit_mcp_live_campaign_edits). Unpublish the campaign in Mautic to edit it, or ask an administrator.';
            } elseif (!$this->auth->hasScope($context, McpScopes::CAMPAIGNS_LIVE)) {
                $liveBlock = 'This campaign is running. Changing it needs the permission "mcp:campaigns:live" (changes are live and may send emails). Reconnect the client and tick "Change RUNNING campaigns" on the Mautic consent page, or unpublish the campaign in Mautic first.';
            }
            if (null !== $liveBlock && !$dryRun) {
                throw McpError::from($liveBlock);
            }
        }

        $result = $this->flow->applySteps($campaign, $steps, $dryRun, $allowDeletions, $redirects);
        if ($campaign->isPublished()) {
            $result['live_effects']['save_allowed'] = null === $liveBlock;
            if (null !== $liveBlock) {
                $result['live_effects']['save_blocked_because'] = $liveBlock;
            }
        }
        if (!$dryRun) {
            $this->auth->audit('campaign', (int) $campaign->getId(), $action, [
                'published' => $campaign->isPublished(),
                'added'     => array_column($result['changes']['added'], 'key'),
                'changed'   => array_column($result['changes']['changed'], 'key'),
                'removed'   => array_column($result['changes']['removed'], 'key'),
            ]);
        }

        $c       = $result['changes'];
        $summary = sprintf('%d added, %d changed, %d removed, %d unchanged', count($c['added']), count($c['changed']), count($c['removed']), count($c['unchanged']));

        return $this->campaignResult($campaign, $dryRun
            ? 'Dry run, nothing saved: '.$summary.'.'
            : 'Campaign flow saved: '.$summary.($campaign->isPublished() ? '. The campaign is running; the changes apply from now on.' : ' (campaign stays unpublished).')) + $result;
    }

    /**
     * @param array<mixed> $ids
     *
     * @return list<LeadList>
     */
    private function segmentEntities(RequestContext $context, array $ids): array
    {
        $segments = [];
        foreach (array_unique(array_map('intval', $ids)) as $id) {
            $segment = $this->segments->getEntity($id);
            if (!$segment instanceof LeadList || !$segment->getId()) {
                throw McpError::from(sprintf('Segment %d not found.', $id));
            }
            $this->auth->requireEntityAccess($context, $segment, 'lead:lists', 'view');
            $segments[] = $segment;
        }

        return $segments;
    }

    /** @return array<string, mixed> */
    private function emailResult(Email $email, string $message): array
    {
        return [
            'id'        => $email->getId(),
            'name'      => $email->getName(),
            'subject'   => $email->getSubject(),
            'published' => $email->isPublished(),
            'message'   => $message,
            'url'       => rtrim((string) $this->parameters->get('site_url'), '/').'/s/emails/view/'.$email->getId(),
        ] + $this->topics->describe($email);
    }

    /** @return array<string, mixed> */
    private function campaignResult(Campaign $campaign, string $message): array
    {
        return [
            'id'        => $campaign->getId(),
            'name'      => $campaign->getName(),
            'published' => $campaign->isPublished(),
            'message'   => $message,
            'url'       => rtrim((string) $this->parameters->get('site_url'), '/').'/s/campaigns/view/'.$campaign->getId(),
        ];
    }
}

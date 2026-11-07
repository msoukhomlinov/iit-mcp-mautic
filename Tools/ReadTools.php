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
use Mautic\LeadBundle\Model\LeadModel;
use Mautic\LeadBundle\Model\ListModel;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Server\RequestContext;
use MauticPlugin\IntellectItMcpBundle\Service\CampaignFlowBuilder;
use MauticPlugin\IntellectItMcpBundle\Service\McpUserContext;
use MauticPlugin\IntellectItMcpBundle\Service\EmailTopics;

/**
 * Read-only tools (scope mcp:read). Every call is checked against the
 * Mautic permissions of the user the access token was issued for.
 */
class ReadTools
{
    public function __construct(
        private readonly McpUserContext $auth,
        private readonly LeadModel $leads,
        private readonly ListModel $segments,
        private readonly EmailModel $emails,
        private readonly CampaignModel $campaigns,
        private readonly CoreParametersHelper $parameters,
        private readonly CampaignFlowBuilder $flow,
        private readonly EmailTopics $topics,
    ) {
    }

    /**
     * Search contacts by name, email, company or Mautic search syntax (e.g. "tag:customer", "segment:newsletter"). With includeFields every contact carries all filled profile fields (for exports to other systems).
     *
     * @param string $search        Search term or Mautic search expression
     * @param int    $limit         Maximum number of contacts
     * @param int    $offset        Number of contacts to skip (pagination)
     * @param bool   $includeFields Include all filled profile fields
     *
     * @return array<string, mixed>
     */
    public function searchContacts(RequestContext $context, string $search = '', #[Schema(minimum: 1, maximum: 100)] int $limit = 20, #[Schema(minimum: 0)] int $offset = 0, bool $includeFields = false): array
    {
        $this->auth->require($context, ['lead:leads:viewown', 'lead:leads:viewother']);
        [$items, $total] = $this->list($this->leads, 'lead:leads', 'l', $search, $limit, $offset);

        return ['total' => $total, 'contacts' => array_map(fn (Lead $l): array => $this->contactSummary($l) + ($includeFields ? ['fields' => $this->profileFields($l)] : []), $items)];
    }

    /**
     * Get one contact with profile fields, tags, segments, points, stage and Do-Not-Contact status.
     *
     * @param int $id Contact ID
     *
     * @return array<string, mixed>
     */
    public function getContact(RequestContext $context, int $id): array
    {
        $lead = $this->leads->getEntity($id);
        if (!$lead instanceof Lead || !$lead->getId()) {
            throw new ToolCallException(sprintf('Contact %d not found.', $id));
        }
        $this->auth->requireEntityAccess($context, $lead, 'lead:leads', 'view');

        return $this->contactSummary($lead) + [
            'fields'          => $this->profileFields($lead),
            'point_groups'    => array_values(array_map(fn ($g): array => ['id' => $g->getGroup()->getId(), 'name' => $g->getGroup()->getName(), 'score' => (int) $g->getScore()], $lead->getGroupScores()->toArray())),
            'segments'        => array_values(array_map(fn (LeadList $s): array => ['id' => $s->getId(), 'name' => $s->getName()], $this->leads->getLists($lead))),
            'do_not_contact'  => array_values(array_map(fn ($d): array => ['channel' => $d->getChannel(), 'reason' => [1 => 'unsubscribed', 2 => 'bounced', 3 => 'manual'][$d->getReason()] ?? $d->getReason(), 'date' => $d->getDateAdded()?->format(DATE_ATOM)], $lead->getDoNotContact()->toArray())),
            'date_identified' => $lead->getDateIdentified()?->format(DATE_ATOM),
        ];
    }

    /**
     * Activity timeline of a contact, newest first: email sends, opens and clicks, page visits, form submissions, points and stage changes, segment and campaign membership, notes and more (everything Mautic shows on the contact's history). Use it to judge engagement, e.g. before handing a lead to sales.
     *
     * @param int          $id     Contact ID
     * @param list<string> $types  Only these event types (see "types" in the result), e.g. ["email.read", "page.hit", "form.submitted"]
     * @param string       $search Search in the event labels
     * @param int          $page   Page (25 events each by default)
     *
     * @return array<string, mixed>
     */
    public function getContactActivity(RequestContext $context, int $id, array $types = [], string $search = '', #[Schema(minimum: 1)] int $page = 1, #[Schema(minimum: 1, maximum: 100)] int $limit = 25): array
    {
        $lead = $this->leads->getEntity($id);
        if (!$lead instanceof Lead || !$lead->getId()) {
            throw new ToolCallException(sprintf('Contact %d not found.', $id));
        }
        $this->auth->requireEntityAccess($context, $lead, 'lead:leads', 'view');
        $data = $this->leads->getEngagements($lead, ['search' => $search, 'includeEvents' => array_values(array_map('strval', $types)), 'excludeEvents' => []], ['timestamp', 'DESC'], $page, $limit);

        $events = [];
        foreach ($data['events'] as $e) {
            $label = $e['eventLabel'] ?? '';
            $time  = $e['timestamp'] ?? null;
            $events[] = array_filter([
                'type'      => $e['event'] ?? null,
                'type_name' => $e['eventType'] ?? null,
                'label'     => is_array($label) ? ($label['label'] ?? null) : (string) $label,
                'link'      => is_array($label) ? ($label['href'] ?? null) : null,
                'at'        => $time instanceof \DateTimeInterface ? $time->format(DATE_ATOM) : (is_string($time) ? $time : null),
            ], fn ($v): bool => null !== $v && '' !== $v);
        }

        return [
            'contact' => $this->contactSummary($lead),
            'total'   => (int) $data['total'],
            'page'    => $page,
            'pages'   => (int) $data['maxPages'],
            'types'   => $data['types'],
            'events'  => $events,
        ];
    }

    /**
     * List contact segments with their size.
     *
     * @param string $search Filter by name
     *
     * @return array<string, mixed>
     */
    public function listSegments(RequestContext $context, string $search = '', #[Schema(minimum: 1, maximum: 100)] int $limit = 50, #[Schema(minimum: 0)] int $offset = 0): array
    {
        $this->auth->require($context, ['lead:lists:viewown', 'lead:lists:viewother']);
        [$items, $total] = $this->list($this->segments, 'lead:lists', 'l', $search, $limit, $offset);
        $ids    = array_map(fn (LeadList $s): int => (int) $s->getId(), $items);
        $counts = [] !== $ids ? $this->segments->getRepository()->getLeadCount($ids) : [];
        $counts = is_array($counts) ? $counts : [$ids[0] => $counts];

        return ['total' => $total, 'segments' => array_map(fn (LeadList $s): array => [
            'id'          => $s->getId(),
            'name'        => $s->getName(),
            'alias'       => $s->getAlias(),
            'description' => $s->getDescription(),
            'published'   => $s->isPublished(),
            'contacts'    => (int) ($counts[$s->getId()] ?? 0),
        ], $items)];
    }

    /**
     * List emails (newest first) with type, status and basic statistics.
     *
     * @param string $search Filter by name or subject
     *
     * @return array<string, mixed>
     */
    public function listEmails(RequestContext $context, string $search = '', #[Schema(minimum: 1, maximum: 100)] int $limit = 20, #[Schema(minimum: 0)] int $offset = 0): array
    {
        $this->auth->require($context, ['email:emails:viewown', 'email:emails:viewother']);
        [$items, $total] = $this->list($this->emails, 'email:emails', 'e', $search, $limit, $offset, 'e.dateAdded', 'DESC');

        return ['total' => $total, 'emails' => array_map(fn (Email $e): array => $this->emailSummary($e), $items)];
    }

    /**
     * Get one email including subject, preheader, sender, segments and HTML content.
     *
     * @param int  $id          Email ID
     * @param bool $includeHtml Include the full HTML (can be large)
     *
     * @return array<string, mixed>
     */
    public function getEmail(RequestContext $context, int $id, bool $includeHtml = true): array
    {
        $email = $this->email($context, $id);

        return $this->emailSummary($email) + [
            'preheader'  => $email->getPreheaderText(),
            'from_name'  => $email->getFromName(),
            'from_email' => $email->getFromAddress(),
            'reply_to'   => $email->getReplyToAddress(),
            'theme'      => $email->getTemplate(),
            'topic'      => $email->getCategory()?->getTitle(),
            'segments'   => array_values(array_map(fn (LeadList $s): array => ['id' => $s->getId(), 'name' => $s->getName()], $email->getLists()->toArray())),
            'plain_text' => $email->getPlainText(),
            'html'       => $includeHtml ? $email->getCustomHtml() : null,
        ];
    }

    /**
     * List the email topics (Mautic categories). Recipients switch topics on and off in their email preferences; Mautic does not send an email of a topic to people who switched it off. Give every marketing email its topic (topic parameter of the email tools); service emails the person asked for (download link, webinar access) have none and go out as transactional campaign steps.
     *
     * @return array<string, mixed>
     */
    public function listEmailTopics(RequestContext $context): array
    {
        $this->auth->require($context, ['email:emails:viewown', 'email:emails:viewother']);

        return ['topics' => $this->topics->all()];
    }

    /**
     * Delivery statistics of an email: sent, read, read rate, clicks per link, pending recipients.
     *
     * @param int $id Email ID
     *
     * @return array<string, mixed>
     */
    public function getEmailStats(RequestContext $context, int $id): array
    {
        $email  = $this->email($context, $id);
        $sent   = (int) $email->getSentCount(true);
        $read   = (int) $email->getReadCount(true);
        $clicks = array_map(fn (array $r): array => ['url' => $r['url'] ?? null, 'clicks' => (int) ($r['hits'] ?? 0), 'unique_clicks' => (int) ($r['unique_hits'] ?? 0)], $this->emails->getEmailClickStats($email->getId()));

        return [
            'id'        => $email->getId(),
            'name'      => $email->getName(),
            'sent'      => $sent,
            'read'      => $read,
            'read_rate' => $sent > 0 ? round($read / $sent * 100, 1) : null,
            'pending'   => 'list' === $email->getEmailType() ? (int) $this->emails->getPendingLeads($email, null, true) : null,
            'links'     => $clicks,
        ];
    }

    /**
     * List campaigns with status and segments.
     *
     * @param string $search Filter by name
     *
     * @return array<string, mixed>
     */
    public function listCampaigns(RequestContext $context, string $search = '', #[Schema(minimum: 1, maximum: 100)] int $limit = 20, #[Schema(minimum: 0)] int $offset = 0): array
    {
        $this->auth->require($context, ['campaign:campaigns:viewown', 'campaign:campaigns:viewother']);
        [$items, $total] = $this->list($this->campaigns, 'campaign:campaigns', 'c', $search, $limit, $offset, 'c.dateAdded', 'DESC');

        return ['total' => $total, 'campaigns' => array_map(fn (Campaign $c): array => $this->campaignSummary($c), $items)];
    }

    /**
     * Get one campaign with its segments and its flow (steps: actions, decisions, conditions, waits, yes/no paths, contacts per step), in the format mautic_set_campaign_steps accepts.
     *
     * @param int $id Campaign ID
     *
     * @return array<string, mixed>
     */
    public function getCampaign(RequestContext $context, int $id): array
    {
        $campaign = $this->campaigns->getEntity($id);
        if (!$campaign instanceof Campaign || !$campaign->getId()) {
            throw new ToolCallException(sprintf('Campaign %d not found.', $id));
        }
        $this->auth->requireEntityAccess($context, $campaign, 'campaign:campaigns', 'view');

        return $this->campaignSummary($campaign) + [
            'steps' => $this->flow->currentSteps($campaign),
            'hint'  => 'steps use the format of mautic_set_campaign_steps; keys are step IDs. Edit with mautic_update_campaign_step / mautic_add_campaign_step / mautic_remove_campaign_step, or send the whole list back to mautic_set_campaign_steps.',
        ];
    }

    /**
     * List the step types available for campaigns: actions (send email, change points, tags, segments, ...), decisions (opened email, clicked link, visited page, ...) and conditions (field value, segment membership, ...). Includes types from installed plugins.
     *
     * @return array<string, mixed>
     */
    public function listCampaignStepTypes(RequestContext $context): array
    {
        $this->auth->require($context, ['campaign:campaigns:viewown', 'campaign:campaigns:viewother', 'campaign:campaigns:create']);

        return $this->flow->stepTypes();
    }

    /**
     * Describe one campaign step type: its properties (names, kinds, required, choices), which steps it may follow, and whether it has yes/no paths. Call this before using a type in mautic_set_campaign_steps.
     *
     * @param string $type Step type, e.g. "email.send", "email.open", "lead.changetags"
     *
     * @return array<string, mixed>
     */
    public function describeCampaignStepType(RequestContext $context, string $type): array
    {
        $this->auth->require($context, ['campaign:campaigns:viewown', 'campaign:campaigns:viewother', 'campaign:campaigns:create']);

        return $this->flow->describe($type);
    }

    // -------------------------------------------------------------------------

    private function email(RequestContext $context, int $id): Email
    {
        $email = $this->emails->getEntity($id);
        if (!$email instanceof Email || !$email->getId()) {
            throw new ToolCallException(sprintf('Email %d not found.', $id));
        }
        $this->auth->requireEntityAccess($context, $email, 'email:emails', 'view');

        return $email;
    }

    /**
     * Same filtering as the Mautic API: users without "view others" only see their own items.
     *
     * @return array{0: list<object>, 1: int}
     */
    private function list(object $model, string $permissionBase, string $alias, string $search, int $limit, int $offset, string $orderBy = '', string $orderDir = 'ASC'): array
    {
        $force = [];
        if (!$this->auth->canViewOther($permissionBase)) {
            $force[] = ['column' => $alias.'.createdBy', 'expr' => 'eq', 'value' => $this->auth->currentUserId()];
        }
        $max    = (int) $this->parameters->get('intellectit_mcp_page_limit', 50);
        $result = $model->getEntities([
            'start'          => max(0, $offset),
            'limit'          => min(max(1, $limit), $max),
            'filter'         => ['string' => $search, 'force' => $force],
            'orderBy'        => $orderBy,
            'orderByDir'     => $orderDir,
            'withTotalCount' => true,
            'ignore_paginator' => false,
        ]);

        if (is_array($result) && array_key_exists('results', $result)) {
            $items = is_array($result['results']) ? array_values($result['results']) : iterator_to_array($result['results'], false);

            return [$items, (int) ($result['count'] ?? count($items))];
        }
        $items = is_iterable($result) ? iterator_to_array($result, false) : [];

        return [$items, $result instanceof \Countable ? count($result) : count($items)];
    }

    /** @return array<string, mixed> */
    private function contactSummary(Lead $lead): array
    {
        return [
            'id'          => $lead->getId(),
            'name'        => trim(($lead->getFirstname() ?? '').' '.($lead->getLastname() ?? '')) ?: null,
            'email'       => $lead->getEmail(),
            'company'     => $lead->getCompany(),
            'points'      => $lead->getPoints(),
            'stage'       => $lead->getStage()?->getName(),
            'tags'        => array_values(array_map(fn ($t): string => $t->getTag(), $lead->getTags()->toArray())),
            'last_active' => $lead->getLastActive()?->format(DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    private function profileFields(Lead $lead): array
    {
        $fields = [];
        foreach ($lead->getProfileFields() as $alias => $value) {
            if (null !== $value && '' !== $value && !in_array($alias, ['id'], true)) {
                $fields[$alias] = $value;
            }
        }

        return $fields;
    }

    /** @return array<string, mixed> */
    private function emailSummary(Email $email): array
    {
        return [
            'id'         => $email->getId(),
            'name'       => $email->getName(),
            'subject'    => $email->getSubject(),
            'type'       => 'list' === $email->getEmailType() ? 'segment' : 'template',
            'published'  => $email->isPublished(),
            'language'   => $email->getLanguage(),
            'sent'       => (int) $email->getSentCount(),
            'read'       => (int) $email->getReadCount(),
            'created_at' => $email->getDateAdded()?->format(DATE_ATOM),
            'url'        => $this->adminUrl('/s/emails/view/'.$email->getId()),
        ];
    }

    /** @return array<string, mixed> */
    private function campaignSummary(Campaign $campaign): array
    {
        return [
            'id'          => $campaign->getId(),
            'name'        => $campaign->getName(),
            'description' => $campaign->getDescription(),
            'published'   => $campaign->isPublished(),
            'segments'    => array_values(array_map(fn (LeadList $s): array => ['id' => $s->getId(), 'name' => $s->getName()], $campaign->getLists()->toArray())),
            'created_at'  => $campaign->getDateAdded()?->format(DATE_ATOM),
            'url'         => $this->adminUrl('/s/campaigns/view/'.$campaign->getId()),
        ];
    }

    private function adminUrl(string $path): string
    {
        return rtrim((string) $this->parameters->get('site_url'), '/').$path;
    }
}

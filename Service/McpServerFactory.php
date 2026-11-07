<?php

declare(strict_types=1);

namespace MauticPlugin\IntellectItMcpBundle\Service;

use Doctrine\DBAL\Connection;
use Firebase\JWT\Key;
use GuzzleHttp\Psr7\HttpFactory;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server;
use Mcp\Server\Session\Psr16SessionStore;
use Mcp\Server\Transport\Http\Middleware\AuthorizationMiddleware;
use Mcp\Server\Transport\Http\Middleware\CorsMiddleware;
use Mcp\Server\Transport\Http\Middleware\DnsRebindingProtectionMiddleware;
use Mcp\Server\Transport\Http\Middleware\ProtectedResourceMetadataMiddleware;
use Mcp\Server\Transport\Http\OAuth\JwtTokenValidator;
use Mcp\Server\Transport\Http\OAuth\ProtectedResourceMetadata;
use Mcp\Server\Transport\Http\OAuth\ScopePolicy;
use Mcp\Server\Transport\StreamableHttpTransport;
use MauticPlugin\IntellectItMcpBundle\Tools\CompanyTools;
use MauticPlugin\IntellectItMcpBundle\Tools\ContactTools;
use MauticPlugin\IntellectItMcpBundle\Tools\InsightTools;
use MauticPlugin\IntellectItMcpBundle\Tools\LayoutTools;
use MauticPlugin\IntellectItMcpBundle\Tools\PointTools;
use MauticPlugin\IntellectItMcpBundle\Tools\ProjectTools;
use MauticPlugin\IntellectItMcpBundle\Tools\SegmentTools;
use MauticPlugin\IntellectItMcpBundle\Tools\SendTools;
use MauticPlugin\IntellectItMcpBundle\Tools\StageTools;
use MauticPlugin\IntellectItMcpBundle\Tools\ReadTools;
use MauticPlugin\IntellectItMcpBundle\Tools\WriteTools;
use MauticPlugin\KopexaOAuthBundle\Service\Settings as OAuthSettings;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Adapter\DoctrineDbalAdapter;
use Symfony\Component\Cache\Psr16Cache;

/**
 * Wires the official MCP PHP SDK to Mautic: tools, MCP sessions stored in
 * the Mautic database (works with several web replicas), and OAuth 2.1
 * bearer token validation against this instance's own authorization server
 * (kopexa/mautic-oauth-bundle), offline with its public key.
 */
class McpServerFactory
{
    public const VERSION = '0.8.0'; // x-release-please-version

    /** Tools that need the mcp:write scope. */
    private const WRITE_TOOLS = ['create_email_draft', 'update_email_draft', 'send_test_email', 'create_email_from_layout', 'update_email_layout', 'create_point_group', 'update_point_group', 'create_point_action', 'update_point_action', 'create_point_trigger', 'update_point_trigger', 'create_stage', 'update_stage', 'create_project', 'update_project', 'add_to_project', 'remove_from_project', 'create_campaign_draft', 'update_campaign_draft', 'set_campaign_steps', 'update_campaign_step', 'add_campaign_step', 'remove_campaign_step'];

    /** Tools that need the mcp:contacts:write scope. */
    private const CONTACT_TOOLS = ['upsert_contacts', 'create_segment', 'update_segment', 'add_contacts_to_segment', 'remove_contacts_from_segment', 'create_contact_field', 'upsert_companies', 'add_contacts_to_company', 'remove_contacts_from_company', 'adjust_contact_points', 'set_contact_stage'];

    /** Tools that need the opt-in scope mcp:send (and intellectit_mcp_allow_send). */
    private const SEND_TOOLS = ['preview_email_send', 'send_email'];

    public function __construct(
        private readonly OAuthSettings $oauth,
        private readonly ReadTools $read,
        private readonly WriteTools $write,
        private readonly ContactTools $contacts,
        private readonly CompanyTools $companies,
        private readonly SegmentTools $segmentTools,
        private readonly LayoutTools $layoutTools,
        private readonly PointTools $pointTools,
        private readonly StageTools $stageTools,
        private readonly ProjectTools $projectTools,
        private readonly InsightTools $insightTools,
        private readonly SendTools $sendTools,
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
        private readonly CoreParametersHelper $parameters,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->oauth->isConfigured();
    }

    public function resourceMetadata(): ProtectedResourceMetadata
    {
        return new ProtectedResourceMetadata(
            resource: $this->oauth->resource(),
            authorizationServers: [$this->oauth->issuer()],
            scopesSupported: [McpScopes::READ, McpScopes::WRITE, McpScopes::CONTACTS_WRITE, McpScopes::CAMPAIGNS_LIVE, McpScopes::SEND],
            resourceName: 'Mautic',
            resourceDocumentation: 'https://github.com/kopexa-grc/mautic-stack/blob/main/docs/mcp.md',
        );
    }

    public function transport(ServerRequestInterface $request): StreamableHttpTransport
    {
        $factory  = new HttpFactory();
        $metadata = $this->resourceMetadata();
        $host     = (string) parse_url($this->oauth->issuer(), PHP_URL_HOST);

        $validator = new JwtTokenValidator(
            issuer: $this->oauth->issuer(),
            audience: $this->oauth->resource(),
            keys: [$this->oauth->keyId() => new Key($this->oauth->publicKeyPem(), 'RS256')],
            algorithms: ['RS256'],
            tokenType: 'at+jwt',
            leeway: 30,
        );
        $scopes = new ScopePolicy(
            default: [McpScopes::READ],
            tools: array_fill_keys(self::WRITE_TOOLS, [McpScopes::WRITE]) + array_fill_keys(self::CONTACT_TOOLS, [McpScopes::CONTACTS_WRITE]) + array_fill_keys(self::SEND_TOOLS, [McpScopes::SEND]),
        );

        return new StreamableHttpTransport(
            $request,
            $factory,
            $factory,
            $this->logger,
            [
                new CorsMiddleware(),
                // Bearer tokens are never sent by browsers on their own, so
                // any Origin may call; the Host must be this instance.
                new DnsRebindingProtectionMiddleware([$host], ['*'], $factory, $factory),
                new ProtectedResourceMetadataMiddleware($metadata),
                new AuthorizationMiddleware($validator, $metadata, $scopes, $factory, $factory),
            ],
        );
    }

    public function server(): Server
    {
        $prefix = defined('MAUTIC_TABLE_PREFIX') ? (string) MAUTIC_TABLE_PREFIX : '';
        $cache  = new Psr16Cache(new DoctrineDbalAdapter($this->connection, 'mcp', 0, ['db_table' => $prefix.'intellectit_mcp_sessions']));

        $ro = new ToolAnnotations(readOnlyHint: true, openWorldHint: false);
        $rw = new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false);

        $builder = Server::builder()
            ->setServerInfo('Mautic', self::VERSION, 'Mautic marketing automation (IntellectIT build)', websiteUrl: $this->oauth->issuer(), title: 'Mautic')
            // One page for all tools: not every client follows nextCursor.
            ->setPaginationLimit(200)
            ->setInstructions(implode("\n", [
                'Mautic marketing automation. Read contacts, segments, emails, campaigns and email statistics.',
                'Emails: prefer create_email_from_layout (brand theme from panels and elements, see list_email_layouts; editable in Mautic\'s builder) over create_email_draft (raw HTML). Then send_test_email to the user and share the "url" for review.',
                'Contacts, companies and segments (scope mcp:contacts:write): upsert_contacts / upsert_companies create or update records matched by their unique fields (up to 100 per call; field aliases from list_contact_fields; create_contact_field adds missing fields, e.g. for CRM IDs). Segments are static (create_segment without filters, add/remove_contacts_to/from_segment) or dynamic (filters from list_segment_filter_fields; Mautic keeps them up to date). Contacts that join a segment can enter running campaigns and receive published segment emails of it: when segments are involved, call with dryRun first, show the user live_effects and matching_contacts and save only after they confirm. Never change records the user did not ask for; Do-Not-Contact is never changed.',
                'Lead scoring: point groups (separate scores per topic), point actions (what earns points; list/describe_point_action_types) and point triggers (what happens at a score; list/describe_point_trigger_event_types). New actions and triggers are created unpublished; a person publishes them. Changing a PUBLISHED trigger is live and needs mcp:campaigns:live; adjust_contact_points can fire published triggers at once. Call both with dryRun first and show the user live_effects / triggers before saving.',
                'Email topics: every marketing email gets its topic (list_email_topics, parameter topic); people who switched a topic off in their email preferences do not get it. Service emails the person asked for (download link, webinar access) have no topic and are sent as transactional campaign steps. Footer links: use {unsubscribe_url} with your own text, not {unsubscribe_text} (English).',
                'Stages are the lifecycle of a contact (list_stages, ordered by weight). New stages are unpublished; set_contact_stage only moves contacts into published stages and can make dynamic segments (and their campaigns) pick the contact up: dryRun first, show live_effects.',
                'Projects group items (emails, campaigns, segments, forms, point actions, stages, ...) by topic; assigning items to a project changes nothing else about them.',
                'For exports to other systems, search_contacts / search_companies with includeFields return all fields; paginate with limit/offset. get_contact_activity shows a contact\'s history (opens, clicks, visits, forms, points).',
                'Emails and new campaigns are created unpublished; publishing them stays with a person in Mautic. Sending (only if the operator enabled it and the token has mcp:send): call preview_email_send, show the user the recipients, exclusions and warnings, and call send_email with confirm=true only after they agree. Unpublished emails are never sent; contacts on Do Not Contact are skipped.',
                'Exception: flows of RUNNING (published) campaigns can be edited (set_campaign_steps, update/add/remove_campaign_step) if the instance allows it and the user granted the scope mcp:campaigns:live. Such changes are live: Mautic\'s scheduler executes them for the contacts in the campaign and can send emails. Always call with dryRun first, show the user live_effects (outbound steps, contacts in the campaign, save_allowed) and save only after they confirm.',
                'Every call runs with the Mautic permissions of the signed-in user.',
                'Return the "url" of created drafts to the user so they can review them.',
            ]))
            ->setLogger($this->logger)
            ->setSession(new Psr16SessionStore($cache, 'session-', 3600));

        $allowWrite = (bool) $this->parameters->get('intellectit_mcp_allow_write', false);
        $allowSend  = (bool) $this->parameters->get('intellectit_mcp_allow_send', false);
        $gated      = array_merge(self::WRITE_TOOLS, self::CONTACT_TOOLS);

        // Operator ceiling: with writes off, write tools are not even listed (the token scopes still apply on top).
        foreach ([
            [[$this->read, 'searchContacts'], 'search_contacts', 'Search contacts', $ro],
            [[$this->read, 'getContact'], 'get_contact', 'Get contact', $ro],
            [[$this->read, 'listSegments'], 'list_segments', 'List segments', $ro],
            [[$this->read, 'listEmails'], 'list_emails', 'List emails', $ro],
            [[$this->read, 'getEmail'], 'get_email', 'Get email', $ro],
            [[$this->read, 'listEmailTopics'], 'list_email_topics', 'Email topics', $ro],
            [[$this->read, 'getEmailStats'], 'get_email_stats', 'Email statistics', $ro],
            [[$this->read, 'listCampaigns'], 'list_campaigns', 'List campaigns', $ro],
            [[$this->read, 'getCampaign'], 'get_campaign', 'Get campaign', $ro],
            [[$this->read, 'listCampaignStepTypes'], 'list_campaign_step_types', 'Campaign step types', $ro],
            [[$this->read, 'describeCampaignStepType'], 'describe_campaign_step_type', 'Describe campaign step type', $ro],
            [[$this->contacts, 'listContactFields'], 'list_contact_fields', 'List contact fields', $ro],
            [[$this->contacts, 'upsertContacts'], 'upsert_contacts', 'Create or update contacts', new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false)],
            [[$this->segmentTools, 'createSegment'], 'create_segment', 'Create segment', $rw],
            [[$this->segmentTools, 'updateSegment'], 'update_segment', 'Update segment', new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: true, openWorldHint: false)],
            [[$this->segmentTools, 'listSegmentFilterFields'], 'list_segment_filter_fields', 'Segment filter fields', $ro],
            [[$this->contacts, 'createContactField'], 'create_contact_field', 'Create contact or company field', new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false)],
            [[$this->companies, 'searchCompanies'], 'search_companies', 'Search companies', $ro],
            [[$this->companies, 'getCompany'], 'get_company', 'Get company', $ro],
            [[$this->companies, 'upsertCompanies'], 'upsert_companies', 'Create or update companies', new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false)],
            [[$this->companies, 'addContactsToCompany'], 'add_contacts_to_company', 'Link contacts to company', new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false)],
            [[$this->companies, 'removeContactsFromCompany'], 'remove_contacts_from_company', 'Unlink contacts from company', new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: true, openWorldHint: false)],
            [[$this->read, 'getContactActivity'], 'get_contact_activity', 'Contact activity', $ro],
            [[$this->insightTools, 'getSegmentSummary'], 'get_segment_summary', 'Segment summary', $ro],
            [[$this->insightTools, 'findDuplicateContacts'], 'find_duplicate_contacts', 'Find duplicate contacts', $ro],
            [[$this->insightTools, 'getCampaignHealth'], 'get_campaign_health', 'Campaign health check', $ro],
            [[$this->sendTools, 'previewEmailSend'], 'preview_email_send', 'Preview an email send', new ToolAnnotations(readOnlyHint: true, openWorldHint: false)],
            [[$this->sendTools, 'sendEmail'], 'send_email', 'Send an email to contacts or segments', new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: false, openWorldHint: true)],
            [[$this->layoutTools, 'listEmailLayouts'], 'list_email_layouts', 'Brand email layouts', $ro],
            [[$this->layoutTools, 'createEmailFromLayout'], 'create_email_from_layout', 'Create email in the brand theme', $rw],
            [[$this->layoutTools, 'updateEmailLayout'], 'update_email_layout', 'Replace email content (brand theme)', new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: true, openWorldHint: false)],
            [[$this->pointTools, 'listPointGroups'], 'list_point_groups', 'Point groups', $ro],
            [[$this->pointTools, 'createPointGroup'], 'create_point_group', 'Create point group', $rw],
            [[$this->pointTools, 'updatePointGroup'], 'update_point_group', 'Update point group', new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false)],
            [[$this->pointTools, 'listPointActionTypes'], 'list_point_action_types', 'Point action types', $ro],
            [[$this->pointTools, 'describePointActionType'], 'describe_point_action_type', 'Describe point action type', $ro],
            [[$this->pointTools, 'listPointActions'], 'list_point_actions', 'Point actions', $ro],
            [[$this->pointTools, 'createPointAction'], 'create_point_action', 'Create point action', $rw],
            [[$this->pointTools, 'updatePointAction'], 'update_point_action', 'Update point action', new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false)],
            [[$this->pointTools, 'listPointTriggerEventTypes'], 'list_point_trigger_event_types', 'Point trigger event types', $ro],
            [[$this->pointTools, 'describePointTriggerEventType'], 'describe_point_trigger_event_type', 'Describe point trigger event type', $ro],
            [[$this->pointTools, 'listPointTriggers'], 'list_point_triggers', 'Point triggers', $ro],
            [[$this->pointTools, 'createPointTrigger'], 'create_point_trigger', 'Create point trigger', $rw],
            [[$this->pointTools, 'updatePointTrigger'], 'update_point_trigger', 'Update point trigger', new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: true, openWorldHint: false)],
            [[$this->pointTools, 'adjustContactPoints'], 'adjust_contact_points', 'Change contact points', new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: true)],
            [[$this->write, 'sendTestEmail'], 'send_test_email', 'Send test email to yourself', new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: true)],
            [[$this->contacts, 'addContactsToSegment'], 'add_contacts_to_segment', 'Add contacts to segment', new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false)],
            [[$this->contacts, 'removeContactsFromSegment'], 'remove_contacts_from_segment', 'Remove contacts from segment', new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: true, openWorldHint: false)],
            [[$this->write, 'createEmailDraft'], 'create_email_draft', 'Create email draft', $rw],
            [[$this->write, 'updateEmailDraft'], 'update_email_draft', 'Update email draft', $rw],
            [[$this->write, 'createCampaignDraft'], 'create_campaign_draft', 'Create campaign draft', $rw],
            [[$this->write, 'updateCampaignDraft'], 'update_campaign_draft', 'Update campaign draft', $rw],
            [[$this->write, 'setCampaignSteps'], 'set_campaign_steps', 'Set campaign flow', new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: true, openWorldHint: false)],
            [[$this->write, 'updateCampaignStep'], 'update_campaign_step', 'Update campaign step', new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false)],
            [[$this->write, 'addCampaignStep'], 'add_campaign_step', 'Add campaign step', $rw],
            [[$this->write, 'removeCampaignStep'], 'remove_campaign_step', 'Remove campaign step', new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: true, openWorldHint: false)],
            [[$this->stageTools, 'listStages'], 'list_stages', 'Stages', $ro],
            [[$this->stageTools, 'createStage'], 'create_stage', 'Create stage', $rw],
            [[$this->stageTools, 'updateStage'], 'update_stage', 'Update stage', new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false)],
            [[$this->stageTools, 'setContactStage'], 'set_contact_stage', 'Move contact to stage', new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: true)],
            [[$this->projectTools, 'listProjects'], 'list_projects', 'Projects', $ro],
            [[$this->projectTools, 'getProject'], 'get_project', 'Project with its items', $ro],
            [[$this->projectTools, 'createProject'], 'create_project', 'Create project', $rw],
            [[$this->projectTools, 'updateProject'], 'update_project', 'Update project', new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false)],
            [[$this->projectTools, 'addToProject'], 'add_to_project', 'Add items to project', new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false)],
            [[$this->projectTools, 'removeFromProject'], 'remove_from_project', 'Remove items from project', new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false)],
        ] as [$handler, $name, $title, $annotations]) {
            if (in_array($name, self::SEND_TOOLS, true) ? $allowSend : ($allowWrite || !in_array($name, $gated, true))) {
                $builder->addTool($handler, $name, $title, null, $annotations);
            }
        }

        return $builder->build();
    }
}

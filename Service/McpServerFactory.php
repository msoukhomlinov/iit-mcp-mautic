<?php

declare(strict_types=1);

namespace MauticPlugin\IntellectItMcpBundle\Service;

use Doctrine\DBAL\Connection;
use Firebase\JWT\Key;
use GuzzleHttp\Psr7\HttpFactory;
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
use MauticPlugin\IntellectItMcpBundle\Tools\LayoutTools;
use MauticPlugin\IntellectItMcpBundle\Tools\PointTools;
use MauticPlugin\IntellectItMcpBundle\Tools\ProjectTools;
use MauticPlugin\IntellectItMcpBundle\Tools\SegmentTools;
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
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
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
            scopesSupported: [McpScopes::READ, McpScopes::WRITE, McpScopes::CONTACTS_WRITE, McpScopes::CAMPAIGNS_LIVE],
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
            tools: array_fill_keys(self::WRITE_TOOLS, [McpScopes::WRITE]) + array_fill_keys(self::CONTACT_TOOLS, [McpScopes::CONTACTS_WRITE]),
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

        return Server::builder()
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
                'Emails and new campaigns are created unpublished; publishing them and sending stay with a person in Mautic.',
                'Exception: flows of RUNNING (published) campaigns can be edited (set_campaign_steps, update/add/remove_campaign_step) if the instance allows it and the user granted the scope mcp:campaigns:live. Such changes are live: Mautic\'s scheduler executes them for the contacts in the campaign and can send emails. Always call with dryRun first, show the user live_effects (outbound steps, contacts in the campaign, save_allowed) and save only after they confirm.',
                'Every call runs with the Mautic permissions of the signed-in user.',
                'Return the "url" of created drafts to the user so they can review them.',
            ]))
            ->setLogger($this->logger)
            ->setSession(new Psr16SessionStore($cache, 'session-', 3600))
            ->addTool([$this->read, 'searchContacts'], 'search_contacts', 'Search contacts', null, $ro)
            ->addTool([$this->read, 'getContact'], 'get_contact', 'Get contact', null, $ro)
            ->addTool([$this->read, 'listSegments'], 'list_segments', 'List segments', null, $ro)
            ->addTool([$this->read, 'listEmails'], 'list_emails', 'List emails', null, $ro)
            ->addTool([$this->read, 'getEmail'], 'get_email', 'Get email', null, $ro)
            ->addTool([$this->read, 'listEmailTopics'], 'list_email_topics', 'Email topics', null, $ro)
            ->addTool([$this->read, 'getEmailStats'], 'get_email_stats', 'Email statistics', null, $ro)
            ->addTool([$this->read, 'listCampaigns'], 'list_campaigns', 'List campaigns', null, $ro)
            ->addTool([$this->read, 'getCampaign'], 'get_campaign', 'Get campaign', null, $ro)
            ->addTool([$this->read, 'listCampaignStepTypes'], 'list_campaign_step_types', 'Campaign step types', null, $ro)
            ->addTool([$this->read, 'describeCampaignStepType'], 'describe_campaign_step_type', 'Describe campaign step type', null, $ro)
            ->addTool([$this->contacts, 'listContactFields'], 'list_contact_fields', 'List contact fields', null, $ro)
            ->addTool([$this->contacts, 'upsertContacts'], 'upsert_contacts', 'Create or update contacts', null, new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false))
            ->addTool([$this->segmentTools, 'createSegment'], 'create_segment', 'Create segment', null, $rw)
            ->addTool([$this->segmentTools, 'updateSegment'], 'update_segment', 'Update segment', null, new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: true, openWorldHint: false))
            ->addTool([$this->segmentTools, 'listSegmentFilterFields'], 'list_segment_filter_fields', 'Segment filter fields', null, $ro)
            ->addTool([$this->contacts, 'createContactField'], 'create_contact_field', 'Create contact or company field', null, new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false))
            ->addTool([$this->companies, 'searchCompanies'], 'search_companies', 'Search companies', null, $ro)
            ->addTool([$this->companies, 'getCompany'], 'get_company', 'Get company', null, $ro)
            ->addTool([$this->companies, 'upsertCompanies'], 'upsert_companies', 'Create or update companies', null, new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false))
            ->addTool([$this->companies, 'addContactsToCompany'], 'add_contacts_to_company', 'Link contacts to company', null, new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false))
            ->addTool([$this->companies, 'removeContactsFromCompany'], 'remove_contacts_from_company', 'Unlink contacts from company', null, new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: true, openWorldHint: false))
            ->addTool([$this->read, 'getContactActivity'], 'get_contact_activity', 'Contact activity', null, $ro)
            ->addTool([$this->layoutTools, 'listEmailLayouts'], 'list_email_layouts', 'Brand email layouts', null, $ro)
            ->addTool([$this->layoutTools, 'createEmailFromLayout'], 'create_email_from_layout', 'Create email in the brand theme', null, $rw)
            ->addTool([$this->layoutTools, 'updateEmailLayout'], 'update_email_layout', 'Replace email content (brand theme)', null, new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: true, openWorldHint: false))
            ->addTool([$this->pointTools, 'listPointGroups'], 'list_point_groups', 'Point groups', null, $ro)
            ->addTool([$this->pointTools, 'createPointGroup'], 'create_point_group', 'Create point group', null, $rw)
            ->addTool([$this->pointTools, 'updatePointGroup'], 'update_point_group', 'Update point group', null, new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false))
            ->addTool([$this->pointTools, 'listPointActionTypes'], 'list_point_action_types', 'Point action types', null, $ro)
            ->addTool([$this->pointTools, 'describePointActionType'], 'describe_point_action_type', 'Describe point action type', null, $ro)
            ->addTool([$this->pointTools, 'listPointActions'], 'list_point_actions', 'Point actions', null, $ro)
            ->addTool([$this->pointTools, 'createPointAction'], 'create_point_action', 'Create point action', null, $rw)
            ->addTool([$this->pointTools, 'updatePointAction'], 'update_point_action', 'Update point action', null, new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false))
            ->addTool([$this->pointTools, 'listPointTriggerEventTypes'], 'list_point_trigger_event_types', 'Point trigger event types', null, $ro)
            ->addTool([$this->pointTools, 'describePointTriggerEventType'], 'describe_point_trigger_event_type', 'Describe point trigger event type', null, $ro)
            ->addTool([$this->pointTools, 'listPointTriggers'], 'list_point_triggers', 'Point triggers', null, $ro)
            ->addTool([$this->pointTools, 'createPointTrigger'], 'create_point_trigger', 'Create point trigger', null, $rw)
            ->addTool([$this->pointTools, 'updatePointTrigger'], 'update_point_trigger', 'Update point trigger', null, new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: true, openWorldHint: false))
            ->addTool([$this->pointTools, 'adjustContactPoints'], 'adjust_contact_points', 'Change contact points', null, new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: true))
            ->addTool([$this->write, 'sendTestEmail'], 'send_test_email', 'Send test email to yourself', null, new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: true))
            ->addTool([$this->contacts, 'addContactsToSegment'], 'add_contacts_to_segment', 'Add contacts to segment', null, new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false))
            ->addTool([$this->contacts, 'removeContactsFromSegment'], 'remove_contacts_from_segment', 'Remove contacts from segment', null, new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: true, openWorldHint: false))
            ->addTool([$this->write, 'createEmailDraft'], 'create_email_draft', 'Create email draft', null, $rw)
            ->addTool([$this->write, 'updateEmailDraft'], 'update_email_draft', 'Update email draft', null, $rw)
            ->addTool([$this->write, 'createCampaignDraft'], 'create_campaign_draft', 'Create campaign draft', null, $rw)
            ->addTool([$this->write, 'updateCampaignDraft'], 'update_campaign_draft', 'Update campaign draft', null, $rw)
            ->addTool([$this->write, 'setCampaignSteps'], 'set_campaign_steps', 'Set campaign flow', null, new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: true, openWorldHint: false))
            ->addTool([$this->write, 'updateCampaignStep'], 'update_campaign_step', 'Update campaign step', null, new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false))
            ->addTool([$this->write, 'addCampaignStep'], 'add_campaign_step', 'Add campaign step', null, $rw)
            ->addTool([$this->write, 'removeCampaignStep'], 'remove_campaign_step', 'Remove campaign step', null, new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: true, openWorldHint: false))
            ->addTool([$this->stageTools, 'listStages'], 'list_stages', 'Stages', null, $ro)
            ->addTool([$this->stageTools, 'createStage'], 'create_stage', 'Create stage', null, $rw)
            ->addTool([$this->stageTools, 'updateStage'], 'update_stage', 'Update stage', null, new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false))
            ->addTool([$this->stageTools, 'setContactStage'], 'set_contact_stage', 'Move contact to stage', null, new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: true))
            ->addTool([$this->projectTools, 'listProjects'], 'list_projects', 'Projects', null, $ro)
            ->addTool([$this->projectTools, 'getProject'], 'get_project', 'Project with its items', null, $ro)
            ->addTool([$this->projectTools, 'createProject'], 'create_project', 'Create project', null, $rw)
            ->addTool([$this->projectTools, 'updateProject'], 'update_project', 'Update project', null, new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false))
            ->addTool([$this->projectTools, 'addToProject'], 'add_to_project', 'Add items to project', null, new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false))
            ->addTool([$this->projectTools, 'removeFromProject'], 'remove_from_project', 'Remove items from project', null, new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false))
            ->build();
    }
}

<?php

declare(strict_types=1);

namespace MauticPlugin\IntellectItMcpBundle\Tools;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\EmailBundle\Entity\Email;
use Mautic\EmailBundle\Model\EmailModel;
use Mautic\LeadBundle\Entity\LeadList;
use Mautic\LeadBundle\Model\ListModel;
use Mcp\Capability\Attribute\Schema;
use MauticPlugin\IntellectItMcpBundle\Service\McpError;
use Mcp\Server\RequestContext;
use MauticPlugin\GrapesJsBuilderBundle\Entity\GrapesJsBuilder;
use MauticPlugin\IntellectItMcpBundle\Service\EmailLayouts;
use MauticPlugin\IntellectItMcpBundle\Service\EmailTopics;
use MauticPlugin\IntellectItMcpBundle\Service\McpUserContext;

const LAYOUT_SCHEMA = [
    'type'     => 'array',
    'description' => 'Panels in order, each {"type": "<panel>", "<area>": [elements]} (see mautic_list_email_layouts)',
    'minItems' => 1,
    'maxItems' => 20,
    'items'    => [
        'type'                 => 'object',
        'required'             => ['type'],
        'description'          => 'A panel: {"type": "<panel>", "<area>": [elements]}. Areas and elements per panel: see mautic_list_email_layouts. Example: {"type": "article", "items": [{"type": "title", "text": "Hallo"}, {"type": "text", "html": "…"}, {"type": "button", "text": "Jetzt ansehen", "url": "https://…"}]}',
        'properties'           => ['type' => ['type' => 'string']],
        'additionalProperties' => ['type' => 'array', 'items' => ['type' => 'object', 'required' => ['type'], 'properties' => ['type' => ['type' => 'string']]]],
    ],
];

/**
 * Emails in the brand theme: built from the theme's panels and elements,
 * stored as MJML (editable in Mautic's builder) plus the HTML for sending.
 * Scope mcp:write; created unpublished like every MCP email.
 */
class LayoutTools
{
    public function __construct(
        private readonly McpUserContext $auth,
        private readonly EmailLayouts $layouts,
        private readonly EmailModel $emails,
        private readonly ListModel $segments,
        private readonly EntityManagerInterface $em,
        private readonly CoreParametersHelper $parameters,
        private readonly EmailTopics $topics,
    ) {
    }

    /**
     * List the brand email layouts: themes with their panels (e.g. hero, article, cards), the elements each panel takes (label, title, text, button, image, ...) and their fields. Use them with mautic_create_email_from_layout.
     *
     * @return array<string, mixed>
     */
    public function listEmailLayouts(RequestContext $context): array
    {
        $this->auth->require($context, ['email:emails:viewown', 'email:emails:viewother']);

        return ['layouts' => array_map(fn (string $theme): array => $this->layouts->describe($theme), array_keys($this->layouts->all()))];
    }

    /**
     * Create an unpublished email in the brand theme from panels and elements (see mautic_list_email_layouts). Header with logo and footer with imprint, privacy and unsubscribe are added automatically. The email opens in Mautic's builder like any theme email; send yourself a test with mautic_send_test_email. Prefer this over mautic_create_email_draft.
     *
     * @param string                     $name       Internal email name (1-191 characters)
     * @param string                     $subject    Subject line (1-191 characters)
     * @param list<array<string, mixed>> $panels     Panels in order
     * @param string                     $type       "segment" (newsletter) or "template" (campaigns, forms, double opt-in)
     * @param list<int>                  $segmentIds Segments of a segment email
     * @param string                     $preheader  Preview text after the subject
     * @param string                     $language   Language code, e.g. "en" (default: the Mautic default locale)
     * @param string                     $theme      Theme with layouts (default: the first theme that has layouts, see mautic_list_email_layouts)
     * @param string                     $topic      Topic of the email (name or ID from mautic_list_email_topics): people who switched the topic off in their email preferences do not get it. Leave empty only for service emails the person asked for
     *
     * @return array<string, mixed>
     */
    public function createEmailFromLayout(
        RequestContext $context,
        #[Schema(minLength: 1, maxLength: 191)] string $name,
        #[Schema(minLength: 1, maxLength: 191)] string $subject,
        #[Schema(definition: LAYOUT_SCHEMA)] array $panels,
        #[Schema(enum: ['segment', 'template'])] string $type = 'template',
        array $segmentIds = [],
        string $preheader = '',
        string $language = '',
        string $theme = '',
        string $topic = '',
    ): array {
        $this->auth->require($context, 'email:emails:create');
        $theme    = $this->theme($theme);
        $rendered = $this->layouts->render($theme, $panels);

        $email = new Email();
        $email->setName($name);
        $email->setSubject($subject);
        $email->setTemplate($theme);
        $email->setCustomHtml($rendered['html']);
        $email->setPlainText($rendered['text']);
        $email->setEmailType('segment' === $type ? 'list' : 'template');
        $email->setPreheaderText('' !== $preheader ? $preheader : null);
        $email->setLanguage('' !== $language ? $language : (string) $this->parameters->get('locale', 'en'));
        $email->setDescription('Created via MCP from the '.$theme.' layout. Review before publishing.');
        $email->setIsPublished(false);
        $this->topics->apply($email, $topic);
        foreach ($this->segmentEntities($context, $segmentIds) as $segment) {
            $email->addList($segment);
        }
        $this->em->wrapInTransaction(function () use ($email, $rendered): void {
            $this->emails->saveEntity($email);
            $this->storeMjml($email, $rendered['mjml']);
        });
        $this->auth->audit('email', (int) $email->getId(), 'create', ['name' => $name, 'layout' => $theme, 'panels' => array_column($panels, 'type')]);

        return $this->result($email, 'Draft created in the '.$theme.' theme (unpublished). It is editable in Mautic\'s builder; review and publish it there.');
    }

    /**
     * Replace the content of an unpublished, never-sent email with new panels (see mautic_list_email_layouts); optionally change the subject.
     *
     * @param int                        $id        ID of the unpublished, never-sent email
     * @param list<array<string, mixed>> $panels    Panels in order, replacing all existing content
     * @param string|null                $subject   New subject (omit to keep the current one)
     * @param string|null                $preheader New preview text (omit to keep, "" clears it)
     * @param string                     $theme     Theme with layouts (default: the email's current theme, else the first)
     * @param string|null                $topic  New topic (name or ID, "" removes it), see mautic_list_email_topics
     *
     * @return array<string, mixed>
     */
    public function updateEmailLayout(
        RequestContext $context,
        int $id,
        #[Schema(definition: LAYOUT_SCHEMA)] array $panels,
        ?string $subject = null,
        ?string $preheader = null,
        string $theme = '',
        ?string $topic = null,
    ): array {
        $email = $this->emails->getEntity($id);
        if (!$email instanceof Email || !$email->getId()) {
            throw McpError::from(sprintf('Email %d not found.', $id));
        }
        $this->auth->requireEntityAccess($context, $email, 'email:emails', 'edit');
        if ($email->isPublished() || $email->getSentCount(true) > 0) {
            throw McpError::from('Only unpublished drafts that were never sent can be changed via MCP. Unpublish or clone the email in Mautic first.');
        }
        $theme    = $this->theme('' !== $theme ? $theme : (isset($this->layouts->all()[(string) $email->getTemplate()]) ? (string) $email->getTemplate() : ''));
        $rendered = $this->layouts->render($theme, $panels);
        $email->setTemplate($theme);
        $email->setCustomHtml($rendered['html']);
        $email->setPlainText($rendered['text']);
        if (null !== $subject) {
            $email->setSubject($subject);
        }
        if (null !== $preheader) {
            $email->setPreheaderText($preheader);
        }
        $this->topics->apply($email, $topic);
        $this->em->wrapInTransaction(function () use ($email, $rendered): void {
            $this->emails->saveEntity($email);
            $this->storeMjml($email, $rendered['mjml']);
        });
        $this->auth->audit('email', (int) $email->getId(), 'update', ['layout' => $theme, 'panels' => array_column($panels, 'type')]);

        return $this->result($email, 'Draft content replaced ('.count($panels).' panel(s)).');
    }

    private function theme(string $theme): string
    {
        $all = array_keys($this->layouts->all());
        if ('' === $theme) {
            if ([] === $all) {
                throw McpError::from('No theme with email layouts is installed.');
            }

            return $all[0];
        }
        $this->layouts->library($theme);

        return $theme;
    }

    /** The builder (GrapesJS) loads the email's MJML from its own table. */
    private function storeMjml(Email $email, string $mjml): void
    {
        $repo    = $this->em->getRepository(GrapesJsBuilder::class);
        $builder = $repo->findOneBy(['email' => $email]);
        if (!$builder instanceof GrapesJsBuilder) {
            $builder = new GrapesJsBuilder();
            $builder->setEmail($email);
        }
        $builder->setCustomMjml($mjml);
        $this->em->persist($builder);
        $this->em->flush();
    }

    /**
     * @param array<mixed> $ids
     *
     * @return list<LeadList>
     */
    private function segmentEntities(RequestContext $context, array $ids): array
    {
        $out = [];
        foreach (array_unique(array_map('intval', $ids)) as $id) {
            $segment = $this->segments->getEntity($id);
            if (!$segment instanceof LeadList || !$segment->getId()) {
                throw McpError::from(sprintf('Segment %d not found.', $id));
            }
            $this->auth->requireEntityAccess($context, $segment, 'lead:lists', 'view');
            $out[] = $segment;
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function result(Email $email, string $message): array
    {
        $base = rtrim((string) $this->parameters->get('site_url'), '/');

        return [
            'id'        => $email->getId(),
            'name'      => $email->getName(),
            'subject'   => $email->getSubject(),
            'theme'     => $email->getTemplate(),
            'published' => $email->isPublished(),
            'message'   => $message,
            'url'       => $base.'/s/emails/view/'.$email->getId(),
            'preview'   => $base.'/email/preview/'.$email->getId(),
        ] + $this->topics->describe($email);
    }
}

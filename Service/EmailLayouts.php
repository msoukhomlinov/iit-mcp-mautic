<?php

declare(strict_types=1);

namespace MauticPlugin\IntellectItMcpBundle\Service;

use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\CoreBundle\Helper\PathsHelper;
use Mautic\CoreBundle\Twig\Helper\AssetsHelper;

/**
 * Email layouts of themes that ship a block library (mcp/blocks.json, e.g.
 * the Kopexa theme): panels of elements, each as MJML (for Mautic's
 * builder) and as HTML compiled by MJML (for sending; Mautic compiles MJML
 * only in the browser). render() concatenates both from the same layout,
 * like qa/blocks.mjs of the theme, whose check proves that the result equals
 * MJML's own output.
 */
class EmailLayouts
{
    private const ALLOWED_TAGS = ['b', 'strong', 'i', 'em', 'u', 'a', 'br', 'p', 'ul', 'ol', 'li', 'span'];

    /** @var array<string, array<string, mixed>>|null */
    private ?array $libraries = null;

    public function __construct(
        private readonly PathsHelper $paths,
        private readonly AssetsHelper $assets,
        private readonly CoreParametersHelper $parameters,
    ) {
    }

    /** @return array<string, array<string, mixed>> theme => library */
    public function all(): array
    {
        if (null !== $this->libraries) {
            return $this->libraries;
        }
        $this->libraries = [];
        foreach (glob(rtrim($this->paths->getThemesPath(), '/').'/*/mcp/blocks.json') ?: [] as $file) {
            $lib = json_decode((string) file_get_contents($file), true);
            if (is_array($lib) && 1 === ($lib['version'] ?? null) && isset($lib['panels'], $lib['mjml'], $lib['html'])) {
                $this->libraries[basename(dirname($file, 2))] = $lib;
            }
        }
        ksort($this->libraries);

        return $this->libraries;
    }

    /** @return array<string, mixed> */
    public function library(string $theme): array
    {
        $lib = $this->all()[$theme] ?? null;
        if (null === $lib) {
            throw McpError::from(sprintf('Theme "%s" has no email layouts. Available: %s.', $theme, implode(', ', array_keys($this->all())) ?: 'none'));
        }

        return $lib;
    }

    /**
     * Compact description for the assistant: panels, areas, elements, slots.
     *
     * @return array<string, mixed>
     */
    public function describe(string $theme): array
    {
        $lib    = $this->library($theme);
        $panels = [];
        foreach ($lib['panels'] as $name => $panel) {
            $areas = [];
            foreach ($panel['areas'] as $area => $elements) {
                foreach ($elements as $type => $e) {
                    $areas[$area][$type] = ['description' => $e['description'], 'fields' => $e['slots']];
                }
            }
            $panels[$name] = ['description' => $panel['description'], 'areas' => $areas];
        }

        $placeholders = [];
        foreach ((array) ($lib['placeholders'] ?? []) as $kind => $urls) {
            $placeholders[$kind] = array_map(fn (string $u): string => $this->assetUrls($theme, $u), (array) $urls);
        }

        return ['theme' => $theme, 'description' => $lib['description'] ?? '', 'field_types' => $lib['slot_types'] ?? [], 'panels' => $panels, 'placeholder_images' => $placeholders];
    }

    /**
     * @param list<array<string, mixed>> $layout panels: {"type": "hero", "items": [{"type": "title", "text": "…"}, …]}
     *
     * @return array{mjml: string, html: string, text: string}
     */
    public function render(string $theme, array $layout): array
    {
        $lib = $this->library($theme);
        if ([] === $layout) {
            throw McpError::from('The layout needs at least one panel.');
        }
        $mjml = '';
        $html = '';
        foreach (array_values($layout) as $i => $panel) {
            $type = (string) ($panel['type'] ?? '');
            $def  = $lib['panels'][$type] ?? null;
            if (null === $def) {
                throw McpError::from(sprintf('Panel %d: unknown type "%s"; use one of %s.', $i + 1, $type, implode(', ', array_keys($lib['panels']))));
            }
            $unknownAreas = array_diff(array_keys(array_diff_key($panel, ['type' => 1])), array_keys($def['areas']));
            if ([] !== $unknownAreas) {
                throw McpError::from(sprintf('Panel %d (%s): unknown area(s) %s; it has %s.', $i + 1, $type, implode(', ', $unknownAreas), implode(', ', array_keys($def['areas']))));
            }
            if ($i > 0) {
                $mjml .= $lib['mjml']['gap'];
                $html .= $lib['html']['gap'];
            }
            $mjml .= $def['mjml']['parts'][0];
            $html .= $def['html']['parts'][0];
            $a = 0;
            foreach ($def['areas'] as $area => $elements) {
                foreach (array_values((array) ($panel[$area] ?? [])) as $k => $item) {
                    $etype = (string) ($item['type'] ?? '');
                    $e     = $elements[$etype] ?? null;
                    if (null === $e) {
                        throw McpError::from(sprintf('Panel %d (%s), %s %d: unknown element "%s"; use one of %s.', $i + 1, $type, $area, $k + 1, $etype, implode(', ', array_keys($elements))));
                    }
                    $values = $this->values($e['slots'], $item, sprintf('Panel %d (%s), %s %d (%s)', $i + 1, $type, $area, $k + 1, $etype));
                    $mjml .= $this->fill($e['mjml'], $values);
                    $html .= $this->fill($e['html'], $values);
                }
                ++$a;
                $mjml .= $def['mjml']['parts'][$a];
                $html .= $def['html']['parts'][$a];
            }
        }

        $assets   = fn (string $s): string => $this->assetUrls($theme, $s);
        $document = $assets((string) $lib['html']['document']);

        return [
            'mjml' => $assets($lib['mjml']['open']).$mjml.$assets($lib['mjml']['close']),
            'html' => str_replace('<!--kx:content-->', $html, $document),
            'text' => $this->plainText($html),
        ];
    }

    /** {{asset:path}} -> absolute URL of themes/<theme>/assets/path */
    private function assetUrls(string $theme, string $s): string
    {
        return preg_replace_callback('/\{\{asset:([^}]+)\}\}/', fn (array $m): string => $this->assets->getUrl('themes/'.$theme.'/assets/'.$m[1], null, null, true), $s) ?? $s;
    }

    /**
     * @param array<string, string> $slots slot => type
     * @param array<string, mixed>  $item
     *
     * @return array<string, string> escaped values
     */
    private function values(array $slots, array $item, string $where): array
    {
        $unknown = array_diff(array_keys($item), ['type', ...array_keys($slots)]);
        if ([] !== $unknown) {
            throw McpError::from(sprintf('%s: unknown field(s) %s; it takes %s.', $where, implode(', ', $unknown), implode(', ', array_keys($slots))));
        }
        $out = [];
        foreach ($slots as $slot => $type) {
            $value = trim((string) ($item[$slot] ?? ''));
            if ('' === $value && 'alt' !== $slot) {
                throw McpError::from(sprintf('%s: "%s" is required.', $where, $slot));
            }
            $out[$slot] = match ($type) {
                'html'  => $this->sanitize($value),
                'url'   => $this->url($value, $where, false),
                'image' => $this->url($value, $where, true),
                default => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            };
        }

        return $out;
    }

    /** @param array<string, string> $values */
    private function fill(string $template, array $values): string
    {
        return preg_replace_callback('/\{\{([a-z]+)\}\}/', fn (array $m): string => $values[$m[1]] ?? '', $template) ?? $template;
    }

    private function url(string $value, string $where, bool $image): string
    {
        $site = rtrim((string) $this->parameters->get('site_url'), '/');
        $own  = '' !== $site && str_starts_with($value, $site.'/') && 1 === preg_match('#^[^\s"<>]+$#', $value);
        $ok   = $image
            ? $own || 1 === preg_match('#^https://[^\s"<>]+$#i', $value)
            : 1 === preg_match('#^(https://[^\s"<>]+|mailto:[^\s"<>]+|\{[a-z_]+(=[^}]*)?\})$#i', $value);
        if (!$ok) {
            throw McpError::from(sprintf('%s: "%s" must be %s.', $where, $value, $image ? 'an https image URL (or an image of this Mautic)' : 'an https or mailto URL, or a Mautic token such as {doi_link}'));
        }

        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Inline HTML: allowed tags only, attributes only href on links (https, mailto, Mautic tokens). */
    private function sanitize(string $html): string
    {
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $old = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"><div id="kx">'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($old);
        $root = $doc->getElementById('kx') ?? $doc->documentElement;

        $walk = function (\DOMNode $node) use (&$walk): string {
            $out = '';
            foreach (iterator_to_array($node->childNodes) as $child) {
                if ($child instanceof \DOMText) {
                    $out .= htmlspecialchars($child->textContent, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                } elseif ($child instanceof \DOMElement) {
                    $tag   = strtolower($child->tagName);
                    $inner = $walk($child);
                    if (!in_array($tag, self::ALLOWED_TAGS, true)) {
                        $out .= $inner; // drop the tag, keep the text
                    } elseif ('br' === $tag) {
                        $out .= '<br>';
                    } elseif ('a' === $tag) {
                        $href = trim($child->getAttribute('href'));
                        $out .= 1 === preg_match('#^(https://|mailto:|\{[a-z_]+)#i', $href)
                            ? '<a href="'.htmlspecialchars($href, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'">'.$inner.'</a>'
                            : $inner;
                    } else {
                        $out .= "<{$tag}>{$inner}</{$tag}>";
                    }
                }
            }

            return $out;
        };

        return $walk($root);
    }

    private function plainText(string $html): string
    {
        $text = preg_replace(['/<(style|head)[\s\S]*?<\/\1>/i', '/<a [^>]*href="([^"]+)"[^>]*>([\s\S]*?)<\/a>/i', '/<br\s*\/?>|<\/(p|div|h[1-6]|tr|li)>/i'], ['', '$2 ($1)', "\n"], $html) ?? '';
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace(["/[ \t]+/", "/\n\s*\n+/"], [' ', "\n\n"], $text) ?? '');
    }
}

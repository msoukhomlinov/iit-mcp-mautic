---
type: concept
title: Destructive operations and brand layouts
description: How mautic_delete_record and mautic_merge_contacts preview and confirm irreversible changes, and how the brand email layout tools render theme block libraries (mcp/blocks.json) into MJML and HTML emails.
tags: [destructive, delete, merge, layouts, email, mjml]
verified:
  - by: openwiki/0.9.0
    at: 2026-11-17T00:16:34.909Z
sources:
  - id: openwiki-source-a2af3984c50b24fce6f0ca5e
    resource: repo://Service/EmailLayouts.php
  - id: openwiki-source-ee6046059a2dfa01454dd9df
    resource: repo://Tools/DestructiveTools.php
  - id: openwiki-source-75229f8d0d8ab099b1d24b9d
    resource: repo://Tools/LayoutTools.php
generated: { by: "openwiki/0.9.0", at: "2026-11-17T00:16:34.909Z" }
---

# Destructive operations and brand layouts

This page owns the confirmation protocol of the two irreversible tools and the layout discovery and rendering mechanism. Registration switches, scopes and defaults live in [capability-gating.md](../architecture/capability-gating.md).

## Destructive operations

`Tools/DestructiveTools.php` holds `mautic_delete_record` and `mautic_merge_contacts`. Both follow a two-step protocol: a call without `confirm=true` changes nothing and returns a preview; the real call must repeat the call with `confirm=true` plus an echo of a value taken from the preview.

### Delete

`deleteRecord` accepts `type` (one of contact, company, segment, email, campaign) and an `id`. Per type it selects the Mautic model, the permission base (`lead:leads`, `lead:lists`, `email:emails`, `campaign:campaigns`) and an impact query. It then:

1. Loads the entity and fails with "not found" if missing.
2. Requires the `delete` permission on that entity via `McpUserContext::requireEntityAccess`.
3. Computes a display name (a contact's email, falling back to its full name; otherwise the entity name) and impact counts via direct `COUNT(*)` queries, honouring `MAUTIC_TABLE_PREFIX`. Impacts cover e.g. emails received and memberships for a contact, or campaigns and emails using a segment.
4. Computes blockers: a published email or campaign must be unpublished first; a segment used by a campaign or email cannot be deleted; an email used by a campaign step cannot be deleted (otherwise Mautic would leave the step pointing at nothing).

The preview contains `type`, `id`, `name`, `impact`, `blockers` and `can_delete`. With `confirm=true`:

- If blockers exist, a `delete_blocked` audit entry is written and the call fails.
- If `expectedName` is missing or differs from the preview `name`, the call fails with the `McpError::CONFIRM_REQUIRED` code.
- Otherwise a `delete` audit entry is written with name and impact, then the model's `deleteEntity` runs.

### Merge

`mergeContacts(winnerId, loserId, confirm, expectedLoserId)` rejects identical ids, loads both contacts, and requires `edit` on the winner and `delete` on the loser. The preview lists a summary of both contacts, `points_after` (the sum of both), and `fields_differ`: fields where both sides are non-empty and differ (`id` and `points` are skipped). The note states that Mautic keeps the value of the more recently modified contact. With `confirm=true`, `expectedLoserId` must equal `loserId`, otherwise `CONFIRM_REQUIRED` is raised. A `merge` audit entry is written and `ContactMerger::merge($winner, $loser)` runs; the loser is deleted.

The preview text tells the caller that an attending person must see it. Unattended caller mode only omits the `requiresUserInteraction` tool meta; the confirmation protocol is unchanged (see [capability-gating.md](../architecture/capability-gating.md#attended-and-unattended-mode)).

## Brand email layouts

Layout tools exist only when an installed Mautic theme ships `mcp/blocks.json`. `EmailLayouts::hasAny` globs `<themes path>/*/mcp/blocks.json` without parsing, to decide whether to list the tools.

### Library loading

`EmailLayouts::all()` parses each file once per service instance and keeps a library only if it is a JSON array with `version` equal to `1` and has `panels`, `mjml` and `html` keys. Libraries are keyed by theme directory name and sorted by name. `library($theme)` fails with a message listing available themes when the theme is unknown.

`describe($theme)` returns the theme description, `slot_types` (as `field_types`), each panel's description and its areas with element descriptions and slots (as `fields`), and `placeholder_images` with `{{asset:path}}` expanded to absolute theme asset URLs.

### Rendering

`render($theme, $layout)` takes a list of panels, each `{"type": <panel>, "<area>": [elements]}`:

- An empty layout is rejected. Unknown panel types, unknown areas and unknown element types are errors that name the valid choices.
- For each panel the library's MJML and HTML fragments are assembled from `parts` interleaved with element templates, with `mjml.gap` / `html.gap` between panels. Both outputs are built from the same layout so they stay equivalent. The HTML is needed because Mautic only compiles MJML in the browser.
- Element slots are validated: unknown fields are errors and every slot except `alt` is required. Values are escaped by slot type: `html` is sanitised, `url` and `image` are URL-checked, anything else is HTML-escaped. Templates are filled by replacing `{{name}}` placeholders.
- URL rules: links must be `https://`, `mailto:` or a Mautic token such as `{doi_link}`; images must be `https://` or start with the configured `site_url`.
- Inline HTML is parsed with DOM and rebuilt using an allow-list of tags (`b strong i em u a br p ul ol li span`). Other tags are dropped but their text is kept; `a` keeps only a valid `href`, otherwise only its content.
- The result has `mjml` (library open + panels + close), `html` (panel HTML substituted into the document at the `<!--kx:content-->` marker) and `text`, a plain-text rendition of the HTML with link targets in parentheses.

### Tools

`Tools/LayoutTools.php`: `mautic_create_email_from_layout` and `mautic_update_email_layout` require scope `mcp:write`, while `mautic_list_email_layouts` is not in the write list and so needs only `mcp:read` (see [capability-gating.md](../architecture/capability-gating.md#tool-to-scope-mapping)).

- `mautic_list_email_layouts` needs email view permission (own or other) and returns the `describe` output for every theme.
- `mautic_create_email_from_layout` needs `email:emails:create`. `panels` is schema-limited to between 1 and 20 items, and name and subject to 1–191 characters. The theme defaults to the first available theme. The email is created **unpublished**, with the theme as its template, the rendered HTML as custom HTML, the plain-text part, language defaulting to the Mautic `locale` parameter, `type` `segment` mapped to a list email and `template` otherwise, and any given segments (each needing view access) attached. A topic is applied through `EmailTopics`. The email save and the MJML store run in one transaction: the MJML is stored in the GrapesJS builder entity so the email opens in Mautic's builder. An audit entry is written.
- `mautic_update_email_layout` requires edit access and refuses emails that are published or have been sent; it replaces the content, and optionally the subject, preheader and topic. The theme defaults to the email's current template if that theme has layouts.

Both write tools return the email id, subject, theme, published state, a Mautic view URL and a preview URL built from `site_url`.

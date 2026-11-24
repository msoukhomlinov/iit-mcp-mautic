---
type: concept
title: Capability gating, scopes and operator settings
description: Operator parameters (intellectit_mcp_*) with defaults, OAuth scopes, the tool-to-scope mapping, hidden tool groups, attended vs unattended mode and layout-tool availability.
tags: [gating, scopes, configuration, security]
verified:
  - by: openwiki/0.9.0
    at: 2026-11-17T00:16:34.909Z
sources:
  - id: openwiki-source-b0ee022fd73f8d5b24347cb4
    resource: repo://Config/config.php
  - id: openwiki-source-a2af3984c50b24fce6f0ca5e
    resource: repo://Service/EmailLayouts.php
  - id: openwiki-source-a7de9e38c7aea9f08224a222
    resource: repo://Service/McpScopes.php
  - id: openwiki-source-ebf7ea8a3de0c62b2477868b
    resource: repo://Service/McpServerFactory.php
  - id: openwiki-source-0f41fe64ef5e38b0983ba682
    resource: repo://Tools/PointTools.php
  - id: openwiki-source-c265c2fb8ccffcd324dccbfa
    resource: repo://Tools/WriteTools.php
generated: { by: "openwiki/0.9.0", at: "2026-11-17T00:16:34.909Z" }
---

# Capability gating, scopes and operator settings

Access to a tool is decided by three independent layers, all of which must allow it:

1. **Operator switches** (Mautic parameters): decide which tools are *registered* at all. A tool that is not registered is absent from `tools/list` and cannot be called.
2. **OAuth scopes** on the access token: enforced per tool by the transport (see [server-and-request-flow.md](server-and-request-flow.md)).
3. **The Mautic role** of the token's user, checked inside each tool (see [server-and-request-flow.md](server-and-request-flow.md#user-context-and-permissions)).

## Operator parameters

Defaults are declared in `Config/config.php`; operators override them in `config/local.php`.

| Parameter | Default | Meaning |
|---|---|---|
| `intellectit_mcp_allow_write` | `false` | Register the write and contact-write tools |
| `intellectit_mcp_caller_mode` | `attended` | `unattended` changes instructions and removes the human-prompt hint (see below) |
| `intellectit_mcp_allow_destructive` | `false` | Register delete and merge |
| `intellectit_mcp_allow_send` | `false` | Register the two send tools |
| `intellectit_mcp_allow_segment_send` | `false` | Allow segment targets in sends |
| `intellectit_mcp_send_max_recipients` | `100` | Per-call recipient cap |
| `intellectit_mcp_send_max_per_hour` | `500` | Rolling hourly recipient cap, `0` = no limit |
| `intellectit_mcp_send_ignore_dnc` | `false` | Also email Do Not Contact contacts on direct contact sends |
| `intellectit_mcp_page_limit` | `50` | Maximum items returned by the contact, segment, email and campaign list and search tools |
| `intellectit_mcp_live_campaign_edits` | `false` | Allow edits of running campaigns and published point triggers |
| `intellectit_mcp_hidden_groups` | `[]` | Tool groups to hide |

Enforcement of the send parameters is described in [tools/email-sending.md](../tools/email-sending.md). The page limit is read only in `Tools/ReadTools.php` (see [tools/contacts-segments-companies.md](../tools/contacts-segments-companies.md#reading) for the tools it caps). The live-edit switch is checked in `Tools/WriteTools.php` (campaign flows, see [tools/campaign-flows.md](../tools/campaign-flows.md)) and `Tools/PointTools.php` (published triggers, see [tools/scoring-stages-projects.md](../tools/scoring-stages-projects.md)).

Boolean parameters are parsed with `FILTER_VALIDATE_BOOLEAN`, so environment-style strings such as `"true"` work.

## Scopes

`McpScopes` declares the scopes to the authorization server (the kopexa OAuth bundle):

| Scope | Opt-in | Purpose |
|---|---|---|
| `mcp:read` | no (default scope) | Read tools |
| `mcp:write` | no | Drafts and campaign flows, point groups/actions/triggers, stages, projects |
| `mcp:contacts:write` | no | Contacts, companies, segments, contact fields, contact points and stage |
| `mcp:send` | yes | Send tools |
| `mcp:destructive` | yes | Delete and merge |
| `mcp:campaigns:live` | yes | Changes to running campaigns |

`McpScopes::REGULAR` is read, write and contacts-write: the scopes a client normally holds (the opt-in ones are excluded).

### Tool to scope mapping

`McpServerFactory` builds a `ScopePolicy` whose default is `mcp:read`. Tools in its `WRITE_TOOLS` list require `mcp:write`, `CONTACT_TOOLS` require `mcp:contacts:write`, `SEND_TOOLS` (`mautic_preview_email_send`, `mautic_send_email`) require `mcp:send`, and `DESTRUCTIVE_TOOLS` (`mautic_delete_record`, `mautic_merge_contacts`) require `mcp:destructive`. Note that `mautic_preview_email_send` needs the send scope even though it only reads. `mcp:campaigns:live` is not part of the transport policy: it is checked inside the tools that edit running campaigns or published triggers via `McpUserContext::hasScope`.

## Registration rules

`McpServerFactory::server()` walks one table of tools and registers each when enabled:

- Destructive tools: only if `intellectit_mcp_allow_destructive`.
- Send tools: only if `intellectit_mcp_allow_send`.
- Every other tool in the write or contact-write lists: only if `intellectit_mcp_allow_write`. Read tools are always registered.
- Layout tools (`mautic_list_email_layouts`, `mautic_create_email_from_layout`, `mautic_update_email_layout`) are additionally dropped unless at least one installed theme ships `mcp/blocks.json` (`EmailLayouts::hasAny`). See [tools/destructive-operations.md](../tools/destructive-operations.md#brand-email-layouts).
- Tools of a hidden group are dropped.

`mautic_setup_status` is always registered; it is restricted by role instead (see [operations/setup.md](../operations/setup.md)).

Because the send and destructive switches are tested separately from `allow_write`, enabling sending does not enable the write tools, and vice versa.

## Hidden groups

`intellectit_mcp_hidden_groups` accepts an array or a string split on commas and whitespace; names are lower-cased and unknown ones ignored (`parseHiddenGroups`). Known groups are `points`, `stages`, `projects` and `companies`. A hidden group removes its tools from the list and the corresponding sentences from the server instructions (the companies group also removes the mention from the reading and contacts lines).

## Attended and unattended mode

`intellectit_mcp_caller_mode` equal to `unattended` has two effects:

- The server instructions are different: they tell the model never to ask a person, and `forCaller()` rewrites phrases such as "show live_effects" into "check live_effects".
- The `anthropic/requiresUserInteraction` tool meta, otherwise attached to `mautic_delete_record`, `mautic_merge_contacts` and `mautic_send_email`, is omitted, so clients do not prompt a person.

It does not relax any switch, scope, role or confirmation requirement.

## Tool annotations

Read tools are annotated read-only and closed-world; create tools are non-destructive; tools that replace or remove state (for example segment update, set campaign steps, remove step) carry `destructiveHint`. Tools that can reach contacts or trigger mail carry `openWorldHint`. These annotations are hints to the client, not enforcement.

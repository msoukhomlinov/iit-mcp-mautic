# Scoping

Recorded decisions for the IntellectIT Mautic MCP server, kept in the form the IntellectIT MCP server standard asks for. Update this file whenever a decision changes.

## Server

- **What it is:** an in-Mautic plugin (PHP, Mautic 7.2+, PHP 8.4+) that serves MCP over Streamable HTTP at `/mcp`. Forked from `kopexa-grc/mautic-mcp-bundle` (Apache-2.0); Kopexa stays credited in `NOTICE`.
- **Domain / tool prefix:** Mautic. Tool names have no prefix (inherited from upstream), see Exceptions.
- **Why a plugin and not a standalone server:** per-user OAuth 2.1 and Mautic's own permission checks on every call (chosen over a single service identity).
- **CI pipeline: YES** (`.github/workflows/ci.yml`: `bin/check.sh` plus the functional suite against a real Mautic 7.2 on PHP 8.4 and 8.5).
- **Package published:** no. Private repository.

## Profiles (fixed at server start by operator parameters in `config/local.php`)

| Profile | Switch | Default | Tools | Notes |
|---|---|---|---|---|
| Read-only | none | on | 30 | reads, insights, projects, lists |
| Write | `intellectit_mcp_allow_write` | **off** | 64 | creates and edits unpublished emails, campaigns, segments, contacts; live-campaign edits also need `intellectit_mcp_live_campaign_edits` and scope `mcp:campaigns:live` |
| Send | `intellectit_mcp_allow_send` | **off** | 66 | `preview_email_send`, `send_email`; scope `mcp:send` (opt-in); segments need `intellectit_mcp_allow_segment_send`; cap `intellectit_mcp_send_max_recipients` (100) |

Counts and sizes are measured by `tests/functional/surface.sh` (see Budget).

## Credential and identity model

OAuth 2.1 (authorization code with PKCE, dynamic client registration) from `kopexa/mautic-oauth-bundle`; the token's subject becomes the Mautic user, so every call runs with that user's Mautic permissions. No shared service credential, no credentials in tool arguments.

## Write safety model

1. Operator switches decide which tools exist (not registered when off).
2. OAuth scopes (`mcp:read`, `mcp:write`, `mcp:contacts:write`, `mcp:campaigns:live`, `mcp:send`) decide what a token may call.
3. Mautic role permissions decide what the user may touch.
4. Sending: preview, then `send_email` with `confirm=true`; unpublished emails never sent; Do Not Contact and addressless contacts skipped; per-call cap; `send_email` carries `requiresUserInteraction`.
5. Changes with side effects elsewhere (segments, scoring, stages, live campaigns) offer `dryRun` with `live_effects`.
6. Audit: writes and sends, including refused sends, go to Mautic's audit log with the client id.

## Budget (measured by `tests/functional/surface.sh`, Mautic 7.2.2)

- Instructions: 2,888 characters (limit 3,500); hard rules in the first 512 characters.
- `tools/list` with every tier on: 66 tools, 51,194 (about 13k tokens) bytes, 99 parameters without a description.

## Exceptions

Rule, reason, approver, date. "PENDING" means inherited from upstream and not yet decided by the owner; the standard treats it as UNAPPROVED until approved.

| # | Rule | Reason | Approver | Date | Status |
|---|---|---|---|---|---|
| 1 | Standalone TypeScript server over an npm SDK (pnpm, `server.json`, `capabilities.json`, Docker gates, stdio, `surface_audit.py`) | In-Mautic PHP plugin for per-user OAuth and Mautic permission fidelity; those rules do not apply to a plugin | Max Soukhomlinov | 2026-11-07 | APPROVED |
| 2 | Per-operation allow/gate/deny table from environment variables; gateway identity headers | Operator switches + OAuth scopes + Mautic RBAC give a stronger per-user policy than a shared table | Max Soukhomlinov | 2026-11-07 | APPROVED |
| 3 | Confirmation bound to a plan hash with expiry | Plain `confirm=true` after a preview, chosen by the owner; the server re-plans at confirm time, so a stale preview cannot be replayed with different recipients | Max Soukhomlinov | 2026-11-07 | APPROVED |
| 4 | Do Not Contact is never overridden | Operator parameter `intellectit_mcp_send_ignore_dnc` (default off, direct contact sends only) exists at the owner's request; the override is written to the audit log | Max Soukhomlinov | 2026-11-07 | APPROVED |
| 5 | About 10 always-loaded tools, long tail behind search/describe/dispatch | 64 to 66 one-per-operation tools inherited from upstream; fewer tools by default through the read-only profile | none yet | | PENDING |
| 6 | Domain prefix on tool names for federation | Bare names (`get_contact`) inherited from upstream; a rename is a breaking change | none yet | | PENDING |
| 7 | Stateless operation | MCP sessions are stored in the Mautic database (`Psr16SessionStore`, one hour), inherited from upstream; replica-safe | none yet | | PENDING |

## Open gaps against the standard (not exceptions, planned or undecided)

- No error taxonomy codes or correlation IDs; refusals are free-text messages.
- No `mautic_guide` tool or outcome skills; playbooks live in the instructions.
- Audit has no per-call outcome or approver record beyond the audit-log row.
- Parameters without a description (see Budget); no mutation-check evidence for the tests.
- No MCP Inspector or protocol-conformance run (the functional suite speaks JSON-RPC directly).

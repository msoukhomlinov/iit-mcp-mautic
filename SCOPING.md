# Scoping

Recorded decisions for the IntellectIT Mautic MCP server, kept in the form the IntellectIT MCP server standard asks for. Update this file whenever a decision changes.

## Server

- **What it is:** an in-Mautic plugin (PHP, Mautic 7.2+, PHP 8.4+) that serves MCP over Streamable HTTP at `/mcp`. Forked from `kopexa-grc/mautic-mcp-bundle` (Apache-2.0); Kopexa stays credited in `NOTICE`.
- **Domain / tool prefix:** Mautic. Every tool name is prefixed `mautic_` (upstream names had no prefix; renamed for federation).
- **Why a plugin and not a standalone server:** per-user OAuth 2.1 and Mautic's own permission checks on every call (chosen over a single service identity).
- **CI pipeline: YES** (`.github/workflows/ci.yml`: `bin/check.sh` plus the functional suite against a real Mautic 7.2 on PHP 8.4 and 8.5).
- **Package published:** no. Private repository.

## Profiles (fixed at server start by operator parameters in `config/local.php`)

| Profile | Switch | Default | Tools | Notes |
|---|---|---|---|---|
| Read-only | none | on | 28 | reads, insights, projects, lists |
| Write | `intellectit_mcp_allow_write` | **off** | 58 | creates and edits emails and campaigns (unpublished), segments (created published), contacts, companies, scoring, stages, projects; `mautic_send_test_email` (to the user only). Edits to running campaigns and published point triggers also need `intellectit_mcp_live_campaign_edits` (**off**) and scope `mcp:campaigns:live` |
| Destructive | `intellectit_mcp_allow_destructive` | **off** | +2 | `mautic_delete_record` (contact, company, segment, email, campaign) and `mautic_merge_contacts`; scope `mcp:destructive` (opt-in); preview, then `confirm=true` with `expectedName` / `expectedLoserId`; emails and campaigns must be unpublished, segments in use cannot be deleted; audited |
| Send | `intellectit_mcp_allow_send` | **off** | +2 (29 with Write off, 60 with everything on) | `mautic_preview_email_send`, `mautic_send_email`; scope `mcp:send` (opt-in); segments need `intellectit_mcp_allow_segment_send`; cap `intellectit_mcp_send_max_recipients` (100) per call and `intellectit_mcp_send_max_per_hour` (500, all users, 0 = off) |

Counts and sizes are measured by `tests/functional/surface.sh` (see Budget).

## Credential and identity model

OAuth 2.1 (authorization code with PKCE, dynamic client registration) from `kopexa/mautic-oauth-bundle`; the token's subject becomes the Mautic user, so every call runs with that user's Mautic permissions. No shared service credential, no credentials in tool arguments.

## Write safety model

1. Operator switches decide which tools exist (not registered when off).
2. OAuth scopes (`mcp:read`, `mcp:write`, `mcp:contacts:write`, `mcp:campaigns:live`, `mcp:send`) decide what a token may call.
3. Mautic role permissions decide what the user may touch.
4. Sending: preview, then `mautic_send_email` with `confirm=true`, `expectedRecipientCount` (the preview's count; a different count is refused) and `targetsHash` (the preview's `targets_hash` over the email and the exact ids; a different selection is refused). The server re-plans at confirm time. The hash binds the selection, not a stored plan: there is no expiry and no server-side state. Unpublished emails never sent; Do Not Contact and addressless contacts skipped; per-call cap; `mautic_send_email` carries `requiresUserInteraction`.
5. Irreversible changes (delete, merge) are a separate tier with its own switch and scope, preview first, and a confirm that names the record. Changes with side effects elsewhere (segments, scoring, stages, live campaigns) offer `dryRun` with `live_effects`.
6. Audit: writes, confirmed sends, sends blocked after `confirm=true`, and every PERMISSION_DENIED, BLOCKED_BY_POLICY or CONFIRM_REQUIRED refusal (object `refusal`, with code and ref) go to Mautic's audit log. Previews and `confirm=false` calls are audited as `send_preview` (mode, targets, targets_hash, count, blockers); they do not count against the hourly limit.
7. Email outside the send tier: campaign steps on running campaigns (live edits, off by default, plus `mcp:campaigns:live`), contacts entering running campaigns through segments, and published point triggers can all cause email. They are guarded by `dryRun` with `live_effects`, scopes and the live-edit switch, not by the send tier.

## Budget (measured by `tests/functional/surface.sh`, Mautic 7.2.2)

- Instructions: 3,442 characters attended (limit 3,500); hard rules in the first 512 characters.
- `tools/list` per profile (measured by `surface.sh`): read-only 28 tools, 13,855 bytes (about 3.5k tokens); write 59 tools, 54,695 bytes (about 13.7k tokens); every tier on 63 tools, 58,923 bytes (about 14.7k tokens). These figures include the three layout tools, which are listed only when a theme ships `mcp/blocks.json`; the test instance has none, so `surface.sh` measures 27 / 56 / 60 tools (13,845 / 51,586 / 55,955 bytes). With the optional `intellectit_mcp_hidden_groups` setting hiding points, stages, projects and companies, the write plus send profile is 33 tools, 34,436 bytes (about 8.6k tokens, inside the budget); an email-and-campaign team should set it. 0 parameters without a description (enforced). The standard's budget is under about 10k tokens per profile: read-only is inside it, write and send are over. See exception 5.

## Exceptions

Rule, reason, approver, date. "PENDING" means inherited from upstream and not yet decided by the owner; the standard treats it as UNAPPROVED until approved.

| # | Rule | Reason | Approver | Date | Status |
|---|---|---|---|---|---|
| 1 | Standalone TypeScript server over an npm SDK (pnpm, `server.json`, `capabilities.json`, Docker gates, stdio, `surface_audit.py`) | In-Mautic PHP plugin for per-user OAuth and Mautic permission fidelity; those rules do not apply to a plugin | Max Soukhomlinov | 2026-11-07 | APPROVED |
| 2 | Per-operation allow/gate/deny table from environment variables; gateway identity headers | Operator switches + OAuth scopes + Mautic RBAC give a stronger per-user policy than a shared table | Max Soukhomlinov | 2026-11-07 | APPROVED |
| 3 | Confirmation bound to a plan hash with expiry | Plain `confirm=true` after a preview, chosen by the owner, tightened with a required `expectedRecipientCount`. Since the review hardening the confirm also carries `targetsHash`, which binds it to the previewed email and exact contact/segment ids; still no expiry or stored plan | Max Soukhomlinov | 2026-11-07 | APPROVED |
| 4 | Do Not Contact is never overridden | Operator parameter `intellectit_mcp_send_ignore_dnc` (default off, direct contact sends only) exists at the owner's request; the override is written to the audit log | Max Soukhomlinov | 2026-11-07 | APPROVED |
| 5 | About 10 always-loaded tools, long tail behind search/describe/dispatch; under about 10k tokens served | Upstream's 66 one-per-operation tools were reduced to 60 by merging six sibling pairs (add/remove and list/describe) behind `action`/`type` parameters, and every parameter is now described. Remainder: 60 tools, about 14k tokens with every tier on; an operator who does not use points, stages, projects or companies hides them with `intellectit_mcp_hidden_groups` (33 tools, about 8.6k tokens for write plus send). Evidence that more merging is possible but costly: create/update pairs for eight resources differ in required fields | Max Soukhomlinov (plan: merge, describe, record the remainder) | 2026-11-07 | APPROVED with the measured numbers above (write 13.5k tokens, all tiers 14k, read-only 3.4k) |
| 6 | Domain prefix on tool names for federation | Bare names (`mautic_get_contact`) inherited from upstream. Owner chose to prefix every tool with `mautic_` (breaking change for existing clients) | Max Soukhomlinov | 2026-11-07 | APPROVED, DONE |
| 7 | Stateless operation | MCP sessions are stored in the Mautic database (`Psr16SessionStore`, one hour), inherited from upstream; replica-safe | Max Soukhomlinov | 2026-11-07 | APPROVED |
| 8 | Running-campaign edits default to allowed (upstream) | Default changed to **off**: such edits can email contacts outside the send tier | Max Soukhomlinov | 2026-11-07 | APPROVED, DONE |
| 9 | The server never asks a human (no-questions contract) | Owner decision: the server must work both attended and unattended. Today the instructions assume a person; Built: operator parameter `intellectit_mcp_caller_mode` (`attended` default, `unattended`). Unattended: instructions never ask a person, `requiresUserInteraction` is dropped from `mautic_send_email`, the preview tells the caller to check it against its task. Switches, scopes and roles still decide what is allowed (a refusal is a coded error), and sends still need preview, `confirm=true` and `expectedRecipientCount`. Limits: one mode per server, not per token; tool descriptions still say "show to the user" | Max Soukhomlinov | 2026-11-07 | APPROVED, DONE |
| 10 | Error taxonomy codes and correlation IDs | Refusals are free-text messages today. Built: every refusal reads `[CODE] message (ref <id>)` with CODE one of PERMISSION_DENIED, NOT_FOUND, BLOCKED_BY_POLICY, CONFIRM_REQUIRED, UPSTREAM_ERROR, INVALID_INPUT. The code is chosen from the message text by `McpError`, so a new message may land on INVALID_INPUT until its pattern is added. The ref is also written to the audit rows of that call. Not done: no `isError` structured content or retry hint | Max Soukhomlinov | 2026-11-07 | APPROVED, DONE |

## Open gaps against the standard (not exceptions, planned or undecided)

- No `mautic_guide` tool or outcome skills; playbooks live in the instructions.
- Audit has no approver record beyond the audit-log row; a `send_preview` row records that a preview happened, not who agreed to it.
- No per-day send limit (per-call and per-hour only; the hourly count comes from the audit log).
- The raw-API passthrough tool was considered and skipped by the owner (2026-11-07): the named tools cover the work and a passthrough widens the surface.
- Contact merge, delete and the hourly send limit are built. Confirmed sends hold a database lock (`GET_LOCK`) so the hourly check and its audit row cannot interleave; a send that fails midway is counted at its planned size.
- No `composer audit` step in CI: the package has no lock file; its dependencies resolve inside Mautic.
- The protocol check is the MCP Inspector CLI (v2.10.1, `tests/functional/inspector.sh`, run by hand: it downloads the Inspector); there is no full conformance suite beyond it and the JSON-RPC functional suite.

## Test evidence

Mutation checks (2026-11-07, Mautic 7.2.2): each guard below was broken by hand, one at a time, and the functional suite failed every time; the change was then reverted.

| Mutation | Suite result |
|---|---|
| `mautic_send_email` accepts a different `expectedRecipientCount` | fails (`expectedRecipientCount not enforced`) |
| `mautic_send_email` accepts a `targetsHash` from another selection | fails (`targetsHash not enforced`) |
| Do Not Contact contacts no longer excluded from a send | fails (preview) |
| Unpublished email may be sent | fails (`unpublished email sent`) |
| Published email may be deleted | fails (`published email deleted`) |
| Wrong `expectedName` accepted for a delete | fails (delete executed) |
| Role permission check always passes | fails (restricted role gets data) |
| Destructive tools always registered | fails (`destructive tools listed by default`) |

Inspector (2026-11-07): `@modelcontextprotocol/inspector@2.10.1 --cli` against the live server with a bearer token: initialize and `tools/list` (27 tools at the time, read-only token, writes off; 28 since mautic_setup_status), a `tools/call` text result, and a coded `NOT_FOUND` tool error all pass.

# Architecture

IntellectIT Mautic MCP server: a Mautic plugin that exposes Mautic as MCP tools over Streamable HTTP, forked from `kopexa-grc/mautic-mcp-bundle`. Decisions and exceptions are recorded in `SCOPING.md`; this file describes how the code implements them.

## Request flow

```
MCP client --POST /mcp--> McpController
   -> StreamableHttpTransport middleware: CORS, DNS-rebinding (Host = this instance),
      protected-resource metadata, bearer-token validation (JWT, RS256, offline with the
      authorization server's public key), scope policy (tool -> required scope)
   -> Server built per request by McpServerFactory (tools registered by tier)
   -> Tools/* run as the Mautic user of the token (McpUserContext), checking Mautic permissions
   -> audit entries to Mautic's audit log
```

Sessions are stored in the Mautic database (`<prefix>intellectit_mcp_sessions`), so several web replicas work.

## Tiers (decided at server build time, per request)

| Tier | Parameter (default) | Tools registered | Scope |
|---|---|---|---|
| Read-only | always | the read, list and describe tools of every `Tools/*` class (contacts, companies, segments, emails, campaigns, scoring, stages, projects, layouts, insights) | `mcp:read` |
| Write | `intellectit_mcp_allow_write` (false) | create/update/upsert tools | `mcp:write`, `mcp:contacts:write` |
| Live edits | `intellectit_mcp_live_campaign_edits` (**false**, only with the Write tier) | step edits on running campaigns and changes to published point triggers | additionally `mcp:campaigns:live` |
| Send | `intellectit_mcp_allow_send` (false) | `preview_email_send`, `send_email` | `mcp:send` (opt-in) |
| Segment send | `intellectit_mcp_allow_segment_send` (false) | segment mode of the send tools | `mcp:send` |

`McpServerFactory::server()` filters the registration list by these switches. A tool that is not registered does not appear in `tools/list` and answers "Tool not found".

## Sending (`Tools/SendTools.php`)

1. `plan()` builds the recipient list and blockers for either contacts or the email's own segments. Contacts must be viewable (preview) or editable (send) by the user; segments additionally need `lead:leads:viewother`/`editother`.
2. Exclusions: Do Not Contact (unless `intellectit_mcp_send_ignore_dnc`), contacts without a valid address. Blockers: unpublished email, template email to segments, segment switch off, over the cap, no eligible recipients.
3. `send_email` without `confirm=true` returns the preview. With it, the plan is rebuilt; the call must carry `expectedRecipientCount` equal to the rebuilt `recipient_count`, and if there are no blockers Mautic's own `EmailModel::sendEmail` / `sendEmailToLists` sends it. Mautic's segment sending applies the limit across the listed segments in total (read in its source); the cap is tested in contact mode only.
4. `send_email` carries `_meta["anthropic/requiresUserInteraction"]`, so Claude Code prompts a person on every call. Server-side checks above do not depend on the client.
5. Confirmed sends and sends blocked after `confirm=true` are audited (mode, ids, counts, cap, whether the DNC override was on). Previews and permission refusals are not.

### Email outside the send tier

Campaign steps added to running campaigns, contacts entering running campaigns through segment membership, and published point triggers can also cause email. They are guarded by `dryRun` with `live_effects`, OAuth scopes and the live-edit switch (off by default), not by the send tier.

## Insight tools (`Tools/InsightTools.php`)

Read-only SQL: segment summary, duplicate finder (column whitelist, needs `lead:leads:viewother`), campaign health. Limits are clamped server-side.

## Testing

`tests/functional/*.sh` install the plugin into a real Mautic 7.2 and drive it over HTTP: gating, scopes and roles, flows run by Mautic's cron, insights, sending (null mailer, delivery checked in `email_stats`), and the served surface (`surface.sh`). CI runs them on PHP 8.4 and 8.5.

## Deviations from the IntellectIT MCP server standard

See `SCOPING.md` section "Exceptions" (APPROVED and PENDING entries) and "Open gaps". Tool-surface size, tool-name prefix and stateful sessions are inherited from upstream and are pending the owner's decision.

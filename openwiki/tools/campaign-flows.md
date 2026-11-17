---
type: feature
title: Campaign drafts, flows and email drafts
description: How the write tools create email and campaign drafts and edit campaign flows through CampaignFlowBuilder, including the running-campaign guard, live_effects, dry runs and the campaign health check.
tags: [campaigns, emails, write-tools, live-effects]
verified:
  - by: openwiki/0.9.0
    at: 2026-11-17T00:16:34.909Z
sources:
  - id: openwiki-source-98e77bc762b4edb67646cf7a
    resource: repo://Service/CampaignFlowBuilder.php
  - id: openwiki-source-b7b1774f8a4a8ca37352b53d
    resource: repo://Tools/InsightTools.php
  - id: openwiki-source-c265c2fb8ccffcd324dccbfa
    resource: repo://Tools/WriteTools.php
generated: { by: "openwiki/0.9.0", at: "2026-11-17T00:16:34.909Z" }
---

# Campaign drafts, flows and email drafts

These tools sit behind the write tier and the `mcp:write` scope; see [../architecture/capability-gating.md](../architecture/capability-gating.md) for the switches and scopes. They live in `Tools/WriteTools.php`, with flow logic in `Service/CampaignFlowBuilder.php`.

## Email drafts

- `mautic_create_email_draft` requires the `email:emails:create` permission and either `html` or `copyFromId`. Name and subject are limited to 1 to 191 characters. Type is `segment` (stored as Mautic type `list`) or `template`; the default is the original's type for a copy, otherwise `template`. New emails use Mautic's `mautic_code_mode` template with the HTML as custom HTML, and the locale parameter as default language.
- Drafts are always created **unpublished**.
- A copy (`copyFromId`) needs view access to the source (any status). It is a shallow clone given fresh collections: **no segments or excluded segments are carried over**, attachments are kept, the publish window is cleared, and the topic is kept when `topic` is empty. Passing new `html` replaces the content and clears the copied plain text.
- `mautic_update_email_draft` only changes emails that are unpublished **and** never sent; otherwise it refuses and points to copying. Omitted fields stay unchanged; `segmentIds` replaces the segment list.
- Topics are applied through `EmailTopics` (name or ID, see `mautic_list_email_topics`).
- `mautic_send_test_email` (self-only test send) is described in [email-sending.md](email-sending.md#test-email).

Real recipient sends are a separate tier: [email-sending.md](email-sending.md).

## Campaign drafts

`mautic_create_campaign_draft` creates an unpublished campaign with segments as its source; `mautic_update_campaign_draft` changes name, description and segments. A flow cannot be saved on a campaign without a source (segments or forms): a real save fails, while a dry run reports `save_blocked_because: no_source`.

## Flow tools

All flow edits go through `WriteTools::applyFlow`, which:

1. Takes a MySQL advisory lock `GET_LOCK(..., 10)` named from the database name, table prefix and campaign ID, so edits to one campaign are serialised and two Mautic instances sharing a server do not block each other. If the lock is not obtained within 10 seconds it refuses with `BLOCKED_BY_POLICY` ("Another edit of this campaign is in progress").
2. Loads the campaign and requires edit access on it.
3. Verifies every email used by `email.send` and `email.send.to.user` steps exists, is viewable by the user, and is a **template** email (segment emails cannot be sent by campaigns).
4. Applies the running-campaign guard (below).
5. Calls `CampaignFlowBuilder::applySteps` and writes an audit row (added, changed and removed keys) unless it was a dry run.

The tools are:

| Tool | Behaviour |
|---|---|
| `mautic_set_campaign_steps` | Replaces the complete flow; steps left out are removed (`allowDeletions`, optional `redirects`) |
| `mautic_update_campaign_step` | Changes name, wait or properties of one step |
| `mautic_add_campaign_step` | Adds one step |
| `mautic_remove_campaign_step` | Removes a step with no children; `confirm` is passed as the deletion permission, `redirectTo` names where waiting contacts continue |
| `mautic_campaign_step_types` | Lists available step types, from Mautic's event collector (so plugin-provided types appear) |

### Step model

A step has a `key`, `type`, optional `parent`, `path` (`yes`/`no` for decisions and conditions), `wait` (`{interval, unit}` with unit one of `i h d m y`, or `{at}`; not applicable to decisions) and `properties`. Keys of existing steps are their numeric Mautic event IDs; a numeric key that does not exist is an error, so new steps need non-numeric keys. The type of an existing step cannot change. Parents must be listed before their children. A flow has between 1 and 100 steps (`MAX_STEPS`).

### Building and validation

`CampaignFlowBuilder` applies a flow as a diff, as Mautic's API does: existing keys update in place and keep ID and contact history; new keys create steps; omitted steps are deleted. Each step is submitted through the step type's own Symfony form, so properties are validated and normalised as in the UI, and connection rules (`connectionRestrictions`) are checked. Tag fields accept names and are resolved to IDs; missing tags are reported as `tags_to_create` in the result. Events and canvas layout are written with `CampaignModel::setEvents()` and `setCanvasSettings()` in one database transaction (rolled back on failure), so the result opens in Mautic's campaign builder. The result lists `changes` as added, changed, unchanged and removed, and returns the new `steps` after a real save.

### Running (published) campaigns

For a published campaign:

- Steps may be added and changed but **not moved** (parent or path change is refused).
- Deleting steps needs `allowDeletions`; a dry run reports `save_blocked_because: needs_allow_deletions` instead of failing.
- A real save needs both the operator switch `intellectit_mcp_live_campaign_edits` and the token scope `mcp:campaigns:live` (checked in `applyFlowLocked`). Dry runs are always allowed and report `live_effects.save_allowed` and the reason when blocked.
- Contacts already scheduled on a changed step keep their scheduled time; the change applies from then on.

`live_effects` contains the number of contacts in the campaign (not manually removed), the added or changed **outbound steps**, and a warning. Outbound types (`OUTBOUND_TYPES`) are email send, send-to-user, marketing message, webhook, plugin lead push, focus item, dynamic content push, SMS and the two notification types. Segment joins have an analogous report from `SegmentEffects` (see [contacts-segments-companies.md](contacts-segments-companies.md)).

## Campaign health

`mautic_get_campaign_health` (`Tools/InsightTools.php`, view access required) runs read-only SQL for one campaign and returns contacts (not manually removed), live step count, scheduled steps, overdue steps (scheduled with trigger time more than one hour ago), steps run and failures in the last 24 hours, the last run time, and the top three failure reasons (first 200 characters, grouped). `status` is `ok` or `attention` depending on warnings: unpublished; published with no contacts; overdue steps (hinting that the trigger cron may not be running); failures in 24 hours; or published with contacts but nothing run in 24 hours and nothing scheduled.

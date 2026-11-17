---
type: concept
title: Points, stages and projects
description: Behaviour and safeguards of the point group/action/trigger tools, contact point adjustment, stage tools and project tools, including draft-only creation, dry runs and live-effect checks.
tags: [points, triggers, stages, projects, safeguards, dry-run]
verified:
  - by: openwiki/0.9.0
    at: 2026-11-17T00:16:34.909Z
sources:
  - id: openwiki-source-0f41fe64ef5e38b0983ba682
    resource: repo://Tools/PointTools.php
  - id: openwiki-source-a8c60f9025c70046569c48a7
    resource: repo://Tools/ProjectTools.php
  - id: openwiki-source-48a742a6c267081675199a0f
    resource: repo://Tools/StageTools.php
generated: { by: "openwiki/0.9.0", at: "2026-11-17T00:16:34.909Z" }
---

# Points, stages and projects

Implemented in `Tools/PointTools.php`, `Tools/StageTools.php` and `Tools/ProjectTools.php`. Which scope each tool needs and how the opt-in `mcp:campaigns:live` scope and the `intellectit_mcp_live_campaign_edits` switch are declared is owned by [capability-gating.md](../architecture/capability-gating.md); this page describes how the tools use them. Contact-level tools (upserts, segments) are in [contacts-segments-companies.md](contacts-segments-companies.md).

## Common pattern

- **Created unpublished.** Point actions, point triggers and stages are always created with `isPublished` false, and the result message tells a person to publish them in Mautic. The tools never publish.
- **Mautic permissions** are checked per tool (for example `point:points:create`, `point:triggers:edit`, `stage:stages:create`) and per entity via `McpUserContext::requireEntityAccess`.
- **Audit entries** are written for every change (`point_group`, `point_action`, `point_trigger`, `stage`, `project` and contact `points`/`stage` actions).
- **Dry run before live effects.** Operations that can immediately cause outbound effects take `dryRun` and describe the effects without saving.

## Point groups and actions

Point groups can be listed, created and renamed. Point actions are validated by submitting the settings through the real Mautic Symfony form for the action type (`submitAction`), run twice: first validate-only (so invalid input leaves no new tag rows behind), then for real. Errors point the caller to `mautic_point_action_types`; unknown types are rejected, and required settings missing from the type's form are reported. Discovery tools list action types and describe the settings of one type.

`updatePointAction` changes name, points, settings, group (0 clears), repeatable and description, and fails with "Nothing to change." if nothing is passed. It may be applied to a published action; the response says scoring changes from now on. There is no live-edit gate on point actions.

## Point triggers

`createPointTrigger` requires at least one event, is saved unpublished, and when `triggerExistingLeads` is set the message states how many contacts are already at the score. Event types are discovered with the trigger-event-type tools and validated through Mautic forms like actions; an event is kept across updates by its key.

`updatePointTrigger` is the gated live edit:

- If the trigger is **published**, the change is blocked unless `intellectit_mcp_live_campaign_edits` is on **and** the token holds `mcp:campaigns:live` (checked in the tool with `McpUserContext::hasScope`, not by the transport).
- When blocked, or when `dryRun` is set, the entity is detached so the requested change is validated but never saved.
- For a published trigger the result carries `live_effects`: whether saving is allowed, which of the outbound event types (`email.send`, `email.send_to_user`, `plugin.leadpush`, `campaign.changecampaign`) are present, how many contacts are already at the score, and how many would have new events run immediately (only when `triggerExistingLeads` is on and new event types were added). A blocked non-dry-run call throws the block message.

## Contact points

`mautic_adjust_contact_points` supports operators `add`, `subtract` and `set`, on the contact total or a point group, with a reason (truncated to 191 characters in the history entry). It needs edit access on the contact. Before changing anything it computes `triggersCrossed`: published triggers with a threshold above the old and up to the new score (same group scope) that have events with no entry yet in the contact's event log. Triggers are only computed when the score increases. `dryRun` returns before/after and these triggers; if the score would not change nothing is saved. `applyPointsChange` writes a `PointsChangeLog` entry with delta, applying everything as an add or subtract delta because Mautic's contact method has no "set"; the same method is reused by contact upserts.

## Stages

- `mautic_create_stage` rejects duplicate names and requires a **unique weight**: Mautic enforces uniqueness only in its form validator, so the tool checks it itself and lists the weights in use on conflict. `updateStage` repeats both checks only for actual renames or weight changes; published stages may be edited and contacts keep their stage.
- `mautic_set_contact_stage` takes a stage name or id, or `""` to clear. The target stage must exist and be **published** (`publishedStage`, also reused by contact upserts). It returns `live_effects` computed by `liveEffects()`: published dynamic segments with a filter on the `stage` field, plus the downstream effects for those segments (campaigns and segment emails they could trigger). Unchanged stage returns early; `dryRun` returns before saving. The change is saved through Mautic's `addToStage` so it appears in stage history.

## Projects

Project tools list, show, create and update projects and add or remove items. Names are unique. `addToProject` and `removeFromProject` accept 1 to 100 ids (`MAX_ITEMS`) of one item `type`. The allowed types come from Mautic's project loader filtered to those the user's role may edit, and an unknown type error lists them. Items are processed individually: missing ones, ones the user may not edit, ones already in (or not in) the project are returned under `skipped` with a reason instead of failing the call. Only successful changes trigger a flush and an `add_items`/`remove_items` audit entry. Removing leaves the items themselves unchanged.

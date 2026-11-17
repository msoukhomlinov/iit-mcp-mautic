---
type: concept
title: Email sending tier
description: How mautic_preview_email_send and mautic_send_email plan, preview and confirm real sends (targets_hash, recipient count, caps, Do Not Contact, segment sends, hourly limit read from the audit log), plus the self-only test email tool.
tags: [email, send, preview, confirmation, limits, audit]
verified:
  - by: openwiki/0.9.0
    at: 2026-11-17T00:16:34.909Z
sources:
  - id: openwiki-source-8efbf0a641c356ae48992661
    resource: repo://Tools/SendTools.php
  - id: openwiki-source-c265c2fb8ccffcd324dccbfa
    resource: repo://Tools/WriteTools.php
generated: { by: "openwiki/0.9.0", at: "2026-11-17T00:16:34.909Z" }
---

# Email sending tier

Real sends live in `Tools/SendTools.php`. Registration switches, scopes and the default values of every `intellectit_mcp_send_*` parameter are owned by [capability-gating.md](../architecture/capability-gating.md#operator-parameters); this page describes how they are enforced. Emails themselves are created as unpublished drafts (see [destructive-operations.md](destructive-operations.md#brand-email-layouts) and [campaign-flows.md](campaign-flows.md) for neighbouring write tools); a person publishes them in Mautic.

## One planner for preview and send

Both tools call the private `plan()` method, so the preview is exactly what a send would act on. It returns the preview array plus the email, resolved recipients, lists and cap.

Rules applied by `plan()`:

- Exactly one of `contactIds` or `segmentIds` must be given; ids are de-duplicated.
- The email must exist and the user needs view access. The email must be **published**, otherwise it is a blocker.
- The per-call cap is read from `intellectit_mcp_send_max_recipients` and is never below 1.
- The preview needs `view` on contacts; an actual send plans with `edit`.

### Contact sends

- More ids than the cap is a blocker. Only the first cap+1 ids are examined.
- Each contact must exist and pass entity access; contacts are then excluded (listed under `excluded`, not counted) as `no_valid_email`, `do_not_contact`, or `already_sent` (an `email_stats` row exists for that email and contact, because resends are disabled).
- Do Not Contact exclusion is skipped when `intellectit_mcp_send_ignore_dnc` is on; the preview then carries a warning.
- No eligible recipients is a blocker.

### Segment sends

- Only emails of type `list` (segment emails) qualify, and `intellectit_mcp_allow_segment_send` must be on; both are blockers otherwise.
- The user needs view (preview) or edit (send) on other users' contacts, and each requested segment must be one the email is assigned to and viewable.
- `recipient_count` is the sum of Mautic's pending leads per segment, capped at the per-call cap; `pending_total` shows the uncapped sum. Pending counts already exclude Do Not Contact and prior recipients, and a contact in several segments may be double counted in the preview.
- The preview warns that once an email is published, Mautic's own cron broadcast can send it to all segments outside this tool's caps.

### Hourly limit

When `intellectit_mcp_send_max_per_hour` is above zero, `sentInLastHour()` sums the `sent` value of `send` audit rows (bundle `intellectit_mcp`, object `email`) from the last 3600 seconds, across all users, and a send that would exceed the limit is a blocker. The limit is therefore only as accurate as the audit log; see below for failure handling.

## Preview output and `targets_hash`

The preview contains the email summary, `max_recipients_per_call`, `mode` (`contacts` or `segments`), `targets`, `recipient_count`, details (first ten recipients or per-segment pending counts), `warnings`, `blockers` and `can_send`. `targets_hash` is the first 16 hex characters of the SHA-256 of the JSON of `[emailId, targets]`, so it binds a confirmation to the email and exact id selection.

`mautic_preview_email_send` writes a `send_preview` audit entry (ids capped at 100 per list; audit failure is swallowed) and does not count against the hourly limit.

## Confirmation protocol

`mautic_send_email` without `confirm=true` returns the preview with `sent: false` and writes a `send_preview` audit entry; the instruction text differs by caller mode (see [capability-gating.md](../architecture/capability-gating.md#attended-and-unattended-mode)).

With `confirm=true`:

1. A database advisory lock (`GET_LOCK('intellectit_mcp_send', 10)`) serialises confirmed sends so the hourly check and the audit row that counts the send cannot interleave. Failing to get it yields a `BLOCKED_BY_POLICY` error; the lock is always released.
2. The plan is recomputed. Any blocker aborts with a `send_blocked` audit entry.
3. `targetsHash` must be non-empty and match (compared with `hash_equals`), else `send_blocked`.
4. `expectedRecipientCount` must be given and equal the freshly computed `recipient_count`; a difference means recipients changed since the preview and the send is refused.
5. Delivery: segment mode calls `EmailModel::sendEmailToLists` with the cap; contact mode calls `sendEmail` with source `['mcp', 0]`, resends off, DNC override from the operator setting, and per-recipient errors returned. Failures are counted and up to ten messages returned.
6. A `send` audit entry records mode, hash, ids, sent and failed counts, cap and DNC override.

Audit failure handling is deliberate: if delivery throws, a `send` entry is still written with the planned recipient count (Mautic may have sent some) before the error propagates; if the audit write fails after a successful send, the error is logged, the result is returned with `audit: failed`, and the hourly cap will miss that send.

## Test email

`mautic_send_test_email` (in `Tools/WriteTools.php`, scope `mcp:write`, not gated by the send switches) sends a sample only to the signed-in Mautic user's own address, via `sendSampleEmailToUser`, so it fails if that user has no valid address. Tokens are filled from a fake sample contact, or from a real contact (`previewContactId`) the user may view. Nothing is published and no statistics are recorded; a `send_test` audit entry is written.

---
type: feature
title: Contacts, segments, companies and read tools
description: Behaviour of the read tools, contact and company upserts, segment creation and membership changes, Do Not Contact handling, dry runs with live_effects, and insight tools such as duplicates and segment summary.
tags: [contacts, segments, companies, read-tools, dry-run]
verified:
  - by: openwiki/0.9.0
    at: 2026-11-17T00:16:34.909Z
sources:
  - id: openwiki-source-f6177d923840e64c3a84ba00
    resource: repo://Service/EmailTopics.php
  - id: openwiki-source-4fdf6cf53ea611d0725c0246
    resource: repo://Service/SegmentEffects.php
  - id: openwiki-source-7166b78e9df319851b1d8d9d
    resource: repo://Tools/CompanyTools.php
  - id: openwiki-source-7b5e0c066a5b00da09e10f59
    resource: repo://Tools/ContactTools.php
  - id: openwiki-source-b7b1774f8a4a8ca37352b53d
    resource: repo://Tools/InsightTools.php
  - id: openwiki-source-b62789ed0a500ad97db89e1b
    resource: repo://Tools/ReadTools.php
  - id: openwiki-source-6146ad584335d3de58607fc3
    resource: repo://Tools/SegmentTools.php
generated: { by: "openwiki/0.9.0", at: "2026-11-17T00:16:34.909Z" }
---

# Contacts, segments, companies and read tools

Read tools need only `mcp:read`; contact, company and segment writes need `mcp:contacts:write` and the write switch. See [../architecture/capability-gating.md](../architecture/capability-gating.md) for the switches and scopes, and [../architecture/server-and-request-flow.md](../architecture/server-and-request-flow.md) for how permissions are applied per user.

## Reading

`Tools/ReadTools.php` provides search and get tools for contacts, segments, emails and campaigns, plus email topics, email statistics, contact activity and campaign step types.

- **Search syntax:** `mautic_search_contacts` takes free text or Mautic search syntax (for example `tag:customer`, `segment:newsletter`). The additional terms `email_sent:<emailId>` and `email_read:<emailId>` list contacts who were sent or opened an email. `includeFields` returns every filled profile field per contact. List tools take `limit` (schema range 1 to 100) and `offset`.
- **Page limit:** every list is additionally capped by the `intellectit_mcp_page_limit` parameter (default in [capability-gating.md](../architecture/capability-gating.md)): the effective limit is `min(max(1, limit), page_limit)`.
- **Ownership filtering:** when the user lacks the "view others" permission, lists are forced to own items. Contacts count as own when the user is the owner, or when there is no owner and the user created them; other objects use the creator.
- **Email statistics** (`mautic_get_email_stats`) are lifetime totals across all uses of the email, without a date range.
- **Contact activity** takes optional `types`, a search string, `page` and `limit` (1 to 100).
- Campaign step type tools return the catalogue and per-type property descriptions (see [campaign-flows.md](campaign-flows.md)).

## Contact upsert

`mautic_upsert_contacts` creates or updates up to 100 contacts per call (`MAX_CONTACTS`), matching on email and the other unique contact fields.

- `overwrite=false` fills only empty fields on existing contacts; it also keeps an existing non-zero score and an existing stage.
- Rows may carry `tags` (with removals marked), segment membership, `points`, `stage`, and `unsubscribe`. The `points` and `stage` fields are refused with `BLOCKED_BY_POLICY` if the matching tool group is hidden.
- **Do Not Contact is add-only.** `unsubscribe: true` adds an email Do Not Contact entry unless the contact is already not contactable (reported as "already on the list, unchanged"); it never removes or alters entries. A non-boolean value is rejected.
- Each non-unchanged row is saved in its own transaction; a failing row is reported as failed and the remaining rows are still processed, unless the failure closed the entity manager, in which case the later rows are reported as `skipped`.
- `dryRun` validates and reports per-row status (created, updated, unchanged, failed) without saving, including `live_effects` for segment joins and any point triggers that would fire.
- Contact fields can be listed with `mautic_list_contact_fields` and created with `mautic_create_contact_field` (field types are a fixed list of text, number, date, select and similar types).

## Segments

`Tools/SegmentTools.php`:

- A segment without filters is **static**; with filters it is **dynamic** and filled by Mautic's segment cron job. Filters are validated through Mautic's own segment form, and tags in filters are resolved to IDs (a missing tag is created on a real save; dry runs never create tags, and a missing one resolves to `-1`, which matches nobody).
- `dryRun` on create validates the filters and returns `matching_contacts` (a count of unsaved matches) without saving. On update it also returns `live_effects`. New filters replace the old ones.
- `mautic_list_segment_filter_fields` lists usable filter fields.

### Membership changes

`mautic_change_segment_contacts` (action `add` or `remove`, 1 to 100 contact IDs, `dryRun`) delegates to add and remove handlers in `ContactTools`. Adding can make contacts enter running campaigns that use the segment and receive published segment emails, so dry run first and inspect `live_effects`. Only adding returns `live_effects`. The tool description says removed contacts leave campaigns that use the segment only if those campaigns are set to remove contacts; Do Not Contact is not changed.

### live_effects for segments

`SegmentEffects` queries, for the given segments, **published, non-deleted campaigns** using them and **published segment emails** (type `list`, not past their publish-down date). Its note says new members enter campaigns on the next campaign run and receive segment emails on the next broadcast run (cron), unless they are Do Not Contact. The equivalent report for campaign flows is described in [campaign-flows.md](campaign-flows.md).

## Companies

`Tools/CompanyTools.php`: `mautic_search_companies`, `mautic_get_company` (with up to 100 contacts), `mautic_upsert_companies` (up to 100 per call, matched on Mautic's unique company fields, company name by default, optional contact links, `dryRun`) and `mautic_change_company_contacts` (add or remove, up to 100 contacts). Adding a contact makes the company their primary company and sets their company field. This group can be hidden (see [capability-gating.md](../architecture/capability-gating.md)).

## Insight tools

- `mautic_get_segment_summary` (view access to the segment): member count excluding manually removed members, static or dynamic, filters (field and operator only), last rebuild, and `sampleSize` sample contacts, clamped to 0 to 10 server-side.
- `mautic_find_duplicate_contacts`: needs permission to view other users' contacts; the field must be `email`, `phone` or `mobile`; `limit` is clamped to 1 to 100. To stay cheap on large databases it scans only the newest 500,000 contacts and caps the statement at 10 seconds (the time cap is a MySQL optimizer hint that MariaDB ignores).
- `mautic_get_campaign_health` is described in [campaign-flows.md](campaign-flows.md).

## Email topics

`EmailTopics` maps a topic given by name (case-insensitive) or ID to a published Mautic category of bundle `global` or `email`; an unknown topic is an error listing the valid ones, and an empty string clears it. Emails without a topic carry a `topic_warning`. `mautic_list_email_topics` lists them. Creation and update of emails (owned by [campaign-flows.md](campaign-flows.md)) apply the topic through it.

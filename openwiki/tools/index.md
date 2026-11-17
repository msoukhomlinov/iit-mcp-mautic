# Files

- [Campaign drafts, flows and email drafts](campaign-flows.md) - How the write tools create email and campaign drafts and edit campaign flows through CampaignFlowBuilder, including the running-campaign guard, live_effects, dry runs and the campaign health check.
- [Contacts, segments, companies and read tools](contacts-segments-companies.md) - Behaviour of the read tools, contact and company upserts, segment creation and membership changes, Do Not Contact handling, dry runs with live_effects, and insight tools such as duplicates and segment summary.
- [Destructive operations and brand layouts](destructive-operations.md) - How mautic_delete_record and mautic_merge_contacts preview and confirm irreversible changes, and how the brand email layout tools render theme block libraries (mcp/blocks.json) into MJML and HTML emails.
- [Email sending tier](email-sending.md) - How mautic_preview_email_send and mautic_send_email plan, preview and confirm real sends (targets_hash, recipient count, caps, Do Not Contact, segment sends, hourly limit read from the audit log), plus the self-only test email tool.
- [Points, stages and projects](scoring-stages-projects.md) - Behaviour and safeguards of the point group/action/trigger tools, contact point adjustment, stage tools and project tools, including draft-only creation, dry runs and live-effect checks.

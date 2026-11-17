---
type: guide
title: Quickstart
description: Orientation for the Mautic MCP plugin and a task-routing map from common questions to the wiki page that owns the answer.
tags: [quickstart, overview, navigation]
verified:
  - by: openwiki/0.9.0
    at: 2026-11-17T00:16:34.909Z
sources:
  - id: openwiki-source-30578edcd2199e7ded316a82
    resource: repo://IntellectItMcpBundle.php
generated: { by: "openwiki/0.9.0", at: "2026-11-17T00:16:34.909Z" }
---

# Quickstart

This repository is a Mautic plugin bundle (`IntellectItMcpBundle`, a `PluginBundleBase` subclass) that exposes Mautic as an MCP server over HTTP. Tools are grouped into tiers (read, write, contact write, send, destructive, live campaign edits), each gated by operator switches, OAuth scopes and the Mautic role of the token's user.

## Where to look

| If you need to... | Read |
|---|---|
| Follow a request from route to tool, understand OAuth validation, sessions, user context, errors and audit | [architecture/server-and-request-flow.md](architecture/server-and-request-flow.md) |
| Know which operator parameters, defaults, scopes and hidden groups control what is registered | [architecture/capability-gating.md](architecture/capability-gating.md) |
| Install, generate keys, set redirect hosts or check the setup status | [operations/setup.md](operations/setup.md) |
| Run the tests, understand CI or release versioning | [operations/testing-and-release.md](operations/testing-and-release.md) |
| Understand read tools, contact/company upserts, segments and Do Not Contact handling | [tools/contacts-segments-companies.md](tools/contacts-segments-companies.md) |
| Work on email and campaign drafts or campaign flow editing and live-effect guards | [tools/campaign-flows.md](tools/campaign-flows.md) |
| Change how real emails are previewed, confirmed, capped and audited, or the test email | [tools/email-sending.md](tools/email-sending.md) |
| Change delete or merge confirmation, or the brand email layouts from theme block libraries | [tools/destructive-operations.md](tools/destructive-operations.md) |
| Change point groups, actions, triggers, contact points, stages or projects | [tools/scoring-stages-projects.md](tools/scoring-stages-projects.md) |

## Reading order for a new contributor

1. [architecture/server-and-request-flow.md](architecture/server-and-request-flow.md) for the runtime shape.
2. [architecture/capability-gating.md](architecture/capability-gating.md) for the safety model that every tool page builds on.
3. The tool page for the area you are changing.
4. [operations/testing-and-release.md](operations/testing-and-release.md) before opening a change.

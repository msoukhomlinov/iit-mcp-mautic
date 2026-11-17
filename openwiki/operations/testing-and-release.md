---
type: operations
title: Testing, CI and release
description: Package check script, functional shell test suites run against a real Mautic 7, the CI workflow triggers and the release-please versioning locations.
tags: [testing, ci, release, versioning]
verified:
  - by: openwiki/0.9.0
    at: 2026-11-17T00:16:34.909Z
sources:
  - id: openwiki-source-164e2da859b5277df81c7d94
    resource: repo://.github/workflows/ci.yml
  - id: openwiki-source-2b6d5f93fd221634433f5489
    resource: repo://bin/check.sh
  - id: openwiki-source-482fa4ca84f42b04ba025fc1
    resource: repo://release-please-config.json
  - id: openwiki-source-b5cc96a24e4e1972c0672ff6
    resource: repo://tests/functional/run.sh
generated: { by: "openwiki/0.9.0", at: "2026-11-17T00:16:34.909Z" }
---

# Testing, CI and release

There are no PHP unit tests in the repository. Verification is a static package check plus end-to-end shell tests that talk to the plugin over HTTP.

## `bin/check.sh`

A static check that needs `php` and `composer`. It:

- runs `php -l` on every PHP file (excluding `vendor`);
- requires `VERSION` to be `x.y.z`;
- fails if `composer.json` contains a `version` key (Packagist takes versions from git tags);
- requires a `CHANGELOG.md` heading for the current version;
- requires the `version` in `Config/config.php` to equal `VERSION`, and every `const VERSION = '...'` in PHP files to contain it;
- runs `composer validate --strict --no-check-lock --no-check-publish`.

## Functional tests

`tests/functional/run.sh` installs a real Mautic 7 (`install_mautic`), configures OAuth, then sets the parameters `intellectit_mcp_allow_write` and `intellectit_mcp_live_campaign_edits` to true (the other tests need writes; gating is covered separately), starts a server and logs in. It first checks RFC 9728 metadata, that an unauthenticated call returns 401 with a `WWW-Authenticate` header containing `resource_metadata`, and that a forged token is rejected. It then initializes a session, asserts `tools/list` returns a single page (no `nextCursor`), and sources the topic scripts in order; later scripts reuse IDs created by earlier ones.

Order: `core.sh`, `scenarios.sh`, `contacts.sh`, `crm.sh`, `points.sh`, `stages.sh`, `topics.sh`, `insight.sh`, `send.sh`, `review.sh`, `destructive.sh`, `surface.sh`, `verify.sh`, `gating.sh`. Tests use tokens with different scope sets (read only, contacts-write, campaigns:live) to exercise [scope enforcement](../architecture/capability-gating.md).

Two scripts are separate entry points: `setup.sh` covers the [setup command and status tool](setup.md) on a fresh install, and `inspector.sh` is manual only (needs `npx`). Shared helpers live in `tests/functional/lib.sh`.

## CI (`.github/workflows/ci.yml`)

CI is a post-release check, not a merge gate. It runs on:

- push of `v*` tags;
- manual dispatch;
- `workflow_call` (the release workflow calls it after creating a release);
- pull requests touching `.github/**`.

Release PRs get no checks, because release-please opens them with the default token, whose events do not trigger workflows. Jobs:

- `check`: runs `bin/check.sh` in a PHP 8.4 container (10 minute timeout).
- `functional`: a matrix of PHP 8.4 and 8.5 against `run.sh` and `setup.sh`, using MariaDB 11.4 and a Mautic created from `mautic/recommended-project:^7.2` with the checkout installed via a path repository (30 minute timeout).

## Releases and version locations

release-please (`release-type: simple`) tags `v<version>` without a component name and keeps the version in `VERSION`, plus generic `x-release-please-version` markers in `Config/config.php` and `Service/McpServerFactory.php` (the `VERSION` constant, see [../architecture/server-and-request-flow.md](../architecture/server-and-request-flow.md)). `bin/check.sh` fails if these drift. Changelog sections: features, bug fixes, performance and documentation are shown; chore, ci, test and refactor are hidden. The config sets `bump-minor-pre-major` to true (breaking changes bump the minor version while pre-1.0) and `bump-patch-for-minor-pre-major` to false.

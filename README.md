# intellectit/mautic-mcp-bundle


[Model Context Protocol](https://modelcontextprotocol.io) server for
Mautic 7 at `/mcp` (Streamable HTTP). It is built on the official
[MCP PHP SDK](https://github.com/modelcontextprotocol/php-sdk).

## Installation

Requires **Mautic 7.2+** (Composer-based installation) and **PHP 8.4+**:

```bash
composer config repositories.intellectit-mcp vcs https://github.com/msoukhomlinov/iit-mcp-mautic
composer require intellectit/mautic-mcp-bundle:dev-main
php bin/console mautic:plugins:reload
```

Requires `kopexa/mautic-oauth-bundle` (installed automatically by Composer) with a signing key configured, see its README.

Already included in the hardened Mautic image
[`ghcr.io/kopexa-grc/mautic`](https://github.com/kopexa-grc/mautic-stack).


Claude, ChatGPT and other MCP clients can:

- read contacts, segments, emails, campaigns and email statistics
- create **unpublished** emails and campaigns
- create emails in the brand theme from panels and elements (themes with a
  block library, e.g. Kopexa), editable in Mautic's builder, and send a
  test to oneself
- create and update contacts and companies (matched by their unique
  fields, up to 100 per call), add custom fields, build static and dynamic
  segments (filters on fields, tags and behaviour), e.g. to sync with a CRM.
  Adding contacts to a segment can start running campaigns and segment
  emails for them; results and dry runs report it
- read a contact's activity (opens, clicks, visits, forms, points)
- summarise a segment (size, static or dynamic, filters, last rebuild, sample contacts), find duplicate contacts by email, phone or mobile, and check a campaign's health (contacts, scheduled and overdue steps, recent failures with reasons, warnings)
- give emails their topic (Mautic categories): recipients switch topics off
  in their email preferences, Mautic then skips those emails for them;
  emails without a topic come with a warning
- set up lead scoring: point groups, point actions (points for visits,
  downloads, forms, emails) and point triggers (tag, segment, stage,
  campaign or email when a contact reaches a score), all **unpublished**,
  and give contacts points. Mautic fires published triggers when a contact
  crosses their score; dry runs list the ones that would fire
- manage stages (lifecycle phases, created **unpublished**) and move
  contacts between published stages; group emails, campaigns, segments,
  forms, stages, ... into projects (Mautic 7)
- build and edit complete campaign flows (actions, decisions, conditions,
  waits). This also works on **running** campaigns, where changes are live:
  Mautic's scheduler executes them and may send emails. Publishing and
  email sending is a separate opt-in tier (see Sending email).

Every call runs with the Mautic permissions of the user who approved the
client. OAuth 2.1 comes from
[kopexa/mautic-oauth-bundle](https://github.com/kopexa-grc/mautic-oauth-bundle).

Design and safety model: [SCOPING.md](SCOPING.md) and [docs/architecture.md](docs/architecture.md). The original Kopexa setup notes: [docs/mcp.md](https://github.com/kopexa-grc/mautic-stack/blob/main/docs/mcp.md).


> **Tool names:** every tool is prefixed `mautic_` (for example `mautic_get_contact`), unlike the upstream Kopexa bundle. Clients and prompts written for the upstream names must be updated.

## Capability gating

Write tools (everything that creates or changes data) are **off by default**: they are not listed in `tools/list` and cannot be called, whatever scopes a token holds. An operator enables them in `config/local.php`:

```php
$parameters['intellectit_mcp_allow_write'] = true;
```

Token scopes (`mcp:write`, `mcp:contacts:write`, `mcp:campaigns:live`) still apply on top of this. The send tools are a separate tier (see below). Delete and raw-API tiers are not built yet.

## Sending email (opt-in)

`mautic_preview_email_send` and `mautic_send_email` can email real recipients. They are **off by default** and are not even listed until an operator turns them on in `config/local.php`:

```php
$parameters['intellectit_mcp_allow_send'] = true;           // register the send tools
$parameters['intellectit_mcp_allow_destructive'] = true;    // register delete and merge (also needs scope mcp:destructive)
$parameters['intellectit_mcp_caller_mode'] = 'unattended';  // default 'attended'; unattended: instructions never ask a person, send_email does not request a human prompt
$parameters['intellectit_mcp_allow_segment_send'] = false;  // also allow whole segments
$parameters['intellectit_mcp_send_max_recipients'] = 100;   // cap per call
$parameters['intellectit_mcp_send_ignore_dnc'] = false;     // keep false, see below
```

- The token also needs the opt-in scope `mcp:send`.
- `mautic_send_email` without `confirm=true` sends nothing and returns the preview (recipients, exclusions, warnings, blockers). With `confirm=true` it also needs `expectedRecipientCount`, the `recipient_count` the preview showed; a send whose recipients now differ is refused.
- Email can also go out indirectly, outside this tier: steps added to running campaigns (needs `intellectit_mcp_live_campaign_edits`, off by default, and scope `mcp:campaigns:live`), contacts entering running campaigns through segments, and published point triggers.
- Unpublished emails are never sent: a person publishes them in Mautic.
- Target either contacts (`contactIds`) or segments (`segmentIds`, only the email's own segments, segment emails only).
- Contacts on Do Not Contact and contacts without a valid address are skipped and named in the preview. `intellectit_mcp_send_ignore_dnc` overrides this for direct contact sends only (Mautic's segment sending always skips them). Do not enable it unless you are certain of your legal basis: emailing unsubscribed people can breach the Spam Act, GDPR and similar rules.

## Development

- `bin/check.sh` checks the package (PHP lint, `composer validate`, version
  consistency). CI runs it only for release tags and on demand (Actions, ci, Run workflow).
- `tests/functional/run.sh` installs the plugin into a real Mautic 7 and
  tests it over HTTP; CI runs it on PHP 8.4 and 8.5. The tool tests are
  split by topic (`core.sh`: drafts, scopes, campaign flows run by
  Mautic's cron, running campaigns and their locks, roles;
  `scenarios.sh`: two realistic campaigns incl. a tracked email open;
  `contacts.sh`, `crm.sh`, `points.sh`, `stages.sh`, `topics.sh`, then
  `insight.sh`, `send.sh`, `surface.sh` (served tools, instructions) and `gating.sh`).
- [kopexa-grc/mautic-stack](https://github.com/kopexa-grc/mautic-stack)
  only tests the integration in its image: discovery behind the public
  URL, MCP sessions across replicas, the operator switch via environment,
  brand layouts with its themes.
- **Commits** follow [Conventional Commits](https://www.conventionalcommits.org)
  (`feat:`, `fix:`, `docs:`, `chore:` …); they drive versions and the changelog.
- **Releases** are automated with
  [release-please](https://github.com/googleapis/release-please): run it by hand (Actions, release-please) and it opens a
  release PR that bumps `VERSION`, the plugin version and `McpServerFactory::VERSION` and
  `CHANGELOG.md`. Merging it tags `v<version>` and creates the GitHub
  release.
- **Dependencies** are kept current by Renovate, once a month
  (security fixes immediately). Composer constraint updates
  are committed as `fix(deps):` and so lead to a patch release; CI-only
  updates (`ci:`) do not.

Apache-2.0 · IntellectIT, forked from [kopexa-grc/mautic-mcp-bundle](https://github.com/kopexa-grc/mautic-mcp-bundle) by [Kopexa](https://kopexa.com)

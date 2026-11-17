---
type: operations
title: Setup command and status check
description: The mautic:mcp:setup console command (key generation, redirect hosts, --check) and the SetupStatus report shared with the mautic_setup_status tool.
tags: [setup, oauth, operations, cli]
verified:
  - by: openwiki/0.9.0
    at: 2026-11-17T00:16:34.909Z
sources:
  - id: openwiki-source-333dc11753c2e2523ef6a7fd
    resource: repo://Command/SetupCommand.php
  - id: openwiki-source-5494b20fde06faa563875779
    resource: repo://Service/SetupStatus.php
  - id: openwiki-source-d4d4c93f00a08dddbb372734
    resource: repo://Tools/SetupTools.php
generated: { by: "openwiki/0.9.0", at: "2026-11-17T00:16:34.909Z" }
---

# Setup command and status check

Two entry points share one report: the console command `mautic:mcp:setup` and the MCP tool `mautic_setup_status`. Both call `SetupStatus::report()`.

## `mautic:mcp:setup`

Options:

| Option | Effect |
|---|---|
| `--force` | Replace existing keys (signs every client out) |
| `--redirect-host=<host>` | Add a host clients may redirect to; repeatable |
| `--check` | Report only, change nothing |

Without `--check` the command:

1. Generates a 3072-bit RSA private key when `kopexa_oauth_private_key` is empty (or with `--force`); fails with an error if OpenSSL cannot create one.
2. Generates a 64-hex-character encryption key (32 random bytes) for `kopexa_oauth_encryption_key` when empty (or with `--force`).
3. Merges each `--redirect-host` (lower-cased, trimmed, de-duplicated) into `kopexa_oauth_redirect_hosts`, preserving existing hosts.
4. If anything changed, writes it to Mautic's local configuration file and **returns immediately** after telling the operator to run `cache:clear` and then `--check`, because the running process still holds the old values. If nothing changed it says keys are already set and falls through to the report.

The parameter names are those of the kopexa OAuth bundle, which owns the authorization server; this bundle only provisions them.

### How `config/local.php` is written

The existing file is included, the changed parameters are merged over it, and the result is written as `$parameters = var_export(...)`. The write is atomic where possible: a temp file next to the original, mode `0640` (it holds the keys), same owner and group as the original, then renamed over it. If the config directory is not writable the command writes in place with an exclusive lock and mode `0640`. OPcache for the file is invalidated afterwards. Failure throws a `RuntimeException`.

### Output and exit code

Each check prints as `STATUS check detail -> fix`. The exit code is success unless any check has status `fail`; `warn` and `info` do not fail the command.

## Checks reported (`SetupStatus`)

| Check | fail | warn | ok / info |
|---|---|---|---|
| `site_url` | empty | not https and host not `localhost`/`127.0.0.1` | the URL |
| `oauth signing key` | empty, or not a valid RSA key | RSA key under 2048 bits | bits reported |
| `oauth encryption key` | empty | shorter than 32 characters | set |
| `oauth redirect hosts` | none configured | - | list of hosts |
| `operator tiers` | - | - | info: on/off for write, live campaign edits, send, segment send, destructive; caller mode; hidden groups |
| `cron` | - | newest rebuild of a published dynamic segment is over a day old | info if no dynamic segment was ever rebuilt or the query fails |

Secrets are never returned, only whether they are usable. The report also carries `endpoint` (`<site_url>/mcp`) and an overall `ok` flag that is false iff any check failed. The meaning of the tier switches is owned by [../architecture/capability-gating.md](../architecture/capability-gating.md).

## `mautic_setup_status` tool

`SetupTools::getSetupStatus` is always registered (it is not in a gated list, see [../architecture/capability-gating.md](../architecture/capability-gating.md)) and needs only the read scope, but throws an error ("Permission denied: only Mautic administrators may check the server setup.") unless the token's Mautic user is an administrator (`isAdmin()`). That message matches none of the `McpError` permission patterns, so its code is inferred as `INVALID_INPUT` and the refusal is not audited. It returns the same report and changes nothing. Its tests live in `tests/functional/setup.sh` (see [testing-and-release.md](testing-and-release.md)).

The request path that these keys feed is described in [../architecture/server-and-request-flow.md](../architecture/server-and-request-flow.md).

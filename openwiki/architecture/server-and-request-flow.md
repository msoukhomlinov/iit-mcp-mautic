---
type: architecture
title: Server and request flow
description: How an MCP request travels through routes, McpController, McpServerFactory (OAuth validation, sessions, tool registry), user context and error/audit handling.
tags: [architecture, http, oauth, sessions, errors]
verified:
  - by: openwiki/0.9.0
    at: 2026-11-17T00:16:34.909Z
sources:
  - id: openwiki-source-b0ee022fd73f8d5b24347cb4
    resource: repo://Config/config.php
  - id: openwiki-source-3b59fd60021da0bcda671306
    resource: repo://Config/services.php
  - id: openwiki-source-c440d03c72892ab931dc4ae2
    resource: repo://Controller/McpController.php
  - id: openwiki-source-94e83ec8b97d1078b3010c2c
    resource: repo://Service/McpError.php
  - id: openwiki-source-ebf7ea8a3de0c62b2477868b
    resource: repo://Service/McpServerFactory.php
  - id: openwiki-source-3a4b610f8deef9c04161a844
    resource: repo://Service/McpUserContext.php
generated: { by: "openwiki/0.9.0", at: "2026-11-17T00:16:34.909Z" }
---

# Server and request flow

The bundle is a Mautic plugin (`MauticPlugin\IntellectItMcpBundle`) that exposes an MCP server built on the official MCP PHP SDK. Services are autowired, autoconfigured and public; everything under the bundle root except Mautic's default excludes (`MauticCoreExtension::DEFAULT_EXCLUDES`), `Tests` and `DependencyInjection` is loaded as services, and controllers are tagged as service-argument controllers (`Config/services.php`).

## Routes

All routes are public (Mautic does not authenticate them; the MCP transport does) and are declared in `Config/config.php`:

| Route | Path | Methods |
|---|---|---|
| `intellectit_mcp_endpoint` | `/mcp` | GET, POST, DELETE, OPTIONS |
| `intellectit_mcp_resource_metadata` | `/.well-known/oauth-protected-resource` | GET, OPTIONS |
| `intellectit_mcp_resource_metadata_path` | `/.well-known/oauth-protected-resource/mcp` | GET, OPTIONS |

The two metadata routes both serve RFC 9728 protected-resource metadata, so clients find it at the plain and the path-inserted location. The metadata response is JSON with `Cache-Control: public, max-age=300` and `Access-Control-Allow-Origin: *`.

## Request handling

`McpController::mcpAction`:

1. Returns HTTP 503 with a JSON error if the OAuth authorization server is not configured (`McpServerFactory::isConfigured`, delegating to the kopexa OAuth settings).
2. Converts the Symfony request to a PSR-7 request, builds a fresh `Server` and `StreamableHttpTransport` **per request**, and runs the transport.
3. Any `Throwable` is logged and returned as HTTP 500 with JSON-RPC error `-32603` "Internal error".
4. On a 403 `insufficient_scope` challenge, `widenScopeChallenge` rewrites the `WWW-Authenticate` scope to the regular scopes plus the scopes the call needs, because clients that re-authorize with only the needed scope would otherwise lose the scopes they already had. See [capability-gating.md](capability-gating.md) for scope names.
5. Event-stream responses are streamed in 8192-byte chunks with `X-Accel-Buffering: no`; everything else is buffered.

Because the server object is rebuilt on every request, nothing is held in PHP memory across calls; cross-request state lives in the session store (below) and the audit log.

## Transport middleware and token validation

`McpServerFactory::transport` stacks, in order: CORS, DNS-rebinding protection, protected-resource-metadata handling, and authorization.

- **DNS rebinding:** the allowed host is the host of the OAuth issuer; any `Origin` is allowed, on the stated reasoning that browsers never send bearer tokens on their own.
- **Token validation** is offline: a `JwtTokenValidator` checks RS256 signatures against the authorization server's public key (keyed by its key ID), the issuer, and the audience (the MCP resource URL), requires token type `at+jwt`, and allows 30 seconds of clock leeway.
- Per-tool scope checks come from the `ScopePolicy`; the mapping is owned by [capability-gating.md](capability-gating.md).

## Server construction

`McpServerFactory::server()`:

- Server info is `Mautic` with the version constant (`McpServerFactory::VERSION`, replaced at release; see [operations/testing-and-release.md](../operations/testing-and-release.md)).
- Pagination limit is 200 so all tools come in one page, because not every client follows `nextCursor`.
- Server instructions are assembled from sections that depend on caller mode, hidden groups and whether brand layouts exist (details in [capability-gating.md](capability-gating.md)).
- Tools are registered from a single table; which rows are registered is decided by the gating rules.

### Sessions

MCP sessions are stored in the Mautic database through a Symfony `DoctrineDbalAdapter` wrapped as PSR-16, table `<MAUTIC_TABLE_PREFIX>intellectit_mcp_sessions`, with session key prefix `session-` and a TTL of 3600 seconds. This makes sessions shared across web replicas. Expired rows are only deleted by `prune()`, which is run on roughly 1% of requests (`random_int(1,100) === 1`) and whose failures are swallowed.

## User context and permissions

`McpUserContext` maps the validated token to a Mautic user so tools behave like the Mautic UI:

- The token's `sub` claim must be a numeric ID of an existing, published user, otherwise the call fails with "The Mautic user of this access token does not exist or is disabled." The user is cached for the request; a Symfony security token is set so Mautic's permission checks apply.
- `require()` checks one or more permission strings (match any) against the user's role.
- `requireEntityAccess()` checks own/other permissions for an entity (`<base>:<action>own` and `<base>:<action>other`, owner from `getPermissionUser` or `getCreatedBy`); bundles with only standard permissions (stages, projects) fall back to the plain permission.
- `canViewOther()` tells list tools whether to restrict results to the user's own items.

## Errors and audit

`McpError::from` produces a `ToolCallException` whose message is `[CODE] message (ref xxxxxxxx)`. The ref is an 8-hex-character correlation ID, one per PHP request (one per MCP call), and also stored in audit rows of that call.

Codes: `PERMISSION_DENIED`, `NOT_FOUND`, `BLOCKED_BY_POLICY`, `CONFIRM_REQUIRED`, `UPSTREAM_ERROR`, `INVALID_INPUT`. If the caller does not pass a code, it is inferred from the message by regex rules checked in this order: confirm-required, permission, upstream, not-found, blocked-by-policy; anything unmatched is `INVALID_INPUT`. Because the classification is text-based, message wording in tools matters for the code a client sees.

Refusals with the codes `PERMISSION_DENIED`, `BLOCKED_BY_POLICY` and `CONFIRM_REQUIRED` are written to Mautic's audit log (bundle `intellectit_mcp`, object `refusal`, action `refused`); a failing audit write never hides the refusal. Tools write their own audit rows through `McpUserContext::audit`, which adds `via: MCP`, the OAuth `client_id` and the `ref` to the details. The hourly send cap reads these rows (see [tools/email-sending.md](../tools/email-sending.md)).

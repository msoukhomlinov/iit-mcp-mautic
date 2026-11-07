#!/usr/bin/env bash
# Functional test: MCP server in a real Mautic 7 (with kopexa/mautic-oauth-bundle).
#   tests/functional/run.sh    (see tests/functional/lib.sh for the environment)
#
# The tool tests are split by topic and sourced in order (later files use
# IDs from earlier ones): core.sh, scenarios.sh, contacts.sh, crm.sh,
# points.sh, stages.sh, topics.sh.
# shellcheck source=tests/functional/lib.sh
source "$(dirname "$0")/lib.sh"
here="$(dirname "$0")"

install_mautic
configure_oauth
# Writes are off by default (gating.sh covers that); the other tests need them.
set_params '{"intellectit_mcp_allow_write": true}'
start_server
login >/dev/null

echo "MCP server"
prm="$(curl -s "$BASE/.well-known/oauth-protected-resource/mcp")"
jq -e --arg b "$BASE" '.resource == ($b + "/mcp") and .authorization_servers == [$b]' <<<"$prm" >/dev/null \
  && pass "RFC 9728 protected resource metadata" || { echo "$prm"; fail "PRM"; }
unauth="$(curl -s -o /dev/null -D - -X POST "$BASE/mcp" -H 'Content-Type: application/json' -d '{}')"
grep -q '^HTTP/1.1 401' <<<"$unauth" && grep -qi '^www-authenticate: Bearer.*resource_metadata=' <<<"$unauth" \
  && pass "no token: 401 with WWW-Authenticate resource_metadata" || { echo "$unauth"; fail "unauthenticated request"; }
[[ "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$BASE/mcp" -H 'Authorization: Bearer x.y.z' -H 'Content-Type: application/json' -d '{}')" == 401 ]] \
  && pass "forged token rejected" || fail "forged token accepted"

access="$(access_token 'mcp:read mcp:write')"
read_token="$(access_token 'mcp:read')"
contacts_token="$(access_token 'mcp:read mcp:contacts:write')"
live_token="$(access_token 'mcp:read mcp:write mcp:campaigns:live' mcp:campaigns:live)"
mcp_init "$access"
jq -e '.result.serverInfo.name == "Mautic"' "$WORK/init" >/dev/null && [[ -n "$MCP_SESSION" ]] \
  && pass "initialize (session stored in the database)" || { cat "$WORK/init"; fail "initialize"; }
tools="$(mcp_post "$access" '{"jsonrpc":"2.0","id":2,"method":"tools/list"}')"
jq -e '(.result.tools | length) >= 60 and (.result | has("nextCursor") | not)' <<<"$tools" >/dev/null \
  && pass "tools/list: $(jq '.result.tools | length' <<<"$tools") tools on one page" || { echo "$tools" | head -c 600; fail "tools/list"; }

# shellcheck source=tests/functional/core.sh
source "$here/core.sh"
# shellcheck source=tests/functional/scenarios.sh
source "$here/scenarios.sh"
# shellcheck source=tests/functional/contacts.sh
source "$here/contacts.sh"
# shellcheck source=tests/functional/crm.sh
source "$here/crm.sh"
# shellcheck source=tests/functional/points.sh
source "$here/points.sh"
# shellcheck source=tests/functional/stages.sh
source "$here/stages.sh"
# shellcheck source=tests/functional/topics.sh
source "$here/topics.sh"
# shellcheck source=tests/functional/insight.sh
source "$here/insight.sh"
# shellcheck source=tests/functional/send.sh
source "$here/send.sh"
# shellcheck source=tests/functional/gating.sh
source "$here/gating.sh"
echo "all MCP checks passed"

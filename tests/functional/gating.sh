# shellcheck shell=bash
# Operator ceiling: intellectit_mcp_allow_write=false hides and refuses every write tool.
# Sourced last by run.sh (uses $access, $read_token and the helpers from lib.sh).
set_params '{"intellectit_mcp_allow_write": false}'
ro_tools="$(mcp_post "$access" '{"jsonrpc":"2.0","id":9,"method":"tools/list"}')"
n_ro="$(jq '.result.tools | length' <<<"$ro_tools")"
jq -e '[.result.tools[] | select(.annotations.readOnlyHint != true)] | length == 0' <<<"$ro_tools" >/dev/null && (( n_ro > 0 && n_ro < 61 )) \
  && pass "allow_write=false: tools/list shows only the $n_ro read-only tools" || { echo "$ro_tools" | head -c 600; fail "write tools still listed"; }
# create_project needs only mcp:write, which $access holds: only the operator ceiling can refuse it.
refused="$(call "$access" create_project '{"name":"gating-test"}')"
jq -e '.error.code == -32602 and (.error.message | test("not found"))' <<<"$refused" >/dev/null \
  && pass "allow_write=false: a write tool is refused even with a token holding mcp:write" || { echo "$refused" | head -c 600; fail "write tool callable"; }
[[ "$(result "$(call "$read_token" list_segments '{}')")" != "" ]] && pass "allow_write=false: read tools still work" || fail "read tool broken"
set_params '{"intellectit_mcp_allow_write": true}'
[[ "$(mcp_post "$access" '{"jsonrpc":"2.0","id":10,"method":"tools/list"}' | jq '.result.tools | length')" == 61 ]] \
  && pass "allow_write=true: all 61 tools listed again" || fail "tools not restored"
allowed="$(result "$(call "$access" create_project '{"name":"gating-test"}')")"
jq -e '.id != null' <<<"$allowed" >/dev/null && pass "allow_write=true: the same call now succeeds (control)" || { echo "$allowed" | head -c 600; fail "control call"; }

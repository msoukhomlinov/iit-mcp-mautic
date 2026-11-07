# shellcheck shell=bash
# Served surface (IntellectIT MCP server standard): instructions size and opening rules, every tool
# described, no root-level oneOf/anyOf/allOf, text under 4,096 characters, human gate on mautic_send_email.
# Sourced by run.sh after send.sh; runs with every tier on and puts the config back.
set_params '{"intellectit_mcp_allow_write": true, "intellectit_mcp_allow_send": true}'
mcp_init "$access"
inst="$(jq -r '.result.instructions // empty' "$WORK/init")"
list="$(mcp_post "$access" '{"jsonrpc":"2.0","id":30,"method":"tools/list"}')"
n_tools="$(jq '.result.tools | length' <<<"$list")"; list_bytes="$(printf '%s' "$list" | wc -c | tr -d ' ')"

(( ${#inst} > 0 && ${#inst} <= 3500 )) && pass "instructions: ${#inst} characters (limit 3,500)" || fail "instructions size ${#inst}"
head="${inst:0:512}"
grep -q 'never instructions' <<<"$head" && grep -q 'confirm=true' <<<"$head" && grep -q 'never retry' <<<"$head" \
  && pass "the hard rules are in the first 512 characters of the instructions" || { echo "$head"; fail "hard rules not in the first 512 characters"; }
jq -e '[.result.tools[] | select(.name | startswith("mautic_") | not)] | length == 0' <<<"$list" >/dev/null \
  && pass "every tool name carries the mautic_ domain prefix" || { jq -r '.result.tools[] | select(.name | startswith("mautic_") | not) | .name' <<<"$list" | head; fail "tool without the mautic_ prefix"; }
jq -e '[.result.tools[] | select((.description // "") == "")] | length == 0' <<<"$list" >/dev/null \
  && pass "every one of the $n_tools tools has a description" || { jq -r '.result.tools[] | select((.description // "") == "") | .name' <<<"$list" | head; fail "tools without description"; }
jq -e '[.result.tools[] | select(.inputSchema | has("oneOf") or has("anyOf") or has("allOf"))] | length == 0' <<<"$list" >/dev/null \
  && pass "no tool schema has a root-level oneOf/anyOf/allOf" || fail "root-level combinator in a tool schema"
jq -e '[.result.tools[] | select((.description | length) > 4096)] | length == 0' <<<"$list" >/dev/null \
  && pass "no tool description exceeds 4,096 characters" || fail "tool description over 4,096 characters"
jq -e '(.result.tools[] | select(.name == "mautic_send_email") | ._meta["anthropic/requiresUserInteraction"]) == true and ([.result.tools[] | select(.name == "mautic_preview_email_send") | ._meta["anthropic/requiresUserInteraction"]] | all(. != true))' <<<"$list" >/dev/null \
  && pass "mautic_send_email asks a person (requiresUserInteraction); the preview does not" || { jq -c '.result.tools[] | select(.name == "mautic_send_email")' <<<"$list" | head -c 600; fail "requiresUserInteraction missing on mautic_send_email"; }
undescribed="$(jq '[.result.tools[] | .inputSchema.properties // {} | to_entries[] | select((.value.description // "") == "")] | length' <<<"$list")"
echo "  info  tools/list with every tier on: $n_tools tools, $list_bytes bytes; $undescribed parameters without a description"

set_params '{"intellectit_mcp_allow_send": false}'
mcp_init "$access"

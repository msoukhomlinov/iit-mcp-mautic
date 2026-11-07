# shellcheck shell=bash
# Served surface (IntellectIT MCP server standard): instructions size and opening rules, every tool
# described, no root-level oneOf/anyOf/allOf, text under 4,096 characters, human gate on mautic_send_email.
# Sourced by run.sh after send.sh; runs with every tier on and puts the config back.
set_params '{"intellectit_mcp_allow_write": true, "intellectit_mcp_allow_send": true, "intellectit_mcp_allow_destructive": true}'
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
undescribed="$(jq -r '[.result.tools[] | .name as $n | (.inputSchema.properties // {} | to_entries[] | select((.value.description // "") == "") | "\($n).\(.key)")] | join(" ")' <<<"$list")"
[[ -z "$undescribed" ]] && pass "every parameter of every tool has a description" || { echo "  undescribed: $undescribed"; fail "parameters without a description"; }
# Refusals carry a code and a correlation ID; the same ID lands in the audit log.
err="$(call "$access" mautic_get_contact '{"id":999999}')"
errtext="$(jq -r '.result.content[0].text // empty' <<<"$err")"
[[ "$(jq -r '.result.isError' <<<"$err")" == true && "$errtext" =~ ^\[NOT_FOUND\]\ .*\(ref\ ([0-9a-f]{8})\)$ ]] \
  && pass "a refusal reads [NOT_FOUND] ... (ref <id>)" || { echo "$errtext"; fail "refusal has no code and ref"; }
echo "  info  sample: $errtext"
echo "  info  tools/list with every tier on: $n_tools tools, $list_bytes bytes"
for prof in 'read-only|false|false' 'write|true|false'; do
  IFS='|' read -r pname pw ps <<<"$prof"
  set_params "{\"intellectit_mcp_allow_write\": $pw, \"intellectit_mcp_allow_send\": $ps, \"intellectit_mcp_allow_destructive\": false}"
  mcp_init "$access"
  pl="$(mcp_post "$access" '{"jsonrpc":"2.0","id":31,"method":"tools/list"}')"
  echo "  info  profile $pname: $(jq '.result.tools | length' <<<"$pl") tools, $(printf '%s' "$pl" | wc -c | tr -d ' ') bytes tools/list"
done

# Unattended mode: no questions in the instructions, hard rules still in the first 512 characters, no human prompt on send.
set_params '{"intellectit_mcp_allow_send": true, "intellectit_mcp_caller_mode": "unattended"}'
mcp_init "$access"
uinst="$(jq -r '.result.instructions // empty' "$WORK/init")"
ulist="$(mcp_post "$access" '{"jsonrpc":"2.0","id":32,"method":"tools/list"}')"
uhead="${uinst:0:512}"
(( ${#uinst} <= 3500 )) && grep -q 'never ask a person' <<<"$uhead" && grep -q 'never instructions' <<<"$uhead" && grep -q 'confirm=true' <<<"$uhead" && grep -q 'never retry' <<<"$uhead" && ! grep -qi 'the user' <<<"$uinst" \
  && pass "unattended: ${#uinst} characters, hard rules first, never asks a person" || { echo "$uhead"; fail "unattended instructions"; }
! jq -r '.result.tools[] | .description, (.inputSchema.properties // {} | to_entries[] | .value.description // "")' <<<"$ulist" | grep -Eqi 'show (the user|it to the user)|to the user|with the user|get their confirmation' \
  && pass "unattended: no tool or parameter description tells the caller to show or ask the user" || { jq -r '.result.tools[] | .name as $n | .description | select(test("the user"; "i")) | $n' <<<"$ulist" | head; fail "tool descriptions still address the user"; }
jq -e '[.result.tools[] | select(.name == "mautic_send_email") | ._meta["anthropic/requiresUserInteraction"]] | all(. != true)' <<<"$ulist" >/dev/null \
  && pass "unattended: mautic_send_email does not ask a person" || fail "unattended send still requires user interaction"
set_params '{"intellectit_mcp_caller_mode": "attended"}'
set_params '{"intellectit_mcp_allow_send": false, "intellectit_mcp_allow_destructive": false}'
mcp_init "$access"

# shellcheck shell=bash
# Irreversible tools: delete a record and merge contacts. Off by default, own opt-in scope, preview then confirm.
dtoken="$(access_token 'mcp:read mcp:destructive' mcp:destructive)"
dnames() { jq -r '[.result.tools[].name] | map(select(. == "mautic_delete_record" or . == "mautic_merge_contacts")) | length'; }

mcp_init "$dtoken"
[[ "$(mcp_post "$dtoken" '{"jsonrpc":"2.0","id":40,"method":"tools/list"}' | dnames)" == 0 ]] \
  && pass "allow_destructive=false (default): delete and merge tools are not listed" || fail "destructive tools listed by default"
set_params '{"intellectit_mcp_allow_destructive": true}'
[[ "$(mcp_post "$dtoken" '{"jsonrpc":"2.0","id":41,"method":"tools/list"}' | dnames)" == 2 ]] \
  && pass "allow_destructive=true: mautic_delete_record and mautic_merge_contacts are listed" || fail "destructive tools not listed"
[[ "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$BASE/mcp" -H "Authorization: Bearer $read_token" -H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' -H 'MCP-Protocol-Version: 2025-06-18' -H "Mcp-Session-Id: $MCP_SESSION" --data-binary '{"jsonrpc":"2.0","id":42,"method":"tools/call","params":{"name":"mautic_delete_record","arguments":{"type":"contact","id":1,"confirm":true}}}')" == 403 ]] \
  && pass "a token without mcp:destructive cannot call mautic_delete_record (403)" || fail "delete callable without mcp:destructive"

mk_c() { api -X POST "$BASE/api/contacts/new" -d "email=$1" -d firstname="$2" -d points="$3" | jq -r '.contact.id'; }
cw="$(mk_c merge-win@example.com Win 10)"; cl="$(mk_c merge-lose@example.com Lose 5)"
mprev="$(result "$(call "$dtoken" mautic_merge_contacts "{\"winnerId\":$cw,\"loserId\":$cl}")")"
jq -e --argjson w "$cw" --argjson l "$cl" '.merged == false and .winner.id == $w and .loser.id == $l and .points_after == 15' <<<"$mprev" >/dev/null \
  && [[ "$(db "SELECT COUNT(*) FROM leads WHERE id = $cl")" == 1 ]] \
  && pass "merge preview: both contacts shown, points add up, nothing changed" || { echo "$mprev" | head -c 600; fail "merge preview"; }
mnoexp="$(call "$dtoken" mautic_merge_contacts "{\"winnerId\":$cw,\"loserId\":$cl,\"confirm\":true}")"
grep -q 'CONFIRM_REQUIRED' <<<"$mnoexp" && [[ "$(db "SELECT COUNT(*) FROM leads WHERE id = $cl")" == 1 ]] \
  && pass "merge needs expectedLoserId (CONFIRM_REQUIRED, nothing merged)" || { echo "$mnoexp" | head -c 500; fail "merge without expectedLoserId"; }
mdone="$(result "$(call "$dtoken" mautic_merge_contacts "{\"winnerId\":$cw,\"loserId\":$cl,\"confirm\":true,\"expectedLoserId\":$cl}")")"
jq -e '.merged == true' <<<"$mdone" >/dev/null && [[ "$(db "SELECT COUNT(*) FROM leads WHERE id = $cl")" == 0 && "$(db "SELECT points FROM leads WHERE id = $cw")" == 15 ]] \
  && [[ "$(db "SELECT COUNT(*) FROM audit_log WHERE bundle = 'intellectit_mcp' AND object = 'contact' AND action = 'merge' AND object_id = $cw")" -ge 1 ]] \
  && pass "merge with confirm: loser deleted, winner has 15 points, audited" || { echo "$mdone" | head -c 600; fail "merge"; }
[[ "$(call "$dtoken" mautic_merge_contacts "{\"winnerId\":$cw,\"loserId\":$cw}" | jq -r '.result.isError')" == true ]] \
  && pass "merging a contact into itself is refused" || fail "self merge accepted"

cd1="$(mk_c delete-me@example.com Del 0)"
dprev="$(result "$(call "$dtoken" mautic_delete_record "{\"type\":\"contact\",\"id\":$cd1}")")"
jq -e '.deleted == false and .can_delete == true and .name == "delete-me@example.com"' <<<"$dprev" >/dev/null && [[ "$(db "SELECT COUNT(*) FROM leads WHERE id = $cd1")" == 1 ]] \
  && pass "delete preview names the record and deletes nothing" || { echo "$dprev" | head -c 600; fail "delete preview"; }
dwrong="$(call "$dtoken" mautic_delete_record "{\"type\":\"contact\",\"id\":$cd1,\"confirm\":true,\"expectedName\":\"someone-else@example.com\"}")"
grep -q 'CONFIRM_REQUIRED' <<<"$dwrong" && [[ "$(db "SELECT COUNT(*) FROM leads WHERE id = $cd1")" == 1 ]] \
  && pass "delete with a wrong expectedName is refused (nothing deleted)" || { echo "$dwrong" | head -c 500; fail "delete with wrong name"; }
ddone="$(result "$(call "$dtoken" mautic_delete_record "{\"type\":\"contact\",\"id\":$cd1,\"confirm\":true,\"expectedName\":\"delete-me@example.com\"}")")"
jq -e '.deleted == true' <<<"$ddone" >/dev/null && [[ "$(db "SELECT COUNT(*) FROM leads WHERE id = $cd1")" == 0 ]] \
  && [[ "$(db "SELECT COUNT(*) FROM audit_log WHERE bundle = 'intellectit_mcp' AND action = 'delete' AND object_id = $cd1")" -ge 1 ]] \
  && pass "delete with confirm and the right name removes the contact and is audited" || { echo "$ddone" | head -c 600; fail "delete"; }

pub_em="$(api -X POST "$BASE/api/emails/new" -d name=DelPub -d subject=x -d emailType=template -d isPublished=1 -d 'customHtml=<p>x</p>' | jq -r '.email.id')"
draft_em="$(api -X POST "$BASE/api/emails/new" -d name=DelDraft -d subject=x -d emailType=template -d isPublished=0 -d 'customHtml=<p>x</p>' | jq -r '.email.id')"
pubr="$(call "$dtoken" mautic_delete_record "{\"type\":\"email\",\"id\":$pub_em,\"confirm\":true,\"expectedName\":\"DelPub\"}")"
grep -q 'BLOCKED_BY_POLICY' <<<"$pubr" && [[ "$(db "SELECT COUNT(*) FROM emails WHERE id = $pub_em")" == 1 ]] \
  && pass "a published email cannot be deleted (BLOCKED_BY_POLICY)" || { echo "$pubr" | head -c 500; fail "published email deleted"; }
draftr="$(result "$(call "$dtoken" mautic_delete_record "{\"type\":\"email\",\"id\":$draft_em,\"confirm\":true,\"expectedName\":\"DelDraft\"}")")"
jq -e '.deleted == true' <<<"$draftr" >/dev/null && [[ "$(db "SELECT COUNT(*) FROM emails WHERE id = $draft_em")" == 0 ]] \
  && pass "an unpublished email is deleted" || { echo "$draftr" | head -c 500; fail "draft email delete"; }

set_params '{"intellectit_mcp_allow_destructive": false}'
mcp_init "$access"

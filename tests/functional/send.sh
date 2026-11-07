# shellcheck shell=bash
# Real sending: preview + confirm, DNC exclusion, caps, publish check, segment opt-in.
# Sourced by run.sh after insight.sh (uses $access, $read_token). Mailer is null://null.
send_token="$(access_token 'mcp:read mcp:send' mcp:send)"
send_names() { jq -r '[.result.tools[].name] | map(select(. == "mautic_preview_email_send" or . == "mautic_send_email")) | length'; }

# Off by default: neither tool is listed.
mcp_init "$send_token"
[[ "$(mcp_post "$send_token" '{"jsonrpc":"2.0","id":20,"method":"tools/list"}' | send_names)" == 0 ]] \
  && pass "allow_send=false (default): send tools are not listed" || fail "send tools listed by default"
set_params '{"intellectit_mcp_allow_send": true}'
[[ "$(mcp_post "$send_token" '{"jsonrpc":"2.0","id":21,"method":"tools/list"}' | send_names)" == 2 ]] \
  && pass "allow_send=true: mautic_preview_email_send and mautic_send_email are listed" || fail "send tools not listed"
[[ "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$BASE/mcp" -H "Authorization: Bearer $read_token" -H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' -H 'MCP-Protocol-Version: 2025-06-18' -H "Mcp-Session-Id: $MCP_SESSION" --data-binary '{"jsonrpc":"2.0","id":22,"method":"tools/call","params":{"name":"mautic_send_email","arguments":{"emailId":1,"contactIds":[1],"confirm":true}}}')" == 403 ]] \
  && pass "a token without mcp:send cannot call mautic_send_email (403)" || fail "mautic_send_email callable without mcp:send"

# Fixtures: a published template email, three contacts (one on Do Not Contact, one without an address).
mk_email() { # <name> <type> <published 0|1> [segment id]
  api -X POST "$BASE/api/emails/new" -d "name=$1" -d "subject=$1" -d "emailType=$2" -d "isPublished=$3" \
    -d 'customHtml=<html><body><p>Hello {contactfield=firstname}</p><a href="{unsubscribe_url}">Unsubscribe</a></body></html>' ${4:+-d "lists[]=$4"} | jq -r '.email.id'
}
mk_contact() { api -X POST "$BASE/api/contacts/new" -d "email=$1" -d firstname=Send | jq -r '.contact.id'; }
em="$(mk_email 'Send test' template 1)"; em_off="$(mk_email 'Send draft' template 0)"
c_ok="$(mk_contact send-ok@example.com)"; c_dnc="$(mk_contact send-dnc@example.com)"; c_none="$(api -X POST "$BASE/api/contacts/new" -d firstname=NoMail | jq -r '.contact.id')"
api -X POST "$BASE/api/contacts/$c_dnc/dnc/email/add" -d reason=3 >/dev/null

# th <emailId> <"contactIds"|"segmentIds"> <csv ids>: the targets_hash a preview of exactly that selection returns.
th() { result "$(call "$send_token" mautic_preview_email_send "{\"emailId\":$1,\"$2\":[$3]}")" | jq -r '.targets_hash'; }

prev="$(result "$(call "$send_token" mautic_preview_email_send "{\"emailId\":$em,\"contactIds\":[$c_ok,$c_dnc,$c_none]}")")"
jq -e --argjson ok "$c_ok" --argjson d "$c_dnc" --argjson n "$c_none" '.mode == "contacts" and .recipient_count == 1 and .recipients[0].id == $ok and .excluded.do_not_contact == [$d] and .excluded.no_valid_email == [$n] and .can_send == true' <<<"$prev" >/dev/null \
  && pass "mautic_preview_email_send: 1 recipient; DNC and no-address contacts excluded and named" || { echo "$prev" | head -c 900; fail "preview"; }

[[ "$(db "SELECT COUNT(*) FROM audit_log WHERE bundle = 'intellectit_mcp' AND object = 'email' AND object_id = $em AND action = 'send_preview' AND details LIKE '%targets_hash%' AND details LIKE '%\"ref\"%'")" -ge 1 ]] \
  && pass "a send preview is written to the audit log (with targets_hash and ref)" || fail "preview not audited"

unconf="$(result "$(call "$send_token" mautic_send_email "{\"emailId\":$em,\"contactIds\":[$c_ok]}")")"
jq -e '.sent == false and .recipient_count == 1' <<<"$unconf" >/dev/null \
  && [[ "$(db "SELECT COUNT(*) FROM email_stats WHERE email_id = $em")" == 0 ]] \
  && [[ "$(db "SELECT COUNT(*) FROM audit_log WHERE bundle = 'intellectit_mcp' AND object = 'email' AND object_id = $em AND action = 'send_preview' AND details LIKE '%send_without_confirm%'")" -ge 1 ]] \
  && [[ "$(db "SELECT COUNT(*) FROM audit_log WHERE bundle = 'intellectit_mcp' AND object = 'email' AND object_id = $em AND action = 'send'")" == 0 ]] \
  && pass "mautic_send_email without confirm sends nothing, returns the preview, and is audited as a preview (not as a send)" || { echo "$unconf" | head -c 600; fail "unconfirmed send"; }

draft="$(call "$send_token" mautic_send_email "{\"emailId\":$em_off,\"contactIds\":[$c_ok],\"confirm\":true}")"
grep -q 'not published' <<<"$draft" && [[ "$(db "SELECT COUNT(*) FROM email_stats WHERE email_id = $em_off")" == 0 ]] \
  && pass "an unpublished email is never sent, even with confirm" || { echo "$draft" | head -c 600; fail "unpublished email sent"; }

[[ "$(db "SELECT COUNT(*) FROM audit_log WHERE bundle = 'intellectit_mcp' AND object = 'refusal' AND action = 'refused' AND details LIKE '%BLOCKED_BY_POLICY%'")" -ge 1 ]] \
  && pass "a policy refusal (unpublished email) is written to the audit log with its code" || fail "refusal not audited"
h_ok="$(th $em contactIds $c_ok)"; h_two="$(th $em contactIds "$c_ok,$c_dnc")"
nohash="$(call "$send_token" mautic_send_email "{\"emailId\":$em,\"contactIds\":[$c_ok],\"confirm\":true,\"expectedRecipientCount\":1}")"
swap="$(call "$send_token" mautic_send_email "{\"emailId\":$em,\"contactIds\":[$c_ok,$c_dnc],\"confirm\":true,\"expectedRecipientCount\":1,\"targetsHash\":\"$h_ok\"}")"
grep -q 'targetsHash' <<<"$nohash" && grep -q 'targetsHash does not match' <<<"$swap" && [[ "$(db "SELECT COUNT(*) FROM email_stats WHERE email_id = $em")" == 0 ]] \
  && pass "confirm needs targetsHash, and a hash from a different selection is refused (nothing sent)" || { echo "$nohash $swap" | head -c 800; fail "targetsHash not enforced"; }
nocount="$(call "$send_token" mautic_send_email "{\"emailId\":$em,\"contactIds\":[$c_ok],\"confirm\":true,\"targetsHash\":\"$h_ok\"}")"
wrongcount="$(call "$send_token" mautic_send_email "{\"emailId\":$em,\"contactIds\":[$c_ok,$c_dnc],\"confirm\":true,\"expectedRecipientCount\":2,\"targetsHash\":\"$h_two\"}")"
grep -q 'expectedRecipientCount' <<<"$nocount" && grep -q 'you expected 2 recipients but 1' <<<"$wrongcount" && [[ "$(db "SELECT COUNT(*) FROM email_stats WHERE email_id = $em")" == 0 ]] \
  && pass "confirm needs expectedRecipientCount, and a count that differs from the preview is refused (nothing sent)" || { echo "$nocount $wrongcount" | head -c 800; fail "expectedRecipientCount not enforced"; }

sent="$(result "$(call "$send_token" mautic_send_email "{\"emailId\":$em,\"contactIds\":[$c_ok,$c_dnc],\"confirm\":true,\"expectedRecipientCount\":1,\"targetsHash\":\"$h_two\"}")")"
jq -e '.sent == true and .sent_count == 1 and .failed_count == 0' <<<"$sent" >/dev/null \
  && [[ "$(db "SELECT COUNT(*) FROM email_stats WHERE email_id = $em AND lead_id = $c_ok")" == 1 && "$(db "SELECT COUNT(*) FROM email_stats WHERE email_id = $em AND lead_id = $c_dnc")" == 0 ]] \
  && pass "mautic_send_email confirm=true: the contact got it, the Do Not Contact contact did not" || { echo "$sent" | head -c 700; fail "confirmed send"; }

[[ "$(db "SELECT COUNT(*) FROM audit_log WHERE bundle = 'intellectit_mcp' AND object = 'email' AND object_id = $em AND action = 'send'")" == 1 && "$(db "SELECT COUNT(*) FROM audit_log WHERE bundle = 'intellectit_mcp' AND object = 'email' AND object_id = $em AND action = 'send' AND details LIKE '%\"ref\"%' AND details LIKE '%\"contactIds\"%'")" == 1 ]] \
  && pass "one send is one audit row, with its recipients and a correlation ref" || fail "send audit row missing, duplicated or without ref"
set_params '{"intellectit_mcp_send_max_recipients": 1}'
cap="$(result "$(call "$send_token" mautic_preview_email_send "{\"emailId\":$em,\"contactIds\":[$c_ok,$c_dnc]}")")"
set_params '{"intellectit_mcp_send_max_recipients": 100}'
jq -e '.can_send == false and (.blockers | join(" ") | test("at most 1"))' <<<"$cap" >/dev/null \
  && pass "recipient cap: more contacts than intellectit_mcp_send_max_recipients is blocked" || { echo "$cap" | head -c 600; fail "cap"; }

set_params '{"intellectit_mcp_send_max_per_hour": 1}'
c_hour="$(mk_contact send-hour@example.com)"
hour="$(result "$(call "$send_token" mautic_preview_email_send "{\"emailId\":$em,\"contactIds\":[$c_hour]}")")"
set_params '{"intellectit_mcp_send_max_per_hour": 500}'
jq -e '.can_send == false and .sent_last_hour >= 1 and (.blockers | join(" ") | test("Hourly limit"))' <<<"$hour" >/dev/null \
  && pass "hourly limit: sends already made in the last hour count against intellectit_mcp_send_max_per_hour" || { echo "$hour" | head -c 600; fail "hourly limit"; }

# Operator override: with intellectit_mcp_send_ignore_dnc a direct send does reach a Do Not Contact contact, warns, and is audited.
set_params '{"intellectit_mcp_send_ignore_dnc": true}'
dncprev="$(result "$(call "$send_token" mautic_preview_email_send "{\"emailId\":$em,\"contactIds\":[$c_dnc]}")")"
dncsent="$(result "$(call "$send_token" mautic_send_email "{\"emailId\":$em,\"contactIds\":[$c_dnc],\"confirm\":true,\"expectedRecipientCount\":1,\"targetsHash\":\"$(th $em contactIds $c_dnc)\"}")")"
set_params '{"intellectit_mcp_send_ignore_dnc": false}'
jq -e '.recipient_count == 1 and (.warnings | join(" ") | test("NOT excluded"))' <<<"$dncprev" >/dev/null && jq -e '.sent == true' <<<"$dncsent" >/dev/null \
  && [[ "$(db "SELECT COUNT(*) FROM email_stats WHERE email_id = $em AND lead_id = $c_dnc")" == 1 ]] \
  && [[ "$(db "SELECT COUNT(*) FROM audit_log WHERE bundle = 'intellectit_mcp' AND object = 'email' AND action = 'send' AND details LIKE '%dnc_override%b:1%'")" -ge 1 ]] \
  && pass "DNC override: the Do Not Contact contact is emailed, the preview warns, the audit row records the override" || { echo "$dncprev $dncsent" | head -c 900; fail "DNC override"; }
back="$(result "$(call "$send_token" mautic_preview_email_send "{\"emailId\":$em,\"contactIds\":[$c_dnc]}")")"
jq -e '.recipient_count == 0 and .can_send == false' <<<"$back" >/dev/null && pass "with the override off again, the Do Not Contact contact is excluded" || fail "DNC exclusion did not come back"

# Segments: a separate switch, only the email's own segments.
seg="$(api -X POST "$BASE/api/segments/new" -d name='Send segment' -d isPublished=1 | jq -r '.list.id')"
api -X POST "$BASE/api/segments/$seg/contact/$c_ok/add" >/dev/null
em_seg="$(mk_email 'Send segment email' list 1 "$seg")"
segoff="$(result "$(call "$send_token" mautic_preview_email_send "{\"emailId\":$em_seg,\"segmentIds\":[$seg]}")")"
jq -e '.mode == "segments" and .can_send == false and (.blockers | join(" ") | test("switched off"))' <<<"$segoff" >/dev/null \
  && pass "segment send is blocked until intellectit_mcp_allow_segment_send is on" || { echo "$segoff" | head -c 600; fail "segment gate"; }
set_params '{"intellectit_mcp_allow_segment_send": true}'
segprev="$(result "$(call "$send_token" mautic_preview_email_send "{\"emailId\":$em_seg,\"segmentIds\":[$seg]}")")"
jq -e '.can_send == true and .pending_total >= 1' <<<"$segprev" >/dev/null && pass "segment preview: $(jq -r '.pending_total' <<<"$segprev") pending contact(s)" || { echo "$segprev" | head -c 600; fail "segment preview"; }
# Large segment: one call sends at most the cap, the preview says so and counts only what would go out.
c_seg2="$(mk_contact send-seg2@example.com)"
api -X POST "$BASE/api/segments/$seg/contact/$c_seg2/add" >/dev/null
set_params '{"intellectit_mcp_send_max_recipients": 1}'
segcap="$(result "$(call "$send_token" mautic_preview_email_send "{\"emailId\":$em_seg,\"segmentIds\":[$seg]}")")"
set_params '{"intellectit_mcp_send_max_recipients": 100}'
jq -e '.pending_total >= 2 and .recipient_count == 1 and (.warnings | join(" ") | test("at most 1"))' <<<"$segcap" >/dev/null \
  && pass "segment cap: $(jq -r '.pending_total' <<<"$segcap") pending, one call would send $(jq -r '.recipient_count' <<<"$segcap")" || { echo "$segcap" | head -c 700; fail "segment cap"; }
[[ "$(call "$send_token" mautic_preview_email_send "{\"emailId\":$em_seg,\"segmentIds\":[999999]}" | jq -r '.result.isError')" == true ]] \
  && pass "a segment the email is not assigned to is refused" || fail "foreign segment accepted"
segsent="$(result "$(call "$send_token" mautic_send_email "{\"emailId\":$em_seg,\"segmentIds\":[$seg],\"confirm\":true,\"expectedRecipientCount\":2,\"targetsHash\":\"$(th $em_seg segmentIds $seg)\"}")")"
jq -e '.sent == true and .sent_count >= 2' <<<"$segsent" >/dev/null && [[ "$(db "SELECT COUNT(*) FROM email_stats WHERE email_id = $em_seg AND lead_id = $c_ok")" == 1 ]] \
  && pass "segment send with confirm reached the segment's contact" || { echo "$segsent" | head -c 700; fail "segment send"; }

# A role that may see the segment and its own contacts, but not edit other people's contacts, cannot email the segment.
srid="$(api -X POST "$BASE/api/roles/new" -d name='Sender (smoke)' -d isAdmin=0 \
  -d 'rawPermissions[email:emails][]=viewother' -d 'rawPermissions[lead:lists][]=viewother' -d 'rawPermissions[lead:leads][]=viewown' -d 'rawPermissions[lead:leads][]=editown' | jq -r '.role.id')"
api -X POST "$BASE/api/users/new" -d username=ai-sender -d firstName=AI -d lastName=Sender -d email=ai-sender@example.com \
  -d "role=$srid" -d 'plainPassword[password]=Ai-S3nder-Passw0rd!' -d 'plainPassword[confirm]=Ai-S3nder-Passw0rd!' >/dev/null
admin_jar="$JAR"; JAR="$WORK/sender-jar"
login ai-sender 'Ai-S3nder-Passw0rd!'
sender_token="$(access_token 'mcp:read mcp:send' mcp:send)"
JAR="$admin_jar"
em_seg2="$(mk_email 'Send segment email 2' list 1 "$seg")"
restricted="$(call "$sender_token" mautic_send_email "{\"emailId\":$em_seg2,\"segmentIds\":[$seg],\"confirm\":true}")"
grep -q 'role does not allow' <<<"$restricted" && [[ "$(db "SELECT COUNT(*) FROM email_stats WHERE email_id = $em_seg2")" == 0 ]] \
  && pass "segment send needs permission over other people's contacts (restricted role refused, nothing sent)" || { echo "$restricted" | head -c 700; fail "segment send ignored contact permissions"; }

set_params '{"intellectit_mcp_allow_send": false, "intellectit_mcp_allow_segment_send": false}'
mcp_init "$access"

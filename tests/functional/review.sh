# shellcheck shell=bash
# Email and campaign team workflow: reporting, copy, unsubscribe, send-path notes, tool groups.
# Sourced by run.sh after send.sh (uses $access, $contacts_token, $send_token, $em, $c_ok, $c_dnc, $seg).
echo "Review changes (reporting, copy, unsubscribe, hidden groups)"
set_params '{"intellectit_mcp_allow_write": true, "intellectit_mcp_allow_send": true}'

# Send path: a contact who already got the email is listed, not counted; the preview names its targets.
again="$(result "$(call "$send_token" mautic_preview_email_send "{\"emailId\":$em,\"contactIds\":[$c_ok]}")")"
jq -e --argjson c "$c_ok" '.recipient_count == 0 and .excluded.already_sent == [$c] and .targets.contactIds == [$c] and .can_send == false' <<<"$again" >/dev/null \
  && pass "send preview: a contact who already got the email is excluded as already_sent, and the targets are echoed" || { echo "$again" | head -c 700; fail "already_sent exclusion"; }
segprev2="$(result "$(call "$send_token" mautic_preview_email_send "{\"emailId\":$em_seg,\"segmentIds\":[$seg]}")")"
jq -e '(.warnings | join(" ") | test("broadcast")) and .targets.segmentIds == ['"$seg"']' <<<"$segprev2" >/dev/null \
  && pass "segment preview warns that a published segment email can also go out through Mautic's own broadcast" || { echo "$segprev2" | head -c 600; fail "broadcast warning"; }

# Reporting: stats carry bounced/unsubscribed/clickers/last_sent; search finds who got the email.
u1="$(mk_contact rv-unsub@example.com)"; u2="$(mk_contact rv-bounce@example.com)"
db_exec "INSERT INTO lead_donotcontact (lead_id, date_added, reason, channel, channel_id) VALUES ($u1, NOW(), 1, 'email', $em), ($u2, NOW(), 2, 'email', $em)" >/dev/null
stats="$(result "$(call "$access" mautic_get_email_stats "{\"id\":$em}")")"
jq -e '.sent >= 2 and .unsubscribed == 1 and .bounced == 1 and .clickers == 0 and .click_rate == 0 and (.last_sent | type == "string")' <<<"$stats" >/dev/null \
  && pass "mautic_get_email_stats: sent, bounced, unsubscribed, clickers, click_rate and last_sent" || { echo "$stats" | head -c 700; fail "email stats"; }
who="$(result "$(call "$access" mautic_search_contacts "{\"search\":\"email_sent:$em\"}")")"
jq -e --argjson c "$c_ok" '.total >= 1 and ([.contacts[].id] | index($c)) != null' <<<"$who" >/dev/null \
  && pass "search email_sent:<id> lists the contacts who were sent the email" || { echo "$who" | head -c 600; fail "email_sent search"; }

# Copy an email (copyFromId): keeps theme and topic, never the audience, never published.
src="$(result "$(call "$access" mautic_create_email_draft "{\"name\":\"Copy source\",\"subject\":\"Original\",\"html\":\"<html><body><p>Hi</p></body></html>\",\"type\":\"segment\",\"segmentIds\":[$seg],\"topic\":\"webinare & events\"}")")"
src_id="$(jq -r '.id' <<<"$src")"
copy="$(result "$(call "$access" mautic_create_email_draft "{\"name\":\"Copy of source\",\"subject\":\"Changed\",\"copyFromId\":$src_id}")")"
cid2="$(jq -r '.id' <<<"$copy")"
got="$(result "$(call "$access" mautic_get_email "{\"id\":$cid2}")")"
jq -e --arg t "$(jq -r '.topic' <<<"$src")" '.name == "Copy of source" and .subject == "Changed" and .published == false and .segments == [] and .topic == $t and .theme == "mautic_code_mode" and (.html | test("<p>Hi</p>"))' <<<"$got" >/dev/null \
  && pass "copyFromId: content, theme and topic are kept; new name and subject; unpublished; segments are not copied" || { echo "$got" | head -c 800; fail "copyFromId"; }
[[ "$(call "$access" mautic_create_email_draft '{"name":"x","subject":"x"}' | jq -r '.result.isError')" == true ]] \
  && pass "an email draft without html and without copyFromId is refused" || fail "empty draft accepted"
pub="$(call "$access" mautic_update_email_draft "{\"id\":$em,\"subject\":\"nope\"}")"
grep -q "copyFromId=$em" <<<"$pub" && pass "changing a sent email points to copyFromId instead of a dead end" || { echo "$pub" | head -c 500; fail "dead-end error"; }
[[ "$(call "$access" mautic_create_email_draft '{"name":"x","subject":"x","copyFromId":999999}' | jq -r '.result.isError')" == true ]] \
  && pass "copyFromId of an unknown email is refused" || fail "unknown copy source accepted"

# Add-only unsubscribe on upsert.
dry="$(result "$(call "$contacts_token" mautic_upsert_contacts '{"contacts":[{"email":"rv-new-unsub@example.com","unsubscribe":true}],"dryRun":true}')")"
jq -e '.results[0].do_not_contact == "would be added"' <<<"$dry" >/dev/null && [[ "$(db "SELECT COUNT(*) FROM leads WHERE email = 'rv-new-unsub@example.com'")" == 0 ]] \
  && pass "unsubscribe dry run: reports 'would be added', saves nothing" || { echo "$dry" | head -c 600; fail "unsubscribe dry run"; }
add="$(result "$(call "$contacts_token" mautic_upsert_contacts '{"contacts":[{"email":"rv-new-unsub@example.com","unsubscribe":true},{"email":"send-ok@example.com","unsubscribe":true}]}')")"
jq -e '.counts.failed == 0 and .results[0].do_not_contact == "added" and .results[1].do_not_contact == "added"' <<<"$add" >/dev/null \
  && [[ "$(db "SELECT COUNT(*) FROM lead_donotcontact d JOIN leads l ON l.id = d.lead_id WHERE d.channel = 'email' AND d.reason = 1 AND l.email IN ('rv-new-unsub@example.com','send-ok@example.com')")" == 2 ]] \
  && pass "unsubscribe=true puts a new and an existing contact on the email Do Not Contact list (reason: unsubscribed)" || { echo "$add" | head -c 700; fail "unsubscribe add"; }
keep="$(result "$(call "$contacts_token" mautic_upsert_contacts '{"contacts":[{"email":"send-dnc@example.com","unsubscribe":true}]}')")"
jq -e '.results[0].do_not_contact | test("already")' <<<"$keep" >/dev/null && [[ "$(db "SELECT reason FROM lead_donotcontact WHERE lead_id = $c_dnc AND channel = 'email'")" == 3 ]] \
  && pass "an existing Do Not Contact entry is left exactly as it was (manual stays manual)" || { echo "$keep" | head -c 600; fail "existing DNC changed"; }
call "$access" mautic_upsert_contacts '{"contacts":[{"email":"x@example.com","unsubscribe":true}]}' >/dev/null
grep -q '^HTTP/1.1 403' "$WORK/mcp-headers" && grep -qi '^www-authenticate:.*insufficient_scope' "$WORK/mcp-headers" \
  && pass "unsubscribe still needs mcp:contacts:write" || fail "unsubscribe without contacts scope"

# Layout tools are only listed when a theme ships layouts (none here); groups can be hidden.
mcp_init "$access"
names="$(mcp_post "$access" '{"jsonrpc":"2.0","id":40,"method":"tools/list"}' | jq -r '[.result.tools[].name] | join(" ")')"
grep -q mautic_create_email_from_layout <<<"$names" && fail "layout tools listed without a theme with layouts"
mcp_init "$access"; inst="$(jq -r '.result.instructions' "$WORK/init")"
grep -q 'from_layout' <<<"$inst" && fail "instructions mention layouts without a theme with layouts"
pass "layout tools and the layout instruction are absent when no theme has layouts"
grep -q mautic_create_point_group <<<"$names" && grep -q mautic_upsert_companies <<<"$names" && grep -q 'Scoring' <<<"$inst" \
  && pass "default: points, stages, projects and companies tools are listed" || fail "default groups missing"
set_params '{"intellectit_mcp_hidden_groups": ["points", "stages", "projects", "companies"]}'
mcp_init "$access"; inst="$(jq -r '.result.instructions' "$WORK/init")"
hidden_list="$(mcp_post "$access" '{"jsonrpc":"2.0","id":41,"method":"tools/list"}')"
hidden="$(jq -r '[.result.tools[].name] | join(" ")' <<<"$hidden_list")"
echo "  info  write profile with points, stages, projects and companies hidden: $(jq '.result.tools | length' <<<"$hidden_list") tools, $(printf '%s' "$hidden_list" | wc -c | tr -d ' ') bytes tools/list"
set_params '{"intellectit_mcp_hidden_groups": []}'
! grep -Eq 'point|stage|project|compan' <<<"$hidden" && ! grep -Eq 'Scoring|Stages \(|Projects group|companies' <<<"$inst" && grep -q mautic_get_email_stats <<<"$hidden" \
  && pass "intellectit_mcp_hidden_groups hides $(wc -w <<<"$names" | tr -d ' ') -> $(wc -w <<<"$hidden" | tr -d ' ') tools and their instruction lines" || { echo "$hidden"; echo "$inst" | head -c 800; fail "hidden groups"; }
set_params '{"intellectit_mcp_allow_send": false}'

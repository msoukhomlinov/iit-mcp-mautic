# shellcheck shell=bash disable=SC2154,SC2034  # shares variables with run.sh
# Core MCP tools: contacts, email drafts, scopes, campaign flows executed by
# Mautic, editing running campaigns and its locks, Mautic roles.

echo "MCP tools"
# A contact that bounced (Do Not Contact), as Mautic records it.
cid="$(api -X POST "$BASE/api/contacts/new" -d email=bounce@example.com | jq -r .contact.id)"
api -X POST "$BASE/api/contacts/$cid/dnc/email/add" -d reason=2 -d comments=bounced >/dev/null
found="$(call "$access" mautic_search_contacts '{"search":"bounce@example.com"}')"
jq -e '.contacts[0].email == "bounce@example.com"' <<<"$(result "$found")" >/dev/null \
  && pass "mautic_search_contacts finds the contact" || { echo "$found" | head -c 600; fail "mautic_search_contacts"; }
contact="$(result "$(call "$access" mautic_get_contact "{\"id\":$cid}")")"
jq -e '.do_not_contact[0].reason == "bounced"' <<<"$contact" >/dev/null \
  && pass "mautic_get_contact shows Do Not Contact (bounced)" || { echo "$contact" | head -c 600; fail "mautic_get_contact"; }
draft="$(call "$access" mautic_create_email_draft '{"name":"MCP smoke draft","subject":"Hallo","html":"<html><body><p>Hi</p>{unsubscribe_text}</body></html>","type":"template"}')"
eid="$(result "$draft" | jq -r '.id // empty')"
[[ "$eid" =~ ^[0-9]+$ ]] && [[ "$(result "$draft" | jq -r .published)" == false ]] \
  && pass "mautic_create_email_draft creates an unpublished email" || { echo "$draft" | head -c 600; fail "mautic_create_email_draft"; }
upd="$(call "$access" mautic_update_email_draft "{\"id\":$eid,\"subject\":\"Hallo Welt\"}")"
[[ "$(api "$BASE/api/emails/$eid" | jq -r .email.subject)" == "Hallo Welt" ]] \
  && pass "mautic_update_email_draft changes the draft" || { echo "$upd" | head -c 600; fail "mautic_update_email_draft"; }
api -X PATCH "$BASE/api/emails/$eid/edit" -d isPublished=1 >/dev/null
locked="$(call "$access" mautic_update_email_draft "{\"id\":$eid,\"subject\":\"changed\"}")"
grep -q 'Only unpublished drafts' <<<"$(result "$locked")" && [[ "$(api "$BASE/api/emails/$eid" | jq -r .email.subject)" == "Hallo Welt" ]] \
  && pass "published emails cannot be changed via MCP" || { echo "$locked" | head -c 600; fail "published email changed"; }
denied="$(call "$read_token" mautic_create_email_draft '{"name":"x","subject":"x","html":"<p>x</p>"}')"
grep -q '^HTTP/1.1 403' "$WORK/mcp-headers" && grep -qi '^www-authenticate:.*insufficient_scope' "$WORK/mcp-headers" \
  && grep -qi '^www-authenticate:.*scope="mcp:read mcp:write mcp:contacts:write"' "$WORK/mcp-headers" \
  && pass "write tools need the mcp:write scope (403 insufficient_scope, challenge names all regular scopes)" || { cat "$WORK/mcp-headers"; echo "$denied" | head -c 600; fail "read-only token could write"; }
reads="$(call "$read_token" mautic_list_emails '{"search":"MCP smoke"}')"
jq -e '.total >= 1' <<<"$(result "$reads")" >/dev/null && pass "read tools work with the mcp:read scope" || { echo "$reads" | head -c 600; fail "mautic_list_emails"; }
sid="$(api -X POST "$BASE/api/segments/new" -d name='MCP smoke segment' -d isPublished=1 | jq -r '.list.id')"
segs="$(result "$(call "$access" mautic_list_segments '{"search":"MCP smoke"}')")"
jq -e --argjson id "$sid" '.segments[0].id == $id' <<<"$segs" >/dev/null && pass "mautic_list_segments" || { echo "$segs"; fail "mautic_list_segments"; }
camp="$(call "$access" mautic_create_campaign_draft "{\"name\":\"MCP smoke campaign\",\"segmentIds\":[$sid]}")"
campid="$(result "$camp" | jq -r '.id // empty')"
[[ "$campid" =~ ^[0-9]+$ ]] && jq -e --argjson id "$sid" '.segments[0].id == $id and .published == false' <<<"$(result "$(call "$access" mautic_get_campaign "{\"id\":$campid}")")" >/dev/null \
  && pass "mautic_create_campaign_draft + mautic_get_campaign (unpublished, segment source)" || { echo "$camp" | head -c 600; fail "campaign draft"; }
types="$(result "$(call "$access" mautic_list_campaign_step_types '{}')")"
jq -e '[.actions[].type] | index("email.send")' <<<"$types" >/dev/null && jq -e '[.decisions[].type] | index("email.open")' <<<"$types" >/dev/null \
  && pass "mautic_list_campaign_step_types (actions, decisions, conditions)" || { echo "$types" | head -c 600; fail "step types"; }
desc="$(result "$(call "$access" mautic_describe_campaign_step_type '{"type":"email.send"}')")"
jq -e '[.properties[].name] | index("email")' <<<"$desc" >/dev/null && pass "mautic_describe_campaign_step_type (properties from the step form)" || { echo "$desc" | head -c 800; fail "describe step type"; }
# Welcome email -> opened? yes: +10 points / no (after 3 days): reminder.
flow='[{"key":"welcome","type":"email.send","properties":{"email":'"$eid"',"email_type":"marketing"}},
       {"key":"opened","type":"email.open","parent":"welcome"},
       {"key":"engaged","type":"lead.changepoints","parent":"opened","path":"yes","properties":{"points":10}},
       {"key":"reminder","type":"email.send","parent":"opened","path":"no","wait":{"interval":3,"unit":"d"},"properties":{"email":'"$eid"',"email_type":"marketing"}}]'
set_steps() { call "$access" mautic_set_campaign_steps "{\"id\":$campid,\"steps\":$(jq -c . <<<"$1")}"; }
saved="$(set_steps "$flow")"
jq -e '.steps | length == 4' <<<"$(result "$saved")" >/dev/null || { echo "$saved" | head -c 1200; fail "mautic_set_campaign_steps"; }
camp_api="$(api "$BASE/api/campaigns/$campid")"
jq -e '(.campaign.events | length == 4)
  and ([.campaign.events[] | select(.name != null and .type == "email.send" and .decisionPath == "no" and .triggerMode == "interval" and .triggerInterval == 3)] | length == 1)
  and ([.campaign.canvasSettings.nodes[].id | tostring | select(startswith("new"))] | length == 0)
  and (.campaign.canvasSettings.connections | length == 4)
  and (.campaign.isPublished == false)' <<<"$camp_api" >/dev/null \
  && pass "mautic_set_campaign_steps: 4 steps with yes/no branches and wait, builder canvas, still unpublished" || { jq -c '.campaign | {events: [.events[] | {name,type,decisionPath,triggerMode,triggerInterval,parent: .parent.id}], canvasSettings}' <<<"$camp_api" | head -c 1500; fail "campaign flow not stored correctly"; }
bad="$(set_steps '[{"key":"a","type":"email.send","properties":{"email":'"$eid"'}},{"key":"b","type":"email.open","parent":"a"},{"key":"c","type":"email.send","parent":"b","path":"no","properties":{"email":'"$eid"'}}]')"
grep -q 'set \\"wait\\"\|set "wait"' <<<"$bad$(result "$bad")" && pass "flow rules enforced (no-path of a decision needs a wait)" || { echo "$bad" | head -c 600; fail "missing wait accepted"; }
bad="$(set_steps '[{"key":"x","type":"email.open"}]')"
grep -q 'cannot be the first step' <<<"$(result "$bad")" && pass "flow rules enforced (email.open must follow email.send)" || { echo "$bad" | head -c 600; fail "email.open as first step accepted"; }
[[ "$(api "$BASE/api/campaigns/$campid" | jq '.campaign.events | length')" == 4 ]] && pass "rejected flows leave the saved one untouched" || fail "rejected flow changed the campaign"
# The flow really runs: publish it (a human step), add a contact, run Mautic's campaign cron.
api -X POST "$BASE/api/contacts/new" -d email=flow@example.com -d firstname=Flow >/dev/null
flow_cid="$(api "$BASE/api/contacts?search=flow@example.com" | jq -r '.contacts | keys[0]')"
api -X POST "$BASE/api/segments/$sid/contact/$flow_cid/add" >/dev/null
api -X PATCH "$BASE/api/campaigns/$campid/edit" -d isPublished=1 >/dev/null
console mautic:campaigns:update -i "$campid" >/dev/null 2>&1
trigger="$(console mautic:campaigns:trigger -i "$campid" 2>&1)"
grep -qE '[1-9][0-9]* total events? (were|was) executed' <<<"$trigger" \
  && pass "Mautic executes the flow (campaigns:trigger: $(grep -oE '[0-9]+ total events? (were|was) executed' <<<"$trigger" | head -1))" || { echo "$trigger" | tail -15; fail "campaign flow did not run"; }
# Editing the RUNNING campaign (published, one contact on its way).
live="$(result "$(call "$access" mautic_get_campaign "{\"id\":$campid}")")"
blocked="$(call "$access" mautic_update_campaign_step "{\"campaignId\":$campid,\"key\":\"$(jq -r '.steps[] | select(.path == "no") | .key' <<<"$live")\",\"wait\":{\"interval\":5,\"unit\":\"d\"}}")"
preview="$(result "$(call "$access" mautic_update_campaign_step "{\"campaignId\":$campid,\"key\":\"$(jq -r '.steps[] | select(.path == "no") | .key' <<<"$live")\",\"wait\":{\"interval\":5,\"unit\":\"d\"},\"dryRun\":true}")")"
grep -q 'needs the permission "mcp:campaigns:live"' <<<"$(result "$blocked")" && jq -e '.live_effects.save_allowed == false' <<<"$preview" >/dev/null \
  && pass "running campaign locked without mcp:campaigns:live (dry run shows save_allowed=false)" || { echo "$blocked $preview" | head -c 1500; fail "running campaign not locked"; }
reminder_id="$(jq -r '.steps[] | select(.path == "no") | .key' <<<"$live")"
welcome_id="$(jq -r '.steps[] | select(.parent == null) | .key' <<<"$live")"
jq -e '(.steps | length == 4) and all(.steps[]; .key | test("^[0-9]+$")) and .published == true' <<<"$live" >/dev/null \
  && pass "mautic_get_campaign returns the flow in mautic_set_campaign_steps format (keys = step IDs; welcome executed for $(jq -r --arg k "$welcome_id" '.steps[] | select(.key == $k) | .contacts.executed' <<<"$live") contact)" || { echo "$live" | head -c 1500; fail "mautic_get_campaign steps"; }
same="$(call "$live_token" mautic_set_campaign_steps "{\"id\":$campid,\"steps\":$(jq -c '[.steps[] | del(.kind, .contacts)]' <<<"$live")}")"
jq -e '(.changes.added | length) == 0 and (.changes.changed | length) == 0 and (.changes.removed | length) == 0 and (.changes.unchanged | length) == 4' <<<"$(result "$same")" >/dev/null \
  && pass "round trip: mautic_get_campaign -> mautic_set_campaign_steps changes nothing" || { echo "$same" | head -c 1500; fail "round trip"; }
upd="$(result "$(call "$live_token" mautic_update_campaign_step "{\"campaignId\":$campid,\"key\":\"$reminder_id\",\"wait\":{\"interval\":5,\"unit\":\"d\"}}")")"
jq -e --arg k "$reminder_id" '(.changes.changed == [{"key": $k, "name": .changes.changed[0].name, "fields": ["wait"]}]) and ([.steps[] | select(.key == $k) | .wait] == [{"interval": 5, "unit": "d"}]) and .published == true' <<<"$upd" >/dev/null \
  && pass "mautic_update_campaign_step on a running campaign: wait 3 -> 5 days, same step ID" || { echo "$upd" | head -c 1500; fail "mautic_update_campaign_step"; }
jq -e --arg k "$reminder_id" '.live_effects.contacts_in_campaign >= 1 and .live_effects.outbound_steps == [{"key": $k, "type": "email.send", "name": .live_effects.outbound_steps[0].name, "change": "changed"}] and (.live_effects.warning | test("Confirm with the user"))' <<<"$upd" >/dev/null \
  && pass "running campaign: result warns that the changed email step is live (live_effects)" || { jq -c .live_effects <<<"$upd"; fail "live_effects missing"; }
added="$(result "$(call "$live_token" mautic_add_campaign_step "{\"campaignId\":$campid,\"step\":{\"key\":\"bonus\",\"type\":\"lead.changepoints\",\"parent\":\"$reminder_id\",\"properties\":{\"points\":5}}}")")"
bonus_id="$(jq -r '.steps[] | select(.parent == "'"$reminder_id"'") | .key' <<<"$added")"
jq -e '(.changes.added | length == 1) and (.steps | length == 5)' <<<"$added" >/dev/null && [[ "$bonus_id" =~ ^[0-9]+$ ]] \
  && pass "mautic_add_campaign_step on a running campaign" || { echo "$added" | head -c 1500; fail "mautic_add_campaign_step"; }
moved="$(call "$live_token" mautic_set_campaign_steps "{\"id\":$campid,\"steps\":$(jq -c --arg r "$reminder_id" --arg w "$welcome_id" '[.steps[] | del(.kind, .contacts) | if .key == $r then .parent = $w | del(.path) else . end]' <<<"$added")}")"
grep -q 'cannot be moved' <<<"$(result "$moved")" && pass "steps of a running campaign cannot be moved" || { echo "$moved" | head -c 800; fail "move on running campaign accepted"; }
haskids="$(call "$live_token" mautic_remove_campaign_step "{\"campaignId\":$campid,\"key\":\"$reminder_id\"}")"
grep -q 'has following steps' <<<"$(result "$haskids")" && pass "steps with following steps are not removed" || { echo "$haskids" | head -c 800; fail "removed step with children"; }
noconfirm="$(call "$live_token" mautic_remove_campaign_step "{\"campaignId\":$campid,\"key\":\"$bonus_id\"}")"
dry="$(result "$(call "$live_token" mautic_remove_campaign_step "{\"campaignId\":$campid,\"key\":\"$bonus_id\",\"dryRun\":true}")")"
grep -q 'allow_deletions' <<<"$(result "$noconfirm")" && jq -e --arg k "$bonus_id" '.dry_run == true and .changes.removed[0].key == $k and (.changes.removed[0] | has("contacts_scheduled"))' <<<"$dry" >/dev/null \
  && [[ "$(api "$BASE/api/campaigns/$campid" | jq '.campaign.events | length')" == 5 ]] \
  && pass "removing from a running campaign needs confirmation; dryRun reports affected contacts" || { echo "$noconfirm $dry" | head -c 1500; fail "removal safeguards"; }
gone="$(result "$(call "$live_token" mautic_remove_campaign_step "{\"campaignId\":$campid,\"key\":\"$bonus_id\",\"confirm\":true,\"redirectTo\":\"$reminder_id\"}")")"
row="$(db "SELECT deleted IS NOT NULL, redirect_event_id FROM campaign_events WHERE id = $bonus_id" 2>/dev/null)"
jq -e '(.changes.removed | length == 1) and (.steps | length == 4)' <<<"$gone" >/dev/null && [[ "$(tr -s '[:space:]' ' ' <<<"$row" | sed 's/ $//')" == "1 $reminder_id" ]] \
  && pass "mautic_remove_campaign_step with confirm (contacts redirected to the reminder)" || { echo "$gone" | head -c 1500; fail "mautic_remove_campaign_step"; }
console mautic:campaigns:trigger -i "$campid" >/dev/null 2>&1 \
  && pass "campaign keeps running after the edits (campaigns:trigger)" || fail "campaigns:trigger after edits"
# Operator switch: intellectit_mcp_live_campaign_edits=false refuses even a token
# with mcp:campaigns:live (the MCP session survives the config change).
set_params '{"intellectit_mcp_live_campaign_edits": false}'
off="$(result "$(call "$live_token" mautic_update_campaign_step "{\"campaignId\":$campid,\"key\":\"$reminder_id\",\"wait\":{\"interval\":6,\"unit\":\"d\"}}")")"
set_params '{"intellectit_mcp_live_campaign_edits": true}'
grep -q 'disabled on this Mautic instance' <<<"$off" \
  && pass "liveCampaignEdits=false locks running campaigns even with the scope" || { echo "$off" | head -c 800; fail "operator switch"; }
stats="$(result "$(call "$access" mautic_get_email_stats "{\"id\":$eid}")")"
jq -e '.sent == 1 and (.links | type == "array")' <<<"$stats" >/dev/null && pass "mautic_get_email_stats (the campaign sent the welcome email)" || { echo "$stats"; fail "mautic_get_email_stats"; }
# A user whose role may only work on their own emails sees no contacts.
rid="$(api -X POST "$BASE/api/roles/new" -d name='AI Editor (smoke)' -d isAdmin=0 \
  -d 'rawPermissions[email:emails][]=viewown' -d 'rawPermissions[email:emails][]=create' -d 'rawPermissions[email:emails][]=editown' | jq -r '.role.id')"
api -X POST "$BASE/api/users/new" -d username=ai-editor -d firstName=AI -d lastName=Editor -d email=ai-editor@example.com \
  -d "role=$rid" -d 'plainPassword[password]=Ai-Ed1tor-Passw0rd!' -d 'plainPassword[confirm]=Ai-Ed1tor-Passw0rd!' >/dev/null
admin_jar="$JAR"; JAR="$WORK/editor-jar"
login ai-editor 'Ai-Ed1tor-Passw0rd!'
editor_token="$(access_token 'mcp:read mcp:write')"
JAR="$admin_jar"
nope="$(call "$editor_token" mautic_search_contacts '{"search":"bounce"}')"
own="$(call "$editor_token" mautic_create_email_draft '{"name":"Editor draft","subject":"x","html":"<p>x</p>"}')"
grep -q 'role does not allow' <<<"$(result "$nope")" && [[ "$(result "$own" | jq -r '.published')" == false ]] \
  && pass "tools respect the Mautic role of the user (no contacts, own email drafts)" || { echo "$nope $own" | head -c 800; fail "Mautic permissions not applied"; }


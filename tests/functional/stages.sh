# shellcheck shell=bash disable=SC2154,SC2034  # shares variables with run.sh
# Stages and projects through MCP. A new stage is unpublished; after a person
# publishes it, mautic_set_contact_stage moves a contact into it and the dry run
# names the dynamic segment that filters on the stage. Projects group items.
# Sourced by run.sh after points.sh (uses call, result, api,
# pass, fail, BASE, access, contacts_token, read_token, db, lead, tid).

echo "MCP stages and projects"
st="$(result "$(call "$access" mautic_create_stage '{"name":"SQL","weight":30,"description":"Sales-qualified"}')")"
stid="$(jq -r .id <<<"$st")"
dup="$(result "$(call "$access" mautic_create_stage '{"name":"SQL"}')")"
dupw="$(result "$(call "$access" mautic_create_stage '{"name":"Other","weight":30}')")"
early="$(result "$(call "$contacts_token" mautic_set_contact_stage "{\"contactId\":$lead,\"stage\":\"SQL\"}")")"
jq -e '.published == false and .weight == 30' <<<"$st" >/dev/null && grep -q 'already exists' <<<"$dup" && grep -q 'already has weight 30' <<<"$dupw" && grep -q 'not published' <<<"$early" \
  && pass "mautic_create_stage: unpublished, unique name and weight; contacts only move into published stages" || { echo "$st $dup $early" | head -c 1000; fail "mautic_create_stage"; }

[[ "$(api -X PATCH "$BASE/api/stages/$stid/edit" -d isPublished=1 | jq -r .stage.isPublished)" == true ]] || fail "publish stage via API"
seg="$(result "$(call "$contacts_token" mautic_create_segment '{"name":"SQLs","filters":[{"field":"stage","operator":"=","value":"SQL"}]}')")"
segid="$(jq -r .id <<<"$seg")"
[[ "$(db "SELECT filters FROM lead_lists WHERE id = $segid" 2>/dev/null)" == *'s:6:"filter";s:'*':"'"$stid"'"'* ]] \
  && pass "segment filter on the stage by name (stored as the stage ID)" || { echo "$seg" | head -c 600; fail "stage filter"; }
dry="$(result "$(call "$contacts_token" mautic_set_contact_stage "{\"contactId\":$lead,\"stage\":\"SQL\",\"dryRun\":true}")")"
jq -e --argjson s "$segid" '.dry_run == true and ([.live_effects.dynamic_segments_on_stage[].id] | index($s) != null)' <<<"$dry" >/dev/null \
  && [[ "$(api "$BASE/api/contacts/$lead" | jq -r '.contact.stage')" == null ]] \
  && pass "mautic_set_contact_stage dryRun names the dynamic segment on the stage, changes nothing" || { echo "$dry" | head -c 800; fail "stage dry run"; }
moved="$(result "$(call "$contacts_token" mautic_set_contact_stage "{\"contactId\":$lead,\"stage\":\"SQL\",\"reason\":\"Demo gebucht\"}")")"
[[ "$(api "$BASE/api/contacts/$lead" | jq -r '.contact.stage.name')" == SQL ]] \
  && [[ "$(result "$(call "$read_token" mautic_list_stages '{}')" | jq --argjson s "$stid" '[.stages[] | select(.id == $s) | .contacts][0]')" == 1 ]] \
  && pass "mautic_set_contact_stage moves the contact; mautic_list_stages counts it" || { echo "$moved" | head -c 600; fail "mautic_set_contact_stage"; }

pr="$(result "$(call "$access" mautic_create_project '{"name":"Lead scoring","description":"Smoke"}')")"
prid="$(jq -r .id <<<"$pr")"
add="$(result "$(call "$access" mautic_change_project_items "{\"action\":\"add\",\"projectId\":$prid,\"type\":\"stage\",\"ids\":[$stid]}")")"
add2="$(result "$(call "$access" mautic_change_project_items "{\"action\":\"add\",\"projectId\":$prid,\"type\":\"pointtrigger\",\"ids\":[$tid,999999]}")")"
got="$(result "$(call "$read_token" mautic_get_project "{\"id\":$prid}")")"
jq -e '(.added | length) == 1' <<<"$add" >/dev/null && jq -e '(.added | length) == 1 and .skipped[0].reason == "not found"' <<<"$add2" >/dev/null \
  && jq -e --argjson s "$stid" --argjson t "$tid" '.items.stage[0].id == $s and .items.pointtrigger[0].id == $t' <<<"$got" >/dev/null \
  && pass "projects: create, add stage and point trigger, mautic_get_project lists them" || { echo "$add $add2 $got" | head -c 1200; fail "projects"; }
rm_="$(result "$(call "$access" mautic_change_project_items "{\"action\":\"remove\",\"projectId\":$prid,\"type\":\"stage\",\"ids\":[$stid]}")")"
bad="$(result "$(call "$access" mautic_change_project_items "{\"action\":\"add\",\"projectId\":$prid,\"type\":\"contact\",\"ids\":[$lead]}")")"
[[ "$(result "$(call "$read_token" mautic_list_projects '{}')" | jq --argjson p "$prid" '[.projects[] | select(.id == $p) | .items.stage // 0][0]')" == 0 ]] && grep -q 'Unknown item type "contact"' <<<"$bad" \
  && pass "mautic_change_project_items; unknown item types refused" || { echo "$rm_ $bad" | head -c 800; fail "mautic_change_project_items"; }

# shellcheck shell=bash disable=SC2154,SC2034  # shares variables with run.sh
# Lead scoring through MCP: point group, point action, point trigger, contact
# points. The trigger is published by a person (here: the REST API), then
# Mautic itself fires it when MCP gives a contact enough points.
# Sourced by run.sh after crm.sh (uses call, result, api,
# pass, fail, BASE, access, contacts_token, read_token, live_token).

echo "MCP lead scoring (point groups, actions, triggers, contact points)"
grp="$(result "$(call "$access" mautic_create_point_group '{"name":"Produktinteresse","description":"Smoke"}')")"
gid="$(jq -r .id <<<"$grp")"
[[ "$gid" =~ ^[0-9]+$ ]] && [[ "$(result "$(call "$read_token" mautic_list_point_groups '{}')" | jq --argjson g "$gid" '[.groups[] | select(.id == $g)] | length')" == 1 ]] \
  && pass "mautic_create_point_group / mautic_list_point_groups" || { echo "$grp" | head -c 600; fail "point group"; }

types="$(result "$(call "$read_token" mautic_list_point_action_types '{}')")"
desc="$(result "$(call "$read_token" mautic_describe_point_action_type '{"type":"url.hit"}')")"
jq -e '[.types[].type] | index("url.hit") and index("form.submit")' <<<"$types" >/dev/null \
  && jq -e '[.properties[] | select(.name == "page_url" and .required)] | length == 1' <<<"$desc" >/dev/null \
  && pass "point action types come live from Mautic, with their settings" || { echo "$types $desc" | head -c 1000; fail "point action types"; }
missing="$(result "$(call "$access" mautic_create_point_action "{\"name\":\"x\",\"type\":\"url.hit\",\"delta\":5,\"properties\":{}}")")"
pa="$(result "$(call "$access" mautic_create_point_action "{\"name\":\"Preisseite besucht\",\"type\":\"url.hit\",\"delta\":20,\"groupId\":$gid,\"properties\":{\"page_url\":\"https://www.kopexa.com/de/preise*\"}}")")"
grep -q 'required setting(s) missing: page_url' <<<"$missing" \
  && jq -e --argjson g "$gid" '.published == false and .group.id == $g and .properties.page_url == "https://www.kopexa.com/de/preise*"' <<<"$pa" >/dev/null \
  && pass "mautic_create_point_action: unpublished, in the group; required settings enforced" || { echo "$missing $pa" | head -c 1000; fail "point action"; }
paid="$(jq -r .id <<<"$pa")"
[[ "$(result "$(call "$access" mautic_update_point_action "{\"id\":$paid,\"delta\":25}")" | jq -r .delta)" == 25 ]] \
  && pass "mautic_update_point_action" || fail "mautic_update_point_action"

ev="$(result "$(call "$read_token" mautic_list_point_trigger_event_types '{}')")"
jq -e '([.types[] | select(.type == "email.send_to_user") | .outbound] == [true]) and ([.types[] | select(.type == "lead.changetags") | .outbound] == [false])' <<<"$ev" >/dev/null \
  && pass "trigger event types, outbound ones marked" || { echo "$ev" | head -c 800; fail "trigger event types"; }
tr="$(result "$(call "$access" mautic_create_point_trigger "{\"name\":\"Sales-ready\",\"points\":50,\"groupId\":$gid,\"events\":[{\"type\":\"lead.changetags\",\"name\":\"Tag sales-ready\",\"properties\":{\"add_tags\":[\"sales-ready\"]}}]}")")"
tid="$(jq -r .id <<<"$tr")"
jq -e '.published == false and .events[0].properties.add_tags == ["sales-ready"]' <<<"$tr" >/dev/null \
  && pass "mautic_create_point_trigger: unpublished, tags by name" || { echo "$tr" | head -c 800; fail "point trigger"; }

# A person publishes the trigger (MCP never does).
[[ "$(api -X PATCH "$BASE/api/points/triggers/$tid/edit" -d isPublished=1 | jq -r .trigger.isPublished)" == true ]] || fail "publish trigger via API"
lead="$(api -X POST "$BASE/api/contacts/new" -d "email=scoring-$gid@example.com" | jq -r .contact.id)"
dry="$(result "$(call "$contacts_token" mautic_adjust_contact_points "{\"contactId\":$lead,\"points\":60,\"groupId\":$gid,\"reason\":\"Webinar\",\"dryRun\":true}")")"
jq -e --argjson t "$tid" '.dry_run == true and .points.after == 60 and ([.triggers[].id] == [$t])' <<<"$dry" >/dev/null \
  && [[ "$(api "$BASE/api/contacts/$lead" | jq -r '[.contact.tags[].tag] | index("sales-ready")')" == null ]] \
  && pass "mautic_adjust_contact_points dryRun names the trigger that would fire, changes nothing" || { echo "$dry" | head -c 800; fail "points dry run"; }
nope="$(call "$access" mautic_adjust_contact_points "{\"contactId\":$lead,\"points\":60}")"
grep -q '^HTTP/1.1 403' "$WORK/mcp-headers" && grep -qi '^www-authenticate:.*insufficient_scope' "$WORK/mcp-headers" && denied=1 || denied=0
done_="$(result "$(call "$contacts_token" mautic_adjust_contact_points "{\"contactId\":$lead,\"points\":60,\"groupId\":$gid,\"reason\":\"Webinar\"}")")"
got="$(result "$(call "$read_token" mautic_get_contact "{\"id\":$lead}")")"
[[ "$denied" == 1 ]] \
  && jq -e --argjson g "$gid" '[.point_groups[] | select(.id == $g) | .score] == [60]' <<<"$got" >/dev/null \
  && [[ "$(api "$BASE/api/contacts/$lead" | jq -r '[.contact.tags[].tag] | index("sales-ready") != null')" == true ]] \
  && pass "mautic_adjust_contact_points: +60 in the group, Mautic fires the trigger (tag sales-ready); needs mcp:contacts:write" || { echo "$nope $done_ $got" | head -c 1500; fail "points / trigger"; }
setp="$(result "$(call "$contacts_token" mautic_adjust_contact_points "{\"contactId\":$lead,\"points\":-5,\"operator\":\"set\"}")")"
jq -e '.points.after == -5' <<<"$setp" >/dev/null && [[ "$(api "$BASE/api/contacts/$lead" | jq -r .contact.points)" == -5 ]] \
  && pass "mautic_adjust_contact_points set (total score)" || { echo "$setp" | head -c 600; fail "points set"; }

prev="$(result "$(call "$access" mautic_update_point_trigger "{\"id\":$tid,\"points\":40,\"dryRun\":true}")")"
blocked="$(result "$(call "$access" mautic_update_point_trigger "{\"id\":$tid,\"points\":40}")")"
jq -e '.live_effects.save_allowed == false and .live_effects.contacts_at_score == 1 and .after.points == 40' <<<"$prev" >/dev/null \
  && grep -q 'needs the permission "mcp:campaigns:live"' <<<"$blocked" \
  && pass "published trigger locked without mcp:campaigns:live (dryRun shows live_effects)" || { echo "$prev $blocked" | head -c 1500; fail "trigger lock"; }
live="$(result "$(call "$live_token" mautic_update_point_trigger "{\"id\":$tid,\"points\":40}")")"
jq -e '.points == 40 and .published == true' <<<"$live" >/dev/null \
  && pass "mautic_update_point_trigger with mcp:campaigns:live" || { echo "$live" | head -c 800; fail "live trigger update"; }

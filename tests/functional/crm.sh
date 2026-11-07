# shellcheck shell=bash disable=SC2154,SC2034  # shares variables with run.sh
# CRM package through MCP: custom fields, companies, dynamic segments,
# contact activity.
# Sourced by run.sh after contacts.sh (uses call, result,
# api, pass, fail, BASE, access, contacts_token, read_token, ada_id, cid,
# console, db).

echo "MCP CRM package (fields, companies, dynamic segments)"
f="$(result "$(call "$contacts_token" mautic_create_contact_field '{"label":"Twenty ID","alias":"twenty_id"}')")"
again="$(result "$(call "$contacts_token" mautic_create_contact_field '{"label":"Twenty ID","alias":"twenty_id"}')")"
sel="$(result "$(call "$contacts_token" mautic_create_contact_field '{"label":"Deal stage","type":"select","options":["Lead","Qualified","Won"]}')")"
arr="$(result "$(call "$contacts_token" mautic_create_contact_field '{"label":"ARR","alias":"arr","type":"number","object":"company"}')")"
jq -e '.created == true' <<<"$f" >/dev/null && jq -e '.created == false' <<<"$again" >/dev/null \
  && jq -e '.alias == "deal_stage"' <<<"$sel" >/dev/null && jq -e '.object == "company"' <<<"$arr" >/dev/null \
  && pass "mautic_create_contact_field: text, select (contact), number (company); idempotent" || { echo "$f $again $sel $arr" | head -c 1200; fail "mautic_create_contact_field"; }

up="$(result "$(call "$contacts_token" mautic_upsert_contacts '{"contacts":[{"email":"ada@example.com","fields":{"twenty_id":"tw-1","deal_stage":"Qualified"},"points":42}]}')")"
ada="$(api "$BASE/api/contacts/$ada_id" | jq '.contact')"
jq -e '.counts.updated == 1' <<<"$up" >/dev/null && jq -e '.fields.all.twenty_id == "tw-1" and .fields.all.deal_stage == "Qualified" and .points == 42' <<<"$ada" >/dev/null \
  && pass "mautic_upsert_contacts writes custom fields and points" || { echo "$up" | head -c 600; jq -c '{points, f: .fields.all | {twenty_id, deal_stage}}' <<<"$ada"; fail "custom fields / points"; }

comp="$(result "$(call "$contacts_token" mautic_upsert_companies "{\"companies\":[{\"fields\":{\"companyname\":\"Kopexa Smoke AG\",\"companycity\":\"Kiel\",\"arr\":120000},\"contactIds\":[$ada_id]},{\"fields\":{\"companycity\":\"no name\"}}]}")")"
coid="$(jq -r '.results[0].id' <<<"$comp")"
got="$(result "$(call "$read_token" mautic_get_company "{\"id\":$coid}")")"
jq -e '.counts.created == 1 and .counts.failed == 1' <<<"$comp" >/dev/null \
  && jq -e --argjson a "$ada_id" '.fields.arr == 120000 and ([.contacts[].id] | index($a) != null)' <<<"$got" >/dev/null \
  && [[ "$(result "$(call "$contacts_token" mautic_upsert_companies '{"companies":[{"fields":{"companyname":"Kopexa Smoke AG","companycity":"Kiel"}}]}')" | jq -r '.counts.unchanged')" == 1 ]] \
  && pass "mautic_upsert_companies: create with custom field, link contact, match by name; mautic_get_company" || { echo "$comp $got" | head -c 1500; fail "companies"; }
[[ "$(result "$(call "$read_token" mautic_search_companies '{"search":"Kopexa Smoke"}')" | jq -r '.total')" == 1 ]] && pass "mautic_search_companies" || fail "mautic_search_companies"

fields="$(result "$(call "$contacts_token" mautic_list_segment_filter_fields '{}')")"
jq -e '([.fields[] | select(.object == "behaviors")] | length > 5) and ([.fields[] | select(.field == "tags")] | length == 1) and ([.fields[] | select(.field == "deal_stage") | .choices] == [["Lead", "Qualified", "Won"]])' <<<"$fields" >/dev/null \
  && pass "mautic_list_segment_filter_fields: fields, behaviour filters, tags, custom field choices" || { echo "$fields" | head -c 800; fail "filter fields"; }
dry="$(result "$(call "$contacts_token" mautic_create_segment '{"name":"Qualified (dynamic)","filters":[{"field":"deal_stage","operator":"=","value":"Qualified"},{"field":"tags","operator":"in","value":["twenty"]}],"dryRun":true}')")"
jq -e '.dry_run == true and .matching_contacts == 1' <<<"$dry" >/dev/null \
  && [[ "$(result "$(call "$read_token" mautic_list_segments '{"search":"Qualified (dynamic)"}')" | jq -r .total)" == 0 ]] \
  && pass "mautic_create_segment dryRun: filters valid, 1 contact matches, nothing saved" || { echo "$dry" | head -c 800; fail "dynamic segment dry run"; }
dyn="$(result "$(call "$contacts_token" mautic_create_segment '{"name":"Qualified (dynamic)","filters":[{"field":"deal_stage","operator":"=","value":"Qualified"}]}')")"
dsid="$(jq -r .id <<<"$dyn")"
console mautic:segments:update -i "$dsid" >/dev/null 2>&1
[[ "$(jq -r .dynamic <<<"$dyn")" == true ]] && [[ "$(api "$BASE/api/contacts?search=segment:$(jq -r .alias <<<"$dyn")" | jq -r .total)" == 1 ]] \
  && pass "dynamic segment created; Mautic's segment job fills it" || { echo "$dyn" | head -c 800; fail "dynamic segment"; }
bad="$(result "$(call "$contacts_token" mautic_create_segment '{"name":"x","filters":[{"field":"city","operator":"between","value":"x"}]}')")"
upd="$(result "$(call "$contacts_token" mautic_update_segment "{\"id\":$dsid,\"filters\":[{\"field\":\"deal_stage\",\"operator\":\"in\",\"value\":[\"Qualified\",\"Won\"]}],\"dryRun\":true}")")"
grep -q 'not allowed; use one of' <<<"$bad" && jq -e '.dry_run == true and .matching_contacts == 1 and (.live_effects | has("running_campaigns"))' <<<"$upd" >/dev/null \
  && pass "invalid operator rejected; mautic_update_segment dryRun with count and live_effects" || { echo "$bad $upd" | head -c 1200; fail "segment validation / update"; }

act="$(result "$(call "$read_token" mautic_get_contact_activity "{\"id\":$ada_id}")")"
jq -e '.total > 0 and ([.events[].type] | length > 0) and (.types | length > 5)' <<<"$act" >/dev/null \
  && pass "mautic_get_contact_activity: $(jq -r .total <<<"$act") events" || { echo "$act" | head -c 800; fail "activity"; }

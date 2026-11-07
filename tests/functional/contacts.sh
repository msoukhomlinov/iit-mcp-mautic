# shellcheck shell=bash disable=SC2154,SC2034  # shares variables with run.sh
# Contacts and segments through MCP (scope mcp:contacts:write), the way an
# assistant syncs a CRM such as Twenty: upsert with dry run, segment
# membership with live effects, export with all fields. Sourced by
# run.sh (uses call, result, api, pass, fail, BASE, access,
# contacts_token, read_token, sid, campid, cid).

echo "MCP contacts and segments (scope mcp:contacts:write)"
denied="$(call "$access" mautic_upsert_contacts '{"contacts":[{"email":"nope@example.com"}]}')"
grep -q '^HTTP/1.1 403' "$WORK/mcp-headers" && grep -qi '^www-authenticate:.*insufficient_scope' "$WORK/mcp-headers" \
  && pass "contact tools need mcp:contacts:write (mcp:write is not enough)" || { echo "$denied" | head -c 600; fail "upsert without contacts scope"; }

fields="$(result "$(call "$read_token" mautic_list_contact_fields '{}')")"
jq -e '[.fields[] | select(.alias == "email" and .unique == true)] | length == 1' <<<"$fields" >/dev/null \
  && jq -e '[.fields[].alias] | index("firstname") and index("company")' <<<"$fields" >/dev/null \
  && pass "mautic_list_contact_fields (aliases, email is the unique identifier)" || { echo "$fields" | head -c 800; fail "mautic_list_contact_fields"; }

seg="$(result "$(call "$contacts_token" mautic_create_segment '{"name":"Twenty import","description":"Contacts from Twenty"}')")"
tsid="$(jq -r '.id // empty' <<<"$seg")"; talias="$(jq -r '.alias // empty' <<<"$seg")"
[[ "$tsid" =~ ^[0-9]+$ ]] && [[ "$(api "$BASE/api/segments/$tsid" | jq -r '.list.isPublished')" == true ]] \
  && pass "mautic_create_segment (static segment \"$talias\")" || { echo "$seg" | head -c 600; fail "mautic_create_segment"; }

batch='[{"email":"ada@example.com","fields":{"firstname":"Ada","lastname":"Lovelace","company":"Analytical GmbH","position":"CTO"},"tags":["twenty","customer"]},
        {"email":"bounce@example.com","fields":{"firstname":"Bounced","city":"Kiel"},"tags":["twenty"]},
        {"email":"grace@example.com","fields":{"firstname":"Grace","shoe_size":"42"}},
        {"email":"not-an-email","fields":{"firstname":"Broken"}}]'
dry="$(result "$(call "$contacts_token" mautic_upsert_contacts "{\"contacts\":$(jq -c . <<<"$batch"),\"segmentIds\":[$sid],\"dryRun\":true}")")"
jq -e --argjson c "$campid" '.dry_run == true and .counts == {"created": 1, "updated": 1, "unchanged": 0, "failed": 2}
  and ([.results[] | select(.status == "failed") | .error] | (.[0] | test("Unknown contact field.*shoe_size")) and (.[1] | test("Invalid email")))
  and ([.live_effects.running_campaigns[].id] | index($c) != null)' <<<"$dry" >/dev/null \
  && [[ "$(api "$BASE/api/contacts?search=ada@example.com" | jq -r '.total')" == 0 ]] \
  && pass "mautic_upsert_contacts dryRun: 1 new, 1 existing, 2 rejected (unknown field, invalid email), running campaign in live_effects, nothing saved" || { echo "$dry" | head -c 1500; fail "upsert dryRun"; }

saved="$(result "$(call "$contacts_token" mautic_upsert_contacts "{\"contacts\":$(jq -c '.[0:2]' <<<"$batch"),\"segmentIds\":[$tsid]}")")"
ada="$(api "$BASE/api/contacts?search=ada@example.com" | jq '.contacts | to_entries[0].value')"
jq -e '.counts.created == 1 and .counts.updated == 1' <<<"$saved" >/dev/null \
  && jq -e '.fields.all.company == "Analytical GmbH" and .fields.all.position == "CTO" and ([.tags[].tag] | sort == ["customer", "twenty"])' <<<"$ada" >/dev/null \
  && pass "mautic_upsert_contacts creates (fields, tags) and updates by email" || { echo "$saved $ada" | head -c 1500; fail "mautic_upsert_contacts"; }
bounced="$(result "$(call "$read_token" mautic_get_contact "{\"id\":$cid}")")"
jq -e '.fields.city == "Kiel" and .do_not_contact[0].reason == "bounced" and ([.segments[].id] | index('"$tsid"') != null)' <<<"$bounced" >/dev/null \
  && pass "existing contact updated and added to the segment; Do-Not-Contact (bounced) unchanged" || { echo "$bounced" | head -c 1000; fail "existing contact"; }
again="$(result "$(call "$contacts_token" mautic_upsert_contacts "{\"contacts\":$(jq -c '.[0:1]' <<<"$batch"),\"segmentIds\":[$tsid]}")")"
keep="$(result "$(call "$contacts_token" mautic_upsert_contacts '{"contacts":[{"email":"ada@example.com","fields":{"company":"Other AG","city":"Berlin"}}],"overwrite":false}')")"
jq -e '.counts.unchanged == 1' <<<"$again" >/dev/null && jq -e '.results[0].changed_fields == ["city"]' <<<"$keep" >/dev/null \
  && [[ "$(api "$BASE/api/contacts?search=ada@example.com" | jq -r '.contacts | to_entries[0].value.fields.all.company')" == "Analytical GmbH" ]] \
  && pass "same data again: unchanged; overwrite=false fills only empty fields" || { echo "$again $keep" | head -c 1000; fail "idempotence / overwrite=false"; }

ada_id="$(jq -r .id <<<"$ada")"
members() { result "$(call "$read_token" mautic_search_contacts "{\"search\":\"segment:$talias\",\"includeFields\":true}")"; }
jq -e '.total == 2 and ([.contacts[] | select(.email == "ada@example.com") | .fields.position] == ["CTO"])' <<<"$(members)" >/dev/null \
  && pass "export: mautic_search_contacts segment:$talias with includeFields" || { members | head -c 800; fail "mautic_search_contacts includeFields"; }
rm1="$(result "$(call "$contacts_token" mautic_remove_contacts_from_segment "{\"segmentId\":$tsid,\"contactIds\":[$ada_id]}")")"
add1="$(result "$(call "$contacts_token" mautic_add_contacts_to_segment "{\"segmentId\":$sid,\"contactIds\":[$ada_id],\"dryRun\":true}")")"
jq -e '.results[0].status == "removed"' <<<"$rm1" >/dev/null && [[ "$(members | jq .total)" == 1 ]] \
  && jq -e --argjson c "$campid" '.dry_run == true and .results[0].status == "added" and ([.live_effects.running_campaigns[].id] | index($c) != null)' <<<"$add1" >/dev/null \
  && pass "mautic_remove_contacts_from_segment; mautic_add_contacts_to_segment dryRun warns about the running campaign" || { echo "$rm1 $add1" | head -c 1200; fail "segment membership"; }

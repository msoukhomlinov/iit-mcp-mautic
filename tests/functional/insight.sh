# shellcheck shell=bash
# Insight tools (read-only): segment summary, duplicate finder, campaign health.
# Sourced by run.sh after the other tests (uses $read_token and $campid from core.sh).
seg_id="$(result "$(call "$read_token" mautic_list_segments '{}')" | jq -r '.segments[0].id')"
seg="$(result "$(call "$read_token" mautic_get_segment_summary "{\"id\":$seg_id,\"sampleSize\":2}")")"
jq -e --argjson i "$seg_id" '.id == $i and (.contacts | type == "number") and (.type == "static" or .type == "dynamic") and (.filters | type == "array")' <<<"$seg" >/dev/null \
  && pass "mautic_get_segment_summary (#$seg_id: $(jq -r '.type' <<<"$seg"), $(jq -r '.contacts' <<<"$seg") contacts)" || { echo "$seg" | head -c 800; fail "mautic_get_segment_summary"; }
jq -e '(.sample // []) | length <= 2' <<<"$seg" >/dev/null && pass "mautic_get_segment_summary honours sampleSize" || fail "sample too large"
[[ "$(call "$read_token" mautic_get_segment_summary '{"id":999999}' | jq -r '.result.isError')" == true ]] \
  && pass "mautic_get_segment_summary: unknown segment is an error" || fail "unknown segment accepted"

out="$(db_exec "INSERT INTO leads (date_added, is_published, points, email) VALUES (NOW(), 1, 0, 'dupe-check@example.com'), (NOW(), 1, 0, ' DUPE-Check@Example.com '), (NOW(), 1, 0, 'dupe-check@example.com')" 2>&1)" || { echo "$out" | head -c 600; fail "could not create duplicate contacts"; }
dup="$(result "$(call "$read_token" mautic_find_duplicate_contacts '{"field":"email"}')")"
jq -e '[.groups[] | select(.value == "dupe-check@example.com")] | length == 1 and .[0].contacts == 3 and (.[0].contact_ids | length == 3)' <<<"$dup" >/dev/null \
  && pass "mautic_find_duplicate_contacts groups case/space variants of an email" || { echo "$dup" | head -c 800; fail "mautic_find_duplicate_contacts"; }
[[ "$(call "$read_token" mautic_find_duplicate_contacts '{"field":"email; DROP TABLE leads"}' | jq -r '.result.isError')" == true ]] \
  && pass "mautic_find_duplicate_contacts rejects any field outside email/phone/mobile" || fail "field not whitelisted"
db_exec "DELETE FROM leads WHERE email LIKE '%dupe-check@example.com%'" >/dev/null 2>&1 || true

health="$(result "$(call "$read_token" mautic_get_campaign_health "{\"id\":$campid}")")"
jq -e --argjson i "$campid" '.id == $i and (.status == "ok" or .status == "attention") and (.contacts | type == "number") and (.steps | type == "number") and (.warnings | type == "array") and (.checked_at | test("Z$"))' <<<"$health" >/dev/null \
  && pass "mautic_get_campaign_health (status $(jq -r '.status' <<<"$health"), $(jq -r '.steps' <<<"$health") steps, $(jq -r '.contacts' <<<"$health") contacts)" || { echo "$health" | head -c 1000; fail "mautic_get_campaign_health"; }
[[ "$(call "$read_token" mautic_get_campaign_health '{"id":999999}' | jq -r '.result.isError')" == true ]] \
  && pass "mautic_get_campaign_health: unknown campaign is an error" || fail "unknown campaign accepted"

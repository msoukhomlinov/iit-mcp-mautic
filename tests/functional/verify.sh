# shellcheck shell=bash
# Regression checks for two review findings: view-own contact lists and deleting an email a campaign step uses.

echo "View-own contact list and email-in-campaign delete"
# (A) A view-own user: Mautic grants access to a contact by its owner (Lead::getPermissionUser),
# so the list must show contacts the user owns, not contacts the user created and that now belong to someone else.
vrid="$(api -X POST "$BASE/api/roles/new" -d name='Own contacts (verify)' -d isAdmin=0 \
  -d 'rawPermissions[lead:leads][]=viewown' -d 'rawPermissions[lead:leads][]=create' -d 'rawPermissions[lead:leads][]=editown' | jq -r '.role.id')"
vuid="$(api -X POST "$BASE/api/users/new" -d username=ai-own -d firstName=AI -d lastName=Own -d email=ai-own@example.com \
  -d "role=$vrid" -d 'plainPassword[password]=Ai-0wn-Passw0rd!' -d 'plainPassword[confirm]=Ai-0wn-Passw0rd!' | jq -r '.user.id')"
# created by ai-own, then reassigned to the admin
c_created="$(curl -s -u 'ai-own:Ai-0wn-Passw0rd!' -H 'Accept: application/json' -X POST "$BASE/api/contacts/new" -d email=verify-created@example.com | jq -r '.contact.id')"
api -X PATCH "$BASE/api/contacts/$c_created/edit" -d owner=1 >/dev/null
# created by the admin, owned by ai-own
c_owned="$(api -X POST "$BASE/api/contacts/new" -d email=verify-owned@example.com -d "owner=$vuid" | jq -r '.contact.id')"
# created by ai-own, no owner: Mautic's single-item check grants it to the creator
c_mine="$(curl -s -u 'ai-own:Ai-0wn-Passw0rd!' -H 'Accept: application/json' -X POST "$BASE/api/contacts/new" -d email=verify-mine@example.com | jq -r '.contact.id')"
[[ "$(db "SELECT created_by, owner_id FROM leads WHERE id = $c_created")" == "$vuid	1" && "$(db "SELECT created_by, owner_id FROM leads WHERE id = $c_owned")" == "1	$vuid" && "$(db "SELECT created_by, owner_id FROM leads WHERE id = $c_mine")" == "$vuid	NULL" ]] \
  || fail "verify setup: contacts not created with the expected createdBy/owner"
admin_jar="$JAR"; JAR="$WORK/own-jar"
login ai-own 'Ai-0wn-Passw0rd!'
own_token="$(access_token 'mcp:read')"
# What Mautic itself lists for this user (contacts index, logged in as ai-own).
ui="$(curl -s -b "$JAR" "$BASE/s/contacts?search=verify")"
JAR="$admin_jar"
ui_ids="$(grep -o 'verify-\(created\|owned\|mine\)@example.com' <<<"$ui" | sort -u | tr '\n' ' ')"
[[ "$ui_ids" == "verify-owned@example.com " ]] \
  && pass "Mautic's contact list for a view-own user is owner-based (owned only)" || { echo "Mautic UI shows: '$ui_ids'"; fail "Mautic view-own baseline"; }
mcp_init "$own_token"
listed="$(result "$(call "$own_token" mautic_search_contacts '{"search":"verify"}')")"
single="$(result "$(call "$own_token" mautic_get_contact "{\"id\":$c_mine}")")"
jq -e --argjson o "$c_owned" --argjson m "$c_mine" '([.contacts[].id] | sort) == ([$o, $m] | sort) and .total == 2' <<<"$listed" >/dev/null && jq -e --argjson m "$c_mine" '.id == $m' <<<"$single" >/dev/null \
  && pass "mautic_search_contacts for a view-own user: owned plus own unowned contacts, not reassigned ones (same rule as mautic_get_contact)" || { echo "$listed | $single" | head -c 800; fail "view-own contact list"; }

# (B) An unpublished email used by a campaign step cannot be deleted.
set_params '{"intellectit_mcp_allow_destructive": true}'
mcp_init "$access"
vem="$(api -X POST "$BASE/api/emails/new" -d name=VerifyStepEmail -d subject=x -d emailType=template -d isPublished=0 -d 'customHtml=<p>x</p>' | jq -r '.email.id')"
vcamp="$(result "$(call "$access" mautic_create_campaign_draft '{"name":"Verify step email","segmentIds":['"$sid"']}')" | jq -r '.id')"
call "$access" mautic_set_campaign_steps "{\"id\":$vcamp,\"steps\":[{\"key\":\"s\",\"type\":\"email.send\",\"properties\":{\"email\":$vem,\"email_type\":\"marketing\"}}]}" >/dev/null
[[ "$(db "SELECT COUNT(*) FROM campaign_events WHERE campaign_id = $vcamp AND channel = 'email' AND channel_id = $vem")" == 1 ]] || fail "verify setup: campaign step not stored"
vtoken="$(access_token 'mcp:read mcp:destructive' mcp:destructive)"
mcp_init "$vtoken"
vprev="$(result "$(call "$vtoken" mautic_delete_record "{\"type\":\"email\",\"id\":$vem}")")"
vdel="$(call "$vtoken" mautic_delete_record "{\"type\":\"email\",\"id\":$vem,\"confirm\":true,\"expectedName\":\"VerifyStepEmail\"}")"
jq -e '.can_delete == false' <<<"$vprev" >/dev/null && grep -q 'BLOCKED_BY_POLICY' <<<"$vdel" && grep -q 'campaign' <<<"$vdel" \
  && [[ "$(db "SELECT COUNT(*) FROM emails WHERE id = $vem")" == 1 ]] \
  && pass "an email used by a campaign step cannot be deleted (BLOCKED_BY_POLICY, preview can_delete=false)" || { echo "$vprev | $vdel" | head -c 800; fail "email used by a campaign step deleted"; }
set_params '{"intellectit_mcp_allow_destructive": false}'
mcp_init "$access"

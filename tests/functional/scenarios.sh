# shellcheck shell=bash disable=SC2154,SC2034  # shares variables with run.sh
# Realistic campaign scenarios built only through the MCP tools, the way an
# assistant would. Sourced by run.sh (uses its helpers: call, result,
# api, pass, fail, BASE, access, console, db).
#
#   A  Trial onboarding (16 steps): opens/clicks, lead scoring, hand-over to
#      sales, re-activation; then executed by Mautic incl. a real email open
#   B  NIS2 webinar follow-up: two entry points, country condition with
#      German/English branches, stage change, sales notification

echo "MCP scenarios (realistic campaign flows)"
sc_email() { # $1 name, $2 subject, [$3 language] -> id (template email, published by a person)
  local id
  id="$(result "$(call "$access" mautic_create_email_draft "$(jq -nc --arg n "$1" --arg s "$2" --arg l "${3:-de}" \
    '{name: $n, subject: $s, type: "template", language: $l,
      html: ("<html><body><h1>" + $s + "</h1><p><a href=\"https://kopexa.com/de\">kopexa.com</a></p>{unsubscribe_text}</body></html>")}')")" | jq -r '.id // empty')"
  [[ "$id" =~ ^[0-9]+$ ]] || fail "mautic_create_email_draft $1"
  api -X PATCH "$BASE/api/emails/$id/edit" -d isPublished=1 >/dev/null
  echo "$id"
}
sc_set() { # $1 campaign id, $2 steps JSON, [$3 extra args JSON] -> tool result text
  result "$(call "$access" mautic_set_campaign_steps "$(jq -nc --argjson id "$1" --argjson s "$2" --argjson x "${3:-{\}}" '{id: $id, steps: $s} + $x')")"
}
sc_sql() { db "$1"; }

# --- A: trial onboarding ----------------------------------------------------
seg_trial="$(api -X POST "$BASE/api/segments/new" -d name='Trial-Anmeldungen' -d isPublished=1 | jq -r .list.id)"
seg_sales="$(api -X POST "$BASE/api/segments/new" -d name='Sales-ready' -d isPublished=1 | jq -r .list.id)"
e_welcome="$(sc_email 'Trial 01 – Willkommen' 'Willkommen bei Kopexa – so starten Sie')"
e_guide="$(sc_email 'Trial 02 – ISO-27001-Fahrplan' 'Ihr Fahrplan zur ISO 27001 in 90 Tagen')"
e_case="$(sc_email 'Trial 03 – Case Study' 'Wie ein Mittelständler NIS2 in 6 Wochen umsetzte')"
e_demo="$(sc_email 'Trial 04 – Demo' '15 Minuten: Ihre persönliche Kopexa-Demo')"
e_remind="$(sc_email 'Trial 05 – Erinnerung' 'Schon reingeschaut? Ihr Trial wartet')"
e_sales="$(sc_email 'Intern – Sales-ready Lead' 'Neuer Sales-ready Lead aus dem Trial')"
camp_a="$(result "$(call "$access" mautic_create_campaign_draft "{\"name\":\"Trial-Onboarding (14 Tage)\",\"segmentIds\":[$seg_trial],\"description\":\"Aktivierung, Lead-Scoring, Übergabe an den Vertrieb, Reaktivierung.\"}")" | jq -r .id)"
flow_a="$(jq -nc --argjson w "$e_welcome" --argjson g "$e_guide" --argjson c "$e_case" --argjson d "$e_demo" \
  --argjson r "$e_remind" --argjson s "$e_sales" --argjson sales "$seg_sales" '[
  {key: "welcome",         type: "email.send",            name: "Willkommens-Mail",         properties: {email: $w, email_type: "marketing"}},
  {key: "opened_welcome",  type: "email.open",            name: "Willkommen geöffnet?",     parent: "welcome"},
  {key: "tag_active",      type: "lead.changetags",       name: "Tag: trial-aktiv",         parent: "opened_welcome", path: "yes", properties: {add_tags: ["trial-aktiv"]}},
  {key: "guide",           type: "email.send",            name: "ISO-27001-Fahrplan",       parent: "tag_active", wait: {interval: 2, unit: "d"}, properties: {email: $g, email_type: "marketing"}},
  {key: "clicked_guide",   type: "email.click",           name: "Fahrplan geklickt?",       parent: "guide"},
  {key: "points_hot",      type: "lead.changepoints",     name: "+20 Punkte (Interesse)",   parent: "clicked_guide", path: "yes", properties: {points: 20}},
  {key: "is_hot",          type: "lead.points",           name: "Score ≥ 30?",              parent: "points_hot", properties: {operator: "gte", score: 30}},
  {key: "sales_ready",     type: "lead.changelist",       name: "→ Segment Sales-ready",    parent: "is_hot", path: "yes", properties: {addToLists: [$sales]}},
  {key: "notify_sales",    type: "email.send.to.user",    name: "Vertrieb benachrichtigen", parent: "sales_ready", properties: {useremail: {email: $s}, user_id: [1]}},
  {key: "case_study",      type: "email.send",            name: "Case Study NIS2",          parent: "is_hot", path: "no", wait: {interval: 1, unit: "d"}, properties: {email: $c, email_type: "marketing"}},
  {key: "demo_offer",      type: "email.send",            name: "Demo anbieten",            parent: "clicked_guide", path: "no", wait: {interval: 4, unit: "d"}, properties: {email: $d, email_type: "marketing"}},
  {key: "reminder",        type: "email.send",            name: "Erinnerung",               parent: "opened_welcome", path: "no", wait: {interval: 3, unit: "d"}, properties: {email: $r, email_type: "marketing"}},
  {key: "opened_reminder", type: "email.open",            name: "Erinnerung geöffnet?",     parent: "reminder"},
  {key: "points_warm",     type: "lead.changepoints",     name: "+5 Punkte",                parent: "opened_reminder", path: "yes", properties: {points: 5}},
  {key: "tag_cold",        type: "lead.changetags",       name: "Tag: trial-kalt",          parent: "opened_reminder", path: "no", wait: {interval: 7, unit: "d"}, properties: {add_tags: ["trial-kalt"], remove_tags: ["trial-aktiv"]}},
  {key: "leave",           type: "campaign.addremovelead", name: "Aus Kampagne entfernen",  parent: "tag_cold", properties: {removeFrom: ["this"]}}
]')"
dry="$(sc_set "$camp_a" "$flow_a" '{"dryRun":true}')"
jq -e '(.changes.added | length == 16) and .tags_to_create == ["trial-aktiv","trial-kalt"]' <<<"$dry" >/dev/null \
  && pass "A: dry run of a 16-step trial onboarding (2 tags would be created)" || { echo "$dry" | head -c 1500; fail "A: dry run"; }
saved="$(sc_set "$camp_a" "$flow_a")"
jq -e '(.steps | length == 16) and ([.steps[] | select(.type == "lead.changetags")][0].properties.add_tags == ["trial-aktiv"])
       and ([.steps[] | select(.path == "no" and .type == "email.send")] | length == 3)' <<<"$saved" >/dev/null \
  && pass "A: saved: 16 steps, 4 decisions/conditions with yes/no branches, waits, tags by name" || { echo "$saved" | head -c 2000; fail "A: save"; }
again="$(sc_set "$camp_a" "$(jq -c '[.steps[] | del(.kind, .contacts)]' <<<"$saved")" '{"dryRun":true}')"
jq -e '(.changes.unchanged | length == 16)' <<<"$again" >/dev/null && pass "A: round trip of the saved flow is a no-op" || { echo "$again" | head -c 1500; fail "A: round trip"; }

# Run it: publish (a person's step), a trial user signs up, Mautic's cron runs.
api -X PATCH "$BASE/api/campaigns/$camp_a/edit" -d isPublished=1 >/dev/null
lead_a="$(api -X POST "$BASE/api/contacts/new" -d email=anna.trial@example.com -d firstname=Anna -d country=Germany | jq -r .contact.id)"
api -X POST "$BASE/api/segments/$seg_trial/contact/$lead_a/add" >/dev/null
sc_console() { console "$@" 2>&1; }
sc_console mautic:campaigns:update -i "$camp_a" >/dev/null
sc_console mautic:campaigns:trigger -i "$camp_a" >/dev/null
hash="$(sc_sql "SELECT tracking_hash FROM email_stats WHERE email_id = $e_welcome AND lead_id = $lead_a")"
[[ -n "$hash" ]] && pass "A: Mautic sent the welcome email to the trial user" || fail "A: welcome email not sent"
# Anna opens the email (Mautic's real tracking pixel), the hit queue is processed.
pixel="$(curl -s -o /dev/null -w '%{http_code}' -A 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/605.1.15' "$BASE/email/$hash.gif")"
console messenger:consume email hit --time-limit=8 >/dev/null 2>&1 || true
sc_console mautic:campaigns:trigger -i "$camp_a" >/dev/null
tags="$(api "$BASE/api/contacts/$lead_a" | jq -c '[.contact.tags[].tag]')"
live_a="$(result "$(call "$access" mautic_get_campaign "{\"id\":$camp_a}")")"
[[ "$tags" == '["trial-aktiv"]' ]] && jq -e '[.steps[] | select(.name == "ISO-27001-Fahrplan") | .contacts.scheduled] == [1]' <<<"$live_a" >/dev/null \
  && pass "A: email opened -> yes branch: tagged trial-aktiv, roadmap email scheduled in 2 days" \
  || { echo "tags=$tags pixel=$pixel"; db "SELECT is_read, open_count, date_read FROM email_stats WHERE tracking_hash = '$hash'"; db "SELECT ip_address FROM ip_addresses"; jq -c '[.steps[] | {name, contacts}]' <<<"$live_a" | head -c 1500; fail "A: yes branch after the open"; }

# --- B: NIS2 webinar follow-up ------------------------------------------------
seg_web="$(api -X POST "$BASE/api/segments/new" -d name='Webinar NIS2 – Teilnehmer' -d isPublished=1 | jq -r .list.id)"
stage_mql="$(api -X POST "$BASE/api/stages/new" -d name='MQL' -d weight=20 -d isPublished=1 | jq -r .stage.id)"
e_de="$(sc_email 'Webinar NIS2 – Danke (DE)' 'Danke für Ihre Teilnahme: Folien und Aufzeichnung')"
e_en="$(sc_email 'Webinar NIS2 – Thanks (EN)' 'Thank you for joining: slides and recording' en)"
e_fde="$(sc_email 'Webinar NIS2 – Nachfass (DE)' 'Ihre NIS2-Checkliste zum Webinar')"
e_fen="$(sc_email 'Webinar NIS2 – Follow-up (EN)' 'Your NIS2 checklist from the webinar' en)"
camp_b="$(result "$(call "$access" mautic_create_campaign_draft "{\"name\":\"Webinar-Follow-up NIS2\",\"segmentIds\":[$seg_web]}")" | jq -r .id)"
flow_b="$(jq -nc --argjson de "$e_de" --argjson en "$e_en" --argjson fde "$e_fde" --argjson fen "$e_fen" \
  --argjson mql "$stage_mql" --argjson s "$e_sales" '[
  {key: "is_de",       type: "lead.field_value",   name: "Land = Deutschland?",    properties: {field: "country", operator: "=", value: "Germany"}},
  {key: "thanks_de",   type: "email.send",         name: "Danke (DE)",             parent: "is_de", path: "yes", properties: {email: $de, email_type: "marketing"}},
  {key: "click_de",    type: "email.click",        name: "Folien geklickt?",       parent: "thanks_de"},
  {key: "mql_de",      type: "stage.change",       name: "Stage → MQL",            parent: "click_de", path: "yes", properties: {stage: $mql}},
  {key: "follow_de",   type: "email.send",         name: "NIS2-Checkliste (DE)",   parent: "click_de", path: "no", wait: {interval: 5, unit: "d"}, properties: {email: $fde, email_type: "marketing"}},
  {key: "thanks_en",   type: "email.send",         name: "Thanks (EN)",            parent: "is_de", path: "no", properties: {email: $en, email_type: "marketing"}},
  {key: "click_en",    type: "email.click",        name: "Slides clicked?",        parent: "thanks_en"},
  {key: "mql_en",      type: "stage.change",       name: "Stage → MQL",            parent: "click_en", path: "yes", properties: {stage: $mql}},
  {key: "follow_en",   type: "email.send",         name: "NIS2 checklist (EN)",    parent: "click_en", path: "no", wait: {interval: 5, unit: "d"}, properties: {email: $fen, email_type: "marketing"}},
  {key: "pricing",     type: "page.pagehit",       name: "Preisseite besucht?",    properties: {url: "*kopexa.com/*/preise*"}},
  {key: "points",      type: "lead.changepoints",  name: "+15 Punkte",             parent: "pricing", path: "yes", properties: {points: 15}},
  {key: "tag_pricing", type: "lead.changetags",    name: "Tag: preis-interesse",   parent: "points", properties: {add_tags: ["preis-interesse"]}},
  {key: "notify",      type: "email.send.to.user", name: "Vertrieb informieren",   parent: "tag_pricing", properties: {useremail: {email: $s}, to_owner: 1, user_id: [1]}}
]')"
saved_b="$(sc_set "$camp_b" "$flow_b")"
jq -e '(.steps | length == 13) and ([.steps[] | select(.parent == null)] | length == 2)' <<<"$saved_b" >/dev/null \
  && pass "B: webinar follow-up: 13 steps, 2 entry points, DE/EN branches, stage change, sales notification" || { echo "$saved_b" | head -c 2000; fail "B: save"; }
api -X PATCH "$BASE/api/campaigns/$camp_b/edit" -d isPublished=1 >/dev/null
for who in 'max.mueller@example.com Germany' 'jane.doe@example.com Ireland'; do
  read -r mail country <<<"$who"
  id="$(api -X POST "$BASE/api/contacts/new" -d "email=$mail" -d "country=$country" | jq -r .contact.id)"
  api -X POST "$BASE/api/segments/$seg_web/contact/$id/add" >/dev/null
done
sc_console mautic:campaigns:update -i "$camp_b" >/dev/null
sc_console mautic:campaigns:trigger -i "$camp_b" >/dev/null
sent_de="$(sc_sql "SELECT COUNT(*) FROM email_stats s JOIN leads l ON l.id = s.lead_id WHERE s.email_id = $e_de AND l.email = 'max.mueller@example.com'")"
sent_en="$(sc_sql "SELECT COUNT(*) FROM email_stats s JOIN leads l ON l.id = s.lead_id WHERE s.email_id = $e_en AND l.email = 'jane.doe@example.com'")"
wrong="$(sc_sql "SELECT COUNT(*) FROM email_stats s JOIN leads l ON l.id = s.lead_id WHERE (s.email_id = $e_de AND l.email = 'jane.doe@example.com') OR (s.email_id = $e_en AND l.email = 'max.mueller@example.com')")"
[[ "$sent_de/$sent_en/$wrong" == "1/1/0" ]] \
  && pass "B: condition routes by country: German contact got the DE email, Irish contact the EN email" || fail "B: country branches (de=$sent_de en=$sent_en wrong=$wrong)"
SCENARIO_INFO="scenario_a=$camp_a scenario_b=$camp_b"

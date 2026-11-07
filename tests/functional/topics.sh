# shellcheck shell=bash disable=SC2154,SC2034  # shares variables with run.sh
# Email topics (Mautic categories): recipients switch them off in their
# email preferences, so every marketing email needs one. Sourced by run.sh
# (uses tool, api, pass, fail, BASE, access).

echo "MCP email topics"
for t in 'Webinare & Events' 'Produkt-Updates'; do api -X POST "$BASE/api/categories/new" --data-urlencode "title=$t" -d bundle=global -d isPublished=1 >/dev/null; done
topics="$(tool "$access" mautic_list_email_topics '{}')"
jq -e '[.topics[].name] | index("Webinare & Events") and index("Produkt-Updates")' <<<"$topics" >/dev/null \
  && pass "mautic_list_email_topics" || { echo "$topics"; fail "mautic_list_email_topics"; }
# (The layout tools share EmailTopics; they need a theme with email layouts, not installed here.)
with="$(tool "$access" mautic_create_email_draft '{"name":"Topic","subject":"x","html":"<p>x</p>","topic":"webinare & events"}')"
without="$(tool "$access" mautic_create_email_draft '{"name":"No topic","subject":"x","html":"<p>x</p>"}')"
jq -e '.topic == "Webinare & Events" and (has("topic_warning") | not)' <<<"$with" >/dev/null && jq -e '.topic == null and (.topic_warning | test("No topic"))' <<<"$without" >/dev/null \
  && [[ "$(api "$BASE/api/emails/$(jq -r .id <<<"$with")" | jq -r .email.category.title)" == "Webinare & Events" ]] \
  && pass "topic set by name (stored as the email's category); warning without one" || { echo "$with $without" | head -c 800; fail "email topic"; }
nid="$(jq -r .id <<<"$without")"
[[ "$(tool "$access" mautic_update_email_draft "{\"id\":$nid,\"topic\":\"Produkt-Updates\"}" | jq -r .topic)" == Produkt-Updates ]] \
  && [[ "$(tool "$access" mautic_get_email "{\"id\":$nid,\"includeHtml\":false}" | jq -r .topic)" == Produkt-Updates ]] \
  && [[ "$(tool "$access" mautic_update_email_draft "{\"id\":$nid,\"topic\":\"\"}" | jq -r .topic)" == null ]] \
  && pass "update sets and clears the topic; mautic_get_email shows it" || fail "update topic"
[[ "$(tool "$access" mautic_create_email_draft '{"name":"x","subject":"x","html":"<p>x</p>","topic":"Gibtsnicht"}')" == "ERROR: [INVALID_INPUT] Unknown topic"*"Produkt-Updates"* ]] \
  && pass "unknown topics are refused with the list of topics" || fail "unknown topic accepted"

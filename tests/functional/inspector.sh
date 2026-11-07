#!/usr/bin/env bash
# Protocol check with the official MCP Inspector CLI against the live server (not part of run.sh:
# it downloads the Inspector with npx). Run it like run.sh, e.g. RUN=bundle/tests/functional/inspector.sh.
source "$(dirname "$0")/lib.sh"
INSPECTOR='@modelcontextprotocol/inspector@2.10.1'

install_mautic
configure_oauth
start_server
login >/dev/null
token="$(access_token 'mcp:read')"

# --header is variadic in the v2 CLI, so it goes last.
insp() { npx -y "$INSPECTOR" --cli "$BASE/mcp" --transport http "$@" --header "Authorization: Bearer $token"; }

list="$(insp --method tools/list 2>"$WORK/insp.err")" && n="$(jq '.tools | length' <<<"$list")" \
  && [[ "$n" -ge 20 ]] && pass "Inspector CLI: initialize + tools/list over Streamable HTTP ($n tools)" \
  || { cat "$WORK/insp.err" | head -20; echo "$list" | head -c 400; fail "Inspector tools/list"; }
call="$(insp --method tools/call --tool-name mautic_list_segments 2>"$WORK/insp.err")" \
  && jq -e '.content[0].type == "text"' <<<"$call" >/dev/null \
  && pass "Inspector CLI: tools/call returns a text result" || { head -20 "$WORK/insp.err"; echo "$call" | head -c 400; fail "Inspector tools/call"; }
bad="$(insp --method tools/call --tool-name mautic_get_contact --tool-arg id=999999 2>&1)" || true
grep -q 'NOT_FOUND' <<<"$bad" && pass "Inspector CLI: an unknown contact is a coded tool error" || { echo "$bad" | head -c 500; fail "Inspector error shape"; }
echo "inspector checks passed"

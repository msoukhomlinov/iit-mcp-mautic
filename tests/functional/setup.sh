#!/usr/bin/env bash
# Setup command and status tool. Run like inspector.sh: RUN=bundle/tests/functional/setup.sh
source "$(dirname "$0")/lib.sh"

install_mautic

echo "Setup command"
out="$(console mautic:mcp:setup --check 2>&1)" && rc=0 || rc=$?
[[ $rc -ne 0 ]] && grep -q 'FAIL.*oauth signing key' <<<"$out" && pass "--check on a fresh install fails and names the missing key" \
  || { echo "$out"; fail "--check before setup"; }

out="$(console mautic:mcp:setup --redirect-host=Example.org 2>&1)" \
  && grep -q 'kopexa_oauth_private_key' <<<"$out" && ! grep -q 'BEGIN' <<<"$out" \
  && pass "setup writes the keys and does not print them" || { echo "$out"; fail "setup"; }
rm -rf "$MAUTIC_DIR"/var/cache/*
php -r '
  include $argv[1]."/config/local.php";
  $k = openssl_pkey_get_private($parameters["kopexa_oauth_private_key"]);
  exit($k && openssl_pkey_get_details($k)["bits"] >= 2048 && strlen($parameters["kopexa_oauth_encryption_key"]) >= 32
    && in_array("example.org", $parameters["kopexa_oauth_redirect_hosts"], true) ? 0 : 1);
' "$MAUTIC_DIR" && pass "local.php holds a valid RSA key, a long secret and the added host" || fail "local.php content"
[[ "$(stat -c %a "$MAUTIC_DIR/config/local.php" 2>/dev/null || stat -f %Lp "$MAUTIC_DIR/config/local.php")" == 640 ]] \
  && pass "local.php is not world-readable" || fail "local.php mode"

before="$(md5sum "$MAUTIC_DIR/config/local.php" | cut -d' ' -f1)"
console mautic:mcp:setup >/dev/null 2>&1; rm -rf "$MAUTIC_DIR"/var/cache/*
[[ "$before" == "$(md5sum "$MAUTIC_DIR/config/local.php" | cut -d' ' -f1)" ]] \
  && pass "a second run keeps the existing keys" || fail "keys replaced without --force"

out="$(console mautic:mcp:setup --check 2>&1)" && rc=0 || rc=$?
grep -q 'OK    oauth signing key' <<<"$out" && grep -q 'OK    oauth encryption key' <<<"$out" \
  && pass "--check passes the key checks after setup" || { echo "$out"; fail "--check after setup"; }

# The README says Mautic's own API setting is not needed: prove it.
set_params '{"api_enabled": false, "api_enable_basic_auth": false}'
start_server
login >/dev/null
tok="$(access_token 'mcp:read')"
mcp_init "$tok"
res="$(mcp_post "$tok" '{"jsonrpc":"2.0","id":9,"method":"tools/call","params":{"name":"mautic_setup_status","arguments":{}}}')"
jq -e '.result.content[0].text | fromjson | .endpoint == ($b + "/mcp") and (.checks | length) > 3' --arg b "$BASE" <<<"$res" >/dev/null \
  && pass "mautic_setup_status works for an administrator with Mautic's API setting OFF" || { echo "$res" | head -c 500; fail "setup status tool"; }
! grep -q 'BEGIN' <<<"$res" && pass "the status tool never returns key material" || fail "key leaked"
echo "setup checks passed"

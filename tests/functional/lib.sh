# shellcheck shell=bash
# Shared helpers for the functional tests (same file in all Kopexa Mautic bundles).
#
# Needs: MAUTIC_DIR = a Composer-based Mautic 7 with this plugin installed,
# a MySQL/MariaDB (DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASSWORD), php,
# curl, jq, openssl. Mautic is served by PHP's built-in web server.
set -euo pipefail

PORT="${PORT:-8080}"
BASE="http://127.0.0.1:$PORT"
ADMIN_PW='Functional-Test-Passw0rd!'
WORK="$(mktemp -d)"
JAR="$WORK/jar"

pass() { printf '  \033[32mok\033[0m   %s\n' "$*"; }
fail() {
  printf '  \033[31mFAIL\033[0m %s\n' "$*"
  echo "--- server log"; tail -30 "$WORK/server.log" 2>/dev/null || true
  echo "--- mautic log"; tail -20 "$MAUTIC_DIR"/var/logs/*.php 2>/dev/null | cut -c1-600 || true
  exit 1
}
console() { php -d memory_limit=-1 "$MAUTIC_DIR/bin/console" "$@"; }

# Merge JSON into Mautic's config/local.php parameters, then clear the cache.
# The test server runs with opcache.revalidate_freq=0, so it sees the change at once.
set_params() {
  php -r '
    $file = $argv[1]."/config/local.php";
    $parameters = [];
    if (is_file($file)) { include $file; }
    $parameters = array_replace($parameters, json_decode($argv[2], true, 512, JSON_THROW_ON_ERROR));
    file_put_contents($file, "<?php\n\$parameters = ".var_export($parameters, true).";\n");
  ' "$MAUTIC_DIR" "$1"
  rm -rf "$MAUTIC_DIR"/var/cache/*
}

install_mautic() {
  echo "install Mautic ($(php -r 'echo PHP_VERSION;'))"
  console mautic:install "$BASE" --force \
    --db_host="$DB_HOST" --db_port="${DB_PORT:-3306}" --db_name="$DB_NAME" \
    --db_user="$DB_USER" --db_password="$DB_PASSWORD" \
    --admin_username=admin --admin_email=admin@example.com --admin_password="$ADMIN_PW" \
    --admin_firstname=Admin --admin_lastname=Test >"$WORK/install.log" 2>&1 \
    || { tail -30 "$WORK/install.log"; fail "mautic:install"; }
  set_params '{"api_enabled": true, "api_enable_basic_auth": true, "mailer_dsn": "null://null"}'
  console mautic:plugins:reload >/dev/null || fail "mautic:plugins:reload"
  pass "Mautic installed, plugins reloaded"
}

start_server() {
  cat >"$MAUTIC_DIR/mcp-router.php" <<'PHP'
<?php
// Router for PHP's built-in server: static files as is, everything else to Mautic.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
if ('/' !== $path && !str_ends_with($path, '.php') && is_file(__DIR__.'/docroot'.$path)) {
    return false;
}
$_SERVER['SCRIPT_NAME']     = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = __DIR__.'/docroot/index.php';
chdir(__DIR__.'/docroot');
require __DIR__.'/docroot/index.php';
PHP
  (cd "$MAUTIC_DIR/docroot" && PHP_CLI_SERVER_WORKERS=4 php -d memory_limit=512M -d opcache.revalidate_freq=0 -S "127.0.0.1:$PORT" ../mcp-router.php >"$WORK/server.log" 2>&1 &)
  for _ in $(seq 1 60); do
    [[ "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/s/login")" == 200 ]] && { pass "web server up"; return; }
    sleep 1
  done
  fail "web server did not start"
}

login() { # [$1 user] [$2 password]
  local csrf
  csrf="$(curl -s -c "$JAR" -b "$JAR" "$BASE/s/login" | sed -n 's/.*name="_csrf_token" value="\([^"]*\)".*/\1/p' | head -1)"
  [[ -n "$csrf" ]] || fail "no CSRF token on the login page"
  curl -s -o /dev/null -c "$JAR" -b "$JAR" -H "Origin: $BASE" -H "Referer: $BASE/s/login" \
    --data-urlencode "_username=${1:-admin}" --data-urlencode "_password=${2:-$ADMIN_PW}" \
    --data-urlencode "_csrf_token=$csrf" "$BASE/s/login_check"
}

api() { curl -s -u "admin:$ADMIN_PW" -H 'Accept: application/json' "$@"; }

b64url() { base64 -w0 | tr '+/' '-_' | tr -d '='; }
b64url_decode() { local s; s="$(tr '_-' '/+' <<<"$1")"; while (( ${#s} % 4 )); do s+='='; done; base64 -d <<<"$s"; }

# OAuth 2.1 with kopexa/mautic-oauth-bundle: generate and configure keys.
OAUTH_KEY="$WORK/oauth.pem"
configure_oauth() {
  openssl genrsa -out "$OAUTH_KEY" 2048 2>/dev/null
  set_params "$(jq -n --rawfile k "$OAUTH_KEY" --arg e "$(openssl rand -hex 32)" \
    '{kopexa_oauth_private_key: $k, kopexa_oauth_encryption_key: $e}')"
}

REDIRECT="http://127.0.0.1:33418/callback"
oauth_client() {
  curl -s -X POST "$BASE/oauth2/register" -H 'Content-Type: application/json' \
    -d "{\"client_name\":\"Functional test\",\"redirect_uris\":[\"$REDIRECT\"]}" | jq -r .client_id
}
# authorize <client> <challenge> <method> <scopes>: writes the response to $WORK/page, prints "code redirect"
authorize() {
  curl -s -G -o "$WORK/page" -w '%{http_code} %{redirect_url}' -b "$JAR" -c "$JAR" "$BASE/s/oauth2/authorize" \
    --data-urlencode response_type=code --data-urlencode "client_id=$1" --data-urlencode "redirect_uri=$REDIRECT" \
    --data-urlencode "code_challenge=$2" --data-urlencode "code_challenge_method=$3" \
    --data-urlencode state=functional --data-urlencode "scope=$4" --data-urlencode "resource=$BASE/mcp"
}
# consent <approve|deny> [extra scopes to tick]: posts the consent form like a browser, prints the redirect
# form_fields <html file> [scopes to tick]: what a browser submits for the
# consent page's <form>: its inputs only (fields outside the form are not
# sent), checkboxes only if ticked and not disabled; extra ticks only for
# checkboxes that exist in the form.
form_fields() {
  php -r '
    $d = new DOMDocument(); @$d->loadHTML((string) file_get_contents($argv[1]));
    $x = new DOMXPath($d); $tick = array_slice($argv, 2);
    foreach ($x->query("//form[@method=\"post\"]//input") as $i) {
      $n = $i->getAttribute("name"); $t = strtolower($i->getAttribute("type"));
      if ("" === $n || $i->hasAttribute("disabled")) { continue; }
      if (in_array($t, ["checkbox", "radio"], true) && !$i->hasAttribute("checked") && !in_array($i->getAttribute("value"), $tick, true)) { continue; }
      echo $n, "=", $i->getAttribute("value"), "\n";
    }' "$@"
}
consent() {
  local args=() f
  # shellcheck disable=SC2086  # $2: space-separated scopes to tick
  while IFS= read -r f; do [[ -n "$f" ]] && args+=(--data-urlencode "$f"); done < <(form_fields "$WORK/page" ${2:-})
  curl -s -o /dev/null -w '%{redirect_url}' -b "$JAR" -c "$JAR" -X POST "$BASE/s/oauth2/authorize" \
    --data-urlencode "decision=$1" "${args[@]}"
}
# token <client> <code> <verifier>: writes the response to $WORK/token, prints the HTTP status
token() {
  curl -s -o "$WORK/token" -w '%{http_code}' -X POST "$BASE/oauth2/token" \
    --data-urlencode grant_type=authorization_code --data-urlencode "client_id=$1" --data-urlencode "code=$2" \
    --data-urlencode "redirect_uri=$REDIRECT" --data-urlencode "code_verifier=$3"
}
# access_token <scopes> [extra scopes to tick]: full flow as the logged-in admin, prints the access token
access_token() {
  local client verifier challenge location code
  client="$(oauth_client)"
  verifier="$(openssl rand -base64 48 | tr '+/' '-_' | tr -d '=\n')"
  challenge="$(printf '%s' "$verifier" | openssl dgst -sha256 -binary | b64url)"
  authorize "$client" "$challenge" S256 "$1" >/dev/null
  location="$(consent approve "${2:-}")"
  code="$(sed -n 's/.*[?&]code=\([^&]*\).*/\1/p' <<<"$location")"
  [[ "$(token "$client" "$code" "$verifier")" == 200 ]] || fail "access token ($1)"
  jq -r .access_token "$WORK/token"
}

# MCP (intellectit/mautic-mcp-bundle), Streamable HTTP with a session.
MCP_SESSION=""
mcp_post() { # <token> <json-rpc body>: prints the JSON-RPC message
  local out
  out="$(curl -s -D "$WORK/mcp-headers" -X POST "$BASE/mcp" -H "Authorization: Bearer $1" \
    -H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' \
    -H 'MCP-Protocol-Version: 2025-06-18' ${MCP_SESSION:+-H "Mcp-Session-Id: $MCP_SESSION"} --data-binary "$2")"
  if grep -qi '^content-type: text/event-stream' "$WORK/mcp-headers"; then sed -n 's/^data: //p' <<<"$out" | tail -1; else printf '%s' "$out"; fi
}
mcp_init() { # <token>
  MCP_SESSION=""
  mcp_post "$1" '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"functional","version":"1"}}}' >"$WORK/init"
  MCP_SESSION="$(sed -n 's/^[Mm]cp-[Ss]ession-[Ii]d: *\([^[:space:]]*\).*/\1/p' "$WORK/mcp-headers")"
  mcp_post "$1" '{"jsonrpc":"2.0","method":"notifications/initialized"}' >/dev/null
}
tool() { # <token> <name> <arguments json>: prints the tool result text (prefixed ERROR: on isError)
  mcp_post "$1" "{\"jsonrpc\":\"2.0\",\"id\":2,\"method\":\"tools/call\",\"params\":{\"name\":\"$2\",\"arguments\":$3}}" \
    | jq -r 'if .result then (if .result.isError then "ERROR: " else "" end) + .result.content[0].text else tostring end'
}

# call <token> <tool> <arguments json>: prints the raw JSON-RPC response
# (HTTP headers in $WORK/mcp-headers); result: the tool's text from it.
call() { mcp_post "$1" "{\"jsonrpc\":\"2.0\",\"id\":3,\"method\":\"tools/call\",\"params\":{\"name\":\"$2\",\"arguments\":$3}}"; }
result() { jq -r '.result.content[0].text // empty' <<<"$1"; }

# db <sql>: runs a query on Mautic's database, prints rows tab-separated (NULL as NULL).
db() {
  php -r '
    $pdo = new PDO(sprintf("mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4", getenv("DB_HOST"), getenv("DB_PORT") ?: "3306", getenv("DB_NAME")), getenv("DB_USER"), getenv("DB_PASSWORD"));
    foreach ($pdo->query($argv[1])->fetchAll(PDO::FETCH_NUM) as $row) { echo implode("\t", array_map(fn ($v) => $v ?? "NULL", $row)), "\n"; }
  ' "$1"
}

# db_exec <sql>: runs a statement that returns no rows (INSERT/UPDATE/DELETE); prints the affected row count.
db_exec() {
  php -r '
    $pdo = new PDO(sprintf("mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4", getenv("DB_HOST"), getenv("DB_PORT") ?: "3306", getenv("DB_NAME")), getenv("DB_USER"), getenv("DB_PASSWORD"), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    echo $pdo->exec($argv[1]), "\n";
  ' "$1"
}

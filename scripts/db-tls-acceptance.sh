#!/bin/bash
# Acceptance for EA_DB_SSL / EA_DB_SSL_CA_PEM against a MySQL 8.4 that REQUIRES
# TLS and presents a self-signed (throwaway) CA, i.e. the DigitalOcean shape.
#
# Self-contained: generates certs into a temp dir, builds the production image as
# ea-tls:test, starts its own compose project (no published ports), and tears
# everything down on exit, including on failure.
#
# Usage: ./scripts/db-tls-acceptance.sh
#
# To add a case: write a function that uses ea_run / sql / pass / fail and call
# it before the final summary (see "Cases").

set -uo pipefail

cd "$(dirname "$0")/.."

FAIL=0
pass() { echo "  PASS  $1"; }
fail() { echo "  FAIL  $1"; FAIL=1; }

TLS_DIR="$(mktemp -d)"
export TLS_DIR
PROJECT="ea-tls-acc-$$"
COMPOSE="docker compose --progress quiet -p $PROJECT -f docker-compose.tls.yml"

cleanup() {
    $COMPOSE down -v --remove-orphans >/dev/null 2>&1
    rm -rf "$TLS_DIR"
}
trap cleanup EXIT

# --- helpers -----------------------------------------------------------------

# ea_run <ENV=VALUE...> -- <command...>
# Runs a one-off EA container (entrypoint bypassed) against the TLS MySQL.
# Combined stdout+stderr is printed; the exit status is the command's.
ea_run() {
    local envs=()
    while [ "$1" != "--" ]; do envs+=(-e "$1"); shift; done
    shift
    $COMPOSE run --rm --no-deps -T --entrypoint "" "${envs[@]}" ea "$@" 2>&1
}

sql() { $COMPOSE exec -T mysql mysql -uroot -prootpass -N -B ea_tls -e "$1" 2>/dev/null; }

migrate() { ea_run "$@" -- php index.php console migrate; }

# Like migrate, but keeps stderr apart from stdout so a case can assert on it.
# Sets MIG_OUT (stdout), MIG_ERR (stderr) and MIG_RC. EA_DEBUG_MODE stays false
# (the compose default, same as production).
migrate_split() {
    local envs=() errf
    errf=$(mktemp)
    while [ $# -gt 0 ]; do envs+=(-e "$1"); shift; done
    MIG_OUT=$($COMPOSE run --rm --no-deps -T --entrypoint "" "${envs[@]}" ea php index.php console migrate 2>"$errf")
    MIG_RC=$?
    MIG_ERR=$(cat "$errf"); rm -f "$errf"
}

# --- fixtures ----------------------------------------------------------------

echo "== Generating throwaway CA and server certificate (SAN DNS:mysql) =="
(
    cd "$TLS_DIR"
    openssl req -x509 -newkey rsa:2048 -nodes -days 2 -subj "/CN=Throwaway Project CA" \
        -keyout ca-key.pem -out ca.pem 2>/dev/null
    openssl req -newkey rsa:2048 -nodes -subj "/CN=mysql" -keyout server-key.pem -out server.csr 2>/dev/null
    printf 'subjectAltName=DNS:mysql\n' > san.ext
    openssl x509 -req -in server.csr -CA ca.pem -CAkey ca-key.pem -CAcreateserial -days 2 \
        -extfile san.ext -out server.pem 2>/dev/null
    chmod 644 ./*.pem   # the mysql user (not us) must read the key; it is throwaway
) || { echo "cert generation failed"; exit 1; }
CA_PEM="$(cat "$TLS_DIR/ca.pem")"

echo "== Building ea-tls:test and starting MySQL 8.4 (require-secure-transport) =="
$COMPOSE build ea >/dev/null 2>&1 || { echo "image build failed"; $COMPOSE build ea; exit 1; }
$COMPOSE up -d mysql >/dev/null 2>&1 || { echo "mysql failed to start"; exit 1; }

# The entrypoint's temporary server answers on the socket only, so wait for a
# real TCP+TLS login as the application user.
for _ in $(seq 60); do
    $COMPOSE exec -T mysql mysql -h127.0.0.1 -uea_tls -ptls_pass --ssl-mode=REQUIRED ea_tls -e 'select 1' >/dev/null 2>&1 && break
    sleep 2
done
$COMPOSE exec -T mysql mysql -h127.0.0.1 -uea_tls -ptls_pass --ssl-mode=REQUIRED ea_tls -e 'select 1' >/dev/null 2>&1 \
    || { echo "mysql never became ready"; exit 1; }

# Prints the cipher of EA's *own* connection, built from application/config/database.php.
read -r -d '' CIPHER_PROBE <<'PHP'
<?php
define('BASEPATH', '/var/www/html/system/');
define('APPPATH', '/var/www/html/application/');
define('ENVIRONMENT', 'production');
require '/var/www/html/config.php';
require APPPATH . 'config/database.php';
require BASEPATH . 'core/Common.php';
require BASEPATH . 'database/DB.php';
$db['default']['db_debug'] = false;
$conn = DB($db['default']);
$row = $conn->query("SHOW SESSION STATUS LIKE 'Ssl_cipher'")->row_array();
echo 'Ssl_cipher=' . ($row['Value'] ?? '') . "\n";
PHP

# --- Cases -------------------------------------------------------------------

echo
echo "== Case 1: EA_DB_SSL=false is rejected by the TLS-only server (reproduces staging) =="
migrate_split EA_DB_SSL=false
echo "    rc=$MIG_RC"; echo "    stdout: $(head -c 400 <<<"$MIG_OUT")"; echo "    stderr: $(head -c 400 <<<"$MIG_ERR")"
{ [ "$MIG_RC" -ne 0 ] && [ "$MIG_RC" -ne 255 ]; } && pass "migrate exits non-zero (rc=$MIG_RC)" || fail "migrate rc=$MIG_RC (want clean non-zero)"
grep -qx 'Database connection failed' <<<"$MIG_ERR" && pass "stderr: Database connection failed" || fail "stderr lacks 'Database connection failed'"
grep -qiE '^cause: \[[0-9]+\] .*insecure transport' <<<"$MIG_ERR" && pass "stderr names the cause: $(grep -m1 '^cause:' <<<"$MIG_ERR")" || fail "stderr lacks an insecure-transport cause line"

echo
echo "== Case 2: EA_DB_SSL=true, no PEM: system bundle cannot verify the throwaway CA =="
migrate_split EA_DB_SSL=true EA_DB_SSL_CA_PEM=
echo "    rc=$MIG_RC"; echo "    stdout: $(head -c 400 <<<"$MIG_OUT")"; echo "    stderr: $(head -c 400 <<<"$MIG_ERR")"
{ [ "$MIG_RC" -ne 0 ] && [ "$MIG_RC" -ne 255 ]; } && pass "migrate exits non-zero (rc=$MIG_RC)" || fail "migrate rc=$MIG_RC (want clean non-zero)"
grep -qx 'Database connection failed' <<<"$MIG_ERR" && pass "stderr: Database connection failed" || fail "stderr lacks 'Database connection failed'"
# Prove the failure is about verification, not something else: same server with a PEM works (case 3),
# and the cause line names the SSL/certificate failure.
grep -qiE '^cause: \[[0-9]+\] .*(certificate|ssl)' <<<"$MIG_ERR" && pass "stderr names the cause: $(grep -m1 '^cause:' <<<"$MIG_ERR" | head -c 160)" || fail "stderr lacks a certificate/SSL cause line"

echo
echo "== Case 2b: wrong password over verified TLS: the cause says so, and never echoes the password =="
migrate_split EA_DB_SSL=true "EA_DB_SSL_CA_PEM=$CA_PEM" EA_DB_PASSWORD=wrong-pw-7f3a
echo "    rc=$MIG_RC"; echo "    stderr: $(head -c 400 <<<"$MIG_ERR")"
{ [ "$MIG_RC" -ne 0 ] && [ "$MIG_RC" -ne 255 ]; } && pass "migrate exits non-zero (rc=$MIG_RC)" || fail "migrate rc=$MIG_RC (want clean non-zero)"
grep -qE '^cause: \[1045\] Access denied' <<<"$MIG_ERR" && pass "stderr names the cause: $(grep -m1 '^cause:' <<<"$MIG_ERR")" || fail "stderr lacks an access-denied cause line"
grep -q 'wrong-pw-7f3a' <<<"$MIG_OUT$MIG_ERR" && fail "the password appears in the output" || pass "the password never appears in the output"

echo
echo "== Case 3: EA_DB_SSL=true + EA_DB_SSL_CA_PEM: migrates over verified TLS =="
OUT=$(migrate EA_DB_SSL=true "EA_DB_SSL_CA_PEM=$CA_PEM"); RC=$?
[ "$RC" -eq 0 ] && pass "migrate exits 0" || fail "migrate rc=$RC: $(head -c 600 <<<"$OUT")"
[ "$(sql "SHOW TABLES LIKE 'ea_users'")" = "ea_users" ] && pass "table ea_users exists" || fail "table ea_users missing"
CIPHER=$(ea_run EA_DB_SSL=true "EA_DB_SSL_CA_PEM=$CA_PEM" -- php <<<"$CIPHER_PROBE" | sed -n 's/^Ssl_cipher=//p')
[ -n "$CIPHER" ] && pass "EA's connection Ssl_cipher=$CIPHER" || fail "EA's connection reports an empty Ssl_cipher"

echo
echo "== Case 4: re-running migrate is idempotent and keeps data =="
sql "INSERT INTO ea_settings (name, value) VALUES ('tls_acceptance_sentinel', 'kept')"
VERSION_BEFORE=$(sql "SELECT version FROM ea_migrations")
OUT=$(migrate EA_DB_SSL=true "EA_DB_SSL_CA_PEM=$CA_PEM"); RC=$?
[ "$RC" -eq 0 ] && pass "second migrate exits 0" || fail "second migrate rc=$RC: $(head -c 600 <<<"$OUT")"
[ "$(sql "SELECT value FROM ea_settings WHERE name='tls_acceptance_sentinel'")" = "kept" ] \
    && pass "sentinel survives" || fail "sentinel lost"
[ -n "$VERSION_BEFORE" ] && [ "$(sql "SELECT version FROM ea_migrations")" = "$VERSION_BEFORE" ] \
    && pass "ea_migrations version unchanged ($VERSION_BEFORE)" || fail "ea_migrations version changed (was '$VERSION_BEFORE')"

echo
echo "== Case 5: service container is ready on the migrated, unseeded DB =="
export EA_DB_SSL=true EA_DB_SSL_CA_PEM="$CA_PEM"
$COMPOSE up -d ea >/dev/null 2>&1 || fail "service container failed to start"
CODE=000
for _ in $(seq 30); do
    CODE=$($COMPOSE exec -T ea curl -s -o /dev/null -w '%{http_code}' http://localhost/index.php 2>/dev/null)
    [ "$CODE" = "200" ] && break
    sleep 2
done
[ "$CODE" = "200" ] && pass "GET /index.php -> 200" || fail "GET /index.php -> $CODE"

echo
echo "== Case 6: ea-seed apply over TLS creates the admin =="
seed() {
    $COMPOSE exec -T \
        -e TLS_ADMIN_PASSWORD=tls-admin-pw -e TLS_PROVIDER_PASSWORD=tls-provider-pw -e TLS_API_KEY=tls-token \
        -e EA_SEED_ENDPOINT=http://localhost \
        ea php /var/www/html/scripts/ea-seed.php apply /var/www/html/tests/fixtures/tls-acceptance.yml 2>&1
}
# The production image does not ship tests/; copy the manifest in.
$COMPOSE exec -T ea mkdir -p /var/www/html/tests/fixtures
$COMPOSE cp tests/fixtures/tls-acceptance.yml ea:/var/www/html/tests/fixtures/tls-acceptance.yml >/dev/null 2>&1
OUT=$(seed); RC=$?
[ "$RC" -eq 0 ] && pass "ea-seed apply exits 0" || fail "ea-seed apply rc=$RC: $(tail -n 8 <<<"$OUT")"
[ "$(sql "SELECT COUNT(*) FROM ea_users WHERE email='admin@tls-acceptance.local'")" = "1" ] \
    && pass "admin created" || fail "admin missing"

echo
echo "== Case 7: second apply is a no-op =="
OUT=$(seed)
grep -q '0 created, 0 updated' <<<"$OUT" && pass "0 created, 0 updated" \
    || fail "second apply reported changes: $(grep -E '[0-9]+ created' <<<"$OUT")"

echo
echo "== Case 8: image runs a passed command (App Platform one-shot jobs) =="
# Bounded: without the entrypoint change the image starts supervisord and never exits.
OUT=$(timeout 60 docker run --rm ea-tls:test php -r 'echo "job-ok\n";' 2>&1); RC=$?
echo "    rc=$RC"; echo "    output: $(head -c 400 <<<"$OUT")"
[ "$RC" -eq 0 ] && pass "passed command exits 0" || fail "passed command rc=$RC (124 = timed out)"
grep -q 'job-ok' <<<"$OUT" && pass "passed command ran (job-ok)" || fail "output lacks job-ok"
grep -q 'supervisord' <<<"$OUT" && fail "supervisord started for a passed command" || pass "supervisord not started"
timeout 60 docker run --rm ea-tls:test sh -c 'exit 3' >/dev/null 2>&1; RC=$?
[ "$RC" -eq 3 ] && pass "passed command's exit status propagates (3)" || fail "exit status rc=$RC (want 3)"

echo
echo "== Case 9: EA_MAIL_PROTOCOL=zeptomail posts the message over HTTPS-style API (local capture server) =="
# A capture server inside the image stands in for api.zeptomail.com; the mailer uses EA's real
# config/email.php and its default cURL transport, so this proves the wiring and the extension.
read -r -d '' MAIL_PROBE <<'PHP'
<?php
define('BASEPATH', '/var/www/html/system/');
define('APPPATH', '/var/www/html/application/');
require '/var/www/html/vendor/autoload.php';
require APPPATH . 'config/email.php';
require APPPATH . 'libraries/Zeptomail_mailer.php';
$m = new Zeptomail_mailer($config['zeptomail_token'], $config['zeptomail_endpoint']);
$m->setFrom($config['from_address'], $config['from_name']);
$m->addAddress('prospect@example.test');
$m->Subject = 'Probe';
$m->isHTML();
$m->Body = '<p>Probe</p>';
$m->AltBody = 'Probe';
$m->addStringAttachment('BEGIN:VCALENDAR', 'invitation.ics', 'base64', 'text/calendar');
echo 'protocol=' . $config['protocol'] . "\n";
echo 'sent=' . var_export($m->send(), true) . "\n";
PHP
read -r -d '' CAPTURE <<'PHP'
<?php
file_put_contents('/tmp/capture.json', json_encode([
    'auth' => $_SERVER['HTTP_AUTHORIZATION'] ?? '',
    'type' => $_SERVER['CONTENT_TYPE'] ?? '',
    'body' => json_decode(file_get_contents('php://input'), true),
]));
header('Content-Type: application/json');
echo '{"request_id":"probe"}';
PHP
OUT=$(timeout 60 docker run --rm -e EA_MAIL_PROTOCOL=zeptomail -e EA_ZEPTOMAIL_TOKEN=probe-token \
    -e EA_ZEPTOMAIL_ENDPOINT=http://127.0.0.1:8099/v1.1/email -e EA_MAIL_FROM_ADDRESS=bookings@example.test \
    -e EA_MAIL_FROM_NAME='Leads Staging' -e "MAIL_PROBE=$MAIL_PROBE" -e "CAPTURE=$CAPTURE" ea-tls:test sh -c '
        printf "%s" "$CAPTURE" > /tmp/capture.php && printf "%s" "$MAIL_PROBE" > /tmp/probe.php
        php -S 127.0.0.1:8099 /tmp/capture.php >/dev/null 2>&1 & sleep 1
        php /tmp/probe.php && echo "capture=$(cat /tmp/capture.json)"' 2>&1); RC=$?
echo "    $(head -c 600 <<<"$OUT")"
[ "$RC" -eq 0 ] && grep -qx 'protocol=zeptomail' <<<"$OUT" && grep -qx 'sent=true' <<<"$OUT" \
    && pass "config/email.php selects zeptomail and send() returns true" || fail "probe rc=$RC"
CAP=$(sed -n 's/^capture=//p' <<<"$OUT")
[ "$(jq -r .auth <<<"$CAP" 2>/dev/null)" = "Zoho-enczapikey probe-token" ] && pass "Authorization: Zoho-enczapikey <token>" || fail "authorization header"
[ "$(jq -r '.body.from.address + "|" + .body.to[0].email_address.address + "|" + .body.htmlbody + "|" + .body.attachments[0].mime_type' <<<"$CAP" 2>/dev/null)" \
    = "bookings@example.test|prospect@example.test|<p>Probe</p>|text/calendar" ] && pass "payload: from, to, htmlbody, ics attachment" || fail "payload shape: $(head -c 300 <<<"$CAP")"

echo
[ "$FAIL" -eq 0 ] && echo "ALL CRITERIA PASSED" || echo "ACCEPTANCE FAILED"
exit "$FAIL"

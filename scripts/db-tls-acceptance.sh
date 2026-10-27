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

echo
echo "== Case 2: EA_DB_SSL=true, no PEM: system bundle cannot verify the throwaway CA =="
migrate_split EA_DB_SSL=true EA_DB_SSL_CA_PEM=
echo "    rc=$MIG_RC"; echo "    stdout: $(head -c 400 <<<"$MIG_OUT")"; echo "    stderr: $(head -c 400 <<<"$MIG_ERR")"
{ [ "$MIG_RC" -ne 0 ] && [ "$MIG_RC" -ne 255 ]; } && pass "migrate exits non-zero (rc=$MIG_RC)" || fail "migrate rc=$MIG_RC (want clean non-zero)"
grep -qx 'Database connection failed' <<<"$MIG_ERR" && pass "stderr: Database connection failed" || fail "stderr lacks 'Database connection failed'"
# Prove the failure is about verification, not something else: same server with a PEM works (case 3),
# and the driver's warning (logged, db_debug off) names the SSL/certificate failure.
LOGHINT=$(ea_run EA_DB_SSL=true EA_DB_SSL_CA_PEM= -- sh -c 'php index.php console migrate >/dev/null 2>&1; cat storage/logs/log-*.php 2>/dev/null' | grep -m1 -iE 'certificate|ssl')
[ -n "$LOGHINT" ] && pass "log shows certificate/SSL cause: $(head -c 160 <<<"$LOGHINT")" || fail "no certificate/SSL cause in EA log"

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
[ "$FAIL" -eq 0 ] && echo "ALL CRITERIA PASSED" || echo "ACCEPTANCE FAILED"
exit "$FAIL"

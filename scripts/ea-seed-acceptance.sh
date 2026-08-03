#!/bin/bash
# Acceptance for the six success criteria in
# docs/superpowers/specs/2026-08-03-vertical-seeding-and-infra-tuning-design.md
#
# Assumes the harness is already up:
#   docker compose -f docker-compose.local.yml up --build -d
#
# Usage: ./scripts/ea-seed-acceptance.sh

set -euo pipefail

COMPOSE="docker compose -f docker-compose.local.yml"
FAIL=0

pass() { echo "  PASS  $1"; }
fail() { echo "  FAIL  $1"; FAIL=1; }

export ZEEKR_ADMIN_PASSWORD=zeekr-admin-pw
export ZEEKR_PROVIDER_PASSWORD=zeekr-provider-pw
export ZEEKR_API_KEY=zeekr-local-token
export ZEEKR_WEBHOOK_SECRET=zeekr-webhook-secret
export LOSTLOCKS_ADMIN_PASSWORD=lostlocks-admin-pw
export LOSTLOCKS_PROVIDER_PASSWORD=lostlocks-provider-pw
export LOSTLOCKS_API_KEY=lostlocks-local-token
export LOSTLOCKS_WEBHOOK_SECRET=lostlocks-webhook-secret

seed() { # seed <service> <manifest> <command>
    $COMPOSE exec -T \
        -e ZEEKR_ADMIN_PASSWORD -e ZEEKR_PROVIDER_PASSWORD -e ZEEKR_API_KEY -e ZEEKR_WEBHOOK_SECRET \
        -e LOSTLOCKS_ADMIN_PASSWORD -e LOSTLOCKS_PROVIDER_PASSWORD -e LOSTLOCKS_API_KEY -e LOSTLOCKS_WEBHOOK_SECRET \
        -e EA_SEED_ENDPOINT=http://localhost \
        "$1" php /var/www/html/scripts/ea-seed.php "$3" "/var/www/html/$2"
}

echo "== Seeding both vendors =="
seed ea-zeekr-gold-coast verticals/zeekr-gold-coast.local.yml apply
seed ea-lostlocks        verticals/lostlocks.local.yml        apply

echo
echo "== Criterion 2: emitted env satisfies each storefront's contract =="
ZEEKR_ENV=$($COMPOSE exec -T ea-zeekr-gold-coast cat /var/www/html/.env.zeekr-gold-coast)
LOST_ENV=$($COMPOSE exec -T ea-lostlocks cat /var/www/html/.env.lostlocks)

for v in EA_BASE_URL EA_PROVIDER_ID EA_SERVICE_TEST_DRIVE EA_SERVICE_SHOWROOM \
         EA_SERVICE_FINANCE EA_SERVICE_TRADEIN EA_TIMEZONE; do
    grep -q "^$v=" <<<"$ZEEKR_ENV" && pass "zeekr $v" || fail "zeekr $v missing"
done

for v in EA_BASE_URL EA_PROVIDER_ID EA_SERVICE_MASTERMIND_HEIST EA_SERVICE_ZODIAC_KILLER \
         EA_SERVICE_PRISON_BREAK EA_SERVICE_ZOMBIE_LAB; do
    grep -q "^$v=" <<<"$LOST_ENV" && pass "lostlocks $v" || fail "lostlocks $v missing"
done

echo
echo "== Criterion 3: conflicting settings coexist =="
Z_PHONE=$(curl -s -H "Authorization: Bearer $ZEEKR_API_KEY" \
    http://localhost:8080/api/v1/settings/require_phone_number | grep -o '"value":"[^"]*"' | cut -d'"' -f4)
L_PHONE=$(curl -s -H "Authorization: Bearer $LOSTLOCKS_API_KEY" \
    http://localhost:8081/api/v1/settings/require_phone_number | grep -o '"value":"[^"]*"' | cut -d'"' -f4)

[ "$Z_PHONE" = "1" ] && pass "zeekr require_phone_number=1" || fail "zeekr require_phone_number=$Z_PHONE"
[ "$L_PHONE" = "0" ] && pass "lostlocks require_phone_number=0" || fail "lostlocks require_phone_number=$L_PHONE"

echo
echo "== Criterion 4: Lostlocks midnight slot is bookable =="
HEIST_ID=$(grep '^EA_SERVICE_MASTERMIND_HEIST=' <<<"$LOST_ENV" | cut -d= -f2)
PROVIDER_ID=$(grep '^EA_PROVIDER_ID=' <<<"$LOST_ENV" | cut -d= -f2)
TOMORROW=$(date -v+1d +%Y-%m-%d 2>/dev/null || date -d '+1 day' +%Y-%m-%d)

AVAIL=$(curl -s -H "Authorization: Bearer $LOSTLOCKS_API_KEY" \
    "http://localhost:8081/api/v1/availabilities?serviceId=$HEIST_ID&providerId=$PROVIDER_ID&date=$TOMORROW")

grep -q '"00:00"' <<<"$AVAIL" && pass "00:00 available for Mastermind Heist" \
    || fail "00:00 not in availabilities: $AVAIL"

echo
echo "== Criterion 5: gated service ids =="
GATED=$(grep '^EA_GATED_SERVICE_IDS=' <<<"$LOST_ENV" | cut -d= -f2 || true)
ZOMBIE_ID=$(grep '^EA_SERVICE_ZOMBIE_LAB=' <<<"$LOST_ENV" | cut -d= -f2)
EXPECTED=$(printf '%s\n%s\n' "$HEIST_ID" "$ZOMBIE_ID" | sort -n | paste -sd, -)

[ "$GATED" = "$EXPECTED" ] && pass "lostlocks EA_GATED_SERVICE_IDS=$GATED" \
    || fail "lostlocks gated ids were '$GATED', expected '$EXPECTED'"
grep -q 'EA_GATED_SERVICE_IDS' <<<"$ZEEKR_ENV" \
    && fail "zeekr should not emit EA_GATED_SERVICE_IDS" \
    || pass "zeekr omits EA_GATED_SERVICE_IDS"

echo
echo "== Criterion 6: re-running changes nothing =="
RERUN=$(seed ea-lostlocks verticals/lostlocks.local.yml apply)
grep -q '0 created, 0 updated' <<<"$RERUN" && pass "lostlocks re-apply is a no-op" \
    || fail "lostlocks re-apply reported changes: $(grep -E '[0-9]+ created' <<<"$RERUN")"

LOST_ENV_2=$($COMPOSE exec -T ea-lostlocks cat /var/www/html/.env.lostlocks)
[ "$LOST_ENV" = "$LOST_ENV_2" ] && pass "emitted env is byte-identical" || fail "emitted env changed"

echo
[ "$FAIL" -eq 0 ] && echo "ALL CRITERIA PASSED" || echo "ACCEPTANCE FAILED"
exit "$FAIL"

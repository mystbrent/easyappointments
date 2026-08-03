#!/bin/bash
# =============================================================================
# EasyAppointments Local Multi-Vendor Startup
#
# Brings up the two-vendor harness and seeds each instance from its manifest
# via ea-seed. Idempotent — safe to run repeatedly; seeding converges rather
# than recreates.
#
# Usage:
#   ./scripts/start-local.sh              # start + seed
#   ./scripts/start-local.sh --rebuild    # force image rebuild, then start + seed
#   ./scripts/start-local.sh --reset      # wipe volumes, rebuild, start + seed
#
# Prerequisites: Docker Desktop running
# =============================================================================

set -euo pipefail

cd "$(dirname "$0")/.."

COMPOSE="docker compose -f docker-compose.local.yml"

# Local-only credentials. Real deployments resolve these from Secret Manager.
# These must match scripts/ea-seed-acceptance.sh exactly — both scripts seed
# the same manifests and are expected to converge to the same state.
export ZEEKR_ADMIN_PASSWORD=zeekr-admin-pw
export ZEEKR_PROVIDER_PASSWORD=zeekr-provider-pw
export ZEEKR_API_KEY=zeekr-local-token
export ZEEKR_WEBHOOK_SECRET=zeekr-webhook-secret
export LOSTLOCKS_ADMIN_PASSWORD=lostlocks-admin-pw
export LOSTLOCKS_PROVIDER_PASSWORD=lostlocks-provider-pw
export LOSTLOCKS_API_KEY=lostlocks-local-token
export LOSTLOCKS_WEBHOOK_SECRET=lostlocks-webhook-secret

REBUILD=""
for arg in "$@"; do
    case "$arg" in
        --reset)   echo "Wiping volumes..."; $COMPOSE down -v; REBUILD="--build" ;;
        --rebuild) REBUILD="--build" ;;
        *)         echo "Unknown flag: $arg"; exit 1 ;;
    esac
done

echo "Starting stack..."
$COMPOSE up -d $REBUILD

wait_for_http() { # wait_for_http <name> <url>
    printf "Waiting for %s" "$1"
    for _ in $(seq 1 90); do
        if curl -sf -o /dev/null "$2"; then echo " ready"; return 0; fi
        printf "."
        sleep 2
    done
    echo " TIMEOUT"
    return 1
}

wait_for_http "zeekr-gold-coast" "http://localhost:8080/"
wait_for_http "lostlocks" "http://localhost:8081/"

# Dockerfile.production does not COPY verticals/ into the image, and scripts/
# or vendor/ inside a running container can drift from the host between
# rebuilds. Sync all three from the host before every seed so the container
# always runs the current ea-seed CLI against the current manifests,
# regardless of whether this run just rebuilt the image. `docker compose cp`
# cannot overwrite an existing path in place under Colima's virtiofs, so the
# destination is removed first.
sync_container() { # sync_container <service>
    echo "Syncing scripts, verticals, and vendor into $1..."
    $COMPOSE exec -T "$1" rm -rf /var/www/html/scripts /var/www/html/verticals /var/www/html/vendor
    $COMPOSE cp scripts    "$1":/var/www/html/scripts
    $COMPOSE cp verticals  "$1":/var/www/html/verticals
    $COMPOSE cp vendor     "$1":/var/www/html/vendor
}

sync_container ea-zeekr-gold-coast
sync_container ea-lostlocks

seed() { # seed <service> <manifest>
    echo
    echo "Seeding $1..."
    $COMPOSE exec -T \
        -e ZEEKR_ADMIN_PASSWORD -e ZEEKR_PROVIDER_PASSWORD -e ZEEKR_API_KEY -e ZEEKR_WEBHOOK_SECRET \
        -e LOSTLOCKS_ADMIN_PASSWORD -e LOSTLOCKS_PROVIDER_PASSWORD -e LOSTLOCKS_API_KEY -e LOSTLOCKS_WEBHOOK_SECRET \
        -e EA_SEED_ENDPOINT=http://localhost \
        "$1" php /var/www/html/scripts/ea-seed.php apply "/var/www/html/$2"
}

seed ea-zeekr-gold-coast verticals/zeekr-gold-coast.local.yml
seed ea-lostlocks        verticals/lostlocks.local.yml

echo
echo "Copying emitted env files out of the containers..."
$COMPOSE exec -T ea-zeekr-gold-coast cat /var/www/html/.env.zeekr-gold-coast > .env.zeekr-gold-coast
$COMPOSE exec -T ea-lostlocks        cat /var/www/html/.env.lostlocks        > .env.lostlocks

cat <<EOF

Ready.

  Zeekr Gold Coast   http://localhost:8080   (admin: zeekradmin / $ZEEKR_ADMIN_PASSWORD)
  Lostlocks          http://localhost:8081   (admin: lostlocksadmin / $LOSTLOCKS_ADMIN_PASSWORD)

Storefront config written to .env.zeekr-gold-coast and .env.lostlocks
EOF

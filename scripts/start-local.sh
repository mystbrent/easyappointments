#!/bin/bash
set -euo pipefail

# =============================================================================
# EasyAppointments Local Dev Startup
#
# Starts the local Docker stack (MySQL + EA), waits for readiness, and
# seeds the database for Padlockd PWA integration testing if needed.
#
# Idempotent: safe to run repeatedly. Detects whether the instance needs
# first-time setup or is already configured.
#
# Usage:
#   ./scripts/start-local.sh              # start + verify
#   ./scripts/start-local.sh --rebuild    # force rebuild image, then start
#   ./scripts/start-local.sh --reset      # wipe DB, rebuild, and re-seed
#
# Prerequisites: Docker Desktop running
# =============================================================================

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
COMPOSE_FILE="$REPO_DIR/docker-compose.local.yml"

# --- Configuration (matches docker-compose.local.yml) -------------------------
EA_PORT=8080
EA_URL="http://localhost:$EA_PORT"
MYSQL_USER="ea_user"
MYSQL_PASS="ea_pass"
MYSQL_DB="easyappointments"

# Padlockd-specific seed values
ADMIN_FIRST_NAME="Brent"
ADMIN_LAST_NAME="Tudas"
ADMIN_EMAIL="admin@padlockd.com"
ADMIN_USERNAME="admin"
ADMIN_PASSWORD="Admin123!"
COMPANY_NAME="Padlockd"
API_TOKEN="padlockd-local-dev-token-2026"
WEBHOOK_SECRET="padlockd-local-webhook-secret-2026"
PROVIDER_NAME_FIRST="Padlockd"
PROVIDER_NAME_LAST="Venue"
PROVIDER_EMAIL="venue@padlockd.com"

# --- Helpers ------------------------------------------------------------------
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
BOLD='\033[1m'
NC='\033[0m'

step()  { echo -e "\n${CYAN}>>> $1${NC}"; }
ok()    { echo -e "    ${GREEN}OK${NC} $1"; }
warn()  { echo -e "    ${YELLOW}WARN${NC} $1"; }
fail()  { echo -e "    ${RED}FAIL${NC} $1"; }
info()  { echo -e "    $1"; }

wait_for() {
    local desc="$1" cmd="$2" max_wait="${3:-60}" interval="${4:-2}"
    local elapsed=0
    while [ $elapsed -lt $max_wait ]; do
        if eval "$cmd" > /dev/null 2>&1; then
            return 0
        fi
        sleep "$interval"
        elapsed=$((elapsed + interval))
    done
    return 1
}

api() {
    curl -sf -H "Authorization: Bearer $API_TOKEN" \
         -H "Content-Type: application/json" "$@" 2>/dev/null
}

# --- Pre-flight ---------------------------------------------------------------
step "Checking prerequisites"

if ! docker info > /dev/null 2>&1; then
    fail "Docker is not running. Start Docker Desktop and try again."
    exit 1
fi
ok "Docker is running"

if [ ! -f "$COMPOSE_FILE" ]; then
    fail "docker-compose.local.yml not found at $COMPOSE_FILE"
    exit 1
fi
ok "Compose file found"

# --- Parse flags --------------------------------------------------------------
REBUILD=false
RESET=false
for arg in "$@"; do
    case "$arg" in
        --rebuild) REBUILD=true ;;
        --reset)   RESET=true; REBUILD=true ;;
        --help|-h)
            echo "Usage: $0 [--rebuild] [--reset]"
            echo "  --rebuild   Force rebuild the Docker image"
            echo "  --reset     Wipe database and re-seed from scratch"
            exit 0
            ;;
        *) echo "Unknown flag: $arg"; exit 1 ;;
    esac
done

# --- Reset (if requested) ----------------------------------------------------
if $RESET; then
    step "Resetting: stopping containers and wiping database volume"
    docker compose -f "$COMPOSE_FILE" down -v 2>/dev/null || true
    ok "Volumes removed"
fi

# --- Start containers ---------------------------------------------------------
step "Starting Docker stack"

BUILD_FLAG=""
if $REBUILD; then
    BUILD_FLAG="--build"
    info "Rebuilding image..."
fi

docker compose -f "$COMPOSE_FILE" up -d $BUILD_FLAG 2>&1 | while IFS= read -r line; do
    echo "    $line"
done

# --- Wait for MySQL -----------------------------------------------------------
step "Waiting for MySQL to be healthy"

if wait_for "MySQL" "docker compose -f '$COMPOSE_FILE' exec -T mysql mysqladmin ping -u$MYSQL_USER -p$MYSQL_PASS" 90 3; then
    ok "MySQL is accepting connections"
else
    fail "MySQL did not start within 90 seconds"
    docker compose -f "$COMPOSE_FILE" logs mysql 2>&1 | tail -10
    exit 1
fi

# --- Wait for EA HTTP ---------------------------------------------------------
step "Waiting for EasyAppointments HTTP"

if wait_for "EA HTTP" "curl -sf $EA_URL/ > /dev/null" 60 2; then
    ok "EA is responding at $EA_URL"
else
    fail "EA did not respond within 60 seconds"
    docker compose -f "$COMPOSE_FILE" logs easyappointments 2>&1 | tail -15
    exit 1
fi

# --- Check if setup is needed ------------------------------------------------
step "Checking instance state"

TABLE_COUNT=$(docker compose -f "$COMPOSE_FILE" exec -T mysql \
    mysql -u$MYSQL_USER -p$MYSQL_PASS $MYSQL_DB -sN \
    -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$MYSQL_DB';" 2>/dev/null || echo "0")

USER_COUNT=$(docker compose -f "$COMPOSE_FILE" exec -T mysql \
    mysql -u$MYSQL_USER -p$MYSQL_PASS $MYSQL_DB -sN \
    -e "SELECT COUNT(*) FROM ea_users;" 2>/dev/null || echo "0")

SERVICE_COUNT=$(docker compose -f "$COMPOSE_FILE" exec -T mysql \
    mysql -u$MYSQL_USER -p$MYSQL_PASS $MYSQL_DB -sN \
    -e "SELECT COUNT(*) FROM ea_services;" 2>/dev/null || echo "0")

info "Tables: $TABLE_COUNT | Users: $USER_COUNT | Services: $SERVICE_COUNT"

NEEDS_INSTALL=false

if [ "$USER_COUNT" = "0" ]; then
    NEEDS_INSTALL=true
    info "Fresh instance — needs installation + Padlockd seeding"
else
    info "Instance exists — will verify configuration"
fi

# --- Run installation if needed -----------------------------------------------
if $NEEDS_INSTALL; then
    step "Running first-time installation via PHP"

    # The installation endpoint is CSRF-protected, so we run the installation
    # logic directly inside the container using EA's own PHP framework.
    docker compose -f "$COMPOSE_FILE" exec -T easyappointments \
        php -r "
            \$_SERVER['REQUEST_METHOD'] = 'GET';
            \$_SERVER['SERVER_NAME'] = 'localhost';
            \$_SERVER['REQUEST_URI'] = '/';

            chdir('/var/www/html');
            require 'vendor/autoload.php';
            require 'system/core/CodeIgniter.php';
        " > /dev/null 2>&1 || true

    # Seed via direct MySQL since we know the schema from the migration.
    # The migration already ran on container start (entrypoint.sh), so tables exist.
    # We insert the data that the installation wizard would create.

    # Generate salt and password hash using PHP inside the container
    ADMIN_HASH=$(docker compose -f "$COMPOSE_FILE" exec -T easyappointments \
        php -r "
            define('BASEPATH', '/var/www/html/system/');
            require '/var/www/html/application/helpers/password_helper.php';
            define('MAX_PASSWORD_LENGTH', 512);
            \$salt = generate_salt();
            \$hash = hash_password(\$salt, '$ADMIN_PASSWORD');
            echo \$salt . '|' . \$hash;
        " 2>/dev/null)

    ADMIN_SALT=$(echo "$ADMIN_HASH" | cut -d'|' -f1)
    ADMIN_PASS_HASH=$(echo "$ADMIN_HASH" | cut -d'|' -f2)

    PROVIDER_HASH=$(docker compose -f "$COMPOSE_FILE" exec -T easyappointments \
        php -r "
            define('BASEPATH', '/var/www/html/system/');
            require '/var/www/html/application/helpers/password_helper.php';
            define('MAX_PASSWORD_LENGTH', 512);
            \$salt = generate_salt();
            \$hash = hash_password(\$salt, bin2hex(random_bytes(16)));
            echo \$salt . '|' . \$hash;
        " 2>/dev/null)

    PROVIDER_SALT=$(echo "$PROVIDER_HASH" | cut -d'|' -f1)
    PROVIDER_PASS_HASH=$(echo "$PROVIDER_HASH" | cut -d'|' -f2)

    WORKING_PLAN='{"monday":{"start":"19:00","end":"23:59","breaks":[]},"tuesday":{"start":"19:00","end":"23:59","breaks":[]},"wednesday":{"start":"19:00","end":"23:59","breaks":[]},"thursday":{"start":"19:00","end":"23:59","breaks":[]},"friday":{"start":"19:00","end":"23:59","breaks":[]},"saturday":{"start":"19:00","end":"23:59","breaks":[]},"sunday":{"start":"19:00","end":"23:59","breaks":[]}}'

    NOW=$(date -u '+%Y-%m-%d %H:%M:%S')

    docker compose -f "$COMPOSE_FILE" exec -T mysql \
        mysql -u$MYSQL_USER -p$MYSQL_PASS $MYSQL_DB <<EOSQL
        -- Admin user (id=1, role=admin)
        INSERT INTO ea_users (id, create_datetime, first_name, last_name, email, phone_number, timezone, language, id_roles)
        VALUES (1, '$NOW', '$ADMIN_FIRST_NAME', '$ADMIN_LAST_NAME', '$ADMIN_EMAIL', '+1 (555) 000-0000', 'UTC', 'english', 1)
        ON DUPLICATE KEY UPDATE first_name=VALUES(first_name);

        INSERT INTO ea_user_settings (id_users, username, password, salt, notifications, calendar_view)
        VALUES (1, '$ADMIN_USERNAME', '$ADMIN_PASS_HASH', '$ADMIN_SALT', 1, 'default')
        ON DUPLICATE KEY UPDATE username=VALUES(username);

        -- Provider user (id=2, role=provider)
        INSERT INTO ea_users (id, create_datetime, first_name, last_name, email, phone_number, timezone, language, id_roles)
        VALUES (2, '$NOW', '$PROVIDER_NAME_FIRST', '$PROVIDER_NAME_LAST', '$PROVIDER_EMAIL', '+1 (555) 000-0001', 'UTC', 'english', 2)
        ON DUPLICATE KEY UPDATE first_name=VALUES(first_name);

        INSERT INTO ea_user_settings (id_users, username, password, salt, working_plan, notifications, google_sync, sync_past_days, sync_future_days, calendar_view)
        VALUES (2, 'padlockd-venue', '$PROVIDER_PASS_HASH', '$PROVIDER_SALT', '$WORKING_PLAN', 1, 0, 30, 90, 'default')
        ON DUPLICATE KEY UPDATE working_plan=VALUES(working_plan);

        -- Company settings: UPDATE rows the migration already created (name is
        -- not a unique key, so ON DUPLICATE KEY only checks the id PK — useless).
        UPDATE ea_settings SET value = '$API_TOKEN' WHERE name = 'api_token';
        UPDATE ea_settings SET value = '0'          WHERE name = 'require_phone_number';

        -- These may not exist yet (the migration doesn't create them, the
        -- installation wizard normally does), so insert only if missing.
        INSERT INTO ea_settings (name, value)
            SELECT 'company_name', '$COMPANY_NAME' FROM DUAL
            WHERE NOT EXISTS (SELECT 1 FROM ea_settings WHERE name = 'company_name');
        INSERT INTO ea_settings (name, value)
            SELECT 'company_email', '$ADMIN_EMAIL' FROM DUAL
            WHERE NOT EXISTS (SELECT 1 FROM ea_settings WHERE name = 'company_email');
        INSERT INTO ea_settings (name, value)
            SELECT 'company_link', '$EA_URL' FROM DUAL
            WHERE NOT EXISTS (SELECT 1 FROM ea_settings WHERE name = 'company_link');
EOSQL

    ok "Admin account and company settings created"
fi

# --- Ensure Padlockd services and configuration -------------------------------
step "Configuring Padlockd escape room services"

# Brief pause if we just ran the installation
if $NEEDS_INSTALL; then sleep 2; fi

# Ensure API token is set (UPDATE existing migration row, not INSERT duplicate)
docker compose -f "$COMPOSE_FILE" exec -T mysql \
    mysql -u$MYSQL_USER -p$MYSQL_PASS $MYSQL_DB -e \
    "UPDATE ea_settings SET value = '$API_TOKEN' WHERE name = 'api_token';" \
    2>/dev/null

    # Check which services already exist
    EXISTING=$(api "$EA_URL/api/v1/services" | python3 -c "
import sys, json
data = json.loads(sys.stdin.read())
print(' '.join(str(s['id']) for s in data))
" 2>/dev/null || echo "")

    # Service 1: Mastermind Heist (60 min, 8 attendants, $52)
    if echo "$EXISTING" | grep -qw 1; then
        api -X PUT "$EA_URL/api/v1/services/1" \
            -d '{"name":"Mastermind Heist","duration":60,"price":52,"currency":"USD","description":"Suitable for all ages — HARD difficulty","attendantsNumber":8,"availabilitiesType":"flexible"}' > /dev/null
        ok "Service 1: Mastermind Heist (updated)"
    else
        api -X POST "$EA_URL/api/v1/services" \
            -d '{"name":"Mastermind Heist","duration":60,"price":52,"currency":"USD","description":"Suitable for all ages — HARD difficulty","attendantsNumber":8,"availabilitiesType":"flexible"}' > /dev/null
        ok "Service 1: Mastermind Heist (created)"
    fi

    # Service 2: Zodiac Killer (60 min, 6 attendants, $45)
    if echo "$EXISTING" | grep -qw 2; then
        api -X PUT "$EA_URL/api/v1/services/2" \
            -d '{"name":"Zodiac Killer","duration":60,"price":45,"currency":"USD","description":"Gold Coast'\''s Scariest — MEDIUM difficulty","attendantsNumber":6,"availabilitiesType":"flexible"}' > /dev/null
        ok "Service 2: Zodiac Killer (updated)"
    else
        api -X POST "$EA_URL/api/v1/services" \
            -d '{"name":"Zodiac Killer","duration":60,"price":45,"currency":"USD","description":"Gold Coast'\''s Scariest — MEDIUM difficulty","attendantsNumber":6,"availabilitiesType":"flexible"}' > /dev/null
        ok "Service 2: Zodiac Killer (created)"
    fi

    # Service 3: Prison Break (60 min, 6 attendants, $48)
    if echo "$EXISTING" | grep -qw 3; then
        api -X PUT "$EA_URL/api/v1/services/3" \
            -d '{"name":"Prison Break","duration":60,"price":48,"currency":"USD","description":"Can you break free? — EXTREME difficulty","attendantsNumber":6,"availabilitiesType":"flexible"}' > /dev/null
        ok "Service 3: Prison Break (updated)"
    else
        api -X POST "$EA_URL/api/v1/services" \
            -d '{"name":"Prison Break","duration":60,"price":48,"currency":"USD","description":"Can you break free? — EXTREME difficulty","attendantsNumber":6,"availabilitiesType":"flexible"}' > /dev/null
        ok "Service 3: Prison Break (created)"
    fi

    # Service 4: Zombie Lab (60 min, 8 attendants, $50)
    if echo "$EXISTING" | grep -qw 4; then
        api -X PUT "$EA_URL/api/v1/services/4" \
            -d '{"name":"Zombie Lab","duration":60,"price":50,"currency":"USD","description":"Find the cure before it'\''s too late — HARD difficulty","attendantsNumber":8,"availabilitiesType":"flexible"}' > /dev/null
        ok "Service 4: Zombie Lab (updated)"
    else
        api -X POST "$EA_URL/api/v1/services" \
            -d '{"name":"Zombie Lab","duration":60,"price":50,"currency":"USD","description":"Find the cure before it'\''s too late — HARD difficulty","attendantsNumber":8,"availabilitiesType":"flexible"}' > /dev/null
        ok "Service 4: Zombie Lab (created)"
    fi

    # Assign all services to the provider and set working hours
    step "Configuring provider"

    api -X PUT "$EA_URL/api/v1/providers/2" \
        -d '{
            "firstName":"Padlockd","lastName":"Venue",
            "email":"venue@padlockd.com",
            "services":[1,2,3,4],
            "settings":{
                "username":"padlockd-venue",
                "notifications":true,
                "googleSync":false,
                "syncPastDays":30,
                "syncFutureDays":90,
                "workingPlan":{
                    "monday":{"start":"19:00","end":"23:59","breaks":[]},
                    "tuesday":{"start":"19:00","end":"23:59","breaks":[]},
                    "wednesday":{"start":"19:00","end":"23:59","breaks":[]},
                    "thursday":{"start":"19:00","end":"23:59","breaks":[]},
                    "friday":{"start":"19:00","end":"23:59","breaks":[]},
                    "saturday":{"start":"19:00","end":"23:59","breaks":[]},
                    "sunday":{"start":"19:00","end":"23:59","breaks":[]}
                }
            }
        }' > /dev/null
    ok "Provider 2: Padlockd Venue (19:00-23:59 daily, 4 services)"

    # Disable phone number requirement
    api -X PUT "$EA_URL/api/v1/settings/require_phone_number" \
        -d '{"value":"0"}' > /dev/null
    ok "Phone number requirement disabled"

    # Create webhook (idempotent — check first)
    step "Configuring webhook"

    WEBHOOK_EXISTS=$(api "$EA_URL/api/v1/webhooks" | python3 -c "
import sys, json
data = json.loads(sys.stdin.read())
print('yes' if any('padlockd' in (w.get('name','').lower()) for w in data) else 'no')
" 2>/dev/null || echo "no")

    if [ "$WEBHOOK_EXISTS" = "yes" ]; then
        ok "Webhook already configured"
    else
        api -X POST "$EA_URL/api/v1/webhooks" \
            -d "{
                \"name\":\"Padlockd PWA Cache Invalidation\",
                \"url\":\"http://host.docker.internal:3000/api/webhooks/easyappointments\",
                \"actions\":\"[\\\"appointment_save\\\",\\\"appointment_delete\\\"]\",
                \"secretToken\":\"$WEBHOOK_SECRET\",
                \"isSslVerified\":false,
                \"notes\":\"Invalidates Next.js ISR cache when bookings change\"
            }" > /dev/null
        ok "Webhook created (targets localhost:3000)"
    fi

# =============================================================================
# VERIFICATION — confirm everything the PWA needs is working
# =============================================================================

step "Running integration readiness checks"

PASS=0
TOTAL=0

check() {
    TOTAL=$((TOTAL + 1))
    local desc="$1"
    if eval "$2" > /dev/null 2>&1; then
        ok "$desc"
        PASS=$((PASS + 1))
    else
        fail "$desc"
    fi
}

# 1. HTTP responds
check "EA responds on $EA_URL" \
    "curl -sf $EA_URL/ > /dev/null"

# 2. API auth works
check "API authentication (Bearer token)" \
    "api '$EA_URL/api/v1/services' | grep -q 'Mastermind'"

# 3. All 4 services exist
check "4 escape room services exist" \
    "[ \$(api '$EA_URL/api/v1/services' | python3 -c 'import sys,json; print(len(json.loads(sys.stdin.read())))' 2>/dev/null) -ge 4 ]"

# 4. Service details correct
check "Service 1: Mastermind Heist (60 min, 8 cap)" \
    "api '$EA_URL/api/v1/services/1' | python3 -c 'import sys,json; s=json.loads(sys.stdin.read()); assert s[\"duration\"]==60 and s[\"attendantsNumber\"]==8, f\"got {s}\"'"

check "Service 2: Zodiac Killer (60 min, 6 cap)" \
    "api '$EA_URL/api/v1/services/2' | python3 -c 'import sys,json; s=json.loads(sys.stdin.read()); assert s[\"duration\"]==60 and s[\"attendantsNumber\"]==6'"

check "Service 3: Prison Break (60 min, 6 cap)" \
    "api '$EA_URL/api/v1/services/3' | python3 -c 'import sys,json; s=json.loads(sys.stdin.read()); assert s[\"duration\"]==60 and s[\"attendantsNumber\"]==6'"

check "Service 4: Zombie Lab (60 min, 8 cap)" \
    "api '$EA_URL/api/v1/services/4' | python3 -c 'import sys,json; s=json.loads(sys.stdin.read()); assert s[\"duration\"]==60 and s[\"attendantsNumber\"]==8'"

# 5. Provider configured with all 4 services
check "Provider 2 has all 4 services assigned" \
    "api '$EA_URL/api/v1/providers/2' | python3 -c 'import sys,json; p=json.loads(sys.stdin.read()); assert set(p[\"services\"])=={1,2,3,4}'"

# 6. Provider working hours cover evening slots
check "Provider working hours start at 19:00" \
    "api '$EA_URL/api/v1/providers/2' | python3 -c '
import sys,json
p=json.loads(sys.stdin.read())
wp=p[\"settings\"][\"workingPlan\"]
assert all(wp[d][\"start\"]==\"19:00\" for d in wp), f\"bad hours: {wp}\"
'"

# 7. Availability API returns expected slots
TOMORROW=$(date -v+1d '+%Y-%m-%d' 2>/dev/null || date -d '+1 day' '+%Y-%m-%d' 2>/dev/null || date '+%Y-%m-%d')

check "Availability API returns slots for tomorrow ($TOMORROW)" \
    "api '$EA_URL/api/v1/availabilities?serviceId=1&providerId=2&date=$TOMORROW' | python3 -c '
import sys,json
slots=json.loads(sys.stdin.read())
assert \"19:30\" in slots, f\"19:30 missing from {slots}\"
'"

# 8. Phone requirement disabled
check "Phone number requirement disabled" \
    "api '$EA_URL/api/v1/settings/require_phone_number' | grep -q '\"0\"'"

# 9. Webhook configured
check "Webhook configured for appointment events" \
    "api '$EA_URL/api/v1/webhooks' | python3 -c '
import sys,json
hooks=json.loads(sys.stdin.read())
assert any(\"appointment_save\" in h.get(\"actions\",\"\") for h in hooks)
'"

# 10. Sync-status endpoint works
check "Sync-status API endpoint responds" \
    "api '$EA_URL/api/v1/sync-status' | grep -q 'googleSyncEnabled'"

# 11. Customer creation with empty phone works
check "Customer creation with empty phone" \
    "api -X POST '$EA_URL/api/v1/customers' -d '{\"firstName\":\"Smoke\",\"lastName\":\"Test\",\"email\":\"smoketest-'$$'@test.dev\",\"phone\":\"\"}' | grep -q '\"id\"'"

# --- Results ------------------------------------------------------------------
echo ""
echo -e "${BOLD}═══════════════════════════════════════════════════════════${NC}"

if [ "$PASS" -eq "$TOTAL" ]; then
    echo -e "${GREEN}${BOLD}  ALL $TOTAL CHECKS PASSED${NC}"
else
    echo -e "${RED}${BOLD}  $PASS/$TOTAL CHECKS PASSED — $(($TOTAL - $PASS)) FAILED${NC}"
fi

echo -e "${BOLD}═══════════════════════════════════════════════════════════${NC}"
echo ""
echo -e "  ${BOLD}EA Admin UI${NC}      $EA_URL/index.php/user/login"
echo -e "  ${BOLD}Credentials${NC}      $ADMIN_USERNAME / $ADMIN_PASSWORD"
echo -e "  ${BOLD}API Token${NC}        $API_TOKEN"
echo -e "  ${BOLD}Provider ID${NC}      2"
echo -e "  ${BOLD}Service IDs${NC}      1=Heist  2=Zodiac  3=Prison  4=Zombie"
echo -e "  ${BOLD}Webhook Secret${NC}   $WEBHOOK_SECRET"
echo ""
echo -e "  ${BOLD}PWA .env.local:${NC}"
echo "    EA_BASE_URL=$EA_URL"
echo "    EA_API_KEY=$API_TOKEN"
echo "    EA_PROVIDER_ID=2"
echo "    EA_SERVICE_MASTERMIND_HEIST=1"
echo "    EA_SERVICE_ZODIAC_KILLER=2"
echo "    EA_SERVICE_PRISON_BREAK=3"
echo "    EA_SERVICE_ZOMBIE_LAB=4"
echo "    EA_WEBHOOK_SECRET=$WEBHOOK_SECRET"
echo ""

if [ "$PASS" -ne "$TOTAL" ]; then
    exit 1
fi

# Declarative Vertical Seeding + Infra Right-Sizing

**Date:** 2026-08-03
**Status:** Proposed
**Builds on:** `docs/superpowers/specs/2026-07-02-gcp-terraform-deployment-design.md`
**Supersedes:** the manual portion of `scripts/provision-instance.sh` and the Padlockd-specific seeding in `scripts/start-local.sh`

## Problem

Onboarding a new PitchTalks vertical onto EasyAppointments costs real money and real time, and both costs scale the wrong way.

**Cost.** The GCP stack bills ~$165–175/month in shared resources before a single vertical exists, plus ~$8–13/month per vertical. The shared tier is over-provisioned for the workload — a 100 GB regional SSD and a regional-HA `db-n1-standard-1` for databases holding appointments and customers.

**Ops.** `scripts/provision-instance.sh` creates only the MySQL database and user, then prints env vars. Everything the PWA actually depends on is manual admin-UI work per vertical:

1. Run the installation wizard
2. Create the admin user
3. Set `api_token`
4. Create the provider and its working plan → becomes `EA_PROVIDER_ID`
5. Create the services → become `EA_SERVICE_*` env vars
6. Register the webhook with its secret
7. Toggle settings (`require_phone_number`, timezone, ...)

This has now been done by hand twice — for Padlockd (scripted ad hoc in `scripts/start-local.sh`) and for Zeekr Gold Coast (whose `lib/booking/schedule.ts` reads service IDs a human transcribed from the admin UI). It is error-prone, unreviewable, and unrepeatable.

### Rejected: a single centralized EasyAppointments instance

The originating question was whether one EA instance could serve every vertical's booking needs. It cannot, without forking EA into a product we would own permanently. EasyAppointments has no tenancy model — there is no tenant/org column on any table. Four blockers, all verified in this repo:

1. **One API token for the entire install.** `application/libraries/Api.php:66` authenticates against `setting('api_token')` — a single row in `ea_settings`. Every vertical's backend would hold the same bearer token, and `/api/v1/customers` is unscoped. Any vertical could read every other vertical's customer PII.
2. **`ea_settings` is a flat global key-value table.** `company_name`, `default_timezone`, `theme`, `company_logo`, `future_booking_limit`, `require_phone_number` and ~35 others are single-valued. Padlockd requires `require_phone_number: false` (its PWA sends an empty phone); a dealership requires it true. Direct, unresolvable collision.
3. **The public booking page is unfiltered.** `application/controllers/Booking.php:137` calls `get_available_services(true)` with no scoping — every vertical's services appear in every other vertical's dropdown.
4. **RBAC is capability-based, not row-scoped.** `Roles_model::get_permissions_by_slug()` returns view/add/edit/delete flags per *resource type*. The only row-level scoping in EA is `secretaries → providers`, and the API-token path does not consult it.

Beyond the security problems, centralizing does not address either stated pain. It saves only the per-vertical Cloud Run cost (~$8–13/mo each) while leaving the dominant shared costs untouched — and it does not reduce ops work at all, since each vertical still needs its own provider, services, and working plan. Same clicks, one admin UI instead of N, minus the blast-radius isolation.

The existing per-instance Terraform design is retained unchanged.

## Solution

Two independent workstreams with no coupling between them.

**A. Infra right-sizing** — Terraform module defaults and tfvars. No application change.

**B. `ea-seed`** — a per-vertical declarative manifest plus an idempotent seeder that takes a freshly-applied, empty EA instance to fully configured and emits the PWA's env vars.

## Architecture

The seeder runs in two phases, forced by a bootstrap dependency: `api_token` is itself a setting, so the API cannot be authenticated against until it exists.

```
Phase 0 (SQL, in-container)      Phase 1 (REST, idempotent, re-runnable)
─────────────────────────        ──────────────────────────────────────
admin user + salt/hash       →   PUT  /api/v1/settings/<key>
api_token                        POST /api/v1/service_categories
                                 POST /api/v1/services        → service ids
                                 POST /api/v1/providers       → provider id
                                 POST /api/v1/webhooks
                                           ↓
                                 emit .env.<vertical> for the PWA
```

Phase 1 is pure HTTP, so the same seeder targets local Docker, Cloud Run, or the legacy Hetzner host with only `EA_BASE_URL` changing.

### Runtime

A PHP CLI script, `scripts/ea-seed.php`, executed inside the EA container — via `docker compose exec` locally and as a **Cloud Run Job on the same production image** in GCP.

This is forced by Phase 0: hashing the admin password requires `hash_password($salt, $password)` and `generate_salt()` from `application/helpers/password_helper.php:28,53` (SHA-256, salted, 100K iterations). Reimplementing that outside the container silently produces an unusable account. Running in-container also provides the DB connection, `ext-curl`, and `ext-json` with no new toolchain in a PHP repository.

**New dependency:** `symfony/yaml` in `composer.json` require (~50KB, pure PHP, MIT — GPL-3.0 compatible). Manifests are the human-facing interface across ~20 verticals and JSON's lack of comments is a material ergonomic cost there.

### API surface used

All verified present:

| Resource | Route |
|---|---|
| settings | `PUT /api/v1/settings/(:any)` (`routes.php:170`) |
| service_categories | `route_api_resource` (`routes.php:150`) |
| services | `route_api_resource` (`routes.php:158`) |
| providers | `route_api_resource` (`routes.php:154`) |
| webhooks | `route_api_resource` (`routes.php:162`) |

Field names map 1:1 to each model's `api_encode`/`api_decode`.

## The manifest

`verticals/<product>.yml`, committed to this repository.

```yaml
product: zeekr-gold-coast          # must match the Terraform products map key
base_url: https://zeekr-gold-coast.bookings.pitchtalks.com

# ── Phase 0 (SQL): bootstrap ────────────────────────────────────────────
admin:
  first_name: Zeekr
  last_name: Admin
  email: admin@zeekrgc.com.au
  username: zeekradmin
  password_env: EA_ADMIN_PASSWORD
api_token_env: EA_API_KEY

# ── Phase 1: PUT /api/v1/settings/<key> ─────────────────────────────────
settings:
  company_name: Zeekr Gold Coast
  company_email: bookings@zeekrgc.com.au
  company_link: https://zeekrgc.com.au
  default_timezone: Australia/Brisbane
  require_phone_number: true
  require_first_name: true
  require_last_name: true
  display_notes: true
  future_booking_limit: 60
  book_advance_timeout: 30

# ── POST /api/v1/service_categories ─────────────────────────────────────
service_categories:
  - name: Sales

# ── POST /api/v1/services ───────────────────────────────────────────────
services:
  - key: TEST_DRIVE                 # → EA_SERVICE_TEST_DRIVE=<assigned id>
    name: Zeekr 7X Test Drive       # natural key for idempotent matching
    duration: 60                    # API: duration
    attendants_number: 1            # API: attendantsNumber
    category: Sales                 # resolved → serviceCategoryId
    price: 0
    currency: AUD
  - key: SHOWROOM
    name: Showroom Visit
    duration: 30
    attendants_number: 6
    category: Sales
  - key: FINANCE
    name: Finance Consultation
    duration: 45
    attendants_number: 2
    category: Sales
  - key: TRADEIN
    name: Trade-in Appraisal
    duration: 30
    attendants_number: 2
    category: Sales

# ── POST /api/v1/providers ──────────────────────────────────────────────
providers:
  - key: PRIMARY                    # → EA_PROVIDER_ID=<assigned id>
    first_name: Zeekr
    last_name: Sales Team
    email: sales@zeekrgc.com.au     # natural key for idempotent matching
    timezone: Australia/Brisbane
    services: [TEST_DRIVE, SHOWROOM, FINANCE, TRADEIN]
    settings:
      username: zeekrsales
      password_env: EA_PROVIDER_PASSWORD
      working_plan:                 # API: settings.workingPlan
        monday:    { start: "09:00", end: "17:00", breaks: [{ start: "12:00", end: "13:00" }] }
        tuesday:   { start: "09:00", end: "17:00", breaks: [] }
        wednesday: { start: "09:00", end: "17:00", breaks: [] }
        thursday:  { start: "09:00", end: "17:00", breaks: [] }
        friday:    { start: "09:00", end: "17:00", breaks: [] }
        saturday:  { start: "09:00", end: "16:00", breaks: [] }
        sunday:    null             # closed

# ── POST /api/v1/webhooks ───────────────────────────────────────────────
webhooks:
  - name: PWA reconcile
    url: https://zeekrgc.pitchtalks.com/api/webhooks/easyappointments
    actions: [appointment_save, appointment_delete]
    secret_token_env: EA_WEBHOOK_SECRET
    is_ssl_verified: true
```

### Design decisions

**`key` is the output contract.** EA assigns numeric IDs at creation and provides no external-id column to pin them against. Today `lib/booking/schedule.ts` in the Zeekr PWA papers over this with `EA_SERVICE_TEST_DRIVE`-style env vars a human filled in from the admin UI. The manifest makes that mapping the seeder's emitted output instead of a manual transcription step.

**Secrets are `*_env` references only.** Manifests are committed. Passwords, API tokens, and webhook secrets resolve from the environment — Secret Manager in production. No manifest contains a credential.

**Natural keys, no statefile.** There is nothing to drift or lose, and re-running against a live instance converges it.

### Output destination

`apply` and `env` write to `./.env.<product>` in this repository (gitignored), containing only the values the PWA needs:

```
EA_BASE_URL=https://zeekr-gold-coast.bookings.pitchtalks.com
EA_PROVIDER_ID=2
EA_SERVICE_TEST_DRIVE=7
EA_SERVICE_SHOWROOM=8
EA_SERVICE_FINANCE=9
EA_SERVICE_TRADEIN=10
EA_TIMEZONE=Australia/Brisbane
```

Transferring these into the consuming PWA's environment (Cloudflare/Vercel project settings) stays manual and deliberate — the seeder does not hold credentials for the PWA platforms. `EA_API_KEY` and `EA_WEBHOOK_SECRET` are *inputs* resolved from Secret Manager, not outputs, and are never written to this file.

The variable names match what `lib/booking/schedule.ts` already reads in the Zeekr PWA, so no PWA change is required to adopt this.

## Commands

```
ea-seed plan  verticals/zeekr-gold-coast.yml    # dry-run diff, writes nothing
ea-seed apply verticals/zeekr-gold-coast.yml    # converge + emit .env
ea-seed env   verticals/zeekr-gold-coast.yml    # re-emit .env from live ids, no writes
```

`plan` is what makes applying against a live production instance reviewable.

Example `apply` output:

```
✓ settings             10 applied
✓ service_categories   Sales → 1
✓ services             TEST_DRIVE → 7  SHOWROOM → 8  FINANCE → 9  TRADEIN → 10
✓ providers            PRIMARY → 2
✓ webhooks             PWA reconcile → 1
→ wrote .env.zeekr-gold-coast
```

## Idempotency

Each resource converges against a natural key read back from the API.

| Resource | Match on | Absent | Present |
|---|---|---|---|
| settings | key | — | `PUT` if value differs |
| service_categories | `name` | `POST` | reuse id |
| services | `name` | `POST` | `PUT` if any field differs |
| providers | `email` | `POST` | `PUT` (incl. `workingPlan`, service links) |
| webhooks | `name` | `POST` | `PUT` if url/actions differ |

A manifest `key` matching nothing while its siblings do match emits a warning — this is the rename-created-a-duplicate case, and silence there is how duplicate services accumulate.

## Known traps the seeder must encode

These are established behaviors of this codebase, each of which has already cost debugging time:

1. **`ea_settings.name` is not unique.** The primary key is auto-increment `id`. Phase 0 must `UPDATE ... WHERE name = ?` for keys the migrations already created and `INSERT` only for genuinely absent ones. `INSERT ... ON DUPLICATE KEY UPDATE` duplicates rows silently.
2. **`webhooks.actions` is a TEXT column holding a JSON *string*.** The seeder serializes the manifest's `actions` array to `"[\"appointment_save\",\"appointment_delete\"]"`, not an array.
3. **Password hashing must use `password_helper.php`.** SHA-256 with salt, 100K iterations. This is why Phase 0 runs in-container.
4. **`is_app_installed()` checks `$CI->db->table_exists('users')`.** Migrations create tables, so the installation wizard reports the app as already installed on a migrated-but-empty database. The seeder must detect "installed" by row presence, not table presence.
5. **CSRF is enforced on installation endpoints** — they are not in `csrf_exclude_uris` (`config.php:410`, which covers `api/v1/.*` and the booking routes only). Phase 0 avoids the HTTP installation flow entirely for this reason.
6. **`working_plan` is day-bounded.** `start`/`end` per weekday, maximum `23:59`, no cross-midnight spans. Padlockd's escape rooms already sit against this ceiling at `19:00–23:59`.

## Error handling

Phase 0 is a single transaction. Phase 1 is REST and cannot be atomic, so the contract is *ordered, independently re-runnable steps*:

```
settings → service_categories → services → providers → webhooks
                                    └─ ids ─────┘
```

- Any step fails → exit non-zero, print what was created, **do not write `.env`**. A partial env file is worse than none: the PWA boots with three of four service IDs and fails at runtime rather than at deploy time.
- Re-running after a failure converges from wherever it stopped.
- Manifest validation runs before any write. Rejected at parse time:
  - `working_plan` with `end < start` on any day, with the day-bounded explanation
  - a provider referencing a service `key` not declared in the manifest
  - duplicate `key` values within `services` or `providers`
  - a `*_env` reference whose environment variable is unset

## Testing

- **Unit** — manifest validation: each rejection case above.
- **Integration** — against `docker-compose.local.yml`: apply, snapshot all assigned IDs, apply again, assert byte-identical output and zero writes. This is the test that matters; idempotency is the entire value proposition.
- **Retrofit as validation** — rewrite `scripts/start-local.sh` as a thin wrapper around `ea-seed apply verticals/padlockd-local.yml`. Reproducing the existing Padlockd setup (4 escape-room services, provider 2, evening hours, webhook, `require_phone_number: false`) validates the seeder against a real vertical rather than a synthetic one.

## Workstream A: infra right-sizing

Separate, mergeable independently of the seeder.

| File | Change | Est. saving |
|---|---|---|
| `infra/terraform/modules/cloud-sql/variables.tf:16` | `disk_gb` default `100 → 20` | ~−$27/mo |
| `infra/terraform/modules/cloud-sql/variables.tf:11` | `tier` default `db-n1-standard-1 → db-custom-1-3840` | ~−$25/mo |
| `infra/terraform/modules/cloud-sql/main.tf:11` | `availability_type` hardcoded `REGIONAL` → variable, default `ZONAL` | ~−$67/mo |
| `infra/terraform/terraform.tfvars` | `min_instances: 0` per low-traffic product (knob exists at `variables.tf:42`) | ~−$10/mo each |

Total ~$120/month before per-product savings.

Accepted trade-offs, recorded explicitly:

- **`ZONAL`** trades a few minutes of unavailability during a zone failure for ~$67/month. Appropriate for booking widgets; revisit per-environment if any vertical takes on an uptime commitment.
- **`min_instances: 0`** introduces a 2–5s cold start on the nginx + PHP-FPM container for the first request after idle. Acceptable for low-traffic verticals; retain `1` for any currently-live vertical.
- **`disk_gb`** must be verified against actual database size before applying. Cloud SQL disk grows but never shrinks — this change is irreversible on an existing instance.

Cost figures are us-central1 list-price estimates and should be confirmed against the billing console after the first full month.

## Out of scope

- Extracting the duplicated PWA-side booking client (`lib/booking/{ea-client,schedule,types}.ts`, the four `/api/ea/*` routes, the webhook handler) into a shared package. Real duplication, but it lives in the PWA repositories, not here, and neither stated pain points at it.
- Cross-vertical reporting. The Supabase `appointments` table with its `client_id` column — already dual-written by `app/api/ea/bookings/route.ts` and reconciled by the EA webhook handler — is the correct place for this, and it already exists.
- Any modification to EasyAppointments application code.

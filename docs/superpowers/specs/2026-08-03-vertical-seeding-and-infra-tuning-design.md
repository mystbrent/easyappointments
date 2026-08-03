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

## Success criteria

This work is complete when a single seeder, driven only by per-vendor manifests, provides working booking capability for **both** of the following storefronts — two unrelated organizations with incompatible requirements.

### Vendor A — Zeekr Gold Coast

`~/PitchTalks/pt-selfsell-zeekr-gold-coast-app` · EV dealership · Next.js on Cloudflare

| Requirement | Value |
|---|---|
| Services | 4, **each a different duration**: Test Drive 60m, Showroom 30m, Finance 45m, Trade-in 30m |
| Capacity | `attendantsNumber` 1 / 6 / 2 / 2 |
| Hours | Daytime — Mon–Fri 09:00–17:00 (lunch break), Sat 09:00–16:00, Sun closed |
| Phone number | **Required** |
| Consumed by | `lib/booking/schedule.ts`, `app/api/ea/{services,slots,bookings}`, `app/api/webhooks/easyappointments` |
| Env contract | `EA_BASE_URL`, `EA_API_KEY`, `EA_PROVIDER_ID`, `EA_SERVICE_{TEST_DRIVE,SHOWROOM,FINANCE,TRADEIN}`, `EA_WEBHOOK_SECRET`, `EA_TIMEZONE` |

### Vendor B — Lostlocks

`~/PitchTalks/Verticals/escape-rooms/lostlocks` · escape-room operator · Next.js + a separate Cloudflare Worker voice agent (`lostlocks-pwa`, `lostlocks-agent`)

| Requirement | Value |
|---|---|
| Services | 4 rooms, **all fixed 60m** (`app/api/bookings/route.ts:53`) |
| Capacity | Party size passed as `playerCount`, recorded in appointment notes |
| Hours | Evening — sessions at 19:30 / 21:00 / 21:30 / 22:30, **and 00:00 for Mastermind Heist** (`app/api/slots/[roomId]/route.ts:15`) |
| Phone number | **Optional** — the storefront submits an empty phone |
| Approval gating | `EA_GATED_SERVICE_IDS` — comma-separated service IDs that cannot be booked directly and require owner approval (`worker/wrangler.toml:135`) |
| Env contract | `EA_BASE_URL`, `EA_API_KEY`, `EA_PROVIDER_ID`, `EA_SERVICE_{MASTERMIND_HEIST,ZODIAC_KILLER,PRISON_BREAK,ZOMBIE_LAB}`, `EA_WEBHOOK_SECRET`, `EA_GATED_SERVICE_IDS` |

### Criteria

1. **Both vendors are seeded from manifests alone.** No admin-UI clicking, no hand-transcribed IDs, no per-vendor branch in the seeder. The only difference between them is the content of two YAML files.
2. **Each emitted `.env` satisfies its storefront's full env contract**, with variable names matching what the code already reads — neither storefront requires a code change to adopt this.
3. **The conflicting settings coexist.** Zeekr's `require_phone_number: true` and Lostlocks' `false` are both in effect simultaneously, on separate instances. Asserted by test, since this is the property a shared instance could not provide.
4. **Lostlocks' 00:00 session is bookable.** The midnight slot resolves to real availability, not an empty slot list.
5. **`EA_GATED_SERVICE_IDS` is emitted from the manifest**, resolving service keys to the numeric IDs assigned at seed time. Zeekr's manifest omits the concept entirely and its `.env` is unaffected.
6. **Re-running the seeder against either live instance changes nothing** and re-emits byte-identical env files.

### What criterion 4 forces

Working plans are day-bounded with one `start`/`end` per weekday, so an evening block and a 00:00 session cannot both be expressed as a simple range. They can be expressed using **breaks**: a plan running `00:00`–`23:59` with a break covering the closed middle of the day yields availability at both ends.

Requiring humans to write that inversion by hand is error-prone, so the manifest accepts the sessions a vendor actually operates and the seeder compiles them:

```
manifest                                   compiled working plan
─────────────────────────────────────      ─────────────────────────────────
sessions:                              →   start:  "00:00"
  - { start: "00:00", end: "01:00" }       end:    "23:59"
  - { start: "19:30", end: "23:59" }       breaks: [{ "01:00" → "19:30" }]
```

This replaces the earlier plan of rejecting any `end` earlier than its `start`. That validation was necessary but insufficient — Lostlocks has a legitimate midnight session, and a rule that only rejects would have blocked a real vendor.

### A compiled plan is not the whole story

Availability is time-of-query dependent, so a correct working plan does not guarantee a slot appears right now. `book_advance_timeout` (default 30 minutes, evaluated in the **provider's** timezone) withholds any slot inside its notice window. Querying "tomorrow at 00:00" shortly before local midnight therefore legitimately returns nothing, even though the plan is stored correctly.

This cost a full acceptance round before being diagnosed. Any test asserting on a near-boundary slot should query several days out, and a vendor reporting "my midnight session isn't bookable" should have `book_advance_timeout` checked before the working plan is doubted.

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

A PHP CLI script, `scripts/ea-seed.php`, executed inside the EA container — via `docker compose exec` locally and as a **Cloud Run Job on the same production image** in GCP. `Dockerfile.production` must therefore copy both `scripts/` and `verticals/` into the runtime stage; the job has nothing to seed from otherwise.

### Two endpoints, not one

A manifest's `base_url` is **what the storefront is told** — it becomes `EA_BASE_URL` in the emitted env file and must be the externally reachable URL. It is not necessarily reachable from wherever the seeder itself runs.

When the CLI runs *inside* the instance it is configuring, the published host port does not exist in the container's own network namespace: `http://localhost:8080` is refused there, while `http://localhost` (port 80) serves. Set `EA_SEED_ENDPOINT` to override the address the seeder uses for its own API calls:

```
docker compose exec -e EA_SEED_ENDPOINT=http://localhost ea-zeekr-gold-coast \
  php /var/www/html/scripts/ea-seed.php apply /var/www/html/verticals/zeekr-gold-coast.local.yml
```

`EA_BASE_URL` in the emitted file is unaffected — it always carries the manifest's `base_url`. Unset, the seeder addresses `base_url` directly, which is correct when it runs outside the instance (a Cloud Run Job against a public URL).

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
    requires_approval: false        # optional; true → id joins EA_GATED_SERVICE_IDS.
                                    # NOT sent to EA — it has no such field. This
                                    # exists solely to populate the storefront's env.
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
    phone: "+61 7 5555 0100"        # required — Providers_model::validate() rejects an empty phone_number
    timezone: Australia/Brisbane
    services: [TEST_DRIVE, SHOWROOM, FINANCE, TRADEIN]
    settings:
      username: zeekrsales
      password_env: EA_PROVIDER_PASSWORD
      # Sessions the vendor actually operates. The seeder compiles these into
      # EA's single start/end + breaks per day (see "What criterion 4 forces").
      working_plan:
        monday:    [{ start: "09:00", end: "12:00" }, { start: "13:00", end: "17:00" }]
        tuesday:   [{ start: "09:00", end: "12:00" }, { start: "13:00", end: "17:00" }]
        wednesday: [{ start: "09:00", end: "12:00" }, { start: "13:00", end: "17:00" }]
        thursday:  [{ start: "09:00", end: "12:00" }, { start: "13:00", end: "17:00" }]
        friday:    [{ start: "09:00", end: "12:00" }, { start: "13:00", end: "17:00" }]
        saturday:  [{ start: "09:00", end: "16:00" }]
        sunday:    []               # closed

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

Vendors whose manifests declare `requires_approval` on any service additionally receive the resolved id list — Lostlocks emits `EA_GATED_SERVICE_IDS=1,4` where Zeekr emits the variable not at all:

```
EA_GATED_SERVICE_IDS=1,4
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

A manifest `key` matching nothing while its siblings do match is the rename-created-a-duplicate case, and silence there is how duplicate services accumulate. **Not yet implemented** — no code path emits this warning today; it remains an open follow-up.

Webhook `secret_token` is write-only: EA does not return it, so drift cannot be detected by comparison. A matched webhook is therefore always written rather than reported as unchanged, so that rotating a secret in the environment actually reaches the instance.

Every collection read is paginated. EA's API defaults to 20 rows per page (`Api::$default_length`), so an unpaginated GET truncates and makes records beyond the first page read as *absent* — which would make a converger create duplicates on every run. All collection reads go through `EaClient::getAll()`.

## Known traps the seeder must encode

These are established behaviors of this codebase, each of which has already cost debugging time:

1. **`ea_settings.name` is not unique.** The primary key is auto-increment `id`. Phase 0 must `UPDATE ... WHERE name = ?` for keys the migrations already created and `INSERT` only for genuinely absent ones. `INSERT ... ON DUPLICATE KEY UPDATE` duplicates rows silently.
2. **`webhooks.actions` is a TEXT column holding a JSON *string*.** The seeder serializes the manifest's `actions` array to `"[\"appointment_save\",\"appointment_delete\"]"`, not an array.
3. **Password hashing must use `password_helper.php`.** SHA-256 with salt, 100K iterations. This is why Phase 0 runs in-container.
4. **`is_app_installed()` checks `$CI->db->table_exists('users')`.** Migrations create tables, so the installation wizard reports the app as already installed on a migrated-but-empty database. The seeder must detect "installed" by row presence, not table presence.
5. **CSRF is enforced on installation endpoints** — they are not in `csrf_exclude_uris` (`config.php:410`, which covers `api/v1/.*` and the booking routes only). Phase 0 avoids the HTTP installation flow entirely for this reason.
6. **`working_plan` is day-bounded.** One `start`/`end` per weekday, maximum `23:59`, no cross-midnight spans. Both escape-room vendors sit against this ceiling — Padlockd at `19:00–23:59`, Lostlocks with a `00:00` session. The manifest's session list plus break compilation exists precisely to make that representable; see "What criterion 4 forces".

## Error handling

Phase 0 is a single transaction. Phase 1 is REST and cannot be atomic, so the contract is *ordered, independently re-runnable steps*:

```
settings → service_categories → services → providers → webhooks
                                    └─ ids ─────┘
```

- Any step fails → exit non-zero, print what was created, **do not write `.env`**. A partial env file is worse than none: the PWA boots with three of four service IDs and fails at runtime rather than at deploy time.
- Re-running after a failure converges from wherever it stopped.
- Manifest validation runs before any write. Rejected at parse time:
  - a session with `end` earlier than or equal to `start` — no session may span midnight; a midnight session is expressed as a separate `00:00`-starting session, which is what makes Lostlocks' Mastermind Heist slot representable
  - overlapping sessions within one day, which would compile to a contradictory break
  - any session `end` later than `23:59`
  - a provider referencing a service `key` not declared in the manifest
  - duplicate `key` values within `services` or `providers`
  - a `*_env` reference whose environment variable is unset

## Local multi-vendor harness

Validating the seeder requires several vendors with genuinely divergent configuration, and that costs nothing to run locally. `docker-compose.local.yml` is extended from one EA container to N, sharing a single MySQL — **structurally identical to production**, where one Cloud SQL server likewise hosts a separate database and user per vendor.

```
mysql:8.0  (:3307)  ──┬── ea_zeekr_gold_coast   ← ea-zeekr-gold-coast  (:8080)
                      └── ea_lostlocks          ← ea-lostlocks         (:8081)
```

These are the two vendors named in the success criteria. A third instance can be added by copying a service block; nothing about the harness assumes a count.

Implementation notes:

- Per-vendor databases and users are created by a script in `docker-entrypoint-initdb.d`, mirroring what `scripts/provision-instance.sh` does against Cloud SQL.
- All EA services declare the same `image:` tag with the same build context, so the production image is built once and reused rather than built per service.
- Each service differs only in `EA_BASE_URL`, `EA_DB_NAME`, `EA_DB_USERNAME`, `EA_DB_PASSWORD`, and published port.
- `scripts/start-local.sh` becomes the entry point: bring up the stack, wait for readiness, then run `ea-seed apply` for each manifest in `verticals/*.local.yml`.

Because the seeder reaches EA over HTTP for everything after bootstrap, the same manifests target this harness or a deployed instance with only `base_url` changing.

**What this harness cannot tell you:** cold-start latency under `min_instances: 0`, load-balancer and TLS behavior, and Cloud SQL socket connectivity. Those are deployment concerns, not seeding concerns, and are deferred to the first GCP bring-up.

## Testing

- **Unit** — manifest validation: each rejection case above.
- **Integration** — against the local multi-vendor harness: apply, snapshot all assigned IDs, apply again, assert byte-identical output and zero writes. This is the test that matters; idempotency is the entire value proposition.
- **Cross-vendor divergence** (success criterion 3) — seed Zeekr and Lostlocks against the same harness, then assert each instance reports its own `require_phone_number` and its own working plan. This is the property a shared instance could not provide, so it is asserted rather than assumed.
- **Midnight availability** (success criterion 4) — after seeding Lostlocks, query availability for Mastermind Heist and assert `00:00` is returned. This exercises the compiled break inversion end to end rather than only unit-testing the compiler.
- **Gated services** (success criterion 5) — assert Lostlocks' emitted env contains `EA_GATED_SERVICE_IDS` with the ids of exactly the services marked `requires_approval`, and that Zeekr's emitted env omits the variable.
- **Env contract conformance** (success criterion 2) — assert each emitted file contains every variable its storefront reads: the seven Zeekr reads from `lib/booking/schedule.ts` and the ea-prefixed set Lostlocks reads across `app/api/slots/[roomId]/route.ts` and `worker/wrangler.toml`. This is the check that catches a manifest key renamed without its consumer.
- **Retrofit as validation** — rewrite `scripts/start-local.sh` as a thin wrapper around `ea-seed apply`. It currently seeds escape-room data by hand-written SQL; reproducing that through a manifest validates the seeder against setup already known to work.

## Workstream A: infra right-sizing

Separate, mergeable independently of the seeder.

| File | Change | Est. saving |
|---|---|---|
| `infra/terraform/modules/cloud-sql/variables.tf:16` | `disk_gb` default `100 → 20` | ~−$27/mo |
| `infra/terraform/modules/cloud-sql/variables.tf:11` | `tier` default `db-n1-standard-1 → db-custom-1-3840` | ~−$25/mo |
| `infra/terraform/modules/cloud-sql/main.tf:11` | `availability_type` hardcoded `REGIONAL` → variable, default `ZONAL` | ~−$67/mo |
| `infra/terraform/terraform.tfvars` | `min_instances: 0` per low-traffic product (knob exists at `variables.tf:42`) | ~−$10/mo each |
| `infra/terraform/modules/network/main.tf:16` | **Verify then likely remove** the Serverless VPC connector | ~−$18/mo |

Total ~$120/month before per-product savings, or ~$137 if the connector is confirmed removable.

### The VPC connector question

`modules/ea-instance/main.tf:36` comments that the database host is *"resolved via Cloud Run socket"*, and the service mounts the Cloud SQL instance as a volume at `/cloudsql`. If that socket is the actual connection path, the Serverless VPC connector — which exists to give Cloud Run egress into private ranges — is not on the database path at all, yet bills ~$18/month for its minimum two instances.

Before removing it, confirm nothing else depends on private-range egress (`vpc_access.egress` is currently `PRIVATE_RANGES_ONLY`). If confirmed, the connector, its `/28` subnet, and the private services access peering can all be dropped, and `EA_DB_HOST` set to the socket path explicitly rather than to the instance name with a clarifying comment.

## Deferred: trimmed deployment tier

Should a hosted environment be needed before the full stack is warranted, the same Terraform yields a ~$12–15/month configuration for three vendors by omitting modules rather than redesigning:

- Skip the `load-balancer` and `dns` modules; use the `*.run.app` URL Cloud Run assigns each service. These affect only hostname aesthetics — CORS, webhook URLs, and `EA_BASE_URL` are indifferent to them.
- `tier = "db-f1-micro"`, `disk_gb = 10`, `availability_type = "ZONAL"`.
- `min_instances = 0` for every product.

This is the same architecture with modules omitted, not a separate one, so nothing validated against it is invalidated on promotion to the full stack.

Accepted trade-offs, recorded explicitly:

- **`ZONAL`** trades a few minutes of unavailability during a zone failure for ~$67/month. Appropriate for booking widgets; revisit per-environment if any vertical takes on an uptime commitment.
- **`min_instances: 0`** introduces a 2–5s cold start on the nginx + PHP-FPM container for the first request after idle. Acceptable for low-traffic verticals; retain `1` for any currently-live vertical.
- **`disk_gb`** must be verified against actual database size before applying. Cloud SQL disk grows but never shrinks — this change is irreversible on an existing instance.

Cost figures are us-central1 list-price estimates and should be confirmed against the billing console after the first full month.

## Out of scope

- Extracting the duplicated PWA-side booking client (`lib/booking/{ea-client,schedule,types}.ts`, the four `/api/ea/*` routes, the webhook handler) into a shared package. Real duplication, but it lives in the PWA repositories, not here, and neither stated pain points at it.
- Cross-vertical reporting. The Supabase `appointments` table with its `client_id` column — already dual-written by `app/api/ea/bookings/route.ts` and reconciled by the EA webhook handler — is the correct place for this, and it already exists.
- Any modification to EasyAppointments application code.

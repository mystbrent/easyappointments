# GCP Terraform Deployment of EasyAppointments Multi-Instance Stack

**Date:** 2026-07-02
**Status:** Proposed
**Replaces:** Hetzner CX22 + Coolify manual deployment (see `docs/deployment-guide.md`)
**Approach:** GCP-native serverless (Cloud Run + Cloud SQL), defined entirely as Terraform

## Problem

The current production deployment runs on a single **Hetzner CX22** VPS managed by hand through **Coolify**. While functional, this approach has structural problems that block scale and reliability:

1. **Manual, stateful, snowflake.** Every instance is created by clicking through the Coolify UI — no version-controlled infrastructure, no drift detection, no rollback. The server is a hand-tuned machine, not a reproducible artifact.
2. **Single point of failure.** One VPS hosts the reverse proxy, all containers, and the shared MySQL. A kernel panic, disk failure, or botched SSH session takes down every product's booking page at once.
3. **No declarative provisioning.** Adding an instance requires running `scripts/provision-instance.sh` against a live MySQL and then manually pasting env vars into the Coolify UI. There is no "create instance" that is reviewable in a PR.
4. **Backups are a cron on the box.** `scripts/setup-backup-cron.sh` writes dumps to `/opt/backups` on the same host. Lose the host, lose the backups.
5. **Patching is SSH-and-pray.** Kernel upgrades, Docker upgrades, Coolify upgrades all happen over SSH on a live production machine.

We need the same multi-instance shape (one image, N product instances, shared MySQL, per-instance DB + secrets, wildcard SSL) but as **reproducible infrastructure on GCP defined entirely in Terraform** — so a new instance is a one-line addition to a `products` map, reviewed and applied via CI.

## Solution

Migrate the existing production Docker image (unchanged — `Dockerfile.production`) to **Google Cloud Run**, backed by **Cloud SQL for MySQL 8.0**, fronted by a **Global External HTTPS Load Balancer** with managed wildcard SSL, with all secrets in **Secret Manager** and all storage in **GCS**. Every resource is declared in Terraform.

GCP services chosen and why:

| Concern | GCP service | Why this one |
|---|---|---|
| Container runtime | **Cloud Run** | Direct analog of "one image, N instances, per-instance env". Auto-scale, min-instances=1 to kill cold starts, no VM to patch. Closes the Coolify gap entirely. |
| Database | **Cloud SQL for MySQL 8.0** | Same engine/version as current shared MySQL 8.0. Managed HA, automated backups + PITR, private IP via VPC. Shared instance, per-instance DB + user (same isolation model as today). |
| Image registry | **Artifact Registry** | GCP-native, IAM-integrated, same pull credentials story as GHCR. |
| Secrets | **Secret Manager** | Per-instance secret versions, IAM-controlled, referenced by Cloud Run revision. Replaces env vars pasted into Coolify. |
| TLS + routing | **Global External Application Load Balancer** + **Serverless NEG** + **Managed SSL Certificate** | Wildcard `*.bookings.domain` cert, per-subdomain path rules to the matching Cloud Run service. Replaces Traefik/Let's Encrypt. |
| File storage | **GCS buckets** (one per instance, mounted via Cloud Run volume) | Persistent `storage/uploads`. Cloud Run now supports GCS-backed volumes. Replaces Coolify Docker volumes. |
| Backups | **Cloud SQL automated backups + PITR** + **Cloud Run Job** dumping per-DB `mysqldump` → GCS | Replaces the hourly cron on the Hetzner host. |
| DNS | **Cloud DNS** (managed zone for `bookings.domain`) | Fully Terraform-owned `*.bookings` A/AAAA records → LB anycast IPs. Replaces Cloudflare grey-cloud records. |
| CI build | **GitHub Actions** (build → push to Artifact Registry → trigger `terraform apply`) | Keeps the existing GitHub-hosted source flow; just changes the registry target and adds a deploy step. |

Rejected alternatives:
- **GKE / Autopilot** — overkill at 4–10 lightweight instances; adds cluster management overhead we are explicitly trying to remove.
- **GCE VM with Coolify** — would be Hetzner-on-GCP; identical maintenance burden, defeats the purpose.
- **Cloud SQL Auth Proxy sidecar** — unnecessary; Cloud Run connects to Cloud SQL via the Serverless VPC Connector + private IP, no sidecar.

## Architecture

```
                        Cloud DNS (bookings.domain managed zone)
                        *.bookings ──► Global External HTTPS LB (anycast)
                                          │
                        Managed SSL Cert (wildcard *.bookings.domain)
                                          │
                          URL map: host-rule per product subdomain
                                          │
                  ┌───────────────────────┼───────────────────────┐
                  ▼                       ▼                       ▼
         product-a.bookings       product-b.bookings       product-c.bookings
        Serverless NEG ──► Cloud Run svc ea-product-a   (ea-product-b)   (ea-product-c)
                              │  (min=1, max=10, SA: ea-product-a@...)
                              │  env from Secret Manager secrets
                              │  GCS bucket mounted at /var/www/html/storage/uploads
                              │
                              ▼  (via Serverless VPC Connector, private IP)
                        ┌─────────────────────────────────────┐
                        │  Cloud SQL MySQL 8.0 (private IP)   │
                        │  Automated backups + 7-day PITR     │
                        │  ├── Database: ea_product_a         │
                        │  ├── Database: ea_product_b         │
                        │  └── Database: ea_product_c         │
                        │      (each with dedicated user,     │
                        │       least-privilege grants)       │
                        └─────────────────────────────────────┘

  Artifact Registry (ea-production image, pulled by each Cloud Run service)
  GCS: ea-backups-product-a/b/c (lifecycle 30d), uploads buckets per instance
  Cloud Run Job: ea-db-dumper (Cloud Scheduler hourly) → GCS backup buckets
```

Each Cloud Run service runs the **identical** `Dockerfile.production` image (Nginx + PHP-FPM + supervisord, port 80 → Cloud Run ingress port). The container contract is unchanged; only the runtime platform changes.

## Integration Points Per Instance

Identical to the existing spec — preserved unchanged because the container image is unchanged:

| Integration | How It Works |
|---|---|
| REST API (`/api/v1/`) | Product backend (Vercel) calls with Bearer token (unique per instance) |
| Booking page embed | iFrame embed at `https://<subdomain>.bookings.domain/booking` |
| Webhooks | Register per-product webhook URLs with unique secret tokens |
| Google Calendar sync | Per-instance Google OAuth credentials via env vars |
| CalDAV sync | Per-provider CalDAV configuration via admin UI |
| CORS | Restricted to product domains via `EA_CORS_ORIGINS` env var (read from secret) |
| iframe | CSP `frame-ancestors` from `EA_FRAME_ANCESTORS` secret |

The Vercel frontend integration is preserved bit-for-bit — same origins, same Bearer tokens, same iframe embedding. DNS cutover is the only visible change to the frontend.

## Environment Variables

The full `EA_*` env-var contract from the prior spec is preserved **verbatim** — the application code is not modified. The only change is **where the values live**: instead of being pasted into the Coolify UI, each value becomes a **Secret Manager secret** referenced by the Cloud Run service.

For each instance the `ea-instance` Terraform module creates these secrets (one secret per env var, per instance):

| Secret name pattern | Required | Source |
|---|---|---|
| `ea-<product>-base-url` | Yes | Computed: `https://<product>.bookings.domain` |
| `ea-<product>-db-host` | Yes | Cloud SQL private IP (module output) |
| `ea-<product>-db-name` | Yes | `ea_<product>` |
| `ea-<product>-db-username` | Yes | `ea_<product>` |
| `ea-<product>-db-password` | Yes | Random, generated by Terraform (`random_password`) |
| `ea-<product>-encryption-key` | Yes | Random, generated by Terraform (`random_password`, 32 bytes) |
| `ea-<product>-cors-origins` | Production | From `products` map input |
| `ea-<product>-frame-ancestors` | If embedding | From `products` map input |
| `ea-<product>-proxy-ips` | Yes | Hardcoded to LB/trusted-proxy CIDRs |
| `ea-<product>-smtp-*` | For email | Shared SMTP secrets, referenced (one set, all instances) |
| `ea-<product>-google-*` | For calendar | Per-instance, from `products` map input |

SMTP credentials are shared across instances (one set of secrets) and referenced by each Cloud Run service — matching the "shared SMTP" pattern documented in the existing deployment guide.

## File Changes

This spec creates **only new files** under a new `infra/` directory. **No application code is modified.** The existing `Dockerfile.production` and `docker/production/*` are reused as-is.

### New Files

#### `infra/terraform/versions.tf`
Provider pinning: `hashicorp/google` (≥ 5.0), `hashicorp/google-beta`, `hashicorp/random`, `hashicorp/null`. Required Terraform version ≥ 1.6.

#### `infra/terraform/backend.tf`
GCS remote state backend. State bucket created by the bootstrap module (below), then referenced here. State locking via GCS object versioning + generation prefix.

#### `infra/terraform/providers.tf`
Provider configuration: `google` and `google-beta` with `project`, `region`, `zone` from variables. `request_timeout = "60s"` for Cloud SQL long-running operations.

#### `infra/terraform/variables.tf`
Top-level inputs:
- `project_id` — GCP project ID
- `region` — single region for Cloud SQL + Cloud Run (e.g. `us-central1`)
- `domain` — root domain (e.g. `bookings.example.com`); subdomains are `<product>.<domain>`
- `products` — map of product slug → `{ cors_origins, frame_ancestors, google_client_id, google_client_secret, min_instances }`
- `sql_tier` — Cloud SQL machine tier (default `db-n1-standard-1`)
- `smtp_*` — shared SMTP creds (or reference to pre-existing secrets)

#### `infra/terraform/main.tf`
Root composition: instantiates each module (below) in dependency order and wires outputs → inputs.

#### `infra/terraform/terraform.tfvars.example`
Example `products` map and placeholder values; the actual `terraform.tfvars` is gitignored.

#### `infra/terraform/.gitignore`
Ignores `terraform.tfvars`, `.terraform/`, `*.tfstate*`, and any `*-secrets.*` files.

### New Module: `modules/project-bootstrap/`
One-time-per-project module. Creates:
- GCS bucket for Terraform state (`<project>-tfstate`)
- Enables required APIs: `run.googleapis.com`, `sqladmin.googleapis.com`, `secretmanager.googleapis.com`, `compute.googleapis.com`, `dns.googleapis.com`, `artifactregistry.googleapis.com`, `vpcaccess.googleapis.com`, `cloudscheduler.googleapis.com`, `cloudbuild.googleapis.com`
- Default service account locking-down note (no changes to default SA; we create dedicated SAs)

### New Module: `modules/artifact-registry/`
Creates the `ea-production` Docker repo (`google_artifact_registry_repository`, format `DOCKER`, region-scoped) and a dedicated pull service account (`ea-image-puller@`) granted `roles/artifactregistry.reader`. Outputs the repo path and SA email consumed by Cloud Run.

### New Module: `modules/cloud-sql/`
Creates the shared MySQL 8.0 instance:
- `google_sql_database_instance` — MySQL 8.0, private IP via the network module's VPC, `availability_type = "REGIONAL"` (HA), `settings.backup_configuration.enabled = true` + `point_in_time_recovery_enabled = true`, `settings.backup_configuration.start_time = "02:00"`.
- DB disk: 100 GB SSD autoscaling.
- Per-instance `google_sql_database` and `google_sql_user` created here OR in the `ea-instance` module (decision in Open Questions; leaning toward `ea-instance` for cohesion — module creates a DB+user per `each.value`).

### New Module: `modules/network/`
Creates:
- Custom VPC (`google_compute_network`)
- Subnet for the Serverless VPC connector (`google_compute_subnetwork`, `/28`)
- `google_vpc_access_connector` (Serverless connector) so Cloud Run can reach Cloud SQL private IP
- Cloud SQL private services access (`google_compute_global_address`, `/20` for `servicenetworking.googleapis.com`)
- Cloud NAT is NOT required (Cloud Run egresses through the connector only for Cloud SQL)

### New Module: `modules/load-balancer/`
Creates the global HTTPS LB fronting all instances:
- `google_compute_managed_ssl_certificate` — wildcard `*.bookings.domain`
- `google_compute_region_network_endpoint_group` (serverless, one per instance) — points each Cloud Run service
- `google_compute_backend_service` per instance (serverless backend, per-instance NEG)
- `google_compute_url_map` — host-rule per `<product>.bookings.domain` → matching backend
- `google_compute_managed_ssl_certificate` (waits on DNS — see Provisioning Workflow)
- `google_compute_target_https_proxy` + `google_compute_global_forwarding_rule` on `0.0.0.0/0:443`
- HTTP→HTTPS redirect via a second `target_http_proxy` + `url_map` returning `301`

### New Module: `modules/dns/`
Creates the Cloud DNS managed zone for `bookings.domain` (or imports an existing one), and per-instance `google_dns_record_set` A/AAAA records pointing `<product>.bookings.domain` at the LB anycast IP. Records are created from the same `products` map the LB uses.

### New Module: `modules/secrets/`
Shared secrets (one copy, referenced by all instances): SMTP host/user/pass/port/crypto/from-address/from-name. Created via `google_secret_manager_secret` + `google_secret_manager_secret_version`. Each instance gets IAM `roles/secretmanager.secretAccessor` on these.

### New Module: `modules/backup-job/`
Replaces `scripts/setup-backup-cron.sh`:
- GCS bucket `ea-db-backups` with 30-day lifecycle
- `google_cloud_run_v2_job` — `ea-db-dumper`, runs `mysqldump` for each `ea_*` DB → timestamped object in the bucket
- `google_cloud_scheduler_job` — hourly (`0 * * * *`), invokes the job via OIDC-authenticated HTTP
- Weekly promotion job (separate scheduler, Sunday 00:00) copies latest dump to a `weekly/` prefix with 8-week retention — matches existing `scripts/backup-databases.sh` semantics

### New Module: `modules/ea-instance/` (THE reusable per-product module)
This is the Terraform replacement for `scripts/provision-instance.sh` + the Coolify "create service" flow. Invoked via `module "ea_instance" { for_each = var.products }` so each map entry fans out to a complete instance:

Resources created per `for_each` key (= product slug):
1. `google_sql_database` `ea_<product>` (utf8mb4_unicode_ci)
2. `google_sql_user` `ea_<product>` with random password
3. `random_password` for `EA_ENCRYPTION_KEY` (32 bytes base64)
4. `google_secret_manager_secret` × N — one per env var, named `ea-<product>-<var>`
5. `google_secret_manager_secret_version` with the computed/secret values
6. `google_storage_bucket` `ea-uploads-<product>` (uploads volume)
7. `google_cloud_run_v2_service` `ea-<product>`:
   - Image: `var.image` (from Artifact Registry, e.g. `region-docker.pkg.dev/.../ea-production:latest`)
   - `min_instance_count = each.value.min_instances` (default 1, kills cold starts), `max_instance_count = 10`
   - Port 8080 (Cloud Run maps; the container still listens on 80 via `containerPort`)
   - All `EA_*` envs via `secret_ref` → Secret Manager
   - Volume mount: GCS bucket → `/var/www/html/storage/uploads`
   - Service account: per-instance `ea-<product>-runner@` with `roles/secretmanager.secretAccessor` + `roles/cloudsql.client` + bucket writer
   - VPC connector: from network module (reaches Cloud SQL private IP)
   - Ingress: `INGRESS_TRAFFIC_INTERNAL_LOAD_BALANCER` (only the LB can reach it — no direct public URL)
   - `cpu_throttling = false` (PHP-FPM wants CPU during I/O waits)
8. `google_compute_region_network_endpoint_group` (serverless) → the Cloud Run service
9. `google_compute_backend_service` referencing the NEG
10. LB `url_map` path-rule entry: host `each.value.subdomain` → backend
11. `google_dns_record_set` for `<product>.bookings.domain` → LB IP

Adding a new product instance becomes:
```hcl
# terraform.tfvars
products = {
  product-a = { cors_origins = "https://app.product-a.com", frame_ancestors = "https://app.product-a.com", min_instances = 1 }
  product-b = { ... }
  product-c = { google_client_id = "...", google_client_secret = "...", ... }
  # NEW — one line:
  product-d = { cors_origins = "https://app.product-d.com", frame_ancestors = "https://app.product-d.com" }
}
```
`terraform plan` shows exactly what will be created; `terraform apply` provisions DB, user, secrets, service, LB path, and DNS — replacing the manual `provision-instance.sh` + Coolify clicks.

### New Files (CI / build)

#### `.github/workflows/build-and-deploy.yml`
On push to `main` (or tag):
1. Build `Dockerfile.production` → tag with SHA + `latest`
2. Push to Artifact Registry (Workload Identity Federation, no static keys)
3. `terraform plan` (PRs) / `terraform apply` (main) — Cloud Run services pick up the new image digest automatically on next revision

#### `infra/terraform/migrate-from-hetzner.sh`
One-shot migration helper (documented runbook, not a long-lived tool):
1. `ssh` to Hetzner, run `mysqldump` per `ea_*` database
2. `gsutil cp` dumps to a GCS import bucket
3. `gcloud sql import sql` into the new Cloud SQL instance, one DB at a time
4. DNS cutover: lower TTL on Cloudflare/DNS, flip `*.bookings` A records to LB anycast IP, verify per-instance `/booking` returns 200, then decommission Hetzner

### New Docs

#### `infra/README.md`
Setup: prerequisites, `terraform init/plan/apply`, how to add an instance, how to roll back an instance (`terraform destroy -target=module.ea_instance["product-x"]`).

#### `infra/RUNBOOK.md`
Operational runbook: rotate a secret, force a backup, scale an instance (`min_instances`/`max`), view Cloud Run logs, decommission Hetzner.

## Provisioning Workflow

### Adding a New Product Instance (Terraform path)

1. **Add an entry to the `products` map** in `infra/terraform/terraform.tfvars`.
2. If Google Calendar sync is needed for this instance, create the OAuth client in the existing Google Cloud project (`https://<product>.bookings.domain/google/callback`) and paste the client ID/secret into the map.
3. `terraform plan` — review the diff (new DB, user, ~12 secrets, Cloud Run service, NEG, backend, DNS record).
4. `terraform apply` — GCP provisions everything (~3–5 minutes; Cloud SQL user creation is the long pole).
5. The build CI deploys the latest `ea-production` image; Cloud Run revision starts; entrypoint runs migrations on the fresh DB.
6. Visit `https://<product>.bookings.domain/installation`, complete the admin wizard, configure services/providers, generate API token.
7. Register webhooks on the product backend.

No SSH. No Coolify clicks. No manual `provision-instance.sh`. The whole thing is reviewable in a PR.

### Updating All Instances

1. Pull upstream changes into the fork (or merge a feature branch).
2. Push to `main` → CI rebuilds `Dockerfile.production`, pushes a new image digest to Artifact Registry.
3. `terraform apply` — each Cloud Run service revision is created with the new image digest; entrypoint runs `php index.php console migrate` on boot.
4. Cloud Run traffic is shifted atomically to the new revision; rollback is `gcloud run services update-traffic --to-revisions`.

### Migrating Off Hetzner (One-Time Cutover)

Phase 1 — **Parallel stand-up** (no downtime risk):
1. `terraform apply` the full stack on GCP with empty databases.
2. Verify each `https://<product>.bookings.domain/` returns the install wizard on GCP (using a staging DNS name first, e.g. `<product>.staging.bookings.domain`, to avoid colliding with live Hetzner DNS).

Phase 2 — **Data migration** (per-instance, low-traffic window):
3. Put the Hetzner instance in read-only mode (announce to product backend, or accept a brief write window of loss).
4. `mysqldump --single-transaction ea_<product>` on Hetzner → `gcloud sql import sql` into GCP.
5. Verify row counts and the latest appointment timestamps match.

Phase 3 — **DNS cutover**:
6. Lower TTL on the existing `*.bookings` record to 60s ahead of cutover day.
7. Flip the wildcard A record to the GCP LB anycast IP.
8. Per instance: hit `https://<product>.bookings.domain/api/v1/...` from the product backend, confirm 200, confirm new-DB writes land.
9. After 48h stable, decommission Hetzner (terminate CX22, delete Coolify project, revoke SSH keys).

## Security

| Concern | Mitigation |
|---|---|
| Network exposure | Each Cloud Run service is `INGRESS_TRAFFIC_INTERNAL_LOAD_BALANCER` — reachable only via the LB, never directly. Cloud SQL has private IP only; no public IP. |
| Per-instance isolation | Separate Cloud SQL database + dedicated least-privilege user per instance. Per-instance service account (`ea-<product>-runner@`) scoped to that instance's secrets + bucket + DB only. |
| Encryption key | Unique `EA_ENCRYPTION_KEY` per instance, generated by Terraform `random_password` (32 bytes), stored in Secret Manager. Prevents cross-instance session forgery — same property as the current provisioning script. |
| Secrets | All `EA_*` values live in Secret Manager with IAM-gated access. No secrets in `terraform.tfvars`, no secrets in Cloud Run env-var plaintext (resolved at runtime). |
| TLS | Managed wildcard SSL cert on the global LB, auto-renewed. HTTP→HTTPS 301 redirect. |
| CORS / iframe | Unchanged from prior spec — `EA_CORS_ORIGINS` and `EA_FRAME_ANCESTORS` from secrets, applied in `application/config/routes.php`. |
| API auth | Unchanged — unique Bearer token per instance, set in admin UI. |
| Image provenance | Artifact Registry + Binary Authorization (optional phase-2 hardening) to require images signed by the CI attestation. |
| Backups | Cloud SQL automated backups + 7-day PITR (managed, off-host). Additional hourly `mysqldump` → GCS with 30-day lifecycle. Backups no longer live on the same host as the database. |
| Supply chain | Workload Identity Federation for GitHub Actions — no long-lived GCP service account keys in GitHub. |
| Trusted proxy | `EA_PROXY_IPS` set to the LB's source CIDRs so the app honors `X-Forwarded-For` correctly (replaces the `172.16.0.0/12` Docker-network value used under Coolify/Traefik). |

## Known Limitations and Notes

- **Cost.** Cloud Run min-instances=1 per instance + a single Cloud SQL instance is comparable to the Hetzner CX22 at ~3 instances, cheaper below, more expensive above. Expect roughly: Cloud SQL `db-n1-standard-1` ~$50/mo, Cloud Run ~$10/mo/instance idle, LB + SSL + DNS ~$20/mo, storage/egress usage-based.
- **Cold starts.** Mitigated by `min_instances = 1` per service (the default in the module). Costs more than scaling-to-zero but matches the "always-on booking page" UX the current Hetzner setup provides.
- **Single region.** This spec is single-region. Multi-region failover is explicitly out of scope (see below); Cloud SQL HA gives zonal redundancy within the region.
- **Cloud Run volume semantics.** GCS-mounted `storage/uploads` is eventually-consistent for writes. EA writes uploads then reads them back in the same request — the GCS mount handles this, but a `NFS` Filestore tier is the escape hatch if consistency becomes a problem. Revisit if issues appear.
- **Per-instance admin.** Unchanged — each instance still requires its own admin setup via `/installation`. No centralized management is introduced.
- **Google Calendar OAuth.** Still one OAuth client per instance (one redirect URI per subdomain). The Google Cloud project holding these is unrelated to the GCP project hosting the infrastructure.
- **Cloud DNS vs Cloudflare.** Default in this spec is Cloud DNS for end-to-end Terraform ownership. If Cloudflare must stay as the authoritative DNS, the `dns` module is dropped and a `cloudflare` provider block + `cloudflare_record` resources are substituted — purely a provider swap.
- **GPL-3.0.** Unchanged — the infrastructure definitions are not derivative works of the GPL code, but the EasyAppointments image itself remains GPL-3.0.

## Decisions Required (Open Questions)

These must be locked before implementation begins:

1. **Region.** Single region — `us-central1`, `us-east1`, `europe-west1`, or other? Should be closest to the Vercel frontend's primary region to minimize API latency.
2. **DNS provider.** Cloud DNS (recommended; fully Terraform-owned) or stay on Cloudflare (swap one module)? If Cloudflare, proxy must remain OFF (grey cloud) so the managed SSL cert validates.
3. **Cloud SQL tier.** `db-n1-standard-1` (~$50/mo, recommended) or cheaper `db-custom-2-7680` (~$30/mo, tighter headroom)?
4. **Cloud SQL user/DB ownership.** Create per-instance DB+user inside the `ea-instance` module (recommended — one module owns everything about an instance) or in the `cloud-sql` module (separation of concerns)? Spec assumes `ea-instance`.
5. **Data migration.** Migrate existing Hetzner data as part of this scope (runbook included above), or stand up greenfield on GCP and decommission Hetzner data?
6. **CI.** GitHub Actions (recommended, matches existing source flow) or Cloud Build?
7. **Cloudflare proxy.** If staying on Cloudflare, keep proxy OFF for managed-cert validation (mandatory).
8. **Binary Authorization.** Phase-1 (ship without) or phase-2 (require signed images from CI)?

## What This Design Does NOT Change

- **The application code.** No changes to `application/`, `system/`, `index.php`, `config-sample.php`, or any PHP file. The entire `EA_*` env-var contract from the prior spec is reused verbatim.
- **`Dockerfile.production` and `docker/production/*`.** Reused as-is. The container contract is identical; only the host platform changes.
- **`scripts/provision-instance.sh`, `scripts/backup-databases.sh`, `scripts/setup-backup-cron.sh`.** Remain in the repo for the Hetzner/Coolify path until that path is decommissioned. Not modified.
- **`docker-compose.yml` and the dev environment.** Untouched — local dev continues to use Docker Compose with the dev MySQL/Mailpit/etc.
- **The Vercel frontend.** No changes. Only the IP behind `*.bookings.domain` changes.
- **The Google Cloud project holding Google Calendar OAuth clients.** Unrelated to the GCP project hosting the infrastructure.
- **The API contract, webhook contract, booking-wizard behavior, CORS/CSP behavior.** All identical to the current production deployment.
- **Multi-region, DR beyond zonal HA, Cloud Armor/WAF, GKE, Cloud Build (vs Actions), Filestore NFS.** All explicitly out of scope for this iteration.

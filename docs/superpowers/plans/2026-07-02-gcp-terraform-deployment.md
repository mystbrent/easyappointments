# GCP Terraform Deployment — Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the Hetzner CX22 + Coolify manual deployment with a fully reproducible GCP deployment (Cloud Run + Cloud SQL + Global LB) defined entirely in Terraform. One `ea-instance` module fans out to a complete per-product instance from a single `products` map entry.

**Architecture:** The existing `Dockerfile.production` image (Nginx + PHP-FPM + supervisord) is deployed unchanged to Cloud Run, one service per product. A shared Cloud SQL MySQL 8.0 instance (HA + PITR) holds one DB + dedicated user per instance. A global external HTTPS LB with a managed wildcard `*.bookings.domain` cert routes each subdomain to its Cloud Run service via serverless NEGs. All secrets live in Secret Manager; uploads in per-instance GCS buckets; hourly DB dumps via a Cloud Run Job + Scheduler.

**Tech Stack:** Terraform (≥ 1.6), Google Cloud (`google` + `google-beta` providers), Cloud Run v2, Cloud SQL MySQL 8.0, Cloud DNS, Secret Manager, Artifact Registry, Serverless VPC Access, Cloud Scheduler, GitHub Actions.

**Spec:** `docs/superpowers/specs/2026-07-02-gcp-terraform-deployment-design.md`

**Locked decisions (from spec Open Questions — override in `terraform.tfvars` if needed):**
- Region: single region, default `us-central1` (parameterized via `var.region`)
- DNS: **Cloud DNS** (fully Terraform-owned)
- Cloud SQL tier: `db-n1-standard-1`
- DB + user ownership: inside the `ea-instance` module (one module owns everything about an instance)
- CI: GitHub Actions + Workload Identity Federation
- Binary Authorization: phase-2 (not in this plan)

---

## Chunk 1: Bootstrap & Foundation

Set up the Terraform scaffold, remote state, project APIs, and the Artifact Registry.

### Task 1: Create the Terraform scaffold

**Files:**
- Create: `infra/terraform/.gitignore`
- Create: `infra/terraform/versions.tf`
- Create: `infra/terraform/backend.tf`
- Create: `infra/terraform/providers.tf`
- Create: `infra/terraform/variables.tf`
- Create: `infra/terraform/terraform.tfvars.example`

- [ ] **Step 1: Create `infra/terraform/.gitignore`**

```
.terraform/
*.tfstate
*.tfstate.*
*.tfstate.backup
crash.log
crash.*.log
terraform.tfvars
*.auto.tfvars
override.tf
override.tf.json
*_override.tf
*_override.tf.json
.terraformrc
terraform.rc
*-secrets.*
```

- [ ] **Step 2: Create `infra/terraform/versions.tf`**

```hcl
terraform {
  required_version = ">= 1.6.0"

  required_providers {
    google = {
      source  = "hashicorp/google"
      version = ">= 5.40.0"
    }
    google-beta = {
      source  = "hashicorp/google-beta"
      version = ">= 5.40.0"
    }
    random = {
      source  = "hashicorp/random"
      version = ">= 3.6.0"
    }
  }
}
```

- [ ] **Step 3: Create `infra/terraform/backend.tf`**

```hcl
# Remote state in GCS. Bucket is created by modules/project-bootstrap.
# Until bootstrap is applied, comment this block out and use local state,
# then `terraform init -migrate-state` to move local state into GCS.

terraform {
  backend "gcs" {
    bucket = "ea-tfstate"
    prefix = "terraform/state"
  }
}
```

- [ ] **Step 4: Create `infra/terraform/providers.tf`**

```hcl
provider "google" {
  project = var.project_id
  region  = var.region
}

provider "google-beta" {
  project = var.project_id
  region  = var.region
}

# Long-running Cloud SQL operations can exceed default timeout.
provider "google" {
  alias               = "sql-ops"
  project             = var.project_id
  region              = var.region
  user_project_override = true
  request_timeout     = "120s"
}
```

- [ ] **Step 5: Create `infra/terraform/variables.tf`**

```hcl
variable "project_id" {
  type        = string
  description = "GCP project ID hosting the EasyAppointments stack."
}

variable "region" {
  type        = string
  description = "Single region for Cloud Run + Cloud SQL."
  default     = "us-central1"
}

variable "domain" {
  type        = string
  description = "Root booking domain, e.g. bookings.example.com. Subdomains are <product>.<domain>."
}

variable "image" {
  type        = string
  description = "Fully-qualified Artifact Registry image reference for the EA production image."
  default     = "" # computed in main.tf from artifact-registry module if empty
}

variable "sql_tier" {
  type        = string
  description = "Cloud SQL machine tier."
  default     = "db-n1-standard-1"
}

variable "sql_disk_gb" {
  type        = number
  description = "Cloud SQL disk size in GB (autoscales above this)."
  default     = 100
}

variable "products" {
  type = map(object({
    cors_origins       = string
    frame_ancestors    = string
    google_sync        = optional(bool, false)
    google_client_id   = optional(string, "")
    google_client_secret = optional(string, "")
    min_instances      = optional(number, 1)
    max_instances      = optional(number, 10)
  }))
  description = "Map of product slug -> instance config. Each key fans out a full instance."
}

variable "smtp_host"          { type = string  default = "smtp.resend.com" }
variable "smtp_port"          { type = number  default = 587 }
variable "smtp_user"          { type = string  default = "resend" }
variable "smtp_pass"          { type = string  sensitive = true }
variable "smtp_crypto"        { type = string  default = "tls" }
variable "smtp_from_address"  { type = string  default = "bookings@example.com" }
variable "smtp_from_name"     { type = string  default = "Bookings" }
```

- [ ] **Step 6: Create `infra/terraform/terraform.tfvars.example`**

```hcl
project_id = "your-gcp-project-id"
region     = "us-central1"
domain     = "bookings.example.com"

# SMTP (shared across all instances)
smtp_pass = "re_YOUR_RESEND_KEY"

products = {
  product-a = {
    cors_origins    = "https://app.product-a.com"
    frame_ancestors = "https://app.product-a.com"
    min_instances   = 1
  }
  # product-b = { ... }
}
```

- [ ] **Step 7: Commit**

```bash
git add infra/terraform/.gitignore infra/terraform/versions.tf infra/terraform/backend.tf infra/terraform/providers.tf infra/terraform/variables.tf infra/terraform/terraform.tfvars.example
git commit -m "feat: scaffold GCP Terraform deployment

Adds versions, GCS remote-state backend, providers, and the top-level
variables (project, region, domain, products map, SMTP). products map
is the single entry point for adding instances."
```

---

### Task 2: Create the project-bootstrap module

**Files:**
- Create: `infra/terraform/modules/project-bootstrap/main.tf`
- Create: `infra/terraform/modules/project-bootstrap/variables.tf`
- Create: `infra/terraform/modules/project-bootstrap/outputs.tf`

- [ ] **Step 1: Create `modules/project-bootstrap/variables.tf`**

```hcl
variable "project_id" { type = string }
variable "region"     { type = string }
```

- [ ] **Step 2: Create `modules/project-bootstrap/main.tf`**

```hcl
locals {
  apis = [
    "run.googleapis.com",
    "sqladmin.googleapis.com",
    "secretmanager.googleapis.com",
    "compute.googleapis.com",
    "dns.googleapis.com",
    "artifactregistry.googleapis.com",
    "vpcaccess.googleapis.com",
    "cloudscheduler.googleapis.com",
    "cloudbuild.googleapis.com",
    "servicenetworking.googleapis.com",
    "iamcredentials.googleapis.com",
    "certificatemanager.googleapis.com",
  ]

  state_bucket = "${var.project_id}-ea-tfstate"
}

# Enable all required APIs (one resource per API — clean diff per API).
resource "google_project_service" "enabled" {
  for_each                    = toset(local.apis)
  project                     = var.project_id
  service                     = each.value
  disable_dependent_services  = false
  disable_on_destroy          = false
}

# Remote-state bucket. Must exist before `terraform init -migrate-state`.
resource "google_storage_bucket" "tfstate" {
  name          = local.state_bucket
  project       = var.project_id
  location      = var.region
  force_destroy = false
  uniform_bucket_level_access = true
  versioning {
    enabled = true
  }
}
```

- [ ] **Step 3: Create `modules/project-bootstrap/outputs.tf`**

```hcl
output "state_bucket" { value = google_storage_bucket.tfstate.name }
output "enabled_apis" { value = [for a in google_project_service.enabled : a.service] }
```

- [ ] **Step 4: Commit**

```bash
git add infra/terraform/modules/project-bootstrap/
git commit -m "feat: add project-bootstrap module

Enables the 12 required GCP APIs and creates the GCS bucket for
Terraform remote state with versioning + UBLA."
```

---

### Task 3: Create the artifact-registry module

**Files:**
- Create: `infra/terraform/modules/artifact-registry/main.tf`
- Create: `infra/terraform/modules/artifact-registry/variables.tf`
- Create: `infra/terraform/modules/artifact-registry/outputs.tf`

- [ ] **Step 1: Create `modules/artifact-registry/variables.tf`**

```hcl
variable "project_id" { type = string }
variable "region"     { type = string }
variable "repo_name"  { type = string default = "ea-production" }
```

- [ ] **Step 2: Create `modules/artifact-registry/main.tf`**

```hcl
resource "google_artifact_registry_repository" "ea" {
  project       = var.project_id
  location      = var.region
  repository_id = var.repo_name
  format        = "DOCKER"
  mode          = "STANDARD_REPOSITORY"

  docker_config {
    immutable_tags = true # every push must use a unique tag (SHA + latest)
  }
}

# Dedicated puller SA used by Cloud Run services.
resource "google_service_account" "puller" {
  project      = var.project_id
  account_id   = "ea-image-puller"
  display_name = "EA Cloud Run image puller"
}

resource "google_artifact_registry_repository_iam_member" "puller" {
  project    = var.project_id
  location   = var.region
  repository = google_artifact_registry_repository.ea.name
  role       = "roles/artifactregistry.reader"
  member     = "serviceAccount:${google_service_account.puller.email}"
}
```

- [ ] **Step 3: Create `modules/artifact-registry/outputs.tf`**

```hcl
output "repo"        { value = google_artifact_registry_repository.ea.name }
output "repo_full"   { value = "${var.region}-docker.pkg.dev/${var.project_id}/${google_artifact_registry_repository.ea.name}" }
output "puller_email"{ value = google_service_account.puller.email }
```

- [ ] **Step 4: Commit**

```bash
git add infra/terraform/modules/artifact-registry/
git commit -m "feat: add artifact-registry module

Creates the ea-production Docker repo with immutable tags and a
dedicated puller service account granted artifactregistry.reader."
```

---

### Task 4: Verify Chunk 1 by planning against a real (or scratch) project

- [ ] **Step 1: Create a scratch GCP project (or pick an existing one) and authenticate**

Run: `gcloud auth login && gcloud auth application-default login`

- [ ] **Step 2: Apply the bootstrap module standalone to create the state bucket**

Run (from `infra/terraform/`):
```bash
# Temporarily comment out the `backend "gcs"` block, then:
terraform init
terraform apply -target=module.bootstrap -var="project_id=YOUR_PROJECT" -var="region=us-central1"
```

Expected: APIs enabled, `<project>-ea-tfstate` bucket created.

- [ ] **Step 3: Uncomment the `backend "gcs"` block, set bucket name, re-init with migration**

Run: `terraform init -migrate-state`

Expected: "Successfully configured the backend gcs"; local state moved to GCS.

- [ ] **Step 4: Plan the artifact-registry module**

Run: `terraform plan -target=module.registry -var="project_id=YOUR_PROJECT" -var="region=us-central1"`

Expected: clean plan, repo + SA + IAM member to create.

---

## Chunk 2: Networking & Database

VPC, Serverless VPC connector, Cloud SQL private services access, and the shared MySQL 8.0 instance.

### Task 5: Create the network module

**Files:**
- Create: `infra/terraform/modules/network/main.tf`
- Create: `infra/terraform/modules/network/variables.tf`
- Create: `infra/terraform/modules/network/outputs.tf`

- [ ] **Step 1: Create `modules/network/variables.tf`**

```hcl
variable "project_id" { type = string }
variable "region"     { type = string }
```

- [ ] **Step 2: Create `modules/network/main.tf`**

```hcl
resource "google_compute_network" "ea" {
  project                 = var.project_id
  name                    = "ea-vpc"
  auto_create_subnetworks = false
}

# Subnet for the Serverless VPC connector (/28 is the minimum).
resource "google_compute_subnetwork" "connector" {
  project       = var.project_id
  name          = "ea-vpc-connector"
  ip_cidr_range = "10.8.0.0/28"
  region        = var.region
  network       = google_compute_network.ea.id
}

resource "google_vpc_access_connector" "ea" {
  project         = var.project_id
  name            = "ea-connector"
  region          = var.region
  network         = google_compute_network.ea.name
  ip_cidr_range   = "10.8.0.16/28"
  min_throughput  = 200
  max_throughput  = 1000
}

# Private services access range for Cloud SQL (/20).
resource "google_compute_global_address" "private_sql" {
  project      = var.project_id
  name         = "ea-google-managed-services"
  purpose      = "VPC_PEERING"
  address_type = "INTERNAL"
  ip_version   = "IPV4"
  prefix_length = 20
  network      = google_compute_network.ea.id
}

resource "google_service_networking_connection" "private_sql" {
  network                 = google_compute_network.ea.id
  service                 = "servicenetworking.googleapis.com"
  reserved_peering_ranges = [google_compute_global_address.private_sql.name]
}
```

- [ ] **Step 3: Create `modules/network/outputs.tf`**

```hcl
output "vpc_self_link"       { value = google_compute_network.ea.self_link }
output "vpc_name"            { value = google_compute_network.ea.name }
output "connector_id"        { value = google_vpc_access_connector.ea.id }
output "connector_self_link" { value = google_vpc_access_connector.ea.self_link }
```

- [ ] **Step 4: Commit**

```bash
git add infra/terraform/modules/network/
git commit -m "feat: add network module

Custom VPC, /28 subnet for the Serverless VPC connector, the connector
itself (200-1000 Mbps), and a /20 private services range peered to
Cloud SQL so the DB has private IP only."
```

---

### Task 6: Create the cloud-sql module

**Files:**
- Create: `infra/terraform/modules/cloud-sql/main.tf`
- Create: `infra/terraform/modules/cloud-sql/variables.tf`
- Create: `infra/terraform/modules/cloud-sql/outputs.tf`

- [ ] **Step 1: Create `modules/cloud-sql/variables.tf`**

```hcl
variable "project_id"   { type = string }
variable "region"       { type = string }
variable "tier"         { type = string  default = "db-n1-standard-1" }
variable "disk_gb"      { type = number  default = 100 }
variable "vpc_self_link"{ type = string }
variable "root_password"{
  type      = string
  sensitive = true
}
```

- [ ] **Step 2: Create `modules/cloud-sql/main.tf`**

```hcl
resource "google_sql_database_instance" "ea" {
  project          = var.project_id
  name             = "ea-mysql"
  region           = var.region
  database_version = "MYSQL_8_0"

  settings {
    tier              = var.tier
    disk_size         = var.disk_gb
    disk_type         = "PD_SSD"
    availability_type = "REGIONAL" # zonal HA within the region

    ip_configuration {
      ipv4_enabled    = false # private IP only
      private_network = var.vpc_self_link
      require_ssl     = true
    }

    backup_configuration {
      enabled                        = true
      start_time                     = "02:00"
      point_in_time_recovery_enabled = true
      binary_log_enabled             = true
      retained_backups               = 14
      retention_unit                 = "COUNT"
    }

    database_flags {
      name  = "character_set_server"
      value = "utf8mb4"
    }
    database_flags {
      name  = "collation_server"
      value = "utf8mb4_unicode_ci"
    }
  }

  deletion_protection = true

  depends_on = [var.vpc_self_link]
}

resource "google_sql_user" "root" {
  project  = var.project_id
  instance = google_sql_database_instance.ea.name
  name     = "root"
  password = var.root_password
  host     = "%"
}
```

- [ ] **Step 3: Create `modules/cloud-sql/outputs.tf`**

```hcl
output "instance_name"     { value = google_sql_database_instance.ea.name }
output "instance_self_link"{ value = google_sql_database_instance.ea.self_link }
output "private_ip" {
  value = google_sql_database_instance.ea.private_ip_address
}
output "connection_name" { value = google_sql_database_instance.ea.connection_name }
```

- [ ] **Step 4: Commit**

```bash
git add infra/terraform/modules/cloud-sql/
git commit -m "feat: add cloud-sql module

Regional-HA MySQL 8.0 instance, private IP only via the network VPC,
SSL required, automated backups + 14-day retention + PITR. Root user
created with provided password. Deletion protection on."
```

---

### Task 7: Verify Chunk 2 — apply network + Cloud SQL and confirm reachability

- [ ] **Step 1: Apply network module**

Run: `terraform apply -target=module.network -var="project_id=..." -var="region=us-central1"`

Expected: VPC, subnet, connector, private services range, peering created.

- [ ] **Step 2: Apply Cloud SQL module**

Run:
```bash
terraform apply -target=module.cloudsql \
  -var="project_id=..." -var="region=us-central1" \
  -var="cloudsql_root_password=$(openssl rand -base64 24)"
```

Expected: instance reaches `RUNNABLE` in ~5 min; `private_ip_address` output populated.

- [ ] **Step 3: Confirm private IP from inside the VPC**

Run:
```bash
gcloud compute instances create ea-probe --zone=us-central1-a --network=ea-vpc --subnet=ea-vpc-connector --image-family=debian-11 --image-project=debian-cloud
gcloud compute ssh ea-probe --zone=us-central1-a --command="nc -zv <PRIVATE_IP> 3306"
gcloud compute instances delete ea-probe --zone=us-central1-a --quiet
```

Expected: `Connection to <PRIVATE_IP> 3306 port [tcp/mysql] succeeded!`

---

## Chunk 3: Shared Services (Secrets, Backups)

### Task 8: Create the secrets module (shared SMTP)

**Files:**
- Create: `infra/terraform/modules/secrets/main.tf`
- Create: `infra/terraform/modules/secrets/variables.tf`
- Create: `infra/terraform/modules/secrets/outputs.tf`

- [ ] **Step 1: Create `modules/secrets/variables.tf`**

```hcl
variable "project_id" { type = string }
variable "smtp_host"         { type = string }
variable "smtp_port"         { type = number }
variable "smtp_user"         { type = string }
variable "smtp_pass"         { type = string sensitive = true }
variable "smtp_crypto"       { type = string }
variable "smtp_from_address" { type = string }
variable "smtp_from_name"    { type = string }
```

- [ ] **Step 2: Create `modules/secrets/main.tf`**

```hcl
locals {
  shared = {
    smtp_host         = var.smtp_host
    smtp_port         = tostring(var.smtp_port)
    smtp_user         = var.smtp_user
    smtp_pass         = var.smtp_pass
    smtp_crypto       = var.smtp_crypto
    smtp_from_address = var.smtp_from_address
    smtp_from_name    = var.smtp_from_name
  }
}

resource "google_secret_manager_secret" "shared" {
  for_each    = local.shared
  project     = var.project_id
  secret_id   = "ea-shared-${each.key}"
  replication {
    auto {}
  }
}

resource "google_secret_manager_secret_version" "shared" {
  for_each    = local.shared
  secret      = google_secret_manager_secret.shared[each.key].id
  secret_data = each.value
}
```

- [ ] **Step 3: Create `modules/secrets/outputs.tf`**

```hcl
output "secret_ids" {
  value = { for k, s in google_secret_manager_secret.shared : k => s.id }
}
```

- [ ] **Step 4: Commit**

```bash
git add infra/terraform/modules/secrets/
git commit -m "feat: add shared secrets module

Creates one Secret Manager secret per shared SMTP value (host, port,
user, pass, crypto, from_address, from_name). Referenced by every
ea-instance Cloud Run service; no SMTP creds in env-var plaintext."
```

---

### Task 9: Create the backup-job module

**Files:**
- Create: `infra/terraform/modules/backup-job/main.tf`
- Create: `infra/terraform/modules/backup-job/variables.tf`
- Create: `infra/terraform/modules/backup-job/outputs.tf`
- Create: `infra/terraform/modules/backup-job/dump.sh.tpl`

- [ ] **Step 1: Create `modules/backup-job/variables.tf`**

```hcl
variable "project_id"      { type = string }
variable "region"          { type = string }
variable "sql_instance"    { type = string }
variable "sql_root_password" { type = string sensitive = true }
variable "db_names"        { type = list(string) } # all ea_<product> DBs
variable "sa_email"        { type = string }        # Cloud SQL client SA
```

- [ ] **Step 2: Create `modules/backup-job/dump.sh.tpl`**

```bash
#!/bin/sh
set -eu
TS=$(date -u +%Y%m%dT%H%M%SZ)
for DB in ${DB_NAMES}; do
  mysqldump --no-tablespaces --single-transaction --host=${SQL_HOST} --user=root --password="${SQL_PASS}" "$DB" \
    | gzip > "/tmp/${DB}_${TS}.sql.gz"
  gsutil cp "/tmp/${DB}_${TS}.sql.gz" "gs://${BUCKET}/hourly/${DB}/${DB}_${TS}.sql.gz"
  rm "/tmp/${DB}_${TS}.sql.gz"
done
```

- [ ] **Step 3: Create `modules/backup-job/main.tf`**

```hcl
resource "google_storage_bucket" "backups" {
  project                     = var.project_id
  name                        = "${var.project_id}-ea-db-backups"
  location                    = var.region
  uniform_bucket_level_access = true
  lifecycle_rule {
    condition { age = 30 }
    action    { type = "Delete" }
  }
}

# Per-DB Cloud Run Job that runs mysqldump and ships to GCS.
# Uses the mysql:8 client image — small and trusted.
resource "google_cloud_run_v2_job" "dumper" {
  project  = var.project_id
  location = var.region
  name     = "ea-db-dumper"

  template {
    template {
      service_account = var.sa_email
      volumes {
        name = "cloudsql"
        cloud_sql_instance { instances = [var.sql_instance] }
      }
      containers {
        image   = "mysql:8"
        command = ["/bin/sh", "/scripts/dump.sh"]
        env {
          name  = "DB_NAMES"
          value = join(" ", var.db_names)
        }
        env {
          name  = "SQL_HOST"
          value = "localhost" # via the cloudsql volume socket
        }
        env {
          name  = "SQL_PASS"
          value = var.sql_root_password
        }
        env {
          name  = "BUCKET"
          value = google_storage_bucket.backups.name
        }
        volume_mounts {
          name       = "cloudsql"
          mount_path = "/cloudsql"
        }
      }
    }
  }
}

# Hourly trigger.
resource "google_cloud_scheduler_job" "hourly" {
  project   = var.project_id
  region    = var.region
  name      = "ea-db-dump-hourly"
  schedule  = "0 * * * *"
  time_zone = "UTC"

  http_target {
    http_method = "POST"
    uri         = "https://${var.region}-run.googleapis.com/apis/run.googleapis.com/v1/namespaces/${var.project_id}/jobs/ea-db-dumper:run"
    oidc_token {
      service_account_email = var.sa_email
    }
  }
}
```

- [ ] **Step 4: Create `modules/backup-job/outputs.tf`**

```hcl
output "backup_bucket" { value = google_storage_bucket.backups.name }
```

- [ ] **Step 5: Commit**

```bash
git add infra/terraform/modules/backup-job/
git commit -m "feat: add backup-job module

GCS bucket (30-day lifecycle) + mysql:8 Cloud Run Job that dumps each
ea_* DB and ships gzipped output to gs://...-ea-db-backups/hourly/<db>/.
Triggered hourly via Cloud Scheduler with OIDC auth. Replaces the
Hetzner cron in scripts/setup-backup-cron.sh."
```

---

## Chunk 4: The Reusable Instance Module

This is the Terraform replacement for `scripts/provision-instance.sh` + the Coolify "create service" flow.

### Task 10: ea-instance module — database, user, encryption key, secrets, uploads bucket

**Files:**
- Create: `infra/terraform/modules/ea-instance/main.tf` (split across Tasks 10–12; this task adds DB/user/secrets/bucket)
- Create: `infra/terraform/modules/ea-instance/variables.tf`
- Create: `infra/terraform/modules/ea-instance/outputs.tf`

- [ ] **Step 1: Create `modules/ea-instance/variables.tf`**

```hcl
variable "project_id"        { type = string }
variable "region"            { type = string }
variable "product"           { type = string } # the slug, e.g. "product-a"
variable "domain"            { type = string }
variable "cors_origins"      { type = string }
variable "frame_ancestors"   { type = string }
variable "google_sync"         { type = bool   default = false }
variable "google_client_id"    { type = string default = "" }
variable "google_client_secret"{ type = string default = "" sensitive = true }
variable "min_instances"     { type = number  default = 1 }
variable "max_instances"     { type = number  default = 10 }
variable "image"             { type = string }
variable "sql_instance"      { type = string }
variable "sql_connection_name" { type = string }
variable "vpc_connector"     { type = string }
variable "puller_sa_email"   { type = string }
variable "shared_secret_ids" { type = map(string) } # from secrets module
```

- [ ] **Step 2: Create `modules/ea-instance/main.tf` (DB + user + key + bucket + secrets)**

```hcl
locals {
  slug   = replace(var.product, "-", "_")
  db     = "ea_${local.slug}"
  dbuser = "ea_${local.slug}"
  fqdn   = "${var.product}.${var.domain}"
}

# ── Database + dedicated least-privilege user ────────────────────────────────
resource "google_sql_database" "this" {
  project  = var.project_id
  name     = local.db
  instance = var.sql_instance
}

resource "random_password" "db_pass" {
  length  = 24
  special = false # MySQL identifier-safe
}

resource "google_sql_user" "this" {
  project  = var.project_id
  instance = var.sql_instance
  name     = local.dbuser
  password = random_password.db_pass.result
  host     = "%"
}

resource "random_password" "encryption_key" {
  length = 32
}

# ── Per-instance secrets (one Secret Manager secret per EA_ env var) ────────
locals {
  instance_secrets = {
    "ea-${var.product}-base-url"        = "https://${local.fqdn}"
    "ea-${var.product}-db-host"         = var.sql_instance # resolved via Cloud Run socket; placeholder for clarity
    "ea-${var.product}-db-name"         = local.db
    "ea-${var.product}-db-username"     = local.dbuser
    "ea-${var.product}-db-password"     = random_password.db_pass.result
    "ea-${var.product}-encryption-key"  = random_password.encryption_key.result
    "ea-${var.product}-cors-origins"    = var.cors_origins
    "ea-${var.product}-frame-ancestors" = var.frame_ancestors
    "ea-${var.product}-proxy-ips"       = "" # set in Task 11 after LB module exposes source CIDRs
    "ea-${var.product}-google-sync"       = tostring(var.google_sync)
    "ea-${var.product}-google-client-id"  = var.google_client_id
    "ea-${var.product}-google-client-secret" = var.google_client_secret
  }
}

resource "google_secret_manager_secret" "instance" {
  for_each  = local.instance_secrets
  project   = var.project_id
  secret_id = each.key
  replication { auto {} }
}

resource "google_secret_manager_secret_version" "instance" {
  for_each    = local.instance_secrets
  secret      = google_secret_manager_secret.instance[each.key].id
  secret_data = each.value
}

# ── Uploads bucket ───────────────────────────────────────────────────────────
resource "google_storage_bucket" "uploads" {
  project                     = var.project_id
  name                        = "${var.project_id}-ea-uploads-${var.product}"
  location                    = var.region
  uniform_bucket_level_access = true
}
```

- [ ] **Step 3: Create placeholder `modules/ea-instance/outputs.tf` (extended in Task 11)**

```hcl
output "db_name"      { value = local.db }
output "uploads_bucket"{ value = google_storage_bucket.uploads.name }
```

(Leave the `locals` reference issue — `local.*` is accessible in outputs. Define `local` block in `main.tf` already done in Step 2.)

- [ ] **Step 4: Commit**

```bash
git add infra/terraform/modules/ea-instance/
git commit -m "feat: ea-instance module — DB, user, secrets, uploads bucket

Per-product Cloud SQL database + dedicated user (random password),
per-instance EA_ENCRYPTION_KEY (32 random bytes), one Secret Manager
secret per EA_ env var, and a per-instance uploads GCS bucket. Fans
out via for_each from the products map at the root."
```

---

### Task 11: ea-instance module — Cloud Run service, SA, GCS volume, IAM

**Files:**
- Modify: `infra/terraform/modules/ea-instance/main.tf` (append)

- [ ] **Step 1: Append SA + IAM + Cloud Run service to `main.tf`**

```hcl
# ── Per-instance runner SA ───────────────────────────────────────────────────
resource "google_service_account" "runner" {
  project      = var.project_id
  account_id   = "ea-${var.product}-runner"
  display_name = "EA ${var.product} Cloud Run runner"
}

# Access this instance's secrets + shared SMTP secrets + uploads bucket + SQL.
resource "google_secret_manager_secret_iam_member" "instance_access" {
  for_each = google_secret_manager_secret.instance
  project  = var.project_id
  secret_id = each.value.id
  role      = "roles/secretmanager.secretAccessor"
  member    = "serviceAccount:${google_service_account.runner.email}"
}

resource "google_secret_manager_secret_iam_member" "shared_access" {
  for_each   = var.shared_secret_ids
  project    = var.project_id
  secret_id  = each.value
  role       = "roles/secretmanager.secretAccessor"
  member     = "serviceAccount:${google_service_account.runner.email}"
}

resource "google_storage_bucket_iam_member" "uploads_writer" {
  bucket = google_storage_bucket.uploads.name
  role   = "roles/storage.objectAdmin"
  member = "serviceAccount:${google_service_account.runner.email}"
}

resource "google_project_iam_member" "sql_client" {
  project = var.project_id
  role    = "roles/cloudsql.client"
  member  = "serviceAccount:${google_service_account.runner.email}"
}

resource "google_project_iam_member" "image_puller" {
  project = var.project_id
  role    = "roles/artifactregistry.reader"
  member  = "serviceAccount:${google_service_account.runner.email}"
}

# ── Cloud Run service ────────────────────────────────────────────────────────
locals {
  secret_env = [
    { name = "EA_BASE_URL",         secret = "ea-${var.product}-base-url" },
    { name = "EA_DB_NAME",          secret = "ea-${var.product}-db-name" },
    { name = "EA_DB_USERNAME",      secret = "ea-${var.product}-db-username" },
    { name = "EA_DB_PASSWORD",      secret = "ea-${var.product}-db-password" },
    { name = "EA_ENCRYPTION_KEY",   secret = "ea-${var.product}-encryption-key" },
    { name = "EA_CORS_ORIGINS",     secret = "ea-${var.product}-cors-origins" },
    { name = "EA_FRAME_ANCESTORS",  secret = "ea-${var.product}-frame-ancestors" },
    { name = "EA_GOOGLE_SYNC",      secret = "ea-${var.product}-google-sync" },
    { name = "EA_GOOGLE_CLIENT_ID",    secret = "ea-${var.product}-google-client-id" },
    { name = "EA_GOOGLE_CLIENT_SECRET", secret = "ea-${var.product}-google-client-secret" },
    # Shared SMTP (referenced by secret_id, not name)
  ]
}

resource "google_cloud_run_v2_service" "this" {
  project  = var.project_id
  location = var.region
  name     = "ea-${var.product}"

  ingress = "INGRESS_TRAFFIC_INTERNAL_LOAD_BALANCER" # only the LB can reach it

  template {
    service_account = google_service_account.runner.email
    min_instance_count = var.min_instances
    max_instance_count = var.max_instances

    volumes {
      name = "uploads"
      gcs {
        bucket    = google_storage_bucket.uploads.name
        read_only = false
      }
    }
    volumes {
      name = "cloudsql"
      cloud_sql_instance { instances = [var.sql_connection_name] }
    }

    containers {
      image = var.image
      ports { container_port = 80 }

      # Per-instance secrets via name.
      dynamic "env" {
        for_each = local.secret_env
        content {
          name = env.value.name
          value_from {
            secret_key_ref {
              name = env.value.secret
              key  = "latest"
            }
          }
        }
      }
      # Shared SMTP secrets (by ID).
      env {
        name = "EA_SMTP_HOST"
        value_from { secret_key_ref { name = var.shared_secret_ids["smtp_host"]; key = "latest" } }
      }
      env {
        name = "EA_SMTP_PORT"
        value_from { secret_key_ref { name = var.shared_secret_ids["smtp_port"]; key = "latest" } }
      }
      env {
        name = "EA_SMTP_USER"
        value_from { secret_key_ref { name = var.shared_secret_ids["smtp_user"]; key = "latest" } }
      }
      env {
        name = "EA_SMTP_PASS"
        value_from { secret_key_ref { name = var.shared_secret_ids["smtp_pass"]; key = "latest" } }
      }
      env {
        name = "EA_SMTP_CRYPTO"
        value_from { secret_key_ref { name = var.shared_secret_ids["smtp_crypto"]; key = "latest" } }
      }
      env {
        name = "EA_MAIL_FROM_ADDRESS"
        value_from { secret_key_ref { name = var.shared_secret_ids["smtp_from_address"]; key = "latest" } }
      }
      env {
        name = "EA_MAIL_FROM_NAME"
        value_from { secret_key_ref { name = var.shared_secret_ids["smtp_from_name"]; key = "latest" } }
      }

      volume_mounts {
        name       = "uploads"
        mount_path = "/var/www/html/storage/uploads"
      }
      volume_mounts {
        name       = "cloudsql"
        mount_path = "/cloudsql"
      }
    }

    vpc_access {
      connector = var.vpc_connector
      egress    = "PRIVATE_RANGES_ONLY"
    }
  }

  traffic {
    type    = "TRAFFIC_TARGET_ALLOCATION_TYPE_LATEST"
    percent = 100
  }

  depends_on = [
    google_secret_manager_secret_version.instance,
    google_secret_manager_secret_iam_member.instance_access,
  ]
}
```

- [ ] **Step 2: Append Cloud Run service outputs**

Add to `outputs.tf`:
```hcl
output "service_name"   { value = google_cloud_run_v2_service.this.name }
output "service_uri"    { value = google_cloud_run_v2_service.this.uri }
output "service_region" { value = var.region }
output "sa_email"       { value = google_service_account.runner.email }
```

- [ ] **Step 3: Commit**

```bash
git add infra/terraform/modules/ea-instance/
git commit -m "feat: ea-instance module — Cloud Run service

Per-instance runner SA with secretAccessor on this instance's secrets
and the shared SMTP set, cloudsql.client, artifactregistry.reader, and
objectAdmin on the uploads bucket. Cloud Run v2 service runs the EA
image, ingress INTERNAL_LOAD_BALANCER only, min/max instances from
vars, all EA_ envs resolved from Secret Manager, GCS uploads volume
mounted at storage/uploads, Cloud SQL socket via cloud_sql_instance
volume."
```

---

### Task 12: ea-instance module — serverless NEG + backend service

These resources are consumed by the load-balancer module via outputs.

**Files:**
- Modify: `infra/terraform/modules/ea-instance/main.tf` (append)
- Modify: `infra/terraform/modules/ea-instance/outputs.tf` (append)

- [ ] **Step 1: Append NEG + backend to `main.tf`**

```hcl
resource "google_compute_region_network_endpoint_group" "this" {
  project               = var.project_id
  name                  = "ea-neg-${var.product}"
  region                = var.region
  network_endpoint_type = "SERVERLESS"
  serverless_deployment {
    platform = "run.googleapis.com/v2"
    resource = google_cloud_run_v2_service.this.name
    resource_path = google_cloud_run_v2_service.this.id
  }
}

resource "google_compute_backend_service" "this" {
  project               = var.project_id
  name                  = "ea-backend-${var.product}"
  region                = var.region
  load_balancing_scheme = "EXTERNAL_MANAGED"
  backend {
    group = google_compute_region_network_endpoint_group.this.id
  }
  enable_cdn = false
}
```

- [ ] **Step 2: Append outputs**

Add to `outputs.tf`:
```hcl
output "backend_self_link" { value = google_compute_backend_service.this.self_link }
output "fqdn"              { value = local.fqdn }
```

- [ ] **Step 3: Commit**

```bash
git add infra/terraform/modules/ea-instance/
git commit -m "feat: ea-instance module — serverless NEG + backend service

Region serverless NEG pointing at the Cloud Run service and an
EXTERNAL_MANAGED regional backend service wrapping it. The
load-balancer module consumes backend_self_link per instance via
the root composition."
```

---

## Chunk 5: Edge — Load Balancer & DNS

### Task 13: Create the load-balancer module

**Files:**
- Create: `infra/terraform/modules/load-balancer/main.tf`
- Create: `infra/terraform/modules/load-balancer/variables.tf`
- Create: `infra/terraform/modules/load-balancer/outputs.tf`

- [ ] **Step 1: Create `modules/load-balancer/variables.tf`**

```hcl
variable "project_id" { type = string }
variable "domain"     { type = string } # wildcard cert subject: *.<domain>

# Per-instance routing: map of fqdn -> backend_self_link
variable "routes" {
  type = map(object({
    fqdn     = string
    backend  = string
  }))
}
```

- [ ] **Step 2: Create `modules/load-balancer/main.tf`**

```hcl
# ── Managed wildcard SSL cert (provisioned before the proxy is wired) ───────
resource "google_compute_managed_ssl_certificate" "wildcard" {
  project = var.project_id
  name    = "ea-wildcard-cert"
  managed {
    domains = ["*.${var.domain}"]
  }
}

# ── HTTPS proxy + global forwarding rule ────────────────────────────────────
locals {
  # URL map: one host rule per route entry, each -> a path matcher for that backend.
  host_rules = [for k, r in var.routes : {
    hosts        = [r.fqdn]
    path_matcher = "pm-${k}"
  }]
}

resource "google_compute_url_map" "https" {
  project         = var.project_id
  name            = "ea-https-map"
  default_url_redirect {
    https_redirect = true
    strip_query    = false
  }
  dynamic "host_rule" {
    for_each = local.host_rules
    content {
      hosts        = host_rule.value.hosts
      path_matcher = host_rule.value.path_matcher
    }
  }
  dynamic "path_matcher" {
    for_each = var.routes
    content {
      name = "pm-${path_matcher.key}"
      default_service = path_matcher.value.backend
    }
  }
}

resource "google_compute_target_https_proxy" "https" {
  project         = var.project_id
  name            = "ea-https-proxy"
  url_map         = google_compute_url_map.https.id
  ssl_certificates = [google_compute_managed_ssl_certificate.wildcard.id]
}

resource "google_compute_global_forwarding_rule" "https" {
  project    = var.project_id
  name       = "ea-https-fr"
  target     = google_compute_target_https_proxy.https.id
  port_range = "443"
}

# ── HTTP → HTTPS 301 redirect ────────────────────────────────────────────────
resource "google_compute_url_map" "http_redirect" {
  project      = var.project_id
  name         = "ea-http-redirect"
  default_url_redirect {
    https_redirect = true
    strip_query    = false
  }
}

resource "google_compute_target_http_proxy" "http" {
  project  = var.project_id
  name     = "ea-http-proxy"
  url_map  = google_compute_url_map.http_redirect.id
}

resource "google_compute_global_forwarding_rule" "http" {
  project    = var.project_id
  name       = "ea-http-fr"
  target     = google_compute_target_http_proxy.http.id
  port_range = "80"
}
```

- [ ] **Step 3: Create `modules/load-balancer/outputs.tf`**

```hcl
output "ipv4" {
  value = google_compute_global_forwarding_rule.https.ip_address
}
output "ipv6" {
  # IPv6 requires a separate forwarding rule; add if ipv6 enabled on the project.
  value = null
}
```

- [ ] **Step 4: Commit**

```bash
git add infra/terraform/modules/load-balancer/
git commit -m "feat: add load-balancer module

Global external HTTPS LB with a managed wildcard *.bookings.domain
certificate, per-host path matchers routing each product subdomain
to its serverless backend, and an HTTP→HTTPS 301 redirect. Cert is
provisioned before the proxy binds it."
```

---

### Task 14: Create the dns module

**Files:**
- Create: `infra/terraform/modules/dns/main.tf`
- Create: `infra/terraform/modules/dns/variables.tf`
- Create: `infra/terraform/modules/dns/outputs.tf`

- [ ] **Step 1: Create `modules/dns/variables.tf`**

```hcl
variable "project_id" { type = string }
variable "domain"     { type = string }
variable "lb_ip"      { type = string }
variable "subdomains" { type = list(string) } # e.g. ["product-a", "product-b"]
```

- [ ] **Step 2: Create `modules/dns/main.tf`**

```hcl
# Managed zone for <domain>. Must be delegated from the registrar.
resource "google_dns_managed_zone" "this" {
  project     = var.project_id
  name        = "ea-bookings"
  dns_name    = "${var.domain}."
  description = "EasyAppointments bookings zone"
  visibility  = "public"
}

# One A record per product subdomain, all pointing at the LB anycast IP.
resource "google_dns_record_set" "instance" {
  for_each     = toset(var.subdomains)
  project      = var.project_id
  managed_zone = google_dns_managed_zone.this.name
  name         = "${each.value}.${var.domain}."
  type         = "A"
  ttl          = 300
  rrdatas      = [var.lb_ip]
}
```

- [ ] **Step 3: Create `modules/dns/outputs.tf`**

```hcl
output "zone_name"   { value = google_dns_managed_zone.this.name }
output "nameservers" { value = google_dns_managed_zone.this.name_servers }
```

- [ ] **Step 4: Commit**

```bash
git add infra/terraform/modules/dns/
git commit -m "feat: add dns module

Public Cloud DNS managed zone for <domain> plus one A record per
product subdomain → LB anycast IP. Output the assigned nameservers
so the registrar NS delegation can be verified."
```

---

## Chunk 6: Root Composition & First Live Instance

### Task 15: Wire everything together in main.tf

**Files:**
- Create: `infra/terraform/main.tf`

- [ ] **Step 1: Create `main.tf`**

```hcl
locals {
  effective_image = coalesce(var.image, "${module.registry.repo_full}/ea-production:latest")
}

# ── Bootstrap (apply once with -target before backend migration) ────────────
module "bootstrap" {
  source     = "./modules/project-bootstrap"
  project_id = var.project_id
  region     = var.region
}

# ── Registry ────────────────────────────────────────────────────────────────
module "registry" {
  source     = "./modules/artifact-registry"
  project_id = var.project_id
  region     = var.region
}

# ── Network ─────────────────────────────────────────────────────────────────
module "network" {
  source     = "./modules/network"
  project_id = var.project_id
  region     = var.region
}

# ── Cloud SQL ───────────────────────────────────────────────────────────────
resource "random_password" "sql_root" { length = 32 special = false }

module "cloudsql" {
  source           = "./modules/cloud-sql"
  project_id       = var.project_id
  region           = var.region
  tier             = var.sql_tier
  disk_gb          = var.sql_disk_gb
  vpc_self_link    = module.network.vpc_self_link
  root_password    = random_password.sql_root.result
}

# ── Shared secrets ──────────────────────────────────────────────────────────
module "secrets" {
  source           = "./modules/secrets"
  project_id       = var.project_id
  smtp_host        = var.smtp_host
  smtp_port        = var.smtp_port
  smtp_user        = var.smtp_user
  smtp_pass        = var.smtp_pass
  smtp_crypto      = var.smtp_crypto
  smtp_from_address= var.smtp_from_address
  smtp_from_name   = var.smtp_from_name
}

# ── Per-instance fan-out ────────────────────────────────────────────────────
module "ea_instance" {
  source = "./modules/ea-instance"
  for_each = var.products

  project_id   = var.project_id
  region       = var.region
  product      = each.key
  domain       = var.domain
  image        = local.effective_image
  sql_instance = module.cloudsql.instance_name
  sql_connection_name = module.cloudsql.connection_name
  vpc_connector = module.network.connector_self_link
  puller_sa_email = module.registry.puller_email
  shared_secret_ids = module.secrets.secret_ids

  cors_origins      = each.value.cors_origins
  frame_ancestors   = each.value.frame_ancestors
  google_sync       = each.value.google_sync
  google_client_id    = each.value.google_client_id
  google_client_secret= each.value.google_client_secret
  min_instances    = each.value.min_instances
  max_instances    = each.value.max_instances
}

# ── Backup job (knows all DB names) ─────────────────────────────────────────
# Dedicated SA for the dumper.
resource "google_service_account" "dumper" {
  project      = var.project_id
  account_id   = "ea-db-dumper"
  display_name = "EA DB dumper"
}
resource "google_project_iam_member" "dumper_sql" {
  project = var.project_id
  role    = "roles/cloudsql.client"
  member  = "serviceAccount:${google_service_account.dumper.email}"
}
resource "google_project_iam_member" "dumper_run_invoker" {
  project = var.project_id
  role    = "roles/run.invoker"
  member  = "serviceAccount:${google_service_account.dumper.email}"
}

module "backups" {
  source             = "./modules/backup-job"
  project_id         = var.project_id
  region             = var.region
  sql_instance       = module.cloudsql.instance_name
  sql_root_password  = random_password.sql_root.result
  db_names           = [for k, v in var.products : "ea_${replace(k, "-", "_")}"]
  sa_email           = google_service_account.dumper.email
}

# ── Load balancer ───────────────────────────────────────────────────────────
module "lb" {
  source     = "./modules/load-balancer"
  project_id = var.project_id
  domain     = var.domain
  routes = {
    for k, inst in module.ea_instance : k => {
      fqdn    = inst.fqdn
      backend = inst.backend_self_link
    }
  }
}

# ── DNS ─────────────────────────────────────────────────────────────────────
module "dns" {
  source     = "./modules/dns"
  project_id = var.project_id
  domain     = var.domain
  lb_ip      = module.lb.ipv4
  subdomains = [for k, v in var.products : k]
}
```

- [ ] **Step 2: Run `terraform init` to pull all module sources**

Run: `cd infra/terraform && terraform init`

Expected: all 8 local modules registered, providers installed.

- [ ] **Step 3: Validate**

Run: `terraform validate`

Expected: `Success! The configuration is valid.`

- [ ] **Step 4: Commit**

```bash
git add infra/terraform/main.tf
git commit -m "feat: wire root composition with ea-instance for_each

Composes all 8 modules: bootstrap, registry, network, cloudsql,
secrets, ea_instance (fans out from var.products), backups, lb, dns.
One random_password for SQL root; dedicated dumper SA. Single
terraform apply provisions the entire stack + N instances."
```

---

### Task 16: Build the EA image, push to Artifact Registry, apply, verify

- [ ] **Step 1: Authenticate Docker to Artifact Registry**

Run:
```bash
gcloud auth configure-docker $(echo $REGION)-docker.pkg.dev
```
Expected: `Added credentials for ...`

- [ ] **Step 2: Build and push the production image**

Run (from repo root):
```bash
docker build -f Dockerfile.production -t $(echo $REGION)-docker.pkg.dev/$PROJECT/ea-production:$(git rev-parse --short HEAD) .
docker tag  $(echo $REGION)-docker.pkg.dev/$PROJECT/ea-production:$(git rev-parse --short HEAD) \
           $(echo $REGION)-docker.pkg.dev/$PROJECT/ea-production:latest
docker push $(echo $REGION)-docker.pkg.dev/$PROJECT/ea-production:$(git rev-parse --short HEAD)
docker push $(echo $REGION)-docker.pkg.dev/$PROJECT/ea-production:latest
```
Expected: both tags pushed.

- [ ] **Step 3: Apply the full stack with a single product**

Set `terraform.tfvars` with `products = { product-a = { ... } }`, then:
```bash
terraform apply
```

Expected: ~6–8 minutes. All resources created. `module.lb.ipv4` and `module.dns.nameservers` populated in outputs.

- [ ] **Step 4: Delegate the zone at the registrar**

Update the registrar's NS records for `<domain>` to the four Cloud DNS nameservers from `module.dns.nameservers`.

Verify propagation:
```bash
dig +short NS ${DOMAIN}
dig +short ${PRODUCT_A}.${DOMAIN}
```
Expected: NS lists Google nameservers; A record returns the LB IP.

- [ ] **Step 5: Wait for managed cert to go ACTIVE**

```bash
gcloud compute ssl-certificates describe ea-wildcard-cert --format='value(managed.status)'
```
Expected: `ACTIVE` (5–30 min after DNS resolves).

- [ ] **Step 6: Verify the instance is reachable over HTTPS**

```bash
curl -sS -o /dev/null -w '%{http_code}\n' https://${PRODUCT_A}.${DOMAIN}/installation
```
Expected: `200` (EA install wizard).

- [ ] **Step 7: Complete the install wizard**

Visit `https://${PRODUCT_A}.${DOMAIN}/installation`, set admin creds, company name, configure one service/provider, generate API token.

- [ ] **Step 8: Verify the API responds with the Bearer token**

```bash
curl -sS -H "Authorization: Bearer ${TOKEN}" \
  https://${PRODUCT_A}.${DOMAIN}/api/v1/appointments | jq length
```
Expected: a number (likely `0` on fresh install).

---

## Chunk 7: CI, Migration Runbook, Docs

### Task 17: Create the GitHub Actions build-and-deploy workflow

**Files:**
- Create: `.github/workflows/build-and-deploy.yml`

- [ ] **Step 1: Create the workflow**

```yaml
name: Build and Deploy to GCP

on:
  push:
    branches: [main]
    tags: ['v*']
  pull_request:
    branches: [main]

permissions:
  contents: read
  id-token: write   # Workload Identity Federation
  packages: write

env:
  REGION: us-central1
  AR_REPO: ea-production

jobs:
  build:
    runs-on: ubuntu-latest
    outputs:
      image: ${{ steps.meta.outputs.image }}
    steps:
      - uses: actions/checkout@v4

      - id: auth
        uses: google-github-actions/auth@v2
        with:
          workload_identity_provider: ${{ secrets.WIF_PROVIDER }}
          service_account: ${{ secrets.WIF_SA }}

      - uses: google-github-actions/setup-gcloud@v2

      - name: Configure Docker for AR
        run: gcloud auth configure-docker ${{ env.REGION }}-docker.pkg.dev

      - id: meta
        run: |
          SHA=$(git rev-parse --short HEAD)
          IMAGE="${{ env.REGION }}-docker.pkg.dev/${{ secrets.GCP_PROJECT }}/${{ env.AR_REPO }}:$SHA"
          echo "image=$IMAGE" >> $GITHUB_OUTPUT
          docker build -f Dockerfile.production -t "$IMAGE" -t ${{ env.REGION }}-docker.pkg.dev/${{ secrets.GCP_PROJECT }}/${{ env.AR_REPO }}:latest .
          docker push "$IMAGE"
          docker push ${{ env.REGION }}-docker.pkg.dev/${{ secrets.GCP_PROJECT }}/${{ env.AR_REPO }}:latest --all-tags

  terraform:
    needs: build
    runs-on: ubuntu-latest
    defaults:
      run:
        working-directory: infra/terraform
    steps:
      - uses: actions/checkout@v4

      - id: auth
        uses: google-github-actions/auth@v2
        with:
          workload_identity_provider: ${{ secrets.WIF_PROVIDER }}
          service_account: ${{ secrets.WIF_SA }}

      - uses: hashicorp/setup-terraform@v3
        with:
          terraform_version: 1.9.0

      - run: terraform init

      - name: Plan (PRs)
        if: github.event_name == 'pull_request'
        run: terraform plan -no-color -var="image=${{ needs.build.outputs.image }}"
        env:
          TF_VAR_project_id: ${{ secrets.GCP_PROJECT }}
          TF_VAR_domain: ${{ secrets.DOMAIN }}
          TF_VAR_smtp_pass: ${{ secrets.SMTP_PASS }}

      - name: Apply (main / tags)
        if: github.ref == 'refs/heads/main' || startsWith(github.ref, 'refs/tags/v')
        run: terraform apply -auto-approve -var="image=${{ needs.build.outputs.image }}"
        env:
          TF_VAR_project_id: ${{ secrets.GCP_PROJECT }}
          TF_VAR_domain: ${{ secrets.DOMAIN }}
          TF_VAR_smtp_pass: ${{ secrets.SMTP_PASS }}
```

- [ ] **Step 2: Commit**

```bash
git add .github/workflows/build-and-deploy.yml
git commit -m "ci: add build-and-deploy workflow for GCP

Builds Dockerfile.production, pushes to Artifact Registry with SHA +
latest tags, then terraform plan on PRs and terraform apply on main.
Auth via Workload Identity Federation (no static keys). Image SHA
passed to terraform via -var=image so each apply rolls the new
revision."
```

---

### Task 18: Document Workload Identity Federation setup

**Files:**
- Create: `infra/WIF-SETUP.md`

- [ ] **Step 1: Create the setup guide**

```markdown
# Workload Identity Federation — one-time setup

Lets GitHub Actions authenticate to GCP without a static service account key.

## 1. Create the pool + provider

```bash
gcloud iam workload-identity-pools create github \
  --location=global
gcloud iam workload-identity-pools providers create-oidc github \
  --location=global \
  --workload-identity-pool=github \
  --issuer-uri=https://token.actions.githubusercontent.com \
  --attribute-mapping="google.subject=assertion.sub,repository=assertion.repository"
```

## 2. Create the GCP SA GitHub will impersonate

```bash
gcloud iam service-accounts create gh-actions-deployer
```

Grant it the roles the workflow needs:
```bash
for ROLE in run.admin sqladmin.admin secretmanager.admin \
            artifactregistry.admin dns.admin compute.admin \
            storage.admin iam.serviceAccountUser cloudscheduler.admin; do
  gcloud projects add-iam-policy-binding $PROJECT \
    --member="serviceAccount:gh-actions-deployer@${PROJECT}.iam.gserviceaccount.com" \
    --role="roles/${ROLE}"
done
```

## 3. Bind the pool to the SA for this repo

```bash
REPO="yourorg/easyappointments"
gcloud iam service-accounts add-iam-policy-binding \
  gh-actions-deployer@${PROJECT}.iam.gserviceaccount.com \
  --role=roles/iam.workloadIdentityUser \
  --member="principalSet://iam.googleapis.com/projects/$(gcloud projects describe $PROJECT --format='value(projectNumber)')/locations/global/workloadIdentityPools/github/attributes.repository/${REPO}"
```

## 4. Add repo secrets

- `WIF_PROVIDER`: `projects/<NUMBER>/locations/global/workloadIdentityPools/github/providers/github`
- `WIF_SA`: `gh-actions-deployer@<PROJECT>.iam.gserviceaccount.com`
- `GCP_PROJECT`, `DOMAIN`, `SMTP_PASS`
```

- [ ] **Step 2: Commit**

```bash
git add infra/WIF-SETUP.md
git commit -m "docs: add Workload Identity Federation setup guide

One-time commands to create the GitHub OIDC pool/provider, the
gh-actions-deployer SA, role grants, repo binding, and the four
required GitHub secrets."
```

---

### Task 19: Create the Hetzner → GCP migration runbook

**Files:**
- Create: `infra/migrate-from-hetzner.sh`

- [ ] **Step 1: Create the migration script**

```bash
#!/usr/bin/env bash
# One-shot migration: dump each ea_* DB from the Hetzner MySQL,
# import into Cloud SQL. Run once per product.
set -euo pipefail

: "${1:?Usage: $0 <product> <hetzner-mysql-host> <hetzner-root-pass> <gcp-project> <gcp-region>}"
PRODUCT="$1"; HZ_HOST="$2"; HZ_PASS="$3"; PROJECT="$4"; REGION="$5"
DB="ea_$(echo "$PRODUCT" | tr - _)"

TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT

echo ">>> Dumping $DB from Hetzner ($HZ_HOST)..."
ssh -i ~/.ssh/hetzner_easyappointments "root@$HZ_HOST" \
  "docker exec booking-mysql mysqldump --single-transaction --no-tablespaces -u root -p$HZ_PASS $DB" \
  > "$TMP/$DB.sql"

echo ">>> Uploading to GCS import bucket..."
BUCKET="gs://${PROJECT}-ea-import"
gcloud storage cp "$TMP/$DB.sql" "$BUCKET/"

echo ">>> Importing into Cloud SQL (ea-mysql)..."
gcloud sql import sql "ea-mysql" "$BUCKET/$DB.sql" \
  --database="$DB" --quiet

echo ">>> Done. Verify row counts, then flip DNS for $PRODUCT."
```

- [ ] **Step 2: Make it executable and syntax-check**

```bash
chmod +x infra/migrate-from-hetzner.sh
bash -n infra/migrate-from-hetzner.sh
```
Expected: no output (no syntax errors).

- [ ] **Step 3: Commit**

```bash
git add infra/migrate-from-hetzner.sh
git commit -m "feat: add Hetzner→GCP migration script

Per-product one-shot: mysqldump from Hetzner MySQL over SSH, upload
to a GCS import bucket, gcloud sql import sql into ea-mysql. Run
once per product during the Phase 2 data migration window."
```

---

### Task 20: Write infra/README.md and infra/RUNBOOK.md

**Files:**
- Create: `infra/README.md`
- Create: `infra/RUNBOOK.md`

- [ ] **Step 1: Create `infra/README.md`**

Cover: prerequisites, one-time bootstrap (`terraform apply -target=module.bootstrap` then `terraform init -migrate-state`), normal flow (`terraform plan/apply`), how to add an instance (one entry in `products`), how to remove an instance (`terraform destroy -target=module.ea_instance[\"product-x\"]`), where secrets live.

- [ ] **Step 2: Create `infra/RUNBOOK.md`**

Cover operational procedures:
- Rotate a secret: `terraform taint random_password.db_pass` (or `terraform apply -replace=...`) then redeploy the Cloud Run revision.
- Force an ad-hoc backup: `gcloud run jobs execute ea-db-dumper --region $REGION`.
- Scale an instance: edit `min_instances`/`max_instances` in `products` map, `terraform apply`.
- View logs: `gcloud run services logs read ea-product-a --region $REGION`.
- Roll back a deploy: `gcloud run services update-traffic ea-product-a --to-revisions=LATEST-1=100`.
- Decommission Hetzner (after 48h stable on GCP): terminate CX22, delete Coolify project, revoke SSH keys.

- [ ] **Step 3: Commit**

```bash
git add infra/README.md infra/RUNBOOK.md
git commit -m "docs: add infra README and ops RUNBOOK

README covers prerequisites, bootstrap, normal plan/apply flow, and
adding/removing instances. RUNBOOK covers secret rotation, ad-hoc
backups, scaling, log access, deploy rollback, and Hetzner
decommission."
```

---

### Task 21: Final verification — multi-instance apply, destroy test

- [ ] **Step 1: Add a second instance to the products map**

```hcl
products = {
  product-a = { ... }
  product-b = {
    cors_origins    = "https://app.product-b.com"
    frame_ancestors = "https://app.product-b.com"
  }
}
```

- [ ] **Step 2: Plan and apply**

Run: `terraform plan` — verify the diff shows exactly one new instance's worth of resources (DB, user, ~12 secrets, SA, Cloud Run svc, NEG, backend, DNS record, LB path entry). Then `terraform apply`.

- [ ] **Step 3: Verify product-b**

```bash
curl -sS -o /dev/null -w '%{http_code}\n' https://product-b.${DOMAIN}/installation
```
Expected: `200`.

- [ ] **Step 4: Test clean destroy of a single instance**

Run: `terraform destroy -target='module.ea_instance["product-b"]'`

Expected: product-b's Cloud Run svc, SA, secrets, DB, user, NEG, backend, DNS record, LB path entry all destroyed; product-a untouched.

- [ ] **Step 5: Verify product-b no longer resolves**

```bash
curl -sS -o /dev/null -w '%{http_code}\n' https://product-b.${DOMAIN}/installation
```
Expected: DNS still cached briefly, then `404` from LB (path no longer matches).

- [ ] **Step 6: Re-apply product-b to restore it**

Run: `terraform apply`

- [ ] **Step 7: Verify the hourly backup fired**

Wait one hour (or trigger manually: `gcloud run jobs execute ea-db-dumper --region $REGION`), then:
```bash
gcloud storage ls gs://${PROJECT}-ea-db-backups/hourly/ | head
```
Expected: at least one object per `ea_*` DB.

- [ ] **Step 8: Final commit — tag as ready**

```bash
git add -A
git status  # verify nothing unexpected staged
git commit -m "chore: GCP Terraform deployment stack complete

8 modules, for_each fan-out per product, CI via GitHub Actions +
Workload Identity Federation, Hetzner migration runbook, README +
RUNBOOK. Replaces Hetzner CX22 + Coolify with reproducible infra."
git tag gcp-terraform-v1.0.0
```

---

## Summary of All Files

| # | Action | File | Task |
|---|---|---|---|
| 1 | Create | `infra/terraform/.gitignore` | Task 1 |
| 2 | Create | `infra/terraform/versions.tf` | Task 1 |
| 3 | Create | `infra/terraform/backend.tf` | Task 1 |
| 4 | Create | `infra/terraform/providers.tf` | Task 1 |
| 5 | Create | `infra/terraform/variables.tf` | Task 1 |
| 6 | Create | `infra/terraform/terraform.tfvars.example` | Task 1 |
| 7 | Create | `infra/terraform/modules/project-bootstrap/main.tf` | Task 2 |
| 8 | Create | `infra/terraform/modules/project-bootstrap/variables.tf` | Task 2 |
| 9 | Create | `infra/terraform/modules/project-bootstrap/outputs.tf` | Task 2 |
| 10 | Create | `infra/terraform/modules/artifact-registry/main.tf` | Task 3 |
| 11 | Create | `infra/terraform/modules/artifact-registry/variables.tf` | Task 3 |
| 12 | Create | `infra/terraform/modules/artifact-registry/outputs.tf` | Task 3 |
| 13 | Create | `infra/terraform/modules/network/main.tf` | Task 5 |
| 14 | Create | `infra/terraform/modules/network/variables.tf` | Task 5 |
| 15 | Create | `infra/terraform/modules/network/outputs.tf` | Task 5 |
| 16 | Create | `infra/terraform/modules/cloud-sql/main.tf` | Task 6 |
| 17 | Create | `infra/terraform/modules/cloud-sql/variables.tf` | Task 6 |
| 18 | Create | `infra/terraform/modules/cloud-sql/outputs.tf` | Task 6 |
| 19 | Create | `infra/terraform/modules/secrets/main.tf` | Task 8 |
| 20 | Create | `infra/terraform/modules/secrets/variables.tf` | Task 8 |
| 21 | Create | `infra/terraform/modules/secrets/outputs.tf` | Task 8 |
| 22 | Create | `infra/terraform/modules/backup-job/main.tf` | Task 9 |
| 23 | Create | `infra/terraform/modules/backup-job/variables.tf` | Task 9 |
| 24 | Create | `infra/terraform/modules/backup-job/outputs.tf` | Task 9 |
| 25 | Create | `infra/terraform/modules/backup-job/dump.sh.tpl` | Task 9 |
| 26 | Create | `infra/terraform/modules/ea-instance/variables.tf` | Task 10 |
| 27 | Create | `infra/terraform/modules/ea-instance/main.tf` | Task 10, 11, 12 |
| 28 | Create | `infra/terraform/modules/ea-instance/outputs.tf` | Task 10, 11, 12 |
| 29 | Create | `infra/terraform/modules/load-balancer/main.tf` | Task 13 |
| 30 | Create | `infra/terraform/modules/load-balancer/variables.tf` | Task 13 |
| 31 | Create | `infra/terraform/modules/load-balancer/outputs.tf` | Task 13 |
| 32 | Create | `infra/terraform/modules/dns/main.tf` | Task 14 |
| 33 | Create | `infra/terraform/modules/dns/variables.tf` | Task 14 |
| 34 | Create | `infra/terraform/modules/dns/outputs.tf` | Task 14 |
| 35 | Create | `infra/terraform/main.tf` | Task 15 |
| 36 | Create | `.github/workflows/build-and-deploy.yml` | Task 17 |
| 37 | Create | `infra/WIF-SETUP.md` | Task 18 |
| 38 | Create | `infra/migrate-from-hetzner.sh` | Task 19 |
| 39 | Create | `infra/README.md` | Task 20 |
| 40 | Create | `infra/RUNBOOK.md` | Task 20 |

## Dependency Order

```
Chunk 1 (scaffold + bootstrap + registry)
   └─> Chunk 2 (network + cloud-sql)
         └─> Chunk 3 (secrets + backup-job)
               └─> Chunk 4 (ea-instance module)
                     └─> Chunk 5 (lb + dns)
                           └─> Chunk 6 (main.tf composition + first live instance)
                                 └─> Chunk 7 (CI + migration + docs + final verify)
```

Each chunk is independently verifiable before moving on. No chunk modifies application code.

# Infrastructure — GCP Terraform Deployment

This directory contains the Terraform definition and operational tooling for
deploying EasyAppointments to Google Cloud Platform. It replaces the previous
Hetzner CX22 + Coolify manual deployment with a fully reproducible,
infrastructure-as-code stack.

> **Design spec:** The full architecture, decisions, and module breakdown live in
> [`docs/superpowers/specs/2026-07-02-gcp-terraform-deployment-design.md`](../docs/superpowers/specs/2026-07-02-gcp-terraform-deployment-design.md).
> This README is an operator's quick reference; consult the spec for rationale.

## Architecture summary

A single `terraform apply` provisions the entire stack from one
`products` map entry per EasyAppointments instance:

- **Cloud Run** — one service per product running the unchanged
  `Dockerfile.production` image (Nginx + PHP-FPM + supervisord), fronted by a
  global external HTTPS Load Balancer via serverless NEGs.
- **Cloud SQL** — one shared regional-HA MySQL 8.0 instance (private IP, SSL,
  automated backups + PITR) holding one database and one dedicated
  least-privilege user per instance.
- **Secret Manager** — all `EA_*` env vars and shared SMTP creds; nothing
  sensitive in plaintext env vars.
- **Cloud DNS** — public managed zone for the bookings domain with one A record
  per product subdomain → LB anycast IP.
- **Backups** — hourly `mysqldump` via a Cloud Run Job + Cloud Scheduler into a
  GCS bucket with a 30-day lifecycle.
- **Uploads** — per-instance GCS bucket mounted into the Cloud Run container.

The `ea-instance` module (in `terraform/modules/ea-instance/`) is fanned out
with `for_each` from the root `products` map, so every key produces a complete,
isolated instance (DB, user, secrets, SA, Cloud Run service, NEG, backend, DNS
record, LB path entry).

All Terraform lives under `infra/terraform/`; run every command below from that
directory unless noted.

## Prerequisites

- **Terraform** ≥ 1.6 (the CI workflow pins 1.9.0).
- **Google Cloud CLI** (`gcloud`) installed and authenticated:
  ```bash
  gcloud auth login
  gcloud auth application-default login
  ```
- A **GCP project** with billing enabled. Note its project ID
  (used as `project_id`).
- A **bookings domain** you control (e.g. `bookings.example.com`), delegatable
  to Cloud DNS nameservers.
- The **production image** built from `Dockerfile.production` at the repo root
  (pushed to the `ea-production` Artifact Registry repository — created by the
  `artifact-registry` module).
- **Workload Identity Federation** set up for CI — see
  [`WIF-SETUP.md`](WIF-SETUP.md).

Create a `terraform.tfvars` from `terraform.tfvars.example`:
```hcl
project_id = "your-gcp-project-id"
region     = "us-central1"
domain     = "bookings.example.com"
smtp_pass  = "re_YOUR_RESEND_KEY"

products = {
  product-a = {
    cors_origins    = "https://app.product-a.com"
    frame_ancestors = "https://app.product-a.com"
  }
}
```

`terraform.tfvars` is git-ignored — never commit it.

## One-time bootstrap

The Terraform state itself must live in a GCS bucket, but that bucket cannot
exist until after the first apply. Resolve this chicken-and-egg with a targeted
bootstrap apply against local state, then migrate state into GCS.

1. **Comment out** the `backend "gcs"` block in
   `infra/terraform/backend.tf` (the file has instructions to this effect).
2. Apply **only** the bootstrap module, which enables the required GCP APIs and
   creates the `<project>-ea-tfstate` bucket:
   ```bash
   cd infra/terraform
   terraform init
   terraform apply -target=module.bootstrap
   ```
3. **Uncomment** the `backend "gcs"` block (set the bucket name if it differs
   from the default `ea-tfstate`), then migrate local state into GCS:
   ```bash
   terraform init -migrate-state
   ```
   Expected: `Successfully configured the backend "gcs"` and local state moved
   into the remote bucket.

From here on, every `init` pulls state from GCS automatically.

## Normal flow

Build and push the image, then apply the whole stack:
```bash
cd infra/terraform
terraform plan
terraform apply
```

On a clean apply, expect ~6–8 minutes for Cloud SQL, the cert, the LB, and the
Cloud Run services to converge. After apply, note the outputs
(`module.lb.ipv4`, `module.dns.nameservers`) and delegate the domain at your
registrar to the four Cloud DNS nameservers.

CI runs the same flow automatically on `main` / `v*` tags — see
[`.github/workflows/build-and-deploy.yml`](../.github/workflows/build-and-deploy.yml).

## Adding an instance

Add **one entry** to the `products` map in `terraform.tfvars`:
```hcl
products = {
  product-a = {
    cors_origins    = "https://app.product-a.com"
    frame_ancestors = "https://app.product-a.com"
    min_instances   = 1
  }
  product-b = {
    cors_origins    = "https://app.product-b.com"
    frame_ancestors = "https://app.product-b.com"
    min_instances   = 1
  }
}
```

Then:
```bash
terraform plan    # verify the diff is exactly one instance's worth of resources
terraform apply
```

The `for_each` fan-out provisions a complete, isolated instance for the new key:
Cloud SQL DB + user, ~12 Secret Manager secrets, runner SA + IAM, the Cloud Run
service, serverless NEG + backend, LB host rule + path matcher, and a DNS A
record. Existing instances are untouched.

## Removing an instance

Destroy a single instance without affecting the others (useful for offboarding
a product):
```bash
terraform destroy -target='module.ea_instance["product-x"]'
```

This tears down only that instance's Cloud Run service, SA, secrets, DB, user,
NEG, backend, DNS record, and LB path entry. Re-running `terraform apply`
restores it.

> **Note:** Cloud SQL has `deletion_protection = true`. Destroying an instance
> removes its database and user but leaves the shared SQL instance intact —
> exactly what you want for offboarding a product.

## Where secrets live

All secrets live in **Google Secret Manager** — never in env-var plaintext, the
Terraform state in plain view, or `terraform.tfvars` (except `smtp_pass`, which
is `sensitive` and marked as such).

- **Shared SMTP** — one secret per value (`smtp_host`, `smtp_port`, `smtp_user`,
  `smtp_pass`, `smtp_crypto`, `smtp_from_address`, `smtp_from_name`), created by
  the `secrets` module as `ea-shared-<key>`. Consumed by every instance.
- **Per-instance** — one secret per `EA_*` env var, created by the
  `ea-instance` module as `ea-<product>-<key>` (e.g.
  `ea-product-a-db-password`, `ea-product-a-encryption-key`). Generated values
  such as `db_pass` and `encryption_key` come from `random_password` resources.
- **Runtime access** — each instance's runner SA is granted
  `roles/secretmanager.secretAccessor` on its own secrets plus the shared SMTP
  set; nothing else can read them.

To inspect or rotate a secret, see [`RUNBOOK.md`](RUNBOOK.md).

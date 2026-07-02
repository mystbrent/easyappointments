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
resource "random_password" "sql_root" {
  length  = 32
  special = false
}

module "cloudsql" {
  source        = "./modules/cloud-sql"
  project_id    = var.project_id
  region        = var.region
  tier          = var.sql_tier
  disk_gb       = var.sql_disk_gb
  vpc_self_link = module.network.vpc_self_link
  root_password = random_password.sql_root.result
}

# ── Shared secrets ──────────────────────────────────────────────────────────
module "secrets" {
  source            = "./modules/secrets"
  project_id        = var.project_id
  smtp_host         = var.smtp_host
  smtp_port         = var.smtp_port
  smtp_user         = var.smtp_user
  smtp_pass         = var.smtp_pass
  smtp_crypto       = var.smtp_crypto
  smtp_from_address = var.smtp_from_address
  smtp_from_name    = var.smtp_from_name
}

# ── Per-instance fan-out ────────────────────────────────────────────────────
module "ea_instance" {
  source   = "./modules/ea-instance"
  for_each = var.products

  project_id          = var.project_id
  region              = var.region
  product             = each.key
  domain              = var.domain
  image               = local.effective_image
  sql_instance        = module.cloudsql.instance_name
  sql_connection_name = module.cloudsql.connection_name
  vpc_connector       = module.network.connector_self_link
  puller_sa_email     = module.registry.puller_email
  shared_secret_ids   = module.secrets.secret_ids

  cors_origins         = each.value.cors_origins
  frame_ancestors      = each.value.frame_ancestors
  google_sync          = each.value.google_sync
  google_client_id     = each.value.google_client_id
  google_client_secret = each.value.google_client_secret
  min_instances        = each.value.min_instances
  max_instances        = each.value.max_instances
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
  source            = "./modules/backup-job"
  project_id        = var.project_id
  region            = var.region
  sql_instance      = module.cloudsql.instance_name
  sql_root_password = random_password.sql_root.result
  db_names          = [for k, v in var.products : "ea_${replace(k, "-", "_")}"]
  sa_email          = google_service_account.dumper.email
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

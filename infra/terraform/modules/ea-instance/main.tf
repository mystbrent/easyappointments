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
    "ea-${var.product}-base-url"             = "https://${local.fqdn}"
    "ea-${var.product}-db-host"              = var.sql_instance # resolved via Cloud Run socket; placeholder for clarity
    "ea-${var.product}-db-name"              = local.db
    "ea-${var.product}-db-username"          = local.dbuser
    "ea-${var.product}-db-password"          = random_password.db_pass.result
    "ea-${var.product}-encryption-key"       = random_password.encryption_key.result
    "ea-${var.product}-cors-origins"         = var.cors_origins
    "ea-${var.product}-frame-ancestors"      = var.frame_ancestors
    "ea-${var.product}-proxy-ips"            = "" # set in Task 11 after LB module exposes source CIDRs
    "ea-${var.product}-google-sync"          = tostring(var.google_sync)
    "ea-${var.product}-google-client-id"     = var.google_client_id
    "ea-${var.product}-google-client-secret" = var.google_client_secret
  }
}

resource "google_secret_manager_secret" "instance" {
  for_each  = local.instance_secrets
  project   = var.project_id
  secret_id = each.key
  replication {
    auto {}
  }
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

# ── Per-instance runner SA ───────────────────────────────────────────────────
resource "google_service_account" "runner" {
  project      = var.project_id
  account_id   = "ea-${var.product}-runner"
  display_name = "EA ${var.product} Cloud Run runner"
}

# Access this instance's secrets + shared SMTP secrets + uploads bucket + SQL.
resource "google_secret_manager_secret_iam_member" "instance_access" {
  for_each  = google_secret_manager_secret.instance
  project   = var.project_id
  secret_id = each.value.id
  role      = "roles/secretmanager.secretAccessor"
  member    = "serviceAccount:${google_service_account.runner.email}"
}

resource "google_secret_manager_secret_iam_member" "shared_access" {
  for_each  = var.shared_secret_ids
  project   = var.project_id
  secret_id = each.value
  role      = "roles/secretmanager.secretAccessor"
  member    = "serviceAccount:${google_service_account.runner.email}"
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
    { name = "EA_BASE_URL", secret = "ea-${var.product}-base-url" },
    { name = "EA_DB_NAME", secret = "ea-${var.product}-db-name" },
    { name = "EA_DB_USERNAME", secret = "ea-${var.product}-db-username" },
    { name = "EA_DB_PASSWORD", secret = "ea-${var.product}-db-password" },
    { name = "EA_ENCRYPTION_KEY", secret = "ea-${var.product}-encryption-key" },
    { name = "EA_CORS_ORIGINS", secret = "ea-${var.product}-cors-origins" },
    { name = "EA_FRAME_ANCESTORS", secret = "ea-${var.product}-frame-ancestors" },
    { name = "EA_GOOGLE_SYNC", secret = "ea-${var.product}-google-sync" },
    { name = "EA_GOOGLE_CLIENT_ID", secret = "ea-${var.product}-google-client-id" },
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
    scaling {
      min_instance_count = var.min_instances
      max_instance_count = var.max_instances
    }

    volumes {
      name = "uploads"
      gcs {
        bucket    = google_storage_bucket.uploads.name
        read_only = false
      }
    }
    volumes {
      name = "cloudsql"
      cloud_sql_instance {
        instances = [var.sql_connection_name]
      }
    }

    containers {
      image = var.image
      ports {
        container_port = 80
      }

      # Per-instance secrets via name.
      dynamic "env" {
        for_each = local.secret_env
        content {
          name = env.value.name
          value_source {
            secret_key_ref {
              secret  = env.value.secret
              version = "latest"
            }
          }
        }
      }
      # Shared SMTP secrets (referenced by secret ID via the shared_secret_ids map).
      dynamic "env" {
        for_each = {
          EA_SMTP_HOST         = "smtp_host"
          EA_SMTP_PORT         = "smtp_port"
          EA_SMTP_USER         = "smtp_user"
          EA_SMTP_PASS         = "smtp_pass"
          EA_SMTP_CRYPTO       = "smtp_crypto"
          EA_MAIL_FROM_ADDRESS = "smtp_from_address"
          EA_MAIL_FROM_NAME    = "smtp_from_name"
        }
        content {
          name = env.key
          value_source {
            secret_key_ref {
              secret  = var.shared_secret_ids[env.value]
              version = "latest"
            }
          }
        }
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

resource "google_compute_region_network_endpoint_group" "this" {
  project               = var.project_id
  name                  = "ea-neg-${var.product}"
  region                = var.region
  network_endpoint_type = "SERVERLESS"
  cloud_run {
    service = google_cloud_run_v2_service.this.name
  }
}

resource "google_compute_backend_service" "this" {
  project               = var.project_id
  name                  = "ea-backend-${var.product}"
  load_balancing_scheme = "EXTERNAL_MANAGED"
  backend {
    group = google_compute_region_network_endpoint_group.this.id
  }
  enable_cdn = false
}

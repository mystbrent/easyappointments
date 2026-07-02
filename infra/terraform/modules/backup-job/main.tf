resource "google_storage_bucket" "backups" {
  project                     = var.project_id
  name                        = "${var.project_id}-ea-db-backups"
  location                    = var.region
  uniform_bucket_level_access = true
  lifecycle_rule {
    condition { age = 30 }
    action { type = "Delete" }
  }
}

# Per-DB Cloud Run Job that runs mysqldump and ships to GCS.
# Uses google/cloud-sdk (has gsutil); mysql-client is installed at job start.
# The dump script is inlined to avoid mounting an external script source.
locals {
  dump_script = <<-EOT
    set -eu
    apt-get update -qq && apt-get install -y -qq default-mysql-client > /dev/null
    TS=$(date -u +%Y%m%dT%H%M%SZ)
    for DB in ${join(" ", var.db_names)}; do
      mysqldump --no-tablespaces --single-transaction \
        --socket="/cloudsql/${var.sql_instance}" \
        --user=root --password="${var.sql_root_password}" "$DB" \
        | gzip > "/tmp/$${DB}_$${TS}.sql.gz"
      gsutil cp "/tmp/$${DB}_$${TS}.sql.gz" "gs://${google_storage_bucket.backups.name}/hourly/$${DB}/$${DB}_$${TS}.sql.gz"
      rm "/tmp/$${DB}_$${TS}.sql.gz"
    done
  EOT
}

resource "google_cloud_run_v2_job" "dumper" {
  project  = var.project_id
  location = var.region
  name     = "ea-db-dumper"

  template {
    template {
      service_account = var.sa_email
      volumes {
        name = "cloudsql"
        cloud_sql_instance {
          instances = [var.sql_instance]
        }
      }
      containers {
        image   = "google/cloud-sdk:latest"
        command = ["/bin/sh", "-c", local.dump_script]
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

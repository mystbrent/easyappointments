output "db_name" { value = local.db }
output "uploads_bucket" { value = google_storage_bucket.uploads.name }

output "service_name" { value = google_cloud_run_v2_service.this.name }
output "service_uri" { value = google_cloud_run_v2_service.this.uri }
output "service_region" { value = var.region }
output "sa_email" { value = google_service_account.runner.email }

output "backend_self_link" { value = google_compute_backend_service.this.self_link }
output "fqdn" { value = local.fqdn }

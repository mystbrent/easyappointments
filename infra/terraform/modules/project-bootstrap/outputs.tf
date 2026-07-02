output "state_bucket" { value = google_storage_bucket.tfstate.name }
output "enabled_apis" { value = [for a in google_project_service.enabled : a.service] }

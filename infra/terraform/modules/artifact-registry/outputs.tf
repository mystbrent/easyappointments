output "repo" { value = google_artifact_registry_repository.ea.name }
output "repo_full" { value = "${var.region}-docker.pkg.dev/${var.project_id}/${google_artifact_registry_repository.ea.name}" }
output "puller_email" { value = google_service_account.puller.email }

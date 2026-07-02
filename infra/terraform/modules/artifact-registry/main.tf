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

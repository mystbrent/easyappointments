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
  for_each                   = toset(local.apis)
  project                    = var.project_id
  service                    = each.value
  disable_dependent_services = false
  disable_on_destroy         = false
}

# Remote-state bucket. Must exist before `terraform init -migrate-state`.
resource "google_storage_bucket" "tfstate" {
  name                        = local.state_bucket
  project                     = var.project_id
  location                    = var.region
  force_destroy               = false
  uniform_bucket_level_access = true
  versioning {
    enabled = true
  }
}

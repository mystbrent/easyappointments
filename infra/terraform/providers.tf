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
  alias                 = "sql-ops"
  project               = var.project_id
  region                = var.region
  user_project_override = true
  request_timeout       = "120s"
}

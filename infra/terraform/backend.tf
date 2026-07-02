# Remote state in GCS. Bucket is created by modules/project-bootstrap.
# Until bootstrap is applied, comment this block out and use local state,
# then `terraform init -migrate-state` to move local state into GCS.

terraform {
  backend "gcs" {
    bucket = "ea-tfstate"
    prefix = "terraform/state"
  }
}

locals {
  shared = {
    smtp_host         = var.smtp_host
    smtp_port         = tostring(var.smtp_port)
    smtp_user         = var.smtp_user
    smtp_pass         = var.smtp_pass
    smtp_crypto       = var.smtp_crypto
    smtp_from_address = var.smtp_from_address
    smtp_from_name    = var.smtp_from_name
  }
}

resource "google_secret_manager_secret" "shared" {
  for_each  = local.shared
  project   = var.project_id
  secret_id = "ea-shared-${each.key}"
  replication {
    auto {}
  }
}

resource "google_secret_manager_secret_version" "shared" {
  for_each    = local.shared
  secret      = google_secret_manager_secret.shared[each.key].id
  secret_data = each.value
}

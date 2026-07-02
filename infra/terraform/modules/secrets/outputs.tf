output "secret_ids" {
  value = { for k, s in google_secret_manager_secret.shared : k => s.id }
}

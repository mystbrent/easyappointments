output "ipv4" {
  value = google_compute_global_forwarding_rule.https.ip_address
}
output "ipv6" {
  # IPv6 requires a separate forwarding rule; add if ipv6 enabled on the project.
  value = null
}

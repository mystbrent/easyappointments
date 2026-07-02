# Managed zone for <domain>. Must be delegated from the registrar.
resource "google_dns_managed_zone" "this" {
  project     = var.project_id
  name        = "ea-bookings"
  dns_name    = "${var.domain}."
  description = "EasyAppointments bookings zone"
  visibility  = "public"
}

# One A record per product subdomain, all pointing at the LB anycast IP.
resource "google_dns_record_set" "instance" {
  for_each     = toset(var.subdomains)
  project      = var.project_id
  managed_zone = google_dns_managed_zone.this.name
  name         = "${each.value}.${var.domain}."
  type         = "A"
  ttl          = 300
  rrdatas      = [var.lb_ip]
}

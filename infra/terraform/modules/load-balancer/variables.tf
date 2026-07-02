variable "project_id" { type = string }
variable "domain" { type = string } # wildcard cert subject: *.<domain>

# Per-instance routing: map of fqdn -> backend_self_link
variable "routes" {
  type = map(object({
    fqdn    = string
    backend = string
  }))
}

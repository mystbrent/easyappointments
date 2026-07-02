# ── Managed wildcard SSL cert (provisioned before the proxy is wired) ───────
resource "google_compute_managed_ssl_certificate" "wildcard" {
  project = var.project_id
  name    = "ea-wildcard-cert"
  managed {
    domains = ["*.${var.domain}"]
  }
}

# ── HTTPS proxy + global forwarding rule ────────────────────────────────────
locals {
  # URL map: one host rule per route entry, each -> a path matcher for that backend.
  host_rules = [for k, r in var.routes : {
    hosts        = [r.fqdn]
    path_matcher = "pm-${k}"
  }]
}

resource "google_compute_url_map" "https" {
  project = var.project_id
  name    = "ea-https-map"
  default_url_redirect {
    https_redirect = true
    strip_query    = false
  }
  dynamic "host_rule" {
    for_each = local.host_rules
    content {
      hosts        = host_rule.value.hosts
      path_matcher = host_rule.value.path_matcher
    }
  }
  dynamic "path_matcher" {
    for_each = var.routes
    content {
      name            = "pm-${path_matcher.key}"
      default_service = path_matcher.value.backend
    }
  }
}

resource "google_compute_target_https_proxy" "https" {
  project          = var.project_id
  name             = "ea-https-proxy"
  url_map          = google_compute_url_map.https.id
  ssl_certificates = [google_compute_managed_ssl_certificate.wildcard.id]
}

resource "google_compute_global_forwarding_rule" "https" {
  project    = var.project_id
  name       = "ea-https-fr"
  target     = google_compute_target_https_proxy.https.id
  port_range = "443"
}

# ── HTTP → HTTPS 301 redirect ────────────────────────────────────────────────
resource "google_compute_url_map" "http_redirect" {
  project = var.project_id
  name    = "ea-http-redirect"
  default_url_redirect {
    https_redirect = true
    strip_query    = false
  }
}

resource "google_compute_target_http_proxy" "http" {
  project = var.project_id
  name    = "ea-http-proxy"
  url_map = google_compute_url_map.http_redirect.id
}

resource "google_compute_global_forwarding_rule" "http" {
  project    = var.project_id
  name       = "ea-http-fr"
  target     = google_compute_target_http_proxy.http.id
  port_range = "80"
}

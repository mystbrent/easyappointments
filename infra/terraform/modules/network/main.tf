resource "google_compute_network" "ea" {
  project                 = var.project_id
  name                    = "ea-vpc"
  auto_create_subnetworks = false
}

# Subnet for the Serverless VPC connector (/28 is the minimum).
resource "google_compute_subnetwork" "connector" {
  project       = var.project_id
  name          = "ea-vpc-connector"
  ip_cidr_range = "10.8.0.0/28"
  region        = var.region
  network       = google_compute_network.ea.id
}

resource "google_vpc_access_connector" "ea" {
  project        = var.project_id
  name           = "ea-connector"
  region         = var.region
  network        = google_compute_network.ea.name
  ip_cidr_range  = "10.8.0.16/28"
  min_throughput = 200
  max_throughput = 1000
}

# Private services access range for Cloud SQL (/20).
resource "google_compute_global_address" "private_sql" {
  project       = var.project_id
  name          = "ea-google-managed-services"
  purpose       = "VPC_PEERING"
  address_type  = "INTERNAL"
  ip_version    = "IPV4"
  prefix_length = 20
  network       = google_compute_network.ea.id
}

resource "google_service_networking_connection" "private_sql" {
  network                 = google_compute_network.ea.id
  service                 = "servicenetworking.googleapis.com"
  reserved_peering_ranges = [google_compute_global_address.private_sql.name]
}

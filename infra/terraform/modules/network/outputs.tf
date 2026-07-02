output "vpc_self_link" { value = google_compute_network.ea.self_link }
output "vpc_name" { value = google_compute_network.ea.name }
output "connector_id" { value = google_vpc_access_connector.ea.id }
output "connector_self_link" { value = google_vpc_access_connector.ea.self_link }

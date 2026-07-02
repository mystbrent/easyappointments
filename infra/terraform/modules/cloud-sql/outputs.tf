output "instance_name" { value = google_sql_database_instance.ea.name }
output "instance_self_link" { value = google_sql_database_instance.ea.self_link }
output "private_ip" {
  value = google_sql_database_instance.ea.private_ip_address
}
output "connection_name" { value = google_sql_database_instance.ea.connection_name }

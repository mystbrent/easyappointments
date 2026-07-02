resource "google_sql_database_instance" "ea" {
  project          = var.project_id
  name             = "ea-mysql"
  region           = var.region
  database_version = "MYSQL_8_0"

  settings {
    tier              = var.tier
    disk_size         = var.disk_gb
    disk_type         = "PD_SSD"
    availability_type = "REGIONAL" # zonal HA within the region

    ip_configuration {
      ipv4_enabled    = false # private IP only
      private_network = var.vpc_self_link
    }

    backup_configuration {
      enabled                        = true
      start_time                     = "02:00"
      point_in_time_recovery_enabled = true
      binary_log_enabled             = true
      backup_retention_settings {
        retained_backups = 14
        retention_unit   = "COUNT"
      }
    }

    database_flags {
      name  = "character_set_server"
      value = "utf8mb4"
    }
    database_flags {
      name  = "collation_server"
      value = "utf8mb4_unicode_ci"
    }
    database_flags {
      name  = "require_secure_transport"
      value = "on"
    }
  }

  deletion_protection = true
}

resource "google_sql_user" "root" {
  project  = var.project_id
  instance = google_sql_database_instance.ea.name
  name     = "root"
  password = var.root_password
  host     = "%"
}

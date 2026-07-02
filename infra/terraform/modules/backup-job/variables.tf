variable "project_id" {
  type = string
}

variable "region" {
  type = string
}

variable "sql_instance" {
  type = string
}

variable "sql_root_password" {
  type      = string
  sensitive = true
}

variable "db_names" {
  type        = list(string)
  description = "all ea_<product> DBs"
}

variable "sa_email" {
  type        = string
  description = "Cloud SQL client SA"
}

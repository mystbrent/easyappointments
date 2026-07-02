variable "project_id" {
  type = string
}

variable "region" {
  type = string
}

variable "product" {
  type        = string
  description = "the slug, e.g. product-a"
}

variable "domain" {
  type = string
}

variable "cors_origins" {
  type = string
}

variable "frame_ancestors" {
  type = string
}

variable "google_sync" {
  type    = bool
  default = false
}

variable "google_client_id" {
  type    = string
  default = ""
}

variable "google_client_secret" {
  type      = string
  default   = ""
  sensitive = true
}

variable "min_instances" {
  type    = number
  default = 1
}

variable "max_instances" {
  type    = number
  default = 10
}

variable "image" {
  type = string
}

variable "sql_instance" {
  type = string
}

variable "sql_connection_name" {
  type = string
}

variable "vpc_connector" {
  type = string
}

variable "puller_sa_email" {
  type = string
}

variable "shared_secret_ids" {
  type        = map(string)
  description = "from secrets module"
}

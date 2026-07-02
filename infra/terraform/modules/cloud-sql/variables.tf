variable "project_id" {
  type = string
}

variable "region" {
  type = string
}

variable "tier" {
  type    = string
  default = "db-n1-standard-1"
}

variable "disk_gb" {
  type    = number
  default = 100
}

variable "vpc_self_link" {
  type = string
}

variable "root_password" {
  type      = string
  sensitive = true
}

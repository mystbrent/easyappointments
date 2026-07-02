variable "project_id" {
  type = string
}

variable "smtp_host" {
  type = string
}

variable "smtp_port" {
  type = number
}

variable "smtp_user" {
  type = string
}

variable "smtp_pass" {
  type      = string
  sensitive = true
}

variable "smtp_crypto" {
  type = string
}

variable "smtp_from_address" {
  type = string
}

variable "smtp_from_name" {
  type = string
}

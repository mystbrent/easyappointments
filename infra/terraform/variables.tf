variable "project_id" {
  type        = string
  description = "GCP project ID hosting the EasyAppointments stack."
}

variable "region" {
  type        = string
  description = "Single region for Cloud Run + Cloud SQL."
  default     = "us-central1"
}

variable "domain" {
  type        = string
  description = "Root booking domain, e.g. bookings.example.com. Subdomains are <product>.<domain>."
}

variable "image" {
  type        = string
  description = "Fully-qualified Artifact Registry image reference for the EA production image."
  default     = "" # computed in main.tf from artifact-registry module if empty
}

variable "sql_tier" {
  type        = string
  description = "Cloud SQL machine tier."
  default     = "db-n1-standard-1"
}

variable "sql_disk_gb" {
  type        = number
  description = "Cloud SQL disk size in GB (autoscales above this)."
  default     = 100
}

variable "products" {
  type = map(object({
    cors_origins         = string
    frame_ancestors      = string
    google_sync          = optional(bool, false)
    google_client_id     = optional(string, "")
    google_client_secret = optional(string, "")
    min_instances        = optional(number, 1)
    max_instances        = optional(number, 10)
  }))
  description = "Map of product slug -> instance config. Each key fans out a full instance."
}

variable "smtp_host" {
  type    = string
  default = "smtp.resend.com"
}

variable "smtp_port" {
  type    = number
  default = 587
}

variable "smtp_user" {
  type    = string
  default = "resend"
}

variable "smtp_pass" {
  type      = string
  sensitive = true
}

variable "smtp_crypto" {
  type    = string
  default = "tls"
}

variable "smtp_from_address" {
  type    = string
  default = "bookings@example.com"
}

variable "smtp_from_name" {
  type    = string
  default = "Bookings"
}

variable "project_id" { type = string }
variable "domain" { type = string }
variable "lb_ip" { type = string }
variable "subdomains" { type = list(string) } # e.g. ["product-a", "product-b"]

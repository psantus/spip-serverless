variable "aws_region" {
  type = string
}

variable "env" {
  type = string
}

variable "commit_id" {
  description = "Git SHA / tag of the SPIP image to deploy (must exist in ECR)."
  type        = string
  default     = "latest"
}

# ── Remote state of the spip-static stack (per environment/account) ──────────
variable "static_state_region" {
  type = string
}

variable "static_state_bucket" {
  type = string
}

variable "static_state_key" {
  type    = string
  default = "spip-static/terraform.tfstate"
}

# ── Optional custom domain (leave empty to use the CloudFront default domain) ─
variable "domain_name" {
  description = "Custom domain for the site, e.g. cms.example.com. Empty = *.cloudfront.net."
  type        = string
  default     = ""
}

variable "hosted_zone_name" {
  description = "Route53 hosted zone that owns domain_name, e.g. example.com."
  type        = string
  default     = ""
}

# ── SPIP configuration ───────────────────────────────────────────────────────
variable "spip_table_prefix" {
  description = "SPIP SQL table prefix (SPIP default is 'spip')."
  type        = string
  default     = "spip"
}

# When true, the public SPIP site (skeletons) returns a 404 'off' page.
# The admin (/ecrire) and login pages stay available. Default false (public on).
variable "spip_public_disabled" {
  type    = bool
  default = false
}

# Optional transactional email via SES (SPIP mail() does not work on Lambda/Bref).
# Leave empty to disable; set to a verified From address to enable.
variable "ses_from_email" {
  type    = string
  default = ""
}

# ── Tags ───────────────────────────────────────────────────────────────────
variable "tag_repo" {
  type    = string
  default = "spip-serverless"
}

variable "tag_costcenter" {
  type    = string
  default = "spip-serverless"
}

variable "tag_stackname" {
  type    = string
  default = "spip-app"
}

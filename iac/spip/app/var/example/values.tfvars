# Copy this folder to var/<env>/ (e.g. var/prod/) and adjust per environment.
aws_region = "eu-west-3"
env        = "test"

# Where the spip-static stack stores its remote state (same account/region).
static_state_region = "eu-west-3"
static_state_bucket = "CHANGE_ME-terraform-state"
static_state_key    = "spip-static/terraform.tfstate"

# Optional custom domain. Leave commented to use the default *.cloudfront.net domain.
# domain_name      = "cms.example.com"
# hosted_zone_name = "example.com"

# Optional transactional email via SES (verified From address required).
# ses_from_email = "SPIP <noreply@example.com>"

# Turn the public site off (admin/login stay available):
# spip_public_disabled = true

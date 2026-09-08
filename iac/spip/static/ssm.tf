# Encrypted SPIP secret keys (cles.php contents). Created empty; the SPIP Lambda
# writes the generated keys back here on first boot so they survive cold starts.
resource "aws_ssm_parameter" "spip_cles" {
  name  = "/spip-serverless/${var.env}/spip/cles"
  type  = "SecureString"
  value = "CHANGE_ME_AFTER_CREATION"

  lifecycle {
    ignore_changes = [value]
  }
}

data "aws_caller_identity" "current" {}

locals {
  app_name = "spip-serverless-${var.env}"
}

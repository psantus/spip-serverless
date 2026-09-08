data "aws_caller_identity" "current" {}

data "terraform_remote_state" "static" {
  backend = "s3"
  config = {
    region = var.static_state_region
    bucket = var.static_state_bucket
    key    = var.static_state_key
  }
}

data "aws_ecr_image" "spip" {
  repository_name = data.terraform_remote_state.static.outputs.ecr_repository_name
  image_tag       = var.commit_id
}

locals {
  app_name   = "spip-serverless-${var.env}"
  account_id = data.aws_caller_identity.current.account_id
  region     = var.aws_region

  # Constant (not derived from the stage resource) so CloudFront's origin_path can use
  # it without depending on the stage → deployment → integration → lambda chain, which
  # would form a cycle with the Lambda env var CF_DISTRIBUTION_ID.
  stage_name = "live"

  dsql_endpoint      = data.terraform_remote_state.static.outputs.dsql_endpoint
  dsql_arn           = data.terraform_remote_state.static.outputs.dsql_arn
  s3_bucket          = data.terraform_remote_state.static.outputs.s3_assets_bucket
  s3_bucket_arn      = data.terraform_remote_state.static.outputs.s3_assets_bucket_arn
  s3_bucket_domain   = data.terraform_remote_state.static.outputs.s3_assets_bucket_regional_domain_name
  sessions_table     = data.terraform_remote_state.static.outputs.dynamodb_sessions_table
  sessions_table_arn = data.terraform_remote_state.static.outputs.dynamodb_sessions_table_arn
  spip_cles_ssm      = data.terraform_remote_state.static.outputs.spip_cles_ssm_name

  env_vars = {
    BREF_RUNTIME       = "fpm"
    SPIP_DSQL_CLUSTER  = local.dsql_endpoint
    SPIP_TABLE_PREFIX  = var.spip_table_prefix
    SPIP_SESSION_TABLE = local.sessions_table
    SPIP_CLES          = "bref-ssm:${local.spip_cles_ssm}"
    SPIP_CLES_SSM_NAME = local.spip_cles_ssm
    SPIP_ENV           = var.env
    S3_BUCKET          = local.s3_bucket
    S3_REGION          = local.region

    LOG_LEVEL  = "WARNING"
    LOG_FORMAT = "json"

    OTEL_SERVICE_NAME                   = local.app_name
    OTEL_EXPORTER_OTLP_ENDPOINT         = "http://localhost:4318"
    OTEL_EXPORTER_OTLP_PROTOCOL         = "http/protobuf"
    OTEL_TRACES_EXPORTER                = "otlp"
    OPENTELEMETRY_COLLECTOR_CONFIG_FILE = "/var/task/collector-config.yaml"

    CF_DISTRIBUTION_ID = aws_cloudfront_distribution.spip.id
    APP_NAME           = local.app_name

    # When "1", the public SPIP site (skeletons) returns a 404 "off" page.
    # API/admin paths stay available.
    SPIP_PUBLIC_DISABLED = var.spip_public_disabled ? "1" : "0"

    # Optional transactional email via SES.
    SES_REGION = var.aws_region
    SES_FROM   = var.ses_from_email
  }
}

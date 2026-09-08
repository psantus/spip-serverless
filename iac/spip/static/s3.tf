# S3 bucket holding SPIP static assets (skeletons, plugin JS/CSS, uploaded media).
# Served read-only through CloudFront (see the app stack).
resource "aws_s3_bucket" "assets" {
  # S3 bucket names are globally unique, so suffix the account id to avoid collisions
  # when the same env name is deployed to different accounts.
  bucket        = "${local.app_name}-assets-${data.aws_caller_identity.current.account_id}"
  force_destroy = var.env != "prod"

  # Opt real-content environments into a tag-based AWS Backup plan (define the plan
  # separately / at the org level). Disposable envs (dev/test) are not backed up.
  tags = contains(["prep", "prod"], var.env) ? { backup = "daily" } : {}
}

resource "aws_s3_bucket_versioning" "assets" {
  bucket = aws_s3_bucket.assets.id
  versioning_configuration { status = "Enabled" }
}

resource "aws_s3_bucket_public_access_block" "assets" {
  bucket                  = aws_s3_bucket.assets.id
  block_public_acls       = true
  block_public_policy     = true
  ignore_public_acls      = true
  restrict_public_buckets = true
}

resource "aws_s3_bucket_cors_configuration" "assets" {
  bucket = aws_s3_bucket.assets.id

  cors_rule {
    allowed_headers = ["*"]
    allowed_methods = ["PUT", "GET", "HEAD"]
    allowed_origins = ["*"]
    max_age_seconds = 3600
  }
}

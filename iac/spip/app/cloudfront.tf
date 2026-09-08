# ── CloudFront in front of API Gateway + the S3 assets bucket ────────────────
# Default behavior → SPIP (via API Gateway). Static asset paths → S3 (long cache,
# content-addressed). Works with or without a custom domain.

resource "aws_cloudfront_origin_access_control" "s3" {
  name                              = "${local.app_name}-s3"
  origin_access_control_origin_type = "s3"
  signing_behavior                  = "always"
  signing_protocol                  = "sigv4"
}

resource "aws_cloudfront_response_headers_policy" "cors" {
  name = "${local.app_name}-cors"
  cors_config {
    access_control_allow_origins { items = ["*"] }
    access_control_allow_methods { items = ["GET", "HEAD"] }
    access_control_allow_headers { items = ["*"] }
    access_control_allow_credentials = false
    access_control_max_age_sec       = 86400
    origin_override                  = true
  }
  remove_headers_config {
    items { header = "composed-by" }
    items { header = "x-powered-by" }
    items { header = "x-spip-cache" }
  }
}

resource "aws_cloudfront_distribution" "spip" {
  enabled     = true
  price_class = "PriceClass_100"

  # SPIP via API Gateway (regional execute-api endpoint + stage path).
  origin {
    domain_name = "${aws_api_gateway_rest_api.spip.id}.execute-api.${var.aws_region}.amazonaws.com"
    origin_id   = "apigw"
    origin_path = "/${local.stage_name}"
    custom_origin_config {
      http_port              = 80
      https_port             = 443
      origin_protocol_policy = "https-only"
      origin_ssl_protocols   = ["TLSv1.2"]
    }
  }

  # Static assets synced to S3.
  origin {
    domain_name              = local.s3_bucket_domain
    origin_id                = "s3-assets"
    origin_access_control_id = aws_cloudfront_origin_access_control.s3.id
  }

  # Static assets → S3 (long cache; SPIP writes content-addressed filenames).
  dynamic "ordered_cache_behavior" {
    for_each = ["/IMG/*", "/plugins-dist/*", "/plugins/*", "/prive/*", "/squelettes-dist/*", "/local/*"]
    content {
      path_pattern     = ordered_cache_behavior.value
      allowed_methods  = ["GET", "HEAD"]
      cached_methods   = ["GET", "HEAD"]
      target_origin_id = "s3-assets"
      forwarded_values {
        query_string = false
        cookies { forward = "none" }
      }
      response_headers_policy_id = aws_cloudfront_response_headers_policy.cors.id
      viewer_protocol_policy     = "redirect-to-https"
      compress                   = true
      min_ttl                    = 0
      default_ttl                = 86400
      max_ttl                    = 31536000
    }
  }

  # Everything else → SPIP (no CDN cache; SPIP sets its own Cache-Control).
  default_cache_behavior {
    allowed_methods          = ["DELETE", "GET", "HEAD", "OPTIONS", "PATCH", "POST", "PUT"]
    cached_methods           = ["GET", "HEAD"]
    target_origin_id         = "apigw"
    cache_policy_id          = "4135ea2d-6df8-44a3-9df3-4b5a84be39ad" # Managed-CachingDisabled
    origin_request_policy_id = "216adef6-5c7f-47e4-b989-5492eafa07d3" # Managed-AllViewer
    viewer_protocol_policy   = "redirect-to-https"
  }

  custom_error_response {
    error_code            = 502
    error_caching_min_ttl = 0
  }
  custom_error_response {
    error_code            = 503
    error_caching_min_ttl = 0
  }
  custom_error_response {
    error_code            = 504
    error_caching_min_ttl = 0
  }

  restrictions {
    geo_restriction { restriction_type = "none" }
  }

  viewer_certificate {
    cloudfront_default_certificate = var.domain_name == ""
    acm_certificate_arn            = var.domain_name != "" ? module.acm_cloudfront[0].acm_certificate_arn : null
    ssl_support_method             = var.domain_name != "" ? "sni-only" : null
    minimum_protocol_version       = "TLSv1.2_2021"
  }

  aliases = var.domain_name != "" ? [var.domain_name] : []
}

# S3 bucket policy: allow only this CloudFront distribution (via OAC) to read.
resource "aws_s3_bucket_policy" "assets_cloudfront" {
  bucket = local.s3_bucket
  policy = jsonencode({
    Version = "2012-10-17"
    Statement = [{
      Effect    = "Allow"
      Principal = { Service = "cloudfront.amazonaws.com" }
      Action    = "s3:GetObject"
      Resource  = "${local.s3_bucket_arn}/*"
      Condition = {
        StringEquals = { "AWS:SourceArn" = aws_cloudfront_distribution.spip.arn }
      }
    }]
  })
}

# ── Optional custom domain ───────────────────────────────────────────────────
# CloudFront viewer certs MUST live in us-east-1, hence the aliased provider.
module "acm_cloudfront" {
  count   = var.domain_name != "" ? 1 : 0
  source  = "terraform-aws-modules/acm/aws"
  version = "~> 5.0"

  providers = {
    aws = aws.us_east_1
  }

  domain_name         = var.domain_name
  zone_id             = data.aws_route53_zone.zone[0].id
  validation_method   = "DNS"
  wait_for_validation = true
}

data "aws_route53_zone" "zone" {
  count = var.domain_name != "" ? 1 : 0
  name  = var.hosted_zone_name
}

resource "aws_route53_record" "spip" {
  count   = var.domain_name != "" ? 1 : 0
  zone_id = data.aws_route53_zone.zone[0].id
  name    = var.domain_name
  type    = "A"
  alias {
    name                   = aws_cloudfront_distribution.spip.domain_name
    zone_id                = aws_cloudfront_distribution.spip.hosted_zone_id
    evaluate_target_health = false
  }
}

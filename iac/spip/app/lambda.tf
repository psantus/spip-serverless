resource "aws_iam_role" "lambda_role" {
  name = "${local.app_name}-lambda-role"
  assume_role_policy = jsonencode({
    Version = "2012-10-17"
    Statement = [{
      Action    = "sts:AssumeRole"
      Effect    = "Allow"
      Principal = { Service = "lambda.amazonaws.com" }
    }]
  })
}

resource "aws_iam_role_policy_attachment" "lambda_basic" {
  role       = aws_iam_role.lambda_role.name
  policy_arn = "arn:aws:iam::aws:policy/service-role/AWSLambdaBasicExecutionRole"
}

resource "aws_iam_role_policy_attachment" "xray" {
  role       = aws_iam_role.lambda_role.name
  policy_arn = "arn:aws:iam::aws:policy/AWSXRayDaemonWriteAccess"
}

# IAM auth to the Aurora DSQL cluster (no stored DB password).
resource "aws_iam_role_policy" "dsql" {
  name = "${local.app_name}-dsql"
  role = aws_iam_role.lambda_role.id
  policy = jsonencode({
    Version = "2012-10-17"
    Statement = [{
      Effect   = "Allow"
      Action   = ["dsql:DbConnectAdmin"]
      Resource = local.dsql_arn
    }]
  })
}

# DynamoDB session store.
resource "aws_iam_role_policy" "dynamodb" {
  name = "${local.app_name}-dynamodb"
  role = aws_iam_role.lambda_role.id
  policy = jsonencode({
    Version = "2012-10-17"
    Statement = [{
      Effect   = "Allow"
      Action   = ["dynamodb:GetItem", "dynamodb:PutItem", "dynamodb:UpdateItem", "dynamodb:DeleteItem", "dynamodb:Scan"]
      Resource = local.sessions_table_arn
    }]
  })
}

# S3 assets bucket (read + write; SPIP serves and stores media here).
resource "aws_iam_role_policy" "s3" {
  name = "${local.app_name}-s3"
  role = aws_iam_role.lambda_role.id
  policy = jsonencode({
    Version = "2012-10-17"
    Statement = [{
      Effect   = "Allow"
      Action   = ["s3:GetObject", "s3:PutObject", "s3:DeleteObject", "s3:ListBucket"]
      Resource = [local.s3_bucket_arn, "${local.s3_bucket_arn}/*"]
    }]
  })
}

# Read/write the encrypted SPIP keys (cles.php) in SSM.
resource "aws_iam_role_policy" "ssm" {
  name = "${local.app_name}-ssm"
  role = aws_iam_role.lambda_role.id
  policy = jsonencode({
    Version = "2012-10-17"
    Statement = [{
      Effect   = "Allow"
      Action   = ["ssm:GetParameter", "ssm:GetParameters", "ssm:PutParameter"]
      Resource = "arn:aws:ssm:${local.region}:${local.account_id}:parameter/spip-serverless/${var.env}/*"
    }]
  })
}

# Let SPIP invalidate its own CloudFront paths after content edits.
resource "aws_iam_role_policy" "cloudfront_invalidate" {
  name = "${local.app_name}-cf-invalidate"
  role = aws_iam_role.lambda_role.id
  policy = jsonencode({
    Version = "2012-10-17"
    Statement = [{
      Effect   = "Allow"
      Action   = ["cloudfront:CreateInvalidation"]
      Resource = aws_cloudfront_distribution.spip.arn
    }]
  })
}

# Optional: transactional email via SES. Only attached when ses_from_email is set.
resource "aws_iam_role_policy" "ses_send" {
  count = var.ses_from_email != "" ? 1 : 0
  name  = "${local.app_name}-ses-send"
  role  = aws_iam_role.lambda_role.id
  policy = jsonencode({
    Version = "2012-10-17"
    Statement = [{
      Effect   = "Allow"
      Action   = ["ses:SendEmail", "ses:SendRawEmail"]
      Resource = "arn:aws:ses:${var.aws_region}:${local.account_id}:identity/*"
    }]
  })
}

resource "aws_cloudwatch_log_group" "lambda" {
  name              = "/aws/lambda/${local.app_name}-web"
  retention_in_days = 30
}

resource "aws_lambda_function" "spip" {
  function_name = "${local.app_name}-web"
  role          = aws_iam_role.lambda_role.arn
  timeout       = 30
  memory_size   = 1769 # exactly 1 vCPU
  package_type  = "Image"
  image_uri     = data.aws_ecr_image.spip.image_uri
  architectures = ["arm64"]
  publish       = true

  tracing_config {
    mode = "Active"
  }

  image_config {
    command = ["router.php"]
  }

  environment {
    variables = local.env_vars
  }
}

resource "aws_lambda_alias" "spip_live" {
  name             = "live"
  function_name    = aws_lambda_function.spip.function_name
  function_version = aws_lambda_function.spip.version
}

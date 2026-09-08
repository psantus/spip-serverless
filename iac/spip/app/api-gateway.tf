# ── REST API: proxy everything to the SPIP Lambda, X-Ray traced ──────────────
# SPIP itself (router.php) decides what each path does (/ecrire admin, public
# skeletons, static fallbacks). Auth is SPIP's own; the gateway is a transparent
# proxy. Add authorizers/authenticated routes in your own stack if you need them.

resource "aws_api_gateway_rest_api" "spip" {
  name = local.app_name

  endpoint_configuration {
    types = ["REGIONAL"]
  }
}

resource "aws_api_gateway_resource" "proxy" {
  rest_api_id = aws_api_gateway_rest_api.spip.id
  parent_id   = aws_api_gateway_rest_api.spip.root_resource_id
  path_part   = "{proxy+}"
}

resource "aws_api_gateway_method" "proxy" {
  rest_api_id   = aws_api_gateway_rest_api.spip.id
  resource_id   = aws_api_gateway_resource.proxy.id
  http_method   = "ANY"
  authorization = "NONE"
}

resource "aws_api_gateway_method" "root" {
  rest_api_id   = aws_api_gateway_rest_api.spip.id
  resource_id   = aws_api_gateway_rest_api.spip.root_resource_id
  http_method   = "ANY"
  authorization = "NONE"
}

resource "aws_api_gateway_integration" "proxy" {
  rest_api_id             = aws_api_gateway_rest_api.spip.id
  resource_id             = aws_api_gateway_resource.proxy.id
  http_method             = aws_api_gateway_method.proxy.http_method
  integration_http_method = "POST"
  type                    = "AWS_PROXY"
  uri                     = aws_lambda_alias.spip_live.invoke_arn
}

resource "aws_api_gateway_integration" "root" {
  rest_api_id             = aws_api_gateway_rest_api.spip.id
  resource_id             = aws_api_gateway_rest_api.spip.root_resource_id
  http_method             = aws_api_gateway_method.root.http_method
  integration_http_method = "POST"
  type                    = "AWS_PROXY"
  uri                     = aws_lambda_alias.spip_live.invoke_arn
}

resource "aws_api_gateway_deployment" "spip" {
  rest_api_id = aws_api_gateway_rest_api.spip.id

  triggers = {
    redeployment = timestamp()
  }

  lifecycle {
    create_before_destroy = true
  }

  depends_on = [
    aws_api_gateway_integration.proxy,
    aws_api_gateway_integration.root,
  ]
}

resource "aws_cloudwatch_log_group" "apigw_access" {
  name              = "/aws/apigateway/${local.app_name}"
  retention_in_days = 30
}

resource "aws_api_gateway_stage" "spip" {
  rest_api_id   = aws_api_gateway_rest_api.spip.id
  deployment_id = aws_api_gateway_deployment.spip.id
  stage_name    = "live"

  xray_tracing_enabled = true

  access_log_settings {
    destination_arn = aws_cloudwatch_log_group.apigw_access.arn
    format          = "$context.requestId $context.identity.sourceIp $context.requestTime $context.httpMethod $context.path $context.status $context.responseLength"
  }
}

resource "aws_lambda_permission" "apigw" {
  statement_id  = "AllowAPIGateway"
  action        = "lambda:InvokeFunction"
  function_name = aws_lambda_function.spip.function_name
  qualifier     = aws_lambda_alias.spip_live.name
  principal     = "apigateway.amazonaws.com"
  source_arn    = "${aws_api_gateway_rest_api.spip.execution_arn}/*/*"
}

# ── API Gateway account-level CloudWatch role (once per account) ─────────────
resource "aws_iam_role" "apigw_cloudwatch" {
  name = "${local.app_name}-apigw-cloudwatch"
  assume_role_policy = jsonencode({
    Version = "2012-10-17"
    Statement = [{
      Effect    = "Allow"
      Principal = { Service = "apigateway.amazonaws.com" }
      Action    = "sts:AssumeRole"
    }]
  })
}

resource "aws_iam_role_policy_attachment" "apigw_cloudwatch" {
  role       = aws_iam_role.apigw_cloudwatch.name
  policy_arn = "arn:aws:iam::aws:policy/service-role/AmazonAPIGatewayPushToCloudWatchLogs"
}

resource "aws_api_gateway_account" "main" {
  cloudwatch_role_arn = aws_iam_role.apigw_cloudwatch.arn
}

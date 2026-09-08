output "api_endpoint" {
  value = "https://${aws_api_gateway_rest_api.spip.id}.execute-api.${local.region}.amazonaws.com/${aws_api_gateway_stage.spip.stage_name}"
}

output "cloudfront_domain" {
  value = aws_cloudfront_distribution.spip.domain_name
}

output "cloudfront_url" {
  value = "https://${var.domain_name != "" ? var.domain_name : aws_cloudfront_distribution.spip.domain_name}"
}

output "lambda_function_name" {
  value = aws_lambda_function.spip.function_name
}

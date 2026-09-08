output "ecr_repository_url" {
  value = aws_ecr_repository.spip.repository_url
}

output "ecr_repository_name" {
  value = aws_ecr_repository.spip.name
}

output "dsql_endpoint" {
  value = "${aws_dsql_cluster.spip.identifier}.dsql.${var.aws_region}.on.aws"
}

output "dsql_arn" {
  value = aws_dsql_cluster.spip.arn
}

output "s3_assets_bucket" {
  value = aws_s3_bucket.assets.id
}

output "s3_assets_bucket_arn" {
  value = aws_s3_bucket.assets.arn
}

output "s3_assets_bucket_regional_domain_name" {
  value = aws_s3_bucket.assets.bucket_regional_domain_name
}

output "dynamodb_sessions_table" {
  value = aws_dynamodb_table.sessions.name
}

output "dynamodb_sessions_table_arn" {
  value = aws_dynamodb_table.sessions.arn
}

output "spip_cles_ssm_name" {
  value = aws_ssm_parameter.spip_cles.name
}

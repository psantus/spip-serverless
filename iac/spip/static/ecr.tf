# Container image repository for the SPIP Lambda image.
# One repo per AWS account (the name is account-scoped); the same name is reused
# across environments that live in separate accounts.
resource "aws_ecr_repository" "spip" {
  name                 = "spip-serverless"
  image_tag_mutability = "MUTABLE"
  force_delete         = var.env != "prod"

  image_scanning_configuration {
    scan_on_push = true
  }
}

# Keep only the most recent images to bound storage cost.
resource "aws_ecr_lifecycle_policy" "spip" {
  repository = aws_ecr_repository.spip.name
  policy = jsonencode({
    rules = [{
      rulePriority = 1
      description  = "Expire untagged images after 14 days"
      selection = {
        tagStatus   = "untagged"
        countType   = "sinceImagePushed"
        countUnit   = "days"
        countNumber = 14
      }
      action = { type = "expire" }
    }]
  })
}

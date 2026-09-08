provider "aws" {
  region = var.aws_region

  default_tags {
    tags = {
      repo       = var.tag_repo
      costcenter = var.tag_costcenter
      stackname  = var.tag_stackname
      env        = var.env
    }
  }
}

# us-east-1 aliased provider — required for the CloudFront viewer ACM certificate,
# which MUST live in us-east-1 regardless of the regional resources' region.
provider "aws" {
  alias  = "us_east_1"
  region = "us-east-1"

  default_tags {
    tags = {
      repo       = var.tag_repo
      costcenter = var.tag_costcenter
      stackname  = var.tag_stackname
      env        = var.env
    }
  }
}

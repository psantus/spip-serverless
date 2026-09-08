provider "aws" {
  region = var.aws_region

  default_tags {
    tags = {
      repo       = "spip-serverless"
      costcenter = "spip-serverless"
      stackname  = "spip"
      env        = var.env
    }
  }
}

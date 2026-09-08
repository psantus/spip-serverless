terraform {
  required_version = ">=1.14.0"

  backend "s3" {}

  required_providers {
    aws = {
      source  = "hashicorp/aws"
      version = ">= 6.47.0"
    }
  }
}

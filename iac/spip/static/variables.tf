variable "aws_region" {
  type = string
}

variable "env" {
  type = string
}

variable "tag_repo" {
  type    = string
  default = "spip-serverless"
}

variable "tag_costcenter" {
  type    = string
  default = "spip-serverless"
}

variable "tag_stackname" {
  type    = string
  default = "spip-static"
}

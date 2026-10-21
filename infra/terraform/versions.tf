terraform {
  required_version = ">= 1.6.0"

  required_providers {
    aws = {
      source  = "hashicorp/aws"
      version = "~> 5.40"
    }
    random = {
      source  = "hashicorp/random"
      version = "~> 3.6"
    }
  }

  # -------------------------------------------------------------------------
  # REMOTE STATE BACKEND.
  # TODO(you): create the S3 bucket and DynamoDB lock table once (see
  # docs/deployment/CICD.md, "One-time bootstrap"), then uncomment and fill in.
  # Until then Terraform uses local state (fine for a first apply / demo).
  # -------------------------------------------------------------------------
  # backend "s3" {
  #   bucket         = "REPLACE_ME-waselna-tfstate"   # TODO(you): your state bucket
  #   key            = "waselna/terraform.tfstate"
  #   region         = "REPLACE_ME-us-east-1"         # TODO(you): your region
  #   dynamodb_table = "REPLACE_ME-waselna-tf-locks"  # TODO(you): your lock table
  #   encrypt        = true
  # }
}

provider "aws" {
  region = var.aws_region

  default_tags {
    tags = {
      Project     = "waselna"
      Environment = var.environment
      ManagedBy   = "terraform"
    }
  }
}
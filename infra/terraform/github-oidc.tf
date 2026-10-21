# -----------------------------------------------------------------------------
# GitHub Actions OIDC: lets the Deploy workflow assume an AWS role WITHOUT any
# long-lived access keys. GitHub presents a short-lived OIDC token; AWS trusts
# it only for this repository.
#
# TODO(you): set github_repository to "<org>/<repo>" (default below matches the
# current remote). After `terraform apply`, put the role ARN from the
# `github_actions_deploy_role_arn` output into the repo secret AWS_DEPLOY_ROLE_ARN.
# -----------------------------------------------------------------------------

variable "github_repository" {
  description = "GitHub repo allowed to assume the deploy role, as owner/name."
  type        = string
  default     = "tehseenahmed1995/Waselna" # TODO(you): confirm owner/name
}

# The GitHub OIDC provider. If your account already has one, import it instead
# of creating a duplicate (terraform import aws_iam_openid_connect_provider...).
resource "aws_iam_openid_connect_provider" "github" {
  url             = "https://token.actions.githubusercontent.com"
  client_id_list  = ["sts.amazonaws.com"]
  thumbprint_list = ["6938fd4d98bab03faadb97b34396831e3780aea1"] # GitHub's root CA thumbprint
}

data "aws_iam_policy_document" "github_assume" {
  statement {
    actions = ["sts:AssumeRoleWithWebIdentity"]
    effect  = "Allow"
    principals {
      type        = "Federated"
      identifiers = [aws_iam_openid_connect_provider.github.arn]
    }
    condition {
      test     = "StringEquals"
      variable = "token.actions.githubusercontent.com:aud"
      values   = ["sts.amazonaws.com"]
    }
    condition {
      test     = "StringLike"
      variable = "token.actions.githubusercontent.com:sub"
      values   = ["repo:${var.github_repository}:*"]
    }
  }
}

resource "aws_iam_role" "github_deploy" {
  name               = "${local.name}-gha-deploy"
  assume_role_policy = data.aws_iam_policy_document.github_assume.json
}

# Permissions the Deploy workflow needs: push to ECR, register/update ECS task
# definitions and services, run the one-off migration task, and pass the ECS
# roles to tasks.
data "aws_iam_policy_document" "github_deploy" {
  statement {
    sid       = "EcrAuth"
    actions   = ["ecr:GetAuthorizationToken"]
    resources = ["*"]
  }
  statement {
    sid = "EcrPush"
    actions = [
      "ecr:BatchCheckLayerAvailability",
      "ecr:CompleteLayerUpload",
      "ecr:InitiateLayerUpload",
      "ecr:PutImage",
      "ecr:UploadLayerPart",
      "ecr:BatchGetImage",
      "ecr:GetDownloadUrlForLayer",
    ]
    resources = [aws_ecr_repository.backend.arn, aws_ecr_repository.frontend.arn]
  }
  statement {
    sid = "EcsDeploy"
    actions = [
      "ecs:DescribeServices",
      "ecs:DescribeTaskDefinition",
      "ecs:DescribeTasks",
      "ecs:RegisterTaskDefinition",
      "ecs:UpdateService",
      "ecs:RunTask",
      "ecs:ListTasks",
    ]
    resources = ["*"]
  }
  statement {
    sid       = "PassEcsRoles"
    actions   = ["iam:PassRole"]
    resources = [aws_iam_role.ecs_execution.arn, aws_iam_role.ecs_task.arn]
  }
}

resource "aws_iam_role_policy" "github_deploy" {
  name   = "${local.name}-gha-deploy"
  role   = aws_iam_role.github_deploy.id
  policy = data.aws_iam_policy_document.github_deploy.json
}

output "github_actions_deploy_role_arn" {
  description = "Put this in the GitHub repo secret AWS_DEPLOY_ROLE_ARN."
  value       = aws_iam_role.github_deploy.arn
}
# -----------------------------------------------------------------------------
# ECR repositories for the backend and frontend images. The deploy pipeline
# builds and pushes to these.
# -----------------------------------------------------------------------------

resource "aws_ecr_repository" "backend" {
  name                 = "${var.project}/backend"
  image_tag_mutability = "MUTABLE"
  force_delete         = true # demo. TODO(you): false for production.
  image_scanning_configuration {
    scan_on_push = true
  }
}

resource "aws_ecr_repository" "frontend" {
  name                 = "${var.project}/frontend"
  image_tag_mutability = "MUTABLE"
  force_delete         = true # demo. TODO(you): false for production.
  image_scanning_configuration {
    scan_on_push = true
  }
}

# Keep only the last 10 images per repo to control storage cost.
resource "aws_ecr_lifecycle_policy" "backend" {
  repository = aws_ecr_repository.backend.name
  policy = jsonencode({
    rules = [{
      rulePriority = 1
      description  = "Keep last 10 images"
      selection    = { tagStatus = "any", countType = "imageCountMoreThan", countNumber = 10 }
      action       = { type = "expire" }
    }]
  })
}

resource "aws_ecr_lifecycle_policy" "frontend" {
  repository = aws_ecr_repository.frontend.name
  policy = jsonencode({
    rules = [{
      rulePriority = 1
      description  = "Keep last 10 images"
      selection    = { tagStatus = "any", countType = "imageCountMoreThan", countNumber = 10 }
      action       = { type = "expire" }
    }]
  })
}
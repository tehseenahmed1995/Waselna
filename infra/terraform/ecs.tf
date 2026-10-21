# -----------------------------------------------------------------------------
# ECS Fargate: one cluster running four services from two images.
#   backend image  -> http-api, queue-worker, reverb
#   frontend image -> frontend
# -----------------------------------------------------------------------------

resource "aws_ecs_cluster" "main" {
  name = "${local.name}-cluster"
  setting {
    name  = "containerInsights"
    value = "enabled"
  }
}

resource "aws_cloudwatch_log_group" "app" {
  name              = "/ecs/${local.name}"
  retention_in_days = 14
}

locals {
  # Non-secret environment shared by every backend container. Secrets (APP_KEY,
  # DB_PASSWORD) are injected separately from Secrets Manager below.
  backend_environment = [
    { name = "APP_ENV", value = var.environment },
    { name = "APP_DEBUG", value = "false" },
    { name = "APP_URL", value = var.domain_name != "" ? "https://${var.domain_name}" : "http://${aws_lb.main.dns_name}" },
    { name = "LOG_CHANNEL", value = "stderr" },
    { name = "LOG_STACK", value = "single" },
    { name = "DB_CONNECTION", value = "pgsql" },
    { name = "DB_HOST", value = aws_db_instance.main.address },
    { name = "DB_PORT", value = "5432" },
    { name = "DB_DATABASE", value = var.db_name },
    { name = "DB_USERNAME", value = var.db_username },
    { name = "REDIS_HOST", value = aws_elasticache_cluster.main.cache_nodes[0].address },
    { name = "REDIS_PORT", value = "6379" },
    { name = "REDIS_CLIENT", value = "phpredis" },
    { name = "CACHE_STORE", value = "redis" },
    { name = "QUEUE_CONNECTION", value = "redis" },
    { name = "SESSION_DRIVER", value = "redis" },
    { name = "BROADCAST_CONNECTION", value = "reverb" },
    { name = "THROTTLE_CACHE_STORE", value = "redis" },
    { name = "WEB_CLIENT_ORIGINS", value = var.domain_name != "" ? "https://${var.domain_name}" : "http://${aws_lb.main.dns_name}" },
    { name = "MAIL_MAILER", value = "log" },
    { name = "REVERB_APP_ID", value = "waselna" },
    { name = "REVERB_APP_KEY", value = "waselna-key" },
    { name = "REVERB_APP_SECRET", value = "waselna-secret" },
    { name = "REVERB_HOST", value = "0.0.0.0" },
    { name = "REVERB_PORT", value = "8080" },
    # TODO(you): to send real email, switch MAIL_MAILER to "ses" and add an SES
    # task-role policy + verified identity.
  ]

  backend_secrets = [
    { name = "APP_KEY", valueFrom = "${aws_secretsmanager_secret.app.arn}:APP_KEY::" },
    { name = "DB_PASSWORD", valueFrom = "${aws_secretsmanager_secret.app.arn}:DB_PASSWORD::" },
  ]
}

# ---- Task definition: HTTP API ---------------------------------------------
resource "aws_ecs_task_definition" "backend" {
  family                   = "${local.name}-backend"
  requires_compatibilities = ["FARGATE"]
  network_mode             = "awsvpc"
  cpu                      = var.backend_cpu
  memory                   = var.backend_memory
  execution_role_arn       = aws_iam_role.ecs_execution.arn
  task_role_arn            = aws_iam_role.ecs_task.arn

  container_definitions = jsonencode([{
    name         = "backend"
    image        = var.backend_image
    essential    = true
    command      = ["serve-http"]
    environment  = local.backend_environment
    secrets      = local.backend_secrets
    portMappings = [{ containerPort = 8000, protocol = "tcp" }]
    logConfiguration = {
      logDriver = "awslogs"
      options = {
        "awslogs-group"         = aws_cloudwatch_log_group.app.name
        "awslogs-region"        = var.aws_region
        "awslogs-stream-prefix" = "http"
      }
    }
  }])
}

# ---- Task definition: queue worker -----------------------------------------
resource "aws_ecs_task_definition" "worker" {
  family                   = "${local.name}-worker"
  requires_compatibilities = ["FARGATE"]
  network_mode             = "awsvpc"
  cpu                      = 256
  memory                   = 512
  execution_role_arn       = aws_iam_role.ecs_execution.arn
  task_role_arn            = aws_iam_role.ecs_task.arn

  container_definitions = jsonencode([{
    name        = "worker"
    image       = var.backend_image
    essential   = true
    command     = ["php", "artisan", "queue:work", "--queue=default,notifications,payments,dispatch,reports", "--tries=3", "--sleep=1"]
    environment = local.backend_environment
    secrets     = local.backend_secrets
    logConfiguration = {
      logDriver = "awslogs"
      options = {
        "awslogs-group"         = aws_cloudwatch_log_group.app.name
        "awslogs-region"        = var.aws_region
        "awslogs-stream-prefix" = "worker"
      }
    }
  }])
}

# ---- Task definition: reverb (realtime gateway) ----------------------------
resource "aws_ecs_task_definition" "reverb" {
  family                   = "${local.name}-reverb"
  requires_compatibilities = ["FARGATE"]
  network_mode             = "awsvpc"
  cpu                      = 256
  memory                   = 512
  execution_role_arn       = aws_iam_role.ecs_execution.arn
  task_role_arn            = aws_iam_role.ecs_task.arn

  container_definitions = jsonencode([{
    name         = "reverb"
    image        = var.backend_image
    essential    = true
    command      = ["php", "artisan", "reverb:start", "--host=0.0.0.0", "--port=8080"]
    environment  = local.backend_environment
    secrets      = local.backend_secrets
    portMappings = [{ containerPort = 8080, protocol = "tcp" }]
    logConfiguration = {
      logDriver = "awslogs"
      options = {
        "awslogs-group"         = aws_cloudwatch_log_group.app.name
        "awslogs-region"        = var.aws_region
        "awslogs-stream-prefix" = "reverb"
      }
    }
  }])
}

# ---- Task definition: frontend ---------------------------------------------
resource "aws_ecs_task_definition" "frontend" {
  family                   = "${local.name}-frontend"
  requires_compatibilities = ["FARGATE"]
  network_mode             = "awsvpc"
  cpu                      = 256
  memory                   = 512
  execution_role_arn       = aws_iam_role.ecs_execution.arn
  task_role_arn            = aws_iam_role.ecs_task.arn

  container_definitions = jsonencode([{
    name         = "frontend"
    image        = var.frontend_image
    essential    = true
    portMappings = [{ containerPort = 80, protocol = "tcp" }]
    logConfiguration = {
      logDriver = "awslogs"
      options = {
        "awslogs-group"         = aws_cloudwatch_log_group.app.name
        "awslogs-region"        = var.aws_region
        "awslogs-stream-prefix" = "frontend"
      }
    }
  }])
}

# ---- Services ---------------------------------------------------------------
resource "aws_ecs_service" "backend" {
  name            = "${local.name}-backend"
  cluster         = aws_ecs_cluster.main.id
  task_definition = aws_ecs_task_definition.backend.arn
  desired_count   = var.backend_desired_count
  launch_type     = "FARGATE"

  network_configuration {
    subnets         = aws_subnet.private[*].id
    security_groups = [aws_security_group.ecs.id]
  }
  load_balancer {
    target_group_arn = aws_lb_target_group.backend.arn
    container_name   = "backend"
    container_port   = 8000
  }
  depends_on = [aws_lb_listener.http]
  # The pipeline updates the image out-of-band; ignore so Terraform does not
  # fight the deploy. TODO(you): remove if you deploy images only via Terraform.
  lifecycle {
    ignore_changes = [task_definition]
  }
}

resource "aws_ecs_service" "worker" {
  name            = "${local.name}-worker"
  cluster         = aws_ecs_cluster.main.id
  task_definition = aws_ecs_task_definition.worker.arn
  desired_count   = 1
  launch_type     = "FARGATE"
  network_configuration {
    subnets         = aws_subnet.private[*].id
    security_groups = [aws_security_group.ecs.id]
  }
  lifecycle {
    ignore_changes = [task_definition]
  }
}

resource "aws_ecs_service" "reverb" {
  name            = "${local.name}-reverb"
  cluster         = aws_ecs_cluster.main.id
  task_definition = aws_ecs_task_definition.reverb.arn
  desired_count   = 1
  launch_type     = "FARGATE"
  network_configuration {
    subnets         = aws_subnet.private[*].id
    security_groups = [aws_security_group.ecs.id]
  }
  load_balancer {
    target_group_arn = aws_lb_target_group.reverb.arn
    container_name   = "reverb"
    container_port   = 8080
  }
  depends_on = [aws_lb_listener.http]
  lifecycle {
    ignore_changes = [task_definition]
  }
}

resource "aws_ecs_service" "frontend" {
  name            = "${local.name}-frontend"
  cluster         = aws_ecs_cluster.main.id
  task_definition = aws_ecs_task_definition.frontend.arn
  desired_count   = 1
  launch_type     = "FARGATE"
  network_configuration {
    subnets         = aws_subnet.private[*].id
    security_groups = [aws_security_group.ecs.id]
  }
  load_balancer {
    target_group_arn = aws_lb_target_group.frontend.arn
    container_name   = "frontend"
    container_port   = 80
  }
  depends_on = [aws_lb_listener.http]
  lifecycle {
    ignore_changes = [task_definition]
  }
}
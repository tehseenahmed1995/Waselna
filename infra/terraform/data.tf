# -----------------------------------------------------------------------------
# Stateful data stores: RDS PostgreSQL (PostGIS), ElastiCache Redis, and the
# secrets the application reads at runtime.
# -----------------------------------------------------------------------------

# ---- Generated secrets ------------------------------------------------------
resource "random_password" "db" {
  length  = 32
  special = false
}

# Laravel APP_KEY: 32 random bytes, base64-encoded, prefixed "base64:".
resource "random_bytes" "app_key" {
  length = 32
}

resource "aws_secretsmanager_secret" "app" {
  name                    = "${local.name}/app"
  description             = "Waselna application secrets (APP_KEY, DB + Redis credentials)."
  recovery_window_in_days = 0 # demo: delete immediately. TODO(you): raise for production.
}

resource "aws_secretsmanager_secret_version" "app" {
  secret_id = aws_secretsmanager_secret.app.id
  secret_string = jsonencode({
    APP_KEY     = "base64:${random_bytes.app_key.base64}"
    DB_PASSWORD = random_password.db.result
  })
}

# ---- RDS PostgreSQL ---------------------------------------------------------
resource "aws_db_subnet_group" "main" {
  name       = "${local.name}-db-subnets"
  subnet_ids = aws_subnet.private[*].id
  tags       = { Name = "${local.name}-db-subnets" }
}

# A parameter group that forces SSL and is compatible with PostGIS. PostGIS
# itself is enabled by the application migration (CREATE EXTENSION postgis).
resource "aws_db_parameter_group" "main" {
  name   = "${local.name}-pg16"
  family = "postgres16"

  parameter {
    name  = "rds.force_ssl"
    value = "0" # demo: allow non-SSL from within the VPC. TODO(you): set 1 + sslmode=require for production.
  }
}

resource "aws_db_instance" "main" {
  identifier     = "${local.name}-pg"
  engine         = "postgres"
  engine_version = var.db_engine_version
  instance_class = var.db_instance_class

  allocated_storage     = var.db_allocated_storage
  max_allocated_storage = var.db_allocated_storage * 3
  storage_type          = "gp3"
  storage_encrypted     = true

  db_name  = var.db_name
  username = var.db_username
  password = random_password.db.result

  db_subnet_group_name   = aws_db_subnet_group.main.name
  vpc_security_group_ids = [aws_security_group.data.id]
  parameter_group_name   = aws_db_parameter_group.main.name

  multi_az                = false # demo. TODO(you): true for production HA.
  publicly_accessible     = false
  skip_final_snapshot     = true  # demo. TODO(you): false + final_snapshot_identifier for production.
  deletion_protection     = false # demo. TODO(you): true for production.
  backup_retention_period = 7
  apply_immediately       = true

  tags = { Name = "${local.name}-pg" }
}

# ---- ElastiCache Redis ------------------------------------------------------
resource "aws_elasticache_subnet_group" "main" {
  name       = "${local.name}-redis-subnets"
  subnet_ids = aws_subnet.private[*].id
}

resource "aws_elasticache_cluster" "main" {
  cluster_id           = "${local.name}-redis"
  engine               = "redis"
  node_type            = var.redis_node_type
  num_cache_nodes      = 1
  engine_version       = "7.1"
  port                 = 6379
  subnet_group_name    = aws_elasticache_subnet_group.main.name
  security_group_ids   = [aws_security_group.data.id]
  parameter_group_name = "default.redis7"
  tags                 = { Name = "${local.name}-redis" }
}
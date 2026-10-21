variable "aws_region" {
  description = "AWS region to deploy into."
  type        = string
  default     = "us-east-1" # TODO(you): set your region
}

variable "environment" {
  description = "Environment name (e.g. production, staging)."
  type        = string
  default     = "production"
}

variable "project" {
  description = "Project/name prefix for all resources."
  type        = string
  default     = "waselna"
}

variable "vpc_cidr" {
  description = "CIDR block for the VPC."
  type        = string
  default     = "10.20.0.0/16"
}

variable "az_count" {
  description = "Number of Availability Zones to spread subnets across."
  type        = number
  default     = 2
}

# ---- Database ---------------------------------------------------------------
variable "db_name" {
  description = "PostgreSQL database name."
  type        = string
  default     = "waselna"
}

variable "db_username" {
  description = "PostgreSQL master username."
  type        = string
  default     = "waselna"
}

variable "db_instance_class" {
  description = "RDS instance class."
  type        = string
  default     = "db.t4g.micro" # TODO(you): size up for real production load
}

variable "db_allocated_storage" {
  description = "RDS allocated storage in GB."
  type        = number
  default     = 20
}

variable "db_engine_version" {
  description = "PostgreSQL engine version (PostGIS is enabled via the rds.force_ssl parameter group / CREATE EXTENSION in migrations)."
  type        = string
  default     = "16.4"
}

# ---- Cache ------------------------------------------------------------------
variable "redis_node_type" {
  description = "ElastiCache Redis node type."
  type        = string
  default     = "cache.t4g.micro" # TODO(you): size up for real production load
}

# ---- Containers -------------------------------------------------------------
variable "backend_image" {
  description = "Full ECR image URI (with tag) for the backend. Set by the deploy pipeline; a placeholder is fine for the first infra apply."
  type        = string
  default     = "public.ecr.aws/nginx/nginx:latest" # TODO(pipeline): overwritten by CI with the real backend image
}

variable "frontend_image" {
  description = "Full ECR image URI (with tag) for the frontend. Set by the deploy pipeline."
  type        = string
  default     = "public.ecr.aws/nginx/nginx:latest" # TODO(pipeline): overwritten by CI with the real frontend image
}

variable "backend_desired_count" {
  description = "Number of HTTP API tasks."
  type        = number
  default     = 2
}

variable "backend_cpu" {
  description = "Fargate CPU units for the backend task (1024 = 1 vCPU)."
  type        = number
  default     = 512
}

variable "backend_memory" {
  description = "Fargate memory (MB) for the backend task."
  type        = number
  default     = 1024
}

# ---- TLS / domain -----------------------------------------------------------
variable "acm_certificate_arn" {
  description = "ARN of an ACM certificate for the ALB HTTPS listener. Leave empty to serve HTTP only (demo)."
  type        = string
  default     = "" # TODO(you): paste your ACM certificate ARN to enable HTTPS
}

variable "domain_name" {
  description = "Public domain the app is served on (used only for CORS/APP_URL wiring). Leave as the ALB DNS for a demo."
  type        = string
  default     = "" # TODO(you): e.g. app.waselna.example.com
}
output "app_url" {
  description = "Public URL of the application (ALB)."
  value       = var.domain_name != "" ? "https://${var.domain_name}" : "http://${aws_lb.main.dns_name}"
}

output "alb_dns_name" {
  description = "ALB DNS name. Point your domain CNAME here."
  value       = aws_lb.main.dns_name
}

output "ecr_backend_repository_url" {
  description = "ECR repo URL for the backend image (used by the deploy pipeline)."
  value       = aws_ecr_repository.backend.repository_url
}

output "ecr_frontend_repository_url" {
  description = "ECR repo URL for the frontend image (used by the deploy pipeline)."
  value       = aws_ecr_repository.frontend.repository_url
}

output "ecs_cluster_name" {
  value = aws_ecs_cluster.main.name
}

output "backend_service_name" {
  value = aws_ecs_service.backend.name
}

output "worker_service_name" {
  value = aws_ecs_service.worker.name
}

output "reverb_service_name" {
  value = aws_ecs_service.reverb.name
}

output "frontend_service_name" {
  value = aws_ecs_service.frontend.name
}

output "backend_task_family" {
  value = aws_ecs_task_definition.backend.family
}

output "private_subnet_ids" {
  description = "Private subnets (for the one-off migration task in the pipeline)."
  value       = aws_subnet.private[*].id
}

output "ecs_security_group_id" {
  value = aws_security_group.ecs.id
}

output "rds_endpoint" {
  value = aws_db_instance.main.address
}

output "redis_endpoint" {
  value = aws_elasticache_cluster.main.cache_nodes[0].address
}
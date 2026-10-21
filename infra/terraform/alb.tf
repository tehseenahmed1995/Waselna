# -----------------------------------------------------------------------------
# Application Load Balancer: public entrypoint. Routes /api/* and /broadcasting*
# to the backend, websocket upgrades to reverb, and everything else to the
# frontend SPA.
# -----------------------------------------------------------------------------

resource "aws_lb" "main" {
  name               = "${local.name}-alb"
  load_balancer_type = "application"
  internal           = false
  security_groups    = [aws_security_group.alb.id]
  subnets            = aws_subnet.public[*].id
  tags               = { Name = "${local.name}-alb" }
}

# ---- Target groups (ECS registers tasks here via awsvpc/IP mode) ------------
resource "aws_lb_target_group" "backend" {
  name        = "${local.name}-backend-tg"
  port        = 8000
  protocol    = "HTTP"
  vpc_id      = aws_vpc.main.id
  target_type = "ip"

  health_check {
    path                = "/api/v1/health"
    healthy_threshold   = 2
    unhealthy_threshold = 5
    timeout             = 5
    interval            = 30
    matcher             = "200-299"
  }
  deregistration_delay = 15
}

resource "aws_lb_target_group" "frontend" {
  name        = "${local.name}-frontend-tg"
  port        = 80
  protocol    = "HTTP"
  vpc_id      = aws_vpc.main.id
  target_type = "ip"

  health_check {
    path                = "/healthz"
    healthy_threshold   = 2
    unhealthy_threshold = 5
    timeout             = 5
    interval            = 30
    matcher             = "200-299"
  }
  deregistration_delay = 15
}

resource "aws_lb_target_group" "reverb" {
  name        = "${local.name}-reverb-tg"
  port        = 8080
  protocol    = "HTTP"
  vpc_id      = aws_vpc.main.id
  target_type = "ip"

  health_check {
    path                = "/"
    healthy_threshold   = 2
    unhealthy_threshold = 5
    timeout             = 5
    interval            = 30
    matcher             = "200-499" # reverb answers 404/426 on /, which still proves liveness
  }
  deregistration_delay = 15
}

# ---- HTTP listener ----------------------------------------------------------
# When no ACM cert is supplied (demo), HTTP serves the app directly. When a cert
# IS supplied, HTTP redirects to HTTPS and the HTTPS listener serves the app.
resource "aws_lb_listener" "http" {
  load_balancer_arn = aws_lb.main.arn
  port              = 80
  protocol          = "HTTP"

  default_action {
    type = var.acm_certificate_arn == "" ? "forward" : "redirect"

    dynamic "redirect" {
      for_each = var.acm_certificate_arn == "" ? [] : [1]
      content {
        port        = "443"
        protocol    = "HTTPS"
        status_code = "HTTP_301"
      }
    }

    target_group_arn = var.acm_certificate_arn == "" ? aws_lb_target_group.frontend.arn : null
  }
}

# Backend + reverb routing on the HTTP listener (demo / no-TLS case).
resource "aws_lb_listener_rule" "http_api" {
  count        = var.acm_certificate_arn == "" ? 1 : 0
  listener_arn = aws_lb_listener.http.arn
  priority     = 10
  action {
    type             = "forward"
    target_group_arn = aws_lb_target_group.backend.arn
  }
  condition {
    path_pattern { values = ["/api/*", "/broadcasting/*", "/up"] }
  }
}

resource "aws_lb_listener_rule" "http_reverb" {
  count        = var.acm_certificate_arn == "" ? 1 : 0
  listener_arn = aws_lb_listener.http.arn
  priority     = 20
  action {
    type             = "forward"
    target_group_arn = aws_lb_target_group.reverb.arn
  }
  condition {
    path_pattern { values = ["/app/*", "/ws/*"] }
  }
}

# ---- HTTPS listener (only when an ACM certificate is provided) --------------
resource "aws_lb_listener" "https" {
  count             = var.acm_certificate_arn == "" ? 0 : 1
  load_balancer_arn = aws_lb.main.arn
  port              = 443
  protocol          = "HTTPS"
  ssl_policy        = "ELBSecurityPolicy-TLS13-1-2-2021-06"
  certificate_arn   = var.acm_certificate_arn

  default_action {
    type             = "forward"
    target_group_arn = aws_lb_target_group.frontend.arn
  }
}

resource "aws_lb_listener_rule" "https_api" {
  count        = var.acm_certificate_arn == "" ? 0 : 1
  listener_arn = aws_lb_listener.https[0].arn
  priority     = 10
  action {
    type             = "forward"
    target_group_arn = aws_lb_target_group.backend.arn
  }
  condition {
    path_pattern { values = ["/api/*", "/broadcasting/*", "/up"] }
  }
}

resource "aws_lb_listener_rule" "https_reverb" {
  count        = var.acm_certificate_arn == "" ? 0 : 1
  listener_arn = aws_lb_listener.https[0].arn
  priority     = 20
  action {
    type             = "forward"
    target_group_arn = aws_lb_target_group.reverb.arn
  }
  condition {
    path_pattern { values = ["/app/*", "/ws/*"] }
  }
}
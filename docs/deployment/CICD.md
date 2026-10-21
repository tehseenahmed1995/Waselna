# Waselna - CI/CD and AWS Deployment Guide

This document explains how the Waselna platform is built, tested, and deployed to
AWS, and lists every value you must supply manually. Anywhere you must plug in a
real value, it is marked **`TODO(you)`** both here and in the code.

---

## 1. What gets deployed

Waselna is one repository with two deployable artifacts:

| Artifact | Source | Runtime |
| --- | --- | --- |
| **Backend image** | `backend/` (Laravel 12 API) | ECS Fargate, 3 roles: HTTP API, queue worker, realtime (Reverb) |
| **Frontend image** | `frontend/` (React + Vite SPA) | ECS Fargate, nginx serving the static bundle |

Both are built by the pipeline, pushed to **Amazon ECR**, and run on **ECS
Fargate** behind one **Application Load Balancer**.

---

## 2. Target AWS architecture

```
                     Internet
                        |
                   (HTTPS / HTTP)
                        |
             +----------------------+
             |  Application Load    |   ACM cert (optional, TODO(you))
             |  Balancer (public)   |
             +----------+-----------+
                        |
        path routing on the ALB listener:
          /api/*, /up        -> backend  target group (:8000)
          /app/*, /ws/*      -> reverb   target group (:8080)
          everything else    -> frontend target group (:80)
                        |
        +---------------+-------------------------------+
        |               |               |               |
   +---------+    +-----------+    +----------+    +-----------+
   | backend |    |  worker   |    |  reverb  |    | frontend  |   ECS Fargate
   | (API)   |    | (queue)   |    | (ws)     |    | (nginx)   |   private subnets
   +----+----+    +-----+-----+    +-----+----+    +-----------+
        |               |                |
        +-------+-------+----------------+
                |                        |
        +-------v--------+      +---------v---------+
        | RDS PostgreSQL |      | ElastiCache Redis |   private subnets
        | (PostGIS)      |      | (cache/queue/cart)|
        +----------------+      +-------------------+

   Secrets Manager: APP_KEY, DB_PASSWORD  (injected into tasks)
   CloudWatch Logs: /ecs/waselna-production
```

**Why this shape:**

- **ECS Fargate** - no servers to manage; the same backend image runs the API,
  the queue worker, and the Reverb websocket gateway as three services, chosen
  by the container command.
- **ALB path routing** - one public endpoint. `/api/*` hits the backend, websocket
  paths hit Reverb, everything else serves the SPA. This keeps the frontend and
  API on the same origin, so no cross-origin complications in the browser.
- **RDS PostgreSQL** with PostGIS - the Zone module uses geospatial containment.
- **ElastiCache Redis** - Cache_Store, queue, cart document, and rate-limit
  counters.
- **Secrets Manager** - the only place credentials live; ECS injects them into
  tasks at launch. Nothing sensitive is in the image or in Git.
- **Private subnets** - only the ALB is public. The app, database, and cache are
  unreachable from the internet directly.

Everything is defined as code in `infra/terraform/` and is deployed by the
`Infrastructure (Terraform)` GitHub Actions workflow. The demo defaults are the
smallest usable sizes; every `TODO(you)` comment marks where to scale up or
harden for real production.

---

## 3. Pipelines (GitHub Actions)

The repo remote is GitHub, so the pipelines are GitHub Actions in
`.github/workflows/`:

| Workflow | File | Trigger | Does |
| --- | --- | --- | --- |
| **CI** | `ci.yml` | every PR and push to `main` | Backend: Pint, PHPStan, Deptrac, Pest tests (Postgres+Redis services). Frontend: tsc + build. The quality gate. |
| **Infrastructure** | `infra.yml` | PR touching `infra/terraform/**` (plan); manual dispatch (apply) | Terraform fmt/validate/plan, and apply on demand. |
| **Deploy** | `deploy.yml` | push to `main`; manual dispatch | Build + push both images to ECR, run DB migrations as a one-off ECS task, roll the ECS services, smoke-test `/api/v1/health`. |

Authentication to AWS uses **GitHub OIDC** - there are **no long-lived AWS access
keys stored in GitHub**. GitHub presents a short-lived token; AWS trusts it only
for this repository (configured in `infra/terraform/github-oidc.tf`).

---
## 4. One-time bootstrap (do this once, manually)

These steps are done once by you, with AWS admin credentials on your laptop. They
create the things the pipeline cannot create for itself (because the pipeline
needs them to exist before it can run).

### 4.1 Prerequisites
- An AWS account and the AWS CLI configured locally (`aws configure`).
- Terraform >= 1.6 locally (or use the `Infrastructure` workflow).

### 4.2 (Optional but recommended) Remote Terraform state
By default Terraform keeps state in a local file. For a team / CI, store it in S3:

```bash
# TODO(you): choose globally-unique names and your region.
aws s3api create-bucket --bucket REPLACE_ME-waselna-tfstate --region us-east-1
aws s3api put-bucket-versioning --bucket REPLACE_ME-waselna-tfstate \
  --versioning-configuration Status=Enabled
aws dynamodb create-table --table-name REPLACE_ME-waselna-tf-locks \
  --attribute-definitions AttributeName=LockID,AttributeType=S \
  --key-schema AttributeName=LockID,KeyType=HASH \
  --billing-mode PAY_PER_REQUEST --region us-east-1
```

Then uncomment and fill the `backend "s3"` block in
`infra/terraform/versions.tf` (**TODO(you)** markers are there).

### 4.3 First infrastructure apply
```bash
cd infra/terraform
cp terraform.tfvars.example terraform.tfvars   # then edit the TODO(you) values
terraform init
terraform apply
```
This creates the VPC, RDS, Redis, ECR repos, ALB, ECS cluster/services, the
secrets, and the GitHub OIDC deploy role. On the first apply the ECS services
start with placeholder public images (nginx) because the real images do not
exist in ECR yet - that is expected; the first Deploy run replaces them.

Record these outputs:
```bash
terraform output app_url                          # public URL (ALB DNS or your domain)
terraform output github_actions_deploy_role_arn   # for the GitHub secret below
terraform output ecr_backend_repository_url
terraform output ecr_frontend_repository_url
```

### 4.4 Configure GitHub repo (Settings -> Secrets and variables -> Actions)
Create these **Variables**:

| Variable | Value | Where from |
| --- | --- | --- |
| `AWS_REGION` | e.g. `us-east-1` | **TODO(you)** your region |
| `AWS_ACCOUNT_ID` | your 12-digit account id | **TODO(you)** `aws sts get-caller-identity` |
| `APP_PUBLIC_URL` | the `app_url` output | from `terraform output app_url` |

Create this **Secret**:

| Secret | Value |
| --- | --- |
| `AWS_DEPLOY_ROLE_ARN` | the `github_actions_deploy_role_arn` output |

### 4.5 First deploy
Push to `main` (or run the **Deploy** workflow manually). It builds the real
images, runs migrations, and rolls the services. To also seed demo data on the
very first deploy, set `RUN_SEED=true` in `infra/scripts/run-migrations.sh`
(there is a comment marking the spot), run once, then revert.

### 4.6 HTTPS / custom domain (optional)
For a real domain instead of the raw ALB DNS:
1. Request an ACM certificate in your region for your domain (**TODO(you)**).
2. Put its ARN in `acm_certificate_arn` and your domain in `domain_name` in
   `terraform.tfvars`, then `terraform apply`. The ALB then serves HTTPS and
   redirects HTTP to it.
3. Point your domain's DNS (CNAME / Route 53 alias) at the `alb_dns_name` output
   (**TODO(you)**).

---

## 5. Complete list of manual placeholders

Everything you must replace, in one place. All are marked `TODO(you)` in code.

| Placeholder | File | Meaning |
| --- | --- | --- |
| `aws_region` | `terraform.tfvars`, repo var `AWS_REGION` | Your AWS region |
| `AWS_ACCOUNT_ID` | repo variable | Your 12-digit account id |
| `APP_PUBLIC_URL` | repo variable | Public app URL (ALB DNS or your domain) |
| `AWS_DEPLOY_ROLE_ARN` | repo secret | From `github_actions_deploy_role_arn` output |
| S3 state bucket + lock table | `versions.tf` | Remote Terraform state (optional) |
| `github_repository` | `github-oidc.tf` | `owner/name` of this repo (default already set) |
| `acm_certificate_arn` | `terraform.tfvars` | ACM cert ARN to enable HTTPS (optional) |
| `domain_name` | `terraform.tfvars` | Your custom domain (optional) |
| DNS record to `alb_dns_name` | your DNS provider | Point your domain at the ALB |
| Sizing: `db_instance_class`, `redis_node_type`, `backend_desired_count` | `terraform.tfvars` | Scale up for production |
| Hardening toggles (multi_az, deletion_protection, skip_final_snapshot, rds.force_ssl, NAT-per-AZ, secret recovery window) | `data.tf`, `network.tf` | Marked `TODO(you)`; flip for real production |
| `MAIL_MAILER` / SES | `ecs.tf` | Switch from `log` to real email if needed |

No AWS credentials or secrets are ever committed to the repo. The only secret in
GitHub is the OIDC role ARN, which is not itself a credential.

---

## 6. How a change reaches production (day-to-day)

1. Open a PR -> **CI** runs (tests, lint, static analysis). Must pass.
2. Merge to `main` -> **Deploy** runs automatically:
   build images -> push to ECR -> migrate DB (one-off task) -> roll ECS services
   -> wait for stable -> smoke-test health.
3. Infra changes: edit `infra/terraform/**` in a PR (**Infrastructure** plan runs),
   then run the **Infrastructure** workflow with `apply` after review.

Rollback: re-run **Deploy** from an earlier commit, or
`aws ecs update-service ... --task-definition <previous-revision>`.

---

## 7. Local development (unchanged)

Local dev still uses `docker-compose.yml` (see `docs/architecture/local.md`). The
production Dockerfiles (`docker/backend-prod/`, `docker/frontend-prod/`) are
separate from the dev image and are used only by the pipeline.
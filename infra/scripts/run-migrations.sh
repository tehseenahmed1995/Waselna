#!/usr/bin/env bash
# Run database migrations once, as a one-off ECS task, before rolling the
# services. Uses the backend task definition but overrides the command and sets
# RUN_MIGRATIONS=true so the entrypoint migrates and exits.
#
# Usage: run-migrations.sh <cluster> <backend-service> <image-uri>
set -euo pipefail

CLUSTER="$1"
BACKEND_SERVICE="$2"
IMAGE="$3"

echo "Running migrations as a one-off task on $CLUSTER"

# Reuse the backend service's networking (subnets + security group) and task def.
TASK_DEF_ARN=$(aws ecs describe-services --cluster "$CLUSTER" --services "$BACKEND_SERVICE" \
  --query "services[0].taskDefinition" --output text)
NETWORK=$(aws ecs describe-services --cluster "$CLUSTER" --services "$BACKEND_SERVICE" \
  --query "services[0].networkConfiguration" --output json)

# Register a migration revision with RUN_MIGRATIONS=true and the new image.
aws ecs describe-task-definition --task-definition "$TASK_DEF_ARN" \
  --query "taskDefinition" --output json > /tmp/migratedef.json

MIG_DEF=$(python3 - "$IMAGE" < /tmp/migratedef.json <<'PY'
import json, sys
image = sys.argv[1]
td = json.load(sys.stdin)
c = td["containerDefinitions"][0]
c["image"] = image
env = {e["name"]: e for e in c.get("environment", [])}
env["RUN_MIGRATIONS"] = {"name": "RUN_MIGRATIONS", "value": "true"}
# Seed demo data on the very first deploy only; set RUN_SEED=true here if wanted.
c["environment"] = list(env.values())
for key in ["taskDefinitionArn","revision","status","requiresAttributes",
            "compatibilities","registeredAt","registeredBy"]:
    td.pop(key, None)
print(json.dumps(td))
PY
)

MIG_ARN=$(echo "$MIG_DEF" | aws ecs register-task-definition \
  --cli-input-json file:///dev/stdin \
  --query "taskDefinition.taskDefinitionArn" --output text)

TASK_ARN=$(aws ecs run-task --cluster "$CLUSTER" \
  --task-definition "$MIG_ARN" --launch-type FARGATE \
  --network-configuration "$NETWORK" \
  --query "tasks[0].taskArn" --output text)

echo "Migration task: $TASK_ARN — waiting for completion..."
aws ecs wait tasks-stopped --cluster "$CLUSTER" --tasks "$TASK_ARN"

EXIT_CODE=$(aws ecs describe-tasks --cluster "$CLUSTER" --tasks "$TASK_ARN" \
  --query "tasks[0].containers[0].exitCode" --output text)
echo "Migration task exit code: $EXIT_CODE"
test "$EXIT_CODE" = "0"
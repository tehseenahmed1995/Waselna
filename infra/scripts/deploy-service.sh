#!/usr/bin/env bash
# Register a new ECS task-definition revision for a service with an updated
# container image, then roll the service onto it.
#
# Usage: deploy-service.sh <cluster> <service> <container-name> <image-uri>
#
# It fetches the service's current task definition, swaps the named container's
# image for the new one, registers the result as a new revision, and updates the
# service. All other task-definition fields (env, secrets, cpu, memory, roles)
# are preserved exactly as Terraform created them.
set -euo pipefail

CLUSTER="$1"
SERVICE="$2"
CONTAINER="$3"
IMAGE="$4"

echo "Deploying $SERVICE ($CONTAINER -> $IMAGE)"

# The task-definition family matches the service name in our Terraform.
TASK_DEF_ARN=$(aws ecs describe-services \
  --cluster "$CLUSTER" --services "$SERVICE" \
  --query "services[0].taskDefinition" --output text)

aws ecs describe-task-definition --task-definition "$TASK_DEF_ARN" \
  --query "taskDefinition" --output json > /tmp/taskdef.json

# Produce a clean registration payload: swap the image, drop read-only fields.
NEW_TASK_DEF=$(python3 - "$CONTAINER" "$IMAGE" < /tmp/taskdef.json <<'PY'
import json, sys
container_name, image = sys.argv[1], sys.argv[2]
td = json.load(sys.stdin)
for c in td["containerDefinitions"]:
    if c["name"] == container_name:
        c["image"] = image
for key in ["taskDefinitionArn","revision","status","requiresAttributes",
            "compatibilities","registeredAt","registeredBy"]:
    td.pop(key, None)
print(json.dumps(td))
PY
)

NEW_ARN=$(echo "$NEW_TASK_DEF" | aws ecs register-task-definition \
  --cli-input-json file:///dev/stdin \
  --query "taskDefinition.taskDefinitionArn" --output text)

echo "Registered $NEW_ARN"

aws ecs update-service --cluster "$CLUSTER" --service "$SERVICE" \
  --task-definition "$NEW_ARN" --force-new-deployment >/dev/null

echo "Service $SERVICE updated."
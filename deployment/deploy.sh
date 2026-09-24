#!/bin/bash

set -e

ENVIRONMENT=$1

if [ -z "$ENVIRONMENT" ]; then
    echo "Usage: ./deployment/deploy.sh <integration|staging|production>"
    exit 1
fi

PROJECT_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
MAGENTO_DIR="$PROJECT_ROOT/$ENVIRONMENT/src"

echo "======================================"
echo "Starting deployment: $ENVIRONMENT"
echo "Magento directory: $MAGENTO_DIR"
echo "======================================"

cd "$MAGENTO_DIR"

docker compose exec -T phpfpm php bin/magento maintenance:enable

trap 'docker compose exec -T phpfpm php bin/magento maintenance:disable' EXIT

docker compose exec -T phpfpm php bin/magento setup:upgrade

docker compose exec -T phpfpm php bin/magento cache:flush

docker compose exec -T phpfpm php bin/magento indexer:reindex

docker compose exec -T phpfpm php bin/magento maintenance:disable

trap - EXIT

echo "=== DEPLOY COMPLETE ==="
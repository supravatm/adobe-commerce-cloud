#!/bin/bash

set -e

echo "=== BUILD START ==="

ENVIRONMENT=$1

if [ -z "$ENVIRONMENT" ]; then
    echo "Usage: ./deployment/release.sh <integration|staging|production>"
    exit 1
fi

echo "======================================"
echo "Starting deployment: $ENVIRONMENT"
echo "======================================"

PROJECT_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
MAGENTO_DIR="$PROJECT_ROOT/$ENVIRONMENT/src"

echo "Magento directory:"
echo "$MAGENTO_DIR"

cd "$MAGENTO_DIR"

docker compose exec -T phpfpm composer install --no-interaction

docker compose exec -T phpfpm php bin/magento setup:di:compile

echo "=== BUILD COMPLETE ==="


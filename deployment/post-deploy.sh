#!/bin/bash

set -e

ENVIRONMENT=$1

if [ -z "$ENVIRONMENT" ]; then
    echo "Usage: ./deployment/post-deploy.sh <integration|staging|production>"
    exit 1
fi

PROJECT_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
MAGENTO_DIR="$PROJECT_ROOT/$ENVIRONMENT/src"

echo "======================================"
echo "Starting post-deployment checks: $ENVIRONMENT"
echo "Magento directory: $MAGENTO_DIR"
echo "======================================"

cd "$MAGENTO_DIR"

case "$ENVIRONMENT" in
    integration)
        BASE_URL="http://integration.magento.local"
        ;;
    staging)
        BASE_URL="http://staging.magento.local"
        ;;
    production)
        BASE_URL="http://production.magento.local"
        ;;
    *)
        echo "Invalid environment: $ENVIRONMENT"
        exit 1
        ;;
esac

docker compose exec -T phpfpm php bin/magento cache:flush

echo "Warming Magento cache..."

BASE_URL=$(docker compose exec -T phpfpm \
    php bin/magento config:show web/unsecure/base_url)
    
echo "Checking: $BASE_URL"

curl -fsS "$BASE_URL" > /dev/null

echo "Magento storefront is responding."

echo "=== POST DEPLOY COMPLETE ==="
#!/bin/bash

set -e

echo "=== POST DEPLOY START ==="

php bin/magento cache:flush

echo "Warming Magento cache..."

curl -s http://localhost/ > /dev/null

echo "=== POST DEPLOY COMPLETE ==="
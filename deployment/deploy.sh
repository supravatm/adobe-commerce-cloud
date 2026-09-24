#!/bin/bash

set -e

echo "=== DEPLOY START ==="

php bin/magento maintenance:enable

php bin/magento setup:upgrade

php bin/magento cache:flush

php bin/magento indexer:reindex

php bin/magento maintenance:disable

echo "=== DEPLOY COMPLETE ==="
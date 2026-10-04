#!/usr/bin/env bash
# End-to-end run of the plugin inside sylius/test-application against a mock XPay API
# (tests/E2E/mock_xpay.php) that follows the documented contract and sends signed webhooks.
#
#   tests/E2E/run.sh            # card, decline, fawry, forged, refund, admin
#   KEEP=1 tests/E2E/run.sh     # leave the containers running afterwards
set -euo pipefail
cd "$(dirname "$0")/../.."
ROOT=$PWD
E2E=$ROOT/tests/E2E
IMG=ghcr.io/sylius/sylius-php:8.3-alpine
NET=xpay-e2e
DB_URL="mysql://root:root@xpay-e2e-db:3306/xpay_e2e?serverVersion=8.4"
ENVS=(-e APP_ENV=test -e APP_DEBUG=1 -e DATABASE_URL="$DB_URL" -e SYLIUS_TEST_APP_CONFIGS_TO_IMPORT=/e2e/app_config.yaml
      -e SYLIUS_MESSENGER_TRANSPORT_PAYMENT_REQUEST_DSN=sync:// -e SYLIUS_MESSENGER_TRANSPORT_PAYMENT_REQUEST_FAILED_DSN=sync://)
VOLS=(-v "$ROOT:/app" -v "$E2E:/e2e")

cleanup() { [[ "${KEEP:-}" == 1 ]] || docker rm -f xpay-app xpay-mock xpay-e2e-db >/dev/null 2>&1; [[ "${KEEP:-}" == 1 ]] || docker network rm $NET >/dev/null 2>&1 || true; }
trap cleanup EXIT
app() { docker run --rm --network $NET -u "$(id -u):$(id -g)" "${VOLS[@]}" "${ENVS[@]}" -w /app --entrypoint sh $IMG -c "$*"; }

docker network create $NET >/dev/null 2>&1 || true
docker run -d --name xpay-e2e-db --network $NET -e MYSQL_ROOT_PASSWORD=root mysql:8.4 >/dev/null
docker run -d --name xpay-mock --network $NET -v "$E2E:/e2e" -w /e2e --entrypoint php $IMG -S 0.0.0.0:8001 mock_xpay.php >/dev/null
until docker exec xpay-e2e-db mysqladmin -uroot -proot ping 2>/dev/null | grep -q alive; do sleep 2; done

# The shop pages only need the Encore entrypoint files to exist.
for b in shop admin app/shop app/admin; do
    mkdir -p vendor/sylius/test-application/public/build/$b
    [[ -f vendor/sylius/test-application/public/build/$b/entrypoints.json ]] || \
        echo '{"entrypoints":{"shop-entry":{},"admin-entry":{},"admin-product-entry":{},"app-shop-entry":{},"app-admin-entry":{},"plugin-shop-entry":{},"plugin-admin-entry":{}}}' > vendor/sylius/test-application/public/build/$b/entrypoints.json
done

app 'vendor/bin/console cache:clear -q; vendor/bin/console doctrine:database:create -n -q; vendor/bin/console doctrine:migrations:migrate -n -q;
     vendor/bin/console sylius:fixtures:load default -n -q; [ -f vendor/sylius/test-application/config/encryption/key ] || vendor/bin/console sylius:payment:generate-key -q;
     php /e2e/setup.php'
docker run -d --name xpay-app --network $NET -u "$(id -u):$(id -g)" "${VOLS[@]}" "${ENVS[@]}" -w /app/vendor/sylius/test-application/public \
    --entrypoint php $IMG -d variables_order=EGPCS -S 0.0.0.0:8000 index.php >/dev/null
sleep 2

drive() { rm -f "$E2E/mock_state.json"; docker run --rm --network $NET -v "$E2E:/e2e" --entrypoint php $IMG /e2e/drive.php "$1"; }
for scenario in card decline fawry forged; do drive $scenario; done
app 'php /e2e/refund.php'
docker run --rm --network $NET -v "$E2E:/e2e" --entrypoint php $IMG /e2e/admin.php

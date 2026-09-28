#!/bin/bash
set -e

# Kiểm tra nếu dev-init bị tắt
if [ "${RUN_DEV_INIT:-true}" = "false" ]; then
    echo "[Docker][dev-init] Bỏ qua dev-init (RUN_DEV_INIT=false)"
    exit 0
fi

# Bảo vệ an toàn môi trường Production
if [ "${APP_ENV}" = "production" ] && [ "${SEED_DEMO_DATA:-false}" = "true" ]; then
    echo "[Docker][dev-init] LỖI BẢO MẬT: Không được phép chạy dữ liệu demo khi APP_ENV=production!" >&2
    exit 1
fi

# Chạy migrations nếu được bật
if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
    echo "[Docker][dev-init] Chạy Laravel migrations..."
    php artisan migrate --force
fi

# Chạy seeders nếu được bật
if [ "${SEED_DEMO_DATA:-true}" = "true" ]; then
    SEED_CLASS="${SEED_CLASS:-Database\\Seeders\\DatabaseSeeder}"
    echo "[Docker][dev-init] Chạy seeder: ${SEED_CLASS}"
    php artisan db:seed --class="${SEED_CLASS}" --force
fi

echo "[Docker][dev-init] Database development đã sẵn sàng."

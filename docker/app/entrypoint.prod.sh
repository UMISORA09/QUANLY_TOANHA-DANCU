#!/bin/bash
set -e

echo "[Production Docker] Khởi tạo container SMART CASSAVAS..."

# Tự động tạo file SQLite nếu cấu hình sqlite được kích hoạt
if [ "$DB_CONNECTION" = "sqlite" ] && [ -n "$DB_DATABASE" ] && [ "$DB_DATABASE" != ":memory:" ]; then
    mkdir -p "$(dirname "$DB_DATABASE")" 2>/dev/null || true
    touch "$DB_DATABASE" 2>/dev/null || true
fi

# Cache cấu hình và routes để tối ưu hiệu năng production
if [ "$APP_ENV" = "production" ] || [ "$APP_ENV" = "staging" ]; then
    echo "[Production Docker] Đang tối ưu hóa Laravel config & routes..."
    php artisan config:cache || true
    php artisan route:cache || true
    php artisan view:cache || true
fi

# Chạy migration database nếu được bật (mặc định bật)
if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
    echo "[Production Docker] Thực thi database migrations..."
    php artisan migrate --force --no-interaction || echo "[Production Docker] Cảnh báo: Migration không thành công, tiếp tục khởi động..."
fi

echo "[Production Docker] Khởi động hệ thống Smart Cassavas tại http://0.0.0.0:8000 ..."
exec php artisan serve --host=0.0.0.0 --port=8000

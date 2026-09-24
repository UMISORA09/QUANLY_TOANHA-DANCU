#!/bin/sh
set -e

echo "[Production Docker] Khởi tạo container SMART CASSAVAS (Production)..."

# Tự động tạo file SQLite nếu cấu hình sqlite được kích hoạt
if [ "$DB_CONNECTION" = "sqlite" ] && [ -n "$DB_DATABASE" ] && [ "$DB_DATABASE" != ":memory:" ]; then
    mkdir -p "$(dirname "$DB_DATABASE")" 2>/dev/null || true
    touch "$DB_DATABASE" 2>/dev/null || true
fi

# Chạy migration database chỉ khi được cấu hình rõ ràng (tránh race condition khi chạy nhiều replica)
if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    echo "[Production Docker] Thực thi database migrations..."
    php artisan migrate --force --no-interaction
fi

# Nếu container được truyền lệnh tùy biến (ví dụ: queue:work, schedule:run, php-fpm...), thực thi lệnh đó
if [ "$#" -gt 0 ]; then
    echo "[Production Docker] Thực thi lệnh tùy biến: $*"
    exec "$@"
fi

# Nếu cấu hình SERVER_MODE=fpm, chạy PHP-FPM cho kiến trúc Nginx reverse proxy
if [ "${SERVER_MODE:-}" = "fpm" ]; then
    echo "[Production Docker] Khởi động PHP-FPM FastCGI server tại port 9000..."
    exec php-fpm -F
fi

# Mặc định khởi chạy HTTP server tại cổng 8000 (hỗ trợ standalone container và CI smoke test)
echo "[Production Docker] Khởi động hệ thống Smart Cassavas tại http://0.0.0.0:8000 ..."
exec php artisan serve --host=0.0.0.0 --port=8000

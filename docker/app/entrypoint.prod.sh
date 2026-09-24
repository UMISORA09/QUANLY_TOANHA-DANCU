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

# Nếu container được truyền lệnh tùy biến (ví dụ: queue:work, schedule:run, artisan...), thực thi lệnh đó
if [ "$#" -gt 0 ]; then
    echo "[Production Docker] Thực thi lệnh tùy biến: $*"
    exec "$@"
fi

# Mặc định khởi chạy PHP-FPM ở chế độ foreground
echo "[Production Docker] Khởi động PHP-FPM FastCGI server tại port 9000..."
exec php-fpm -F

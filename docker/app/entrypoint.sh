#!/bin/bash
set -e

# Đảm bảo file .env tồn tại
if [ ! -f .env ]; then
    echo "[Docker] Tạo file .env từ .env.example..."
    cp .env.example .env
fi

# Cài đặt PHP dependencies nếu chưa có vendor
if [ ! -d "vendor" ] || [ ! -f "vendor/autoload.php" ]; then
    echo "[Docker] Đang chạy composer install..."
    composer install --no-interaction --prefer-dist --optimize-autoloader
fi

# Đảm bảo APP_KEY đã được tạo
php artisan key:generate --force

# Cài đặt NPM và build assets nếu chưa có
if [ ! -d "node_modules" ]; then
    echo "[Docker] Đang cài đặt thư viện frontend..."
    npm install
fi

if [ ! -d "public/build" ]; then
    echo "[Docker] Đang biên dịch giao diện Home.tsx với Vite..."
    npm run build
fi

# Tự động chạy migrations
echo "[Docker] Chạy migrations cơ sở dữ liệu..."
php artisan migrate --force

# Khởi động server
echo "[Docker] Khởi động hệ thống Smart Cassavas tại http://0.0.0.0:8000 ..."
exec php artisan serve --host=0.0.0.0 --port=8000

# ==============================================================================
# Base Stage: PHP 8.4 runtime với đầy đủ extension cần thiết
# ==============================================================================
FROM php:8.4-bookworm AS base

WORKDIR /var/www/html

# Cài đặt các công cụ hệ thống tối thiểu
RUN apt-get update && apt-get install -y --no-install-recommends \
    curl \
    gnupg2 \
    apt-transport-https \
    git \
    unzip \
    libzip-dev \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    ca-certificates \
    && rm -rf /var/lib/apt/lists/*

# Sử dụng công cụ cài đặt extensions chính thức
ADD https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/

# Cài đặt PHP extensions cho MySQL & hệ thống
RUN chmod +x /usr/local/bin/install-php-extensions && \
    install-php-extensions \
    pdo_mysql \
    pdo_sqlite \
    zip \
    bcmath \
    gd \
    intl \
    opcache

# Tích hợp Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Cấu hình PHP & Opcache tối ưu cho container
RUN echo "opcache.enable=1\nopcache.enable_cli=1\nopcache.memory_consumption=256\nopcache.max_accelerated_files=20000\nopcache.revalidate_freq=2\nopcache.validate_timestamps=1\nvariables_order=\"EGPCS\"" > /usr/local/etc/php/conf.d/custom.ini

# ==============================================================================
# Development Stage: Dành cho lập trình viên local (mount volume, Node.js, HMR)
# ==============================================================================
FROM base AS development

# Cài đặt Node.js 22 LTS và npm phục vụ live rebuild Vite
COPY --from=node:22-bookworm-slim /usr/local/bin /usr/local/bin
COPY --from=node:22-bookworm-slim /usr/local/lib/node_modules /usr/local/lib/node_modules

COPY docker/app/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 8000

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]

# ==============================================================================
# Frontend Builder Stage: Đóng gói giao diện React / Vite độc lập
# ==============================================================================
FROM node:22-bookworm-slim AS frontend-builder

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci --ignore-scripts

COPY resources/ ./resources/
COPY vite.config.js tsconfig.json ./
COPY public/ ./public/

RUN npm run build

# ==============================================================================
# Production Stage: Image độc lập, tối ưu, tự chứa mã nguồn để push GHCR
# ==============================================================================
FROM base AS production

ENV APP_ENV=production \
    APP_DEBUG=false \
    RUN_MIGRATIONS=true

WORKDIR /var/www/html

# Cài đặt PHP dependencies tối ưu cho production (không bao gồm dev packages)
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts

# Sao chép toàn bộ mã nguồn dự án
COPY . .

# Sao chép frontend bundles đã build từ frontend-builder
COPY --from=frontend-builder /app/public/build ./public/build

# Tạo autoloader và tối ưu hóa
RUN composer dump-autoload --optimize --no-dev

# Thiết lập phân quyền thư mục lưu trữ cho web server
RUN chown -R www-data:www-data storage bootstrap/cache && \
    chmod -R 775 storage bootstrap/cache

# Cấu hình script khởi động production
COPY docker/app/entrypoint.prod.sh /usr/local/bin/entrypoint.prod.sh
RUN chmod +x /usr/local/bin/entrypoint.prod.sh

EXPOSE 8000

ENTRYPOINT ["/usr/local/bin/entrypoint.prod.sh"]

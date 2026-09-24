# ==============================================================================
# Base Stage: PHP 8.4-FPM runtime với đầy đủ extension (MySQL, SQLite, Redis, Opcache)
# ==============================================================================
FROM php:8.4-fpm-bookworm AS base

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
    libicu-dev \
    ca-certificates \
    && rm -rf /var/lib/apt/lists/*

# Sử dụng công cụ cài đặt extensions chính thức
ADD https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/

# Cài đặt PHP extensions: bổ sung redis phục vụ cache, session, queue hiệu năng cao
RUN chmod +x /usr/local/bin/install-php-extensions && \
    install-php-extensions \
    pdo_mysql \
    pdo_sqlite \
    redis \
    zip \
    bcmath \
    gd \
    intl \
    opcache

# Tích hợp Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Cấu hình PHP & Opcache cơ bản cho container
RUN echo "opcache.enable=1\nopcache.enable_cli=1\nopcache.memory_consumption=256\nopcache.max_accelerated_files=20000\nopcache.revalidate_freq=2\nopcache.validate_timestamps=1\nvariables_order=\"EGPCS\"" > /usr/local/etc/php/conf.d/custom.ini

# ==============================================================================
# Development Stage: Dành cho lập trình viên local (mount volume, Node.js, HMR)
# ==============================================================================
FROM base AS development

# Cài đặt Node.js 22 LTS và npm phục vụ live rebuild Vite
COPY --from=node:22-bookworm-slim /usr/local/bin /usr/local/bin
COPY --from=node:22-bookworm-slim /usr/local/lib/node_modules /usr/local/lib/node_modules

COPY docker/app/entrypoint.sh /usr/local/bin/entrypoint.sh
COPY docker/app/dev-init.sh /usr/local/bin/dev-init.sh
RUN chmod +x /usr/local/bin/entrypoint.sh /usr/local/bin/dev-init.sh

EXPOSE 8000

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]

# ==============================================================================
# Vendor Stage: Cài đặt PHP dependencies tối ưu cache layer
# ==============================================================================
FROM base AS vendor

WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --prefer-dist \
    --no-progress \
    --optimize-autoloader \
    --no-scripts

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
# Production Stage: Image PHP-FPM độc lập, tối ưu, tự chứa mã nguồn để push GHCR
# ==============================================================================
FROM base AS production

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    RUN_MIGRATIONS=false

WORKDIR /var/www/html

# Cấu hình PHP-FPM OPcache production tối ưu (không validate timestamp)
RUN printf '%s\n' \
    'opcache.enable=1' \
    'opcache.enable_cli=0' \
    'opcache.memory_consumption=256' \
    'opcache.interned_strings_buffer=16' \
    'opcache.max_accelerated_files=20000' \
    'opcache.validate_timestamps=0' \
    'opcache.revalidate_freq=0' \
    'memory_limit=256M' \
    'upload_max_filesize=20M' \
    'post_max_size=25M' \
    > /usr/local/etc/php/conf.d/production.ini

# Sao chép vendor đã cài đặt từ vendor stage
COPY --from=vendor /var/www/html/vendor ./vendor

# Sao chép toàn bộ mã nguồn dự án
COPY . .

# Sao chép frontend bundles đã build từ frontend-builder
COPY --from=frontend-builder /app/public/build ./public/build

# Tạo classmap autoloader tối ưu cho production
RUN composer dump-autoload --optimize --no-dev --classmap-authoritative

# Thiết lập phân quyền thư mục lưu trữ & database cho user www-data
RUN mkdir -p database \
             storage/framework/cache \
             storage/framework/sessions \
             storage/framework/views \
             storage/logs \
             bootstrap/cache && \
    touch database/database.sqlite && \
    chown -R www-data:www-data database storage bootstrap/cache && \
    chmod -R ug+rwX database storage bootstrap/cache

# Cấu hình PHP-FPM pool production
COPY docker/app/php-fpm.conf /usr/local/etc/php-fpm.d/zz-production.conf

# Cấu hình script khởi động production
COPY docker/app/entrypoint.prod.sh /usr/local/bin/entrypoint.prod.sh
RUN chmod +x /usr/local/bin/entrypoint.prod.sh

EXPOSE 9000

ENTRYPOINT ["/usr/local/bin/entrypoint.prod.sh"]

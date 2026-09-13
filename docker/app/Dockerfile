FROM php:8.3-bookworm

# Thiết lập thư mục làm việc
WORKDIR /var/www/html

# Cài đặt các công cụ hệ thống và Node.js
RUN apt-get update && apt-get install -y \
    curl \
    gnupg2 \
    apt-transport-https \
    git \
    unzip \
    libzip-dev \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    nodejs \
    npm \
    && rm -rf /var/lib/apt/lists/*

# Sử dụng công cụ cài đặt extensions chính thức
ADD https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/

# Cài đặt PHP extensions (bao gồm pdo_sqlsrv cho Microsoft SQL Server)
RUN chmod +x /usr/local/bin/install-php-extensions && \
    install-php-extensions \
    pdo_sqlsrv \
    sqlsrv \
    pdo_mysql \
    pdo_sqlite \
    zip \
    bcmath \
    gd \
    intl \
    opcache

# Tích hợp Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Script khởi động
COPY docker/app/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 8000

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]

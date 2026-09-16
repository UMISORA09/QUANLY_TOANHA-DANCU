FROM php:8.4-bookworm

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

# Cấu hình PHP & Opcache tối ưu cho Docker
RUN echo "opcache.enable=1\nopcache.enable_cli=1\nopcache.memory_consumption=256\nopcache.max_accelerated_files=20000\nopcache.revalidate_freq=0\nopcache.validate_timestamps=1\nvariables_order=\"EGPCS\"" > /usr/local/etc/php/conf.d/custom.ini

# Script khởi động
COPY docker/app/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 8000

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]

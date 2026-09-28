# HƯỚNG DẪN CẤU HÌNH REDIS VÀ DOCKER PRODUCTION
# Dự án `QUANLY_TOANHA-DANCU`

## 1. Kết quả kiểm tra cấu hình hiện tại

Repository hiện có `docker/app/Dockerfile`, không có `Dockerfile` ở thư mục gốc. Một số điểm cần sửa:

| Thành phần | Hiện trạng | Vấn đề |
|---|---|---|
| Redis service | Chưa có trong `docker-compose.prod.yml` | App không có Redis để kết nối |
| `REDIS_CLIENT=phpredis` | Có trong `.env.example` | Dockerfile chưa cài PHP Redis extension |
| Cache | `CACHE_STORE=database` | Cache còn đi qua MySQL |
| Session | `SESSION_DRIVER=file` | Không phù hợp khi chạy nhiều replica |
| Queue | `QUEUE_CONNECTION=database` | Queue còn phụ thuộc MySQL |
| Production server | `php artisan serve` | Không nên dùng làm web server production |
| Docker entrypoint | Tự chạy migration và cache | Có thể gây race condition nếu nhiều replica |
| Database password | Có giá trị mặc định `123567` | Không an toàn cho production |
| PHP OPcache | `validate_timestamps=1` | Chưa tối ưu production |

Cấu hình đề xuất:

```text
Internet
   ↓
Nginx :80/:443
   ↓ FastCGI
PHP-FPM app :9000
   ├── MySQL :3306
   └── Redis :6379
          ├── Laravel cache
          ├── Laravel session
          └── Laravel queue

queue worker ───────┘
scheduler ────────────┘
```

---

# 2. Cài Redis extension trong Dockerfile

Mở file:

```text
docker/app/Dockerfile
```

Tìm đoạn:

```dockerfile
install-php-extensions \\
    pdo_mysql \\
    pdo_sqlite \\
    zip \\
    bcmath \\
    gd \\
    intl \\
    opcache
```

Thêm `redis`:

```dockerfile
install-php-extensions \\
    pdo_mysql \\
    pdo_sqlite \\
    redis \\
    zip \\
    bcmath \\
    gd \\
    intl \\
    opcache
```

Nếu không thêm extension này, cấu hình:

```env
REDIS_CLIENT=phpredis
```

sẽ không hoạt động đúng trong container PHP.

Kiểm tra sau khi build:

```bash
docker compose -f docker-compose.prod.yml run --rm app php -m | grep -i redis
```

Kết quả cần thấy:

```text
redis
```

---

# 3. Dockerfile production đề xuất

File hiện tại dùng chung base/development/production, nhưng production vẫn khởi động bằng `php artisan serve`. Có thể thay phần production bằng cấu hình sau.

## 3.1. Dockerfile mẫu dùng PHP-FPM

```dockerfile
# syntax=docker/dockerfile:1.7

FROM php:8.4-fpm-bookworm AS base

WORKDIR /var/www/html

RUN apt-get update && apt-get install -y --no-install-recommends \\
    ca-certificates \\
    curl \\
    git \\
    unzip \\
    libzip-dev \\
    libpng-dev \\
    libonig-dev \\
    libxml2-dev \\
    libicu-dev \\
    && rm -rf /var/lib/apt/lists/*

ADD https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/install-php-extensions

RUN chmod +x /usr/local/bin/install-php-extensions && \\
    install-php-extensions \\
    pdo_mysql \\
    redis \\
    zip \\
    bcmath \\
    gd \\
    intl \\
    opcache

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# PHP-FPM production configuration
RUN printf '%s\n' \\
    'opcache.enable=1' \\
    'opcache.enable_cli=0' \\
    'opcache.memory_consumption=256' \\
    'opcache.interned_strings_buffer=16' \\
    'opcache.max_accelerated_files=20000' \\
    'opcache.validate_timestamps=0' \\
    'opcache.revalidate_freq=0' \\
    'memory_limit=256M' \\
    'upload_max_filesize=20M' \\
    'post_max_size=25M' \\
    > /usr/local/etc/php/conf.d/production.ini

# Install PHP dependencies in a cache-friendly layer
FROM base AS vendor
COPY composer.json composer.lock ./
RUN composer install \\
    --no-dev \\
    --no-interaction \\
    --prefer-dist \\
    --no-progress \\
    --optimize-autoloader \\
    --no-scripts

# Build frontend separately
FROM node:22-bookworm-slim AS frontend
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --ignore-scripts
COPY resources/ ./resources/
COPY public/ ./public/
COPY vite.config.js tsconfig.json ./
RUN npm run build

# Final production image
FROM base AS production

ENV APP_ENV=production \\
    APP_DEBUG=false \\
    LOG_CHANNEL=stderr

WORKDIR /var/www/html

COPY --from=vendor /var/www/html/vendor ./vendor
COPY . .
COPY --from=frontend /app/public/build ./public/build

RUN composer dump-autoload --no-dev --classmap-authoritative && \\
    mkdir -p storage/framework/cache \\
             storage/framework/sessions \\
             storage/framework/views \\
             storage/logs \\
             bootstrap/cache && \\
    chown -R www-data:www-data storage bootstrap/cache && \\
    chmod -R ug+rwX storage bootstrap/cache

COPY docker/app/php-fpm.conf /usr/local/etc/php-fpm.d/zz-production.conf

USER www-data

EXPOSE 9000

CMD ["php-fpm", "-F"]
```

## 3.2. File PHP-FPM pool

Tạo file:

```text
docker/app/php-fpm.conf
```

Nội dung:

```ini
[www]
user = www-data
group = www-data
listen = 9000

pm = dynamic
pm.max_children = 20
pm.start_servers = 3
pm.min_spare_servers = 3
pm.max_spare_servers = 8
pm.max_requests = 500

catch_workers_output = yes
clear_env = no
```

Số `pm.max_children=20` chỉ là giá trị khởi đầu. Cần điều chỉnh theo RAM:

```text
max_children ≈ RAM dành cho PHP-FPM / RAM trung bình mỗi worker
```

Không đặt quá cao vì sẽ làm máy hết RAM và chậm hơn.

---

# 4. Nginx production

Tạo file:

```text
docker/nginx/default.conf
```

```nginx
server {
    listen 80;
    server_name _;

    root /var/www/html/public;
    index index.php;

    client_max_body_size 25m;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        try_files $uri =404;

        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param HTTP_PROXY "";
        fastcgi_pass app:9000;
        fastcgi_read_timeout 60s;
    }

    location ~ /\. {
        deny all;
    }

    location = /health {
        access_log off;
        try_files $uri /index.php?$query_string;
    }
}
```

Nginx phải mount cùng source/public với app để đọc được `public/index.php` và static assets.

---

# 5. docker-compose production có Redis

Có thể thay `docker-compose.prod.yml` bằng mẫu sau. Các giá trị password thật phải được đặt trong file `.env` trên server hoặc secret manager, không commit vào Git.

```yaml
services:
  app:
    image: ${DEPLOY_IMAGE}
    container_name: smart_cassavas_app
    restart: unless-stopped
    env_file:
      - .env.production
    environment:
      APP_ENV: production
      APP_DEBUG: "false"
      LOG_CHANNEL: stderr
      DB_CONNECTION: mysql
      DB_HOST: db
      DB_PORT: 3306
      REDIS_CLIENT: phpredis
      REDIS_HOST: redis
      REDIS_PORT: 6379
      REDIS_PASSWORD: ${REDIS_PASSWORD}
      CACHE_STORE: redis
      SESSION_DRIVER: redis
      SESSION_STORE: cache
      QUEUE_CONNECTION: redis
      RUN_MIGRATIONS: "false"
    expose:
      - "9000"
    depends_on:
      db:
        condition: service_healthy
      redis:
        condition: service_healthy
    networks:
      - cassavas_network

  nginx:
    image: nginx:1.27-alpine
    container_name: smart_cassavas_nginx
    restart: unless-stopped
    ports:
      - "${APP_PORT:-8000}:80"
    volumes:
      - app_public:/var/www/html/public:ro
      - ./docker/nginx/default.conf:/etc/nginx/conf.d/default.conf:ro
    depends_on:
      - app
    networks:
      - cassavas_network

  queue:
    image: ${DEPLOY_IMAGE}
    container_name: smart_cassavas_queue
    restart: unless-stopped
    env_file:
      - .env.production
    environment:
      APP_ENV: production
      APP_DEBUG: "false"
      LOG_CHANNEL: stderr
      DB_HOST: db
      REDIS_CLIENT: phpredis
      REDIS_HOST: redis
      REDIS_PORT: 6379
      REDIS_PASSWORD: ${REDIS_PASSWORD}
      CACHE_STORE: redis
      SESSION_DRIVER: redis
      QUEUE_CONNECTION: redis
      RUN_MIGRATIONS: "false"
    command: ["php", "artisan", "queue:work", "redis", "--sleep=3", "--tries=3", "--timeout=90", "--max-time=3600"]
    depends_on:
      db:
        condition: service_healthy
      redis:
        condition: service_healthy
    networks:
      - cassavas_network

  scheduler:
    image: ${DEPLOY_IMAGE}
    container_name: smart_cassavas_scheduler
    restart: unless-stopped
    env_file:
      - .env.production
    environment:
      APP_ENV: production
      APP_DEBUG: "false"
      LOG_CHANNEL: stderr
      DB_HOST: db
      REDIS_CLIENT: phpredis
      REDIS_HOST: redis
      REDIS_PORT: 6379
      REDIS_PASSWORD: ${REDIS_PASSWORD}
      CACHE_STORE: redis
      SESSION_DRIVER: redis
      QUEUE_CONNECTION: redis
      RUN_MIGRATIONS: "false"
    command: ["sh", "-c", "while true; do php artisan schedule:run --verbose --no-interaction; sleep 60; done"]
    depends_on:
      db:
        condition: service_healthy
      redis:
        condition: service_healthy
    networks:
      - cassavas_network

  db:
    image: mysql:8.0
    container_name: smart_cassavas_db
    restart: unless-stopped
    command:
      - --character-set-server=utf8mb4
      - --collation-server=utf8mb4_unicode_ci
      - --skip-name-resolve
    environment:
      MYSQL_DATABASE: ${DB_DATABASE}
      MYSQL_USER: ${DB_USERNAME}
      MYSQL_PASSWORD: ${DB_PASSWORD}
      MYSQL_ROOT_PASSWORD: ${DB_ROOT_PASSWORD}
    volumes:
      - mysql_prod_data:/var/lib/mysql
    healthcheck:
      test: ["CMD-SHELL", "mysqladmin ping -h localhost -u root -p$${MYSQL_ROOT_PASSWORD}"]
      interval: 5s
      timeout: 5s
      retries: 20
      start_period: 15s
    networks:
      - cassavas_network

  redis:
    image: redis:7.4-alpine
    container_name: smart_cassavas_redis
    restart: unless-stopped
    command:
      - redis-server
      - --requirepass
      - ${REDIS_PASSWORD}
      - --appendonly
      - "yes"
      - --appendfsync
      - everysec
      - --maxmemory
      - 256mb
      - --maxmemory-policy
      - noeviction
    volumes:
      - redis_prod_data:/data
    healthcheck:
      test: ["CMD-SHELL", "redis-cli -a \"$${REDIS_PASSWORD}\" ping | grep PONG"]
      interval: 5s
      timeout: 3s
      retries: 20
    networks:
      - cassavas_network

volumes:
  mysql_prod_data:
  redis_prod_data:
  app_public:

networks:
  cassavas_network:
    driver: bridge
```

## Lưu ý quan trọng về `app_public`

Nếu image chứa `public/build` nhưng Nginx dùng volume `app_public` rỗng, Nginx sẽ không thấy file. Có hai cách:

### Cách đơn giản cho một server

Dùng volume source code read-only cho Nginx:

```yaml
volumes:
  - ./:/var/www/html:ro
  - ./docker/nginx/default.conf:/etc/nginx/conf.d/default.conf:ro
```

### Cách image chuẩn hơn

Tạo image Nginx riêng có `public/` được copy vào image, hoặc dùng shared named volume được khởi tạo bằng một init container. Với nhóm 3 người, cách source read-only dễ vận hành hơn nhưng cần bảo vệ source trên server.

---

# 6. File `.env.production`

Tạo trên server, không commit vào Git:

```env
APP_NAME="Smart Cassavas"
APP_ENV=production
APP_KEY=base64:GENERATE_A_REAL_KEY
APP_DEBUG=false
APP_URL=https://your-domain.example
LOG_CHANNEL=stderr
LOG_LEVEL=warning

DB_CONNECTION=mysql
DB_HOST=db
DB_PORT=3306
DB_DATABASE=quanly_toanha
DB_USERNAME=smart_app
DB_PASSWORD=CHANGE_THIS_LONG_RANDOM_PASSWORD
DB_ROOT_PASSWORD=CHANGE_THIS_OTHER_LONG_RANDOM_PASSWORD

REDIS_CLIENT=phpredis
REDIS_HOST=redis
REDIS_PORT=6379
REDIS_PASSWORD=CHANGE_THIS_LONG_RANDOM_REDIS_PASSWORD
REDIS_DB=0
REDIS_CACHE_DB=1
REDIS_PREFIX=smart_cassavas_

CACHE_STORE=redis
CACHE_PREFIX=smart_cassavas_cache_
SESSION_DRIVER=redis
SESSION_STORE=cache
SESSION_LIFETIME=120
SESSION_SECURE_COOKIE=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax
QUEUE_CONNECTION=redis
REDIS_QUEUE_CONNECTION=default
REDIS_QUEUE=default
REDIS_QUEUE_RETRY_AFTER=90

FILESYSTEM_DISK=local
BCRYPT_ROUNDS=12
RUN_MIGRATIONS=false
```

Tạo key và password ngẫu nhiên:

```bash
php artisan key:generate --show
openssl rand -base64 32
```

Phân quyền file:

```bash
chmod 600 .env.production
```

Không đặt `REDIS_PASSWORD` trong URL công khai và không expose port Redis ra Internet.

---

# 7. Entry point production nên sửa

Entrypoint hiện tại tự chạy:

```bash
php artisan migrate --force || echo "... tiếp tục ..."
php artisan serve
```

Có hai vấn đề:

1. Migration lỗi nhưng container vẫn tiếp tục chạy.
2. `php artisan serve` không phải web server production.

Với kiến trúc PHP-FPM, entrypoint app nên tối giản:

```bash
#!/usr/bin/env sh
set -eu

echo "Starting PHP-FPM production container..."

if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    php artisan migrate --force --no-interaction
fi

exec php-fpm -F
```

Khuyến nghị chạy migration **một lần trong release job**, không chạy đồng thời trong mọi replica:

```bash
docker compose -f docker-compose.prod.yml run --rm app php artisan migrate --force --no-interaction
```

Sau khi migration thành công mới restart/update app.

---

# 8. Cài đặt và khởi động production

## 8.1. Kiểm tra compose

```bash
docker compose --env-file .env.production \\
  -f docker-compose.prod.yml config
```

Nếu YAML hợp lệ, lệnh sẽ in ra cấu hình đã resolve.

## 8.2. Build image

```bash
docker build \\
  --target production \\
  -f docker/app/Dockerfile \\
  -t smart-cassavas:production .
```

Trong CI dùng BuildKit/GitHub Actions như workflow hiện tại.

## 8.3. Khởi động Redis và database

```bash
docker compose --env-file .env.production \\
  -f docker-compose.prod.yml up -d db redis
```

Kiểm tra:

```bash
docker compose -f docker-compose.prod.yml ps

docker compose -f docker-compose.prod.yml logs --tail=100 redis
```

## 8.4. Kiểm tra Redis

```bash
docker compose --env-file .env.production \\
  -f docker-compose.prod.yml exec redis \\
  redis-cli -a "$REDIS_PASSWORD" ping
```

Trong shell server, nếu biến không được export, dùng:

```bash
docker compose --env-file .env.production \\
  -f docker-compose.prod.yml exec redis \\
  sh -c 'redis-cli -a "$REDIS_PASSWORD" ping'
```

Kết quả cần là:

```text
PONG
```

## 8.5. Chạy migration một lần

```bash
docker compose --env-file .env.production \\
  -f docker-compose.prod.yml run --rm app \\
  php artisan migrate --force --no-interaction
```

## 8.6. Xóa cache cũ và tạo cache production

```bash
docker compose --env-file .env.production \\
  -f docker-compose.prod.yml run --rm app php artisan optimize:clear

docker compose --env-file .env.production \\
  -f docker-compose.prod.yml run --rm app php artisan config:cache

docker compose --env-file .env.production \\
  -f docker-compose.prod.yml run --rm app php artisan route:cache

docker compose --env-file .env.production \\
  -f docker-compose.prod.yml run --rm app php artisan view:cache
```

## 8.7. Khởi động toàn bộ stack

```bash
docker compose --env-file .env.production \\
  -f docker-compose.prod.yml up -d
```

## 8.8. Kiểm tra queue

```bash
docker compose -f docker-compose.prod.yml logs -f queue
```

Tạo một job test hoặc kiểm tra queue bằng Tinker:

```bash
docker compose -f docker-compose.prod.yml exec app php artisan tinker
```

```php
Cache::put('redis_test', 'ok', 60);
Cache::get('redis_test');
```

Kết quả cần là:

```text
"ok"
```

---

# 9. Redis cache, session và queue khác nhau thế nào?

| Thành phần | Redis DB đề xuất | Mục đích |
|---|---:|---|
| Cache | DB 1 | Dashboard, permission, search cache |
| Session | Cache store/DB 1 | Session Laravel nếu dùng cookie/session auth |
| Queue | DB 0 | Job email, notification, report |

Laravel hiện có connection Redis `default` và `cache`; có thể dùng:

```env
REDIS_DB=0
REDIS_CACHE_DB=1
REDIS_QUEUE_CONNECTION=default
REDIS_CACHE_CONNECTION=cache
```

Không dùng `CACHE_STORE=redis` khi PHP container chưa có extension `redis` hoặc chưa có `predis` package.

---

# 10. Redis persistence và giới hạn bộ nhớ

## Cache-only

Nếu Redis chỉ lưu cache, có thể dùng policy evict:

```text
allkeys-lru
```

## Cache + session + queue

Dự án dùng Redis cho cả session và queue thì không nên để Redis tự ý xóa key quan trọng. Dùng:

```text
noeviction
```

và theo dõi memory. Nếu Redis đầy, job/session phải báo lỗi thay vì mất âm thầm.

Trong compose mẫu đã bật:

```text
--appendonly yes
--appendfsync everysec
--maxmemory 256mb
--maxmemory-policy noeviction
```

Tăng `256mb` theo dữ liệu thực tế. Cần backup AOF/RDB nếu Redis chứa session hoặc queue có giá trị vận hành.

---

# 11. Bảo mật production

Bắt buộc:

```text
[ ] Không expose port 6379 ra ngoài.
[ ] Không dùng password 123567.
[ ] Không dùng DB root cho Laravel app.
[ ] Không commit .env.production.
[ ] APP_DEBUG=false.
[ ] APP_KEY cố định và lưu ngoài image.
[ ] Redis password dài, ngẫu nhiên.
[ ] Redis chỉ nằm trong Docker network.
[ ] Có backup MySQL.
[ ] Có backup Redis nếu Redis chứa queue/session quan trọng.
[ ] Container chạy non-root khi có thể.
[ ] Không log password, token, cookie hoặc CCCD.
```

Redis password trong compose được truyền qua environment. Với production có yêu cầu bảo mật cao, nên chuyển sang Docker secrets hoặc secret manager thay vì environment plain text.

---

# 12. Những điểm cần sửa trong workflow CI/CD

Workflow hiện tại build production image đúng target, nhưng smoke test đang dùng SQLite và file cache. Điều này không kiểm tra được Redis production.

Bổ sung smoke test compose gồm:

```text
MySQL + Redis + app + Nginx
```

Tối thiểu kiểm tra:

```bash
curl -f http://localhost/health
redis-cli ping
php artisan about
php artisan route:list --except-vendor
```

CI cũng nên kiểm tra extension:

```bash
php -m | grep -qi '^redis$'
```

Và kiểm tra cache:

```bash
php artisan tinker --execute="Cache::put('ci-test', 'ok', 60); if (Cache::get('ci-test') !== 'ok') exit(1);"
```

---

# 13. Lộ trình triển khai an toàn

## Giai đoạn 1 — Local

```bash
docker compose up -d db redis
```

Dùng:

```env
APP_ENV=local
APP_DEBUG=true
CACHE_STORE=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis
```

## Giai đoạn 2 — Staging

- Build production image.
- Dùng MySQL và Redis riêng.
- Chạy migration thủ công trong release job.
- Chạy smoke test.
- Kiểm tra login, dashboard, cache, queue.

## Giai đoạn 3 — Production

- Push image theo immutable tag, ví dụ `sha-abc1234`, không chỉ dùng `latest`.
- Backup database trước migration.
- Chạy migration một lần.
- Deploy app/nginx/queue/scheduler.
- Kiểm tra `/health`.
- Theo dõi logs và Redis memory.
- Có rollback về image tag trước đó.

---

# 14. Checklist nghiệm thu

```text
[ ] Dockerfile cài pdo_mysql và redis.
[ ] Production image không chứa dev dependencies.
[ ] Frontend được build trong multi-stage.
[ ] PHP-FPM chạy foreground.
[ ] Nginx chuyển request tới app:9000.
[ ] Redis healthcheck trả PONG.
[ ] Laravel cache dùng Redis.
[ ] Session dùng Redis nếu cần scale ngang.
[ ] Queue worker dùng Redis.
[ ] Scheduler chạy riêng.
[ ] Redis không expose port public.
[ ] DB password và Redis password không commit.
[ ] APP_DEBUG=false.
[ ] Migration không chạy đồng thời ở nhiều replica.
[ ] Config/route/view cache đã tạo.
[ ] OPcache production đã bật.
[ ] Login smoke test thành công.
[ ] Dashboard smoke test thành công.
[ ] Queue test thành công.
[ ] Có backup và rollback plan.
```

## Mục tiêu hiệu năng ban đầu

| Hạng mục | Mục tiêu staging đề xuất |
|---|---:|
| Redis `PING` | < 10 ms trong cùng Docker network |
| Cache hit | < 50 ms ở tầng Laravel, chưa tính mạng |
| Login p95 | < 1 giây |
| Dashboard cache hit p95 | < 300 ms |
| Queue job bắt đầu xử lý | < 5 giây khi worker khỏe |

Các mục tiêu này phải được benchmark bằng dữ liệu và máy thực tế trước khi ghi là kết quả chính thức.

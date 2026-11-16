# Vercel Deployment Architecture & Operational Guide

## 1. Tổng Quan Kiến Trúc (Architecture Overview)

Dự án **Smart Apartment Management (QUANLY_TOANHA-DANCU)** sử dụng **Vercel** làm nền tảng Production Runtime chính thức, kết hợp kiến trúc container hóa hiệu năng cao:

```text
Browser / Client
       │
       ▼
   Vercel Edge
       │
       ▼
 Vercel Container Service (Dockerfile.vercel)
   ├── Caddy Web Server (Caddyfile: HTTP/2, Zstandard, Gzip)
   ├── FrankenPHP 1.x (PHP 8.4 Bookworm)
   └── Laravel 13 Framework (Optimized Autoload & Cache)
       │
       ├── Managed Cloud MySQL Database (External)
       └── Observability Endpoints:
             ├── GET /health
             ├── GET /api/monitoring/freshness
             └── GET /metrics
```

- **Runtime Image**: `dunglas/frankenphp:1-php8.4-bookworm` (Multi-stage build kết hợp Node 22 slim để build Vite assets).
- **Web Server**: Caddy tích hợp trong FrankenPHP xử lý static file serving, zstd/gzip compression và FastCGI PHP server trực tiếp trong một tiến trình.
- **Tương thích Local**: Môi trường Docker Compose đa container (`smart_cassavas_app`, `smart_cassavas_db`, `smart_cassavas_redis`, `smart_cassavas_phpmyadmin`) được giữ nguyên 100% cho lập trình viên local.

---

## 2. Chiến Lược Phân Nhánh (Branch Strategy)

Dự án áp dụng Git workflow tiêu chuẩn:

| Nhánh (Branch) | Môi Trường (Target Env) | Cơ Chế Deployment | Database Mục Tiêu |
|---|---|---|---|
| `master` | **Production** | CI Gate → DB Migration → Vercel Production Deploy | Production Managed MySQL |
| `develop` | **Staging / Preview** | CI Gate → Vercel Preview Deploy | Staging / Preview DB |
| `feature/*` | **Preview** | CI Gate → Vercel Preview Deploy (theo PR) | Ephemeral / Test DB |
| Pull Request | **Preview / QC** | CI Gate → Vercel Preview URL cho QA/Review | Preview DB |

> **QUY TẮC BẮT BUỘC**:
> - Nhánh mặc định và nhánh phát hành production là `master` (KHÔNG dùng `main`).
> - Không bao giờ deploy production trực tiếp từ nhánh `feature/*` hoặc `develop`.

---

## 3. Cấu Hình Runtime Vercel (`vercel.json` & `Dockerfile.vercel`)

### 3.1. `vercel.json`
Định nghĩa service container ánh xạ tới `Dockerfile.vercel`:
```json
{
  "$schema": "https://openapi.vercel.sh/vercel.json",
  "services": {
    "app": {
      "root": ".",
      "entrypoint": "Dockerfile.vercel",
      "runtime": "container"
    }
  },
  "rewrites": [
    {
      "source": "/(.*)",
      "destination": {
        "service": "app"
      }
    }
  ]
}
```

### 3.2. `Dockerfile.vercel`
- **Stage 1 (Frontend Builder)**: Node 22 build toàn bộ React/TypeScript assets qua Vite (`npm run build`).
- **Stage 2 (Base Runtime)**: `dunglas/frankenphp:1-php8.4-bookworm` cài đặt đầy đủ PHP extensions: `pdo_mysql`, `pdo_sqlite`, `redis`, `zip`, `bcmath`, `gd`, `intl`, `opcache`.
- **Stage 3 (Dependencies)**: Composer 2 cài đặt dependencies với `--no-dev --optimize-autoloader --classmap-authoritative`.
- **Stage 4 (Runtime)**: Copy source và assets build, thiết lập quyền `www-data`, chạy lệnh:
  ```bash
  frankenphp run --config /etc/frankenphp/Caddyfile
  ```

---

## 4. Cơ Sở Dữ Liệu Production (Production Database)

- Container trên Vercel có tính chất stateless và ephemeral, do đó **KHÔNG sử dụng** container MySQL cục bộ (`DB_HOST=db`) trên production.
- Production kết nối tới **External Managed Cloud MySQL** (AWS RDS, PlanetScale, DigitalOcean Managed DB, Aiven, Railway Cloud, v.v.).
- Laravel hỗ trợ cả 2 định dạng cấu hình qua biến môi trường:
  - **Dạng rời rạc**: `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`.
  - **Dạng Connection URL**: `DATABASE_URL` (ví dụ `mysql://user:pass@host:3306/dbname`).

---

## 5. Chiến Lược Migration An Toàn (Database Migration Strategy)

1. **Tuyệt đối KHÔNG chạy migration tự động khi container start**:
   - Biến môi trường production bắt buộc: `RUN_MIGRATIONS=false`.
   - Tránh hiện tượng race condition khi Vercel scale nhiều container replica cùng lúc.
2. **Tuyệt đối KHÔNG seed demo data ở production**:
   - `SEED_DEMO_DATA=false`.
3. **Migration được kiểm soát bởi GitHub Actions Pipeline**:
   - Chạy qua job riêng biệt `production-migration` sau khi toàn bộ CI kiểm thử đã pass:
     ```bash
     php artisan migrate --force --no-interaction
     ```
   - **Nghiêm cấm** chạy `php artisan migrate:fresh` hoặc `php artisan db:wipe` trên production.
   - Nếu secrets database chưa được cấu hình, pipeline sẽ fail rõ ràng:
     `Production database is not configured. Configure production database secrets before deployment.`

---

## 6. Session, Cache, Queue & Storage

- **Session & Cache**:
  - Mặc định trên Vercel: `SESSION_DRIVER=file`, `CACHE_STORE=file` (hoặc `database`).
  - Nếu cần chia sẻ session giữa các replica, cấu hình kết nối tới External Redis (`REDIS_HOST`, `REDIS_PASSWORD`, `REDIS_PORT`).
- **Queue**:
  - Do HTTP container trên Vercel không chạy long-lived worker, `QUEUE_CONNECTION=sync` được áp dụng.
- **Storage**:
  - Local disk `storage/app/public` chỉ phục vụ các file tĩnh được tạo trong phiên làm việc.
  - Các tệp tin tải lên cần lưu trữ lâu dài được cấu hình qua S3-compatible Object Storage disk (`AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_BUCKET`, `AWS_ENDPOINT`).

---

## 7. Giám Sát Sau Triển Khai (Post-Deployment Health & Observability)

Pipeline tự động kiểm tra các endpoints ngay sau khi Vercel deploy:

1. `GET /`: Kiểm tra khả năng phục vụ HTTP và load trang chủ.
2. `GET /health`:
   - Parse JSON kiểm tra `status: "healthy"` và `database: "healthy"`.
   - Đọc `version`, `commit_sha` thực tế (hỗ trợ `VERCEL_GIT_COMMIT_SHA`).
3. `GET /api/monitoring/freshness`:
   - Kiểm tra độ tươi mới của dữ liệu (Collector, GitHub Actions, Deployment, DB).
4. `GET /metrics`:
   - Kiểm tra xuất định dạng Prometheus metrics cho hệ thống giám sát.

---

## 8. Rollback (Chiến Lược Khôi Phục Nhanh)

Khi phát hiện bản phát hành mới gặp sự cố:
1. **Rollback qua Vercel Dashboard / CLI**:
   - Truy cập **Vercel Dashboard** → **Deployments**.
   - Tìm bản deployment ổn định gần nhất trước đó → chọn **Promote to Production** (thời gian khôi phục < 5 giây).
2. **Rollback qua GitHub**:
   - Revert commit lỗi trên nhánh `master` và đẩy lại vào repository:
     ```bash
     git revert HEAD
     git push origin master
     ```
   - Pipeline CI/CD sẽ tự động build và deploy lại bản ổn định.

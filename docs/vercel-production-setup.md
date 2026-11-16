# Hướng Dẫn Cấu Hình Vercel Production & GitHub Secrets

Tài liệu này hướng dẫn chi tiết quy trình thiết lập GitHub Actions Secrets và Vercel Environment Variables để vận hành hệ thống CI/CD tự động cho ứng dụng **Smart Apartment Management (QUANLY_TOANHA-DANCU)**.

---

## 1. Danh Sách GitHub Actions Secrets

Để GitHub Actions có thể kết nối và điều phối triển khai lên Vercel cũng như quản lý database migration an toàn, cần cấu hình các secrets sau trong GitHub Repository:

👉 **Vị trí cấu hình**: GitHub Repository → **Settings** → **Secrets and variables** → **Actions** → **New repository secret**.

| Tên Secret | Mục Đích (Purpose) | Nguồn Lấy (Where to Obtain) | Môi Trường (Scope) |
|---|---|---|---|
| `VERCEL_TOKEN` | Token xác thực quyền truy cập Vercel API / CLI để build và deploy | [Vercel Dashboard → Account Settings → Tokens](https://vercel.com/account/tokens) (Tạo token với scope phù hợp) | Repository Secret (Dùng cho cả Preview & Production) |
| `VERCEL_ORG_ID` | Mã định danh tổ chức hoặc cá nhân sở hữu dự án trên Vercel | File `.vercel/project.json` (sau khi chạy `vercel link`) hoặc Vercel Dashboard → Team Settings → General | Repository Secret |
| `VERCEL_PROJECT_ID` | Mã định danh duy nhất của Project trên Vercel | File `.vercel/project.json` hoặc Vercel Dashboard → Project Settings → General | Repository Secret |
| `PROD_DB_HOST` | Địa chỉ host của MySQL Database Production bên ngoài Vercel (Managed Cloud MySQL) | Nhà cung cấp Cloud MySQL (PlanetScale, AWS RDS, DigitalOcean, Railway, Supabase, Aiven, v.v.) | Production Environment Secret |
| `PROD_DB_PORT` | Cổng kết nối CSDL Production (thường là `3306`) | Nhà cung cấp Cloud MySQL | Production Environment Secret |
| `PROD_DB_DATABASE` | Tên cơ sở dữ liệu trên Production | Nhà cung cấp Cloud MySQL | Production Environment Secret |
| `PROD_DB_USERNAME` | Tên người dùng CSDL Production | Nhà cung cấp Cloud MySQL | Production Environment Secret |
| `PROD_DB_PASSWORD` | Mật khẩu truy cập CSDL Production | Nhà cung cấp Cloud MySQL | Production Environment Secret |
| `DATABASE_URL` | *(Tùy chọn)* Chuỗi kết nối CSDL trọn gói (`mysql://user:pass@host:3306/dbname`) nếu nhà cung cấp cấp connection string | Nhà cung cấp Cloud MySQL | Production Environment Secret |
| `GITHUB_TOKEN` | Token GitHub để truy vấn commit, trigger workflow và hiển thị telemetry thực tế | [GitHub Settings → Developer Settings → Personal access tokens (classic)](https://github.com/settings/tokens) (Scope: `repo`, `workflow`) | Repository Secret |

> **LƯU Ý BẢO MẬT TUYỆT ĐỐI**:
> - Không commit các giá trị thật của secret vào Git repository, file `.env`, hay bất kỳ file mã nguồn nào.
> - Nếu GitHub Actions phát hiện các secret trên chưa được cấu hình, pipeline sẽ dừng lại an toàn với thông báo `NOT CONFIGURED` thay vì giả lập thành công.

---

## 2. Danh Sách Vercel Environment Variables

Các biến môi trường sau đây cần được cấu hình trực tiếp trên **Vercel Project Dashboard**:

👉 **Vị trí cấu hình**: Vercel Dashboard → Project **quanly-toanha-dancu** → **Settings** → **Environment Variables**.

### 2.1. Cấu hình bắt buộc trên Production (Scope: `Production`)

| Tên Biến | Giá Trị Mẫu | Mô Tả |
|---|---|---|
| `APP_ENV` | `production` | Bắt buộc chuyển sang chế độ production |
| `APP_DEBUG` | `false` | Vô hiệu hóa debug stacktrace trên môi trường công khai |
| `APP_KEY` | `base64:...` | Khóa mã hóa bảo mật của Laravel (`php artisan key:generate --show`) |
| `APP_URL` | `https://quanly-toanha-dancu.vercel.app` | URL domain chính thức của ứng dụng |
| `DB_CONNECTION` | `mysql` | Kết nối CSDL MySQL bên ngoài |
| `DB_HOST` | `mysql-production.example.com` | Host database cloud bên ngoài |
| `DB_PORT` | `3306` | Cổng database |
| `DB_DATABASE` | `quanly_toanha` | Tên database |
| `DB_USERNAME` | `smart_cassavas_prod` | Username database |
| `DB_PASSWORD` | `[Mật khẩu an toàn]` | Mật khẩu database cloud |
| `SESSION_DRIVER` | `file` (hoặc `database`) | Lưu session bảo mật |
| `CACHE_STORE` | `file` (hoặc `database`) | Cache store |
| `QUEUE_CONNECTION` | `sync` | Đồng bộ tác vụ trực tiếp |
| `RUN_MIGRATIONS` | `false` | **Bắt buộc = false**: Container HTTP không tự động chạy migration khi khởi động |
| `SEED_DEMO_DATA` | `false` | **Bắt buộc = false**: Tuyệt đối không nạp demo data vào CSDL Production |
| `GITHUB_OWNER` | `UMISORA09` | Chủ sở hữu repository |
| `GITHUB_REPO` | `QUANLY_TOANHA-DANCU` | Tên repository |
| `GITHUB_TOKEN` | `ghp_...` | Token GitHub để API CI/CD & Freshness lấy dữ liệu giám sát thật |

---

## 3. Quy Trình Vận Hành Deployment & Migration

1. **Khi có Pull Request hoặc Push lên `develop`**:
   - GitHub Actions chạy toàn bộ CI (Lint, TypeCheck, Build, PHPUnit MySQL, Security Audit, Docker Smoke Test).
   - Nếu CI pass, kích hoạt **Vercel Preview Deployment** để kiểm thử giao diện & API.
2. **Khi Merge vào `master`**:
   - GitHub Actions chạy toàn bộ CI Gate.
   - Job `production-migration` chạy lệnh:
     ```bash
     php artisan migrate --force --no-interaction
     ```
     *(Chỉ chạy incremental migrations mới, không bao giờ chạy `migrate:fresh` hay `db:wipe`)*.
   - Job `deploy-vercel-production` deploy mã nguồn mới nhất lên Vercel Production runtime (`Dockerfile.vercel` + FrankenPHP).
   - Job `post-deployment-verify` thực hiện smoke test thực tế:
     - `GET /health` (parse JSON kiểm tra database status `healthy`, latency, version, commit SHA).
     - `GET /api/monitoring/freshness` (kiểm tra telemetry không stale).
     - `GET /metrics` (kiểm tra xuất Prometheus metrics đầy đủ).

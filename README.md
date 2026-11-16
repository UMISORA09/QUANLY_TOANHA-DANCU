# HỆ THỐNG QUẢN LÝ TÒA NHÀ & CƯ DÂN - SMART CASSAVAS

[![CI - Continuous Integration](https://github.com/UMISORA09/QUANLY_TOANHA-DANCU/actions/workflows/ci.yml/badge.svg)](https://github.com/UMISORA09/QUANLY_TOANHA-DANCU/actions/workflows/ci.yml)
[![CD - Vercel Deployment & Observability](https://github.com/UMISORA09/QUANLY_TOANHA-DANCU/actions/workflows/vercel.yml/badge.svg)](https://github.com/UMISORA09/QUANLY_TOANHA-DANCU/actions/workflows/vercel.yml)
[![Security & Vulnerability Scan](https://github.com/UMISORA09/QUANLY_TOANHA-DANCU/actions/workflows/security.yml/badge.svg)](https://github.com/UMISORA09/QUANLY_TOANHA-DANCU/actions/workflows/security.yml)

> Đề án hệ thống quản lý tòa nhà chung cư thông minh, kết nối cư dân, ban quản lý, lễ tân và admin.  
> Ngăn xếp công nghệ: **Laravel 12 + React 19 (TypeScript) + Vite + Tailwind CSS + MySQL 8.0 (Docker)**.

---

## 🚀 KHỞI ĐỘNG HỆ THỐNG VỚI DOCKER (DUY NHẤT & CHUẨN HÓA)

Hệ thống được đóng gói và vận hành **duy nhất thông qua Docker Compose**. Không cần cài đặt PHP, Composer, Node.js hay MySQL thủ công trên máy tính cá nhân.

### 🌟 Cách 1: Khởi động 1-Click bằng Menu tự động (Khuyên dùng trên Windows)
Chỉ cần **nhấp đúp chuột vào file `start_docker.bat`** tại thư mục gốc của dự án:
- ✅ Tự động kiểm tra Docker Desktop (nếu chưa bật sẽ tự động bật và chờ sẵn sàng).
- ✅ Tự động kiểm tra và cấu hình chuẩn tệp `.env` kết nối MySQL Docker.
- ✅ Hiển thị Menu điều hành trực quan (Khởi động, Build lại, Reset CSDL, Xem logs, Mở web tự động...).

### 💻 Cách 2: Khởi động bằng dòng lệnh (CLI / Terminal)
```bash
# 1. Chuẩn bị tệp môi trường
Copy-Item .env.example .env   # (hoặc: cp .env.example .env)

# 2. Khởi chạy toàn bộ hệ thống bằng Docker Compose
docker compose up -d
```

Quá trình tự động thực hiện:
1. **`smart_cassavas_db`**: Khởi chạy MySQL 8.0, tự động import dữ liệu ban đầu từ `dump_quanly_toanha.sql`.
2. **`smart_cassavas_app`**: Container PHP 8.4 + Node 22 tự động cài đặt Composer/NPM dependencies, build frontend Vite và chạy ứng dụng Laravel.
3. **`smart_cassavas_phpmyadmin`**: Khởi chạy giao diện phpMyAdmin để quản lý cơ sở dữ liệu.

---

## 🌐 ĐỊA CHỈ TRUY CẬP VÀ KẾT NỐI

| Dịch vụ | Địa chỉ | Thông tin đăng nhập |
| :--- | :--- | :--- |
| **Giao diện Web** | [http://localhost:8000](http://localhost:8000) | Trực tiếp trên trình duyệt |
| **phpMyAdmin** | [http://localhost:8888](http://localhost:8888) | Server: `db`, User: `root`, Password: `123567`, Database: `quanly_toanha` |
| **MySQL Database Port** | `localhost:3306` | User: `root`, Password: `123567`, Database: `quanly_toanha` |

---

## 🛠️ CÁC LỆNH ĐIỀU HÀNH VỚI DOCKER

Mọi thao tác phát triển, kiểm thử và bảo trì đều được thực hiện qua Docker:

- **Xem trạng thái các container**:
  ```bash
  docker compose ps
  ```

- **Xem logs ứng dụng realtime**:
  ```bash
  docker compose logs -f app
  ```

- **Xem logs cơ sở dữ liệu**:
  ```bash
  docker compose logs -f db
  ```

- **Chạy lệnh Artisan bên trong container**:
  ```bash
  docker compose exec app php artisan route:list
  docker compose exec app php artisan migrate
  docker compose exec app php artisan test
  ```

- **Chạy kiểm tra code Pint bên trong container**:
  ```bash
  docker compose exec app vendor/bin/pint
  ```

- **Khởi động lại toàn bộ hệ thống**:
  ```bash
  docker compose restart
  ```

- **Dừng hệ thống**:
  ```bash
  docker compose down
  ```

- **Reset sạch sẽ dữ liệu và nạp lại từ đầu**:
  ```bash
  docker compose down -v
  docker compose up -d
  ```

---

## ⚡ QC LOCAL AUTO WATCH & SAFE DEPLOYMENT (TỰ ĐỘNG THEO DÕI & TRIỂN KHAI QC)

Dành cho Tester / QC trên máy local. Người làm QC chỉ cần mở terminal PowerShell tại thư mục dự án và chạy đúng **MỘT câu lệnh duy nhất**:

```powershell
.\qc-update.ps1
```

*(Tùy chọn: `.\qc-update.ps1 -Branch <ten_nhanh>` hoặc `.\qc-update.ps1 -PollIntervalSeconds 10` hoặc `.\qc-update.ps1 -Once` để chạy 1 lần).*

### Cách thức hoạt động:
1. **Khởi động Watcher (`QC AUTO WATCH = ON`)**: Script duy trì chạy liên tục, tự động thăm dò branch phát triển trên GitHub định kỳ (mặc định mỗi 5 giây).
2. **Developer Git Push → Tự động nhận diện**: Ngay khi Developer push commit mới, Watcher phát hiện sự thay đổi (`LOCAL_COMMIT != REMOTE_COMMIT`) và khởi động quy trình Safe Deployment.
3. **Môi trường Candidate cô lập (`.qc-candidate`)**: Mã nguồn mới được chuẩn bị trong Git Worktree riêng biệt. Ứng dụng hiện tại đang phục vụ QC trên cổng `8000` **hoàn toàn không bị ảnh hưởng hay gián đoạn**.
4. **Kiểm định toàn diện (Lint, Build, Candidate Health Check)**:
   - Kiểm tra cú pháp PHP (PHP Lint) trên các tệp thay đổi.
   - Kiểm tra Composer dependencies và biên dịch frontend Vite bundle.
   - Khởi chạy candidate container trên cổng `8001` (`RUN_MIGRATIONS=false`) và kiểm tra endpoint `/health` thực tế.
5. **Kích hoạt an toàn hoặc Bảo toàn phiên bản cũ**:
   - **Nếu Candidate PASS**: Kích hoạt lên môi trường active (cổng `8000`), thực thi database migrations an toàn và xác minh lại `/health`.
   - **Nếu Candidate FAIL**: Giữ nguyên 100% phiên bản cũ đang chạy ổn định, không để lỗi từ commit mới phá hỏng môi trường QC. Tiếp tục theo dõi commit kế tiếp.
6. **Dừng Watcher (`QC AUTO WATCH = OFF`)**:
   - Khi cần dừng, người dùng chỉ cần nhấn **`Ctrl + C`**.
   - Script dọn dẹp các tệp tạm, giải phóng lock và dừng an toàn. Khi Watcher đã OFF, code mới từ Developer push lên sẽ không tự động nạp vào máy QC cho đến khi người dùng chạy lại `.\qc-update.ps1`.

---


## 🔄 HỆ THỐNG CI/CD (CONTINUOUS INTEGRATION & VERCEL DEPLOYMENT)

Dự án áp dụng quy trình CI/CD tự động hóa toàn diện qua **GitHub Actions** làm bộ điều phối (Orchestrator) và **Vercel** làm nền tảng Production Runtime chính thức:

### 1. ⚙️ Continuous Integration (CI Gate)
Mỗi Pull Request hoặc Push vào các nhánh `master`, `develop`, `main` sẽ tự động kích hoạt pipeline `.github/workflows/ci.yml`:
- **Code Style (Laravel Pint)**: Kiểm tra chuẩn mã nguồn (`vendor/bin/pint --test`).
- **Frontend CI (Typecheck & Build)**: Kiểm tra TypeScript (`npm run typecheck`) và đóng gói tài nguyên tĩnh qua Vite (`npm run build`).
- **Backend Test (MySQL 8.0)**: Khởi chạy MySQL 8.0 service container, kiểm tra kết nối CSDL, thực thi migration -> rollback 1 step -> re-migrate, chạy seeder verification và toàn bộ 115+ bài kiểm thử PHPUnit.
- **Security Audit**: Quét lỗ hổng bảo mật thư viện qua `composer audit` và `npm audit --audit-level=high`.
- **Docker Container Smoke Test**: Đóng gói multi-stage Docker container và kiểm tra tính khả dụng của các endpoint `/up`, `/health`, `/api/monitoring/freshness`, `/metrics`.

### 2. 🚀 Vercel Production Deployment (`.github/workflows/vercel.yml`)
Production runtime hoạt động chính thức trên **Vercel**: [https://quanly-toanha-dancu.vercel.app](https://quanly-toanha-dancu.vercel.app)
- **Runtime**: `Dockerfile.vercel` sử dụng `dunglas/frankenphp:1-php8.4-bookworm` tích hợp Caddy web server.
- **Production Database**: Kết nối External Managed Cloud MySQL an toàn qua biến môi trường (`DB_HOST` hoặc `DATABASE_URL`).
- **Controlled Migration**: Job `production-migration` chạy lệnh `php artisan migrate --force --no-interaction` an toàn trước khi deploy (tuyệt đối không chạy `migrate:fresh` hay `db:wipe`, không seed demo data).
- **Post-Deploy Observability Verification**: Tự động parse JSON và kiểm tra các endpoint `/health`, `/api/monitoring/freshness` và `/metrics` ngay sau khi deploy.

### 3. 🧪 Vercel Preview Deployment (PR & Staging)
- Mọi Pull Request hoặc commit đẩy vào `develop` sẽ tự động tạo một Preview Deployment riêng biệt trên Vercel.
- Cung cấp URL kiểm thử thực tế cho Tester/QC và các thành viên duyệt mã trước khi hòa nhập vào `master`.

### 4. 🖥️ Máy chủ Self-Hosted SSH *(Dự phòng)*
Dự án vẫn duy trì các workflow `.github/workflows/production.yml` và `staging.yml` cho các môi trường máy chủ vật lý / on-premise riêng biệt nếu cần thiết thông qua Docker Compose & SSH scripts.

---

## 📚 TÀI LIỆU KỸ THUẬT DEVOPS (DOCS)

Hệ thống cung cấp đầy đủ tài liệu kiến trúc và vận hành chuẩn mực trong thư mục `docs/`:
- 🚀 [Hướng Dẫn Triển Khai Vercel Production](docs/VERCEL_DEPLOYMENT.md): Chi tiết kiến trúc FrankenPHP container, Caddyfile, cấu hình Vercel và database cloud.
- 🏛️ [Kiến Trúc Toàn Diện CI/CD](docs/CI-CD-ARCHITECTURE.md): Sơ đồ luồng CI/CD, cơ chế Quality Gate, branching strategy và failure handling.
- 🔐 [Thiết Lập GitHub Secrets & Biến Môi Trường](docs/vercel-production-setup.md): Hướng dẫn lấy và cấu hình `VERCEL_TOKEN`, `PROD_DB_*` và các biến môi trường.
- ⏱️ [Giám Sát Độ Tươi Mới Dữ Liệu (Freshness Observability)](docs/freshness-monitoring.md): Kiến trúc đo lường Data Freshness & Monitoring Freshness, Prometheus rules, Alertmanager và Incident tracking.
- 📊 [Giám Sát Hệ Thống](docs/monitoring.md): Tích hợp Sentry, Prometheus (`/metrics`) và Grafana.
- ⏪ [Sổ Tay Phục Hồi & Rollback](docs/rollback.md): Quy trình rollback an toàn và bảo toàn CSDL.
- 🩺 [Sổ Tay Xử Lý Sự Cố](docs/troubleshooting.md): Debug container và truy vết lỗi với `X-Request-ID`.

---

## 🚦 ĐƯỜNG DẪN GIÁM SÁT & VẬN HÀNH

- **DevOps Dashboard (Admin)**: `/admin/cicd` hoặc `/devops`
- **Freshness Observability Endpoint**: `/api/monitoring/freshness`
- **Trang Trạng Thái Công Khai (Public Status)**: `/status`
- **Lịch Sử Bảo Trì & Sự Cố (Public Incidents)**: `/status/incidents`
- **Health Check Endpoint**: `/health` hoặc `/api/health`
- **CSDL Health Check**: `/api/db-health`
- **Prometheus Metrics Scraper**: `/metrics`




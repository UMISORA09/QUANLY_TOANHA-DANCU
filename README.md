# HỆ THỐNG QUẢN LÝ TÒA NHÀ & CƯ DÂN - SMART CASSAVAS

[![CI - Continuous Integration](https://github.com/UMISORA09/QUANLY_TOANHA-DANCU/actions/workflows/ci.yml/badge.svg)](https://github.com/UMISORA09/QUANLY_TOANHA-DANCU/actions/workflows/ci.yml)
[![CD - Staging Deployment](https://github.com/UMISORA09/QUANLY_TOANHA-DANCU/actions/workflows/staging.yml/badge.svg)](https://github.com/UMISORA09/QUANLY_TOANHA-DANCU/actions/workflows/staging.yml)
[![CD - Production Deployment](https://github.com/UMISORA09/QUANLY_TOANHA-DANCU/actions/workflows/production.yml/badge.svg)](https://github.com/UMISORA09/QUANLY_TOANHA-DANCU/actions/workflows/production.yml)
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

## ⚡ QC LOCAL UPDATE (CẬP NHẬT 1 LỆNH AN TOÀN CHO MÁY QC)

Dành cho Tester / QC trên máy local. Khi Developer push code mới lên GitHub, người làm QC chỉ cần chạy **MỘT câu lệnh duy nhất** trên PowerShell:

```powershell
.\qc-update.ps1
```

Hoặc chỉ định cụ thể nhánh cần cập nhật:
```powershell
.\qc-update.ps1 -Branch <ten_nhanh>
```

### Cơ chế Safe Deployment tự động:
1. **Kiểm tra môi trường (Environment Check)**: Kiểm tra Git, Docker, Docker Compose và trạng thái Docker daemon.
2. **Khóa tiến trình (Concurrency Lock)**: Cơ chế lock file `.qc-update.lock` đảm bảo chỉ có duy nhất 1 tiến trình update tại một thời điểm.
3. **Bảo vệ mã nguồn cục bộ (Local Changes Detection)**: Tự động dừng nếu phát hiện file uncommitted trên máy QC, tuyệt đối không ghi đè mất mát dữ liệu hoặc tệp `.env`.
4. **Lấy mã nguồn mới (Fetch Target Commit)**: Lấy commit mới từ GitHub (`git fetch origin <branch>`). Nếu commit đã trùng khớp, hiển thị `Already up to date` và dừng an toàn.
5. **Chuẩn bị môi trường cô lập (.qc-candidate)**: Sử dụng `git worktree` tạo thư mục độc lập cho commit mới. Thư mục chính đang phục vụ QC hoàn toàn không bị ảnh hưởng.
6. **Kiểm tra cú pháp & Biên dịch (Validate & Build)**: Kiểm tra cú pháp PHP (PHP Lint), kiểm tra và đồng bộ Composer, biên dịch giao diện Vite.
7. **Kiểm tra sức khỏe độc lập (Health Check Port 8001)**: Khởi chạy candidate container trên cổng `8001` (với `RUN_MIGRATIONS=false`), liên tục kiểm tra endpoint `/health` thực tế.
8. **Kích hoạt an toàn (Activation)**:
   - **Nếu Candidate PASS**: Chuyển mã nguồn chính sang commit mới, đồng bộ bundle/vendor, chạy migrations an toàn và restart container cổng `8000`.
   - **Nếu Candidate FAIL**: Giữ nguyên 100% phiên bản cũ đang chạy ổn định.
9. **Tự động Rollback (Safe Rollback)**: Nếu sau khi kích hoạt trên cổng `8000` mà `/health` không phản hồi, hệ thống tự động rollback về commit ổn định trước đó.

---


## 🔄 HỆ THỐNG CI/CD (CONTINUOUS INTEGRATION & DEPLOYMENT)

Dự án áp dụng quy trình CI/CD tự động hóa toàn diện qua **GitHub Actions**, **Docker Buildx**, **GitHub Container Registry (GHCR)** và **SSH Deployment**.

### 1. ⚙️ Continuous Integration (CI)
Mỗi Pull Request hoặc Push vào các nhánh `main`, `master`, `develop` sẽ tự động thực hiện:
- **Lint Code**: Kiểm tra tiêu chuẩn mã nguồn PHP bằng Laravel Pint (`vendor/bin/pint --test`).
- **Backend Test**: Khởi chạy MySQL 8.0 service container, kiểm tra kết nối, thực thi migrations & rollback test, chạy bộ kiểm thử PHPUnit (Unit & Feature tests).
- **Frontend Build**: Cài đặt dependencies với `npm ci`, biên dịch bundles với Vite (`npm run build`), kiểm tra tính hợp lệ của manifest và asset bundles.
- **Security Check**: Quét lỗ hổng thư viện qua `composer audit` và `npm audit`.
- **Python Check**: Tự động phát hiện và kiểm thử nếu có module Python bổ sung trong tương lai.

### 2. 🐳 Docker & GHCR Registry
Workflow `.github/workflows/docker.yml` thực thi:
- Build Docker Image tối ưu dạng Multi-stage (tách biệt `development` và `production`).
- **Smoke Test**: Khởi động thử nghiệm container trên môi trường runner và kiểm tra endpoint `/health` trả về HTTP 200 trước khi đẩy image.
- **GitHub Container Registry (GHCR)**: Đẩy image lên `ghcr.io/umisora09/quanly_toanha-dancu` kèm đầy đủ OCI labels và tags:
  - `sha-<commit_sha>` (Định danh bất biến cho từng bản build)
  - `latest` (Dành cho bản build trên nhánh mặc định)
  - `<branch_name>`
  - `vX.Y.Z` (Khi phát hành bản release)

Xem các packages đã build tại: [GitHub Packages - QUANLY_TOANHA-DANCU](https://github.com/UMISORA09/QUANLY_TOANHA-DANCU/pkgs/container/quanly_toanha-dancu).

### 3. 🚀 Triển khai Staging (cd-staging.yml)
- Tự động kích hoạt khi có commit mới vào `main` sau khi CI và Docker build hoàn tất thành công.
- Kéo chính xác Docker Image theo Commit SHA từ GHCR về máy chủ Staging.
- Chạy migrations và kiểm tra endpoint `/health`.

### 4. 💎 Phát hành Production (cd-production.yml)
Quy trình phát hành Production được bảo vệ nghiêm ngặt:
1. Tạo và đẩy Git Tag phiên bản:
   ```bash
   git tag v1.0.0
   git push origin v1.0.0
   ```
2. Workflow yêu cầu xét duyệt qua **GitHub Environment Approval Gate** (`production`).
3. Đăng nhập GHCR trên server Production, kéo chính xác Image Release Tag, lưu backup tham chiếu phiên bản trước, chạy migration an toàn và khởi động lại container.
4. Tự động kiểm tra sức khỏe hệ thống sau khi deploy.

### 5. 🔐 Danh sách Secrets cần thiết (Environment Secrets)
Cần cấu hình trong **Settings > Environments** (`staging` và `production`) trên GitHub:

| Tên Secret | Ý nghĩa |
| :--- | :--- |
| `STAGING_HOST` | Địa chỉ IP hoặc Domain máy chủ Staging |
| `STAGING_PORT` | Cổng SSH máy chủ Staging (mặc định 22) |
| `STAGING_USER` | Tên người dùng SSH (ví dụ: `ubuntu`, `deploy`) |
| `STAGING_SSH_KEY` | Private Key SSH để đăng nhập máy chủ Staging |
| `STAGING_APP_PATH` | Đường dẫn thư mục dự án trên Staging (ví dụ: `/var/www/smart-cassavas`) |
| `PROD_HOST` | Địa chỉ IP hoặc Domain máy chủ Production |
| `PROD_PORT` | Cổng SSH máy chủ Production (mặc định 22) |
| `PROD_USER` | Tên người dùng SSH máy chủ Production |
| `PROD_SSH_KEY` | Private Key SSH máy chủ Production |
| `PROD_APP_PATH` | Đường dẫn thư mục dự án trên Production |

> *Lưu ý: Không lưu trữ giá trị secret thực tế vào mã nguồn.*

### 6. ⏪ Cơ chế Phục hồi Khẩn cấp (Rollback Strategy)
Khi có sự cố xảy ra trong quá trình deploy, hệ thống hỗ trợ khôi phục tức thì:
- **Tự động**: Script `deploy.sh` tự động kích hoạt `rollback.sh` nếu health check hoặc smoke test thất bại sau khi khởi động image mới.
- **Thủ công**: Đăng nhập vào server và chạy:
  ```bash
  ./scripts/rollback.sh
  ```
  Hoặc chỉ định cụ thể phiên bản image ổn định trước đó:
  ```bash
  ./scripts/deploy.sh "ghcr.io/umisora09/quanly_toanha-dancu:sha-xxxxxxx"
  ```

---

## 📚 TÀI LIỆU KỸ THUẬT DEVOPS (DOCS)

Hệ thống cung cấp đầy đủ tài liệu kiến trúc và vận hành chuẩn mực trong thư mục `docs/`:
- 🏛️ [Kiến Trúc Tổng Thể DevOps](docs/devops-architecture.md): Sơ đồ luồng CI/CD, Containerization, Monitoring & Rollback.
- 🛡️ [Quy Tắc Bảo Vệ Nhánh GitHub](docs/github-branch-protection.md): Branch protection rules cho `main` và `develop`.
- ⚙️ [Hướng Dẫn Vận Hành CI/CD](docs/ci-cd.md): Chi tiết 4 pipeline CI, Staging, Production và Security.
- 🚀 [Hướng Dẫn Triển Khai Máy Chủ](docs/deployment.md): Zero-Downtime deployment và cấu hình GitHub Secrets.
- ⏪ [Sổ Tay Phục Hồi & Rollback](docs/rollback.md): Quy trình rollback an toàn và bảo toàn CSDL.
- 📊 [Giám Sát Hệ Thống](docs/monitoring.md): Tích hợp Sentry, Prometheus (`/metrics`) và Grafana.
- 🚨 [Cảnh Báo & Bất Thường](docs/alerting.md): Ngưỡng kích hoạt và tích hợp Discord/Slack Webhook.
- 🩺 [Sổ Tay Xử Lý Sự Cố](docs/troubleshooting.md): Debug container và truy vết lỗi với `X-Request-ID`.

---

## 🚦 ĐƯỜNG DẪN GIÁM SÁT & VẬN HÀNH

- **DevOps Dashboard (Admin)**: `/admin/cicd` hoặc `/devops`
- **Trang Trạng Thái Công Khai (Public Status)**: `/status`
- **Lịch Sử Bảo Trì & Sự Cố (Public Incidents)**: `/status/incidents`
- **Health Check Endpoint**: `/health` hoặc `/api/health`
- **CSDL Health Check**: `/api/db-health`
- **Prometheus Metrics Scraper**: `/metrics`



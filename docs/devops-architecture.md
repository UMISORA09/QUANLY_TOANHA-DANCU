# DevOps Architecture & Engineering Design

Dự án: **QUANLY_TOANHA-DANCU – Hệ thống quản lý tòa nhà & cư dân / SMART CASSAVAS**  
Repository: [https://github.com/UMISORA09/QUANLY_TOANHA-DANCU](https://github.com/UMISORA09/QUANLY_TOANHA-DANCU)  
Phiên bản tài liệu: **2.0.0**  
Trạng thái: **Active / Implemented**

---

## 1. Tổng Quan Kiến Trúc Hiện Tại & Mục Tiêu (Current vs Target)

### 1.1. Hiện trạng (Current Architecture)
- **Backend Framework**: Laravel 12 (PHP 8.4 runtime), Eloquent ORM.
- **Frontend Framework**: React 18, TypeScript, TailwindCSS, Vite build system.
- **Database**: MySQL 8.0 (InnoDB, utf8mb4), dữ liệu bảng cư dân, căn hộ, ban quản lý, tiện ích.
- **Containerization**: Docker Compose với dịch vụ `app`, `db`, `phpmyadmin`. Multi-stage Dockerfile gồm `base`, `development`, `frontend-builder`, `production`.
- **Python / FastAPI Audit**: Không phát hiện module Python đang chạy trong nghiệp vụ cốt lõi. Tuy nhiên, CI workflow vẫn duy trì kiểm tra có điều kiện (`python-conditional-check`) để tự động hỗ trợ nếu có module AI/Python được bổ sung trong tương lai mà không làm hỏng quy trình.
- **CI/CD Trạng thái trước**: Đã có workflow CI kiểm tra cơ bản và giao diện bảng điều khiển CI/CD nội bộ tại `/admin/cicd`.

### 1.2. Kiến trúc đích (Target DevOps Architecture)
Xây dựng một hệ thống DevOps toàn diện khép kín:
```
Developer (Git Push / PR)
      │
      ▼
GitHub Repository (UMISORA09/QUANLY_TOANHA-DANCU)
      │
      ├──> GitHub Actions CI Pipeline (Lint, Tests, Security Scan, Build)
      │         │
      │         ├── (FAIL) ──> Block Merge / Gửi Discord/Slack Alert
      │         │
      │         └── (PASS) ──> Docker Build -> GHCR (Image: sha-<commit>)
      │                               │
      ├── [Push/Merge develop] ───────┼──> Staging Environment
      │                               │         │
      │                               │         ├── Health Check (/health)
      │                               │         └── Smoke Test (scripts/smoke-test.sh)
      │                               │                 │
      ├── [Tag v*.*.* / Push main] ───┘                 ▼
      │                                         Production Environment
      │                                                 │
      │                                                 ├── Blue/Green / Rolling Container Update
      │                                                 ├── Health Check & Smoke Test
      │                                                 │     ├── (FAIL) ──> Auto Rollback to Previous SHA
      │                                                 │     └── (PASS) ──> Traffic Switch
      │                                                 │
      ▼                                                 ▼
DevOps Dashboard (/devops)                      Monitoring & Observability
Public Status Page (/status)                      ├── Sentry (Runtime Exceptions & Releases)
                                                  ├── Prometheus (/metrics scraper)
                                                  ├── Grafana (Dashboards P50/P95/P99, 5xx)
                                                  └── Anomaly Detection & Webhook Alerts
```

---

## 2. CI Pipeline (Continuous Integration)

- **Workflow**: `.github/workflows/ci.yml`
- **Kích hoạt (Triggers)**:
  - `push`: Mọi nhánh (`branches: ['**']`), cho phép tất cả thành viên trong nhóm (`xuanhoa/*`, `QuocTin/*`, `DangNguyen/*`) kiểm tra mã nguồn độc lập.
  - `pull_request`: Nhánh đích `main`, `master`, `develop`.
- **Các bước thực thi (Pipeline Stages)**:
  1. **PHP Lint (Laravel Pint)**: Kiểm tra chuẩn code PSR-12 / Laravel Code Style (`vendor/bin/pint --test`).
  2. **Backend Unit & Integration Tests**:
     - Khởi chạy MySQL 8.0 test service container trong GitHub Actions.
     - Cache composer dependencies để tối ưu tốc độ.
     - Chạy migrations và seed dữ liệu mẫu.
     - Kiểm thử test suite với `vendor/bin/phpunit` (26/26 test cases).
  3. **Frontend Build Check**:
     - Cài đặt Node.js 22 LTS với npm cache.
     - Kiểm tra TypeScript và build production bundle Vite (`npm run build`).
     - Xác thực sự tồn tại của `public/build/manifest.json`.
  4. **Security Vulnerability Scan**:
     - `composer audit` kiểm tra bảo mật gói PHP.
     - `npm audit` kiểm tra bảo mật gói Node.js.
  5. **Docker Build & Container Smoke Test**:
     - Build image mục tiêu `production` từ `docker/app/Dockerfile`.
     - Chạy thử container độc lập và gọi `curl http://127.0.0.1:8000/health`.
     - Đảm bảo image có thể chạy độc lập ở môi trường thực tế trước khi xuất xưởng.
  6. **CI Summary Status**: Kiểm tra tổng hợp kết quả của tất cả các job; bắt buộc 100% pass.

---

## 3. CD Pipeline (Continuous Deployment)

### 3.1. Staging Environment (`.github/workflows/staging.yml`)
- Kích hoạt khi có commit merge vào nhánh `develop`.
- Đóng gói Docker image và push lên **GitHub Container Registry (GHCR)**:
  `ghcr.io/umisora09/quanly_toanha-dancu:sha-<commit_sha>` và `...:staging`.
- Triển khai tới Staging Server thông qua SSH runner.
- Chạy tự động:
  - `scripts/health-check.sh`
  - `scripts/smoke-test.sh`
- Nếu phát hiện lỗi: Đánh dấu deployment FAILED và cảnh báo qua Discord/Slack.

### 3.2. Production Environment (`.github/workflows/production.yml`)
- Kích hoạt khi tạo Git release tag `v*.*.*` hoặc push/merge vào `main`.
- Đòi hỏi môi trường bảo vệ (`environment: production` trên GitHub) với Secret Protection.
- Cơ chế Concurrency đảm bảo không có 2 luồng deployment chạy đè nhau (`cancel-in-progress: false`).
- Các bước:
  1. Kéo image định danh chính xác theo SHA từ GHCR.
  2. Sao lưu tham chiếu image đang chạy vào file `.last_deployed_image`.
  3. Khởi động container phiên bản mới với `docker compose -f docker-compose.prod.yml up -d`.
  4. Chạy `scripts/health-check.sh` (20 lần thử, chu kỳ 3s).
  5. Chạy `scripts/smoke-test.sh` kiểm tra các API nghiệp vụ cốt lõi (Auth, Căn hộ, Tiện ích).
  6. **Tự động Rollback**: Nếu bước 4 hoặc bước 5 thất bại, hệ thống tự động kích hoạt `scripts/rollback.sh` để đưa container về `.last_deployed_image` trong vòng dưới 30 giây.

---

## 4. Monitoring & Observability Architecture

### 4.1. Sentry Integration
- Theo dõi runtime exceptions, API 500, unhandled promise rejections ở cả backend (Laravel) và frontend (React).
- Mỗi phiên bản lỗi được gắn thẻ tương ứng `release: sha-<commit_sha>` và `environment: staging|production`.
- Truy vết chính xác commit nào gây ra lỗi mới (regression).

### 4.2. Prometheus Metrics Collection
- Expose endpoint `/metrics` định dạng chuẩn Prometheus (text format).
- Dữ liệu thu thập:
  - `http_requests_total{method, path, status}`
  - `http_request_duration_seconds`
  - `app_uptime_seconds`
  - `app_memory_bytes`
  - `app_database_status` (1 = OK, 0 = Error)
  - `app_database_query_duration_seconds`
- Cấu hình Prometheus scraper định kỳ 15 giây lấy chỉ số từ dịch vụ ứng dụng.

### 4.3. Grafana Dashboards
- Tự động nạp Prometheus data source (`docker/grafana/provisioning/`).
- Dashboard chuyên dụng hiển thị:
  - **System Overview**: CPU, RAM, Disk, Uptime.
  - **HTTP Metrics**: Requests/min, tỉ lệ lỗi 4xx và 5xx.
  - **Latency Distribution**: P50, P95, P99 response times.
  - **Deployment Tracking**: Đánh dấu mốc thời gian release ứng dụng.

---

## 5. Anomaly Detection & Alerting Architecture

### 5.1. Quy tắc phát hiện bất thường (Anomaly Rules)
| Mức độ | Chỉ số | Ngưỡng | Thời gian duy trì | Hành động |
|---|---|---|---|---|
| **CRITICAL** | HTTP 5xx Error Rate | > 5% tổng request | 3 phút | Gửi Alert khẩn cấp, cân nhắc rollback |
| **CRITICAL** | Health Check Endpoint | Trả về 503 hoặc timeout | 1 phút | Kích hoạt Auto Rollback |
| **CRITICAL** | Database Connectivity | Mất kết nối (PDO Exception) | Ngay lập tức | Gửi Alert khẩn cấp |
| **WARNING** | API Latency P95 | > 1000ms | 5 phút | Gửi cảnh báo hiệu năng |
| **WARNING** | RAM Usage Container | > 85% giới hạn | 5 phút | Cảnh báo nguy cơ rò rỉ bộ nhớ |
| **WARNING** | Disk Storage | > 90% dung lượng | 10 phút | Cảnh báo dọn dẹp log/cache |

### 5.2. Kênh cảnh báo (Discord / Slack Webhooks)
- Tích hợp thông qua `AlertNotificationService.php` và biến môi trường `DISCORD_WEBHOOK_URL` / `SLACK_WEBHOOK_URL`.
- Hỗ trợ các trạng thái thông báo trực quan:
  - 🚨 **CRITICAL / INCIDENT**: Nền đỏ, nêu rõ dịch vụ, lỗi, thời gian, commit SHA.
  - ⚠️ **WARNING**: Nền vàng, thông báo tiệm cận ngưỡng quá tải.
  - ✅ **RESOLVED**: Nền xanh, thông báo thời gian khắc phục và khôi phục hoạt động bình thường.

---

## 6. Rollback Architecture & An Toàn CSDL

1. **Nguyên tắc bất biến**: Rollback không được làm mất hoặc sai lệch dữ liệu người dùng.
2. **Quy trình Rollback**:
   - Khi có sự cố, script `scripts/rollback.sh` đọc image tag trước đó từ `.last_deployed_image`.
   - Ra lệnh cho Docker Compose tái khởi động container về image tag an toàn trước đó.
   - **Database Migrations**: Không bao giờ sử dụng `migrate:fresh` hoặc `migrate:reset` trên môi trường Production/Staging. Mọi migration được thiết kế tương thích ngược (backward-compatible). Nếu migration mới bổ sung cột không phá hủy, việc rollback image mã nguồn vẫn an toàn và không gây lỗi CSDL.
   - Tái xác nhận sức khỏe hệ thống sau rollback qua `scripts/health-check.sh`.

---

## 7. Security Architecture & Secrets Management

- **Không bao giờ commit secrets vào Git**: Tệp `.env`, `.env.production`, private keys, mật khẩu CSDL tuyệt đối nằm trong `.gitignore`.
- **Tệp mẫu an toàn**: Cung cấp `.env.example` với các giá trị placeholder tiêu chuẩn.
- **GitHub Secrets & Environments**:
  - `GHCR_TOKEN` / `GITHUB_TOKEN`: Xác thực với GitHub Container Registry.
  - `STAGING_*` và `PROD_*`: Thông tin kết nối SSH và đường dẫn máy chủ được lưu trong GitHub Repository Environment Secrets.
- **Quét bảo mật định kỳ**: Workflow `.github/workflows/security.yml` chạy quét rò rỉ secret bằng Gitleaks, kiểm tra lỗ hổng dependency bằng Composer/NPM Audit và quét lỗ hổng image bằng Aqua Security Trivy.

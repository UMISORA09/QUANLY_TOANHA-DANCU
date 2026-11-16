# CI/CD Pipeline Architecture & Governance

## 1. Mục Tiêu Thiết Kế (Design Principles)

Hệ thống CI/CD của **Smart Apartment Management (QUANLY_TOANHA-DANCU)** được thiết kế để:
1. **Bảo đảm chất lượng tự động (Automated Quality Gates)**: Mọi dòng code trước khi hòa nhập vào repository đều phải vượt qua toàn bộ linting, typechecking, unit/feature tests, migration tests và security audits.
2. **Không làm hỏng môi trường phát triển cục bộ**: Giữ nguyên tương thích với Docker Compose đa container (`docker-compose.yml`) cho các lập trình viên.
3. **Phân định rõ trách nhiệm nền tảng**:
   - **GitHub Actions**: Điều phối CI/CD (Orchestrator).
   - **Vercel**: Nền tảng thực thi Production & Preview (Runtime & Platform).
   - **External Managed MySQL**: Lưu trữ dữ liệu an toàn lâu dài.
4. **Tuyệt đối không giả lập dữ liệu**: Mọi trạng thái hiển thị trên dashboard hay metrics đều phản ánh đo lường từ hệ thống thật.

---

## 2. Sơ Đồ Luồng Pipeline Toàn Diện (End-to-End Workflow)

```text
┌────────────────────────┐
│  Developer Git Push    │
└───────────┬────────────┘
            │
            ▼
┌─────────────────────────────────────────────────────────────┐
│                 GitHub Actions CI Gate                      │
│                                                             │
│  ├── 1. PHP Code Style (Laravel Pint --test)                │
│  ├── 2. Frontend CI (TypeScript Typecheck & Vite Build)     │
│  ├── 3. Backend CI (MySQL 8.0 Container + Migrations +      │
│  │      Rollback Step Test + Seeder + PHPUnit Suite)        │
│  ├── 4. Security Audit (Composer Audit + NPM Audit)         │
│  └── 5. Docker Smoke Test (Multi-stage Container Build,     │
│         /up, /health, /api/monitoring/freshness, /metrics)  │
└───────────┬─────────────────────────────────────────────────┘
            │
      [All CI Passed]
            │
     ┌──────┴───────────────────────────────────────┐
     │                                              │
[Branch == develop / PR]                    [Branch == master]
     │                                              │
     ▼                                              ▼
┌────────────────────────┐             ┌─────────────────────────────┐
│ Vercel Preview Deploy  │             │ Production DB Migration     │
│ (Preview Environment)  │             │ (php artisan migrate --force│
│  - No prod DB impact   │             │  --no-interaction)          │
│  - QC & Smoke Verify   │             └────────────┬────────────────┘
└────────────────────────┘                          │
                                             [Migration OK]
                                                    │
                                                    ▼
                                       ┌─────────────────────────────┐
                                       │ Vercel Production Deploy    │
                                       │ (Dockerfile.vercel container│
                                       │  FrankenPHP + Caddyfile)    │
                                       └────────────┬────────────────┘
                                                    │
                                                    ▼
                                       ┌─────────────────────────────┐
                                       │ Post-Deployment Health      │
                                       │  - GET / (HTTP 200)         │
                                       │  - GET /health (JSON valid) │
                                       │  - GET /api/monitoring/     │
                                       │    freshness (State check)  │
                                       │  - GET /metrics (Prometheus)│
                                       └─────────────────────────────┘
```

---

## 3. Chi Tiết Các Workflows Trong Repository

| File Workflow | Mục Đích | Nhánh Kích Hoạt | Trách Nhiệm Chính |
|---|---|---|---|
| `.github/workflows/ci.yml` | Continuous Integration chính | Mọi push & PR vào `master`, `develop`, `main` | Pint, Vite, PHPUnit trên MySQL 8, Security, Docker container smoke test |
| `.github/workflows/security.yml` | Quét bảo mật chuyên sâu định kỳ & PR | `master`, `develop`, `main`, và cron 02:00 thứ Hai | Gitleaks (lộ secrets), Composer CVE audit, NPM high CVE audit, Trivy container image scan |
| `.github/workflows/vercel.yml` | Continuous Deployment lên Vercel | `master` (Production), PR / `develop` (Preview), manual dispatch | CI Gate → DB Migration an toàn → Vercel CLI deploy → Health verification |
| `.github/workflows/production.yml` | CD máy chủ Self-hosted SSH *(Dự phòng)* | Git tag `v*`, manual dispatch | Build GHCR container image và deploy qua SSH script lên máy chủ riêng biệt (không đè lên Vercel) |
| `.github/workflows/staging.yml` | CD máy chủ Staging SSH *(Dự phòng)* | Push `develop`, manual dispatch | Deploy lên server staging self-hosted (dành cho hạ tầng on-premise) |

---

## 4. Bảo Vệ Dữ Liệu & Quy Tắc Production

1. **Tuyệt đối cấm**:
   - `php artisan migrate:fresh`
   - `php artisan db:wipe`
   - `DROP DATABASE`
   - Seed dữ liệu giả (`db:seed` demo) trên production.
2. **Quy tắc Migration**:
   - Mọi migration phải mang tính chất bổ sung gia số (incremental).
   - Có phương án `down()` rõ ràng để rollback khi cần thiết.
   - Luôn test migrate -> rollback 1 step -> re-migrate trong CI trước khi merge vào `master`.

---

## 5. Xử Lý Sự Cố Thất Bại (Failure Handling)

Mọi stage trong pipeline đều có cơ chế ngắt và báo lỗi rõ ràng (Fail Fast):
- **Lint thất bại**: Dừng ngay lập tức, dev cần chạy `vendor/bin/pint` ở local để định dạng lại code.
- **Typecheck thất bại**: Dừng ngay lập tức, dev cần kiểm tra lỗi TypeScript (`npm run typecheck`).
- **PHPUnit thất bại**: Không xuất build artifact, chặn không cho tiến vào bước deploy.
- **Security phát hiện Secret Leak**: Gitleaks chặn đứng commit có chứa token/key.
- **Database Chưa Cấu Hình**: Workflow dừng lại với thông báo lỗi tường minh, không bỏ qua và không tạo dữ liệu giả.

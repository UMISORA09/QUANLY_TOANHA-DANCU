# Hướng Dẫn Vận Hành CI/CD Pipeline

Dự án: **QUANLY_TOANHA-DANCU – SMART CASSAVAS**  
Repository: [https://github.com/UMISORA09/QUANLY_TOANHA-DANCU](https://github.com/UMISORA09/QUANLY_TOANHA-DANCU)

---

## 1. Danh Sách GitHub Actions Workflows

| Tên Workflow | Tệp Cấu Hình | Kích Hoạt (Trigger) | Nhiệm Vụ Chính |
|---|---|---|---|
| **CI - Continuous Integration** | `.github/workflows/ci.yml` | `push` mọi branch (`**`), `pull_request` vào `main/develop` | Lint code (Pint), Unit Tests & Migrations (PHPUnit), Build Frontend (Vite), Security Scan, Docker Smoke Test |
| **CD - Staging Deployment** | `.github/workflows/staging.yml` | `push` nhánh `develop`, `workflow_dispatch` | Build image GHCR tag `staging`, deploy lên Staging server, chạy Health Check & Smoke Test |
| **CD - Production Deployment** | `.github/workflows/production.yml` | `push` tag `v*.*.*` hoặc nhánh `main` | Build image GHCR tag `vX.Y.Z` & `production`, Zero-Downtime deploy, Auto-Rollback |
| **Security & Vulnerability Scan** | `.github/workflows/security.yml` | `push`, `pull_request`, định kỳ thứ Hai hàng tuần | Secret scanning (Gitleaks), Composer Audit, NPM Audit, Trivy Container Scan |

---

## 2. Chu Trình Làm Việc Cho Lập Trình Viên

1. **Phát triển tính năng cá nhân**:
   ```bash
   git checkout -b feature/ten-tinh-nang
   # Thực hiện code...
   git add .
   git commit -m "feat: mo ta tinh nang"
   git push origin feature/ten-tinh-nang
   ```
   *Ngay lập tức, GitHub Actions CI sẽ tự động kích hoạt để kiểm tra code của bạn mà không cần nhấn thủ công.*

2. **Tạo Pull Request vào `develop`**:
   - Mọi kiểm tra trong CI (`Pint`, `PHPUnit`, `Build Vite`, `Security`, `Docker Build`) phải đạt màu xanh (PASS).
   - Nhánh `develop` sẽ tự động kích hoạt deploy lên môi trường Staging.

3. **Phát hành lên Production**:
   - Tạo tag release:
     ```bash
     git tag -a v1.0.0 -m "Release v1.0.0"
     git push origin v1.0.0
     ```
   - Pipeline Production CD sẽ đóng gói image định danh `ghcr.io/umisora09/quanly_toanha-dancu:v1.0.0` và deploy với cơ chế tự động bảo vệ Rollback.

# Hướng Dẫn Triển Khai Ứng Dụng (Deployment Guide)

Dự án: **QUANLY_TOANHA-DANCU – SMART CASSAVAS**

---

## 1. Cơ Chế Triển Khai (Deployment Flow)

Quy trình triển khai sử dụng Docker Compose Production (`docker-compose.prod.yml`) và image đóng gói độc lập từ GitHub Container Registry (GHCR).

### 1.1. Chuẩn bị Môi Trường Máy Chủ (Server Prerequisites)
- Hệ điều hành: Linux Ubuntu 22.04 / 24.04 LTS (hoặc máy chủ hỗ trợ Docker).
- Đã cài đặt `docker` và `docker compose`.
- Mở cổng: `80`, `443`, `8000` (hoặc cấu hình Nginx Reverse Proxy trỏ vào cổng 8000).

### 1.2. Biến Môi Trường Cần Thiết Trên GitHub Repository
Để kích hoạt deploy tự động qua SSH, cấu hình trong **Settings -> Environments**:

#### A. Môi trường `staging`:
- `STAGING_HOST`: Địa chỉ IP hoặc Domain máy chủ Staging.
- `STAGING_PORT`: Cổng SSH (mặc định 22).
- `STAGING_USER`: Tên tài khoản SSH (ví dụ: `ubuntu` hoặc `deploy`).
- `STAGING_SSH_KEY`: Khóa riêng tư SSH (Private Key) để runner kết nối.
- `STAGING_APP_PATH`: Đường dẫn thư mục dự án (ví dụ: `/var/www/staging.cassavas.vn`).

#### B. Môi trường `production`:
- `PROD_HOST`: Địa chỉ IP hoặc Domain máy chủ Production.
- `PROD_PORT`: Cổng SSH (mặc định 22).
- `PROD_USER`: Tên tài khoản SSH.
- `PROD_SSH_KEY`: Khóa riêng tư SSH.
- `PROD_APP_PATH`: Đường dẫn thư mục dự án (ví dụ: `/var/www/smart.cassavas.vn`).

---

## 2. Triển Khai Thủ Công Bằng Script

Nếu triển khai trực tiếp trên server:
```bash
# 1. Di chuyển vào thư mục dự án
cd /var/www/smart-cassavas

# 2. Chạy script triển khai với image chỉ định
chmod +x scripts/*.sh
./scripts/deploy.sh "ghcr.io/umisora09/quanly_toanha-dancu:sha-a83f21c"
```

Script sẽ tự động:
1. Ghi nhận image trước đó vào `.last_deployed_image`.
2. Kéo image mới từ GHCR.
3. Cập nhật container chạy ngầm (`docker compose -f docker-compose.prod.yml up -d`).
4. Gọi `scripts/health-check.sh` kiểm tra phản hồi `/health`.
5. Gọi `scripts/smoke-test.sh` kiểm tra các API nghiệp vụ.
6. **Tự động Rollback** ngay lập tức nếu bước 4 hoặc 5 không đạt chuẩn.

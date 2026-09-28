# Giám Sát Hệ Thống (Monitoring & Observability Guide)

Dự án: **QUANLY_TOANHA-DANCU – SMART CASSAVAS**

---

## 1. Tích Hợp Sentry (Runtime Error Tracking)

### Cấu hình trong `.env`:
```env
SENTRY_LARAVEL_DSN="https://xxxx@o000000.ingest.sentry.io/0000000"
SENTRY_SAMPLE_RATE=1.0
SENTRY_TRACES_SAMPLE_RATE=0.2
APP_VERSION="sha-a83f21c"
```
- Mọi exception chưa bắt (unhandled exceptions) ở backend và frontend sẽ tự động được gửi lên dashboard Sentry kèm theo:
  - Commit SHA tương ứng (`release: sha-xxxxxxx`)
  - Request ID tương ứng (`request_id: xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx`)
  - Môi trường (`production` hoặc `staging`).

---

## 2. Prometheus & Grafana Stack

### 2.1. Endpoint Exporter: `/metrics`
- Ứng dụng cung cấp sẵn endpoint `/metrics` trả về chỉ số theo định dạng text Prometheus.
- Các chỉ số thu thập:
  - `smart_cassavas_app_uptime_seconds`: Thời gian uptime của ứng dụng.
  - `smart_cassavas_memory_bytes`: Dung lượng RAM đang dùng.
  - `smart_cassavas_database_status`: Trạng thái sống/chết của CSDL MySQL (1 = Online, 0 = Offline).
  - `smart_cassavas_database_query_duration_seconds`: Độ trễ truy vấn CSDL.

### 2.2. Khởi chạy cụm Giám Sát (Prometheus + Grafana)
Để chạy Prometheus và Grafana trên môi trường máy chủ hoặc local:
```bash
docker compose -f docker-compose.yml -f docker-compose.monitoring.yml up -d
```
- **Prometheus Web UI**: `http://localhost:9090` (Scrape tự động từ `app:8000/metrics` mỗi 15s).
- **Grafana Web UI**: `http://localhost:3001` (Tài khoản mặc định: `admin` / `admin`).
- Đã được cấu hình tự động nạp DataSource Prometheus và Dashboard mẫu `Smart Cassavas - DevOps Overview`.

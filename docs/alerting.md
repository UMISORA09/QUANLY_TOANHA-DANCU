# Cảnh Báo & Phát Hiện Bất Thường (Alerting & Anomaly Detection)

Dự án: **QUANLY_TOANHA-DANCU – SMART CASSAVAS**

---

## 1. Các Ngưỡng Phát Hiện Bất Thường (Anomaly Thresholds)

| Loại Cảnh Báo | Mức Độ | Ngưỡng Kích Hoạt | Ý Nghĩa Kỹ Thuật | Hành Động Yêu Cầu |
|---|---|---|---|---|
| **API 5xx Surge** | 🚨 CRITICAL | Tỉ lệ lỗi HTTP 5xx > 5% trong 3 phút liên tục | Ứng dụng gặp lỗi sập hoặc exception hàng loạt | Kiểm tra Sentry, chuẩn bị Rollback |
| **Database Failure** | 🚨 CRITICAL | Mất kết nối CSDL MySQL (PDO Error) | Máy chủ CSDL quá tải hoặc tiến trình bị tắt | Khởi động lại `smart_cassavas_db` |
| **Health Check 503** | 🚨 CRITICAL | Endpoint `/health` trả về status khác 200 | Hệ thống không sẵn sàng phục vụ người dùng | Tự động kích hoạt Rollback |
| **High Latency (P95)** | ⚠️ WARNING | P95 Latency > 1000ms trong 5 phút | Truy vấn chậm hoặc quá tải RAM/CPU | Kiểm tra chỉ mục CSDL và cache |
| **High Memory** | ⚠️ WARNING | RAM container > 85% dung lượng | Nguy cơ rò rỉ bộ nhớ (Memory Leak) | Khởi động lại container ứng dụng |
| **Disk Capacity** | ⚠️ WARNING | Dung lượng ổ đĩa khả dụng < 10% | Nguy cơ đầy log/storage | Dọn dẹp `storage/logs` và Docker cache |

---

## 2. Cấu Hình Discord & Slack Webhook

Cấu hình các biến môi trường trong `.env` hoặc GitHub Secrets:
```env
# Discord Webhook URL
DISCORD_WEBHOOK_URL="https://discord.com/api/webhooks/123456789/abcdefgh"

# Slack Webhook URL (Tùy chọn)
SLACK_WEBHOOK_URL="https://hooks.slack.com/services/T00/B00/XXXX"
```

### Mẫu Thông Báo Thực Tế:
```
🚨 PRODUCTION INCIDENT
Dịch vụ: API / Tiện ích cư dân
Chỉ số: HTTP 5xx Error Rate
Giá trị hiện tại: 7.8% (Ngưỡng cho phép: 5%)
Thời gian: 2026-09-18 15:30:00
Commit: sha-a83f21c
Hành động: Đang kích hoạt Rollback tự động về phiên bản trước.
```

```
✅ INCIDENT RESOLVED
Dịch vụ: API
Thời gian gián đoạn: 2m 14s
Phiên bản khôi phục: sha-92dd72e
Trạng thái hiện tại: 100% Operational
```

# Quy Trình Phục Hồi & Rollback (Rollback Guide)

Dự án: **QUANLY_TOANHA-DANCU – SMART CASSAVAS**

---

## 1. Nguyên Tắc Cốt Lõi Của Rollback

1. **Bảo Toàn Cơ Sở Dữ Liệu 100%**:
   - Tuyệt đối không chạy `migrate:fresh` hay `migrate:reset` trên Staging hoặc Production.
   - Các migration phải được thiết kế tương thích ngược (Backward-Compatible): thêm cột mới dạng nullable hoặc có default value, không xóa ngay cột cũ đang được phiên bản trước sử dụng.
2. **Khôi Phục Nhanh Chóng Trong Dưới 30 Giây**:
   - Khôi phục container về Image Tag đã được lưu trong tệp `.last_deployed_image`.

---

## 2. Các Kịch Bản Rollback

### Kịch Bản 1: Tự Động Rollback (Automatic Rollback)
- Xảy ra khi: Quá trình deploy bản mới thất bại ở bước `health-check.sh` hoặc `smoke-test.sh`.
- Hành động: Script `deploy.sh` tự động bắt mã lỗi (`exit code != 0`) và gọi ngay `scripts/rollback.sh` mà không cần con người can thiệp.

### Kịch Bản 2: Rollback Khẩn Cấp Bằng Tay (Manual Rollback)
Nếu người dùng báo cáo lỗi nghiệp vụ nghiêm trọng sau khi deploy:
```bash
# Cách 1: Sử dụng script tự động đọc phiên bản gần nhất
./scripts/rollback.sh

# Cách 2: Chỉ định trực tiếp phiên bản Docker Image ổn định mong muốn
./scripts/deploy.sh "ghcr.io/umisora09/quanly_toanha-dancu:v1.0.0"
```

### Kịch Bản 3: Rollback Từ Giao Diện Web (DevOps Dashboard)
Quản trị viên có thể truy cập `/admin/cicd` hoặc `/devops`, chuyển đến tab **Triển khai**, nhấn nút **"Rollback về bản trước"**. Hệ thống sẽ gọi API `/api/admin/cicd/rollback` để kích hoạt script trên máy chủ.

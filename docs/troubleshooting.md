# Sổ Tay Xử Lý Sự Cố (DevOps Troubleshooting Playbook)

Dự án: **QUANLY_TOANHA-DANCU – SMART CASSAVAS**

---

## 1. Truy Vết Yêu Cầu Người Dùng Bằng Request ID (`X-Request-ID`)

Mọi yêu cầu HTTP gửi qua hệ thống Smart Cassavas đều được gắn một mã định danh duy nhất (UUID v4) trong header `X-Request-ID`.
- Khi người dùng hoặc kiểm thử viên báo lỗi, hãy yêu cầu giá trị của `X-Request-ID` từ tab Network (Trình duyệt DevTools).
- Tra cứu nhanh trong file log của máy chủ:
  ```bash
  # Lọc toàn bộ hoạt động liên quan đến request đó
  grep "request_id\":\"xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx" storage/logs/laravel.log
  ```

---

## 2. Các Lỗi Thường Gặp & Cách Khắc Phục

### Lỗi 1: Container không thể khởi động hoặc Health Check báo 503
- **Triệu chứng**: Chạy `curl http://localhost:8000/health` nhận HTTP 503 hoặc timeout.
- **Nguyên nhân**: Dịch vụ MySQL chưa hoàn tất khởi động hoặc thông tin cấu hình `DB_*` chưa khớp.
- **Khắc phục**:
  ```bash
  # Kiểm tra tình trạng container DB
  docker ps -a
  docker logs smart_cassavas_db

  # Chạy thử lệnh ping CSDL thủ công
  docker exec smart_cassavas_app php artisan db:show
  ```

### Lỗi 2: Lỗi Vite Manifest ("Unable to locate file in Vite manifest")
- **Nguyên nhân**: Mã nguồn frontend vừa cập nhật nhưng chưa chạy bản build đóng gói.
- **Khắc phục**:
  ```bash
  npm run build
  ```

### Lỗi 3: Quy trình CI thất bại ở bước PHP Pint
- **Nguyên nhân**: Định dạng mã nguồn PHP chưa tuân thủ quy chuẩn Laravel Pint.
- **Khắc phục**:
  ```bash
  vendor/bin/pint --dirty --format agent
  ```

### Lỗi 4: Rollback khẩn cấp khi triển khai phiên bản mới bị lỗi
- **Khắc phục nhanh**:
  ```bash
  ./scripts/rollback.sh
  ```

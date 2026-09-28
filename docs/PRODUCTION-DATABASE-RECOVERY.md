# Production Database Backup, Migration & Disaster Recovery Runbook

Tài liệu hướng dẫn quản trị, sao lưu, phục hồi sự cố CSDL và xử lý lỗi migration cho hệ thống **Smart Apartment Management (QUANLY_TOANHA-DANCU)**.

---

## 1. Trạng Thái Cấu Hình Hiện Tại (Current Status)

| Thành phần | Trạng Thái Thực Tế | Chi Tiết |
|---|---|---|
| **Cơ sở dữ liệu Production** | External Managed MySQL | Vercel kết nối ra CSDL MySQL bên ngoài (Managed Cloud) |
| **Cơ chế sao lưu tự động** | `BACKUP_NOT_CONFIGURED` *(ở cấp app script)* | Ứng dụng Laravel không lưu trữ backup dump nội bộ; việc sao lưu phải được cấu hình tại tầng Cloud Database Provider |
| **Cơ chế Migration Production** | `CONTROLLED_PIPELINE` | Chạy qua pipeline GitHub Actions với `php artisan migrate --force --no-interaction` |
| **Cơ chế rollback CSDL** | `STEP_ROLLBACK` | `php artisan migrate:rollback --step=1` |

> ⚠️ **CẢNH BÁO BẢO MẬT & DỮ LIỆU**:
> - Tuyệt đối **KHÔNG chạy `php artisan migrate:fresh` hoặc `php artisan db:wipe`** trên môi trường Production hoặc Staging chứa dữ liệu thật.
> - Tuyệt đối **KHÔNG chạy seeder dữ liệu giả (`db:seed`)** trên Production.

---

## 2. Tiêu Chuẩn Sao Lưu Khuyến Nghị (Recommended Backup Standards)

Đối với nhà cung cấp Managed MySQL (PlanetScale, AWS RDS, DigitalOcean, Supabase, Railway, Aiven):

```text
BACKUP_METHOD: Automated Daily Snapshot + Point-In-Time-Recovery (PITR)
BACKUP_LOCATION: Cloud Provider Encrypted Storage (Cross-region replication)
RETENTION: 30 days continuous WAL/binlog, 7 daily snapshots
RESTORE_METHOD: Point-In-Time Instance Restore hoặc mysqldump import
RESTORE_TEST: Định kỳ diễn tập phục hồi vào môi trường staging mỗi quý
```

### Sao lưu thủ công bằng mysqldump trước khi bảo trì lớn:
```bash
# Thực hiện sao lưu schema và dữ liệu từ máy trạm an toàn:
mysqldump -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USERNAME" -p"$DB_PASSWORD" \
  --single-transaction \
  --quick \
  --routines \
  --triggers \
  "$DB_DATABASE" | gzip > "backup_${DB_DATABASE}_$(date +%Y%m%d_%H%M%S).sql.gz"
```

---

## 3. Chiến Lược Migration Tương Thích Ngược (Expand & Contract)

Mọi thay đổi CSDL trên Production phải tuân thủ mô hình **Expand & Contract** để bảo đảm ứng dụng phiên bản cũ và phiên bản mới đều có thể chạy song song mà không bị gián đoạn:

```text
Giai đoạn 1 (Expand):
  Thêm cột mới, bảng mới (nullable hoặc có default value hợp lý).
  Triển khai code mới tương thích đọc/ghi đồng thời cả cột cũ và mới.

Giai đoạn 2 (Migrate Data):
  Chạy background command di chuyển dữ liệu cũ sang định dạng mới.

Giai đoạn 3 (Switch Behavior):
  Chuyển hẳn ứng dụng sang sử dụng schema mới.

Giai đoạn 4 (Contract):
  Sau khi ổn định ít nhất 1-2 chu kỳ phát hành, mới tạo migration xóa cột/bảng cũ.
```

### Các migration nguy hiểm cần kiểm duyệt đặc biệt:
- **Đổi tên cột (`renameColumn`)**: Phá vỡ ngay lập tức các câu truy vấn từ container cũ đang phục vụ request.
- **Xóa cột (`dropColumn`)**: Gây lỗi `Column not found` nếu phiên bản code cũ vẫn đang chạy.
- **Thêm cột `NOT NULL` không có `default`**: Gây lỗi khi code cũ insert bản ghi mà không truyền trường mới.
- **Thay đổi kiểu dữ liệu (`change`)**: Có thể khóa bảng (table lock) lâu trên dữ liệu lớn.

---

## 4. Quy Trình Xử Lý Sự Cố (Emergency Procedures)

### 4.1. Sự cố 1: Migration Pipeline thất bại
1. Pipeline dừng lại ngay lập tức tại bước `production-migration`.
2. Vercel deployment sẽ **KHÔNG** được kích hoạt (Application code giữ nguyên bản ổn định).
3. Kiểm tra log chi tiết:
   ```bash
   php artisan migrate:status
   ```
4. Nếu lỗi do cú pháp SQL migration, rollback 1 bước:
   ```bash
   php artisan migrate:rollback --step=1 --force
   ```
5. Sửa lỗi trên nhánh tính năng, kiểm thử lại trong CI trước khi merge lại vào `master`.

### 4.2. Sự cố 2: Application Deploy thành công nhưng xuất hiện lỗi CSDL diện rộng
1. Kích hoạt Rollback tức thì trên Vercel:
   - Truy cập **Vercel Dashboard** → **Deployments** → Chọn bản deploy ổn định gần nhất trước đó → **Promote to Production**.
2. Đánh giá tính tương thích của schema đã migrate:
   - Nếu migration tuân thủ nguyên tắc Expand: Code cũ vẫn hoạt động bình thường, **không cần rollback CSDL**.
   - Nếu migration phá vỡ logic cũ: Thực hiện rollback migration tương ứng:
     ```bash
     php artisan migrate:rollback --step=1 --force
     ```

### 4.3. Sự cố 3: Dữ liệu bị hỏng hoặc mất mát ngoài ý muốn
1. Bật chế độ bảo trì hệ thống ngay lập tức:
   ```bash
   php artisan down --secret="emergency-bypass-token" --render="errors.503"
   ```
2. Xác định thời điểm xảy ra sự cố (Timestamp $T$).
3. Thực hiện phục hồi Point-In-Time (PITR) trên Cloud Database Provider về thời điểm $T - 1$ phút.
4. Kiểm tra tính toàn vẹn dữ liệu:
   ```bash
   php artisan db-health
   ```
5. Tắt chế độ bảo trì:
   ```bash
   php artisan up
   ```

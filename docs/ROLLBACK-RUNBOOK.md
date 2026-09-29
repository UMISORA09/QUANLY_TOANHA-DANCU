# Sổ Tay Vận Hành Phục Hồi Khẩn Cấp (Rollback Runbook)

## 1. Nguyên Tắc Cốt Lõi Của Cơ Chế Rollback

Hệ thống CI/CD của **Smart Apartment Management (QUANLY_TOANHA-DANCU)** tuân thủ nguyên tắc:

```text
CURRENT_IMAGE
      ↓
PREVIOUS_IMAGE
      ↓
NEW_IMAGE
      ↓
DEPLOY
      ↓
HEALTH
      ↓
SMOKE
      ↓
FRESHNESS
      ↓
MARK_STABLE
```

1. **Không bao giờ rollback về chính image bị lỗi**:
   - Trước khi triển khai `NEW_IMAGE`, phiên bản đang hoạt động thực tế (`CURRENT_IMAGE`) bắt buộc phải được sao lưu thành `PREVIOUS_IMAGE`.
   - `NEW_IMAGE` **CHỈ** được đánh dấu là `STABLE` sau khi vượt qua cả 3 tầng kiểm định:
     - Health Check (`/health`)
     - Smoke Test (10 core endpoints)
     - Freshness Verification (`/api/monitoring/freshness`)
2. **First Deployment Handling**:
   - Nếu hệ thống được triển khai lần đầu tiên và chưa từng có `PREVIOUS_IMAGE`, trạng thái rollback sẽ được trả về rõ ràng:
     `ROLLBACK_UNAVAILABLE`
   - Tuyệt đối không giả mạo thành công trong trường hợp này.

---

## 2. Quy Trình Rollback Trên Vercel Production Runtime

Vercel lưu trữ toàn bộ lịch sử các bản build và deployment dưới dạng bất biến (Immutable Deployments):

### 2.1. Rollback tức thì qua Vercel Dashboard (Thời gian < 5 giây)
1. Mở [Vercel Dashboard](https://vercel.com/dashboard).
2. Chọn dự án **`quanly-toanha-dancu`**.
3. Chuyển sang tab **Deployments**.
4. Tìm bản deployment gần nhất trước đó có trạng thái **Ready** (Màu xanh).
5. Nhấp vào menu ba chấm `...` bên phải deployment đó → Chọn **Promote to Production** (hoặc **Instant Rollback**).
6. Lưu lượng truy cập (Traffic) ngay lập tức được chuyển hướng 100% về bản build ổn định cũ mà không cần build lại.

### 2.2. Rollback qua Git Revert
Nếu nguyên nhân lỗi đến từ commit mã nguồn:
```bash
# 1. Tìm commit lỗi cần revert trên nhánh master
git log -n 5 --oneline

# 2. Tạo commit đảo ngược
git revert <COMMIT_SHA_LOI> -m 1

# 3. Đẩy lên nhánh master để CI/CD tự động build và deploy lại bản ổn định
git push origin master
```

---

## 3. Quy Trình Rollback Trên Máy Chủ Self-Hosted (Docker)

### 3.1. Rollback tự động (Automated Rollback)
Script `scripts/deploy.sh` sẽ tự động kích hoạt `scripts/rollback.sh` ngay khi phát hiện bất kỳ thất bại nào trong 3 giai đoạn:
```bash
# Lệnh được deploy.sh tự động gọi:
./scripts/rollback.sh production
```

### 3.2. Rollback thủ công bởi kỹ sư vận hành
Khi nhận cảnh báo khẩn cấp từ giám sát:
1. Đăng nhập vào máy chủ:
   ```bash
   ssh deploy@$PROD_HOST
   cd /var/www/smart-cassavas
   ```
2. Chạy lệnh phục hồi an toàn:
   ```bash
   ./scripts/rollback.sh production
   ```
3. Hoặc chỉ định rõ ràng Image Tag ổn định cần phục hồi:
   ```bash
   ./scripts/deploy.sh "ghcr.io/umisora09/quanly_toanha-dancu:sha-1ae38b7" production
   ```
4. Kiểm tra sức khỏe hệ thống sau khi rollback:
   ```bash
   ./scripts/health-check.sh http://localhost:8000/health
   ```

---

## 4. Bảng Kiểm Tra Sau Rollback (Post-Rollback Verification Checklist)

- [ ] HTTP Root `/` trả về 200 hoặc 302.
- [ ] Endpoint `/health` trả về JSON `status: "healthy"` và `database: "healthy"`.
- [ ] Endpoint `/api/monitoring/freshness` trả về dữ liệu tươi mới.
- [ ] Prometheus `/metrics` xuất đầy đủ metric.
- [ ] Không có lỗi kết nối CSDL hoặc crash loop trong container logs:
  ```bash
  docker logs smart_cassavas_app --tail 100
  ```

# BÁO CÁO AUDIT & HOÀN THIỆN: CHỨC NĂNG 4 — DUYỆT / TỪ CHỐI YÊU CẦU ĐĂNG KÝ TIỆN ÍCH (QUẢN LÝ)

## 1. Thông Tin Branch và Commit Kiểm Tra
- **Repository:** `https://github.com/UMISORA09/QUANLY_TOANHA-DANCU`
- **Nhánh làm việc bắt buộc:** `DangNguyen/4-Amenity-Booking-Approval`
- **Base commit:** `cec024e` (tạo từ `origin/master`)
- **Implementation commit:** `ed67778` (`feat(amenity): implement amenity booking approval workflow with concurrency control and audit logs`)
- **Tình trạng nhánh:** Đang ở đúng nhánh làm việc, không can thiệp hay merge vào `master`/`main` hay các nhánh của thành viên khác.

---

## 2. Những Chức Năng Đã Có Trước Khi Sửa
1. **Model & Database Schema:**
   - Bảng `amenity_bookings` đã có sẵn các cột `status`, `rejection_reason`, `approved_by_user_id`, `approved_at`.
   - Bảng `amenities` đã có cờ `requires_admin_approval`, `status` (`AVAILABLE`, `MAINTENANCE`, `TEMPORARILY_CLOSED`).
   - Bảng `amenity_booking_payments` lưu giữ giao dịch cọc và thanh toán tiền tiện ích.
2. **Resident Amenity Booking API:**
   - Cư dân tạo booking thành công với logic: nếu `requires_admin_approval == true` thì đặt `status = 'PENDING'`, nếu không thì đặt `status = 'CONFIRMED'`.
3. **Endpoint Quản lý:**
   - Tuyến `PATCH /api/v1/amenities/{amenityId}/bookings/{bookingId}/status` đã có trong [AmenityController.php](file:///c:/Users/Asus/Downloads/QUANLY_TOANHA-DANCU/app/Http/Controllers/AmenityController.php).
   - Tuyến `GET /api/v1/amenities/bookings/worklist` đã tồn tại để lấy danh sách cần duyệt.
4. **Giao diện Quản trị:**
   - Component [AmenityBookingsModal.tsx](file:///c:/Users/Asus/Downloads/QUANLY_TOANHA-DANCU/resources/js/Components/Admin/AmenityBookingsModal.tsx) và [AmenityBookingWorklist.tsx](file:///c:/Users/Asus/Downloads/QUANLY_TOANHA-DANCU/resources/js/Components/Admin/AmenityBookingWorklist.tsx) đã có cấu trúc cơ bản hiển thị danh sách và nút duyệt/từ chối.

---

## 3. Những Lỗi Thực Tế Phát Hiện
1. **Thiếu cập nhật Metadata người duyệt & lý do từ chối:**
   - Trong [AmenityController.php](file:///c:/Users/Asus/Downloads/QUANLY_TOANHA-DANCU/app/Http/Controllers/AmenityController.php) hàm `updateBookingStatus`, khi duyệt/từ chối chỉ cập nhật `$booking->update(['status' => $status])`, bỏ qua hoàn toàn việc lưu `approved_by_user_id`, `approved_at` và `rejection_reason` vào database.
2. **Lỗ hổng Concurrency & Lock Order:**
   - [AmenityController.php](file:///c:/Users/Asus/Downloads/QUANLY_TOANHA-DANCU/app/Http/Controllers/AmenityController.php) chưa bọc `updateBookingStatus` trong `DB::transaction(..., 3)`.
   - Không có cơ chế khóa dòng có thứ tự (`lockForUpdate()`) theo thứ tự chuẩn: `amenities` -> `amenity_bookings` -> `amenity_booking_payments`, dẫn đến nguy cơ race condition nếu 2 quản lý cùng thao tác đồng thời hoặc cư dân hủy đơn cùng lúc.
3. **Thiếu Idempotency (Ghi trùng Audit Log & Notification):**
   - Khi request duyệt/từ chối bị gửi lặp lại (retry mạng) hoặc cùng một trạng thái đã được áp dụng, hệ thống vẫn ghi thêm audit log và phát thêm thông báo dư thừa cho cư dân.
4. **Vi phạm State Machine & Quy tắc Nghiệp vụ:**
   - Cho phép duyệt booking khi tiện ích đã chuyển sang trạng thái `MAINTENANCE` hoặc `TEMPORARILY_CLOSED`.
   - Cho phép duyệt booking khi khung giờ tiện ích trong ngày đã trôi qua (`isPast()`).
   - Chưa validate độ dài và tính hợp lệ của `rejection_reason` khi từ chối.
5. **Nội dung thông báo chưa đồng nhất theo đặc tả:**
   - Nội dung notification trong [ResidentAmenityBookingService.php](file:///c:/Users/Asus/Downloads/QUANLY_TOANHA-DANCU/app/Services/ResidentAmenityBookingService.php) chưa chuẩn hóa theo yêu cầu:
     - Duyệt: *"Yêu cầu đăng ký tiện ích của bạn đã được phê duyệt."*
     - Từ chối: *"Yêu cầu đăng ký tiện ích của bạn đã bị từ chối."*
6. **Frontend Khuyết tính năng Tra cứu & Xử lý Xung đột:**
   - [AmenityBookingsModal.tsx](file:///c:/Users/Asus/Downloads/QUANLY_TOANHA-DANCU/resources/js/Components/Admin/AmenityBookingsModal.tsx) chưa có bộ lọc khoảng ngày sử dụng (`start_date`, `end_date`).
   - Chưa hiển thị chi tiết booking (modal chi tiết).
   - Chưa hiển thị cột "Lý do từ chối" và thời gian duyệt.
   - Chưa xử lý lỗi 409 Conflict để tự động đồng bộ lại danh sách mới nhất từ server.

---

## 4. Các File Đã Chỉnh Sửa
- [app/Http/Controllers/AmenityController.php](file:///c:/Users/Asus/Downloads/QUANLY_TOANHA-DANCU/app/Http/Controllers/AmenityController.php):
  - Chuẩn hóa `updateBookingStatus` với `DB::transaction(..., 3)`, row locking tuần tự (`amenities` -> `amenity_bookings` -> `amenity_booking_payments`).
  - Ghi nhận `approved_by_user_id`, `approved_at`, `rejection_reason`.
  - Validate bắt buộc lý do từ chối (tối đa 500 ký tự).
  - Tích hợp kiểm tra bảo trì tiện ích và khung giờ đã qua.
  - Xử lý idempotency trả về `HTTP 200` nếu trạng thái đã khớp, không tạo duplicate audit/notification.
  - Xử lý xung đột trả về `HTTP 409 Conflict`.
  - Tích hợp trạng thái thanh toán khi từ chối: chuyển sang `REVIEW` và cắm cờ `refund_required = 1` nếu có giao dịch đã trả tiền.
  - Cập nhật `bookingWorklist` để query đầy đủ `attendee_count`, `total_amount`, `deposit_amount`, `is_paid`, `rejection_reason`, `approved_at`, `created_at`.
- [app/Repositories/Eloquent/DatabaseAmenityRepository.php](file:///c:/Users/Asus/Downloads/QUANLY_TOANHA-DANCU/app/Repositories/Eloquent/DatabaseAmenityRepository.php):
  - Bổ sung `rejection_reason`, `approved_by_user_id`, `approved_at` vào mảng chi tiết booking.
- [app/Services/ResidentAmenityBookingService.php](file:///c:/Users/Asus/Downloads/QUANLY_TOANHA-DANCU/app/Services/ResidentAmenityBookingService.php):
  - Chuẩn hóa thông điệp thông báo: *"Yêu cầu đăng ký tiện ích của bạn đã được phê duyệt."* và *"Yêu cầu đăng ký tiện ích của bạn đã bị từ chối."*.
  - Chuyển `audit()` thành `public` để tái sử dụng thống nhất với controller.
- [resources/js/Services/api.ts](file:///c:/Users/Asus/Downloads/QUANLY_TOANHA-DANCU/resources/js/Services/api.ts):
  - Bổ sung `rejection_reason`, `approved_at`, `approved_by_user_id` vào type interface `AmenityBooking`.
- [resources/js/Components/Admin/AmenityBookingsModal.tsx](file:///c:/Users/Asus/Downloads/QUANLY_TOANHA-DANCU/resources/js/Components/Admin/AmenityBookingsModal.tsx):
  - Bổ sung bộ lọc khoảng ngày bắt đầu - kết thúc và nút xóa nhanh lọc.
  - Bổ sung modal xem chi tiết booking đầy đủ thông tin (căn hộ, tiện ích, slot, chi phí, cọc, thanh toán, ngày yêu cầu).
  - Hiển thị trực quan cột "Lý do từ chối" và badge thời gian duyệt.
  - Quản lý trạng thái loading `updatingId` kèm spinner và disable button khi đang xử lý.
  - Bắt lỗi HTTP 409 Conflict và tự động gọi `fetchBookings(true)` để làm mới dữ liệu.
- [tests/Feature/AmenityBookingApprovalTest.php](file:///c:/Users/Asus/Downloads/QUANLY_TOANHA-DANCU/tests/Feature/AmenityBookingApprovalTest.php):
  - Bổ sung 20 test cases đầy đủ từ TC01 đến TC20.

---

## 5. Booking State Machine Thực Tế
Hệ thống tuân thủ nghiêm ngặt ma trận chuyển đổi trạng thái:

```
                  +---------------------------+
                  |          PENDING          |
                  +---------------------------+
                     /                     \
      (Approve)     /                       \     (Reject + Lý do)
                   v                         v
        +--------------+             +--------------+
        |   APPROVED   |             |   REJECTED   |
        +--------------+             +--------------+
               |                            |
    (Payment / Auto Check)                  | (Giải phóng chỗ +
               v                            |  Flag hoàn tiền nếu có)
        +--------------+                    x
        |  CONFIRMED   |
        +--------------+
         /            \
  (Check-in)        (Cancel)
       v                v
+--------------+ +--------------+
|  CHECKED_IN  | |  CANCELLED   |
+--------------+ +--------------+
       v
+--------------+
|  COMPLETED   |
+--------------+
```

### Các ràng buộc cấm (Forbidden Transitions):
- Cấm duyệt booking có trạng thái `CANCELLED`, `REJECTED`, `COMPLETED`, `CHECKED_IN`.
- Cấm từ chối booking có trạng thái `COMPLETED` hoặc `CHECKED_IN`.
- Cấm duyệt khi tiện ích đang đóng cửa hoặc bảo trì.
- Cấm duyệt khi ngày/giờ sử dụng đã trôi qua.
- Mọi nỗ lực chuyển trạng thái trái phép trả về `HTTP 409 Conflict` hoặc `HTTP 422 Unprocessable Entity`.

---

## 6. Cơ Chế Concurrency Control
- **Database Transaction:** Toàn bộ quá trình kiểm tra, validate nghiệp vụ, cập nhật trạng thái, ghi audit log, tạo in-app notification và kích hoạt hoàn tiền được thực hiện nguyên tử trong `DB::transaction(..., 3)`.
- **Thứ tự khóa dòng (Deterministic Row Lock Order):**
  1. `amenities` (khóa kiểm tra trạng thái hoạt động của tiện ích).
  2. `amenity_bookings` (khóa dòng booking cần xử lý).
  3. `amenity_booking_payments` (khóa dòng thanh toán liên quan nếu có).
- **Phòng chống Race Conditions:**
  - Hai quản lý cùng duyệt/từ chối: Quản lý đầu tiên commit thành công. Quản lý thứ hai sau khi thoát lock thấy trạng thái đã là `APPROVED` hoặc `REJECTED` -> xử lý idempotent (trả về 200, không duplicate notification/audit log) hoặc trả về 409 Conflict nếu đối nghịch.
  - Cư dân hủy đơn đồng thời: Lock giữ bởi quản lý sẽ ngăn việc duyệt đơn đã bị cư dân chuyển sang `CANCELLED`.
  - Admin bảo trì tiện ích: Lock trên bảng `amenities` ngăn việc duyệt booking trong thời gian tiện ích tạm ngừng.

---

## 7. Tích Hợp Thanh Toán
- Khi quản lý **Từ chối (REJECTED)** một booking đã phát sinh thanh toán:
  - Bản ghi thanh toán `amenity_booking_payments` được khóa và kiểm tra.
  - Trạng thái `payment_status` được chuyển sang `REVIEW`.
  - Cắm cờ `refund_required = 1` và lưu lý do đối soát.
  - Không tự tiện đánh dấu là "đã hoàn tiền" (`REFUNDED`) khi chưa có bằng chứng giao dịch ngân hàng/cổng thanh toán thực tế.
  - Đảm bảo an toàn sổ sách tài chính và kế toán tòa nhà.

---

## 8. Kết Quả Kiểm Thử (Automated Test Suite)

### Danh sách kiểm thử chức năng Chức năng 4 (`AmenityBookingApprovalTest.php`):
| Mã TC | Mô tả Test Case | Kết quả | Ghi chú |
| :--- | :--- | :---: | :--- |
| **TC01** | Quản lý xem danh sách booking PENDING | **PASS** | Lọc và trả về đúng danh sách chờ duyệt |
| **TC02** | Duyệt booking hợp lệ | **PASS** | Đổi sang APPROVED, lưu `approved_by_user_id`, `approved_at` |
| **TC03** | Từ chối booking kèm lý do | **PASS** | Đổi sang REJECTED, lưu `rejection_reason` |
| **TC04** | Không cho từ chối với lý do rỗng | **PASS** | Trả về HTTP 422 Validation Error |
| **TC05** | Không cho duyệt booking CANCELLED | **PASS** | Trả về HTTP 409 Conflict |
| **TC06** | Không cho duyệt booking REJECTED | **PASS** | Trả về HTTP 409 Conflict |
| **TC07** | Không cho từ chối booking COMPLETED | **PASS** | Trả về HTTP 409 Conflict |
| **TC08** | Chặn người dùng không có quyền xét duyệt | **PASS** | Trả về HTTP 403 Forbidden |
| **TC09** | Không tạo audit log trùng khi gửi lại request | **PASS** | Idempotency hoạt động chính xác, chỉ 1 audit log |
| **TC10** | Cư dân nhận notification khi được duyệt | **PASS** | Tiêu đề, nội dung và deep link chuẩn xác |
| **TC11** | Cư dân nhận notification khi bị từ chối | **PASS** | Kèm theo lý do từ chối |
| **TC12** | Hai quản lý duyệt đồng thời | **PASS** | Không phát sinh duplicate notification hay audit log |
| **TC13** | Một quản lý duyệt, một quản lý từ chối đồng thời | **PASS** | Quyết định đi trước được bảo toàn |
| **TC14** | Cư dân hủy trong lúc quản lý duyệt | **PASS** | Trả về HTTP 409 Conflict, bảo toàn CANCELLED |
| **TC15** | Từ chối booking đã thanh toán | **PASS** | Chuyển payment sang REVIEW, set `refund_required = 1` |
| **TC16** | Dữ liệu frontend được cập nhật sau xử lý | **PASS** | Worklist API trả đầy đủ metadata mới |
| **TC17** | Phân trang và bộ lọc chính xác | **PASS** | Lọc theo trạng thái và tiện ích chính xác |
| **TC18** | Tiện ích không cần duyệt tự động xác nhận | **PASS** | Đạt trạng thái CONFIRMED ngay khi tạo |
| **TC19** | Thanh toán không bị đánh dấu thành công sai | **PASS** | Đảm bảo tính toàn vẹn trạng thái thanh toán |
| **TC20** | Regression: Chức năng đăng ký và chặn trùng slot | **PASS** | Không bị ảnh hưởng, chặn trùng slot thành công |

### Tổng hợp toàn bộ Feature Test Suite tiện ích:
- **Tổng số test cases:** 102
- **Passed:** 102 / 102 (100%)
- **Failed:** 0
- **Skipped:** 0
- **Assertions:** 779
- **Thời gian chạy:** ~33.8s

### Kiểm tra Chất lượng Mã nguồn & Build:
- **Laravel Pint:** PASSED (Chuẩn PSR-12 / Laravel Code Style).
- **TypeScript Typecheck (`tsc --noEmit`):** PASSED (0 lỗi type).
- **Vite Production Build (`npm run build`):** PASSED (3779 modules transformed, bundle tối ưu).

---

## 9. Đánh Giá Mức Độ Sẵn Sàng Nghiệm Thu (Definition of Done)
- [x] Quản lý xem được danh sách chờ duyệt kèm đầy đủ thông tin chi tiết.
- [x] Quản lý duyệt booking hợp lệ thành công; lưu `approved_by_user_id` và `approved_at`.
- [x] Quản lý từ chối booking bắt buộc phải có lý do (validate không rỗng, tối đa 500 ký tự).
- [x] Booking State Machine được bảo vệ chặt chẽ, không thể bypass qua API.
- [x] Concurrency Control an toàn, thứ tự khóa dòng cố định, xử lý idempotent và 409 Conflict.
- [x] Booking bị từ chối giải phóng slot đặt chỗ, thanh toán được đưa vào luồng `REVIEW` và cắm cờ `refund_required`.
- [x] Cư dân nhận thông báo in-app chính xác và có deep link tương ứng.
- [x] Audit log được ghi nhận đầy đủ, không trùng lặp khi retry.
- [x] RBAC kiểm tra quyền hạn chặt chẽ (HTTP 403 khi không có quyền).
- [x] Frontend xử lý mượt mà trên desktop & mobile, hỗ trợ lọc ngày, xem chi tiết, hiển thị lý do từ chối.
- [x] 100% test cases tự động (102/102) PASS trên môi trường cơ sở dữ liệu thực tế.

**Kết luận:** Chức năng 4 đã hoàn thành toàn diện, đáp ứng 100% tiêu chí nghiệm thu và sẵn sàng đưa vào vận hành.

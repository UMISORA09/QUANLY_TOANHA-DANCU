# PHÂN TÍCH HỆ THỐNG: QUẢN LÝ DANH MỤC TIỆN ÍCH & CẤU HÌNH SLOT TỐI ĐA (ADMIN)

## 1. Hiện trạng Kiến trúc Tổng thể (Existing Architecture)
- **Hệ thống gốc**: Ban đầu được khởi tạo với ngăn xếp Laravel 12 + React 19 (TypeScript) + Vite 8 + Tailwind CSS v4 + Microsoft SQL Server 2022.
- **Yêu cầu phân hệ Quản lý Tiện ích**: Kiến trúc chuẩn hóa theo mô hình phân tách rõ ràng:
  - **Frontend**: React 19 (TypeScript) mở rộng trực tiếp trên ứng dụng hiện có (`resources/js`), tận dụng Tailwind CSS, Lucide icons, glassmorphism design system đồng bộ.
  - **Backend**: Python FastAPI REST API (`backend/`), chạy Uvicorn, cấu trúc phân tầng rõ ràng (Router → Service → Repository → SQL Server).
  - **Database**: Microsoft SQL Server (`CSDL_CHUNGCU&DANCU`), kết nối thực qua PyODBC / SQLAlchemy với driver `ODBC Driver 17 for SQL Server`.

## 2. Cấu trúc React Hiện tại (Existing React Structure)
- **Điểm khởi chạy**: `resources/js/app.tsx` khởi tạo React root tại `#app`, quản lý routing dạng SPA bằng `window.location.pathname` và `popstate`.
- **Trang hiện tại**:
  - `resources/js/Pages/Home.tsx`: Trang chủ với UI glassmorphism, 3D Architectural Digital Twin, hiển thị tổng quan tòa nhà.
  - `resources/js/Pages/NotFound.tsx`: Trang 404 tùy biến hiệu ứng kính mờ.
  - `resources/js/Components/AuthModal.tsx`: Hộp thoại đăng nhập/đăng ký với demo vai trò (Manager, Resident, Receptionist, Admin).
- **Styling**: `resources/css/app.css` với Tailwind CSS 4, hiệu ứng `glass-auth-card`, `glass-role-card`, animations aurora, gradients cao cấp.

## 3. Cấu trúc FastAPI Backend (FastAPI Structure)
- Phân tầng tối giản theo nguyên lý Ponytail:
  ```text
  backend/
  ├── app/
  │   ├── core/
  │   │   ├── config.py         # Cấu hình môi trường (DB, JWT, CORS)
  │   │   ├── database.py       # Kết nối SQL Server (SessionLocal, Engine)
  │   │   ├── security.py       # JWT decode, hash check, token dependencies
  │   │   └── audit.py          # Service ghi nhận nhật ký hệ thống audit_logs
  │   ├── models/               # SQLAlchemy Models map đúng 100% bảng SQL Server
  │   │   ├── amenity.py        # AmenityCategory, Amenity, AmenityTimeSlot, AmenityBlackout, AmenityBooking
  │   │   ├── auth.py           # User, Role, Permission, UserRole, RolePermission
  │   │   ├── building.py       # Block, Floor, Apartment
  │   │   └── audit.py          # AuditLog
  │   ├── schemas/              # Pydantic Schemas (Request/Response validation)
  │   │   └── amenity.py        # Category, Amenity, TimeSlot, Blackout schemas
  │   ├── repositories/         # Tầng truy xuất CSDL SQL Server & Transactions
  │   │   └── amenity_repo.py
  │   ├── services/             # Nghiệp vụ (Business rules, overlap check, audit trail)
  │   │   └── amenity_service.py
  │   ├── routers/              # HTTP Endpoints, RBAC, Status codes
  │   │   ├── auth.py           # /api/v1/auth (Login & current user permissions)
  │   │   ├── admin_amenities.py# /api/v1/admin/amenities & sub-resources
  │   │   └── public_meta.py    # Danh mục blocks, options phục vụ dropdown
  │   └── main.py               # FastAPI application, CORS middleware, Exception Handlers, Swagger docs
  ├── tests/                    # Pytest test suite toàn diện
  └── run.py                    # Script khởi chạy FastAPI (mặc định port 8001 / proxy qua 8000)
  ```

## 4. Mô hình Truy cập Cơ sở Dữ liệu (Database-Access Pattern)
- Database: Microsoft SQL Server (`CSDL_CHUNGCU&DANCU`).
- Driver: `ODBC Driver 17 for SQL Server` thông qua `pyodbc` và `SQLAlchemy`.
- Hỗ trợ cả Windows Authentication (`Trusted_Connection=yes`) cho local development SQLEXPRESS và SQL Server Authentication (`sa` / `SmartCassavas@2026`) cho Docker/Production.
- Connection Pooling và transaction commit/rollback rõ ràng trong Repository layer.

## 5. Hiện trạng Xác thực (Authentication)
- Bảng `users` trong CSDL lưu trữ `username`, `phone_number`, `email`, `password_hash`, `status`, `mfa_enabled`.
- Cơ chế xác thực: JSON Web Token (JWT) Bearer Token kèm endpoint `/api/v1/auth/login`.
- Nếu request không có Bearer Token hợp lệ → HTTP 401 Unauthorized.

## 6. Hiện trạng Phân quyền (RBAC - Role-Based Access Control)
- CSDL đã có sẵn 10 vai trò chuẩn trong bảng `roles` (`SUPER_ADMIN`, `BUILDING_MANAGER`, `RESIDENT_OWNER`, v.v.).
- Bảng `permissions` và `role_permissions` lưu trữ quyền hạn chi tiết.
- Các quyền nghiệp vụ tiện ích:
  - `AMENITY:READ`
  - `AMENITY:CREATE`
  - `AMENITY:UPDATE`
  - `AMENITY:DELETE`
  - `AMENITY:CONFIGURE_SLOT`
  - `AMENITY:CONFIGURE_BLACKOUT`
- Cấp quyền cho `SUPER_ADMIN` và `BUILDING_MANAGER`.
- Dependency `require_permission(permission_code)` trong FastAPI kiểm tra quyền của user:
  - Không có token: 401
  - Có token nhưng vai trò/quyền không đủ (vd Resident): 403 Forbidden
  - Hợp lệ: tiếp tục xử lý nghiệp vụ.

## 7. Cấu trúc Bảng Tiện ích Hiện có trong CSDL (Không tạo trùng bảng)
Bảng có sẵn trong CSDL_CHUNGCU&DANCU:
1. `amenity_categories`: id, category_name, category_code, icon_name, description, created_at.
2. `amenities`: id, category_id, block_id, amenity_name, amenity_code, location_detail, max_capacity_per_slot, hourly_rate, security_deposit_required, advance_booking_days_limit, min_cancel_hours_before, requires_admin_approval, rules_and_regulations, cover_image_url, gallery_images (JSON), is_active, created_at, updated_at, deleted_at.
3. `amenity_time_slots`: id, amenity_id, day_of_week (0-6), slot_start_time, slot_end_time, slot_label, max_bookings, is_active, created_at.
4. `amenity_blackouts`: id, amenity_id, blackout_date, start_time, end_time, reason, created_by, created_at.
5. `amenity_bookings`: id, booking_code, amenity_id, apartment_id, resident_user_id, booking_date, start_time, end_time, attendee_count, total_amount, deposit_amount, status, checkin_qr_code, v.v.

## 8. Thành phần Giao diện Có thể Tái sử dụng (Reusable Components)
- `MorphIcon` từ `morphicons/react` và các biểu tượng từ `lucide-react`.
- Glassmorphism styling tokens: `glass-auth-card`, `glass-input`, atmospheric aurora effects.
- Modal patterns, Toast notifications, Dropdowns, Card layouts.

## 9. Các Tệp Sẽ Tạo Mới (Files to Create)
- **Backend**:
  - `backend/app/core/config.py`
  - `backend/app/core/database.py`
  - `backend/app/core/security.py`
  - `backend/app/core/audit.py`
  - `backend/app/models/amenity.py`
  - `backend/app/models/auth.py`
  - `backend/app/models/building.py`
  - `backend/app/schemas/amenity.py`
  - `backend/app/repositories/amenity_repo.py`
  - `backend/app/services/amenity_service.py`
  - `backend/app/routers/auth.py`
  - `backend/app/routers/admin_amenities.py`
  - `backend/app/routers/meta.py`
  - `backend/app/main.py`
  - `backend/tests/test_amenity_api.py`
  - `backend/tests/test_business_rules.py`
- **Frontend**:
  - `resources/js/Services/api.ts` (API Client kết nối REST API)
  - `resources/js/Pages/Admin/AmenityManagement.tsx` (Trang Quản lý Tiện ích Admin)
  - `resources/js/Components/Admin/AmenityFormModal.tsx` (Form tạo/sửa tiện ích)
  - `resources/js/Components/Admin/CategoryModal.tsx` (Modal quản lý danh mục)
  - `resources/js/Components/Admin/TimeSlotModal.tsx` (Modal cấu hình khung giờ)
  - `resources/js/Components/Admin/BlackoutModal.tsx` (Modal cấu hình ngày đóng cửa)
  - `resources/js/Components/Admin/ConfirmDialog.tsx` (Dialog xác nhận xóa/đổi trạng thái)

## 10. Các Tệp Cần Chỉnh sửa (Files to Modify)
- `resources/js/app.tsx`: Thêm route `/admin/amenities` dẫn tới `AmenityManagement`.
- `resources/js/Pages/Home.tsx`: Tích hợp link điều hướng "Quản lý tiện ích" cho tài khoản Admin/Manager.
- `routes/web.php`: Thêm fallback route cho `/admin/amenities` và `/admin/amenities/{any}` để phục vụ SPA loading khi F5.
- `vite.config.js`: Cấu hình proxy sang backend FastAPI (`http://127.0.0.1:8001/api`) hoặc CORS trực tiếp.

## 11. Thiết kế REST API (API Design)
Tất cả endpoint đặt dưới tiền tố `/api/v1`:
- **Auth**:
  - `POST /api/v1/auth/login`: Xác thực đăng nhập bằng email/sđt, trả về JWT token kèm user/roles/permissions.
  - `GET  /api/v1/auth/me`: Thông tin người dùng hiện tại và danh sách quyền hạn.
- **Categories**:
  - `GET    /api/v1/admin/amenity-categories`
  - `GET    /api/v1/admin/amenity-categories/{category_id}`
  - `POST   /api/v1/admin/amenity-categories`
  - `PUT    /api/v1/admin/amenity-categories/{category_id}`
  - `DELETE /api/v1/admin/amenity-categories/{category_id}`
- **Amenities**:
  - `GET    /api/v1/admin/amenities` (hỗ trợ search, category_id, block_id, is_active, page, limit, sort)
  - `GET    /api/v1/admin/amenities/{amenity_id}`
  - `POST   /api/v1/admin/amenities`
  - `PUT    /api/v1/admin/amenities/{amenity_id}`
  - `PATCH  /api/v1/admin/amenities/{amenity_id}/status`
  - `DELETE /api/v1/admin/amenities/{amenity_id}` (soft-delete bảo vệ dữ liệu lịch sử)
- **Time Slots**:
  - `GET    /api/v1/admin/amenities/{amenity_id}/time-slots`
  - `POST   /api/v1/admin/amenities/{amenity_id}/time-slots`
  - `PUT    /api/v1/admin/amenities/{amenity_id}/time-slots/{slot_id}`
  - `PATCH  /api/v1/admin/amenities/{amenity_id}/time-slots/{slot_id}/status`
  - `DELETE /api/v1/admin/amenities/{amenity_id}/time-slots/{slot_id}`
- **Blackout Dates**:
  - `GET    /api/v1/admin/amenities/{amenity_id}/blackouts`
  - `POST   /api/v1/admin/amenities/{amenity_id}/blackouts`
  - `PUT    /api/v1/admin/amenities/{amenity_id}/blackouts/{blackout_id}`
  - `DELETE /api/v1/admin/amenities/{amenity_id}/blackouts/{blackout_id}`
- **Meta / Options**:
  - `GET    /api/v1/meta/blocks`: Lấy danh sách tòa nhà phục vụ form chọn tiện ích.

## 12. Quy tắc Nghiệp vụ (Business Rules)
1. **Phân biệt Capacity vs Max Bookings**:
   - `amenities.max_capacity_per_slot`: Sức chứa tối đa số người trên 1 lượt đặt / khung giờ (VD: 20 người).
   - `amenity_time_slots.max_bookings`: Số lượt đăng ký đồng thời trong 1 khung giờ (VD: 1 slot = 1 hộ).
2. **Quy tắc Hiệu lực Cấu hình (Configuration Effective Rule)**:
   - Khi Admin thay đổi giá (`hourly_rate`), tiền cọc (`security_deposit_required`), sức chứa (`max_capacity_per_slot`), giới hạn đặt trước, v.v., cấu hình mới **chỉ áp dụng cho các lượt đặt phòng mới trong tương lai**.
   - Tuyệt đối không tự động tính toán lại, thay đổi chi phí, hủy hay cập nhật các bản ghi `amenity_bookings` hiện có trong lịch sử.
3. **Quy tắc Trạng thái Tiện ích (Amenity Status Rule)**:
   - Khi tiện ích chuyển sang `INACTIVE` (`is_active = 0`): không xóa tiện ích, không xóa hay hủy các booking đã có, chỉ chặn không cho tạo booking mới.
4. **Quy tắc Xóa an toàn (Safe Deletion)**:
   - Danh mục (`amenity_categories`) đang có tiện ích tham chiếu: Chặn xóa và trả về HTTP 409 Conflict.
   - Tiện ích (`amenities`) đang có dữ liệu booking: Thực hiện soft-delete (`deleted_at = NOW()`), không xóa cứng để đảm bảo toàn vẹn dữ liệu kế toán và nhật ký cư dân.

## 13. Quy tắc Kiểm định Dữ liệu (Validation Rules)
- **Category**: `category_name` bắt buộc, `category_code` bắt buộc và duy nhất trong hệ thống.
- **Amenity**:
  - `amenity_name`, `amenity_code`, `location_detail` không được rỗng.
  - `amenity_code` phải duy nhất.
  - `category_id` phải tồn tại trong CSDL.
  - `block_id` nếu cung cấp phải tồn tại trong bảng `blocks`.
  - `max_capacity_per_slot > 0`.
  - `hourly_rate >= 0`, `security_deposit_required >= 0`.
  - `advance_booking_days_limit >= 0`, `min_cancel_hours_before >= 0`.
- **Time Slot**:
  - `day_of_week` trong khoảng [0, 6] (0 = Chủ nhật, 1-6 = Thứ 2 - Thứ 7).
  - `slot_start_time < slot_end_time`.
  - `max_bookings > 0`.
  - **Chống trùng khung giờ (Overlap validation)**:
    Hai slot trong cùng 1 tiện ích và cùng ngày trong tuần không được giao nhau:
    `existing.slot_start_time < new.slot_end_time AND existing.slot_end_time > new.slot_start_time`.
- **Blackout**:
  - `reason` bắt buộc.
  - `start_time` và `end_time`: Hoặc cả hai cùng `null` (đóng cửa nguyên ngày), hoặc cả hai cùng có giá trị và `start_time < end_time`.

## 14. Tác động Cơ sở Dữ liệu (Database Impact)
- Sử dụng đúng 100% schema có sẵn trong `CSDL_CHUNGCU&DANCU.sql`.
- Không tạo thêm bảng mới, không đổi tên cột, không sửa đổi cấu trúc migration.
- Tận dụng khóa ngoại và trigger hệ thống có sẵn.

## 15. Yêu cầu Ghi nhật ký Hệ thống (Audit Trail Requirements)
- Mọi thao tác thay đổi (Tạo danh mục, Sửa danh mục, Xóa danh mục, Tạo tiện ích, Sửa tiện ích, Đổi trạng thái, Cấu hình slot, Cấu hình ngày bảo trì) đều được ghi tự động vào bảng `audit_logs` với:
  - `table_name`: Tên bảng bị tác động (`amenity_categories`, `amenities`, `amenity_time_slots`, `amenity_blackouts`).
  - `record_id`: UUID của bản ghi.
  - `action`: `INSERT`, `UPDATE`, `DELETE`, `STATUS_CHANGE`.
  - `performed_by_user_id`: ID người dùng thực hiện.
  - `old_data`: JSON snapshot trước khi sửa/xóa.
  - `new_data`: JSON snapshot sau khi tạo/sửa.
  - `changed_fields`: JSON danh sách các trường thay đổi.
  - `client_ip_address` & `user_agent`.

## 16. Giới hạn Đã biết (Known Limitations)
- Trigger CSDL hiện tại `dbo.trg_prevent_booking_overlap` đang kiểm tra xung đột thời gian đơn giản cho từng booking. Trường hợp tương lai cho phép `max_bookings > 1` (nhiều cư dân cùng đặt slot) sẽ cần logic kiểm soát đồng thời (concurrency control) nâng cao trong phân hệ Đăng ký sử dụng của Cư dân.

## 17. Xem xét Đồng thời (Concurrency Considerations)
- Ở phân hệ Admin hiện tại, cấu hình khung giờ và ngày bảo trì được thực hiện trong database transaction (ACID) có kiểm tra overlap.
- Việc xử lý tranh chấp đặt chỗ khi nhiều cư dân đăng ký đồng thời sẽ được thiết kế đầy đủ trong tính năng "Resident Booking + Concurrency Control".

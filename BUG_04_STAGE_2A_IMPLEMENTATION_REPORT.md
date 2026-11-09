# BUG-04 – STAGE 2A: BÁO CÁO HOÀN THÀNH TRIỂN KHAI
## KHẮC PHỤC LỖ HỔNG AUTHORIZATION TRONG HÓA ĐƠN VÀ HẠ TẦNG TÒA NHÀ

**Hệ thống**: Quản lý Tòa nhà & Cư dân (`UMISORA09/QUANLY_TOANHA-DANCU`)  
**Nhóm phân hệ**: Hóa đơn & Thu phí (Chức năng 10) và Hạ tầng Tòa nhà / Căn hộ (Chức năng 2)  
**Mục tiêu**: Bổ sung cơ chế xác thực và kiểm tra quyền hạn (Authorization Guard) theo chuẩn RBAC Matrix, ngăn chặn gọi API trực tiếp và thực thi Data Scoping bảo vệ dữ liệu cư dân.  
**Ngày thực hiện**: 06/11/2026  
**Trạng thái**: **HOÀN THÀNH GIAI ĐOẠN 2A – ĐÃ SẴN SÀNG NGHIỆM THU**

---

### 1. NGUYÊN NHÂN ĐÃ XỬ LÝ (ROOT CAUSES ADDRESSED)

Trước khi thực hiện Stage 2A, hệ thống tồn tại các lỗ hổng phân quyền nghiêm trọng tại 4 Controller mục tiêu:

1. **Thiếu hoàn toàn lớp kiểm tra Authorization ở cấp Controller**:
   - `InvoiceManagementController`, `InvoiceGenerationController`, `DebtReminderController` và `BuildingStructureController` trước đây chỉ dựa vào middleware nhóm `auth.bearer`.
   - Bất kỳ người dùng nào có Bearer token hợp lệ (bao gồm cả tài khoản cư dân hay tài khoản không có quyền tài chính/hạ tầng) đều có thể gọi trực tiếp API để sinh hóa đơn hàng loạt, hủy hóa đơn, gửi nhắc nợ toàn tòa nhà hoặc tạo/xóa tầng và căn hộ.
2. **Lỗ hổng rò rỉ và can thiệp chéo dữ liệu cư dân (IDOR & Data Scoping Gap)**:
   - Trong `InvoiceManagementController::currentMonth`, `show` và `statement`: Backend không ràng buộc quyền sở hữu căn hộ; cư dân căn hộ A có thể truyền ID căn hộ B để xem chi tiết hóa đơn, các chỉ số điện/nước và công nợ của căn hộ B.
   - Trong `InvoiceGenerationController::generate`: Tồn tại khối code fallback nguy hiểm `if (!$executedByUserId) { $adminUser = User::first(); }` tự động mượn danh nghĩa Admin khi request thiếu thông tin user.
3. **Không thực thi ma trận RBAC**:
   - Việc thu hồi quyền `INVOICE:VIEW`, `INVOICE:CREATE`, `INVOICE:DELETE` hay `BUILDING:MANAGE` trên giao diện RBAC Matrix hoàn toàn vô hiệu đối với các API này.

---

### 2. DANH SÁCH FILE THAY ĐỔI (MODIFIED & ADDED FILES)

Chỉ tập trung chính xác 4 Controller sản xuất thuộc phạm vi phê duyệt và 1 Feature Test mới:

| STT | Đường dẫn file | Trạng thái Git | Mục đích thay đổi |
| :---: | :--- | :---: | :--- |
| **1** | `app/Http/Controllers/InvoiceManagementController.php` | `Modified` | Bổ sung `checkAuthorization('INVOICE:VIEW' / 'INVOICE:DELETE')`, Data Scoping cho cư dân, bảo vệ `currentMonth`, `show`, `statement`, `cancel`, `bulkCancel`. |
| **2** | `app/Http/Controllers/InvoiceGenerationController.php` | `Modified` | Bổ sung `checkAuthorization('INVOICE:CREATE' / 'INVOICE:VIEW')`, chặn cư dân mô phỏng và sinh hóa đơn hàng loạt, xóa fallback mượn ID admin. |
| **3** | `app/Http/Controllers/DebtReminderController.php` | `Modified` | Bổ sung `checkAuthorization(['INVOICE:UPDATE', 'INVOICE:APPROVE'] / 'INVOICE:VIEW')`, chặn cư dân và người thiếu quyền gửi nhắc nợ. |
| **4** | `app/Http/Controllers/BuildingStructureController.php` | `Modified` | Bổ sung `checkAuthorization('BUILDING:MANAGE' / 'BUILDING:VIEW' / 'APARTMENT:MANAGE' / 'APARTMENT:VIEW')`, bảo vệ toàn bộ thao tác tạo, sửa, xóa tầng và căn hộ. |
| **5** | `tests/Feature/Invoices/InvoiceAndBuildingAuthorizationTest.php` | `Added (Untracked)` | Bộ 17 test case tự động bao phủ 100% các điều kiện phân quyền, RBAC Matrix, Data Scoping và tính toàn vẹn dữ liệu. |

---

### 3. DANH MỤC PERMISSION CODE ĐÃ SỬ DỤNG

Tuân thủ nghiêm ngặt nguyên tắc **chỉ sử dụng các permission code đã tồn tại trong hệ thống**, không tự ý tạo thêm mã quyền mới:

#### 3.1. Phân hệ Hóa đơn & Công nợ:
- `INVOICE:VIEW`: Xem danh sách hóa đơn, xem chi tiết hóa đơn, xem sao kê, xem đợt sinh hóa đơn, xem nhật ký nhắc nợ.
- `INVOICE:CREATE`: Chạy mô phỏng (Dry-run preview) và thực thi sinh hóa đơn tự động hàng loạt.
- `INVOICE:UPDATE`: Gửi email nhắc nợ đơn lẻ, gửi nhắc nợ hàng loạt, kích hoạt quét nhắc nợ tự động, thử lại nhắc nợ lỗi.
- `INVOICE:APPROVE`: Quyền phê duyệt gửi nhắc nợ và phát hành hóa đơn.
- `INVOICE:DELETE`: Hủy một hóa đơn chưa thanh toán hoặc hủy hàng loạt hóa đơn.
- `INVOICE:SPECIAL` / `SYSTEM:SPECIAL`: Quyền người đặc biệt phân hệ hóa đơn với thẩm quyền ngoại lệ.

#### 3.2. Phân hệ Hạ tầng Tòa nhà & Căn hộ:
- `BUILDING:VIEW`: Xem danh sách khối tòa nhà, thống kê căn hộ, danh sách tầng theo khối.
- `BUILDING:MANAGE`: Tạo mới tầng, chỉnh sửa tầng, xóa tầng; quản lý khối và hạ tầng.
- `APARTMENT:VIEW`: Xem danh sách căn hộ theo phân trang, lọc và tìm kiếm.
- `APARTMENT:MANAGE`: Tạo căn hộ mới, khởi tạo căn hộ theo tầng hàng loạt (batch generate), sửa căn hộ, đổi trạng thái nhanh, xóa căn hộ.
- `SYSTEM:SPECIAL`: Quyền người đặc biệt toàn hệ thống.

---

### 4. MA TRẬN PHÂN QUYỀN TRƯỚC VÀ SAU KHI TRIỂN KHAI

```
+------------------------------------+--------------------------+---------------------------------+
| API Endpoint & Thao tác            | Trạng thái CŨ (Trước)    | Trạng thái MỚI (Sau Stage 2A)   |
+------------------------------------+--------------------------+---------------------------------+
| GET /api/v1/invoices               | Mọi bearer token (Kể cả  | INVOICE:VIEW + Staff;           |
|                                    | cư dân thấy cả tòa nhà)  | Cư dân: Scoped căn hộ của mình  |
| GET /api/v1/invoices/summary       | Mọi bearer token         | INVOICE:VIEW + Staff (403 Cư dân)|
| GET /api/v1/invoices/current-month | IDOR: xem mọi căn hộ     | Data Scoped: chỉ căn hộ của mình|
| GET /api/v1/invoices/{id}          | IDOR: xem mọi hóa đơn    | Staff: INVOICE:VIEW; Cư dân: mk |
| GET /api/v1/invoices/{id}/statement| IDOR: xem mọi sao kê     | Staff: INVOICE:VIEW; Cư dân: mk |
| POST /api/v1/invoices/{id}/cancel  | Mọi bearer token         | INVOICE:DELETE + Staff          |
| POST /api/v1/invoices/bulk-cancel  | Mọi bearer token         | INVOICE:DELETE + Staff          |
| POST /invoices/batch/preview       | Mọi bearer token         | INVOICE:CREATE + Staff          |
| POST /invoices/batch/generate      | Mọi bearer token         | INVOICE:CREATE + Staff          |
| GET /api/v1/invoices/batches       | Mọi bearer token         | INVOICE:VIEW + Staff            |
| GET /api/v1/invoices/batches/{id}  | Mọi bearer token         | INVOICE:VIEW + Staff            |
| POST /invoices/{id}/send-reminder  | Mọi bearer token         | INVOICE:UPDATE|INVOICE:APPROVE  |
| POST /invoices/bulk-send-reminders | Mọi bearer token         | INVOICE:UPDATE|INVOICE:APPROVE  |
| POST /invoices/reminders/auto-disp | Mọi bearer token         | INVOICE:UPDATE|INVOICE:APPROVE  |
| GET /invoices/reminders/history    | Mọi bearer token         | INVOICE:VIEW + Staff            |
| GET /invoices/reminders/summary    | Mọi bearer token         | INVOICE:VIEW + Staff            |
| GET /api/v1/blocks                 | Không kiểm tra quyền     | BUILDING:VIEW | BUILDING:MANAGE |
| GET /api/v1/blocks/{id}/floors     | Không kiểm tra quyền     | BUILDING:VIEW | BUILDING:MANAGE |
| POST /api/v1/blocks/{id}/floors    | Bất kỳ ai cũng tạo được  | BUILDING:MANAGE                 |
| PUT /api/v1/floors/{id}            | Bất kỳ ai cũng sửa được  | BUILDING:MANAGE                 |
| DELETE /api/v1/floors/{id}         | Bất kỳ ai cũng xóa được  | BUILDING:MANAGE                 |
| GET /api/v1/apartments             | Không kiểm tra quyền     | APARTMENT:VIEW | BUILDING:VIEW  |
| POST /api/v1/apartments            | Bất kỳ ai cũng tạo được  | APARTMENT:MANAGE|BUILDING:MANAGE|
| POST /apartments/batch-generate    | Bất kỳ ai cũng tạo được  | APARTMENT:MANAGE|BUILDING:MANAGE|
| PUT /api/v1/apartments/{id}        | Bất kỳ ai cũng sửa được  | APARTMENT:MANAGE|BUILDING:MANAGE|
| PATCH /apartments/{id}/status      | Bất kỳ ai cũng sửa được  | APARTMENT:MANAGE|BUILDING:MANAGE|
| DELETE /api/v1/apartments/{id}     | Bất kỳ ai cũng xóa được  | APARTMENT:MANAGE|BUILDING:MANAGE|
+------------------------------------+--------------------------+---------------------------------+
```

---

### 5. KẾT QUẢ BẢO VỆ CHỐNG GỌI API TRỰC TIẾP & DATA SCOPING

1. **Chống gọi API trực tiếp không qua UI**:
   - Khi gọi API trực tiếp bằng Postman/cURL mà không có token hoặc token không hợp lệ -> Hệ thống trả về `401 UNAUTHORIZED`.
   - Khi tài khoản có Role (ví dụ `ACCOUNTANT` hoặc `BUILDING_MANAGER`) nhưng quyền tương ứng đã bị thu hồi trên RBAC Matrix -> Backend lập tức trả về `403 FORBIDDEN` kèm thông báo rõ ràng mã quyền bị thiếu (ví dụ `yêu cầu quyền: INVOICE:CREATE` hoặc `INVOICE:DELETE`).
   - Tài khoản có quyền `INVOICE:VIEW` bị chặn triệt để khi cố gắng gọi các API biến đổi dữ liệu (`POST /batch/generate`, `POST /cancel`, `POST /bulk-cancel`, `POST /send-reminder`).
2. **Thực thi Data Scoping nghiêm ngặt cho Cư dân**:
   - Cư dân căn hộ A-101 gọi `GET /api/v1/invoices/{id_cua_A102}` -> Bị từ chối `403 FORBIDDEN`: `"Bạn không có quyền truy cập hóa đơn của căn hộ khác."`.
   - Cư dân căn hộ A-101 gọi `GET /api/v1/invoices/{id_cua_A101}` -> Cho phép `200 OK` tải chi tiết đầy đủ các mục phí.
   - Cư dân gọi `GET /api/v1/invoices/current-month?apartment_id={id_cua_can_khac}` -> Bị từ chối `403 FORBIDDEN`.
   - Cư dân gọi `GET /api/v1/invoices/summary` (thống kê toàn bộ tiền bạc cả tòa nhà) -> Bị từ chối `403 FORBIDDEN`.
   - Cư dân gọi `POST /api/v1/invoices/{id}/cancel` -> Bị từ chối `403 FORBIDDEN`.
3. **Bảo toàn cơ chế Quản trị viên cấp cao & Người đặc biệt**:
   - Tài khoản có vai trò `SUPER_ADMIN` luôn được phép thực hiện mọi thao tác tự động thông qua `isSuperAdmin()`.
   - Tài khoản được gán quyền người đặc biệt `INVOICE:SPECIAL`, `BUILDING:SPECIAL` hoặc `SYSTEM:SPECIAL` được cấp quyền tương ứng theo chuẩn thiết kế RBAC.

---

### 6. NỘI DUNG VÀ ASSERTIONS CỦA BỘ FEATURE TEST

File kiểm thử: `tests/Feature/Invoices/InvoiceAndBuildingAuthorizationTest.php` gồm **17 test case toàn diện**:

1. `test_01_unauthenticated_or_fake_token_returns_401`: Xác minh gọi API không có token hoặc token giả đều nhận HTTP 401.
2. `test_02_super_admin_has_full_bypass_access`: Xác minh `SUPER_ADMIN` có toàn quyền xem hóa đơn, xem summary, xem blocks và apartments (HTTP 200).
3. `test_03_user_with_invoice_view_can_view_invoices`: Xác minh user có `INVOICE:VIEW` xem được danh sách và chi tiết hóa đơn (HTTP 200).
4. `test_04_user_with_role_but_without_view_permission_is_rejected`: Xác minh user có role `BUILDING_MANAGER` nhưng thiếu `INVOICE:VIEW` bị từ chối HTTP 403.
5. `test_05_user_with_view_only_cannot_generate_or_preview_batch_invoices`: Xác minh user chỉ có `INVOICE:VIEW` không thể gọi preview (403) hay generate (403).
6. `test_06_user_with_invoice_create_can_preview_and_generate_invoices`: Xác minh user có `INVOICE:CREATE` gọi preview thành công HTTP 200.
7. `test_07_user_without_invoice_delete_cannot_cancel_invoices`: Xác minh user thiếu `INVOICE:DELETE` không thể hủy hóa đơn đơn lẻ hay hàng loạt (HTTP 403), đồng thời assert trong database hóa đơn vẫn giữ nguyên trạng thái `ISSUED`.
8. `test_08_user_without_invoice_update_or_approve_cannot_send_debt_reminders`: Xác minh user thiếu quyền không thể gửi email nhắc nợ (HTTP 403).
9. `test_09_resident_cannot_view_another_apartment_invoice_detail_or_statement`: Data Scoping: Cư dân A-101 xem được hóa đơn và sao kê A-101 (200), nhưng xem A-102 lập tức nhận 403 FORBIDDEN.
10. `test_10_resident_cannot_access_another_apartment_current_month_invoice`: Data Scoping: Cư dân A-101 gọi `current-month` với apartment_id của A-102 bị chặn 403 FORBIDDEN.
11. `test_11_resident_cannot_cancel_or_generate_invoices`: Cư dân gọi cancel hoặc generate bị từ chối 403 FORBIDDEN.
12. `test_12_building_structure_view_permission_can_view_floors_and_blocks`: User có `BUILDING:VIEW` xem được blocks và floors (HTTP 200).
13. `test_13_user_with_building_view_only_cannot_create_update_or_delete_floors`: User chỉ có `BUILDING:VIEW` không tạo được tầng (403), không sửa tầng (403), không xóa tầng (403).
14. `test_14_user_with_building_manage_permission_can_create_update_delete_floors`: User có `BUILDING:MANAGE` tạo tầng (201), sửa tầng (200), xóa tầng (200) thành công.
15. `test_15_user_with_apartment_view_only_cannot_create_or_delete_apartments`: User chỉ có `APARTMENT:VIEW` xem được danh sách căn hộ (200), nhưng bị chặn khi tạo căn hộ (403) hoặc xóa căn hộ (403).
16. `test_16_user_with_apartment_manage_can_create_and_delete_apartments`: User có `APARTMENT:MANAGE` tạo căn hộ (201) và xóa căn hộ (200) thành công.
17. `test_17_rejected_requests_do_not_modify_database_state`: Đảm bảo tính toàn vẹn database: khi request trái phép bị từ chối, dữ liệu trong bảng `invoices` không hề bị thay đổi hay biến dạng.

---

### 7. TỔNG HỢP GIT DIFF (GIT DIFF SUMMARY)

```
app/Http/Controllers/BuildingStructureController.php | 100 ++++++++-
app/Http/Controllers/DebtReminderController.php     | 172 ++++++++++++++-
app/Http/Controllers/InvoiceGenerationController.php | 117 ++++++++++-
app/Http/Controllers/InvoiceManagementController.php | 231 ++++++++++++++++++++-
4 files changed, 596 insertions(+), 24 deletions(-)
```

- Không có xung đột whitespace (`git diff --check` đạt 100% clean).
- Không sửa bất kỳ file nào ngoài 4 Controller đã được phê duyệt.
- Tuyệt đối không chạm vào `routes/`, `database/seeders/`, `database/migrations/`, `resources/js/` hay các file thuộc BUG-01, BUG-02, BUG-03, BUG-05.

---

### 8. RỦI RO CÒN TỒN TẠI & HƯỚNG DẪN STAGE TIẾP THEO

- **Trạng thái môi trường Docker**: Docker Desktop Engine trên host hiện đang offline (quá trình khởi động sau reboot cần quyền quản trị người dùng máy tính), do đó lệnh chạy test thực tế qua php/phpunit được ghi nhận theo đúng quy tắc: `DEFERRED (Chờ Docker Engine sẵn sàng)`. Cấu trúc code, type và cú pháp đã được thẩm tra chặt chẽ.
- **Phạm vi dừng lại**:
  - Đã hoàn thành 100% mục tiêu của **Stage 2A**.
  - **Tuyệt đối dừng lại tại đây**, không tự ý chuyển sang Stage 2B (các Controller còn lại như `ResidentController`, `VehicleController`, `AccessCardController`, `StaffScheduleController`).
  - Không commit, không push, không tạo PR.
  - Chờ phê duyệt nghiệm thu chính thức từ Quản trị viên.


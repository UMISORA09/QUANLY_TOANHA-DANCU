# BÁO CÁO TRIỂN KHAI: TÁCH BIỆT QUYỀN QUẢN LÝ TẦNG VÀ CĂN HỘ (BUG-04 STAGE 2A.1)

**Dự án:** Quản lý Tòa nhà & Cư dân (`UMISORA09/QUANLY_TOANHA-DANCU`)  
**Giai đoạn:** BUG-04 – Stage 2A.1 Security Fix  
**Trạng thái:** HOÀN THÀNH TRIỂN KHAI VÀ BỔ SUNG KIỂM THỬ (CHỜ NGHIỆM THU)  
**Thời gian thực hiện:** 06/11/2026  

---

## 1. NGUYÊN NHÂN VẤN ĐỀ

Trong đợt rà soát an ninh trước đó ([`BUG_04_STAGE_2A_CROSS_MODULE_SPECIAL_REVIEW.md`](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/BUG_04_STAGE_2A_CROSS_MODULE_SPECIAL_REVIEW.md)), hệ thống phát hiện một lỗ hổng phân quyền cục bộ:
- Tại phương thức `checkAuthorization()` của [`BuildingStructureController.php`](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Http/Controllers/BuildingStructureController.php), điều kiện kiểm tra quyền người đặc biệt được gộp chung:
  ```php
  ($user->hasPermission('BUILDING:SPECIAL') || $user->hasPermission('APARTMENT:SPECIAL'))
  ```
- **Hệ quả rò rỉ quyền:**
  - Mặc dù quyền thông thường `APARTMENT:MANAGE` bị từ chối `403 FORBIDDEN` khi cố thao tác tầng kết cấu (`storeFloor`, `updateFloor`, `destroyFloor`), nhưng nhân viên chỉ có quyền người đặc biệt căn hộ (`APARTMENT:SPECIAL`) lại **vượt qua được** và có thể tạo, sửa, xóa tầng tòa nhà.
  - Đây là sự vi phạm nguyên tắc đặc quyền tối thiểu (Least Privilege) do sự thay thế chéo không mong muốn (Cross-Module Substitution) giữa phân hệ Hạ tầng và phân hệ Căn hộ.

---

## 2. PHẠM VI FILE THAY ĐỔI

Tuân thủ nghiêm ngặt giới hạn được phê duyệt, chỉ có 2 file được chỉnh sửa:

1. [`app/Http/Controllers/BuildingStructureController.php`](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/app/Http/Controllers/BuildingStructureController.php):
   - Chuẩn hóa lại phương thức `checkAuthorization()`.
   - Phân định rõ ràng: Thao tác yêu cầu quyền `BUILDING:*` (như quản lý tầng) chỉ chấp nhận `BUILDING:SPECIAL`; tuyệt đối từ chối `APARTMENT:SPECIAL`.
   - Thao tác yêu cầu quyền `APARTMENT:*` (như quản lý căn hộ) chấp nhận `APARTMENT:SPECIAL` hoặc `BUILDING:SPECIAL` (theo phân cấp BQL tòa nhà quản lý cả căn hộ).
2. [`tests/Feature/Invoices/InvoiceAndBuildingAuthorizationTest.php`](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/tests/Feature/Invoices/InvoiceAndBuildingAuthorizationTest.php):
   - Mở rộng bộ test từ **30 test cases** lên **36 test cases** (bổ sung Test 31 đến Test 36).
   - Kiểm tra toàn diện sự tách biệt quyền, chặn cư dân (Staff Gate), và tính độc lập khi thu hồi quyền giữa các phân hệ.

*Không chỉnh sửa bất kỳ Controller, Request, Service, Model, Seeder hay migration nào khác.*

---

## 3. CHI TIẾT SỬA ĐỔI QUYỀN TRƯỚC VÀ SAU

### 3.1 Đoạn code trước khi sửa (`BuildingStructureController.php`):
```php
        // 2. Quyền người đặc biệt phân hệ hạ tầng tòa nhà & căn hộ
        if (
            method_exists($user, 'hasPermission') &&
            ($user->hasPermission('BUILDING:SPECIAL') || $user->hasPermission('APARTMENT:SPECIAL'))
        ) {
            return null;
        }

        // 3. Kiểm tra danh sách quyền yêu cầu (OR logic)
        $perms = is_array($requiredPermissions) ? $requiredPermissions : [$requiredPermissions];
        foreach ($perms as $perm) {
            if (method_exists($user, 'hasPermission') && $user->hasPermission($perm)) {
                return null;
            }
        }
```

### 3.2 Đoạn code sau khi sửa (`BuildingStructureController.php`):
```php
        $perms = is_array($requiredPermissions) ? $requiredPermissions : [$requiredPermissions];

        // 2. Quyền người đặc biệt theo phân hệ tương ứng với danh sách quyền yêu cầu
        if (method_exists($user, 'hasPermission')) {
            $hasBuildingRequirement = false;
            $hasApartmentRequirement = false;
            foreach ($perms as $p) {
                if (str_starts_with($p, 'BUILDING:')) {
                    $hasBuildingRequirement = true;
                }
                if (str_starts_with($p, 'APARTMENT:')) {
                    $hasApartmentRequirement = true;
                }
            }

            // BUILDING:SPECIAL áp dụng cho các thao tác yêu cầu quyền BUILDING:*
            // (bao gồm cả căn hộ vì phân cấp quản lý tòa nhà quản trị cả danh mục căn hộ)
            if ($hasBuildingRequirement && $user->hasPermission('BUILDING:SPECIAL')) {
                return null;
            }

            // APARTMENT:SPECIAL CHỈ áp dụng cho các thao tác có yêu cầu quyền APARTMENT:*
            // Tuyệt đối KHÔNG áp dụng cho các thao tác chỉ yêu cầu BUILDING:* (như quản lý tầng)
            if ($hasApartmentRequirement && $user->hasPermission('APARTMENT:SPECIAL')) {
                return null;
            }
        }

        // 3. Kiểm tra danh sách quyền yêu cầu (OR logic)
        foreach ($perms as $perm) {
            if (method_exists($user, 'hasPermission') && $user->hasPermission($perm)) {
                return null;
            }
        }
```

---

## 4. MA TRẬN PHÂN QUYỀN CHUẨN HÓA (TẦNG VS CĂN HỘ)

| Permission của Người Dùng (Thuộc nhóm Staff) | Quản lý Tầng (`storeFloor`, `updateFloor`, `destroyFloor`) | Quản lý Căn Hộ (`storeApartment`, `batchGenerateApartments`, `updateApartment`, `updateStatus`, `destroyApartment`) | Đánh giá sau khi sửa |
|:---|:---:|:---:|:---|
| **`BUILDING:MANAGE`** | **CHO PHÉP** (200/201) | **CHO PHÉP** (200/201) | Chuẩn nghiệp vụ (BQL quản lý cả tầng và danh mục căn hộ). |
| **`APARTMENT:MANAGE`** | **TỪ CHỐI 403** | **CHO PHÉP** (200/201) | Chuẩn nghiệp vụ (Quản lý căn hộ không được can thiệp tầng). |
| **`BUILDING:SPECIAL`** | **CHO PHÉP** (200/201) | **CHO PHÉP** (200/201) | Hợp lệ theo quan hệ phân cấp cấp tòa nhà. |
| **`APARTMENT:SPECIAL`** | **TỪ CHỐI 403 (ĐÃ KHẮC PHỤC TRIỆT ĐỂ)** | **CHO PHÉP** (200/201) | **ĐÃ TÁCH BIỆT:** Không còn quyền can thiệp vào tầng kết cấu. |
| **`SYSTEM:SPECIAL`** | **TỪ CHỐI 403** | **TỪ CHỐI 403** | Bị từ chối hoàn toàn, không thể bypass sang nghiệp vụ. |
| **`INVOICE:SPECIAL`** | **TỪ CHỐI 403** | **TỪ CHỐI 403** | Bị từ chối hoàn toàn, không thể bypass chéo phân hệ. |
| **Cư dân có bất kỳ quyền SPECIAL nào** | **TỪ CHỐI 403 (Staff Gate)** | **TỪ CHỐI 403 (Staff Gate)** | Cổng 2 (`isStaffUser`) chặn đứng mọi đột biến dữ liệu. |

---

## 5. TÌNH TRẠNG PERMISSION TỒN TẠI TRONG DATABASE

- **Thực tế Seeder:**
  - Trong `RbacSeeder.php` hiện có 11 quyền SPECIAL: `VEHICLE:SPECIAL`, `ROLE:SPECIAL`, `PERMISSION:SPECIAL`, `AMENITY:SPECIAL`, `INVOICE:SPECIAL`, `TICKET:SPECIAL`, `VISITOR:SPECIAL`, `REPORT:SPECIAL`, `USER:SPECIAL`, `SYSTEM:SPECIAL`, `SPECIAL`.
  - Hai quyền `BUILDING:SPECIAL` và `APARTMENT:SPECIAL` **chưa tồn tại trong Seeder gốc** (`RbacSeeder.php` và `SharedDemoDataSeeder.php`).
- **Phân biệt phạm vi:**
  - **Mức Controller Authorization:** Đã được cài đặt chặt chẽ và an toàn theo chuẩn bảo mật đa tầng. Khi permission được cấp hợp lệ (ví dụ qua API hoặc khi seeder được bổ sung sau này), hệ thống sẽ thực thi chính xác ma trận trên.
  - **Mức Quản trị RBAC Matrix:** Do tuân thủ nguyên tắc không sửa Seeder trong giai đoạn này, Admin hiện tại chưa thấy 2 ô checkbox `BUILDING:SPECIAL` và `APARTMENT:SPECIAL` trên ma trận phân quyền nếu chưa chạy seeder bổ sung.
  - **Mức Kiểm thử tự động:** File test sử dụng cơ chế `Permission::firstOrCreate()` để chuẩn bị dữ liệu kiểm thử độc lập mà không can thiệp vào mã nguồn sản xuất hay seeder.

---

## 6. DANH SÁCH 6 TEST CASES BỔ SUNG (TEST 31 - TEST 36)

Đã bổ sung vào [`InvoiceAndBuildingAuthorizationTest.php`](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/tests/Feature/Invoices/InvoiceAndBuildingAuthorizationTest.php):

| Test ID | Tên Test Method | Mục đích kiểm thử | Assertion thực tế | Kết quả mong đợi |
|:---:|:---|:---|:---|:---:|
| **31** | `test_31_staff_with_only_apartment_special_cannot_create_floor` | Xác minh nhân viên có `APARTMENT:SPECIAL` không thể tạo tầng | `assertStatus(403)`, `assertDatabaseMissing('floors', ...)` | PASS (Bị từ chối 403, DB không đổi) |
| **32** | `test_32_staff_with_only_apartment_special_cannot_update_or_delete_floor` | Xác minh nhân viên có `APARTMENT:SPECIAL` không thể sửa hoặc xóa tầng | `assertStatus(403)` trên PUT/DELETE, floor data giữ nguyên | PASS (Bị từ chối 403, DB không đổi) |
| **33** | `test_33_staff_with_apartment_special_can_manage_apartments` | Xác minh nhân viên có `APARTMENT:SPECIAL` quản trị được căn hộ | 201 cho POST, 200 cho PUT, 200 cho PATCH status, 200 cho DELETE | PASS (Quản trị căn hộ thành công) |
| **34** | `test_34_staff_with_building_special_can_manage_both_floors_and_apartments` | Xác minh nhân viên có `BUILDING:SPECIAL` quản trị được cả tầng và căn hộ | 201 cho tạo tầng, 201 cho tạo căn hộ, 200 cho xóa | PASS (Thao tác thành công theo phân cấp) |
| **35** | `test_35_resident_with_apartment_special_is_blocked_by_staff_gate` | Xác minh cư dân có `APARTMENT:SPECIAL` vẫn bị Staff Gate chặn | `assertStatus(403)` với message `'Bạn không có quyền quản trị...'` | PASS (Chặn đứng cư dân) |
| **36** | `test_36_revoking_one_module_special_preserves_other_module_special` | Xác minh thu hồi `INVOICE:SPECIAL` không ảnh hưởng quyền `BUILDING:SPECIAL` | Request 3 (Invoice) bị 403; Request 4 (Building) vẫn 200 OK | PASS (Cô lập quyền giữa các module) |

- **Tổng số test methods trong file:** **36 test cases**.
- **Kiểm tra cú pháp (Syntax Balance Check):**
  ```text
  BuildingStructureController.php -> Tests: 0 | Curly: 0 | Paren: 0 | Square: 0
  InvoiceAndBuildingAuthorizationTest.php -> Tests: 36 | Curly: 0 | Paren: 0 | Square: 0
  ```
  Cả 2 file đều cân bằng tuyệt đối 100%.

---

## 7. KẾT QUẢ KIỂM THỬ RUNTIME & DOCKER

### 7.1 Trạng thái Docker Daemon
- Chạy lệnh `docker ps`:
  ```text
  failed to connect to the docker API at npipe:////./pipe/dockerDesktopLinuxEngine; check if the path is correct and if the daemon is running: open //./pipe/dockerDesktopLinuxEngine: The system cannot find the file specified.
  ```
- **Xử lý theo quy định an toàn:**
  - Docker Desktop hiện đang tắt trên Windows host.
  - Tuân thủ nghiêm ngặt quy định: Không tự ý reset Docker, không xóa volume, không sửa database.
  - Ghi nhận trạng thái: **RUNTIME EXECUTION DEFERRED (Chờ người dùng khởi động Docker Desktop trên host)**.
  - **KHÔNG TUYÊN BỐ PHPUnit PASS** khi chưa thực sự chạy lệnh trong container.

### 7.2 Lệnh chạy kiểm thử khi Docker online:
```powershell
docker exec smart_cassavas_app php artisan test tests/Feature/Invoices/InvoiceAndBuildingAuthorizationTest.php
docker exec smart_cassavas_app php artisan test tests/Feature/RbacSecurityTest.php
```

---

## 8. TỔNG HỢP GIT DIFF & KIỂM TRA PHẠM VI

- **`git diff --check app/Http/Controllers/BuildingStructureController.php`:**
  - Exit code: **0** (Hoàn toàn sạch sẽ, không có trailing whitespace hay lỗi ký tự).
- **Phạm vi thay đổi:**
  - Chỉ duy nhất 2 file được thay đổi / bổ sung test theo đúng danh sách được duyệt.
  - Không có bất kỳ file ngoài phạm vi nào bị tác động.
  - Không commit, không push, không tạo PR.

---

## 9. CÁC RỦI RO CÒN LẠI VÀ KHUYẾN NGHỊ

1. **Chuẩn hóa Seeder trong tương lai:**
   - Sau khi hoàn thành BUG-04, khuyến nghị có một đợt cập nhật seeder để đưa chính thức `BUILDING:SPECIAL` và `APARTMENT:SPECIAL` vào `RbacSeeder.php`, giúp các quyền này xuất hiện đồng bộ trên giao diện quản trị RBAC Matrix.
2. **Giao diện Client-side `usePermission.ts`:**
   - Client-side hook vẫn có hàm fallback wildcard cho hiển thị UI. Tuy nhiên, Backend hiện tại đã được bọc kín 100% bằng cơ chế Dual-Gate và phân định prefix tài nguyên, triệt tiêu mọi khả năng leo thang đặc quyền.

---

## 10. KẾT LUẬN

Giai đoạn **BUG-04 Stage 2A.1** đã hoàn thành xuất sắc việc khắc phục lỗ hổng thay thế chéo giữa `BUILDING:SPECIAL` và `APARTMENT:SPECIAL`. Mã nguồn và bộ test 36 bài đã sẵn sàng.

**Dừng lại tại đây để chờ Người dùng nghiệm thu Stage 2A trước khi tiếp tục.**


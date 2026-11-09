# BÁO CÁO ĐÁNH GIÁ AN NINH CHUYÊN SÂU: CROSS-MODULE SPECIAL VÀ RBAC SPECIAL ALIAS (BUG-04 STAGE 2A)

**Dự án:** Quản lý Tòa nhà & Cư dân (`UMISORA09/QUANLY_TOANHA-DANCU`)  
**Phân hệ:** Hóa đơn & Hạ tầng tòa nhà (Stage 2A)  
**Chế độ:** REVIEW ONLY / NO CODE MODIFICATION  
**Thời gian thực hiện:** 06/11/2026  

---

## 1. KIỂM TRA `BUILDING:SPECIAL` VÀ `APARTMENT:SPECIAL` TRONG `BuildingStructureController.php`

### 1.1 Hiện trạng logic phân quyền tại `BuildingStructureController.php`

Hiện tại, phương thức `checkAuthorization()` của `BuildingStructureController.php` (dòng 43-48) được viết như sau:

```php
// 2. Quyền người đặc biệt phân hệ hạ tầng tòa nhà & căn hộ
if (
    method_exists($user, 'hasPermission') &&
    ($user->hasPermission('BUILDING:SPECIAL') || $user->hasPermission('APARTMENT:SPECIAL'))
) {
    return null;
}
```

Phương thức này được gọi đầu tiên tại **toàn bộ 11 endpoints** của Controller:
1. **Quản lý Tầng (Floors):**
   - `storeFloor`: Yêu cầu `BUILDING:MANAGE` + `isStaffUser($user)`.
   - `updateFloor`: Yêu cầu `BUILDING:MANAGE` + `isStaffUser($user)`.
   - `destroyFloor`: Yêu cầu `BUILDING:MANAGE` + `isStaffUser($user)`.
2. **Quản lý Căn hộ (Apartments):**
   - `storeApartment`: Yêu cầu `['APARTMENT:MANAGE', 'BUILDING:MANAGE']` + `isStaffUser($user)`.
   - `batchGenerateApartments`: Yêu cầu `['APARTMENT:MANAGE', 'BUILDING:MANAGE']` + `isStaffUser($user)`.
   - `updateApartment`: Yêu cầu `['APARTMENT:MANAGE', 'BUILDING:MANAGE']` + `isStaffUser($user)`.
   - `updateStatus`: Yêu cầu `['APARTMENT:MANAGE', 'BUILDING:MANAGE']` + `isStaffUser($user)`.
   - `destroyApartment`: Yêu cầu `['APARTMENT:MANAGE', 'BUILDING:MANAGE']` + `isStaffUser($user)`.
3. **Các endpoint đọc (Read/Bootstrap):**
   - `bootstrap`: Yêu cầu `['BUILDING:VIEW', 'BUILDING:MANAGE', 'APARTMENT:VIEW', 'APARTMENT:MANAGE']`.
   - `indexBlocks`: Yêu cầu `['BUILDING:VIEW', 'BUILDING:MANAGE']`.
   - `getStats`: Yêu cầu `['BUILDING:VIEW', 'BUILDING:MANAGE']`.
   - `indexFloors`: Yêu cầu `['BUILDING:VIEW', 'BUILDING:MANAGE']`.
   - `indexApartments`: Yêu cầu `['APARTMENT:VIEW', 'BUILDING:VIEW', 'APARTMENT:MANAGE', 'BUILDING:MANAGE']`.

---

### 1.2 Ma trận phân quyền thực tế theo hiện trạng code

| Permission của Người Dùng (Thuộc nhóm Staff) | Quản lý Tầng (`storeFloor`, `updateFloor`, `destroyFloor`) | Quản lý Căn Hộ (`storeApartment`, `updateApartment`, `destroyApartment`, v.v.) | Đánh giá an ninh & Rủi ro |
|:---|:---|:---|:---|
| **`BUILDING:MANAGE`** | **CHO PHÉP** (200/201) | **CHO PHÉP** (200/201) | Hợp lệ theo quan hệ phân cấp: Ban Quản lý tòa nhà quản trị cả kết cấu tầng và danh mục căn hộ. |
| **`APARTMENT:MANAGE`** | **TỪ CHỐI 403** | **CHO PHÉP** (200/201) | Chuẩn xác: Nhân viên quản lý căn hộ không được phép thêm/sửa/xóa kết cấu tầng tòa nhà. |
| **`BUILDING:SPECIAL`** | **CHO PHÉP** (200/201) | **CHO PHÉP** (200/201) | Hợp lệ nếu chấp nhận `BUILDING:SPECIAL` bao trùm toàn bộ phân hệ Hạ tầng và Căn hộ của tòa nhà. |
| **`APARTMENT:SPECIAL`** | **CHO PHÉP (RỦI RO LEO THANG QUYỀN)** | **CHO PHÉP** (200/201) | **LỖI LOGIC / RỦI RO:** Do dòng 45 kiểm tra chung `BUILDING:SPECIAL || APARTMENT:SPECIAL`, người chỉ có `APARTMENT:SPECIAL` lại được phép thêm/sửa/xóa tầng kết cấu tòa nhà! |
| **`SYSTEM:SPECIAL`** | **TỪ CHỐI 403** | **TỪ CHỐI 403** | Đã khắc phục triệt để ở bản vá trước, không thể bypass. |
| **`INVOICE:SPECIAL`** | **TỪ CHỐI 403** | **TỪ CHỐI 403** | Đã khắc phục triệt để, không thể bypass sang hạ tầng. |

---

### 1.3 Phát hiện bất cập cốt lõi (Core Findings):
1. **Thay thế chéo không mong muốn (Cross-Module Substitution):**
   - Trong khi quyền thông thường `APARTMENT:MANAGE` bị chặn `403 FORBIDDEN` khi cố thao tác tầng (`storeFloor`, `updateFloor`, `destroyFloor`), thì quyền đặc biệt `APARTMENT:SPECIAL` lại **vượt qua được** và thao tác được tầng vì dòng 45 gộp chung `($user->hasPermission('BUILDING:SPECIAL') || $user->hasPermission('APARTMENT:SPECIAL'))`.
   - Đây là hành vi vi phạm nguyên tắc đặc quyền tối thiểu (Least Privilege). Nhân sự phụ trách căn hộ không được quyền thay đổi kết cấu tầng tòa nhà.
2. **Thực trạng Permission trong Database & Seeder:**
   - **`RbacSeeder.php`:** Hoàn toàn **KHÔNG CÓ** `BUILDING:SPECIAL` hay `APARTMENT:SPECIAL`. Danh mục Seeder chỉ có 11 quyền SPECIAL (`VEHICLE:SPECIAL`, `ROLE:SPECIAL`, `PERMISSION:SPECIAL`, `AMENITY:SPECIAL`, `INVOICE:SPECIAL`, `TICKET:SPECIAL`, `VISITOR:SPECIAL`, `REPORT:SPECIAL`, `USER:SPECIAL`, `SYSTEM:SPECIAL`, `SPECIAL`).
   - **`SharedDemoDataSeeder.php`:** Chỉ có `BUILDING:VIEW`, `BUILDING:MANAGE`, `APARTMENT:VIEW`, `APARTMENT:MANAGE`. Không hề có `BUILDING:SPECIAL` hay `APARTMENT:SPECIAL`.
   - `BUILDING:SPECIAL` và `APARTMENT:SPECIAL` hiện chỉ tồn tại dưới dạng quy ước chuỗi trong Controller và dữ liệu tạo động trong test.

---

## 2. KIỂM TRA `RbacService::syncRolePermissions()` VÀ MAPPING ALIAS SPECIAL

### 2.1 Cơ chế mapping trong `RbacService.php` (Lines 248-264)
```php
$upperCodes = array_map(fn ($p) => is_string($p) ? strtoupper(trim($p)) : $p, $permissionIds);

// Tìm các Permission ID thực tế
$resolvedIds = Permission::whereIn('id', $permissionIds)
    ->orWhereIn('permission_code', $permissionIds)
    ->orWhereIn('permission_code', $upperCodes)
    ->pluck('id')
    ->toArray();

// Hỗ trợ alias 'special' / 'SPECIAL' để cấp quyền người đặc biệt
if (in_array('SPECIAL', $upperCodes, true)) {
    $specialIds = Permission::where('permission_code', 'SPECIAL')
        ->orWhere('permission_code', 'SYSTEM:SPECIAL')
        ->pluck('id')
        ->toArray();
    $resolvedIds = array_values(array_unique(array_merge($resolvedIds, $specialIds)));
}
```

---

### 2.2 Trả lời 6 câu hỏi kiểm toán trọng tâm

#### 1. Admin bật SPECIAL cho module Invoice thì database có bị cấp `SYSTEM:SPECIAL` không?
- **KẾT QUẢ: KHÔNG.**
- **Giải trình:** Khi Admin tick ô "Người đặc biệt" của hàng `INVOICE` trên ma trận phân quyền, giao diện `RbacManagement.tsx` lấy mã chính xác từ permission model là `'INVOICE:SPECIAL'` và đưa vào payload gửi lên API (`['INVOICE:SPECIAL']`).
- Tại `RbacService`, mảng `$upperCodes` chứa chuỗi `'INVOICE:SPECIAL'`. Lệnh so sánh nghiêm ngặt `in_array('SPECIAL', $upperCodes, true)` trả về `false` vì `'INVOICE:SPECIAL' !== 'SPECIAL'`.
- Do đó, khối `if` không chạy, database chỉ lưu duy nhất quyền `INVOICE:SPECIAL`.

#### 2. Admin bật SPECIAL cho module Building thì có bị cấp `SYSTEM:SPECIAL` không?
- **KẾT QUẢ: KHÔNG.**
- Tương tự, payload gửi lên là `'BUILDING:SPECIAL'`. Chuỗi này không bằng `'SPECIAL'`, do đó không bị gán thêm `SYSTEM:SPECIAL`.

#### 3. Admin bật SPECIAL cho module Apartment thì có bị cấp `SYSTEM:SPECIAL` không?
- **KẾT QUẢ: KHÔNG.**
- Tương tự, payload gửi lên là `'APARTMENT:SPECIAL'`, không khớp `'SPECIAL'`, không bị gán `SYSTEM:SPECIAL`.

#### 4. Khi tắt SPECIAL của module, quyền `SYSTEM:SPECIAL` có bị thu hồi ngoài ý muốn không?
- **KẾT QUẢ: KHÔNG BỊ THU HỒI NGOÀI Ý MUỐN NẾU THAO TÁC QUA GIAO DIỆN MATRIX.**
- **Giải trình:** 
  - `syncRolePermissions` áp dụng cơ chế **Full Sync**: Xóa toàn bộ `role_permissions` của vai trò và nạp lại chính xác các quyền có mặt trong payload.
  - Trên giao diện `RbacManagement.tsx`, state `matrixPermissions` lưu toàn bộ các checkbox đang được chọn của tất cả các module. Khi uncheck `INVOICE:SPECIAL`, mảng chỉ bỏ `'INVOICE:SPECIAL'`. Nếu checkbox của module `SYSTEM` vẫn đang được chọn, chuỗi `'SYSTEM:SPECIAL'` vẫn nằm trong payload gửi lên. Do đó `SYSTEM:SPECIAL` vẫn được giữ nguyên trong database.
  - **Lưu ý ngoại lệ:** Nếu một client bên ngoài (như script tự động) chỉ gửi một phần mảng quyền (Partial payload), các quyền không có trong mảng sẽ bị xóa theo nguyên lý của phương thức sync.

#### 5. Giao diện gửi permission code cụ thể hay chỉ gửi alias `SPECIAL`?
- **KẾT QUẢ: GIAO DIỆN GỬI PERMISSION CODE CỤ THỂ.**
- Trong `RbacManagement.tsx`:
  - Hàm `findModulePermissionForAction(modPerms, 'special')` tìm permission trong danh mục quyền của từng module:
    - Module Invoice -> Trả về permission code `INVOICE:SPECIAL`.
    - Module Vehicle -> Trả về permission code `VEHICLE:SPECIAL`.
    - Module System -> Trả về permission code `SYSTEM:SPECIAL` hoặc `SPECIAL`.
  - Checkbox gọi: `onChange={() => toggleMatrixPermission(perm.permission_code)}`.
  - Khi lưu, API nhận danh sách mã tường minh `['INVOICE:SPECIAL', 'VEHICLE:SPECIAL', ...]`, hoàn toàn không gửi chuỗi alias chung `SPECIAL`.
  - Khối code `if (in_array('SPECIAL', $upperCodes, true))` trong `RbacService` chỉ có tác dụng khi có một request API đặc thù truyền thẳng alias `'SPECIAL'`.

#### 6. Có tình trạng module này ghi đè quyền đặc biệt của module khác không?
- **KẾT QUẢ: KHÔNG BỊ GHI ĐÈ TRONG THAO TÁC TỪNG MODULE.**
- Mỗi ô checkbox trên UI được liên kết với một `perm.permission_code` duy nhất. Việc check/uncheck một ô chỉ thay đổi trạng thái tồn tại của mã đó trong mảng `matrixPermissions`.
- **Lưu ý duy nhất:** Nút "Bật/Tắt tất cả" ở tiêu đề cột 7 (`toggleColumnPermissions('special')`): Nếu Admin bấm vào tiêu đề cột 7, toàn bộ quyền đặc biệt của tất cả các module sẽ cùng được chọn hoặc cùng bị bỏ chọn đồng loạt.

---

## 3. KIỂM TRA FRONTEND (`usePermission.ts`)

### 3.1 Hiện trạng logic Frontend
Trong [`resources/js/Hooks/usePermission.ts`](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/resources/js/Hooks/usePermission.ts) (dòng 72-78 và 127-133):
```typescript
if (cleanPerm === 'SPECIAL') {
  return (
    permissions.includes('SPECIAL') ||
    permissions.includes('SYSTEM:SPECIAL') ||
    permissions.includes('USER:SPECIAL') ||
    permissions.some((p) => p.endsWith(':SPECIAL'))
  );
}
```

### 3.2 Đánh giá hiển thị UI vs Thực thi Backend:
1. **Rà soát mã nguồn Component:**
   - Không có component giao diện nào trong toàn bộ ứng dụng gọi `can('SPECIAL')` hay `can('special')`.
   - Các component bảo vệ nút bấm đều gọi quyền nghiệp vụ cụ thể (như `can('INVOICE:CREATE')`, `can('TICKET:RESOLVE')`, v.v.).
2. **Khả năng gây hiểu nhầm:**
   - Nếu trong tương lai có lập trình viên gọi `can('SPECIAL')` trên UI, người dùng có bất kỳ quyền đặc biệt nào (như `VEHICLE:SPECIAL`) sẽ được UI trả về `true`.
   - **Tuyến phòng thủ quyết định:** Tuy nhiên, đây chỉ là logic hiển thị Client-side (về UX). Mọi request API đều được Backend chặn đứng bằng `checkAuthorization()` với mã lỗi `403 FORBIDDEN`. Frontend không thể ép Backend thực thi nếu không có quyền hợp lệ trên API.
3. **Hiển thị ma trận phân quyền (`RbacManagement.tsx`):**
   - Ma trận phân quyền hiển thị từng module riêng biệt với nhãn tên rõ ràng:
     - Dòng Invoice: "Người đặc biệt phân hệ hóa đơn (`INVOICE:SPECIAL`)".
     - Dòng Vehicle: "Người đặc biệt phân hệ phương tiện (`VEHICLE:SPECIAL`)".
     - Dòng System: "Người đặc biệt (special) (`SYSTEM:SPECIAL`)".
   - Không xảy ra tình trạng hiểu nhầm danh tính quyền trên giao diện quản trị.

---

## 4. KIỂM TRA TEST COVERAGE HIỆN TẠI (30 Test Cases)

Đã đối chiếu 30 test methods trong [`tests/Feature/Invoices/InvoiceAndBuildingAuthorizationTest.php`](file:///d:/QUANLYNHA/QUANLY_TOANHA-DANCU/tests/Feature/Invoices/InvoiceAndBuildingAuthorizationTest.php) với 4 tình huống kiểm tra chuyên sâu:

| Kịch bản kiểm tra yêu cầu | Tình trạng trong Test Suite hiện tại | Đánh giá |
|:---|:---:|:---|
| **1. `APARTMENT:SPECIAL` không được quản lý tầng** | **CHƯA CÓ TEST** | Hiện tại chưa có test case nào kiểm tra tài khoản chỉ có `APARTMENT:SPECIAL` bị từ chối khi gọi `storeFloor`, `updateFloor`, `destroyFloor`. *(Nếu chạy thử với code hiện tại, request này sẽ PASS nhầm do dòng 45 `BuildingStructureController` chấp nhận cả 2).* |
| **2. `BUILDING:SPECIAL` không được quản lý căn hộ (nếu nghiệp vụ yêu cầu tách biệt)** | **CHƯA CÓ TEST TÁCH BIỆT** | Hiện tại các endpoint căn hộ cho phép `BUILDING:MANAGE`, và Controller coi `BUILDING:SPECIAL` có quyền trên căn hộ. Chưa có test khẳng định ranh giới giữa 2 quyền này. |
| **3. Bật SPECIAL cho một module không cấp `SYSTEM:SPECIAL`** | **CHƯA CÓ TEST Ở TẦNG RBAC SERVICE** | Test suite hiện tại tập trung kiểm thử API Authorization của Controller (Functional/HTTP Test), chưa có Unit/Feature test kiểm tra đầu ra của `RbacService::syncRolePermissions` khi truyền `INVOICE:SPECIAL`. |
| **4. Thu hồi SPECIAL không ảnh hưởng quyền SPECIAL ở module khác** | **CHƯA CÓ TEST ĐỘC LẬP CHÉO** | Test 30 chỉ kiểm tra thu hồi `INVOICE:SPECIAL` của tài khoản và assert request tiếp theo bị 403. Chưa có test cấp đồng thời `INVOICE:SPECIAL` và `BUILDING:SPECIAL`, sau đó thu hồi 1 quyền và xác minh quyền còn lại vẫn hoạt động bình thường. |

---

## 5. CÁC RỦI RO PHÁT HIỆN VÀ ĐỀ XUẤT KHẮC PHỤC TỐI THIỂU

### 5.1 Danh sách rủi ro phát hiện
1. **Rủi ro R1 (Trung bình):** Sự thay thế chéo giữa `BUILDING:SPECIAL` và `APARTMENT:SPECIAL` tại `BuildingStructureController.php:45`. Một nhân viên chỉ được cấp quyền người đặc biệt căn hộ (`APARTMENT:SPECIAL`) vẫn có thể can thiệp cấu trúc tầng tòa nhà (`storeFloor`, `updateFloor`, `destroyFloor`).
2. **Rủi ro R2 (Thấp/Thiếu hụt dữ liệu):** `BUILDING:SPECIAL` và `APARTMENT:SPECIAL` chưa được chuẩn hóa vào `RbacSeeder.php`, khiến hệ thống chỉ có quyền runtime/test mà chưa có sẵn trong danh mục quyền chuẩn của hệ thống khi chạy fresh seed.
3. **Rủi ro R3 (Thiếu hụt kiểm thử):** Thiếu 4 test case bảo vệ ranh giới giữa `BUILDING:SPECIAL` và `APARTMENT:SPECIAL`, cũng như kiểm tra tính độc lập khi sync/thu hồi quyền qua `RbacService`.

---

### 5.2 Đề xuất sửa đổi tối thiểu (Minimal Fix Proposal - Chờ phê duyệt)

#### Đề xuất 1: Tách biệt quyền SPECIAL theo đúng nhóm tài nguyên trong `BuildingStructureController.php`
Thay vì dùng chung một điều kiện gộp:
```php
// HIỆN TẠI:
if ($user->hasPermission('BUILDING:SPECIAL') || $user->hasPermission('APARTMENT:SPECIAL')) {
    return null;
}
```

Nâng cấp thành phân định theo nhóm tài nguyên kiểm tra:
- **Khi thao tác Tầng (`BUILDING:MANAGE` / `storeFloor`, `updateFloor`, `destroyFloor`):**
  Chỉ chấp nhận `$user->hasPermission('BUILDING:SPECIAL')`. Tuyệt đối từ chối `APARTMENT:SPECIAL`.
- **Khi thao tác Căn hộ (`APARTMENT:MANAGE` / `storeApartment`, `updateApartment`, `destroyApartment`, v.v.):**
  Chấp nhận `$user->hasPermission('APARTMENT:SPECIAL') || $user->hasPermission('BUILDING:SPECIAL')` (do Trưởng ban quản lý tòa nhà quản lý cả danh mục căn hộ).

#### Đề xuất 2: Bổ sung 4 Feature Test Cases vào `InvoiceAndBuildingAuthorizationTest.php`
1. `test_31_staff_with_apartment_special_cannot_manage_floors`: Nhân viên chỉ có `APARTMENT:SPECIAL` bị từ chối 403 khi tạo, sửa, xóa tầng.
2. `test_32_staff_with_apartment_special_can_manage_apartments`: Nhân viên có `APARTMENT:SPECIAL` thao tác được căn hộ.
3. `test_33_sync_role_permissions_for_module_special_does_not_grant_system_special`: Kiểm tra mức Service/Database rằng gán `INVOICE:SPECIAL` không tự sinh `SYSTEM:SPECIAL`.
4. `test_34_revoking_one_module_special_preserves_other_module_special`: Cấp cả `INVOICE:SPECIAL` và `BUILDING:SPECIAL`, thu hồi `INVOICE:SPECIAL`, kiểm tra quyền `BUILDING:SPECIAL` vẫn giữ nguyên hiệu lực.

---

## 6. KẾT LUẬN

1. **Về `RbacService` & Giao diện ma trận quyền:** An toàn. Việc gán SPECIAL của từng module KHÔNG hề tự động cấp thêm `SYSTEM:SPECIAL`, và giao diện gửi đúng permission code cụ thể của từng phân hệ.
2. **Về `BuildingStructureController`:** Phát hiện điểm rò rỉ quyền cục bộ: `APARTMENT:SPECIAL` hiện đang có thể quản trị tầng kết cấu tòa nhà do điều kiện OR gộp chung ở đầu Controller.
3. **Trạng thái thực thi:** Báo cáo này ở chế độ **REVIEW ONLY**. Toàn bộ mã nguồn, kiểm thử và seeder được giữ nguyên trạng thái 100%.

**Dừng lại tại đây để chờ Người dùng xem xét và phê duyệt đề xuất sửa đổi tối thiểu trước khi thực hiện.**


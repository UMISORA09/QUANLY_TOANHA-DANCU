# Manager Notification Center audit

Ngày kiểm tra: 2026-10-20. Nhánh: `DangNguyen/centralize-manager-notifications`.
Baseline: `origin/master` tại `6273855f9ab1d1afbedc333f2dad0a23c7ee6f55`.

## Phạm vi và kết luận

Admin và Quản lý dùng chung một inbox trong chuông Header. Không ghép trang đầu của hai API: backend truy vấn bảng `user_in_app_notifications`, sắp xếp toàn cục `created_at DESC, id DESC`, 15 dòng/trang. Badge đếm toàn bộ thông báo chưa đọc được phép xem, độc lập trang và bộ lọc.

Không thêm dependency, bảng, migration hoặc sao chép/xóa thông báo cũ. Không sửa database production, không phát sinh thanh toán thật, không push/merge/deploy. Các thông báo mẫu không bị xóa khỏi database; bản ghi mẫu không có liên kết đối tượng Ticket hợp lệ không được đưa vào inbox.

## Inventory nguồn thực tế

| Nguồn | Cơ chế có thật | Kết quả |
|---|---|---|
| `ResidentTicketService` | Tạo Ticket, lịch sử và `TicketNotificationService::changed` trong transaction | Tích hợp `TICKET` vào chuông chung |
| `TicketWorkflowService` | Tiếp nhận, phân công, xử lý, khắc phục, mở lại, hủy, nghiệm thu | Giữ thông báo theo hành động/người nhận thực tế; quản lý chỉ thấy bản ghi gửi cho mình |
| `ResidentAmenityBookingService` | Đăng ký tiện ích → `notifyManagers`; thay đổi trạng thái → `notifyResident` | Tích hợp `AMENITY_BOOKING`; giữ trải nghiệm cư dân `AMENITY` |
| `AmenityBookingPaymentService` | Báo chuyển khoản, nhận giao dịch, giao dịch cần đối soát | Dùng thông báo tiện ích hiện có, không tạo một bản sao ở frontend |
| `AmenitySePayCheckoutService` | IPN hợp lệ/không khớp/cần đối soát | Cùng inbox tiện ích; giao dịch không xác định căn hộ chỉ gửi người có phạm vi toàn khu/Admin |
| `ManagementDashboardController` | Trước đây lấy 5 thông báo toàn hệ thống, không lọc người nhận, dùng cache chung | Bỏ thông báo khỏi response tổng quan; đổi phiên bản cache để không trả lại dữ liệu cũ |
| `ManagementHome` | Popup `bookingAlert`; hai danh sách riêng; bốn thông báo mẫu cố định | Bỏ popup và dữ liệu mẫu, dùng inbox chung |
| `DevConsolePage` (`/admin`) | Trước đây chỉ có danh sách Ticket | Dùng cùng component/hook với Quản lý, gồm cả tiện ích |
| `SendParcelNotificationJob` | Gửi thông báo `PARCEL` cho cư dân | Giữ nguyên; không đưa nội dung cư dân vào chuông quản lý |
| `Cicd/AlertNotificationService` | Discord/Slack/log, không có người nhận inbox nội bộ | Chưa nối vào chuông; không tự suy đoán người nhận, không tắt cảnh báo hệ thống |
| Hóa đơn, thu tiền chung, cư dân, chỉ số điện/nước, bảo trì tài sản | Có API dữ liệu và feedback thao tác; không tìm thấy producer inbox quản lý tương ứng ngoài seed/demo | Chưa có notification thật để tích hợp; không tạo sự kiện giả |
| `ResidentHome`, `ReceptionHome` | Hook/thông báo riêng | Giữ luồng cư dân/lễ tân; kiểm thử hồi quy cư dân bằng API mock |

Đã tìm trong controller/service/job/seeder và frontend: `showToast`, `setToast`, `bookingAlert`, `setAlert`, `notification`, `unread`, `role="alert"`, `fixed bottom`, `fixed top`, `user_in_app_notifications`.

## Những thay đổi hành vi

- Bỏ popup đăng ký tiện ích tự xuất hiện ở góc màn hình Quản lý. Không bỏ producer hay xóa dữ liệu thông báo.
- Bỏ bốn sự kiện mẫu: hóa đơn quá hạn, đồng bộ chỉ số, bảo trì thang máy, cư dân mới. Không đưa `overview.notifications` vào chuông nữa.
- Chỉ mở inbox khi nhấn chuông; nhận thông báo mới chỉ đổi badge và làm mới danh sách nghiệp vụ. Không tự điều hướng hoặc mở chi tiết Ticket.
- Gỡ listener tự mở Ticket từ event polling; vẫn mở Ticket từ URL/`popstate` sau thao tác chủ động.
- Phản hồi tiện ích chuyển sang vùng inline của phân hệ, giữ thông báo lỗi, lưu thành công và lỗi dữ liệu bị xóa ở tab khác.
- Giữ validation, lỗi `409`, lỗi thiếu quyền, lỗi lưu và xác nhận bỏ ảnh/hủy/lưu trữ trong Ticket/form/modal.
- Giữ feedback trực tiếp khi người dùng đổi toàn màn hình, tải lại/xuất báo cáo và thao tác các phân hệ khác. Không tắt toàn bộ toast bằng CSS.
- Header quản lý hiển thị số chưa đọc (`99+` khi lớn hơn 99), không dùng badge mẫu mặc định. Cư dân/lễ tân giữ kiểu chỉ báo cũ.

## API và bảo mật

| API | Vai trò |
|---|---|
| `GET /api/v1/manager/notifications?page=…&category=…&unread=…` | Danh sách chung, 15 dòng, unread toàn cục, latest ID để phát hiện thay đổi |
| `POST /api/v1/manager/notifications/{id}/read` | Đánh dấu đã đọc idempotent; trả unread mới từ server |
| `/api/v1/ticket-notifications` và `/{id}/read` | Giữ tương thích; nhân viên dùng cùng scope với inbox chung; cư dân chỉ lấy liên kết cổng cư dân |
| `/api/v1/admin/amenity-booking-notifications` và `/{id}/read` | Giữ tương thích và quyền `AMENITY:UPDATE`; dùng lại scope chung |
| `/api/v1/resident/amenity-notifications` | Giữ API riêng, không trả category quản lý |

- API mới dùng `auth.bearer:strict`: kiểm tra session thật, hết hạn/thu hồi; không nhận token demo.
- Người nhận lấy từ `$request->user()`, bỏ qua recipient từ client. ID khác người nhận trả `404`.
- `ManagerNotificationService` dùng một scope chung: role Admin/Quản lý/Kỹ thuật phù hợp, permission hiện hành, hồ sơ nhân viên và phạm vi tòa. Thiếu hồ sơ nhân viên trả `403`.
- Ticket phải còn tồn tại, thuộc tòa được quản lý; kỹ thuật chỉ thấy Ticket hiện giao cho mình. Đổi tòa/đổi phân công làm bản ghi cũ không còn truy cập được.
- Thông báo đặt tiện ích theo tòa của căn hộ đăng ký, kể cả tiện ích dùng chung. Không gửi tên/căn hộ sang quản lý tòa khác. Phạm vi `NULL` là toàn khu.
- Mở lại Ticket gửi điều phối viên còn hợp lệ và kỹ thuật viên. Nếu chưa có hoặc điều phối viên không còn hợp lệ, gửi quản lý đủ quyền đúng tòa; không gửi lặp cùng người nhận.
- Deep link chỉ chấp nhận cùng origin, `/admin` hoặc `/quan-ly`, đúng tab nghiệp vụ; Ticket phải có ID. Liên kết ngoài bị từ chối trước khi đánh dấu đọc. API chi tiết tiếp tục kiểm tra quyền đối tượng.
- Tiện ích ở Admin mở qua `/quan-ly?tab=amenities&…`, nơi có màn hình quản lý tiện ích thật; Ticket giữ cổng hiện tại.
- Link hoặc đối tượng không còn truy cập được: hiển thị lỗi, không giả thành công. Đọc thành công rồi refetch lỗi vẫn giữ trạng thái đã đọc/badge đã được server xác nhận.
- API mới trả `private, no-store`; không lưu inbox dùng chung trong localStorage. Thời gian trả ISO-8601 có offset theo timezone ứng dụng.

## Refresh và cạnh tranh phía trình duyệt

Một vòng polling 15 giây, không gửi thêm khi request hiện hành còn chạy. Cleanup timeout, AbortController và subscriptions khi đổi trang/bộ lọc hoặc unmount. Ngừng polling khi tab ẩn, tải lại khi quay lại/focus. Mutation version ngăn phản hồi GET cũ ghi đè thao tác đọc.

`AUTH_SESSION_CHANGED`, storage/focus và kiểm tra token khi nhận response bảo vệ đổi tài khoản; xóa dữ liệu và trả trang 1 trước khi nhận tài khoản mới. Storage event đồng bộ thao tác đọc giữa các tab; polling vẫn hoạt động khi storage không khả dụng. Không tự mở chuông từ các event này.

## File thay đổi

Backend:

- `app/Http/Controllers/ManagerNotificationController.php`
- `app/Services/ManagerNotificationService.php`
- `app/Http/Controllers/ManagementDashboardController.php`
- `app/Http/Controllers/TicketManagementController.php`
- `app/Services/ResidentAmenityBookingService.php`
- `app/Services/TicketNotificationService.php`
- `routes/web.php`

Frontend:

- `resources/js/Components/ManagerNotificationCenter.tsx`
- `resources/js/Components/Layout/AppTopbar.tsx`
- `resources/js/Components/Layout/AppLayout.tsx`
- `resources/js/Components/TicketManagementPanel.tsx`
- `resources/js/Pages/ManagementHome.tsx`
- `resources/js/Pages/Dev/DevConsolePage.tsx`
- `resources/js/Pages/Admin/AmenityManagement.tsx`
- `resources/js/Services/api.ts`
- `public/index.html` (hash bundle sau build)

Tests:

- `tests/Feature/ManagerNotificationCenterTest.php`
- `tests/Feature/AmenityBookingNotificationTest.php`
- `tests/e2e/manager-notifications.spec.ts`
- `tests/e2e/ticket-management.spec.ts`
- `tests/e2e/resident-amenity-booking.spec.ts`

## Kiểm thử và bằng chứng

Môi trường backend: MySQL Docker, database riêng `amenity_ticket_regression_index_audit_test`. Config cache và route cache dùng đường dẫn QA riêng, không xóa cache server đang phục vụ. Các test ghi dữ liệu dùng transaction rollback. PHPUnit XML mặc định ép database khác nên dùng `--no-configuration`, bootstrap rõ ràng và biến môi trường QA. Không ghi khóa/token vào báo cáo.

Runtime đã xác minh: Laravel 13.30.1, PHP container 8.4.25; React 19, Vite 8.3.0. Không dựa vào giả định PHP 8.5 trong mô tả dự án.

| Kiểm tra | Kết quả |
|---|---|
| `php vendor/bin/pint --dirty --format agent` | PASS |
| `php vendor/bin/pint --test` | PASS toàn bộ phạm vi mặc định của Pint; không sửa file ngoài phạm vi |
| `npm run typecheck` | PASS |
| `npm run build` | PASS |
| PHPUnit ban đầu: notification center, Ticket, thông báo tiện ích | PASS: 42 tests, 446 assertions |
| Artisan mở rộng: 6 file backend bên dưới | PASS bản cuối: 122 tests, 1058 assertions, 133.17s; gồm API cũ dùng scope chung, thu hồi phiên, tòa của tiện ích dùng chung và fallback điều phối viên |
| Playwright: 4 file thông báo/Ticket/tiện ích/cư dân | PASS bản cuối: 85/85, 4.0 phút. Lần đầu có một selector test sai role của `CustomSelect`; đã sửa và chạy lại đủ bộ, không skip/xóa test |
| Playwright chạy lại 6 test `manager-notifications.spec.ts` | PASS: 6/6, gồm test selector đã sửa |
| Playwright 6 case thông báo cũ được cập nhật | PASS: quản lý/Admin/cư dân, phân trang, lỗi đọc, deep link, tiện ích |
| Full PHPUnit toàn repository / browser qua backend thật / production | Chưa kiểm thử; không suy ra từ test mock |

Lệnh backend mở rộng thực tế (chạy trong container với env QA ở trên):

```text
php artisan test --compact --no-configuration --bootstrap=vendor/autoload.php tests/Feature/ManagerNotificationCenterTest.php tests/Feature/AmenityBookingNotificationTest.php tests/Feature/ResidentTicketTest.php tests/Feature/ResidentAmenityBookingTest.php tests/Feature/AmenityBookingPaymentTest.php tests/Feature/AmenityBookingApprovalTest.php
```

Lệnh UI (APP_URL trỏ `http://127.0.0.1:8126`, mọi API được mock):

```text
npx playwright test tests/e2e/manager-notifications.spec.ts tests/e2e/ticket-management.spec.ts tests/e2e/resident-amenity-booking.spec.ts tests/e2e/resident-incident-ticket.spec.ts --output=test-results/manager-notifications-regression
npx playwright test tests/e2e/manager-notifications.spec.ts --output=test-results/manager-notifications-final
npx playwright test tests/e2e/manager-notifications.spec.ts tests/e2e/ticket-management.spec.ts tests/e2e/resident-amenity-booking.spec.ts tests/e2e/resident-incident-ticket.spec.ts --output=test-results/manager-notifications-regression-final
```

Đã kiểm tra ảnh QA: desktop 1440×812 và mobile 375×812; inbox trong viewport, danh sách không bị sidebar/header che, số badge nhìn thấy. Ảnh là dữ liệu mock, không chứng minh backend production. Chỉ lưu artifact kiểm thử bị Git ignore, không đưa ảnh vào mã nguồn.

- [Desktop QA](../../test-results/manager-notifications-final/manager-notifications-Chuô-d57d8-load-bàn-phím-và-kích-thước-chromium/notification-center.png)
- [Mobile QA](../../test-results/manager-notifications-final/manager-notifications-Chuô-46b22-load-bàn-phím-và-kích-thước-chromium/notification-center.png)

## Giới hạn và sẵn sàng PR

- Không thêm đánh dấu tất cả đã đọc; backend cũ chưa có thao tác này, yêu cầu chỉ bắt buộc khi hỗ trợ an toàn.
- Các module chưa có producer/người nhận thật được ghi rõ trong inventory, chưa thể tuyên bố mọi nghiệp vụ của toàn bộ website đều đã có notification.
- Object ID của thông báo cũ được lấy từ deep link ở scope MySQL; cần trường object ID riêng nếu mở rộng định dạng liên kết hoặc thêm backend khác. Không sửa dữ liệu lịch sử chỉ để chuẩn hóa.
- Polling cập nhật trong khoảng 15 giây khi tab hiển thị, không phải WebSocket tức thời.
- Phân trang offset là snapshot theo từng request; sự kiện mới có thể dịch vị trí giữa các trang. Không nhân đôi bản ghi lưu hay ID trong một trang; không cam kết duyệt nhiều trang khi đang có ghi mới là snapshot bất biến.
- Không kết luận đã chạy tương đương trên Vercel. Chưa push, merge, deploy hoặc thay đổi production.
- Sẵn sàng tạo PR để review: các kiểm tra trong phạm vi đã đạt. Chưa commit/push/tạo PR theo yêu cầu. CI toàn repository và smoke sau triển khai vẫn là bước riêng trước nghiệm thu production.

## Ma trận nghiệm thu

| Tiêu chí | Bằng chứng / trạng thái |
|---|---|
| Không tự bật popup nghiệp vụ, chuông hay điều hướng | PASS Playwright mới và case tiện ích |
| Ticket/tiện ích chung danh sách, mới nhất trước, 15 dòng | PASS UI mock + MySQL pagination |
| Badge toàn cục, đọc idempotent, quyền người nhận | PASS MySQL bản cuối |
| Deep link đúng đối tượng, link ngoài bị chặn | PASS Ticket và tiện ích desktop/mobile |
| Không còn bốn thông báo mẫu, không nhân đôi khi render/poll | PASS source inspection + UI reload/poll; DB request lặp đã có regression |
| Validation, lỗi lưu và `409` còn rõ | PASS các case Ticket/tiện ích hiện có |
| Nhiều tab/đổi tài khoản/hidden tab/stale response | PASS 6 test mới |
| Responsive/bàn phím/Escape/focus | PASS ảnh QA, bounds assertions, Ticket/resident regression |
| Không hỏng cư dân | PASS hồi quy cư dân trong lượt UI cuối; không đổi giao diện lễ tân |
| Có regression và báo cáo | PASS; 122 test backend và 85 test UI cuối đã đạt |

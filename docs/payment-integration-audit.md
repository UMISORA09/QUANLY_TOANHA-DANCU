# Audit tích hợp thanh toán tiện ích và hóa đơn

**Kết luận: NOT READY — còn lỗi CRITICAL và HIGH đã tái hiện.**

Ngày kiểm tra: **09/10/2026**, UTC+07. Báo cáo đánh giá cả commit hiện tại và working tree; không mặc định CI xanh đồng nghĩa thanh toán đã sẵn sàng.

## A. Môi trường, Git và phạm vi

- Repository: `UMISORA09/QUANLY_TOANHA-DANCU`.
- Nhánh: `DangNguyen/resident-amenity-booking`.
- HEAD: `b96eab053373043e722beaee44c58f252e197c1b`.
- Local master: `209000d1c147be637342c9fc7748b1992f2ca5a7`.
- Merge-base với local master chính là HEAD. `git diff master...HEAD --stat` rỗng: thay đổi đã commit trên nhánh này nằm trong lịch sử local master. Không fetch hoặc khẳng định remote master đang ở cùng SHA.
- Ba commit gần nhất: vá dependency `shell-quote`; sửa collation FK MySQL; thêm VietQR, đối soát, công việc quản lý, thông báo và xử lý đóng tiện ích.
- Runtime kiểm chứng: PHP **8.4.25**, Laravel **13.30.1**, PHPUnit **12.5.34**, Pint **1.31.0**, MySQL **8.0.46**. React/TypeScript/Tailwind/Vite theo package hiện có; Vite cài trên Windows **8.3.0**, Sass **1.105.1**; Node container **18.20.4**, CI cấu hình Node **22**. Không sử dụng giả định PHP 8.5 trong hướng dẫn để kết luận về runtime đang chạy.
- Docker app chính dùng MySQL và timezone `Asia/Ho_Chi_Minh`. Các test ghi dữ liệu chỉ chạy trên **`quanly_toanha_booking_test`**. Fixture kiểm tra tên database kết thúc bằng `_test`.
- `.ai/rules` không tồn tại. Không sửa code ứng dụng, dependency, môi trường, cache thật hoặc build đang phục vụ. Không checkout/reset/merge/commit/push, không tạo giao dịch ngân hàng thật.
- Tạo probe kiểm thử tạm ngoài `app/` và `tests/`, dùng lại fixture hiện có; probe nghiệp vụ dùng `DatabaseTransactions` để rollback. Probe đồng thời dùng HTTP server nội bộ riêng, fixture tự dọn. Các file tạm và server QA được dọn sau audit. File báo cáo này là artifact duy nhất được giữ lại bởi audit.

### Working tree có sẵn trước audit

Đã sửa: `.env.example`, `app/Http/Controllers/AmenityBookingPaymentController.php`, `app/Services/AmenityBookingPaymentService.php`, `config/amenity_payments.php`, `public/index.html`, `resources/js/Components/AmenityPaymentDialog.tsx`, `resources/js/Services/api.ts`, `routes/web.php`, `tests/Feature/AmenityBookingPaymentTest.php`, `tests/e2e/resident-amenity-booking.spec.ts`.

Chưa tracked: **`app/Services/AmenitySePayCheckoutService.php`**. Không được bỏ sót file này khi chuẩn bị commit; controller/service đang tham chiếu nó. Các thay đổi chưa commit bổ sung webhook ngân hàng, form checkout có chữ ký, IPN cổng thanh toán, cấu hình và test. Composer/package lock không bị thay đổi bởi audit. Tích hợp checkout đang dùng giao thức HTML POST trực tiếp; **không có bằng chứng SDK `sepay/sepay-pg` đã được cài**.

## B. Ma trận đánh giá và liên kết thực tế

Số dòng dưới đây thuộc working tree được kiểm tra, không phải một commit đã phát hành.

| Hạng mục | Trạng thái | Rủi ro | File/Dòng | Kết quả và bằng chứng |
|---|---|---|---|---|
| Merchant Configuration | Code có; runtime thiếu | HIGH | `config/amenity_payments.php:4`, `app/Services/AmenitySePayCheckoutService.php:15` | Đọc env; sandbox/production riêng. Cấu hình chưa cache vẫn thiếu merchant, signing key và IPN key; checkout không sẵn sàng. |
| Secret Key Security | Đạt trong phạm vi source đã đọc | MEDIUM vận hành | `AmenitySePayCheckoutService.php:55`, `AmenityBookingPaymentController.php:23` | Key dùng phía server; frontend chỉ nhận chữ ký. Test không lộ secret đạt. `.env` không tracked. Chưa quét độc lập toàn bộ lịch sử Git hoặc log cũ; chưa chứng minh xoay vòng key thực tế. |
| SePay IPN | Có, chưa hoàn chỉnh | HIGH | `routes/web.php:169`, `AmenityBookingPaymentController.php:20`, `AmenitySePayCheckoutService.php:71` | Auth `X-Secret-Key`; lookup checkout đã phát hành, kiểm tra VND, số tiền/trạng thái; có audit/idempotency. VOID không xử lý tiền, sai thứ tự thất bại. |
| SePay bank webhook | Đạt các tình huống QA đã chạy | MEDIUM triển khai | `routes/web.php:168`, `AmenityBookingPaymentController.php:46`, `AmenityBookingPaymentService.php:18` | HMAC raw body + timestamp, kiểm tra người nhận, mã TI, số tiền, chiều tiền và replay. Dữ liệu QA giả lập hợp lệ; chưa nhận webhook từ SePay thật. |
| VietQR | Đạt backend/UI QA | MEDIUM vận hành | `AmenityBookingPaymentService.php:69`, `:96`, `AmenityPaymentDialog.tsx:126` | QR từ snapshot ngân hàng, số tiền và reference backend. Tài khoản cấu hình mặc định là tài khoản thật; cảnh báo sandbox có, nhưng QR trực tiếp vẫn chuyển tiền thật. |
| Payment Verification — tiện ích | Đạt happy path, có hai lỗi HIGH | HIGH | `AmenityBookingPaymentService.php:201`, `AmenitySePayCheckoutService.php:89` | Báo đã chuyển chỉ REPORTED; redirect không PAID; chỉ event xác thực hoặc người quản lý có quyền được xác nhận. VOID và trùng mã giữa kênh chưa an toàn. |
| Payment Verification — hóa đơn | Không đạt | **CRITICAL** | `ResidentPortalController.php:385`, `routes/web.php:194` | API không token hoặc của cư dân khác đều chuyển hóa đơn QA sang PAID mà không có giao dịch. |
| Duplicate Prevention | Đạt cùng kênh, không đạt giữa kênh | HIGH | `AmenitySePayCheckoutService.php:99`, `AmenityBookingPaymentService.php:217`, migration payment `:73` | Unique `(bank_bin, bank_transaction_id)` và event UUID. `PG-X` khác `X`, tái sử dụng qua xác nhận thủ công vẫn được. |
| Database Consistency | Đạt locking đã chạy; cần kiểm tra schema triển khai | MEDIUM | `AmenityBookingPaymentService.php:261`, migration payment `:35` | Khóa tiện ích → booking → payment, transaction retry. FK và unique payment tồn tại trong QA. Một số bảng legacy QA không có FK; không suy ra production cũng thiếu. |
| Payment Repository | Không có repository riêng | Thông tin | `AmenityBookingPaymentService.php:18`, `app/Repositories/Eloquent/DatabaseAmenityRepository.php:542` | Service ghi trực tiếp bằng DB; repository booking đọc payment để trả danh sách. Không coi thiếu lớp riêng là lỗi. |
| Payment History/Webhook logs | Có audit, thiếu quản trị xử lý lại | MEDIUM | `AmenitySePayCheckoutService.php:79`, `AmenityBookingPaymentService.php:277`, `database/schema/CSDL_CHUNGCU_DANCU.sql:80` | Dùng audit_logs; bảng inbound_webhooks đã có nhưng luồng này không dùng. Không có lịch sử webhook có trạng thái xử lý/retry riêng trên UI. |
| Refund | Chỉ đánh dấu cần hoàn | MEDIUM, chưa triển khai | `AmenityBookingPaymentService.php:241`, `AmenityPaymentDialog.tsx:132`, `Admin/AmenityBookingsModal.tsx:173` | Giữ số tiền lịch sử, `refund_required`, cảnh báo/thống kê. Không có API hoàn tiền hay xác nhận đã hoàn, không kiểm chứng hoàn tiền thật. |
| Frontend | Đạt 14 test fixture | MEDIUM giới hạn bằng chứng | `AmenityPaymentDialog.tsx:34`, `:43`, `:97`; `api.ts:1165`, `:1194`; `amenityCache.ts:84` | Poll 10 giây, bỏ tab ẩn, AbortController/cleanup, chặn response cũ theo mutationVersion, lỗi/thử lại, countdown, khóa gửi; BroadcastChannel/storage; desktop/mobile/bàn phím đạt. |
| PHPUnit/Playwright | Các test có sẵn đạt; probe mới phát hiện lỗi | HIGH thiếu regression | `tests/Feature/AmenityBookingPaymentTest.php:47`, `:166`, `:412`; E2E `:179`, `:474`, `:487` | 62 backend đạt; 7 lượt race đạt; 14 UI đạt. 5 probe bảo vệ nghiệp vụ thất bại, tương ứng 3 nhóm lỗi. |
| CI/CD | HEAD xanh, working tree chưa được CI kiểm tra | MEDIUM | `.github/workflows/ci.yml:193`, `:307`; `security.yml:105` | Chạy toàn bộ PHPUnit/Playwright theo directory; race test cần server riêng nên mặc định skip. Trivy exit-code 0 không chặn khi phát hiện vulnerability. |

Tên file ngắn trong bảng được hiểu dưới `app/Services/`, `app/Http/Controllers/`, `resources/js/Components/` hoặc đường dẫn đầy đủ đã nêu; migration payment là `database/migrations/2026_10_07_133624_create_amenity_booking_payments_table.php`.

### Luồng và trạng thái đã kiểm chứng

1. Booking tính phí theo slot + cọc, lưu snapshot payment và ngân hàng. Miễn phí không bị ép tạo payment. Cần duyệt → WAITING_APPROVAL; duyệt → PENDING và deadline.
2. GET payment của cư dân yêu cầu strict Bearer/session còn hiệu lực; ownership theo `resident_user_id`. API xác nhận quản lý yêu cầu `AMENITY:UPDATE`. Token demo không được dùng cho các route này.
3. QR/checkout lấy số tiền backend. Bấm đã chuyển → REPORTED, chưa đánh dấu đã nhận tiền. Report lặp không kéo dài vô hạn deadline. REPORTED giữ chỗ đến giờ bắt đầu, có cảnh báo quá thời hạn đối soát.
4. Ngân hàng webhook và gateway IPN là **hai sản phẩm/xác thực khác nhau**. Webhook dùng HMAC-SHA256; IPN dùng secret header khi merchant chọn SECRET_KEY. Không tráo key hoặc giả định cả hai đều HMAC.
5. Event hợp lệ → chung service decide, audit, payment PAID và booking is_paid. Commit xong invalidate cache; thông báo lưu vào `user_in_app_notifications`; UI polling cập nhật trạng thái. Mốc 10 giây không phải cam kết cập nhật tức thời dưới một giây.
6. Sai số tiền hoặc đến muộn → REVIEW, ghi tiền thực nhận, yêu cầu hoàn/đối soát; không khôi phục booking hủy/hết hạn. Hủy booking đã trả → giữ receipt/số tiền, REVIEW/refund_required. EXPIRED/CANCELLED không tự trở lại PAID bằng webhook muộn trong các test đã chạy.
7. `routes/console.php:17` có expiry mỗi phút; GET/list/availability cũng expire khi truy cập. REPORTED quá review_minutes không tự mất chỗ ngay; giới hạn cuối là giờ sử dụng.
8. Return URL thành công/lỗi/hủy chỉ mở lại trang booking; không dùng query string làm bằng chứng tiền. Backend checkout ký trường theo thứ tự; frontend chỉ POST đến hai URL SePay được allowlist.

## C. Các lỗi và cải thiện cần thực hiện

### C1 — CRITICAL: thanh toán giả hóa đơn không cần đăng nhập hoặc quyền sở hữu

**Bằng chứng:** `ResidentPortalController.php:385–408`, `routes/web.php:194`, `bootstrap/app.php:22`. Route chỉ có middleware web; `api/*` miễn CSRF. Controller lấy hóa đơn theo ID rồi set PAID/paid_amount/remaining_balance, không lấy người dùng, không kiểm tra receipt.

**Tái hiện an toàn:** tạo hóa đơn ISSUED 200.000đ thuộc user B trong database QA. Gửi POST `/api/v1/resident/invoices/{id}/pay` với body rỗng, không Authorization: HTTP **200**, status thành **PAID**. Tạo lại fixture và gửi với token user A: cũng PAID. Hai assertion mong giữ ISSUED đều thất bại. Fixture rollback, không chuyển tiền.

**Tác động:** người biết ID có thể xóa công nợ bằng thanh toán giả và tác động hóa đơn người khác. Luồng này độc lập với các guard thanh toán tiện ích; tiện ích đạt test không bảo vệ hóa đơn.

**Đề xuất:** chặn endpoint ghi PAID giả; yêu cầu strict auth và ownership, tạo yêu cầu thanh toán thay vì credit trực tiếp; chỉ cập nhật công nợ từ receipt đáng tin cậy trong transaction/idempotency. Thêm regression không token, khác chủ sở hữu, thiếu receipt và replay. Audit không tự sửa module hóa đơn.

### H1 — HIGH: TRANSACTION_VOID không ngăn hoặc đảo trạng thái đã thanh toán

**Bằng chứng:** `AmenitySePayCheckoutService.php:73–95`: event ID chứa notification_type; mọi event khác ORDER_PAID chỉ audit, notify và return. Không có marker VOID chống ORDER_PAID đến sau; không đưa PAID đang bị hủy giao dịch về trạng thái cần xử lý.

**Tái hiện:** checkout QA; gửi hai IPN có đúng secret, cùng transaction và invoice. (a) VOID → ORDER_PAID; (b) ORDER_PAID → VOID. Cả hai endpoint trả 200; cả hai thứ tự để payment **PAID**. Hai probe mong payment không còn PAID thất bại.

**Tác động:** booking vẫn được xem đã thanh toán sau thông báo hủy giao dịch; event đến sai thứ tự có thể credit giao dịch đã bị hủy. Probe là payload fixture theo validation hiện có, chưa khẳng định đã nhận một VOID thực tế từ ngân hàng/gateway.

**Đề xuất:** định nghĩa chuyển trạng thái VOID theo hợp đồng provider, đối chiếu transaction gốc và ghi trạng thái cuối trong cùng khóa payment. Khi không thể tự kết luận, chuyển REVIEW và cảnh báo, giữ lịch sử; không tự khôi phục hoặc hoàn tiền khi chưa xác minh. ORDER_PAID cũ sau VOID phải bị chặn. Thêm hai regression thứ tự event.

### H2 — HIGH: cùng mã giao dịch được credit hai booking khi đi qua IPN và thủ công

**Bằng chứng:** IPN lưu `PG-` + transaction_id tại `AmenitySePayCheckoutService.php:99`; manual/webhook lưu reference không cùng quy ước tại `AmenityBookingPaymentService.php:217` và `:48`. Unique constraint chỉ so sánh chuỗi chính xác.

**Tái hiện:** IPN hợp lệ trả PAID cho booking A với transaction_id `audit-bank`, lưu `PG-AUDIT-BANK`. Quản lý có quyền xác nhận booking B bằng `audit-bank`, cùng số tiền/thời điểm. API trả 200; database có **2 payment PAID**. Probe mong đúng một payment được credit thất bại.

**Điều kiện/tác động:** cần quản lý nhập lại mã gốc hoặc các kênh trình bày cùng receipt theo cách khác nhau; đây là lỗi nhất quán đối soát, không phải cư dân tự vượt quyền. Đồng nhất mã thực tế giữa gateway và ngân hàng còn phải đối chiếu với provider; không giả định hai sản phẩm luôn trả cùng identifier.

**Đề xuất:** giữ nguồn giao dịch riêng, định danh/canonical mapping receipt chung trước kiểm tra unique, ngăn receipt gateway bị xác nhận lại bằng mã gốc. Không chỉ bỏ mọi tiền tố PG- một cách mù quáng. Test IPN↔manual, webhook↔manual và IPN↔webhook theo mapping đã xác minh.

### H3 — HIGH: runtime đang phục vụ route/config cache cũ, checkout chưa cấu hình

**Bằng chứng runtime:** routes_cached/config_cached đều true; `route:list --path=amenity-payments` với cache thật không tìm thấy route. Với APP_ROUTES_CACHE riêng chưa tồn tại, cả IPN và webhook hiện ra. Cache thật không thấy webhook secret; cấu hình chưa cache có webhook secret nhưng merchant/signing/IPN secret vẫn chưa cấu hình. `checkout_available=false` ở cả hai.

**Tác động:** source có tính năng nhưng runtime không cung cấp đúng route hoặc xác thực mới; checkout thực tế chưa mở được. Chưa có bằng chứng URL HTTPS public nhận được IPN; ảnh dashboard trước đó cũng không thay cho test kết nối.

**Đề xuất:** cấu hình thông tin merchant/key/IPN URL qua kênh bảo mật, đặt đúng kiểu auth trong dashboard, cập nhật config/route cache bằng quy trình deploy, kiểm tra route và gửi test từ SePay. Không đưa secret vào report/Git. Audit không tự xóa cache thật hoặc cấu hình tài khoản.

### M1 — MEDIUM: build working tree không tái lập được ở môi trường hiện tại

- Windows: typecheck đạt, Vite build thất bại với `Preprocessor dependency "sass-embedded" not found`.
- Container: build thất bại trước compile vì Node 18 không export `node:util.styleText` mà bundler yêu cầu.
- Không thêm dependency, đổi Node hoặc ghi đè build ứng dụng trong audit. CI HEAD xanh là một môi trường/commit khác với các thay đổi chưa commit.

**Đề xuất:** đồng bộ Node theo CI/engine thực tế và bộ dependency qua quy trình được duyệt; kiểm tra Sass integration cho Vite đang dùng, clean install/build trong môi trường riêng trước phát hành. Không sửa nghiệp vụ để che lỗi môi trường.

### M2 — MEDIUM: giao dịch bất thường và hoàn tiền chưa có quy trình hoàn tất

`AmenityBookingPaymentService.php:219–223` giữ receipt đầu tiên khi nhận giao dịch bổ sung, chỉ notify; raw event được audit nhưng không cộng vào tiền thực nhận hoặc refund_pending ở modal (`Admin/AmenityBookingsModal.tsx:169–173`). Sai reference/receiver được lưu event, notify và ack, không có công việc retry/assign receipt riêng. `refund_required` chưa có thao tác xác nhận đã hoàn, mã hoàn hoặc lịch sử hoàn.

**Tác động:** cần xem SePay/sao kê để xử lý giao dịch dư và hoàn tiền; thống kê trên UI chưa thể hiện toàn bộ dòng tiền. Đây là giới hạn đã triển khai, không được quảng bá như hoàn tiền tự động.

**Đề xuất:** trước dùng tiền thật, chốt quy trình đối soát và ghi nhận xử lý/hoàn thủ công, tổng hợp các giao dịch bổ sung. Tận dụng cấu trúc receipt/webhook hiện có nếu phù hợp; không mặc định phải tạo bảng mới.

### M3 — MEDIUM: event log không có kết quả xử lý bền vững/retry nội bộ

`AmenitySePayCheckoutService.php:75–82`, `AmenityBookingPaymentService.php:22–29` lưu event audit trong transaction xử lý. Exception rollback mất audit nhận event; event đã ack nhưng chưa khớp booking không được tự xử lý lại sau sửa dữ liệu vì idempotency coi đã nhận. Có bảng `inbound_webhooks` với processing_status/error/retry_count trong schema (`database/schema/CSDL_CHUNGCU_DANCU.sql:80`) nhưng chưa dùng ở module này.

**Đề xuất:** bổ sung theo dõi kết quả/failed/ignored và thao tác replay có kiểm soát; lưu failed event qua cơ chế không bị rollback cùng nghiệp vụ, che/redact header bí mật. Tách lỗi nghiệp vụ cần người xử lý khỏi lỗi tạm thời cần provider retry. Chưa test mất DB hoặc provider retry thật.

### M4 — MEDIUM: độ tin cậy schema legacy và môi trường QA cần đối chiếu

Đọc schema thực tế bằng Laravel Schema: payment mới có FK booking/user; `payments`, `payment_receipts`, `invoices`, `amenity_bookings` trong **QA** không có FK dù dump MySQL mô tả các FK hóa đơn. Unique/index vẫn có. Không có bằng chứng schema production có cùng thiếu sót.

**Đề xuất:** đối chiếu schema QA/staging/production trước release, xác định nguồn tạo database và orphan record. Không thêm FK vào dữ liệu đang có khi chưa phân tích tác động. Rollback payment migration chỉ drop table (`:83`), có tính phá hủy lịch sử nếu chạy trên database có dữ liệu; cần backup khi rollback thực tế.

### L1 — LOW: security container scan không chặn pipeline

`.github/workflows/security.yml:105` dùng Trivy `exit-code: '0'`. Workflow xanh không chứng minh image không có vulnerability. Đề xuất phân biệt advisory scan và release gate, cân nhắc policy severity/blocking phù hợp. Composer/npm audit trong phiên này sạch; chưa quét lại image độc lập.

### L2 — LOW: xác nhận đã xem sao kê chỉ được kiểm tra ở UI

`AmenityPaymentDialog.tsx:139–143` có checkbox required; `api.ts:1194` không gửi sự xác nhận đó, Form Request `ConfirmAmenityBookingPaymentRequest.php:27` chỉ kiểm tra mã/số tiền/thời điểm. Người quản lý có quyền có thể gọi API mà không có thao tác xác nhận checkbox. Không phải bypass quyền và không thể tự chứng minh sao kê bằng boolean; nên lưu bằng chứng/attestation nếu nghiệp vụ yêu cầu audit trách nhiệm.

## D. Test đã chạy, kết quả và giới hạn

### Backend hiện có — PASS

```powershell
docker compose exec -T -e APP_CONFIG_CACHE=/tmp/payment-audit-config.php -e APP_ROUTES_CACHE=/tmp/payment-audit-routes.php -e DB_DATABASE=quanly_toanha_booking_test -e CACHE_STORE=array app php artisan test --compact tests/Feature/AmenityBookingPaymentTest.php tests/Feature/AmenityBookingNotificationTest.php tests/Feature/AmenityClosureTest.php tests/Feature/ResidentAmenityBookingTest.php
```

**62 passed, 515 assertions**, 76,37 giây. Bao phủ checkout server amount/secret, auth/ownership/expiry, chữ ký/timestamp, replay, wrong receiver/reference, thiếu/thừa tiền, tiền trước duyệt/đến muộn, receipt unique cùng quy ước, trạng thái, miễn phí/legacy, cancel/closure/refund flag và thông báo. Không phải tiền ngân hàng thật.

### Probe audit — FAIL, tái hiện lỗi thật

```powershell
docker compose exec -T -e APP_CONFIG_CACHE=/tmp/payment-audit-config.php -e APP_ROUTES_CACHE=/tmp/payment-audit-routes.php -e DB_DATABASE=quanly_toanha_booking_test -e CACHE_STORE=array app vendor/bin/phpunit .audit-tmp/PaymentAuditProbeTest.php --filter=test_audit --colors=never
docker compose exec -T -e APP_CONFIG_CACHE=/tmp/payment-audit-config.php -e APP_ROUTES_CACHE=/tmp/payment-audit-routes.php -e DB_DATABASE=quanly_toanha_booking_test -e CACHE_STORE=array app vendor/bin/phpunit .audit-tmp/PaymentAuditProbeTest.php --filter=test_audit_gateway --colors=never
```

Lần đầu **4 failures / 16 assertions**: anonymous invoice, other-user invoice, VOID trước/sau PAID. Lần bổ sung **1 failure / 7 assertions**: IPN receipt tái sử dụng thủ công. Tổng **5 probe thất bại**, không phải lỗi môi trường. Kỳ vọng và dữ liệu tái hiện nằm trong C1/H1/H2; probe tạm được dọn, chưa thêm regression vào repository vì audit cấm sửa code.

### Concurrency — PASS với MySQL và nhiều worker

Server QA dùng `php -S 127.0.0.1:9002 -t public public/index.php`, DB test, APP_ENV=testing, CACHE_STORE=array, PHP_CLI_SERVER_WORKERS=4 và webhook secret chỉ dành cho fixture. Không dùng cổng/server chính.

```powershell
docker compose exec -T -e APP_CONFIG_CACHE=/tmp/payment-audit-config.php -e APP_ROUTES_CACHE=/tmp/payment-audit-routes.php -e DB_DATABASE=quanly_toanha_booking_test -e CACHE_STORE=array -e TEST_HTTP_BASE_URL=http://127.0.0.1:9002 app vendor/bin/phpunit tests/Feature/ResidentAmenityBookingConcurrencyTest.php .audit-tmp/PaymentAuditConcurrencyTest.php --filter='test_audit|concurrent_payment|payment_confirmation_after' --colors=never
```

**7 test executions passed, 41 assertions**, 48,56 giây: 3 probe mới và 2 test có sẵn (2 test có sẵn chạy thêm qua inheritance). **5 tình huống khác nhau**, không tính thành 7 tình huống độc lập. Probe giữ khóa tiện ích khi khởi động curl_multi, sau đó nhả khóa để ép requests tranh nhau:

- Hai webhook cùng event: `[200,200]`, một payment update audit, PAID một lần.
- Quản lý và webhook cùng receipt chuẩn hóa giống nhau: `[200,200]`, một update; không chứng minh trùng giữa các namespace đã nêu H2.
- Webhook và resident cancel: `[200,200]`, cuối booking CANCELLED/payment REVIEW/refund_required, không khôi phục.
- Hai quản lý khác receipt: `[200,409]`, một update.
- Receipt phải chờ khóa tới khi payment hết hạn: REVIEW, không giữ lại chỗ.

Không thử tải lớn, nhiều server, network partition hoặc crash giữa commit/ack; các lượt đạt không phủ nhận lỗi VOID/cross-channel.

### Frontend — PASS fixture, build FAIL

```powershell
npm run typecheck
npx vite build --outDir .audit-tmp/build --emptyOutDir
docker compose exec -T app npx vite build --outDir /tmp/payment-audit-build --emptyOutDir
npx playwright test tests/e2e/resident-amenity-booking.spec.ts --grep 'thanh toán|QR|checkout|đối soát|trả tiền' --reporter=list --output=.audit-tmp/playwright
npx playwright test tests/e2e/resident-amenity-booking.spec.ts --grep 'tab khác|bàn phím' --reporter=list --output=.audit-tmp/playwright-sync
```

- TypeScript: **PASS**, exit 0.
- Hai build: **FAIL** vì môi trường M1. Dùng output riêng, không chạy sync-index-html ghi đè public/index.html. Không kết luận build working tree thành công.
- Playwright thanh toán: **12 passed**, 1,4 phút; gồm duyệt/mở QR, đối soát thủ công, hủy đã trả/statistics, mạng lỗi/expiry, poll PAID, checkout POST/409 và QR không tự PAID trên desktop/mobile.
- Playwright đồng bộ tab/bàn phím: **2 passed**, 16,9 giây. Tổng **14 UI test khác nhau đạt** trong phiên audit.
- Lượt chạy đầu trong sandbox filesystem: **12 runner failures** do không tìm thấy Chromium trong profile sandbox; chưa đến assertion UI. Chạy lại cùng lệnh ngoài profile sandbox dùng Chromium có sẵn đạt 12/12, không cài browser/dependency mới.
- Các bài UI intercept API và checkout provider bằng fixture, sử dụng bundle hiện có đang phục vụ tại localhost. Không chứng minh source có thể build sạch, merchant thật hoạt động, IPN công khai hoặc browser–MySQL toàn luồng thật.

### Lint/security/GitHub Actions

```powershell
docker compose exec -T app php -l <file>
docker compose exec -T app composer audit --locked --no-interaction
npm audit --audit-level=high
gh run list --branch DangNguyen/resident-amenity-booking --limit 5 --json databaseId,headSha,status,conclusion,workflowName,url
git diff --check
```

PHP lint **PASS** cho hai service payment, controller payment, config payment, routes và payment Feature test. Composer audit **PASS**, không có security advisory; npm audit **PASS**, 0 vulnerabilities. `git diff --check` lúc đầu đạt, nhưng kiểm tra cuối báo **FAIL** vì trailing whitespace tại `.env.example:76` (`RUN_MIGRATIONS=true`); file này không được audit chỉnh sửa. Working tree không phải snapshot bất biến. Không chạy Pint để autoformat/ghi đè các PHP change có sẵn; không tuyên bố working tree đã qua Pint trong phiên audit.

GitHub run cho HEAD hiện tại: [CI thành công](https://github.com/UMISORA09/QUANLY_TOANHA-DANCU/actions/runs/37891573602), [Security thành công](https://github.com/UMISORA09/QUANLY_TOANHA-DANCU/actions/runs/37891573611), [CD thành công](https://github.com/UMISORA09/QUANLY_TOANHA-DANCU/actions/runs/37891573682). Những run này không bao gồm file chưa commit, probe audit hoặc file service chưa tracked.

CI chạy PHPUnit toàn Feature/Unit, MySQL test DB, migration/rollback bước cuối/re-migrate; E2E dùng SQLite và Playwright mock, không tự thiết lập TEST_HTTP_BASE_URL cho race test. Live resident E2E cần server/fixture riêng, không được tính là đạt nếu skip. Security dùng Gitleaks, Composer/npm, Trivy. Chưa chạy lại toàn bộ suite CI, Trivy hay Gitleaks độc lập trong phiên này.

### Migration/database/provider chưa kiểm chứng đầy đủ

- `php artisan migrate:status`: migration payment đã Ran trên môi trường đang chạy. QA Schema xác nhận unique booking_id/reference, unique bank_bin+bank_transaction_id, index status+expires_at, hai FK payment. Không drop/rollback database có dữ liệu trong audit.
- Các bảng payments/payment_receipts/invoices có thật nhưng API payInvoice không ghi receipt đáng tin cậy. Tiện ích dùng bảng payment riêng; không mặc định đã đồng bộ vào sổ thu hóa đơn chung.
- Audit event dùng audit_logs, không có bảng tên webhook_logs; inbound_webhooks tồn tại trong schema nhưng chưa nối module. Không cần tạo bảng mới để báo cáo.
- SQL Server không chạy trong cấu hình Docker đã kiểm tra; không test trigger/backend SQL Server và không kết luận tương thích.
- Chưa tạo đơn ở SePay sandbox thật, chưa cấu hình dashboard, chưa kiểm tra TLS/public ingress, provider retry, rotation key hay chuyển khoản/hoàn tiền thật. Các thao tác có tiền thật bị loại khỏi audit theo yêu cầu.

## E. Nguồn provider và điều kiện nghiệm thu

Đối chiếu tài liệu chính thức, không suy đoán cơ chế xác thực:

- Bank webhook HMAC raw body/timestamp: [SePay webhook authentication](https://developer.sepay.vn/vi/sepay-webhooks/xac-thuc).
- IPN secret header, loại event và yêu cầu HTTPS public: [SePay gateway IPN](https://developer.sepay.vn/vi/cong-thanh-toan/IPN).
- Retry/ack webhook: [SePay tích hợp webhook](https://developer.sepay.vn/vi/sepay-webhooks/tich-hop-webhook).
- Checkout/form SDK tham chiếu: [SePay PHP SDK](https://developer.sepay.vn/vi/cong-thanh-toan/sdk/php), [SignatureGenerator chính thức](https://github.com/sepayvn/sepay-pg-php/blob/main/src/Auth/SignatureGenerator.php), [CheckoutResource chính thức](https://github.com/sepayvn/sepay-pg-php/blob/main/src/Resources/CheckoutResource.php). Đọc source giao thức không chứng minh SDK đã cài.

**Thứ tự cần làm:** C1 → H1/H2 → cấu hình/cache H3 → khắc phục build M1 → thêm regression vào suite và chạy CI working tree đã commit → test SePay sandbox thật trên database/tài khoản test riêng → chốt quy trình đối soát/hoàn và vận hành. Không tự thay đổi branch khác hoặc module khác trong bước sửa sau này.

**NOT READY** áp dụng toàn payment scope được yêu cầu, vì C1 vẫn cho thanh toán giả và H1/H2 gây lệch tiền. Phần tiện ích có nền tảng QR, auth, locking, audit, notification và nhiều test đạt, nhưng chưa đủ để gọi SANDBOX READY với cấu hình runtime hiện tại. **Không có bằng chứng để kết luận PRODUCTION READY.**

## F. Bản vá sau audit — 09/10/2026

Các phần A–E giữ kết quả trước sửa. Theo yêu cầu triển khai tiếp theo:

- Nhánh `DangNguyen/resident-amenity-booking`: xử lý VOID thành REVIEW, gỡ is_paid, hủy booking còn chờ/sắp sử dụng; bảo toàn receipt lịch sử và không tự hoàn tiền. PAID đến sau VOID bị chặn; VOID khác receipt đang được ghi nhận không xóa thanh toán hợp lệ. Các IPN được serialize theo khóa tiện ích.
- Receipt mới giữ transaction_id gốc. Kiểm tra và giữ chỗ receipt/alias PG- cũ bằng unique UUID trong audit_logs, bảo vệ cả hai tiện ích khác nhau; không đổi receipt lịch sử, không tạo bảng/dependency. Alias mơ hồ bị chặn để đối soát, không tự suy ra hai identifier của provider là cùng giao dịch thật.
- Webhook dashboard id=0 hoặc chuỗi "0" được ack sau xác thực/validation, không ghi nhận tiền. `.env.example` giải thích tách HMAC webhook, merchant và IPN secret.
- C1 sửa **riêng** trong worktree `C:/Users/Asus/.codex/worktrees/invoice-payment-security/QUANLY_TOANHA-DANCU`, nhánh `codex/invoice-payment-security`: strict session + ownership, chặn thanh toán giả với 409, không tự set PAID. Chưa xây dựng cổng thanh toán hóa đơn. Bản vá này **chưa merge vào nhánh tiện ích/master**, nên không được coi API hóa đơn ở checkout chính đã vá.
- Cache config/routes của app chính đã cập nhật; cả webhook và IPN xuất hiện trong route:list. Không điền hoặc in key thật; checkout vẫn cần merchant/signing/IPN key của người dùng.
- Không commit/push. Các giới hạn hoàn tiền, log/replay và xác minh provider thật vẫn còn.

Kiểm chứng sau sửa: 67 backend liên quan đạt (564 assertions); sau bổ sung dashboard test, toàn file payment đạt 40 test (325 assertions tại lượt chạy đó), tổng 68 tình huống backend khác nhau đã qua các lượt kiểm tra. Có 4 race test đạt (28 assertions), gồm receipt alias ở hai tiện ích và IPN VOID/PAID đồng thời. Nhánh hóa đơn có 4 security test đạt (15 assertions) trên container QA riêng; key encryption chỉ dành cho QA, không dùng key thật. Pint cả hai nhánh và TypeScript đạt. Build output riêng đạt với Node Windows 24.19.0/Vite 8.3.0 khi chạy ngoài profile filesystem sandbox; không cài thêm Sass/dependency. Lỗi build Windows ở M1 không tái hiện trong môi trường này; Node 18 trong Docker vẫn chưa được nâng cấp. Không chạy lại Playwright vì bản vá mới không đổi frontend; kết quả UI A–E thuộc lần audit trước.

Các test mới lưu vào `tests/Feature/AmenityBookingPaymentTest.php`, `tests/Feature/ResidentAmenityBookingConcurrencyTest.php`; test hóa đơn nằm trong nhánh riêng tại `tests/Feature/ResidentInvoicePaymentSecurityTest.php`. Concurrency vẫn cần TEST_HTTP_BASE_URL và TEST_SEPAY_IPN_SECRET trỏ đúng server/database QA. Server QA được dừng sau kiểm tra; không dùng dữ liệu chính để thử receipt.

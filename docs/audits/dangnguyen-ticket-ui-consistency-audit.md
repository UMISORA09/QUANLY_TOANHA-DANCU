# Kiểm tra đồng bộ giao diện Ticket F5–F8

Ngày kiểm tra: 19/10/2026. Các thay đổi được phân về các nhánh chức năng 5, 6, 7 và 8; không tiếp tục dùng nhánh audit.
Nền của lượt audit ban đầu sau `git fetch origin`: `origin/master` tại `bda97bdbea99660542ccf1f85e97bee36ab0aeb2`.
Working tree sạch trước khi tạo nhánh. Đợt kiểm thử không push, merge hoặc deploy. Sau yêu cầu tách nhánh, các bản sửa được giữ bằng commit cục bộ trên đúng nhánh chức năng.

## Phạm vi và phương pháp

Đã đọc `AGENTS.md`; checkout hiện không có `.ai/rules`. Dùng skill `e2e-testing-patterns` cho kiểm thử trình duyệt có dữ liệu riêng. Không tìm thấy skill `testing-best-practices` tại đường dẫn skill cục bộ; các bài PHPUnit chạy lại là test đã có trong dự án.

Đã đối chiếu mã nguồn các màn hình tiện ích, form tiện ích, danh sách đăng ký, worklist và cổng cư dân với `ResidentTicketPanel`, `TicketManagementPanel`, `TicketDialog`, `TicketImage`, `TicketNotifications`, `CustomSelect`, các điểm tích hợp ResidentHome/ManagementHome, API client và CSS.

Đã chạy Chromium thực tế với API giả lập (**MOCKED E2E**) cho các tình huống giao diện và lỗi. Ngoài ra đã chạy **một luồng API thật** trên database MySQL QA riêng. Không lấy dữ liệu hay ghi lên production.

Runtime xác minh: PHP CLI Windows/container 8.4.25, Laravel 13.30.1, PHPUnit 12.5.34; React/React DOM 19.3.0, TypeScript 7.0.2, Vite 8.3.0, Playwright 1.63.0. Runtime PHP thực tế khác PHP 8.5 mô tả trong AGENTS; không đổi dependency hay runtime.

## Baseline được chọn

F1–F4 chưa dùng duy nhất một kiểu control: AmenityManagement có combobox riêng, một số bộ lọc đăng ký dùng native select với CSS `base-select`; danh mục tiện ích cư dân phân trang client, worklist phân trang API. Không sao chép các khác biệt này sang Ticket.

Giữ phong cách chung: card trắng/border neutral, card và modal `rounded-2xl`, input/button `rounded-xl`, nút chính màu đen, badge có nhãn và màu, khoảng cách 12–20px. Ticket dùng control tối thiểu 44px ở các form/lọc đã chỉnh và card trên mobile, bảng trên desktop. Giữ `CustomSelect overlay` với Popover Top Layer cho Ticket: dropdown thoát vùng cuộn, tự đổi hướng và định vị lại khi cuộn/resize.

Giữ debounce Ticket 350ms thay vì ép theo tìm kiếm tiện ích quản lý 150ms hoặc lọc catalog tại client. Ticket tiếp tục phân trang **15 dòng phía server**, không tải toàn bộ dữ liệu để lọc ở frontend.



## Bảng tổng hợp

`PASS` dưới đây chỉ áp dụng cho tình huống đã chạy trong bộ kiểm thử nêu bên dưới. `PARTIAL` nghĩa là còn giới hạn cụ thể, không phải kết luận đạt toàn bộ nghiệp vụ.

| Hạng mục | Baseline F1–F4 | F5 | F6 | F7 | F8 | Trạng thái |
|---|---|---|---|---|---|---|
| Combobox | Nhiều triển khai, bo góc/cuộn | PASS | N/A | PASS | PASS | PASS trên Chromium và các viewport đã thử |
| Search | Server quản lý, client catalog cư dân | PASS | N/A | PASS | N/A, dùng danh sách F7 | PASS về UI/request; chưa kiểm đủ collation DB |
| Debounce | Quản lý 150ms, catalog lọc client | PASS 350ms | N/A | PASS 350ms | N/A | PASS |
| Filter | Lọc danh mục/tòa/trạng thái | PASS, thêm ưu tiên | N/A | PASS trong các tổ hợp đã thử | N/A, dùng F7 | PARTIAL, chưa chạy mọi tích Descartes bộ lọc |
| Clear filters | Có nhiều mẫu xóa lọc | PASS | N/A | PASS | N/A | PASS, xóa từ khóa và lọc đồng thời |
| Pagination | Catalog 15/client, worklist API | PASS 15/server | N/A | PASS 15/server | Dùng F7 | PASS với 35 bản ghi giả lập |
| Loading | Spinner/status/cập nhật nền | PASS danh sách và gửi form | PASS tải ảnh | PASS | PASS luồng chuẩn | PARTIAL, chưa giả lập mọi timeout |
| Empty state | Có thông báo trống | PASS, phân biệt không có kết quả | PASS ảnh trống | PASS | PASS thiếu kỹ thuật viên | PASS trong các trường hợp đã thử |
| Error handling | Lỗi mạng/thử lại/conflict | PARTIAL | PASS HTTP và ảnh hỏng | PASS mạng và sửa 409 | PARTIAL | PARTIAL, xem giới hạn 409 bên dưới |
| Modal | Card giữa màn hình, body cuộn | PASS các ca đã thử | PASS preview/xác nhận bỏ ảnh lưu | PASS | PASS phân công và luồng thật | PARTIAL, lưu trữ chưa chạy API thật |
| Responsive | Card/grid và bảng có cuộn | PASS | PASS | PASS | PASS phân công | PASS ở 5 kích thước đã thử |
| Keyboard/Accessibility | Mức hỗ trợ khác nhau giữa control | PASS Escape/focus đã thử | PASS preview Escape | PASS dropdown/phục hồi focus | PASS dropdown kỹ thuật viên | PARTIAL, chưa audit screen reader toàn diện |

## Lỗi và khác biệt đã sửa

| Mã / mức độ | Vị trí | Cách tái hiện / bằng chứng | Bản sửa và kết quả |
|---|---|---|---|
| UI-01 / P2 | `ResidentTicketPanel.tsx:239`, `TicketManagementPanel.tsx:45` | Mở trang, đổi trang trong thời gian debounce đầu tiên: timer tìm kiếm rỗng có thể đưa về trang 1. Bộ test phân trang mới phát hiện trước khi sửa. | Chỉ đặt timer khi từ khóa thực sự khác từ khóa đã áp dụng; giữ 350ms khi nhập. Test chuyển trang nhanh qua. |
| UI-02 / P2 | `ResidentTicketPanel.tsx:307`, `TicketManagementPanel.tsx:52` | Có 35 dòng, ở trang 3, API sau cập nhật chỉ còn 30 dòng/2 trang; UI cũ ở trang 3 trống. Hai test ban đầu FAIL với 0 thay vì 15 dòng. | Giới hạn trang theo `last_page` tối thiểu 1 rồi tải lại trang hợp lệ. Test xác minh chuỗi request và số dòng 15/15/5. |
| UI-03 / P2 | `ResidentTicketPanel.tsx:1004–1049` | `priorityFilter` được gửi API nhưng không có control để chọn; không có nút xóa toàn bộ lọc; empty state khi lọc giống chưa từng có yêu cầu. Backend đã hỗ trợ priority. | Dùng CustomSelect hiện có cho ưu tiên; thêm xóa từ khóa, xóa bộ lọc, empty state phù hợp; căn lại hàng desktop. Không đổi API. |
| UI-04 / P2 | Hai handler `Xóa bộ lọc` trong hai Ticket panel | Từ trang 3 tìm kiếm, xóa lọc: quản lý gửi thêm một request với từ khóa cũ trước request rỗng. Test chuỗi request chính xác tái hiện. | Xóa cả query/debounced query cùng bộ lọc trong một hành động; không bỏ debounce nhập liệu. |
| UI-05 / P2 | `ResidentTicketPanel.tsx:1422` trở đi; footer quản lý `TicketManagementPanel.tsx:141` | Các modal cư dân còn là div riêng; nút Đóng vẫn enabled khi POST hủy đang chờ. Test giữ request hủy xác nhận FAIL trước sửa. Footer quản lý còn nằm trong body cuộn. | Tái sử dụng TicketDialog cho sửa/hủy/nghiệm thu/mở lại; đưa nút ra footer riêng, khóa đóng khi busy, hiển thị lỗi bên trong modal. Giữ dữ liệu khi lỗi. |
| UI-06 / P2 | `TicketDialog.tsx:9–18` | Sau đóng modal cư dân bằng nút Đóng, focus không trở lại nút mở. Test bàn phím FAIL trước sửa. | Ghi nhớ focus trước showModal, đóng dialog và khôi phục focus/body scroll trong cleanup. Test đã qua. |
| UI-07 / P2 | `TicketImage.tsx:22` | Endpoint ảnh trả 200/image/png nhưng bytes hỏng: img vỡ, không có Thử tải lại. Test lần lượt 503 → ảnh hỏng → PNG hợp lệ FAIL trước sửa. | Xử lý sự kiện lỗi giải mã ảnh bằng error state/thử lại đã có. Test qua, đúng 3 lần tải. |
| UI-08 / P2 | `TicketManagementPanel.tsx:127,152` | Bấm Bỏ ảnh của ảnh đã lưu gửi DELETE ngay. Test mong hộp xác nhận FAIL trước sửa. | Hộp xác nhận có busy/error; Đóng không gửi DELETE; xác nhận gửi một DELETE với version, cập nhật chi tiết và danh sách. |
| UI-09 / P3 | Nút ảnh đã chọn và phân trang cư dân | Vùng bấm ảnh nhỏ trên điện thoại; bộ lọc ưu tiên mới làm phát sinh hàng đơn lẻ trên desktop. Đã đối chiếu screenshot. | Tăng vùng bấm lên 44px, hàng bộ lọc desktop 5 cột với search chiếm 2 cột. Ảnh chụp và kiểm thử responsive đã chạy lại. |

Không phát hiện P0/P1 trong các tình huống đã chạy; kết quả này không thay thế audit toàn bộ phân quyền/nghiệp vụ.

## Kiểm thử và kết quả

Máy chủ frontend QA: `http://127.0.0.1:8126`, chạy với `APP_ENV=testing`, session/cache array và config cache riêng. Tất cả endpoint `/api/**` trong hai spec UI được route giả lập, dùng tài khoản và dữ liệu QA.

Các viewport: **375×812, 390×844, 768×1024, 1024×768, 1440×900**. Test quản lý còn resize chiều cao giảm 20px để kiểm tra định vị dropdown lại.

| Nhóm | Đã xác minh | Loại bằng chứng |
|---|---|---|
| Dropdown | Danh mục 30+, kỹ thuật viên 30; option đầu/cuối; Home/End, ArrowDown, Escape; click ngoài; menu nằm trong viewport và hit-test không bị lớp khác che; cuộn modal/resize; reset căn hộ/người báo khi đổi tòa | MOCKED E2E, hình học DOM và ảnh |
| Tìm kiếm | Gửi “điện” chậm hơn “điện nước”; không dùng dữ liệu cũ; debounce; kết hợp ưu tiên, clear; lỗi 503, thử lại, không có kết quả | MOCKED E2E |
| Phân trang | 35 dòng → trang 1/2/3 có 15/15/5; quay lại; tìm kiếm/lọc trạng thái từ trang 3 về trang 1; rút còn 30 dòng khi tải lại; kiểm tra chính xác request | MOCKED E2E |
| Modal | Validation tạo, giữ nội dung khi mạng lỗi, giữ khóa chống trùng; giữ lý do khi hủy lỗi; chặn đóng khi busy; 409 sửa quản lý giữ input và yêu cầu xem bản mới; focus/body scroll | MOCKED E2E |
| Ảnh | Chọn/xem/bỏ ảnh chưa gửi; preview mobile/desktop; retry HTTP lỗi và ảnh hỏng; xác nhận bỏ ảnh đã lưu | MOCKED E2E |
| Thông báo | Chuông resident/manager/admin, phân trang, lỗi đánh dấu đọc, chặn link khác origin, mở đúng ticket_id, nhận thông báo đầu tiên sau feed rỗng | MOCKED E2E |
| Workflow | Tạo kèm ảnh, nhận, phân công, kỹ thuật bắt đầu, thêm ảnh kết quả, resolve, cư dân reopen, resolve lại, nghiệm thu | API thật trên MySQL QA |

Các lệnh của lượt audit tích hợp trước khi tách nhánh (PowerShell đặt `APP_URL=http://127.0.0.1:8126` trước Playwright):

```text
npm run typecheck
npm run build
git diff --check
npx playwright test tests/e2e/resident-incident-ticket.spec.ts tests/e2e/ticket-management.spec.ts --reporter=list --output=test-results/ticket-ui-verified
npx playwright test tests/e2e/resident-amenity-booking.spec.ts ... --reporter=list
npx playwright test tests/e2e/ticket-management.spec.ts --grep 'form, combobox' --reporter=list --output=test-results/ticket-ui-dropdown
npx playwright test tests/e2e/resident-incident-ticket.spec.ts --grep 'Danh mục dài|Ảnh đã chọn' --reporter=list --output=test-results/ticket-ui-evidence
npx playwright test tests/e2e/ticket-workflow-live.spec.ts --reporter=list --output=test-results/ticket-ui-live
```

Lệnh hồi quy tiện ích trong lượt đầy đủ thực tế được chạy cùng ba spec Ticket: `npx playwright test tests/e2e/resident-incident-ticket.spec.ts tests/e2e/ticket-management.spec.ts tests/e2e/ticket-workflow-live.spec.ts tests/e2e/resident-amenity-booking.spec.ts --reporter=list`. Phần tiện ích **47 passed**. Lượt đó có hai lỗi Ticket (focus và assertion biên footer), cùng một live test skipped vì chưa bật fixture; các phần này đã được xử lý/chạy lại riêng, không ghi nhận lượt ban đầu là xanh.

- TypeScript và build: **PASS**. `public/index.html` được script build cập nhật hash CSS/JS; không sửa tay nội dung trang.
- Bộ UI Ticket cuối: **32 passed (2.2 phút), 0 failed, 0 skipped**. Gồm cả kiểm thử mở rộng tìm kiếm/lọc từ trang 3 và chuỗi request không thừa khi xóa lọc.
- Bổ sung dropdown/quan hệ tòa–căn hộ–cư dân: **5 passed**; một lượt trước đó lỗi selector test khớp cả body và menu, đã sửa selector và chạy lại.
- Chụp lại F5/F6 sau căn hàng và dùng ảnh QA có nội dung nhìn rõ: **7 passed**.
- Chụp baseline + kiểm thử baseline liên quan: **3 passed**. Ảnh sự cố trong bằng chứng là ảnh QA tạo bằng canvas, không phải ảnh sự cố thật.
- Không đổi PHP, không có PHP cần chạy Pint. Không chạy toàn bộ PHPUnit của các phân hệ khác.

### Luồng thật và cách ly dữ liệu

Trước khi ghi, xác minh `database.connections.mysql.database` trong container là **`amenity_ticket_regression_index_audit_test`**, dùng config cache riêng; `DB_URL`/`DATABASE_URL` rỗng, host Docker `db`. Test fixture tự assert tên DB kết thúc `_test`. Kho ảnh ép **`TICKET_ATTACHMENT_DISK=local`**, không dùng Supabase/S3 production.

Chạy `tests/Feature/TicketBrowserFixtureTest.php` qua `php vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php` trong `smart_cassavas_app`, lần lượt filter `test_prepare_browser_fixture` và `test_verify_and_cleanup_browser_fixture`. Các override chỉ đặt cho tiến trình QA, không ghi `.env`.

- Chuẩn bị fixture: **PASS, 1 test/4 assertions**.
- Playwright live: **PASS, 1 test**, gọi API riêng nhiều worker ở `127.0.0.1:9038` bên trong container; không gọi cổng production.
- Kiểm chứng DB và cleanup: **PASS, 1 test/8 assertions**. Xác nhận Ticket `CLOSED`, version **9**, **2 ảnh**, **1** lịch sử chuyển CLOSED và có thông báo Ticket. Xóa fixture/tài khoản/ảnh thử bằng cleanup sẵn có; file token fixture đã được gỡ, không đưa vào Git/báo cáo.

## Bằng chứng kiểm thử

Theo yêu cầu mới, đã xóa ảnh chụp audit và gỡ liên kết ảnh. Các bước tái hiện, kết quả kiểm thử và thông tin cách ly QA được giữ ở trên. Test upload vẫn sử dụng dữ liệu ảnh QA trong bộ nhớ; không lưu ảnh chụp chủ động.

## File thay đổi và ảnh hưởng

- `resources/js/Components/ResidentTicketPanel.tsx`: debounce, phân trang, lọc ưu tiên/clear/empty, modal và vùng nhấn.
- `resources/js/Components/TicketManagementPanel.tsx`: phân trang/debounce/clear, footer, xác nhận bỏ ảnh.
- `resources/js/Components/TicketDialog.tsx`: cleanup và phục hồi focus; caller chỉ thuộc Ticket.
- `resources/js/Components/TicketImage.tsx`: retry ảnh hỏng; caller thuộc hai Ticket panel.
- Ba spec `resident-incident-ticket`, `ticket-management`, `resident-amenity-booking`: test hồi quy và ảnh bằng chứng.
- `public/index.html`: hash bundle do `npm run build` sinh.
- Báo cáo này; không lưu thư mục ảnh bằng chứng.

Không thay đổi `CustomSelect`, CSS dùng chung, API contract, backend, state machine, permissions, storage, schema hay dependency. Đã tìm caller trước khi sửa component chung. Nguy cơ F1–F4 thấp do không sửa component giao diện của tiện ích; 47 test hồi quy đã qua, nhưng không chứng minh mọi luồng production.

## Khác biệt và phần chưa kiểm chứng

1. Giữ luồng nghiệp vụ khác nhau: tiện ích có đăng ký/thanh toán; Ticket có xử lý/nghiệm thu. Không ép dùng chung mọi modal/bộ lọc hay state.
2. **PARTIAL – 409 cư dân:** edit giữ dữ liệu và backend từ chối version cũ, nhưng chưa có trải nghiệm khóa form + “Xem bản mới nhất” đầy đủ như quản lý. Không sửa state/version nghiệp vụ trong đợt UI này; cần một test riêng tái hiện trước khi mở rộng sửa.
3. **PARTIAL – thao tác chưa chạy API thật:** lưu trữ, tạo hộ cư dân, hủy thành công và bỏ ảnh đã lưu chỉ được kiểm ở mức UI/mock hoặc đọc mã, chưa nghiệm thu tất cả bằng DB thật. Luồng thật nêu trên không bao gồm các thao tác này.
4. **NOT VERIFIED:** mọi tổ hợp danh mục/tòa/ưu tiên/trạng thái, tìm kiếm tiếng Việt có/không dấu và hoa/thường trên dữ liệu thật. Mã dùng SQL LIKE; collation MySQL quyết định kết quả, không có chuẩn hóa tiếng Việt mới.
5. **NOT VERIFIED:** Safari/Firefox, trình đọc màn hình, zoom 200%, tablet/mobile phần cứng thật, popup khi bàn phím ảo mở, fallback khi trình duyệt thiếu Popover API. Bằng chứng hiện tại là Chromium emulation.
6. **NOT VERIFIED:** upload bucket production, Vercel, tải lớn và mọi nhánh timeout/409 trong workflow. Không gửi thanh toán, không deploy, không thay đổi khóa/cấu hình cloud.

Sẵn sàng tạo PR để review bản sửa UI trong phạm vi đã kiểm thử. Không kết luận toàn bộ F5–F8 hoàn thiện hoặc đủ điều kiện tự động merge/deploy; các giới hạn trên phải được giữ trong mô tả PR.

## Kết quả cuối đợt

**PASS trong lượt audit tích hợp trước khi tách nhánh:** 32 bài MOCKED E2E Ticket, 47 bài hồi quy tiện ích, một luồng Playwright API thật và hai bước PHPUnit chuẩn bị/kiểm chứng–cleanup fixture. Typecheck/build/diff check đều qua. Các lần chạy chọn lọc là kiểm tra bổ sung, không cộng trùng thành số test riêng.

Đã sửa phân trang, debounce/clear, control ưu tiên, empty state, các modal cư dân, focus, thử lại ảnh hỏng và xác nhận bỏ ảnh quản lý. Giao diện giữ phong cách tiện ích về card, màu, radius và thao tác; không ép đồng nhất những phần khác nghiệp vụ. Ảnh chụp bằng chứng đã được xóa theo yêu cầu; không lưu token/khóa.

Những mục PARTIAL/NOT VERIFIED ở trên vẫn còn. Bản sửa sẵn sàng review UI, được tách bằng commit cục bộ; chưa push hoặc triển khai lên production.


## Phân bổ lại theo nhánh chức năng

Theo yêu cầu tiếp theo, không sử dụng nhánh audit để chứa code chức năng. Không merge master hoặc trộn toàn bộ bản sửa vào các nhánh. Mỗi nhánh giữ nền sẵn có và chỉ nhận patch theo phạm vi bên dưới; các thay đổi trước đó của từng nhánh được giữ nguyên.

| Nhánh | Phần sửa được đưa về | Kiểm tra sau khi tách |
|---|---|---|
| `DangNguyen/5-BaoCao-SuCo-Ticket` | Tìm kiếm, debounce, lọc ưu tiên/clear/empty, phân trang cư dân, lỗi gửi form | Typecheck/build PASS; toàn bộ spec cư dân trên nhánh 5: **16 passed** |
| `DangNguyen/6-LuuTru-AnhSuCo` | Retry ảnh hỏng; xác nhận bỏ ảnh đã lưu ở quản lý; vùng nhấn ảnh cư dân và test ảnh | Typecheck/build PASS; test ảnh chọn/preview, HTTP/ảnh hỏng, xác nhận bỏ ảnh: **4 passed** |
| `DangNguyen/7-QL-PhanLoai-Ticket` | Tìm kiếm/lọc/phân trang quản lý; footer form; test dropdown/quan hệ tòa–căn hộ–người báo | Typecheck/build PASS; test phạm vi quản lý: **7 passed** |
| `DangNguyen/8-TiepNhan-XuLy-Ticket` | Modal thao tác workflow/sửa cư dân dùng TicketDialog; footer/busy/error/focus; bỏ focus trap thủ công trùng lặp | Typecheck/build PASS; hai spec cư dân/quản lý: **22 passed** |

`TicketDialog` dùng chung được sửa trong nhánh 8 vì lỗi được tái hiện từ modal hủy workflow. Thao tác bỏ ảnh xuất hiện trong `TicketManagementPanel` nhưng patch đó thuộc chức năng 6; patch tìm kiếm/form cùng file thuộc chức năng 7. Các đoạn sửa source đã được kiểm tra bằng cách tái dựng: kết hợp bốn patch khôi phục chính xác bốn file source của bản sửa ban đầu, không mất logic/error handling.

Các test cư dân/quản lý được tách tương ứng để từng nhánh chỉ nhận test mới thuộc phạm vi của nó. Những dòng chủ động chụp screenshot được loại khỏi các test đang sửa; ảnh audit đã xóa, không có file ảnh trong commit mới. `public/index.html` được build lại riêng trên mỗi nhánh, không sao chép hash bundle từ nhánh audit.

Kết quả trên từng nhánh không có nghĩa chúng đã được merge chung hoặc đã chạy trên Vercel. Chưa push, merge hoặc deploy. Khi tích hợp các nhánh về sau cần kiểm tra lại xung đột ở các file dùng chung; không dùng `public/index.html` từ nhánh này thay cho build của nhánh khác.


## Yêu cầu push/merge tiếp theo

Sau thời điểm audit và tách nhánh, người dùng đã cho phép push và merge. Các ghi chú “chưa push/merge” ở trên mô tả thời điểm kiểm thử trước yêu cầu mới.

Đã push cả bốn nhánh. Tích hợp qua các PR riêng theo thứ tự, giữ nguyên bảo vệ master và yêu cầu CI bắt buộc:

- F5: https://github.com/UMISORA09/QUANLY_TOANHA-DANCU/pull/115
- F6: https://github.com/UMISORA09/QUANLY_TOANHA-DANCU/pull/116
- F7: https://github.com/UMISORA09/QUANLY_TOANHA-DANCU/pull/117
- F8: https://github.com/UMISORA09/QUANLY_TOANHA-DANCU/pull/118

Trạng thái cập nhật xem trực tiếp trên các PR. Không tạo nhánh audit mới, không push ảnh chụp, không bỏ qua hook/CI hay thay đổi quy tắc bảo vệ repository. Xung đột hash public/index.html được xử lý bằng build lại; test dùng chung được ghép giữ cả kiểm thử của các chức năng đã merge trước.

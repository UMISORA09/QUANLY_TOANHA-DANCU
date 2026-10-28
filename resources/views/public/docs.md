# Tài liệu SMART CASSAVAS

SMART CASSAVAS là nền tảng quản lý tòa nhà dành cho cư dân và nhân viên vận hành. Tài liệu công khai phục vụ việc tìm hiểu sản phẩm và kết nối trợ lý với nội dung giới thiệu; không cấp quyền đọc hồ sơ cư dân hay thực hiện giao dịch.

## Đọc nội dung bằng máy

Gửi `Accept: text/markdown` đến `/` hoặc `/home`, `/about`, `/contact`, `/privacy`, `/docs` để nhận Markdown. Gửi `Accept: text/html` để nhận HTML. Máy chủ trả `Vary: Accept`, tôn trọng trọng số `q` và trả 406 nếu không có định dạng phù hợp. Các URL tương ứng thêm `.md` cũng trả Markdown. Trang không tồn tại giữ HTTP 404; yêu cầu Markdown nhận thông báo lỗi và liên kết tài liệu.

[Hướng dẫn cho AI](/llms.txt), [sitemap](/sitemap.xml) và [OpenAPI công khai](/openapi.json) mô tả các điểm truy cập có thể đọc mà không đăng nhập. Sitemap không liệt kê các cổng tài khoản hay API riêng tư.

## Kết nối MCP

MCP chỉ đọc nội dung công khai tại `/mcp/public`, dùng Streamable HTTP với giao thức `2025-11-25` của Laravel MCP 0.9.5. Client gửi `initialize`, tiếp theo `notifications/initialized`, rồi `tools/list` hoặc `tools/call`. POST dùng `Content-Type: application/json` và `Accept: application/json, text/event-stream`. Sau khởi tạo, gửi `MCP-Protocol-Version: 2025-11-25`. GET không mở luồng SSE thường trực và trả 405 theo transport đã chọn.

Công cụ `read-public-guide` nhận `page` thuộc `home`, `about`, `contact`, `privacy`, `docs`; kết quả là văn bản công khai. Không nhận URL tùy ý, đường dẫn file hoặc ID cư dân. `/.well-known/mcp` là tài liệu khám phá riêng của website, không phải tuyên bố đăng ký hay xác minh bởi MCP Registry. Server không cung cấp công cụ đặt chỗ, thanh toán hoặc quản trị.

## Xác thực API nghiệp vụ

Các API cư dân và quản lý kiểm tra phiên Bearer và quyền trên máy chủ; tài liệu này không phát hành token. Không sử dụng tài khoản mẫu hoặc token trong mã nguồn để gọi hệ thống. Khi cần tích hợp nghiệp vụ, trao đổi với đơn vị vận hành về phạm vi quyền và môi trường kiểm thử trước; không tự gửi giao dịch Production.

## Khi nào nên sử dụng

Dùng tài liệu này để giải thích các cổng và luồng công việc, hướng dẫn người dùng tìm màn hình phù hợp hoặc kiểm tra định dạng nội dung công khai. Dùng màn hình sau đăng nhập để xem trạng thái thực tế của một yêu cầu. Không suy ra đã thanh toán chỉ vì đặt chỗ thành công, không suy ra sự cố đã hoàn tất chỉ từ một bình luận.

[Trang chủ](/home) · [Giới thiệu](/about) · [Liên hệ](/contact) · [Quyền riêng tư](/privacy)

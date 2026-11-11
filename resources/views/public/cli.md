# SMART CASSAVAS CLI — Công cụ dòng lệnh

CLI của dự án giúp lập trình viên và AI agent đọc tài liệu công khai, kiểm tra khả năng truy cập tài liệu và tìm cấu hình MCP. Công cụ không đăng nhập, không yêu cầu token và không đọc dữ liệu cư dân, hóa đơn, đặt tiện ích hoặc thanh toán.

## Cài đặt và chạy

Clone repository chính thức tại https://github.com/UMISORA09/QUANLY_TOANHA-DANCU và dùng Node.js 20 trở lên. Chưa có package được phát hành trên npm; không dùng tên package ngẫu nhiên từ registry. Không cần cài thêm dependency để chạy CLI.

```bash
node bin/smart-cassavas.cjs --help
node bin/smart-cassavas.cjs --version
node bin/smart-cassavas.cjs docs home
node bin/smart-cassavas.cjs status --json
node bin/smart-cassavas.cjs mcp-info --json
```

Thêm --base-url http://127.0.0.1:8000 để kiểm tra một bản local hoặc QA; mặc định dùng website Production. --json xuất dữ liệu JSON qua stdout. Lỗi được ghi qua stderr và trả exit code 1. CLI chỉ gửi GET, có timeout và không tự thử lại khi máy chủ báo lỗi hoặc hết hạn mức.

## Giới hạn và liên kết

Lệnh status chỉ kiểm tra tài liệu công khai, không xác nhận database hay toàn bộ nghiệp vụ đang khỏe. Rate limit mặc định là 60 lượt/phút/IP dùng chung cho guide, CLI metadata và MCP discovery. File cache trên Vercel có phạm vi từng instance; không phải hạn mức toàn cụm. Khi nhận 429, chờ theo Retry-After. Khi bộ lưu hạn mức không dùng được, API trả 503 thay vì bỏ qua hạn mức.

Xem [tài liệu API](/docs), [MCP Server](/mcp) và [OpenAPI](/openapi.json). Các thao tác tài khoản, giao dịch và quản trị vẫn yêu cầu quyền riêng và không được CLI này cung cấp.

# SMART CASSAVAS MCP

Kết nối trợ lý với hướng dẫn công khai về quản lý tòa nhà, đăng ký tiện ích và báo cáo sự cố.

Endpoint `/mcp/public` dùng Streamable HTTP. Gọi công cụ `read-public-guide` với tham số `page`, ví dụ `home`. Xem [tài liệu API SMART CASSAVAS](/docs) để cấu hình phiên và [tài liệu khám phá MCP](/.well-known/mcp) để lấy URL endpoint.

## Khi nào dùng công cụ

Dùng công cụ để hướng dẫn cư dân tìm màn hình đặt tiện ích, giải thích cách gửi yêu cầu sửa chữa hoặc tra cứu mô tả các vai trò. Với câu hỏi về trạng thái đặt chỗ, thanh toán hoặc tiến độ của một yêu cầu cụ thể, hướng dẫn người dùng mở cổng sau đăng nhập với quyền phù hợp.
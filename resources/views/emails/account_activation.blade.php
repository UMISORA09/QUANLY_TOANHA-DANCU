<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bạn đã được cấp tài khoản</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            background-color: #f8fafc;
            color: #1e293b;
            margin: 0;
            padding: 0;
            -webkit-font-smoothing: antialiased;
        }
        .container {
            max-width: 600px;
            margin: 40px auto;
            background: #ffffff;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -2px rgba(0, 0, 0, 0.1);
            border: 1px solid #e2e8f0;
        }
        .header {
            background: linear-gradient(135deg, #0ea5e9 0%, #0284c7 100%);
            padding: 32px 24px;
            text-align: center;
            color: #ffffff;
        }
        .header h1 {
            margin: 0;
            font-size: 24px;
            font-weight: 700;
            letter-spacing: -0.025em;
        }
        .header p {
            margin: 8px 0 0;
            opacity: 0.9;
            font-size: 14px;
        }
        .content {
            padding: 32px 28px;
        }
        .greeting {
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 16px;
        }
        .info-box {
            background-color: #f1f5f9;
            border-radius: 12px;
            padding: 20px;
            margin: 24px 0;
            border-left: 4px solid #0284c7;
        }
        .info-row {
            display: flex;
            margin-bottom: 8px;
            font-size: 14px;
        }
        .info-row:last-child {
            margin-bottom: 0;
        }
        .info-label {
            font-weight: 600;
            color: #475569;
            width: 140px;
        }
        .info-value {
            color: #0f172a;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            font-weight: 600;
        }
        .btn-container {
            text-align: center;
            margin: 32px 0;
        }
        .btn {
            display: inline-block;
            background: #0284c7;
            color: #ffffff !important;
            text-decoration: none;
            padding: 14px 32px;
            border-radius: 10px;
            font-weight: 700;
            font-size: 15px;
            letter-spacing: 0.02em;
            transition: background-color 0.2s;
        }
        .btn:hover {
            background: #0369a1;
        }
        .note {
            font-size: 13px;
            color: #64748b;
            line-height: 1.6;
            margin-top: 24px;
            border-top: 1px solid #e2e8f0;
            padding-top: 20px;
        }
        .footer {
            background: #f8fafc;
            padding: 20px 24px;
            text-align: center;
            font-size: 12px;
            color: #94a3b8;
            border-top: 1px solid #e2e8f0;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>HỆ THỐNG QUẢN LÝ TÒA NHÀ CASSAVAS</h1>
            <p>Bạn đã được cấp tài khoản</p>
        </div>
        <div class="content">
            <div class="greeting">Xin chào {{ $fullName }},</div>
            <p style="font-size: 14px; line-height: 1.6; color: #334155;">
                Tài khoản nhân viên của bạn đã được khởi tạo thành công trên hệ thống tòa nhà. Dưới đây là thông tin tài khoản được cấp phát:
            </p>

            <div class="info-box">
                <div class="info-row">
                    <span class="info-label">Họ và tên:</span>
                    <span class="info-value" style="font-family: inherit;">{{ $fullName }}</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Username:</span>
                    <span class="info-value">{{ $username }}</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Email:</span>
                    <span class="info-value">{{ $email }}</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Trạng thái:</span>
                    <span class="info-value" style="color: #d97706;">Chờ kích hoạt</span>
                </div>
            </div>

            <p style="font-size: 14px; line-height: 1.6; color: #334155;">
                Vui lòng nhấn vào nút bên dưới để chuyển tới trang đặt mật khẩu và hoàn tất kích hoạt tài khoản:
            </p>

            <div class="btn-container">
                <a href="{{ $activationUrl }}" class="btn" target="_blank">KÍCH HOẠT TÀI KHOẢN</a>
            </div>

            <div class="note">
                <p><strong>Lưu ý bảo mật:</strong></p>
                <ul>
                    <li>Liên kết kích hoạt này có hiệu lực trong vòng <strong>{{ $expiresInHours }} giờ</strong> kể từ thời điểm gửi email.</li>
                    <li>Vì lý do an toàn, Ban Quản Lý không bao giờ gửi mật khẩu thô qua email. Quý vị sẽ tự đặt mật khẩu cá nhân tại trang kích hoạt.</li>
                    <li>Tuyệt đối không chia sẻ liên kết này cho bất kỳ ai khác.</li>
                </ul>
            </div>
        </div>
        <div class="footer">
            &copy; {{ date('Y') }} Ban Quản Lý Tòa Nhà Cassavas. Mọi thắc mắc xin vui lòng liên hệ quầy lễ tân hoặc hotline hỗ trợ tòa nhà.
        </div>
    </div>
</body>
</html>

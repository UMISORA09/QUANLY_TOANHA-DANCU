<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Thông Báo Tiếp Nhận Kiện Hàng Bưu Phẩm</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; line-height: 1.6; color: #1e293b; background-color: #f8fafc; margin: 0; padding: 0; }
        .container { max-width: 600px; margin: 24px auto; background: #ffffff; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.06); border: 1px solid #e2e8f0; }
        .header { background: linear-gradient(135deg, #0f172a 0%, #1e1b4b 100%); padding: 32px 24px; text-align: center; color: #ffffff; }
        .header h1 { margin: 0 0 8px 0; font-size: 20px; font-weight: 800; letter-spacing: 0.5px; }
        .header p { margin: 0; font-size: 13px; color: #94a3b8; }
        .body { padding: 32px 24px; }
        .badge-notice { background-color: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 12px; padding: 16px; margin-bottom: 24px; color: #166534; font-size: 14px; }
        .info-table { width: 100%; border-collapse: collapse; margin-bottom: 24px; font-size: 14px; }
        .info-table td { padding: 10px 12px; border-bottom: 1px solid #f1f5f9; }
        .info-table td.label { color: #64748b; width: 40%; font-weight: 500; }
        .info-table td.value { color: #0f172a; font-weight: 600; text-align: right; }
        .pin-box { text-align: center; background: #f1f5f9; padding: 18px; border-radius: 12px; border: 1px dashed #94a3b8; margin: 20px 0; }
        .pin-code { font-size: 28px; font-weight: 800; color: #4338ca; letter-spacing: 4px; font-family: monospace; }
        .pin-label { font-size: 12px; color: #64748b; text-transform: uppercase; margin-bottom: 4px; font-weight: 600; }
        .instructions { background: #f8fafc; padding: 18px; border-radius: 12px; border: 1px solid #e2e8f0; margin-top: 20px; font-size: 13px; color: #475569; }
        .instructions h4 { margin: 0 0 8px 0; color: #0f172a; font-size: 14px; }
        .instructions ol { margin: 0; padding-left: 20px; }
        .instructions li { margin-bottom: 4px; }
        .footer { background: #f8fafc; padding: 24px; text-align: center; font-size: 12px; color: #64748b; border-top: 1px solid #e2e8f0; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>BAN QUẢN LÝ TÒA NHÀ CASSAVAS SMART</h1>
            <p>THÔNG BÁO TIẾP NHẬN BƯU PHẨM & KIỆN HÀNG CƯ DÂN</p>
        </div>

        <div class="body">
            <p>Kính gửi Quý Cư dân <strong>{{ $recipientName }}</strong>,</p>

            <div class="badge-notice">
                <strong>Thông báo nhận kiện hàng:</strong> Lễ tân tòa nhà đã tiếp nhận một bưu phẩm mới dành cho căn hộ của Quý cư dân vào hệ thống.
            </div>

            <table class="info-table">
                <tr>
                    <td class="label">Mã vận đơn / Bưu phẩm:</td>
                    <td class="value" style="font-family: monospace; color: #4338ca;">{{ $parcel->tracking_number }}</td>
                </tr>
                <tr>
                    <td class="label">Đơn vị vận chuyển:</td>
                    <td class="value">{{ $parcel->courier_company }}</td>
                </tr>
                <tr>
                    <td class="label">Căn hộ:</td>
                    <td class="value">Căn {{ $parcel->apartment?->apartment_number }} ({{ $parcel->apartment?->block?->block_name ?? 'Khu căn hộ' }})</td>
                </tr>
                <tr>
                    <td class="label">Người nhận ghi nhận:</td>
                    <td class="value">{{ $parcel->recipient_name }}</td>
                </tr>
                <tr>
                    <td class="label">Thời gian tiếp nhận:</td>
                    <td class="value">{{ $parcel->received_at ? $parcel->received_at->format('H:i - d/m/Y') : now()->format('H:i - d/m/Y') }}</td>
                </tr>
                <tr>
                    <td class="label">Địa điểm lưu trữ:</td>
                    <td class="value">{{ $pickupLocation }}</td>
                </tr>
                @if(!empty($parcel->notes))
                <tr>
                    <td class="label">Ghi chú kiện hàng:</td>
                    <td class="value" style="font-weight: normal; color: #475569;">{{ $parcel->notes }}</td>
                </tr>
                @endif
            </table>

            @if(!empty($pinCode))
            <div class="pin-box">
                <div class="pin-label">Mã PIN Nhận Hàng Tại Quầy / Smart Locker</div>
                <div class="pin-code">{{ $pinCode }}</div>
                <div style="font-size: 11px; color: #64748b; margin-top: 4px;">Cung cấp mã này cho Lễ tân hoặc nhập tại Tủ đồ để lấy hàng an toàn</div>
            </div>
            @endif

            <div class="instructions">
                <h4>Quy trình nhận bưu phẩm:</h4>
                <ol>
                    <li>Quý cư dân vui lòng đến quầy Lễ tân tòa nhà trong khung giờ hoạt động (06:00 - 22:00 hàng ngày).</li>
                    <li>Cung cấp mã vận đơn hoặc mã PIN nhận hàng để nhân viên hỗ trợ bàn giao.</li>
                    <li>Kiểm tra tình trạng nguyên vẹn của kiện hàng trước khi hoàn tất thủ tục bàn giao.</li>
                </ol>
            </div>
        </div>

        <div class="footer">
            <p>Trân trọng,<br><strong>Ban Quản Lý Tòa Nhà Cassavas Smart</strong></p>
            <p style="margin-top: 8px; color: #94a3b8;">Email này được gửi tự động từ hệ thống quản lý tòa nhà thông minh. Quý cư dân vui lòng không phản hồi trực tiếp vào địa chỉ email này.</p>
        </div>
    </div>
</body>
</html>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Thông Báo Nhắc Nợ Phí Quản Lý & Tiện Ích</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; line-height: 1.6; color: #1e293b; background-color: #f8fafc; margin: 0; padding: 0; }
        .container { max-width: 600px; margin: 20px auto; background: #ffffff; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.06); border: 1px solid #e2e8f0; }
        .header { background: linear-gradient(135deg, #0f172a 0%, #1e1b4b 100%); padding: 32px 24px; text-align: center; color: #ffffff; }
        .header h1 { margin: 0 0 8px 0; font-size: 20px; font-weight: 800; letter-spacing: 0.5px; }
        .header p { margin: 0; font-size: 13px; color: #94a3b8; }
        .body { padding: 32px 24px; }
        .alert-box { background-color: #fff1f2; border: 1px solid #fecdd3; border-radius: 12px; padding: 16px; margin-bottom: 24px; color: #9f1239; }
        .alert-box.normal { background-color: #f0fdf4; border: 1px solid #bbf7d0; color: #166534; }
        .info-table { width: 100%; border-collapse: collapse; margin-bottom: 24px; font-size: 14px; }
        .info-table td { padding: 10px 12px; border-bottom: 1px solid #f1f5f9; }
        .info-table td.label { color: #64748b; width: 40%; font-weight: 500; }
        .info-table td.value { color: #0f172a; font-weight: 600; text-align: right; }
        .highlight-amount { font-size: 22px; font-weight: 800; color: #dc2626; font-family: monospace; }
        .qr-section { text-align: center; background: #f8fafc; padding: 20px; border-radius: 12px; border: 1px dashed #cbd5e1; margin-top: 24px; }
        .qr-image { width: 180px; height: 180px; margin: 0 auto 12px auto; display: block; border-radius: 8px; border: 1px solid #e2e8f0; }
        .footer { background: #f8fafc; padding: 24px; text-align: center; font-size: 12px; color: #64748b; border-top: 1px solid #e2e8f0; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>BAN QUẢN LÝ TÒA NHÀ CASSAVAS SMART</h1>
            <p>THÔNG BÁO THANH TOÁN PHÍ DỊCH VỤ CƯ DÂN</p>
        </div>

        <div class="body">
            <p>Kính gửi Quý Cư dân <strong>{{ $recipientName }}</strong>,</p>

            @if($daysOverdue > 0)
                <div class="alert-box">
                    <strong>Cảnh báo quá hạn:</strong> Hóa đơn kỳ <strong>{{ $invoice->billing_period }}</strong> của Quý cư dân hiện đã quá hạn thanh toán <strong>{{ $daysOverdue }} ngày</strong>. Kính đề nghị Quý cư dân vui lòng thanh toán để tránh gián đoạn các dịch vụ tiện ích tòa nhà.
                </div>
            @else
                <div class="alert-box normal">
                    <strong>Thông báo đến hạn:</strong> Hóa đơn kỳ <strong>{{ $invoice->billing_period }}</strong> sẽ đến hạn thanh toán vào ngày <strong>{{ $invoice->due_date }}</strong>.
                </div>
            @endif

            <table class="info-table">
                <tr>
                    <td class="label">Mã hóa đơn:</td>
                    <td class="value" style="font-family: monospace; color: #4f46e5;">{{ $invoice->invoice_number }}</td>
                </tr>
                <tr>
                    <td class="label">Căn hộ:</td>
                    <td class="value">Căn {{ $invoice->apartment?->apartment_number }} ({{ $invoice->apartment?->block?->block_name }})</td>
                </tr>
                <tr>
                    <td class="label">Kỳ phí:</td>
                    <td class="value">{{ $invoice->billing_period }}</td>
                </tr>
                <tr>
                    <td class="label">Hạn chót thanh toán:</td>
                    <td class="value" style="color: #ea580c;">{{ $invoice->due_date }}</td>
                </tr>
                <tr>
                    <td class="label">Tổng tiền hóa đơn:</td>
                    <td class="value">{{ number_format($invoice->total_amount) }} đ</td>
                </tr>
                <tr>
                    <td class="label">Đã thanh toán:</td>
                    <td class="value" style="color: #16a34a;">{{ number_format($invoice->paid_amount) }} đ</td>
                </tr>
                <tr>
                    <td class="label">Dư nợ còn phải nộp:</td>
                    <td class="value"><span class="highlight-amount">{{ number_format($invoice->remaining_balance) }} đ</span></td>
                </tr>
            </table>

            @if(!empty($vietQrData['qr_image_url']))
                <div class="qr-section">
                    <p style="margin-top: 0; font-weight: 700; color: #0f172a; font-size: 14px;">Quét Mã VietQR Chuyển Khoản Nhanh</p>
                    <img src="{{ $vietQrData['qr_image_url'] }}" alt="VietQR" class="qr-image" />
                    <p style="margin: 0; font-size: 12px; color: #475569;">
                        Số tài khoản: <strong style="font-family: monospace; color: #0f172a;">{{ $vietQrData['bank_account_number'] }}</strong> ({{ $vietQrData['bank_account_name'] }})<br>
                        Nội dung: <strong style="font-family: monospace; color: #4f46e5;">{{ $vietQrData['transfer_content'] }}</strong>
                    </p>
                </div>
            @endif

            <p style="margin-top: 24px; font-size: 13px; color: #475569;">
                Nếu Quý cư dân đã hoàn tất thanh toán trước khi nhận được email này, vui lòng bỏ qua thông báo. Mọi thắc mắc xin vui lòng liên hệ Bộ phận Kế toán / Ban Quản trị Tòa nhà.
            </p>
        </div>

        <div class="footer">
            <p style="margin: 0 0 4px 0;"><strong>VĂN PHÒNG BAN QUẢN LÝ CĂN HỘ CASSAVAS SMART</strong></p>
            <p style="margin: 0;">Hotline: 1900 8888 • Email: support@cassavas.com</p>
        </div>
    </div>
</body>
</html>

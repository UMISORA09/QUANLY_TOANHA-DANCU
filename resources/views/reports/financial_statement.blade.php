<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Báo Cáo Tài Chính & Công Nợ - Kỳ {{ $report['filters']['period'] }}</title>
    <style>
        @page {
            size: A4 landscape;
            margin: 12mm;
        }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            color: #1e293b;
            margin: 0;
            padding: 15px;
            font-size: 11px;
            background-color: #ffffff;
        }
        .header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            border-bottom: 2px solid #0f172a;
            padding-bottom: 12px;
            margin-bottom: 15px;
        }
        .title-section {
            text-align: center;
            margin-bottom: 20px;
        }
        .title-section h1 {
            font-size: 18px;
            margin: 0 0 4px 0;
            text-transform: uppercase;
            color: #0f172a;
        }
        .title-section p {
            margin: 0;
            color: #64748b;
            font-style: italic;
        }
        .kpi-cards {
            display: flex;
            gap: 12px;
            margin-bottom: 20px;
        }
        .kpi-card {
            flex: 1;
            padding: 10px 14px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
        }
        .kpi-card .label {
            font-size: 9px;
            font-weight: bold;
            color: #64748b;
            text-transform: uppercase;
        }
        .kpi-card .value {
            font-size: 14px;
            font-weight: bold;
            color: #0f172a;
            margin-top: 3px;
            font-family: monospace;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 25px;
            font-size: 10px;
        }
        th, td {
            border: 1px solid #cbd5e1;
            padding: 5px 6px;
            text-align: left;
        }
        th {
            background-color: #f1f5f9;
            font-weight: 700;
            color: #1e293b;
            text-transform: uppercase;
            font-size: 9px;
        }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .font-mono { font-family: monospace; }
        .font-bold { font-weight: bold; }
        .signatures {
            display: flex;
            justify-content: space-between;
            margin-top: 40px;
            page-break-inside: avoid;
        }
        .signature-box {
            text-align: center;
            width: 28%;
        }
        .signature-box .title {
            font-weight: bold;
            text-transform: uppercase;
            margin-bottom: 4px;
        }
        .signature-box .sub {
            font-style: italic;
            color: #64748b;
            font-size: 9px;
            margin-bottom: 60px;
        }
        .no-print {
            position: fixed;
            top: 15px;
            right: 15px;
            display: flex;
            gap: 8px;
            z-index: 1000;
        }
        .btn {
            background-color: #4f46e5;
            color: #ffffff;
            border: none;
            padding: 8px 14px;
            border-radius: 6px;
            font-weight: bold;
            font-size: 12px;
            cursor: pointer;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }
        .btn-close {
            background-color: #64748b;
        }
        @media print {
            .no-print { display: none; }
            body { padding: 0; }
        }
    </style>
</head>
<body>
    <div class="no-print">
        <button class="btn" onclick="window.print()">In Báo Cáo / Xuất PDF</button>
        <button class="btn btn-close" onclick="window.close()">Đóng Tab</button>
    </div>

    <!-- Header thông tin tổ chức -->
    <div class="header">
        <div>
            <div style="font-weight: bold; font-size: 13px; text-transform: uppercase;">BAN QUẢN LÝ TÒA NHÀ CASSAVAS SMART</div>
            <div style="color: #64748b; font-size: 10px;">Hệ thống Vận hành & Quản lý Chung cư Hiện đại</div>
            <div style="color: #64748b; font-size: 10px;">Hotline: 1900 8888 | Email: bql@cassavas.com</div>
        </div>
        <div style="text-align: right; font-size: 10px;">
            <div><strong>Mẫu biểu:</strong> BC-TC-01</div>
            <div><strong>Ngày xuất:</strong> {{ $report['generated_at'] }}</div>
            <div><strong>Phạm vi:</strong> Toàn hệ thống</div>
        </div>
    </div>

    <!-- Tiêu đề chính -->
    <div class="title-section">
        <h1>BÁO CÁO CÔNG NỢ & DOANH THU THU PHÍ TÒA NHÀ</h1>
        <p>Kỳ đối soát tài chính: {{ $report['filters']['period'] ?: 'Tất cả các kỳ' }}</p>
    </div>

    <!-- Thẻ KPI tổng kết -->
    <div class="kpi-cards">
        <div class="kpi-card">
            <div class="label">Tổng Số Hóa Đơn</div>
            <div class="value">{{ $report['summary']['total_invoices'] }} HĐ</div>
        </div>
        <div class="kpi-card">
            <div class="label">Tổng Doanh Thu Phát Hành</div>
            <div class="value" style="color: #4f46e5;">{{ number_format($report['summary']['total_billed'], 0, ',', '.') }} đ</div>
        </div>
        <div class="kpi-card">
            <div class="label">Tổng Thực Thu Đã Nộp</div>
            <div class="value" style="color: #059669;">{{ number_format($report['summary']['total_collected'], 0, ',', '.') }} đ</div>
        </div>
        <div class="kpi-card">
            <div class="label">Tổng Công Nợ Tồn Đọng</div>
            <div class="value" style="color: #d97706;">{{ number_format($report['summary']['total_debt'], 0, ',', '.') }} đ</div>
        </div>
        <div class="kpi-card">
            <div class="label">Tỷ Lệ Thu Hồi</div>
            <div class="value" style="color: #0284c7;">{{ $report['summary']['collection_rate'] }}%</div>
        </div>
    </div>

    <!-- Bảng chi tiết hóa đơn căn hộ -->
    <table>
        <thead>
            <tr>
                <th class="text-center" style="width: 25px;">STT</th>
                <th>Số HĐ</th>
                <th>Căn Hộ</th>
                <th>Tòa</th>
                <th>Chủ Hộ / Cư Dân</th>
                <th class="text-center">Kỳ Phí</th>
                <th class="text-center">Hạn Nộp</th>
                <th class="text-right">Tiền Điện</th>
                <th class="text-right">Tiền Nước</th>
                <th class="text-right">Phí QLVH</th>
                <th class="text-right">Phí Xe</th>
                <th class="text-right">Tổng Phát Hành</th>
                <th class="text-right">Đã Thu</th>
                <th class="text-right">Còn Nợ</th>
                <th class="text-center">Trạng Thái</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($report['rows'] as $r)
                <tr>
                    <td class="text-center font-mono">{{ $r['stt'] }}</td>
                    <td class="font-mono font-bold">{{ $r['invoice_number'] }}</td>
                    <td class="font-bold">{{ $r['apartment_number'] }}</td>
                    <td>{{ $r['block_name'] }}</td>
                    <td>{{ $r['resident_name'] }}</td>
                    <td class="text-center font-mono">{{ $r['billing_period'] }}</td>
                    <td class="text-center font-mono">{{ $r['due_date'] }}</td>
                    <td class="text-right font-mono">{{ number_format($r['electricity_amount'], 0, ',', '.') }}</td>
                    <td class="text-right font-mono">{{ number_format($r['water_amount'], 0, ',', '.') }}</td>
                    <td class="text-right font-mono">{{ number_format($r['management_fee'], 0, ',', '.') }}</td>
                    <td class="text-right font-mono">{{ number_format($r['parking_fee'], 0, ',', '.') }}</td>
                    <td class="text-right font-mono font-bold">{{ number_format($r['total_amount'], 0, ',', '.') }}</td>
                    <td class="text-right font-mono" style="color: #059669;">{{ number_format($r['paid_amount'], 0, ',', '.') }}</td>
                    <td class="text-right font-mono font-bold" style="color: #d97706;">{{ number_format($r['remaining_balance'], 0, ',', '.') }}</td>
                    <td class="text-center">{{ $r['status_label'] }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="15" class="text-center" style="padding: 20px; color: #94a3b8;">
                        Không có dữ liệu hóa đơn nào phù hợp với phạm vi báo cáo.
                    </td>
                </tr>
            @endforelse
        </tbody>
        @if (count($report['rows']) > 0)
            <tfoot>
                <tr style="background-color: #f8fafc; font-weight: bold;">
                    <td colspan="11" class="text-right" style="padding: 8px;">TỔNG CỘNG TOÀN BỘ:</td>
                    <td class="text-right font-mono font-bold">{{ number_format($report['summary']['total_billed'], 0, ',', '.') }} đ</td>
                    <td class="text-right font-mono font-bold" style="color: #059669;">{{ number_format($report['summary']['total_collected'], 0, ',', '.') }} đ</td>
                    <td class="text-right font-mono font-bold" style="color: #d97706;">{{ number_format($report['summary']['total_debt'], 0, ',', '.') }} đ</td>
                    <td></td>
                </tr>
            </tfoot>
        @endif
    </table>

    <!-- Khối chữ ký xác nhận -->
    <div class="signatures">
        <div class="signature-box">
            <div class="title">NGƯỜI LẬP BÁO CÁO</div>
            <div class="sub">(Ký, ghi rõ họ tên)</div>
            <div style="font-weight: bold; margin-top: 50px;">Ban Quản Trị Hệ Thống</div>
        </div>
        <div class="signature-box">
            <div class="title">KẾ TOÁN TRƯỞNG</div>
            <div class="sub">(Ký, ghi rõ họ tên)</div>
            <div style="font-weight: bold; margin-top: 50px;">Phòng Kế Toán Tài Chính</div>
        </div>
        <div class="signature-box">
            <div class="title">TRƯỞNG BAN QUẢN LÝ TÒA NHÀ</div>
            <div class="sub">(Ký tên và đóng dấu)</div>
            <div style="font-weight: bold; margin-top: 50px;">Đại Diện Ban Quản Lý</div>
        </div>
    </div>

    @if (request()->has('auto_print'))
        <script>
            window.addEventListener('load', function() {
                setTimeout(function() {
                    window.print();
                }, 500);
            });
        </script>
    @endif
</body>
</html>

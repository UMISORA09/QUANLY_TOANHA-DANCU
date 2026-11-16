/**
 * Invoice & Receipt Print Helper for Smart Cassavas
 * Solves browser print preview hanging by rendering in an isolated clean iframe
 */

import { InvoiceModel } from './invoiceApi';

const formatVnd = (value: number | string | undefined | null): string => {
    const num = Number(value) || 0;
    return num.toLocaleString('vi-VN') + ' đ';
};

const getStatusBadgeHtml = (status: string): string => {
    switch (status) {
        case 'PAID':
            return '<span style="background:#dcfce7;color:#15803d;padding:3px 8px;border-radius:4px;font-weight:700;font-size:11px;">ĐÃ THANH TOÁN</span>';
        case 'PARTIAL':
            return '<span style="background:#e0f2fe;color:#0369a1;padding:3px 8px;border-radius:4px;font-weight:700;font-size:11px;">THANH TOÁN 1 PHẦN</span>';
        case 'OVERDUE':
            return '<span style="background:#fee2e2;color:#b91c1c;padding:3px 8px;border-radius:4px;font-weight:700;font-size:11px;">QUÁ HẠN</span>';
        case 'CANCELLED':
            return '<span style="background:#f1f5f9;color:#64748b;padding:3px 8px;border-radius:4px;font-weight:700;font-size:11px;">ĐÃ HỦY</span>';
        default:
            return '<span style="background:#fef9c3;color:#854d0e;padding:3px 8px;border-radius:4px;font-weight:700;font-size:11px;">CHỜ THANH TOÁN</span>';
    }
};

/**
 * Generate full HTML for printable Invoice Statement (A4 layout)
 */
export const generateInvoicePrintHtml = (invoice: InvoiceModel): string => {
    const todayStr = new Date().toLocaleDateString('vi-VN');
    const aptNumber = invoice.apartment?.apartment_number || 'N/A';
    const blockName = invoice.apartment?.block?.block_name || '';
    const residentName = (invoice as any).resident_user?.full_name || 'Đại diện Căn Hộ';
    const residentPhone = (invoice as any).resident_user?.phone_number || 'N/A';
    const area = invoice.apartment?.net_usable_area_sqm || 70;

    const itemsRowsHtml = (invoice.items || []).map((item, index) => {
        let tierHtml = '';
        if (item.tier_calculation_details && item.tier_calculation_details.length > 0) {
            tierHtml = `
                <div style="margin-top:4px;padding:4px 6px;background:#f8fafc;border:1px dashed #cbd5e1;border-radius:4px;font-size:10px;color:#475569;">
                    <div style="font-weight:600;margin-bottom:2px;">Chi tiết bậc thang:</div>
                    ${item.tier_calculation_details.map(t => `
                        <div>• ${t.tier_name}: ${t.tier_usage} ${item.unit_name} × ${Number(t.unit_price).toLocaleString('vi-VN')} đ = <strong>${Number(t.tier_amount).toLocaleString('vi-VN')} đ</strong></div>
                    `).join('')}
                </div>
            `;
        }

        return `
            <tr>
                <td style="text-align:center;padding:8px 6px;border-bottom:1px solid #e2e8f0;font-size:11px;">${index + 1}</td>
                <td style="padding:8px;border-bottom:1px solid #e2e8f0;">
                    <strong style="color:#0f172a;font-size:12px;">${item.item_description}</strong>
                    ${tierHtml}
                </td>
                <td style="text-align:center;padding:8px;border-bottom:1px solid #e2e8f0;font-size:11px;">${item.unit_name}</td>
                <td style="text-align:right;padding:8px;border-bottom:1px solid #e2e8f0;font-family:monospace;font-size:12px;">${item.quantity}</td>
                <td style="text-align:right;padding:8px;border-bottom:1px solid #e2e8f0;font-family:monospace;font-size:12px;">${Number(item.unit_price).toLocaleString('vi-VN')}</td>
                <td style="text-align:right;padding:8px;border-bottom:1px solid #e2e8f0;font-family:monospace;font-size:12px;">${Number(item.amount_before_tax).toLocaleString('vi-VN')}</td>
                <td style="text-align:right;padding:8px;border-bottom:1px solid #e2e8f0;font-family:monospace;font-size:12px;">${Number(item.vat_amount + item.environmental_fee_amount).toLocaleString('vi-VN')}</td>
                <td style="text-align:right;padding:8px;border-bottom:1px solid #e2e8f0;font-family:monospace;font-weight:700;color:#047857;font-size:12px;">${Number(item.total_line_amount).toLocaleString('vi-VN')}</td>
            </tr>
        `;
    }).join('');

    const paymentsRowsHtml = ((invoice as any).payments || []).map((p: any) => `
        <div style="display:flex;justify-content:space-between;padding:4px 0;font-size:11px;font-family:monospace;color:#334155;border-bottom:1px dashed #e2e8f0;">
            <span>${p.payment_reference_code} (${p.payment_gateway}) - ${p.payment_date || todayStr}:</span>
            <strong style="color:#047857;">+${Number(p.amount_paid).toLocaleString('vi-VN')} đ</strong>
        </div>
    `).join('');

    return `
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <title>Hóa Đơn ${invoice.invoice_number} - Căn ${aptNumber}</title>
    <style>
        @page {
            size: A4 portrait;
            margin: 12mm 15mm;
        }
        * {
            box-sizing: border-box;
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }
        body {
            font-family: 'Segoe UI', -apple-system, BlinkMacSystemFont, Arial, sans-serif;
            color: #1e293b;
            margin: 0;
            padding: 0;
            font-size: 12px;
            line-height: 1.45;
            background: #ffffff;
        }
        .header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            border-bottom: 2px solid #0284c7;
            padding-bottom: 12px;
            margin-bottom: 16px;
        }
        .company-name {
            font-size: 15px;
            font-weight: 800;
            color: #0369a1;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .company-sub {
            font-size: 11px;
            color: #64748b;
            margin-top: 2px;
        }
        .invoice-title {
            text-align: center;
            margin: 12px 0 16px;
        }
        .invoice-title h1 {
            margin: 0;
            font-size: 18px;
            font-weight: 800;
            color: #0f172a;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .invoice-title p {
            margin: 4px 0 0;
            font-size: 12px;
            color: #64748b;
        }
        .meta-box {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 12px 14px;
            margin-bottom: 16px;
        }
        .meta-item {
            display: flex;
            justify-content: space-between;
            padding: 2px 0;
            font-size: 11.5px;
        }
        .meta-label {
            color: #64748b;
        }
        .meta-val {
            font-weight: 600;
            color: #0f172a;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 16px;
        }
        th {
            background: #f1f5f9;
            color: #334155;
            font-weight: 700;
            text-align: left;
            padding: 8px;
            border-top: 1px solid #cbd5e1;
            border-bottom: 2px solid #cbd5e1;
            font-size: 11px;
            text-transform: uppercase;
        }
        .summary-container {
            display: flex;
            justify-content: space-between;
            gap: 20px;
            margin-bottom: 24px;
            page-break-inside: avoid;
        }
        .note-box {
            flex: 1;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 10px 12px;
            font-size: 11px;
            color: #475569;
        }
        .calc-box {
            width: 320px;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 10px 12px;
            font-size: 11.5px;
        }
        .calc-row {
            display: flex;
            justify-content: space-between;
            padding: 3px 0;
            color: #475569;
        }
        .calc-row.total {
            border-top: 2px solid #047857;
            padding-top: 6px;
            margin-top: 4px;
            font-size: 13px;
            font-weight: 800;
            color: #047857;
        }
        .signatures {
            display: flex;
            justify-content: space-between;
            margin-top: 20px;
            page-break-inside: avoid;
            text-align: center;
        }
        .sig-col {
            width: 45%;
        }
        .sig-title {
            font-weight: 700;
            color: #0f172a;
            font-size: 12px;
            text-transform: uppercase;
        }
        .sig-sub {
            font-size: 11px;
            color: #64748b;
            font-style: italic;
            margin-top: 2px;
        }
        @media print {
            .no-print {
                display: none !important;
            }
        }
    </style>
</head>
<body style="padding-top: 30px;">
    <!-- Floating Toolbar (hidden when printing) -->
    <div class="no-print" style="position:fixed;top:10px;right:15px;display:flex;gap:8px;z-index:9999;background:white;padding:6px 10px;border-radius:10px;box-shadow:0 4px 15px rgba(0,0,0,0.12);border:1px solid #cbd5e1;">
        <button onclick="window.print()" style="display:inline-flex;align-items:center;gap:6px;padding:6px 14px;background:#0284c7;color:white;border:none;border-radius:6px;font-weight:700;cursor:pointer;font-size:12px;font-family:sans-serif;">
            <span>🖨️ In Hóa Đơn (Ctrl + P)</span>
        </button>
        <button onclick="window.close()" style="padding:6px 12px;background:#f8fafc;color:#475569;border:1px solid #cbd5e1;border-radius:6px;font-weight:600;cursor:pointer;font-size:12px;font-family:sans-serif;">
            ✕ Đóng
        </button>
    </div>

    <!-- Header -->
    <div class="header">
        <div>
            <div class="company-name">BAN QUẢN LÝ TÒA NHÀ CASSAVAS SMART</div>
            <div class="company-sub">Hệ thống Vận hành Quản lý Tòa nhà & Cư dân Thông minh</div>
            <div class="company-sub">Hotline CSKH: 1900 6868 • Email: bql@cassavas.vn</div>
        </div>
        <div style="text-align:right;">
            <div style="font-size:11px;color:#64748b;">MÃ HÓA ĐƠN:</div>
            <div style="font-size:14px;font-weight:800;font-family:monospace;color:#0369a1;">${invoice.invoice_number}</div>
            <div style="margin-top:3px;">${getStatusBadgeHtml(invoice.status)}</div>
        </div>
    </div>

    <!-- Title -->
    <div class="invoice-title">
        <h1>BẢNG KÊ CHI PHÍ & THÔNG BÁO THU TIỀN</h1>
        <p>Kỳ thanh toán: <strong style="color:#0369a1;">${invoice.billing_period}</strong> • Ngày lập: ${invoice.issue_date || todayStr}</p>
    </div>

    <!-- Metadata Grid -->
    <div class="meta-box">
        <div>
            <div class="meta-item">
                <span class="meta-label">Căn hộ:</span>
                <span class="meta-val">Căn ${aptNumber} ${blockName ? `(${blockName})` : ''}</span>
            </div>
            <div class="meta-item">
                <span class="meta-label">Diện tích sử dụng:</span>
                <span class="meta-val">${area} m²</span>
            </div>
            <div class="meta-item">
                <span class="meta-label">Kỳ tính phí:</span>
                <span class="meta-val" style="color:#0369a1;">${invoice.billing_period}</span>
            </div>
        </div>
        <div>
            <div class="meta-item">
                <span class="meta-label">Cư dân nhận:</span>
                <span class="meta-val">${residentName}</span>
            </div>
            <div class="meta-item">
                <span class="meta-label">Số điện thoại:</span>
                <span class="meta-val" style="font-family:monospace;">${residentPhone}</span>
            </div>
            <div class="meta-item">
                <span class="meta-label">Hạn thanh toán:</span>
                <span class="meta-val" style="color:#b91c1c;">${invoice.due_date || 'Theo quy định'}</span>
            </div>
        </div>
    </div>

    <!-- Items Table -->
    <table>
        <thead>
            <tr>
                <th style="width:35px;text-align:center;">STT</th>
                <th>Khoản Mục Chi Phí Dịch Vụ</th>
                <th style="width:50px;text-align:center;">ĐVT</th>
                <th style="width:60px;text-align:right;">SL</th>
                <th style="width:85px;text-align:right;">Đơn Giá</th>
                <th style="width:90px;text-align:right;">Trước Thuế</th>
                <th style="width:85px;text-align:right;">VAT & MT</th>
                <th style="width:100px;text-align:right;">Thành Tiền (đ)</th>
            </tr>
        </thead>
        <tbody>
            ${itemsRowsHtml || '<tr><td colspan="8" style="text-align:center;padding:15px;color:#94a3b8;">Chưa có chi tiết khoản mục.</td></tr>'}
        </tbody>
    </table>

    <!-- Summary Section -->
    <div class="summary-container">
        <div class="note-box">
            <div style="font-weight:700;color:#0f172a;margin-bottom:4px;">HƯỚNG DẪN THANH TOÁN:</div>
            <div>• Quý cư dân có thể thanh toán trực tiếp tại Quầy Lễ Tân hoặc Chuyển khoản ngân hàng.</div>
            <div>• Ngân hàng: <strong>MB Bank (Quân Đội)</strong></div>
            <div>• Số tài khoản: <strong>038868689999</strong></div>
            <div>• Chủ tài khoản: <strong>BAN QUAN LY SMART CASSAVAS</strong></div>
            <div>• Nội dung CK: <strong>${invoice.invoice_number} CAN ${aptNumber}</strong></div>

            ${((invoice as any).payments?.length || 0) > 0 ? `
                <div style="margin-top:8px;padding-top:6px;border-top:1px solid #e2e8f0;">
                    <div style="font-weight:700;color:#047857;margin-bottom:2px;">CÁC ĐỢT ĐÃ THANH TOÁN:</div>
                    ${paymentsRowsHtml}
                </div>
            ` : ''}
        </div>

        <div class="calc-box">
            <div class="calc-row">
                <span>Tổng tiền trước thuế:</span>
                <span style="font-family:monospace;font-weight:600;">${formatVnd(invoice.subtotal_amount)}</span>
            </div>
            <div class="calc-row">
                <span>Thuế VAT & BVMT:</span>
                <span style="font-family:monospace;font-weight:600;">${formatVnd(invoice.tax_amount)}</span>
            </div>
            ${Number(invoice.previous_debt_amount) > 0 ? `
                <div class="calc-row" style="color:#b45309;font-weight:600;">
                    <span>Công nợ tồn kỳ trước:</span>
                    <span style="font-family:monospace;">+${formatVnd(invoice.previous_debt_amount)}</span>
                </div>
            ` : ''}
            <div class="calc-row total">
                <span>TỔNG CỘNG PHẢI THU:</span>
                <span style="font-family:monospace;">${formatVnd(invoice.total_amount)}</span>
            </div>
            <div class="calc-row" style="color:#047857;font-weight:600;margin-top:4px;">
                <span>Số tiền đã thanh toán:</span>
                <span style="font-family:monospace;">${formatVnd(invoice.paid_amount)}</span>
            </div>
            <div class="calc-row" style="color:${Number(invoice.remaining_balance) > 0 ? '#b91c1c' : '#047857'};font-weight:700;border-top:1px dashed #cbd5e1;padding-top:4px;margin-top:2px;">
                <span>DƯ NỢ CÒN LẠI:</span>
                <span style="font-family:monospace;font-size:12.5px;">${formatVnd(invoice.remaining_balance)}</span>
            </div>
        </div>
    </div>

    <!-- Signatures -->
    <div class="signatures">
        <div class="sig-col">
            <div class="sig-title">NGƯỜI NỘP TIỀN / CƯ DÂN</div>
            <div class="sig-sub">(Ký và ghi rõ họ tên)</div>
            <div class="sig-space"></div>
            <div style="font-weight:600;color:#334155;">${residentName}</div>
        </div>
        <div class="sig-col">
            <div style="font-size:11px;color:#64748b;margin-bottom:2px;">Ngày ..... tháng ..... năm 20....</div>
            <div class="sig-title">ĐẠI DIỆN BAN QUẢN LÝ TÒA NHÀ</div>
            <div class="sig-sub">(Ký, ghi rõ họ tên và đóng dấu)</div>
            <div class="sig-space"></div>
            <div style="font-weight:600;color:#334155;">Ban Quản Lý Smart Cassavas</div>
        </div>
    </div>
</body>
</html>
    `;
};

/**
 * Generate full HTML for printable Receipt (Biên Lai Thu Tiền)
 */
export const generateReceiptPrintHtml = (receiptModalData: any): string => {
    const receipt = receiptModalData?.receipt;
    const invoice = receiptModalData?.invoice;
    const payment = receiptModalData?.payment;
    const todayStr = receipt?.receipt_date || new Date().toLocaleDateString('vi-VN');

    return `
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <title>Biên Lai ${receipt?.receipt_number || 'Thu Tiền'}</title>
    <style>
        @page {
            size: A5 landscape;
            margin: 10mm 12mm;
        }
        * {
            box-sizing: border-box;
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }
        body {
            font-family: 'Segoe UI', -apple-system, BlinkMacSystemFont, Arial, sans-serif;
            color: #1e293b;
            margin: 0;
            padding: 10px;
            font-size: 12px;
            line-height: 1.5;
            background: #ffffff;
        }
        .box {
            border: 2px solid #047857;
            border-radius: 8px;
            padding: 16px;
        }
        .header {
            text-align: center;
            border-bottom: 1px solid #cbd5e1;
            padding-bottom: 8px;
            margin-bottom: 12px;
        }
        .company-name {
            font-size: 13px;
            font-weight: 800;
            color: #047857;
            text-transform: uppercase;
        }
        .title {
            font-size: 17px;
            font-weight: 800;
            color: #0f172a;
            margin: 4px 0 2px;
            text-transform: uppercase;
        }
        .meta {
            font-size: 11px;
            color: #64748b;
        }
        .rows {
            margin: 12px 0;
            font-size: 12px;
        }
        .row {
            display: flex;
            justify-content: space-between;
            padding: 4px 0;
            border-bottom: 1px dashed #f1f5f9;
        }
        .amount-box {
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            border-radius: 6px;
            padding: 8px 12px;
            margin: 10px 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .signatures {
            display: flex;
            justify-content: space-between;
            margin-top: 15px;
            text-align: center;
        }
        .sig-col { width: 45%; }
        .sig-title { font-weight: 700; font-size: 11px; text-transform: uppercase; }
        .sig-sub { font-size: 10px; color: #64748b; font-style: italic; }
        .sig-space { height: 45px; }
        .hash-box {
            font-size: 9px;
            color: #64748b;
            font-family: monospace;
            word-break: break-all;
            background: #f8fafc;
            padding: 4px 6px;
            border-radius: 4px;
            margin-top: 8px;
            border: 1px solid #e2e8f0;
        }
    </style>
</head>
<body>
    <div class="box">
        <div class="header">
            <div class="company-name">BAN QUẢN LÝ TÒA NHÀ CASSAVAS SMART</div>
            <div class="title">BIÊN LAI THU TIỀN DỊCH VỤ</div>
            <div class="meta">Số: <strong style="color:#0369a1;font-family:monospace;">${receipt?.receipt_number || 'REC-N/A'}</strong> • Ngày lập: ${todayStr}</div>
        </div>

        <div class="rows">
            <div class="row">
                <span style="color:#64748b;">Họ tên người nộp tiền:</span>
                <strong>${receipt?.received_from_name || 'Cư dân'}</strong>
            </div>
            <div class="row">
                <span style="color:#64748b;">Hóa đơn gạch nợ:</span>
                <strong style="color:#0369a1;font-family:monospace;">${invoice?.invoice_number || 'N/A'} (Kỳ ${invoice?.billing_period || ''})</strong>
            </div>
            <div class="row">
                <span style="color:#64748b;">Căn hộ áp dụng:</span>
                <strong>Căn ${invoice?.apartment?.apartment_number || 'N/A'} ${invoice?.apartment?.block?.block_name ? `(${invoice.apartment.block.block_name})` : ''}</strong>
            </div>
            <div class="row">
                <span style="color:#64748b;">Phương thức thanh toán:</span>
                <strong style="color:#047857;">${payment?.payment_gateway || 'Tiền mặt tại quầy'}</strong>
            </div>
        </div>

        <div class="amount-box">
            <span style="font-weight:700;color:#065f46;">Số tiền thực thu:</span>
            <span style="font-size:16px;font-weight:800;font-family:monospace;color:#047857;">${formatVnd(receipt?.amount)}</span>
        </div>

        ${receipt?.amount_in_words ? `
            <div style="font-size:11px;color:#334155;margin-bottom:8px;">
                <span>Bằng chữ: </span>
                <em style="font-weight:600;">${receipt.amount_in_words}</em>
            </div>
        ` : ''}

        ${receipt?.digital_signature_hash ? `
            <div class="hash-box">
                Xác thực số (SHA256): ${receipt.digital_signature_hash}
            </div>
        ` : ''}

        <div class="signatures">
            <div class="sig-col">
                <div class="sig-title">NGƯỜI NỘP TIỀN</div>
                <div class="sig-sub">(Ký, ghi rõ họ tên)</div>
                <div class="sig-space"></div>
                <div>${receipt?.received_from_name || ''}</div>
            </div>
            <div class="sig-col">
                <div class="sig-title">NGƯỜI THU TIỀN / THỦ QUỸ</div>
                <div class="sig-sub">(Ký, ghi rõ họ tên)</div>
                <div class="sig-space"></div>
                <div>Ban Quản Lý Smart Cassavas</div>
            </div>
        </div>
    </div>
</body>
</html>
    `;
};

/**
 * Print HTML directly using an isolated, clean hidden iframe
 * Guarantees zero UI freezing, no backdrop-filter interference, and instantaneous preview loading in Chrome
 */
/**
 * Print HTML document reliably across all browsers and PDF printers
 * Uses a dedicated clean print window to guarantee instant print preview without hanging
 */
export const printHtmlViaIframe = (htmlContent: string, title = 'In Tài Liệu'): void => {
    // 1. Try window.open first (most reliable print preview rendering for Chrome, Edge, Firefox)
    try {
        const printWindow = window.open('', '_blank');
        if (printWindow && !printWindow.closed) {
            printWindow.document.open();
            printWindow.document.write(htmlContent);
            printWindow.document.close();
            printWindow.document.title = title;
            printWindow.focus();

            const triggerPrint = () => {
                try {
                    printWindow.focus();
                    printWindow.print();
                } catch (err) {
                    console.error('Lỗi khi gọi printWindow.print():', err);
                }
            };

            if (printWindow.document.readyState === 'complete') {
                setTimeout(triggerPrint, 350);
            } else {
                printWindow.onload = () => setTimeout(triggerPrint, 350);
            }
            return;
        }
    } catch (e) {
        console.warn('window.open was blocked or failed, falling back to off-screen iframe:', e);
    }

    // 2. Fallback: off-screen iframe with real layout dimensions (1000px x 1400px, NOT 0x0)
    const oldFrames = document.querySelectorAll('.smart-invoice-print-frame');
    oldFrames.forEach(f => f.remove());

    const iframe = document.createElement('iframe');
    iframe.className = 'smart-invoice-print-frame';
    iframe.style.position = 'fixed';
    iframe.style.left = '-9999px';
    iframe.style.top = '0';
    iframe.style.width = '1000px';
    iframe.style.height = '1414px'; // Standard A4 ratio
    iframe.style.border = 'none';
    iframe.style.zIndex = '-9999';
    iframe.title = title;
    document.body.appendChild(iframe);

    const doc = iframe.contentWindow?.document;
    if (doc) {
        doc.open();
        doc.write(htmlContent);
        doc.close();

        setTimeout(() => {
            try {
                iframe.contentWindow?.focus();
                iframe.contentWindow?.print();
            } catch (err) {
                console.error('Lỗi khi in qua iframe fallback:', err);
            }
        }, 350);
    }
};

/**
 * High-level helper: Print Invoice Statement
 */
export const printInvoiceStatement = (invoice: InvoiceModel): void => {
    const html = generateInvoicePrintHtml(invoice);
    printHtmlViaIframe(html, `Hóa Đơn ${invoice.invoice_number} - Căn ${invoice.apartment?.apartment_number || ''}`);
};

/**
 * High-level helper: Print Receipt
 */
export const printPaymentReceipt = (receiptModalData: any): void => {
    const html = generateReceiptPrintHtml(receiptModalData);
    printHtmlViaIframe(html, `Biên Lai ${receiptModalData?.receipt?.receipt_number || 'Thu Tiền'}`);
};


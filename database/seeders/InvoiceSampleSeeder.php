<?php

namespace Database\Seeders;

use App\Models\Apartment;
use App\Models\Block;
use App\Models\Floor;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\PaymentReceipt;
use App\Models\User;
use App\Services\PaymentCollectionService;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class InvoiceSampleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $paymentService = app(PaymentCollectionService::class);

        // 1. TẠO KHỐI TÒA & TẦNG
        $blockA = Block::firstOrCreate(
            ['block_code' => 'T-A'],
            [
                'block_name' => 'Tòa Tháp A Sapphire',
                'total_floors' => 25,
                'total_apartments' => 100,
            ]
        );

        $blockB = Block::firstOrCreate(
            ['block_code' => 'T-B'],
            [
                'block_name' => 'Tòa Tháp B Ruby',
                'total_floors' => 25,
                'total_apartments' => 100,
            ]
        );

        $floor1A = Floor::firstOrCreate(
            ['floor_code' => 'FL-A01'],
            ['block_id' => $blockA->id, 'floor_number' => 1, 'floor_name' => 'Tầng 1']
        );

        $floor2A = Floor::firstOrCreate(
            ['floor_code' => 'FL-A02'],
            ['block_id' => $blockA->id, 'floor_number' => 2, 'floor_name' => 'Tầng 2']
        );

        $floor3A = Floor::firstOrCreate(
            ['floor_code' => 'FL-A03'],
            ['block_id' => $blockA->id, 'floor_number' => 3, 'floor_name' => 'Tầng 3']
        );

        $floor5B = Floor::firstOrCreate(
            ['floor_code' => 'FL-B05'],
            ['block_id' => $blockB->id, 'floor_number' => 5, 'floor_name' => 'Tầng 5']
        );

        $floor6B = Floor::firstOrCreate(
            ['floor_code' => 'FL-B06'],
            ['block_id' => $blockB->id, 'floor_number' => 6, 'floor_name' => 'Tầng 6']
        );

        // 2. DANH SÁCH CƯ DÂN & CĂN HỘ MẪU
        $sampleResidents = [
            ['name' => 'Đặng Thu Thảo', 'email' => 'thuthao.dang@gmail.com', 'phone' => '0918000001', 'apt' => 'A-101', 'block' => $blockA, 'floor' => $floor1A],
            ['name' => 'Nguyễn Văn An', 'email' => 'an.nguyen@gmail.com', 'phone' => '0918000002', 'apt' => 'A-202', 'block' => $blockA, 'floor' => $floor2A],
            ['name' => 'Trần Thị Bích', 'email' => 'bich.tran@gmail.com', 'phone' => '0918000003', 'apt' => 'B-504', 'block' => $blockB, 'floor' => $floor5B],
            ['name' => 'Lê Hoàng Nam', 'email' => 'nam.le@gmail.com', 'phone' => '0918000004', 'apt' => 'B-505', 'block' => $blockB, 'floor' => $floor5B],
            ['name' => 'Phạm Quốc Huy', 'email' => 'huy.pham@gmail.com', 'phone' => '0918000005', 'apt' => 'A-102', 'block' => $blockA, 'floor' => $floor1A],
            ['name' => 'Vũ Minh Tâm', 'email' => 'tam.vu@gmail.com', 'phone' => '0918000006', 'apt' => 'A-203', 'block' => $blockA, 'floor' => $floor2A],
            ['name' => 'Hoàng Diệu Linh', 'email' => 'linh.hoang@gmail.com', 'phone' => '0918000007', 'apt' => 'A-301', 'block' => $blockA, 'floor' => $floor3A],
            ['name' => 'Ngô Bảo Long', 'email' => 'long.ngo@gmail.com', 'phone' => '0918000008', 'apt' => 'B-601', 'block' => $blockB, 'floor' => $floor6B],
        ];

        $userMap = [];
        $aptMap = [];

        foreach ($sampleResidents as $item) {
            $user = User::firstOrCreate(
                ['email' => $item['email']],
                [
                    'full_name' => $item['name'],
                    'phone_number' => $item['phone'],
                    'status' => 'ACTIVE',
                    'password_hash' => bcrypt('123567'),
                ]
            );

            $apartment = Apartment::firstOrCreate(
                ['apartment_number' => $item['apt']],
                [
                    'block_id' => $item['block']->id,
                    'floor_id' => $item['floor']->id,
                    'net_usable_area_sqm' => 75.0,
                    'gross_floor_area_sqm' => 80.0,
                    'current_resident_user_id' => $user->id,
                    'status' => 'OCCUPIED',
                ]
            );

            $userMap[$item['apt']] = $user;
            $aptMap[$item['apt']] = $apartment;
        }

        // Lấy admin/manager để gán recorded_by_staff_id nếu có
        $staffUser = User::where('email', 'admin@cassavas.vn')->orWhere('email', 'quanly@cassavas.vn')->first();
        $staffId = $staffUser?->id;

        // 3. TẠO HÓA ĐƠN & GIAO DỊCH QUA CÁC KỲ (2026-08, 2026-09, 2026-10)
        $invoiceDefinitions = [
            // --- KỲ 2026-08 (Kỳ trước: Đã thu hoàn tất qua nhiều hình thức) ---
            [
                'inv_num' => 'INV-202608-001',
                'period' => '2026-08',
                'apt' => 'A-101',
                'total' => 2200000,
                'paid' => 2200000,
                'status' => 'PAID',
                'issue_date' => '2026-08-05',
                'due_date' => '2026-08-25',
                'payment' => [
                    'gateway' => 'BANK_TRANSFER',
                    'time' => '2026-08-10 09:15:30',
                    'ref_suffix' => '801',
                    'gateway_tx' => 'MB-20260810-882190',
                    'notes' => 'Chuyển khoản thanh toán phí dịch vụ T8/2026',
                ],
            ],
            [
                'inv_num' => 'INV-202608-002',
                'period' => '2026-08',
                'apt' => 'A-202',
                'total' => 1150000,
                'paid' => 1150000,
                'status' => 'PAID',
                'issue_date' => '2026-08-05',
                'due_date' => '2026-08-25',
                'payment' => [
                    'gateway' => 'VIETQR',
                    'time' => '2026-08-12 14:22:10',
                    'ref_suffix' => '802',
                    'gateway_tx' => 'VQR-20260812-339182',
                    'notes' => 'Quét mã VietQR gạch nợ tự động T8/2026',
                ],
            ],
            [
                'inv_num' => 'INV-202608-003',
                'period' => '2026-08',
                'apt' => 'B-504',
                'total' => 3600000,
                'paid' => 3600000,
                'status' => 'PAID',
                'issue_date' => '2026-08-05',
                'due_date' => '2026-08-25',
                'payment' => [
                    'gateway' => 'CASH',
                    'time' => '2026-08-15 10:05:40',
                    'ref_suffix' => '803',
                    'gateway_tx' => 'CASH-T8-003',
                    'notes' => 'Cư dân nộp tiền mặt trực tiếp tại quầy BQL',
                ],
            ],

            // --- KỲ 2026-09 (Kỳ trước: Đã thu và có hóa đơn đã gạch nợ) ---
            [
                'inv_num' => 'INV-202609-001',
                'period' => '2026-09',
                'apt' => 'A-101',
                'total' => 2350000,
                'paid' => 2350000,
                'status' => 'PAID',
                'issue_date' => '2026-09-05',
                'due_date' => '2026-09-25',
                'payment' => [
                    'gateway' => 'VIETQR',
                    'time' => '2026-09-08 11:30:00',
                    'ref_suffix' => '901',
                    'gateway_tx' => 'VQR-20260908-109283',
                    'notes' => 'Thanh toán VietQR MB Bank kỳ T9/2026',
                ],
            ],
            [
                'inv_num' => 'INV-202609-002',
                'period' => '2026-09',
                'apt' => 'A-203',
                'total' => 1400000,
                'paid' => 1400000,
                'status' => 'PAID',
                'issue_date' => '2026-09-05',
                'due_date' => '2026-09-25',
                'payment' => [
                    'gateway' => 'VNPAY',
                    'time' => '2026-09-14 16:45:12',
                    'ref_suffix' => '902',
                    'gateway_tx' => 'VNPAY-99281729',
                    'notes' => 'Cổng VNPay xác nhận thanh toán trực tuyến',
                ],
            ],
            [
                'inv_num' => 'INV-202609-003',
                'period' => '2026-09',
                'apt' => 'A-301',
                'total' => 1800000,
                'paid' => 1800000,
                'status' => 'PAID',
                'issue_date' => '2026-09-05',
                'due_date' => '2026-09-25',
                'payment' => [
                    'gateway' => 'CASH',
                    'time' => '2026-09-20 15:10:00',
                    'ref_suffix' => '903',
                    'gateway_tx' => 'CASH-T9-005',
                    'notes' => 'Thu tiền mặt tại quầy lễ tân tầng 1',
                ],
            ],

            // --- KỲ 2026-10 (Kỳ hiện tại: Có hóa đơn đã thu, thanh toán một phần và chờ thu) ---
            [
                'inv_num' => 'INV-202610-001',
                'period' => '2026-10',
                'apt' => 'A-101',
                'total' => 2450000,
                'paid' => 0,
                'status' => 'ISSUED',
                'issue_date' => '2026-10-05',
                'due_date' => '2026-10-25',
                'payment' => null, // Chưa thanh toán
            ],
            [
                'inv_num' => 'INV-202610-002',
                'period' => '2026-10',
                'apt' => 'A-202',
                'total' => 1200000,
                'paid' => 500000,
                'status' => 'PARTIAL',
                'issue_date' => '2026-10-05',
                'due_date' => '2026-10-25',
                'payment' => [
                    'amount' => 500000,
                    'gateway' => 'CASH',
                    'time' => '2026-10-12 09:40:00',
                    'ref_suffix' => '102',
                    'gateway_tx' => 'CASH-T10-002',
                    'notes' => 'Cư dân tạm ứng nộp 500.000 đ tiền mặt tại quầy BQL',
                ],
            ],
            [
                'inv_num' => 'INV-202610-003',
                'period' => '2026-10',
                'apt' => 'B-504',
                'total' => 3850000,
                'paid' => 0,
                'status' => 'OVERDUE',
                'issue_date' => '2026-09-15',
                'due_date' => '2026-10-01',
                'payment' => null,
            ],
            [
                'inv_num' => 'INV-202610-004',
                'period' => '2026-10',
                'apt' => 'B-505',
                'total' => 1750000,
                'paid' => 0,
                'status' => 'ISSUED',
                'issue_date' => '2026-10-05',
                'due_date' => '2026-10-25',
                'payment' => null,
            ],
            [
                'inv_num' => 'INV-202610-005',
                'period' => '2026-10',
                'apt' => 'A-102',
                'total' => 850000,
                'paid' => 0,
                'status' => 'ISSUED',
                'issue_date' => '2026-10-05',
                'due_date' => '2026-10-25',
                'payment' => null,
            ],
            [
                'inv_num' => 'INV-202610-006',
                'period' => '2026-10',
                'apt' => 'A-203',
                'total' => 1500000,
                'paid' => 1500000,
                'status' => 'PAID',
                'issue_date' => '2026-10-05',
                'due_date' => '2026-10-25',
                'payment' => [
                    'amount' => 1500000,
                    'gateway' => 'BANK_TRANSFER',
                    'time' => '2026-10-08 14:10:00',
                    'ref_suffix' => '106',
                    'gateway_tx' => 'TECH-20261008-88129',
                    'notes' => 'Chuyển khoản Techcombank gạch nợ hoàn tất',
                ],
            ],
            [
                'inv_num' => 'INV-202610-007',
                'period' => '2026-10',
                'apt' => 'A-301',
                'total' => 1950000,
                'paid' => 1950000,
                'status' => 'PAID',
                'issue_date' => '2026-10-05',
                'due_date' => '2026-10-25',
                'payment' => [
                    'amount' => 1950000,
                    'gateway' => 'VIETQR',
                    'time' => '2026-10-15 10:25:00',
                    'ref_suffix' => '107',
                    'gateway_tx' => 'VQR-20261015-449102',
                    'notes' => 'Quét mã VietQR chuyển khoản nhanh gạch nợ',
                ],
            ],
            [
                'inv_num' => 'INV-202610-008',
                'period' => '2026-10',
                'apt' => 'B-601',
                'total' => 2600000,
                'paid' => 2600000,
                'status' => 'PAID',
                'issue_date' => '2026-10-05',
                'due_date' => '2026-10-25',
                'payment' => [
                    'amount' => 2600000,
                    'gateway' => 'MOMO',
                    'time' => '2026-10-18 19:30:00',
                    'ref_suffix' => '108',
                    'gateway_tx' => 'MOMO-20261018-9921',
                    'notes' => 'Cổng ví MoMo thanh toán trực tuyến',
                ],
            ],
        ];

        $receiptIndex = 100;

        foreach ($invoiceDefinitions as $def) {
            $user = $userMap[$def['apt']];
            $apartment = $aptMap[$def['apt']];

            $totalAmount = $def['total'];
            $paidAmount = $def['paid'];
            $remaining = max(0, $totalAmount - $paidAmount);

            $invoice = Invoice::updateOrCreate(
                ['invoice_number' => $def['inv_num']],
                [
                    'apartment_id' => $apartment->id,
                    'resident_user_id' => $user->id,
                    'billing_period' => $def['period'],
                    'issue_date' => $def['issue_date'],
                    'due_date' => $def['due_date'],
                    'subtotal_amount' => round($totalAmount / 1.1),
                    'tax_amount' => round($totalAmount - ($totalAmount / 1.1)),
                    'discount_amount' => 0,
                    'previous_debt_amount' => 0,
                    'total_amount' => $totalAmount,
                    'paid_amount' => $paidAmount,
                    'remaining_balance' => $remaining,
                    'status' => $def['status'],
                    'notes' => "Hóa đơn dịch vụ & tiện ích kỳ {$def['period']}",
                ]
            );

            // Xóa các dòng khoản mục cũ để không bị trùng lặp
            InvoiceItem::where('invoice_id', $invoice->id)->delete();

            // Sinh khoản mục chi phí thực tế
            $mgmtPreTax = round($totalAmount * 0.45 / 1.1);
            $elecPreTax = round($totalAmount * 0.35 / 1.1);
            $waterPreTax = round($totalAmount / 1.1) - $mgmtPreTax - $elecPreTax;

            InvoiceItem::create([
                'invoice_id' => $invoice->id,
                'service_code' => 'MANAGEMENT_FEE',
                'item_description' => "Phí quản lý vận hành tòa nhà T{$def['period']}",
                'quantity' => 75,
                'unit_name' => 'm²',
                'unit_price' => round($mgmtPreTax / 75),
                'amount_before_tax' => $mgmtPreTax,
                'vat_percentage' => 10,
                'vat_amount' => round($mgmtPreTax * 0.1),
                'environmental_fee_amount' => 0,
                'total_line_amount' => $mgmtPreTax + round($mgmtPreTax * 0.1),
            ]);

            InvoiceItem::create([
                'invoice_id' => $invoice->id,
                'service_code' => 'ELECTRICITY',
                'item_description' => "Điện sinh hoạt căn hộ kỳ T{$def['period']}",
                'quantity' => 150,
                'unit_name' => 'kWh',
                'unit_price' => round($elecPreTax / 150),
                'amount_before_tax' => $elecPreTax,
                'vat_percentage' => 10,
                'vat_amount' => round($elecPreTax * 0.1),
                'environmental_fee_amount' => 0,
                'total_line_amount' => $elecPreTax + round($elecPreTax * 0.1),
            ]);

            InvoiceItem::create([
                'invoice_id' => $invoice->id,
                'service_code' => 'WATER',
                'item_description' => "Nước sinh hoạt căn hộ kỳ T{$def['period']}",
                'quantity' => 15,
                'unit_name' => 'm³',
                'unit_price' => round($waterPreTax / 15),
                'amount_before_tax' => $waterPreTax,
                'vat_percentage' => 10,
                'vat_amount' => $invoice->tax_amount - round($mgmtPreTax * 0.1) - round($elecPreTax * 0.1),
                'environmental_fee_amount' => 0,
                'total_line_amount' => $waterPreTax + ($invoice->tax_amount - round($mgmtPreTax * 0.1) - round($elecPreTax * 0.1)),
            ]);

            // 4. NẾU CÓ DỮ LIỆU THANH TOÁN -> TẠO BẢN GHI PAYMENT VÀ BIÊN LAI PAYMENTRECEIPT
            if (! empty($def['payment'])) {
                $payInfo = $def['payment'];
                $amountPaid = $payInfo['amount'] ?? $def['paid'];

                $cleanPeriod = str_replace('-', '', $def['period']);
                $cleanApt = str_replace(['/', ' ', '-'], '', $def['apt']);
                $refCode = "PAY-{$cleanPeriod}-{$cleanApt}-{$payInfo['ref_suffix']}";

                $payment = Payment::updateOrCreate(
                    ['payment_reference_code' => $refCode],
                    [
                        'invoice_id' => $invoice->id,
                        'apartment_id' => $apartment->id,
                        'payer_user_id' => $user->id,
                        'amount_paid' => $amountPaid,
                        'payment_gateway' => $payInfo['gateway'],
                        'gateway_transaction_id' => $payInfo['gateway_tx'],
                        'idempotency_key' => "seed-idemp-{$refCode}",
                        'payment_status' => 'SUCCESS',
                        'payment_time' => Carbon::parse($payInfo['time']),
                        'recorded_by_staff_id' => $staffId,
                        'notes' => $payInfo['notes'],
                    ]
                );

                // Tự động sinh biên lai thu tiền chính thức
                $receiptIndex++;
                $receiptNumber = "BL-{$cleanPeriod}-".str_pad((string) $receiptIndex, 5, '0', STR_PAD_LEFT);
                $amountInWords = $paymentService->convertNumberToWords((int) round($amountPaid)).' đồng chẵn';
                $signatureHash = hash('sha256', "{$receiptNumber}|{$payment->id}|{$amountPaid}|{$invoice->id}|".config('app.key'));

                PaymentReceipt::updateOrCreate(
                    ['payment_id' => $payment->id],
                    [
                        'receipt_number' => $receiptNumber,
                        'receipt_date' => Carbon::parse($payInfo['time'])->format('Y-m-d'),
                        'amount' => $amountPaid,
                        'amount_in_words' => $amountInWords,
                        'received_from_name' => $user->full_name,
                        'digital_signature_hash' => $signatureHash,
                    ]
                );
            }
        }
    }
}

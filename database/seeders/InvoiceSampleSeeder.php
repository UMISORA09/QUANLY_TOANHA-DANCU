<?php

namespace Database\Seeders;

use App\Models\Apartment;
use App\Models\Block;
use App\Models\Floor;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\User;
use Illuminate\Database\Seeder;

class InvoiceSampleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
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

        $floor5B = Floor::firstOrCreate(
            ['floor_code' => 'FL-B05'],
            ['block_id' => $blockB->id, 'floor_number' => 5, 'floor_name' => 'Tầng 5']
        );

        $sampleResidents = [
            ['name' => 'Đặng Thu Thảo', 'email' => 'thuthao.dang@gmail.com', 'phone' => '0918000001', 'apt' => 'A-101', 'block' => $blockA, 'floor' => $floor1A, 'debt' => 2450000, 'status' => 'ISSUED'],
            ['name' => 'Nguyễn Văn An', 'email' => 'an.nguyen@gmail.com', 'phone' => '0918000002', 'apt' => 'A-202', 'block' => $blockA, 'floor' => $floor2A, 'debt' => 1200000, 'status' => 'PARTIAL'],
            ['name' => 'Trần Thị Bích', 'email' => 'bich.tran@gmail.com', 'phone' => '0918000003', 'apt' => 'B-504', 'block' => $blockB, 'floor' => $floor5B, 'debt' => 3850000, 'status' => 'OVERDUE'],
            ['name' => 'Lê Hoàng Nam', 'email' => 'nam.le@gmail.com', 'phone' => '0918000004', 'apt' => 'B-505', 'block' => $blockB, 'floor' => $floor5B, 'debt' => 1750000, 'status' => 'ISSUED'],
            ['name' => 'Phạm Quốc Huy', 'email' => 'huy.pham@gmail.com', 'phone' => '0918000005', 'apt' => 'A-102', 'block' => $blockA, 'floor' => $floor1A, 'debt' => 850000, 'status' => 'ISSUED'],
            ['name' => 'Vũ Minh Tâm', 'email' => 'tam.vu@gmail.com', 'phone' => '0918000006', 'apt' => 'A-203', 'block' => $blockA, 'floor' => $floor2A, 'debt' => 0, 'status' => 'PAID'],
        ];

        foreach ($sampleResidents as $index => $item) {
            $user = User::firstOrCreate(
                ['email' => $item['email']],
                [
                    'full_name' => $item['name'],
                    'phone_number' => $item['phone'],
                    'status' => 'ACTIVE',
                    'password_hash' => bcrypt('password123'),
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

            $totalAmount = $item['debt'] > 0 ? $item['debt'] : 1500000;
            $paidAmount = $item['status'] === 'PAID' ? $totalAmount : ($item['status'] === 'PARTIAL' ? 500000 : 0);
            $remaining = $totalAmount - $paidAmount;

            $invNumber = sprintf('INV-202610-%03d', $index + 1);

            $invoice = Invoice::updateOrCreate(
                ['invoice_number' => $invNumber],
                [
                    'apartment_id' => $apartment->id,
                    'resident_user_id' => $user->id,
                    'billing_period' => '2026-10',
                    'issue_date' => '2026-10-05',
                    'due_date' => '2026-10-25',
                    'subtotal_amount' => round($totalAmount / 1.1),
                    'tax_amount' => round($totalAmount - ($totalAmount / 1.1)),
                    'discount_amount' => 0,
                    'previous_debt_amount' => 0,
                    'total_amount' => $totalAmount,
                    'paid_amount' => $paidAmount,
                    'remaining_balance' => $remaining,
                    'status' => $item['status'],
                    'notes' => 'Hóa đơn dịch vụ & tiện ích kỳ 10/2026',
                ]
            );

            // Xóa các dòng khoản mục cũ để không bị trùng lặp
            InvoiceItem::where('invoice_id', $invoice->id)->delete();

            // Sinh các khoản mục chi phí thực tế và chính xác cho từng hóa đơn
            if ($invNumber === 'INV-202610-001') {
                // Khớp chính xác với Ảnh 2: Tổng trước thuế 2.227.273 đ, Thuế 222.727 đ, Tổng cộng 2.450.000 đ
                InvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'service_code' => 'MANAGEMENT_FEE',
                    'item_description' => 'Phí quản lý vận hành tòa nhà T10/2026',
                    'quantity' => 75,
                    'unit_name' => 'm²',
                    'unit_price' => 12000,
                    'amount_before_tax' => 900000,
                    'vat_percentage' => 10,
                    'vat_amount' => 90000,
                    'environmental_fee_amount' => 0,
                    'total_line_amount' => 990000,
                ]);

                InvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'service_code' => 'ELECTRICITY',
                    'item_description' => 'Điện sinh hoạt căn hộ kỳ T10/2026',
                    'quantity' => 180,
                    'unit_name' => 'kWh',
                    'unit_price' => 2500,
                    'amount_before_tax' => 450000,
                    'vat_percentage' => 10,
                    'vat_amount' => 45000,
                    'environmental_fee_amount' => 0,
                    'total_line_amount' => 495000,
                ]);

                InvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'service_code' => 'WATER',
                    'item_description' => 'Nước sinh hoạt căn hộ kỳ T10/2026',
                    'quantity' => 18,
                    'unit_name' => 'm³',
                    'unit_price' => 12500,
                    'amount_before_tax' => 225000,
                    'vat_percentage' => 10,
                    'vat_amount' => 22500,
                    'environmental_fee_amount' => 0,
                    'total_line_amount' => 247500,
                ]);

                InvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'service_code' => 'PARKING_FEE',
                    'item_description' => 'Phí trông giữ phương tiện (01 Ô tô 30A-888.88 & 01 Xe máy 29-X1 123.45)',
                    'quantity' => 1,
                    'unit_name' => 'tháng',
                    'unit_price' => 652273,
                    'amount_before_tax' => 652273,
                    'vat_percentage' => 10,
                    'vat_amount' => 65227,
                    'environmental_fee_amount' => 0,
                    'total_line_amount' => 717500,
                ]);
            } elseif ($invNumber === 'INV-202610-002') {
                // Hóa đơn 1.200.000 đ (Trước thuế: 1.090.909 đ, Thuế: 109.091 đ)
                InvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'service_code' => 'MANAGEMENT_FEE',
                    'item_description' => 'Phí quản lý vận hành tòa nhà T10/2026',
                    'quantity' => 75,
                    'unit_name' => 'm²',
                    'unit_price' => 10000,
                    'amount_before_tax' => 750000,
                    'vat_percentage' => 10,
                    'vat_amount' => 75000,
                    'environmental_fee_amount' => 0,
                    'total_line_amount' => 825000,
                ]);

                InvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'service_code' => 'WATER',
                    'item_description' => 'Nước sinh hoạt căn hộ kỳ T10/2026',
                    'quantity' => 12,
                    'unit_name' => 'm³',
                    'unit_price' => 12000,
                    'amount_before_tax' => 144000,
                    'vat_percentage' => 10,
                    'vat_amount' => 14400,
                    'environmental_fee_amount' => 0,
                    'total_line_amount' => 158400,
                ]);

                InvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'service_code' => 'ELECTRICITY',
                    'item_description' => 'Điện sinh hoạt căn hộ kỳ T10/2026',
                    'quantity' => 80,
                    'unit_name' => 'kWh',
                    'unit_price' => 2461.36,
                    'amount_before_tax' => 196909,
                    'vat_percentage' => 10,
                    'vat_amount' => 19691,
                    'environmental_fee_amount' => 0,
                    'total_line_amount' => 216600,
                ]);
            } elseif ($invNumber === 'INV-202610-003') {
                // Hóa đơn 3.850.000 đ (Trước thuế: 3.500.000 đ, Thuế: 350.000 đ)
                InvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'service_code' => 'MANAGEMENT_FEE',
                    'item_description' => 'Phí quản lý vận hành tòa nhà T10/2026',
                    'quantity' => 90,
                    'unit_name' => 'm²',
                    'unit_price' => 14000,
                    'amount_before_tax' => 1260000,
                    'vat_percentage' => 10,
                    'vat_amount' => 126000,
                    'environmental_fee_amount' => 0,
                    'total_line_amount' => 1386000,
                ]);

                InvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'service_code' => 'ELECTRICITY',
                    'item_description' => 'Điện sinh hoạt căn hộ kỳ T10/2026',
                    'quantity' => 280,
                    'unit_name' => 'kWh',
                    'unit_price' => 2800,
                    'amount_before_tax' => 784000,
                    'vat_percentage' => 10,
                    'vat_amount' => 78400,
                    'environmental_fee_amount' => 0,
                    'total_line_amount' => 862400,
                ]);

                InvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'service_code' => 'WATER',
                    'item_description' => 'Nước sinh hoạt căn hộ kỳ T10/2026',
                    'quantity' => 24,
                    'unit_name' => 'm³',
                    'unit_price' => 15000,
                    'amount_before_tax' => 360000,
                    'vat_percentage' => 10,
                    'vat_amount' => 36000,
                    'environmental_fee_amount' => 0,
                    'total_line_amount' => 396000,
                ]);

                InvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'service_code' => 'PARKING_FEE',
                    'item_description' => 'Phí trông giữ xe ô tô kỳ T10/2026 (Biển số: 30E-999.88)',
                    'quantity' => 1,
                    'unit_name' => 'tháng',
                    'unit_price' => 1096000,
                    'amount_before_tax' => 1096000,
                    'vat_percentage' => 10,
                    'vat_amount' => 109600,
                    'environmental_fee_amount' => 0,
                    'total_line_amount' => 1205600,
                ]);
            } else {
                // Các hóa đơn khác: sinh cân bằng theo đúng totalAmount
                $mgmtPreTax = round($totalAmount * 0.45 / 1.1);
                $elecPreTax = round($totalAmount * 0.35 / 1.1);
                $waterPreTax = round($totalAmount / 1.1) - $mgmtPreTax - $elecPreTax;

                InvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'service_code' => 'MANAGEMENT_FEE',
                    'item_description' => 'Phí quản lý vận hành tòa nhà T10/2026',
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
                    'item_description' => 'Điện sinh hoạt căn hộ kỳ T10/2026',
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
                    'item_description' => 'Nước sinh hoạt căn hộ kỳ T10/2026',
                    'quantity' => 15,
                    'unit_name' => 'm³',
                    'unit_price' => round($waterPreTax / 15),
                    'amount_before_tax' => $waterPreTax,
                    'vat_percentage' => 10,
                    'vat_amount' => $invoice->tax_amount - round($mgmtPreTax * 0.1) - round($elecPreTax * 0.1),
                    'environmental_fee_amount' => 0,
                    'total_line_amount' => $waterPreTax + ($invoice->tax_amount - round($mgmtPreTax * 0.1) - round($elecPreTax * 0.1)),
                ]);
            }
        }
    }
}

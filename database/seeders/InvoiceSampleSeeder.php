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

            // Add sample items
            InvoiceItem::updateOrCreate(
                ['invoice_id' => $invoice->id, 'service_code' => 'MANAGEMENT_FEE'],
                [
                    'item_description' => 'Phí quản lý vận hành tòa nhà T10/2026',
                    'quantity' => 80,
                    'unit_name' => 'm2',
                    'unit_price' => 12000,
                    'amount_before_tax' => 960000,
                    'vat_percentage' => 10,
                    'vat_amount' => 96000,
                    'environmental_fee_amount' => 0,
                    'total_line_amount' => 1056000,
                ]
            );

            InvoiceItem::updateOrCreate(
                ['invoice_id' => $invoice->id, 'service_code' => 'ELECTRICITY'],
                [
                    'item_description' => 'Điện sinh hoạt căn hộ',
                    'quantity' => 150,
                    'unit_name' => 'kWh',
                    'unit_price' => 2500,
                    'amount_before_tax' => 375000,
                    'vat_percentage' => 8,
                    'vat_amount' => 30000,
                    'environmental_fee_amount' => 0,
                    'total_line_amount' => 405000,
                ]
            );
        }
    }
}

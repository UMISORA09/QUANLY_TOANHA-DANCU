<?php

namespace Tests\Feature\Payments;

use App\Models\Apartment;
use App\Models\Block;
use App\Models\Floor;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentReceipt;
use App\Models\User;
use App\Services\PaymentCollectionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentCollectionTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;

    protected string $token;

    protected Block $blockA;

    protected Floor $floor1;

    protected Apartment $apartment;

    protected Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::factory()->create([
            'email' => 'treasurer@cassavas.com',
            'full_name' => 'Nguyễn Thị Thu Quỹ',
            'status' => 'ACTIVE',
        ]);

        $this->token = $this->issueTestBearerToken($this->adminUser);

        $this->blockA = Block::firstOrCreate(
            ['block_code' => 'T-A'],
            [
                'block_name' => 'Tòa Tháp A Sapphire',
                'total_floors' => 25,
                'total_apartments' => 100,
            ]
        );

        $this->floor1 = Floor::firstOrCreate(
            ['floor_code' => 'FL-A01'],
            [
                'block_id' => $this->blockA->id,
                'floor_number' => 1,
                'floor_name' => 'Tầng 1',
            ]
        );

        $this->apartment = Apartment::firstOrCreate(
            ['apartment_number' => 'A-101'],
            [
                'block_id' => $this->blockA->id,
                'floor_id' => $this->floor1->id,
                'net_usable_area_sqm' => 75.5,
                'gross_floor_area_sqm' => 80.0,
                'current_resident_user_id' => $this->adminUser->id,
                'status' => 'OCCUPIED',
            ]
        );

        $this->invoice = Invoice::firstOrCreate(
            ['invoice_number' => 'INV-202610-001'],
            [
                'apartment_id' => $this->apartment->id,
                'resident_user_id' => $this->adminUser->id,
                'billing_period' => '2026-10',
                'issue_date' => '2026-10-05',
                'due_date' => '2026-10-25',
                'subtotal_amount' => 1000000,
                'tax_amount' => 100000,
                'total_amount' => 1100000,
                'paid_amount' => 0,
                'remaining_balance' => 1100000,
                'status' => 'ISSUED',
            ]
        );
    }

    public function test_collect_payment_full_amount_updates_invoice_to_paid_and_creates_receipt(): void
    {
        $payload = [
            'invoice_id' => $this->invoice->id,
            'amount' => 1100000,
            'payment_method' => 'CASH',
            'notes' => 'Cư dân đóng tiền mặt tại quầy lễ tân',
            'idempotency_key' => 'IDEMP-TEST-FULL-01',
        ];

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
        ])->postJson('/api/v1/payments/collect', $payload);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'message' => 'Ghi nhận thanh toán và gạch nợ thành công',
            ]);

        $data = $response->json('data');
        $this->assertEquals('PAID', $data['invoice']['status']);
        $this->assertEquals(0, $data['invoice']['remaining_balance']);
        $this->assertEquals(1100000, $data['invoice']['paid_amount']);

        // Kiểm tra record thanh toán
        $this->assertDatabaseHas('payments', [
            'invoice_id' => $this->invoice->id,
            'amount_paid' => 1100000,
            'payment_gateway' => 'CASH',
            'payment_status' => 'SUCCESS',
            'idempotency_key' => 'IDEMP-TEST-FULL-01',
        ]);

        // Kiểm tra record biên lai
        $this->assertDatabaseHas('payment_receipts', [
            'payment_id' => $data['payment']['id'],
            'amount' => 1100000,
        ]);

        $receipt = PaymentReceipt::where('payment_id', $data['payment']['id'])->first();
        $this->assertNotNull($receipt);
        $this->assertStringContainsString('đồng chẵn', $receipt->amount_in_words);
        $this->assertNotEmpty($receipt->digital_signature_hash);
    }

    public function test_collect_payment_partial_amount_updates_invoice_to_partial(): void
    {
        $payload = [
            'invoice_id' => $this->invoice->id,
            'amount' => 400000,
            'payment_method' => 'BANK_TRANSFER',
            'transaction_id' => 'VCB99882211',
            'idempotency_key' => 'IDEMP-TEST-PARTIAL-01',
        ];

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
        ])->postJson('/api/v1/payments/collect', $payload);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
            ]);

        $data = $response->json('data');
        $this->assertEquals('PARTIAL', $data['invoice']['status']);
        $this->assertEquals(400000, $data['invoice']['paid_amount']);
        $this->assertEquals(700000, $data['invoice']['remaining_balance']);
    }

    public function test_idempotency_key_prevents_duplicate_payments(): void
    {
        $idempotencyKey = 'IDEMP-UNIQUE-LOCK-KEY-999';

        $payload = [
            'invoice_id' => $this->invoice->id,
            'amount' => 300000,
            'payment_method' => 'VIETQR',
            'idempotency_key' => $idempotencyKey,
        ];

        // Lần 1: Thành công
        $res1 = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
        ])->postJson('/api/v1/payments/collect', $payload);

        $res1->assertStatus(201);
        $payment1Id = $res1->json('data.payment.id');

        // Lần 2: Gửi lại cùng idempotency_key
        $res2 = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
        ])->postJson('/api/v1/payments/collect', $payload);

        $res2->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'is_duplicate' => true,
                ],
            ]);

        $this->assertEquals($payment1Id, $res2->json('data.payment.id'));

        // Kiểm tra Invoice chỉ bị trừ 300,000 một lần duy nhất
        $freshInvoice = $this->invoice->fresh();
        $this->assertEquals(300000, $freshInvoice->paid_amount);
        $this->assertEquals(800000, $freshInvoice->remaining_balance);
        $this->assertEquals(1, Payment::where('idempotency_key', $idempotencyKey)->count());
    }

    public function test_cannot_collect_payment_on_already_paid_or_cancelled_invoice(): void
    {
        // 1. Thử trên hóa đơn đã PAID
        $this->invoice->update([
            'status' => 'PAID',
            'paid_amount' => 1100000,
            'remaining_balance' => 0,
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
        ])->postJson('/api/v1/payments/collect', [
            'invoice_id' => $this->invoice->id,
            'amount' => 100000,
            'payment_method' => 'CASH',
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'Hóa đơn đã được thanh toán toàn bộ.',
            ]);

        // 2. Thử trên hóa đơn CANCELLED
        $this->invoice->update(['status' => 'CANCELLED']);

        $responseCancelled = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
        ])->postJson('/api/v1/payments/collect', [
            'invoice_id' => $this->invoice->id,
            'amount' => 100000,
            'payment_method' => 'CASH',
        ]);

        $responseCancelled->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'Hóa đơn này đã bị hủy bỏ, không thể thu tiền.',
            ]);
    }

    public function test_cannot_pay_more_than_remaining_balance(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
        ])->postJson('/api/v1/payments/collect', [
            'invoice_id' => $this->invoice->id,
            'amount' => 2000000, // Lớn hơn remaining_balance (1,100,000)
            'payment_method' => 'CASH',
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
            ]);

        $this->assertStringContainsString('không được lớn hơn dư nợ còn lại', $response->json('message'));
    }

    public function test_get_vietqr_payload_returns_valid_transfer_data(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
        ])->getJson("/api/v1/invoices/{$this->invoice->id}/vietqr-payload");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'bank_bin' => '970422',
                    'bank_account_number' => '0988889999',
                    'amount_due' => 1100000,
                ],
            ]);

        $data = $response->json('data');
        $this->assertNotEmpty($data['qr_image_url']);
        $this->assertStringContainsString('INV-202610-001', $data['transfer_content']);
    }

    public function test_list_payments_with_filter(): void
    {
        // Tạo 1 thanh toán thành công
        $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
        ])->postJson('/api/v1/payments/collect', [
            'invoice_id' => $this->invoice->id,
            'amount' => 500000,
            'payment_method' => 'CASH',
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
        ])->getJson('/api/v1/payments?payment_method=CASH');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);

        $data = $response->json('data');
        $this->assertCount(1, $data['data']);
        $this->assertEquals('CASH', $data['data'][0]['payment_gateway']);
    }

    public function test_concurrent_or_double_full_collection_rejects_second_attempt_and_does_not_overpay(): void
    {
        $invoice = Invoice::create([
            'invoice_number' => 'INV-202612-CONCURRENT',
            'apartment_id' => $this->apartment->id,
            'resident_user_id' => $this->adminUser->id,
            'billing_period' => '2026-12',
            'issue_date' => '2026-12-05',
            'due_date' => '2026-12-25',
            'subtotal_amount' => 1000000,
            'tax_amount' => 0,
            'total_amount' => 1000000,
            'paid_amount' => 0,
            'remaining_balance' => 1000000,
            'status' => 'ISSUED',
        ]);

        $service = app(PaymentCollectionService::class);

        // Lần 1: Thu toàn bộ thành công
        $res1 = $service->collectPayment([
            'invoice_id' => $invoice->id,
            'amount_paid' => 1000000,
            'payment_gateway' => 'CASH',
        ], (string) $this->adminUser->id);

        $this->assertEquals('PAID', $res1['invoice']->status);
        $this->assertEquals(1000000, $res1['invoice']->paid_amount);

        // Lần 2: Cùng gọi thu toàn bộ trên hóa đơn đó -> phải bị từ chối
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Hóa đơn đã được thanh toán toàn bộ.');

        $service->collectPayment([
            'invoice_id' => $invoice->id,
            'amount_paid' => 1000000,
            'payment_gateway' => 'CASH',
        ], (string) $this->adminUser->id);
    }

    public function test_race_condition_second_request_rejected_when_balance_reduced_under_lock(): void
    {
        $invoice = Invoice::create([
            'invoice_number' => 'INV-202701-RACE',
            'apartment_id' => $this->apartment->id,
            'resident_user_id' => $this->adminUser->id,
            'billing_period' => '2027-01',
            'issue_date' => '2027-01-05',
            'due_date' => '2027-01-25',
            'subtotal_amount' => 1000000,
            'tax_amount' => 0,
            'total_amount' => 1000000,
            'paid_amount' => 0,
            'remaining_balance' => 1000000,
            'status' => 'ISSUED',
        ]);

        $service = app(PaymentCollectionService::class);

        // Request 1 thu 700,000
        $service->collectPayment([
            'invoice_id' => $invoice->id,
            'amount_paid' => 700000,
            'payment_gateway' => 'CASH',
        ]);

        // Request 2 cố thu 500,000 (lớn hơn số dư còn lại 300,000 sau khi khóa)
        try {
            $service->collectPayment([
                'invoice_id' => $invoice->id,
                'amount_paid' => 500000,
                'payment_gateway' => 'CASH',
            ]);
            $this->fail('Expected DomainException was not thrown');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('không được lớn hơn dư nợ còn lại', $e->getMessage());
        }

        $fresh = $invoice->fresh();
        $this->assertEquals(700000, $fresh->paid_amount);
        $this->assertEquals(300000, $fresh->remaining_balance);
        $this->assertLessThanOrEqual($fresh->total_amount, $fresh->paid_amount);
    }

    public function test_collect_payment_with_pos_gateway_succeeds(): void
    {
        $payload = [
            'invoice_id' => $this->invoice->id,
            'amount' => 500000,
            'payment_method' => 'POS',
            'notes' => 'Quẹt thẻ qua máy POS tại quầy',
        ];

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
        ])->postJson('/api/v1/payments/collect', $payload);

        $response->assertSuccessful();

        $data = $response->json('data');
        $this->assertEquals('POS', $data['payment']['payment_gateway']);
        $this->assertEquals(500000, $data['invoice']['paid_amount']);

        $this->assertDatabaseHas('payments', [
            'invoice_id' => $this->invoice->id,
            'amount_paid' => 500000,
            'payment_gateway' => 'POS',
            'payment_status' => 'SUCCESS',
        ]);
    }

    public function test_sequential_receipt_numbers_are_unique_and_incrementing(): void
    {
        $service = app(PaymentCollectionService::class);

        $r1 = $service->collectPayment([
            'invoice_id' => $this->invoice->id,
            'amount_paid' => 100000,
            'payment_gateway' => 'CASH',
        ]);

        $r2 = $service->collectPayment([
            'invoice_id' => $this->invoice->id,
            'amount_paid' => 100000,
            'payment_gateway' => 'CASH',
        ]);

        $r3 = $service->collectPayment([
            'invoice_id' => $this->invoice->id,
            'amount_paid' => 100000,
            'payment_gateway' => 'CASH',
        ]);

        $num1 = $r1['receipt']->receipt_number;
        $num2 = $r2['receipt']->receipt_number;
        $num3 = $r3['receipt']->receipt_number;

        $prefix = 'BL-'.Carbon::now()->format('Ym').'-';
        $this->assertStringStartsWith($prefix, $num1);
        $this->assertStringStartsWith($prefix, $num2);
        $this->assertStringStartsWith($prefix, $num3);

        $seq1 = (int) substr($num1, -5);
        $seq2 = (int) substr($num2, -5);
        $seq3 = (int) substr($num3, -5);

        $this->assertEquals($seq1 + 1, $seq2);
        $this->assertEquals($seq2 + 1, $seq3);
        $this->assertNotEquals($num1, $num2);
        $this->assertNotEquals($num2, $num3);
    }

    public function test_idempotency_key_is_scoped_to_invoice(): void
    {
        $sharedKey = 'IDEMP-SHARED-KEY-123';

        // Payment 1 cho invoice 1
        $res1 = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
        ])->postJson('/api/v1/payments/collect', [
            'invoice_id' => $this->invoice->id,
            'amount' => 200000,
            'payment_method' => 'CASH',
            'idempotency_key' => $sharedKey,
        ]);
        $res1->assertStatus(201);
        $payment1Id = $res1->json('data.payment.id');

        // Gửi lại cho invoice 1 cùng key -> trả về đúng payment lần 1, không tạo mới
        $res1Retry = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
        ])->postJson('/api/v1/payments/collect', [
            'invoice_id' => $this->invoice->id,
            'amount' => 200000,
            'payment_method' => 'CASH',
            'idempotency_key' => $sharedKey,
        ]);
        $res1Retry->assertStatus(200);
        $this->assertTrue($res1Retry->json('data.is_duplicate'));
        $this->assertEquals($payment1Id, $res1Retry->json('data.payment.id'));
        $this->assertEquals(1, Payment::where('invoice_id', $this->invoice->id)->where('idempotency_key', $sharedKey)->count());

        // Tạo hóa đơn thứ 2 cho kỳ khác (hoặc căn hộ khác)
        $invoice2 = Invoice::create([
            'invoice_number' => 'INV-202611-002',
            'apartment_id' => $this->apartment->id,
            'resident_user_id' => $this->adminUser->id,
            'billing_period' => '2026-11',
            'issue_date' => '2026-11-05',
            'due_date' => '2026-11-25',
            'subtotal_amount' => 500000,
            'tax_amount' => 0,
            'total_amount' => 500000,
            'paid_amount' => 0,
            'remaining_balance' => 500000,
            'status' => 'ISSUED',
        ]);

        // Gửi cho invoice 2 dùng cùng key -> không bị nhận nhầm là trùng với invoice 1
        $res2 = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
        ])->postJson('/api/v1/payments/collect', [
            'invoice_id' => $invoice2->id,
            'amount' => 250000,
            'payment_method' => 'CASH',
            'idempotency_key' => $sharedKey,
        ]);
        $res2->assertStatus(201);
        $this->assertFalse($res2->json('data.is_duplicate') ?? false);
        $this->assertNotEquals($payment1Id, $res2->json('data.payment.id'));
        $this->assertEquals($invoice2->id, $res2->json('data.payment.invoice_id'));
    }
}

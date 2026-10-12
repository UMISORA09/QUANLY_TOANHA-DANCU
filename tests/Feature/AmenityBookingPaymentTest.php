<?php

namespace Tests\Feature;

use App\Services\AmenityBookingPaymentService;
use App\Services\ResidentAmenityBookingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\ResidentAmenityBookingFixtures;
use Tests\TestCase;

class AmenityBookingPaymentTest extends TestCase
{
    use DatabaseTransactions, ResidentAmenityBookingFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        $this->createBookingFixture();
        DB::table('amenities')->where('id', $this->amenityId)->update(['requires_admin_approval' => 0]);
    }

    private function createPaidAmenityBooking(): string
    {
        $this->withHeader('Authorization', 'Bearer '.$this->residentToken);

        return $this->postJson('/api/v1/resident/amenity-bookings', $this->bookingPayload())->assertCreated()->json('booking.id');
    }

    private function configureCheckout(): void
    {
        config(['amenity_payments.checkout_environment' => 'sandbox', 'amenity_payments.checkout_merchant_id' => 'SP-TEST-QA',
            'amenity_payments.checkout_secret' => 'qa-checkout-secret', 'amenity_payments.checkout_ipn_secret' => 'qa-ipn-secret']);
    }

    public function test_booking_failure_after_payment_insert_rolls_back_all_writes(): void
    {
        $payments = app(AmenityBookingPaymentService::class);
        $bookingId = null;
        $this->partialMock(AmenityBookingPaymentService::class, function ($mock) use ($payments, &$bookingId): void {
            $mock->shouldReceive('initialize')->once()->andReturnUsing(function (array $booking) use ($payments, &$bookingId): void {
                $bookingId = $booking['id'];
                $payments->initialize($booking);
                throw new \RuntimeException('QA failure after payment insert');
            });
        });
        $auditCount = DB::table('audit_logs')->count();
        $noticeCount = DB::table('user_in_app_notifications')->count();
        $this->withoutExceptionHandling();
        try {
            $this->withHeader('Authorization', 'Bearer '.$this->residentToken)
                ->postJson('/api/v1/resident/amenity-bookings', $this->bookingPayload());
            $this->fail('Expected injected failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('QA failure after payment insert', $exception->getMessage());
        }
        $this->assertDatabaseMissing('amenity_bookings', ['amenity_id' => $this->amenityId]);
        $this->assertNotNull($bookingId);
        $this->assertDatabaseMissing('amenity_booking_payments', ['booking_id' => $bookingId]);
        $this->assertSame($auditCount, DB::table('audit_logs')->count());
        $this->assertSame($noticeCount, DB::table('user_in_app_notifications')->count());
        $this->getJson($this->availabilityUrl())->assertOk()->assertJsonPath('slots.0.remaining_attendees', 4);
    }

    public function test_webhook_failure_after_credit_rolls_back_and_retry_credits_once(): void
    {
        config(['amenity_payments.sepay_webhook_secret' => 'qa-secret']);
        $id = $this->createPaidAmenityBooking();
        $payload = $this->webhookPayload($id);
        $service = app(ResidentAmenityBookingService::class);
        $this->partialMock(ResidentAmenityBookingService::class, function ($mock): void {
            $mock->shouldReceive('notifyManagers')->once()->andThrow(new \RuntimeException('QA failure after credit'));
        });
        $auditCount = DB::table('audit_logs')->count();
        $noticeCount = DB::table('user_in_app_notifications')->count();
        $this->withoutExceptionHandling();
        try {
            $this->sendWebhook($payload);
            $this->fail('Expected injected failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('QA failure after credit', $exception->getMessage());
        }
        $this->assertDatabaseHas('amenity_bookings', ['id' => $id, 'is_paid' => 0]);
        $this->assertDatabaseHas('amenity_booking_payments', ['booking_id' => $id, 'status' => 'PENDING', 'bank_transaction_id' => null]);
        $this->assertSame($auditCount, DB::table('audit_logs')->count());
        $this->assertSame($noticeCount, DB::table('user_in_app_notifications')->count());
        $this->app->instance(ResidentAmenityBookingService::class, $service);
        $this->sendWebhook($payload)->assertOk();
        $this->assertDatabaseHas('amenity_booking_payments', ['booking_id' => $id, 'status' => 'PAID', 'received_amount' => 200000]);
        $auditCount = DB::table('audit_logs')->count();
        $this->sendWebhook($payload)->assertOk();
        $this->assertSame($auditCount, DB::table('audit_logs')->count());
    }

    /** @return array<string, mixed> */
    private function ipnPayload(string $invoice): array
    {
        return ['timestamp' => now()->timestamp, 'notification_type' => 'ORDER_PAID',
            'order' => ['id' => 'order-qa', 'order_invoice_number' => $invoice, 'order_status' => 'CAPTURED', 'order_currency' => 'VND', 'order_amount' => '200000.00'],
            'transaction' => ['id' => 'transaction-qa', 'transaction_id' => 'bank-qa', 'payment_method' => 'BANK_TRANSFER', 'transaction_type' => 'PAYMENT',
                'transaction_status' => 'APPROVED', 'transaction_currency' => 'VND', 'transaction_amount' => '200000', 'transaction_date' => now()->format('Y-m-d H:i:s')]];
    }

    public function test_checkout_uses_server_invoice_and_amount_and_never_exposes_secret(): void
    {
        $this->configureCheckout();
        $id = $this->createPaidAmenityBooking();
        $url = $this->paymentUrl($id).'/checkout';
        $response = $this->postJson($url, ['amount' => 1, 'merchant' => 'FAKE', 'payment_method' => 'CARD'])->assertOk()
            ->assertJsonPath('action', 'https://pay-sandbox.sepay.vn/v1/checkout/init')
            ->assertJsonPath('fields.order_amount', '200000')->assertJsonPath('fields.merchant', 'SP-TEST-QA')
            ->assertJsonPath('fields.payment_method', 'BANK_TRANSFER');
        $fields = $response->json('fields');
        $invoice = 'SBX-'.DB::table('amenity_booking_payments')->where('booking_id', $id)->value('reference');
        $returnUrl = url('/cu-dan').'?tab=amenities&booking_id='.$id;
        $code = DB::table('amenity_bookings')->where('id', $id)->value('booking_code');
        $signed = 'merchant=SP-TEST-QA,currency=VND,order_amount=200000,operation=PURCHASE,order_description=Thanh toan tien ich '.$code.',payment_method=BANK_TRANSFER,order_invoice_number='.$invoice.',success_url='.$returnUrl.',error_url='.$returnUrl.',cancel_url='.$returnUrl;
        $this->assertSame(base64_encode(hash_hmac('sha256', $signed, 'qa-checkout-secret', true)), $fields['signature']);
        $this->assertStringNotContainsString('qa-checkout-secret', $response->getContent());
        $this->assertSame($fields, $this->postJson($url)->assertOk()->json('fields'));
        $this->assertSame(1, DB::table('audit_logs')->where('table_name', 'amenity_payment_checkouts')->count());
        $this->assertDatabaseHas('amenity_bookings', ['id' => $id, 'is_paid' => 0]);
        $this->getJson('/cu-dan?tab=amenities&booking_id='.$id.'&payment=success')->assertOk();
        $this->assertDatabaseHas('amenity_bookings', ['id' => $id, 'is_paid' => 0]);
    }

    public function test_checkout_checks_ownership_configuration_approval_and_expiry(): void
    {
        $id = $this->createPaidAmenityBooking();
        config(['amenity_payments.checkout_secret' => null]);
        $url = $this->paymentUrl($id).'/checkout';
        $this->postJson($url)->assertStatus(503);
        $this->configureCheckout();
        $this->withHeader('Authorization', 'Bearer '.$this->secondToken)->postJson($url)->assertNotFound();
        $this->withHeader('Authorization', 'Bearer invalid')->postJson($url)->assertUnauthorized();
        $this->withHeader('Authorization', 'Bearer '.$this->residentToken);
        DB::table('amenity_booking_payments')->where('booking_id', $id)->update(['status' => 'WAITING_APPROVAL']);
        $this->postJson($url)->assertConflict();
        DB::table('amenity_booking_payments')->where('booking_id', $id)->update(['status' => 'PENDING', 'expires_at' => now()->subMinute()]);
        $this->postJson($url)->assertConflict();
    }

    public function test_ipn_requires_its_own_secret_and_updates_only_issued_checkout_once(): void
    {
        $this->configureCheckout();
        $id = $this->createPaidAmenityBooking();
        $invoice = $this->postJson($this->paymentUrl($id).'/checkout')->assertOk()->json('fields.order_invoice_number');
        $payload = $this->ipnPayload($invoice);
        $url = '/api/v1/amenity-payments/sepay/ipn';
        $this->withHeader('X-Secret-Key', 'qa-checkout-secret')->postJson($url, $payload)->assertUnauthorized();
        $this->withHeader('X-Secret-Key', 'qa-ipn-secret')->postJson($url, $payload)->assertOk();
        $this->assertDatabaseHas('amenity_bookings', ['id' => $id, 'is_paid' => 1]);
        $this->assertDatabaseHas('amenity_booking_payments', ['booking_id' => $id, 'status' => 'PAID', 'bank_transaction_id' => 'BANK-QA', 'confirmed_by_user_id' => null]);
        $count = DB::table('audit_logs')->count();
        $this->postJson($url, $payload)->assertOk();
        $this->assertSame($count, DB::table('audit_logs')->count());
    }

    public function test_ipn_cannot_match_checkout_from_another_environment_or_unknown_invoice(): void
    {
        $this->configureCheckout();
        $id = $this->createPaidAmenityBooking();
        $invoice = $this->postJson($this->paymentUrl($id).'/checkout')->assertOk()->json('fields.order_invoice_number');
        config(['amenity_payments.checkout_environment' => 'production']);
        $this->withHeader('X-Secret-Key', 'qa-ipn-secret')->postJson('/api/v1/amenity-payments/sepay/ipn', $this->ipnPayload($invoice))->assertOk();
        $this->assertDatabaseHas('amenity_bookings', ['id' => $id, 'is_paid' => 0]);
    }

    public function test_void_before_paid_never_credits_or_restores_booking(): void
    {
        $this->assertVoidedCheckout(true);
    }

    public function test_void_after_paid_removes_paid_flag_and_preserves_receipt_history(): void
    {
        $id = $this->assertVoidedCheckout(false);
        $this->assertDatabaseHas('amenity_booking_payments', ['booking_id' => $id, 'received_amount' => 200000, 'bank_transaction_id' => 'BANK-QA']);
    }

    private function assertVoidedCheckout(bool $voidFirst): string
    {
        $this->configureCheckout();
        $id = $this->createPaidAmenityBooking();
        $invoice = $this->postJson($this->paymentUrl($id).'/checkout')->assertOk()->json('fields.order_invoice_number');
        $paid = $this->ipnPayload($invoice);
        $void = $paid;
        $void['notification_type'] = 'TRANSACTION_VOID';
        $void['transaction']['transaction_status'] = 'VOID';
        $void['transaction']['transaction_amount'] = '0';
        $this->withHeader('X-Secret-Key', 'qa-ipn-secret');
        foreach ($voidFirst ? [$void, $paid] : [$paid, $void] as $payload) {
            $this->postJson('/api/v1/amenity-payments/sepay/ipn', $payload)->assertOk();
        }
        $count = DB::table('audit_logs')->count();
        $this->postJson('/api/v1/amenity-payments/sepay/ipn', $void)->assertOk();
        $this->assertSame($count, DB::table('audit_logs')->count());
        $this->assertDatabaseHas('amenity_bookings', ['id' => $id, 'is_paid' => 0, 'status' => 'CANCELLED']);
        $this->assertDatabaseHas('amenity_booking_payments', ['booking_id' => $id, 'status' => 'REVIEW']);

        return $id;
    }

    public function test_void_for_another_transaction_does_not_revoke_valid_manual_receipt(): void
    {
        $this->configureCheckout();
        $id = $this->createPaidAmenityBooking();
        $invoice = $this->postJson($this->paymentUrl($id).'/checkout')->assertOk()->json('fields.order_invoice_number');
        $this->withHeader('Authorization', 'Bearer '.$this->adminToken)->postJson($this->confirmUrl($id), $this->receipt())->assertOk();
        $void = $this->ipnPayload($invoice);
        $void['notification_type'] = 'TRANSACTION_VOID';
        $this->withHeader('X-Secret-Key', 'qa-ipn-secret')->postJson('/api/v1/amenity-payments/sepay/ipn', $void)->assertOk();
        $this->assertDatabaseHas('amenity_bookings', ['id' => $id, 'is_paid' => 1]);
        $this->assertDatabaseHas('amenity_booking_payments', ['booking_id' => $id, 'status' => 'PAID', 'bank_transaction_id' => 'VCB-TEST-001']);
    }

    public function test_gateway_receipt_cannot_be_reused_manually_including_legacy_prefix(): void
    {
        $this->configureCheckout();
        $id = $this->createPaidAmenityBooking();
        $invoice = $this->postJson($this->paymentUrl($id).'/checkout')->assertOk()->json('fields.order_invoice_number');
        $this->withHeader('X-Secret-Key', 'qa-ipn-secret')->postJson('/api/v1/amenity-payments/sepay/ipn', $this->ipnPayload($invoice))->assertOk();
        $second = $this->withHeader('Authorization', 'Bearer '.$this->secondToken)->postJson('/api/v1/resident/amenity-bookings', $this->bookingPayload())->assertCreated()->json('booking.id');
        $this->withHeader('Authorization', 'Bearer '.$this->adminToken);
        foreach (['bank-qa', 'PG-BANK-QA'] as $reference) {
            $this->postJson($this->confirmUrl($second), $this->receipt($reference))->assertUnprocessable()->assertJsonValidationErrors('bank_transaction_id');
        }
        DB::table('amenity_booking_payments')->where('booking_id', $id)->update(['bank_transaction_id' => 'PG-BANK-QA']);
        $this->postJson($this->confirmUrl($second), $this->receipt('bank-qa'))->assertUnprocessable()->assertJsonValidationErrors('bank_transaction_id');
        $this->assertDatabaseHas('amenity_bookings', ['id' => $second, 'is_paid' => 0]);
    }

    public function test_manual_receipt_cannot_be_reused_by_gateway_ipn(): void
    {
        $this->configureCheckout();
        $first = $this->createPaidAmenityBooking();
        $this->withHeader('Authorization', 'Bearer '.$this->adminToken)->postJson($this->confirmUrl($first), $this->receipt('bank-qa'))->assertOk();
        $this->withHeader('Authorization', 'Bearer '.$this->secondToken);
        $second = $this->postJson('/api/v1/resident/amenity-bookings', $this->bookingPayload())->assertCreated()->json('booking.id');
        $invoice = $this->postJson($this->paymentUrl($second).'/checkout')->assertOk()->json('fields.order_invoice_number');
        $this->withHeader('X-Secret-Key', 'qa-ipn-secret')->postJson('/api/v1/amenity-payments/sepay/ipn', $this->ipnPayload($invoice))->assertOk();
        $this->assertDatabaseHas('amenity_bookings', ['id' => $second, 'is_paid' => 0]);
        $this->assertDatabaseHas('amenity_booking_payments', ['booking_id' => $second, 'bank_transaction_id' => null]);
    }

    public function test_ipn_mismatched_money_requires_review(): void
    {
        $this->configureCheckout();
        $id = $this->createPaidAmenityBooking();
        $invoice = $this->postJson($this->paymentUrl($id).'/checkout')->assertOk()->json('fields.order_invoice_number');
        $payload = $this->ipnPayload($invoice);
        $payload['transaction']['transaction_amount'] = '100000';
        $this->withHeader('X-Secret-Key', 'qa-ipn-secret')->postJson('/api/v1/amenity-payments/sepay/ipn', $payload)->assertOk();
        $this->assertDatabaseHas('amenity_booking_payments', ['booking_id' => $id, 'status' => 'REVIEW', 'received_amount' => 100000, 'refund_required' => 1]);
        $this->assertDatabaseHas('amenity_bookings', ['id' => $id, 'is_paid' => 0, 'status' => 'CANCELLED']);
    }

    public function test_late_checkout_ipn_does_not_restore_booking(): void
    {
        $this->configureCheckout();
        $id = $this->createPaidAmenityBooking();
        $invoice = $this->postJson($this->paymentUrl($id).'/checkout')->assertOk()->json('fields.order_invoice_number');
        $this->travel(16)->minutes();
        $this->withHeader('X-Secret-Key', 'qa-ipn-secret')->postJson('/api/v1/amenity-payments/sepay/ipn', $this->ipnPayload($invoice))->assertOk();
        $this->assertDatabaseHas('amenity_bookings', ['id' => $id, 'is_paid' => 0, 'status' => 'CANCELLED']);
        $this->assertDatabaseHas('amenity_booking_payments', ['booking_id' => $id, 'status' => 'REVIEW', 'refund_required' => 1]);
    }

    public function test_sandbox_checkout_is_disabled_in_production_application(): void
    {
        $this->configureCheckout();
        $id = $this->createPaidAmenityBooking();
        $this->app['env'] = 'production';
        $this->postJson($this->paymentUrl($id).'/checkout')->assertStatus(503);
        $this->withHeader('X-Secret-Key', 'qa-ipn-secret')->postJson('/api/v1/amenity-payments/sepay/ipn', $this->ipnPayload('SBX-FAKE'))->assertStatus(503);
    }

    /** @return array<string, mixed> */
    private function webhookPayload(string $bookingId): array
    {
        return ['id' => 92704, 'gateway' => 'Vietcombank', 'accountNumber' => '1037900935',
            'transactionDate' => now()->format('Y-m-d H:i:s'), 'transferType' => 'in', 'transferAmount' => 200000,
            'content' => 'Thanh toan '.DB::table('amenity_booking_payments')->where('booking_id', $bookingId)->value('reference'),
            'referenceCode' => 'VCB-AUTO-001'];
    }

    /** @param array<string, mixed> $payload */
    private function sendWebhook(array $payload, ?int $timestamp = null, string $signingSecret = 'qa-secret'): TestResponse
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp ??= now()->timestamp;

        return $this->call('POST', '/api/v1/amenity-payments/sepay/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_SEPAY_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_SEPAY_SIGNATURE' => 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $signingSecret),
        ], $body);
    }

    public function test_webhook_requires_configuration_valid_signature_and_fresh_timestamp(): void
    {
        $payload = $this->webhookPayload($this->createPaidAmenityBooking());
        config(['amenity_payments.sepay_webhook_secret' => null]);
        $this->sendWebhook($payload)->assertStatus(503);
        config(['amenity_payments.sepay_webhook_secret' => 'qa-secret']);
        $this->sendWebhook($payload, signingSecret: 'wrong')->assertUnauthorized();
        $this->sendWebhook($payload, now()->timestamp - 301)->assertUnauthorized();
        $this->assertDatabaseCount('amenity_booking_payments', 1);
        $this->assertSame(0, DB::table('audit_logs')->where('table_name', 'amenity_payment_webhooks')->count());
    }

    public function test_signed_dashboard_webhook_test_never_credits_booking(): void
    {
        config(['amenity_payments.sepay_webhook_secret' => 'qa-secret']);
        $id = $this->createPaidAmenityBooking();
        foreach ([0, '0'] as $testId) {
            $this->sendWebhook(array_replace($this->webhookPayload($id), ['id' => $testId]))->assertOk()->assertJsonPath('success', true);
        }
        $this->assertDatabaseHas('amenity_booking_payments', ['booking_id' => $id, 'status' => 'PENDING', 'bank_transaction_id' => null]);
        $this->assertSame(0, DB::table('audit_logs')->where('table_name', 'amenity_payment_webhooks')->count());
    }

    public function test_webhook_marks_paid_without_resident_report_and_replay_has_no_side_effects(): void
    {
        config(['amenity_payments.sepay_webhook_secret' => 'qa-secret']);
        $id = $this->createPaidAmenityBooking();
        $payload = $this->webhookPayload($id);
        $this->sendWebhook($payload)->assertOk()->assertExactJson(['success' => true]);
        $this->assertDatabaseHas('amenity_bookings', ['id' => $id, 'is_paid' => 1, 'status' => 'APPROVED']);
        $this->assertDatabaseHas('amenity_booking_payments', ['booking_id' => $id, 'status' => 'PAID', 'confirmed_by_user_id' => null, 'received_amount' => 200000]);
        $auditCount = DB::table('audit_logs')->count();
        $noticeCount = DB::table('user_in_app_notifications')->count();
        $this->sendWebhook($payload)->assertOk();
        $this->assertSame($auditCount, DB::table('audit_logs')->count());
        $this->assertSame($noticeCount, DB::table('user_in_app_notifications')->count());
        $payload['id']++;
        $this->sendWebhook($payload)->assertOk();
        $this->assertSame($noticeCount, DB::table('user_in_app_notifications')->count());
    }

    public function test_webhook_ignores_outgoing_wrong_receiver_and_unmatched_reference_but_keeps_audit(): void
    {
        config(['amenity_payments.sepay_webhook_secret' => 'qa-secret']);
        $id = $this->createPaidAmenityBooking();
        foreach ([['transferType' => 'out'], ['accountNumber' => 'wrong'], ['gateway' => 'BIDV'], ['content' => 'Khong co ma']] as $index => $change) {
            $payload = array_replace($this->webhookPayload($id), $change, ['id' => 100 + $index]);
            $this->sendWebhook($payload)->assertOk();
        }
        $this->assertDatabaseHas('amenity_bookings', ['id' => $id, 'is_paid' => 0]);
        $this->assertSame(4, DB::table('audit_logs')->where('table_name', 'amenity_payment_webhooks')->count());
    }

    public function test_wrong_amount_webhook_records_actual_money_for_review_without_marking_paid(): void
    {
        config(['amenity_payments.sepay_webhook_secret' => 'qa-secret']);
        $id = $this->createPaidAmenityBooking();
        $this->sendWebhook(array_replace($this->webhookPayload($id), ['transferAmount' => 100000]))->assertOk();
        $this->assertDatabaseHas('amenity_booking_payments', ['booking_id' => $id, 'status' => 'REVIEW', 'received_amount' => 100000, 'refund_required' => 1]);
        $this->assertDatabaseHas('amenity_bookings', ['id' => $id, 'is_paid' => 0, 'status' => 'CANCELLED']);
    }

    public function test_late_webhook_does_not_restore_expired_booking(): void
    {
        config(['amenity_payments.sepay_webhook_secret' => 'qa-secret']);
        $id = $this->createPaidAmenityBooking();
        $this->travel(16)->minutes();
        $this->sendWebhook($this->webhookPayload($id))->assertOk();
        $this->assertDatabaseHas('amenity_bookings', ['id' => $id, 'status' => 'CANCELLED', 'is_paid' => 0]);
        $this->assertDatabaseHas('amenity_booking_payments', ['booking_id' => $id, 'status' => 'REVIEW', 'refund_required' => 1]);
    }

    public function test_payment_before_approval_is_recorded_for_review(): void
    {
        config(['amenity_payments.sepay_webhook_secret' => 'qa-secret']);
        DB::table('amenities')->where('id', $this->amenityId)->update(['requires_admin_approval' => 1]);
        $id = $this->createPaidAmenityBooking();
        $this->sendWebhook($this->webhookPayload($id))->assertOk();
        $this->assertDatabaseHas('amenity_booking_payments', ['booking_id' => $id, 'status' => 'REVIEW', 'refund_required' => 1]);
        $this->assertDatabaseHas('amenity_bookings', ['id' => $id, 'is_paid' => 0, 'status' => 'CANCELLED']);
    }

    public function test_additional_transfer_preserves_first_receipt_and_notifies_managers(): void
    {
        config(['amenity_payments.sepay_webhook_secret' => 'qa-secret']);
        $id = $this->createPaidAmenityBooking();
        $payload = $this->webhookPayload($id);
        $this->sendWebhook($payload)->assertOk();
        $this->sendWebhook(array_replace($payload, ['id' => 92705, 'referenceCode' => 'VCB-EXTRA']))->assertOk();
        $this->assertDatabaseHas('amenity_booking_payments', ['booking_id' => $id, 'status' => 'PAID', 'bank_transaction_id' => 'VCB-AUTO-001']);
        $this->assertTrue(DB::table('user_in_app_notifications')->where('title', 'like', 'Có thêm giao dịch%')->exists());
    }

    public function test_webhook_rejects_fractional_amount_and_future_transaction(): void
    {
        config(['amenity_payments.sepay_webhook_secret' => 'qa-secret']);
        $payload = $this->webhookPayload($this->createPaidAmenityBooking());
        $this->sendWebhook(array_replace($payload, ['transferAmount' => 1.5]))->assertUnprocessable();
        $this->sendWebhook(array_replace($payload, ['transactionDate' => now()->addMinute()->format('Y-m-d H:i:s')]))->assertUnprocessable();
    }

    public function test_webhook_does_not_assign_one_bank_transaction_to_two_bookings(): void
    {
        config(['amenity_payments.sepay_webhook_secret' => 'qa-secret']);
        $first = $this->createPaidAmenityBooking();
        $this->sendWebhook($this->webhookPayload($first))->assertOk();
        $this->withHeader('Authorization', 'Bearer '.$this->secondToken);
        $second = $this->postJson('/api/v1/resident/amenity-bookings', $this->bookingPayload())->assertCreated()->json('booking.id');
        $this->sendWebhook(array_replace($this->webhookPayload($second), ['id' => 92705]))->assertOk();
        $this->assertDatabaseHas('amenity_bookings', ['id' => $second, 'is_paid' => 0]);
        $this->assertDatabaseHas('amenity_booking_payments', ['booking_id' => $second, 'bank_transaction_id' => null]);
        $this->assertTrue(DB::table('user_in_app_notifications')->where('title', 'Giao dịch trùng cần đối soát')->exists());
        $this->assertSame(2, DB::table('audit_logs')->where('table_name', 'amenity_payment_webhooks')->count());
    }

    public function test_reconciliation_preserves_rejected_booking_history(): void
    {
        DB::table('amenities')->where('id', $this->amenityId)->update(['requires_admin_approval' => 1]);
        $id = $this->createPaidAmenityBooking();
        $this->withHeader('Authorization', 'Bearer '.$this->adminToken);
        $this->patchJson('/api/v1/admin/amenities/'.$this->amenityId.'/bookings/'.$id.'/status', ['status' => 'REJECTED', 'rejection_reason' => 'Không đáp ứng nội quy'])->assertOk();
        $before = (array) DB::table('amenity_bookings')->where('id', $id)->first();
        $paymentId = DB::table('amenity_booking_payments')->where('booking_id', $id)->value('id');
        $auditCount = DB::table('audit_logs')->where('table_name', 'amenity_booking_payments')->where('record_id', $paymentId)->count();
        $this->travel(1)->minutes();
        $url = str_replace('/confirm', '/reject', $this->confirmUrl($id));
        $payload = ['reason' => 'Không tìm thấy giao dịch'];
        $this->postJson($url, $payload)->assertOk()->assertJsonPath('payment.status', 'REVIEW')->assertJsonPath('payment.review_reason', $payload['reason']);
        $this->postJson($url, $payload)->assertOk();
        $this->assertSame($before, (array) DB::table('amenity_bookings')->where('id', $id)->first());
        $this->assertSame($auditCount + 1, DB::table('audit_logs')->where('table_name', 'amenity_booking_payments')->where('record_id', $paymentId)->count());
    }

    public function test_reconciliation_preserves_cancelled_and_completed_legacy_booking_history(): void
    {
        $id = $this->createPaidAmenityBooking();
        $this->withHeader('Authorization', 'Bearer '.$this->adminToken);
        foreach (['CANCELLED', 'COMPLETED'] as $status) {
            DB::table('amenity_bookings')->where('id', $id)->update(['status' => $status]);
            $before = (array) DB::table('amenity_bookings')->where('id', $id)->first();
            $this->travel(1)->minutes();
            $this->postJson(str_replace('/confirm', '/reject', $this->confirmUrl($id)), ['reason' => 'Đối soát lịch sử '.$status])->assertOk()->assertJsonPath('payment.status', 'REVIEW');
            $this->assertSame($before, (array) DB::table('amenity_bookings')->where('id', $id)->first());
        }
    }

    public function test_deletion_preserves_access_to_unresolved_payments_and_refunds(): void
    {
        $id = $this->createPaidAmenityBooking();
        $this->postJson($this->paymentUrl($id).'/report')->assertOk();
        $this->withHeader('Authorization', 'Bearer '.$this->adminToken);
        $this->postJson('/api/v1/admin/amenities/'.$this->amenityId.'/bookings/'.$id.'/cancel', ['reason' => 'Đóng cửa'])->assertOk();
        $url = '/api/v1/admin/amenities/'.$this->amenityId;
        $this->deleteJson($url)->assertConflict();
        $this->assertDatabaseHas('amenities', ['id' => $this->amenityId, 'deleted_at' => null]);
        $this->assertSame(0, DB::table('audit_logs')->where('record_id', $this->amenityId)->where('action', 'DELETE')->count());
        $this->getJson('/api/v1/admin/amenity-booking-worklist?bucket=REVIEW')->assertOk()->assertJsonPath('counts.REVIEW', 1);
        $this->postJson($this->confirmUrl($id), $this->receipt())->assertOk()->assertJsonPath('payment.refund_required', true);
        $this->deleteJson($url)->assertConflict();
        $this->getJson($url.'/bookings')->assertOk()->assertJsonPath('0.payment.bank_transaction_id', 'VCB-TEST-001');
    }

    private function paymentUrl(string $id): string
    {
        return '/api/v1/resident/amenity-bookings/'.$id.'/payment';
    }

    private function confirmUrl(string $id): string
    {
        return '/api/v1/admin/amenities/'.$this->amenityId.'/bookings/'.$id.'/payment/confirm';
    }

    /** @return array<string, mixed> */
    private function receipt(string $reference = 'VCB-TEST-001'): array
    {
        return ['bank_transaction_id' => $reference, 'received_amount' => 200000, 'received_at' => now()->toIso8601String()];
    }

    public function test_qr_uses_server_amount_bank_snapshot_and_is_idempotent(): void
    {
        $id = $this->createPaidAmenityBooking();
        $first = $this->postJson($this->paymentUrl($id), ['amount' => 1, 'account_number' => 'fake'])->assertOk()
            ->assertJsonPath('payment.amount', 200000)->assertJsonPath('payment.account_number', '1037900935')->assertJsonPath('payment.status', 'PENDING')->json('payment');
        $this->assertStringContainsString('970436-1037900935', $first['qr_url']);
        $this->assertStringContainsString('amount=200000', $first['qr_url']);
        config(['amenity_payments.account_number' => 'different']);
        $this->getJson($this->paymentUrl($id))->assertJsonPath('payment.id', $first['id'])->assertJsonPath('payment.account_number', '1037900935');
        $this->assertSame(1, DB::table('amenity_booking_payments')->where('booking_id', $id)->count());
    }

    public function test_report_does_not_mark_paid_or_extend_deadline_repeatedly(): void
    {
        $id = $this->createPaidAmenityBooking();
        $response = $this->postJson($this->paymentUrl($id).'/report')->assertOk()->assertJsonPath('payment.status', 'REPORTED');
        $expiry = $response->json('payment.expires_at');
        $this->travel(5)->minutes();
        $this->postJson($this->paymentUrl($id).'/report')->assertOk()->assertJsonPath('payment.expires_at', $expiry);
        $this->assertDatabaseHas('amenity_bookings', ['id' => $id, 'is_paid' => 0]);
    }

    public function test_fractional_vnd_is_rejected_instead_of_silently_changing_the_charge(): void
    {
        $timestamp = DB::table('amenities')->where('id', $this->amenityId)->value('updated_at');
        $this->withHeader('Authorization', 'Bearer '.$this->adminToken)
            ->putJson('/api/v1/admin/amenities/'.$this->amenityId, ['hourly_rate' => 1, 'security_deposit_required' => 0, 'updated_at' => $timestamp])->assertOk();
        $this->withHeader('Authorization', 'Bearer '.$this->residentToken)
            ->getJson($this->availabilityUrl())->assertOk()->assertJsonPath('slots.0.available', false)->assertJsonPath('slots.0.total_amount', 1.5)
            ->assertJsonPath('slots.0.reason', 'Phí và cọc thanh toán QR phải có tổng là số đồng nguyên. Vui lòng liên hệ ban quản lý để kiểm tra cấu hình.');
        $this->postJson('/api/v1/resident/amenity-bookings', $this->bookingPayload())->assertConflict();
        $this->assertSame(0, DB::table('amenity_bookings')->where('amenity_id', $this->amenityId)->count());
        DB::table('amenities')->where('id', $this->amenityId)->update(['hourly_rate' => 2]);
        $this->getJson($this->availabilityUrl())->assertOk()->assertJsonPath('slots.0.available', true)->assertJsonPath('slots.0.total_amount', 3);
        $this->postJson('/api/v1/resident/amenity-bookings', $this->bookingPayload())->assertCreated()->assertJsonPath('booking.payment.amount', 3);
    }

    public function test_report_keeps_capacity_after_review_deadline_until_booking_start(): void
    {
        $id = $this->createPaidAmenityBooking();
        $receivedAt = now()->toIso8601String();
        $this->postJson($this->paymentUrl($id).'/report')->assertOk();
        $this->travel(31)->minutes();
        $this->getJson('/api/v1/resident/amenity-bookings/'.$id)->assertJsonPath('status', 'APPROVED')->assertJsonPath('payment.status', 'REPORTED')->assertJsonPath('payment.review_overdue', true);
        $this->getJson($this->availabilityUrl())->assertJsonPath('slots.0.remaining_attendees', 2);
        $this->withHeader('Authorization', 'Bearer '.$this->adminToken);
        $this->postJson($this->confirmUrl($id), array_merge($this->receipt(), ['received_at' => $receivedAt]))->assertOk()->assertJsonPath('payment.status', 'PAID');
    }

    public function test_unconfirmed_report_at_booking_start_releases_capacity_without_restoring_late_receipt(): void
    {
        $id = $this->createPaidAmenityBooking();
        $receivedAt = now()->toIso8601String();
        $this->postJson($this->paymentUrl($id).'/report')->assertOk();
        $this->travelTo(Carbon::parse($this->bookingDate.' 10:00:00'));
        $this->getJson('/api/v1/resident/amenity-bookings/'.$id)->assertJsonPath('status', 'CANCELLED')->assertJsonPath('payment.status', 'REVIEW');
        $this->withHeader('Authorization', 'Bearer '.$this->adminToken);
        $this->postJson($this->confirmUrl($id), array_merge($this->receipt(), ['received_at' => $receivedAt]))->assertOk()->assertJsonPath('payment.status', 'REVIEW')->assertJsonPath('payment.refund_required', true);
    }

    public function test_receipt_time_cannot_precede_payment_request_or_be_in_future(): void
    {
        $id = $this->createPaidAmenityBooking();
        $this->withHeader('Authorization', 'Bearer '.$this->adminToken);
        foreach ([now()->subYear()->toIso8601String(), now()->addDay()->toIso8601String()] as $date) {
            $this->postJson($this->confirmUrl($id), array_merge($this->receipt(), ['received_at' => $date]))->assertUnprocessable()->assertJsonValidationErrors('received_at');
        }
        $this->assertDatabaseHas('amenity_bookings', ['id' => $id, 'is_paid' => 0]);
    }

    public function test_status_transitions_are_forward_only_and_repeated_approval_is_idempotent(): void
    {
        DB::table('amenities')->where('id', $this->amenityId)->update(['requires_admin_approval' => 1]);
        $id = $this->createPaidAmenityBooking();
        $this->withHeader('Authorization', 'Bearer '.$this->adminToken);
        $url = '/api/v1/admin/amenities/'.$this->amenityId.'/bookings/'.$id.'/status';
        $this->patchJson($url, ['status' => 'COMPLETED'])->assertConflict();
        $this->patchJson($url, ['status' => 'APPROVED'])->assertOk();
        $count = DB::table('user_in_app_notifications')->where('recipient_user_id', $this->residentUser->id)->count();
        $this->patchJson($url, ['status' => 'APPROVED'])->assertOk();
        $this->assertSame($count, DB::table('user_in_app_notifications')->where('recipient_user_id', $this->residentUser->id)->count());
        $this->patchJson($url, ['status' => 'PENDING'])->assertConflict();
        $this->patchJson($url, ['status' => 'CANCELLED'])->assertOk();
        $this->patchJson($url, ['status' => 'APPROVED'])->assertConflict();
        $this->patchJson($url, ['status' => 'COMPLETED'])->assertConflict();
    }

    public function test_ownership_authentication_and_resident_cannot_confirm(): void
    {
        $id = $this->createPaidAmenityBooking();
        $this->postJson($this->confirmUrl($id), $this->receipt())->assertForbidden();
        $this->withHeader('Authorization', 'Bearer '.$this->secondToken);
        $this->getJson($this->paymentUrl($id))->assertNotFound();
        $this->postJson($this->paymentUrl($id).'/report')->assertNotFound();
        $this->withHeader('Authorization', '');
        $this->getJson($this->paymentUrl($id))->assertUnauthorized();
    }

    public function test_approval_opens_payment_and_unpaid_booking_cannot_be_confirmed(): void
    {
        DB::table('amenities')->where('id', $this->amenityId)->update(['requires_admin_approval' => 1]);
        $id = $this->createPaidAmenityBooking();
        $this->getJson($this->paymentUrl($id))->assertJsonPath('payment.status', 'WAITING_APPROVAL')->assertJsonPath('payment.qr_url', null);
        $this->postJson($this->paymentUrl($id).'/report')->assertConflict();
        $this->withHeader('Authorization', 'Bearer '.$this->adminToken);
        $url = '/api/v1/admin/amenities/'.$this->amenityId.'/bookings/'.$id.'/status';
        $this->patchJson($url, ['status' => 'APPROVED'])->assertOk();
        $this->patchJson($url, ['status' => 'CONFIRMED'])->assertConflict();
        $this->withHeader('Authorization', 'Bearer '.$this->residentToken);
        $this->getJson($this->paymentUrl($id))->assertJsonPath('payment.status', 'PENDING')->assertJsonPath('payment.can_pay', true);
    }

    public function test_confirmation_validates_money_and_records_receipt_once(): void
    {
        $id = $this->createPaidAmenityBooking();
        $this->withHeader('Authorization', 'Bearer '.$this->adminToken);
        $this->postJson($this->confirmUrl($id), array_merge($this->receipt(), ['received_amount' => 1]))->assertUnprocessable()->assertJsonValidationErrors('received_amount');
        $this->postJson($this->confirmUrl($id), $this->receipt())->assertOk()->assertJsonPath('payment.status', 'PAID')->assertJsonPath('payment.received_at', now()->toIso8601String());
        $notifications = DB::table('user_in_app_notifications')->where('recipient_user_id', $this->residentUser->id)->count();
        $this->postJson($this->confirmUrl($id), $this->receipt())->assertOk()->assertJsonPath('payment.status', 'PAID');
        $this->assertSame($notifications, DB::table('user_in_app_notifications')->where('recipient_user_id', $this->residentUser->id)->count());
        $this->assertDatabaseHas('amenity_bookings', ['id' => $id, 'is_paid' => 1]);
        $this->assertDatabaseHas('amenity_booking_payments', ['booking_id' => $id, 'confirmed_by_user_id' => $this->adminUser->id]);
        $this->assertDatabaseHas('audit_logs', ['table_name' => 'amenity_booking_payments', 'performed_by_user_id' => $this->adminUser->id]);
    }

    public function test_bank_transaction_cannot_be_reused_for_another_booking(): void
    {
        $first = $this->createPaidAmenityBooking();
        $this->withHeader('Authorization', 'Bearer '.$this->secondToken);
        $second = $this->postJson('/api/v1/resident/amenity-bookings', $this->bookingPayload())->assertCreated()->json('booking.id');
        $this->withHeader('Authorization', 'Bearer '.$this->adminToken);
        $this->postJson($this->confirmUrl($first), $this->receipt())->assertOk();
        $this->postJson($this->confirmUrl($second), $this->receipt())->assertUnprocessable()->assertJsonValidationErrors('bank_transaction_id');
        $this->assertDatabaseHas('amenity_bookings', ['id' => $second, 'is_paid' => 0]);
    }

    public function test_expiry_releases_capacity_without_scheduler_and_late_money_never_restores_booking(): void
    {
        $id = $this->createPaidAmenityBooking();
        $this->travel(15)->minutes();
        $this->getJson($this->availabilityUrl())->assertOk()->assertJsonPath('slots.0.remaining_attendees', 4);
        $this->assertDatabaseHas('amenity_bookings', ['id' => $id, 'status' => 'CANCELLED']);
        $this->postJson($this->paymentUrl($id).'/report')->assertConflict();
        $this->withHeader('Authorization', 'Bearer '.$this->adminToken);
        $this->postJson($this->confirmUrl($id), $this->receipt())->assertOk()->assertJsonPath('payment.status', 'REVIEW')->assertJsonPath('payment.refund_required', true);
        $this->assertDatabaseHas('amenity_bookings', ['id' => $id, 'status' => 'CANCELLED', 'is_paid' => 0]);
    }

    public function test_paid_cancellation_keeps_transaction_and_requires_refund(): void
    {
        $id = $this->createPaidAmenityBooking();
        $this->withHeader('Authorization', 'Bearer '.$this->adminToken);
        $this->postJson($this->confirmUrl($id), $this->receipt())->assertOk();
        $this->withHeader('Authorization', 'Bearer '.$this->residentToken);
        $this->postJson('/api/v1/resident/amenity-bookings/'.$id.'/cancel')->assertOk()->assertJsonPath('booking.payment.refund_required', true);
        $this->assertDatabaseHas('amenity_booking_payments', ['booking_id' => $id, 'bank_transaction_id' => 'VCB-TEST-001', 'received_amount' => 200000]);
    }

    public function test_rejected_reconciliation_keeps_reason_and_releases_capacity(): void
    {
        $id = $this->createPaidAmenityBooking();
        $this->postJson($this->paymentUrl($id).'/report')->assertOk();
        $this->withHeader('Authorization', 'Bearer '.$this->adminToken);
        $url = str_replace('/confirm', '/reject', $this->confirmUrl($id));
        $this->postJson($url)->assertUnprocessable();
        $this->postJson($url, ['reason' => 'Chưa nhận đủ tiền'])->assertOk()->assertJsonPath('payment.review_reason', 'Chưa nhận đủ tiền');
        $this->assertDatabaseHas('amenity_bookings', ['id' => $id, 'status' => 'CANCELLED', 'is_paid' => 0]);
    }

    public function test_free_and_legacy_bookings_are_not_subject_to_payment_expiry(): void
    {
        $id = $this->createPaidAmenityBooking();
        DB::table('amenity_booking_payments')->where('booking_id', $id)->delete();
        $this->travel(60)->minutes();
        app(AmenityBookingPaymentService::class)->expire($this->amenityId);
        $this->assertDatabaseHas('amenity_bookings', ['id' => $id, 'status' => 'APPROVED']);
        $this->postJson('/api/v1/resident/amenity-bookings/'.$id.'/cancel')->assertOk();
        DB::table('amenities')->where('id', $this->amenityId)->update(['hourly_rate' => 0, 'security_deposit_required' => 0]);
        $free = $this->createPaidAmenityBooking();
        $this->getJson($this->paymentUrl($free))->assertOk()->assertJsonPath('payment', null);
        $this->assertDatabaseHas('amenity_bookings', ['id' => $free, 'is_paid' => 1]);

    }
}

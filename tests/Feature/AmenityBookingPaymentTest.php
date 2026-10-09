<?php

namespace Tests\Feature;

use App\Services\AmenityBookingPaymentService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
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
        DB::table('amenities')->where('id', $this->amenityId)->update(['hourly_rate' => 1, 'security_deposit_required' => 0]);
        $this->postJson('/api/v1/resident/amenity-bookings', $this->bookingPayload())->assertUnprocessable()->assertJsonValidationErrors('amenity_id');
        $this->assertSame(0, DB::table('amenity_bookings')->where('amenity_id', $this->amenityId)->count());
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

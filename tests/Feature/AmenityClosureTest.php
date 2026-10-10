<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\ResidentAmenityBookingFixtures;
use Tests\TestCase;

class AmenityClosureTest extends TestCase
{
    use DatabaseTransactions, ResidentAmenityBookingFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        $this->createBookingFixture();
        DB::table('amenities')->where('id', $this->amenityId)->update(['requires_admin_approval' => 0]);
    }

    private function booking(): string
    {
        $this->withHeader('Authorization', 'Bearer '.$this->residentToken);
        $id = $this->postJson('/api/v1/resident/amenity-bookings', $this->bookingPayload())->assertCreated()->json('booking.id');
        $this->withHeader('Authorization', 'Bearer '.$this->adminToken);

        return $id;
    }

    private function url(): string
    {
        return '/api/v1/admin/amenities/'.$this->amenityId;
    }

    /** @return array<string, mixed> */
    private function closure(string $token): array
    {
        return ['is_active' => false, 'updated_at' => $this->getJson($this->url())->assertOk()->json('updated_at'), 'booking_action' => 'cancel', 'reason' => 'Sửa hệ thống điện', 'confirmation_token' => $token];
    }

    /** @param array<string, mixed> $changes */
    private function copyBooking(string $id, array $changes): string
    {
        $copy = array_merge((array) DB::table('amenity_bookings')->where('id', $id)->first(), ['id' => (string) Str::uuid(), 'booking_code' => 'BK-'.Str::random(12), 'checkin_qr_code' => 'QR-'.Str::random(32)], $changes);
        DB::table('amenity_bookings')->insert($copy);

        return $copy['id'];
    }

    public function test_pause_preserves_old_booking_and_payment_but_blocks_new_bookings(): void
    {
        $id = $this->booking();
        $this->patchJson($this->url().'/status', ['is_active' => false, 'updated_at' => $this->getJson($this->url())->json('updated_at'), 'booking_action' => 'keep'])->assertOk();
        $this->assertDatabaseHas('amenity_bookings', ['id' => $id, 'status' => 'APPROVED']);
        $this->withHeader('Authorization', 'Bearer '.$this->residentToken);
        $this->getJson('/api/v1/resident/amenity-bookings/'.$id.'/payment')->assertJsonPath('payment.can_pay', true);
        $this->postJson('/api/v1/resident/amenity-bookings', $this->bookingPayload())->assertConflict();
        $this->withHeader('Authorization', 'Bearer '.$this->adminToken);
        $this->patchJson($this->url().'/status', ['is_active' => true, 'updated_at' => $this->getJson($this->url())->json('updated_at')])->assertOk();
        $this->assertDatabaseHas('amenity_bookings', ['id' => $id, 'status' => 'APPROVED']);
    }

    public function test_close_requires_preview_reason_and_permission_then_cancels_notifies_and_audits(): void
    {
        $id = $this->booking();
        $token = $this->getJson($this->url().'/closure-impact')->assertOk()->assertJsonPath('count', 1)->json('confirmation_token');
        $payload = $this->closure($token);
        $this->patchJson($this->url().'/status', array_merge($payload, ['reason' => null]))->assertUnprocessable();
        $this->patchJson($this->url().'/status', array_merge($payload, ['confirmation_token' => null]))->assertConflict();
        $this->patchJson($this->url().'/status', array_merge($payload, ['updated_at' => '2000-01-01T00:00:00Z']))->assertConflict();
        $this->assertDatabaseHas('amenity_bookings', ['id' => $id, 'status' => 'APPROVED']);
        $this->withHeader('Authorization', 'Bearer '.$this->residentToken);
        $this->getJson($this->url().'/closure-impact')->assertForbidden();
        $this->patchJson($this->url().'/status', $payload)->assertForbidden();
        $this->withHeader('Authorization', 'Bearer '.$this->adminToken);
        $this->patchJson($this->url().'/status', $payload)->assertOk();
        $this->assertDatabaseHas('amenity_bookings', ['id' => $id, 'status' => 'CANCELLED', 'total_amount' => 150000, 'deposit_amount' => 50000]);
        $this->assertDatabaseHas('amenity_booking_payments', ['booking_id' => $id, 'status' => 'CANCELLED']);
        $this->assertSame(1, DB::table('user_in_app_notifications')->where('recipient_user_id', $this->residentUser->id)->where('category', 'AMENITY')->where('body_message', 'like', '%Sửa hệ thống điện%')->count());
        $this->assertDatabaseHas('audit_logs', ['record_id' => $id, 'action' => 'UPDATE']);
    }

    public function test_new_booking_and_payment_change_invalidate_preview_without_partial_write(): void
    {
        $id = $this->booking();
        $token = $this->getJson($this->url().'/closure-impact')->json('confirmation_token');
        $other = $this->copyBooking($id, ['start_time' => '13:00', 'end_time' => '14:00']);
        $this->patchJson($this->url().'/status', $this->closure($token))->assertConflict()->assertJsonPath('impact.count', 2);
        $this->assertDatabaseHas('amenity_bookings', ['id' => $other, 'status' => 'APPROVED']);
        $token = $this->getJson($this->url().'/closure-impact')->json('confirmation_token');
        DB::table('amenity_booking_payments')->where('booking_id', $id)->update(['status' => 'REPORTED', 'reported_at' => now()]);
        $this->patchJson($this->url().'/status', $this->closure($token))->assertConflict();
        $this->assertDatabaseHas('amenities', ['id' => $this->amenityId, 'is_active' => 1]);
        $this->assertDatabaseHas('amenity_bookings', ['id' => $id, 'status' => 'APPROVED']);
    }

    public function test_partial_maintenance_only_cancels_overlaps_and_removal_does_not_revive(): void
    {
        $id = $this->booking();
        $edge = $this->copyBooking($id, ['start_time' => '09:00', 'end_time' => '10:30']);
        $outside = $this->copyBooking($id, ['start_time' => '12:00', 'end_time' => '13:00']);
        $otherDay = $this->copyBooking($id, ['booking_date' => today()->addDays(3)->toDateString()]);
        $period = ['blackout_date' => $this->bookingDate, 'start_time' => '10:30', 'end_time' => '12:00', 'reason' => 'Thay thiết bị'];
        $token = $this->getJson($this->url().'/closure-impact?'.http_build_query($period))->assertOk()->assertJsonPath('count', 1)->json('confirmation_token');
        $this->postJson($this->url().'/blackouts', $period)->assertConflict();
        $this->assertSame(0, DB::table('amenity_blackouts')->where('amenity_id', $this->amenityId)->count());
        $blackout = $this->postJson($this->url().'/blackouts', array_merge($period, ['confirmation_token' => $token]))->assertCreated()->json('id');
        $this->assertDatabaseHas('amenity_bookings', ['id' => $id, 'status' => 'CANCELLED']);
        foreach ([$edge, $outside, $otherDay] as $unaffected) {
            $this->assertDatabaseHas('amenity_bookings', ['id' => $unaffected, 'status' => 'APPROVED']);
        }
        $this->deleteJson($this->url().'/blackouts/'.$blackout)->assertOk();
        $this->assertDatabaseHas('amenity_bookings', ['id' => $id, 'status' => 'CANCELLED']);
    }

    public function test_paid_closure_keeps_receipt_and_marks_refund(): void
    {
        $id = $this->booking();
        $receipt = ['bank_transaction_id' => 'VCB-CLOSURE', 'received_amount' => 200000, 'received_at' => now()->toIso8601String()];
        $confirm = $this->url().'/bookings/'.$id.'/payment/confirm';
        $this->postJson($confirm, $receipt)->assertOk();
        $token = $this->getJson($this->url().'/closure-impact')->assertJsonPath('received_amount', 200000)->json('confirmation_token');
        $this->patchJson($this->url().'/status', $this->closure($token))->assertOk();
        $this->assertDatabaseHas('amenity_booking_payments', ['booking_id' => $id, 'status' => 'REVIEW', 'refund_required' => 1, 'received_amount' => 200000]);
        $this->postJson($confirm, $receipt)->assertOk()->assertJsonPath('payment.status', 'REVIEW');
        $this->assertDatabaseHas('amenity_bookings', ['id' => $id, 'status' => 'CANCELLED', 'is_paid' => 1]);
    }

    public function test_reported_closure_keeps_reconciliation_and_late_receipt_cannot_revive(): void
    {
        $id = $this->booking();
        $this->withHeader('Authorization', 'Bearer '.$this->residentToken);
        $this->postJson('/api/v1/resident/amenity-bookings/'.$id.'/payment/report')->assertOk();
        $this->withHeader('Authorization', 'Bearer '.$this->adminToken);
        $token = $this->getJson($this->url().'/closure-impact')->assertJsonPath('reported_count', 1)->json('confirmation_token');
        $this->patchJson($this->url().'/status', $this->closure($token))->assertOk();
        $this->assertDatabaseHas('amenity_booking_payments', ['booking_id' => $id, 'status' => 'REVIEW']);
        $this->postJson($this->url().'/bookings/'.$id.'/payment/confirm', ['bank_transaction_id' => 'VCB-LATE-CLOSURE', 'received_amount' => 200000, 'received_at' => now()->toIso8601String()])->assertOk()->assertJsonPath('payment.refund_required', true);
        $this->assertDatabaseHas('amenity_bookings', ['id' => $id, 'status' => 'CANCELLED']);
    }

    public function test_full_day_update_excludes_terminal_bookings(): void
    {
        $id = $this->booking();
        $terminal = $this->copyBooking($id, ['status' => 'COMPLETED']);
        $blackout = $this->postJson($this->url().'/blackouts', ['blackout_date' => today()->addDays(3)->toDateString(), 'reason' => 'Kiểm tra'])->assertCreated()->json('id');
        $period = ['blackout_date' => $this->bookingDate, 'reason' => 'Đóng cả ngày'];
        $token = $this->getJson($this->url().'/closure-impact?'.http_build_query($period))->assertJsonPath('count', 1)->json('confirmation_token');
        $this->putJson($this->url().'/blackouts/'.$blackout, array_merge($period, ['confirmation_token' => $token]))->assertOk();
        $this->assertDatabaseHas('amenity_bookings', ['id' => $id, 'status' => 'CANCELLED']);
        $this->assertDatabaseHas('amenity_bookings', ['id' => $terminal, 'status' => 'COMPLETED']);
    }
}

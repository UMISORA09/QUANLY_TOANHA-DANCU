<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\ResidentAmenityBookingFixtures;
use Tests\TestCase;

class AmenityBookingApprovalTest extends TestCase
{
    use DatabaseTransactions, ResidentAmenityBookingFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createBookingFixture();
    }

    /**
     * TC01 — Quản lý xem danh sách PENDING.
     */
    public function test_tc01_manager_can_view_pending_bookings(): void
    {
        $created = $this->postJson('/api/v1/resident/amenity-bookings', $this->bookingPayload())->assertCreated()->json('booking');
        $this->assertEquals('PENDING', $created['status']);

        $this->withHeader('Authorization', 'Bearer '.$this->adminToken);

        // Kiểm tra danh sách qua Worklist
        $worklist = $this->getJson('/api/v1/admin/amenity-booking-worklist?bucket=PENDING')->assertOk();
        $worklist->assertJsonFragment(['booking_code' => $created['booking_code']]);

        // Kiểm tra danh sách chi tiết theo tiện ích
        $bookings = $this->getJson("/api/v1/admin/amenities/{$this->amenityId}/bookings")->assertOk()->json();
        $item = collect($bookings)->firstWhere('id', $created['id']);
        $this->assertNotNull($item);
        $this->assertEquals('PENDING', $item['status']);
    }

    /**
     * TC02 — Duyệt booking hợp lệ.
     */
    public function test_tc02_manager_can_approve_valid_booking(): void
    {
        $created = $this->postJson('/api/v1/resident/amenity-bookings', $this->bookingPayload())->assertCreated()->json('booking');

        $this->withHeader('Authorization', 'Bearer '.$this->adminToken);
        $response = $this->patchJson("/api/v1/admin/amenities/{$this->amenityId}/bookings/{$created['id']}/status", [
            'status' => 'APPROVED',
            'admin_notes' => 'Đã duyệt qua test TC02',
        ])->assertOk();

        $response->assertJsonFragment([
            'id' => $created['id'],
            'status' => 'APPROVED',
            'admin_notes' => 'Đã duyệt qua test TC02',
        ]);

        $fresh = DB::table('amenity_bookings')->where('id', $created['id'])->first();
        $this->assertEquals('APPROVED', $fresh->status);
        $this->assertEquals($this->adminUser->id, $fresh->approved_by_user_id);
        $this->assertNotNull($fresh->approved_at);
    }

    /**
     * TC03 — Từ chối booking có lý do.
     */
    public function test_tc03_manager_can_reject_booking_with_reason(): void
    {
        $created = $this->postJson('/api/v1/resident/amenity-bookings', $this->bookingPayload())->assertCreated()->json('booking');

        $this->withHeader('Authorization', 'Bearer '.$this->adminToken);
        $response = $this->patchJson("/api/v1/admin/amenities/{$this->amenityId}/bookings/{$created['id']}/status", [
            'status' => 'REJECTED',
            'rejection_reason' => 'Không đủ điều kiện tổ chức sự kiện',
        ])->assertOk();

        $response->assertJsonFragment([
            'id' => $created['id'],
            'status' => 'REJECTED',
            'rejection_reason' => 'Không đủ điều kiện tổ chức sự kiện',
        ]);

        $fresh = DB::table('amenity_bookings')->where('id', $created['id'])->first();
        $this->assertEquals('REJECTED', $fresh->status);
        $this->assertEquals('Không đủ điều kiện tổ chức sự kiện', $fresh->rejection_reason);
    }

    /**
     * TC04 — Không cho từ chối với lý do rỗng.
     */
    public function test_tc04_cannot_reject_booking_with_empty_reason(): void
    {
        $created = $this->postJson('/api/v1/resident/amenity-bookings', $this->bookingPayload())->assertCreated()->json('booking');

        $this->withHeader('Authorization', 'Bearer '.$this->adminToken);
        $this->patchJson("/api/v1/admin/amenities/{$this->amenityId}/bookings/{$created['id']}/status", [
            'status' => 'REJECTED',
            'rejection_reason' => '',
        ])->assertUnprocessable()->assertJsonValidationErrors('rejection_reason');

        $this->patchJson("/api/v1/admin/amenities/{$this->amenityId}/bookings/{$created['id']}/status", [
            'status' => 'REJECTED',
            'rejection_reason' => '   ',
        ])->assertUnprocessable()->assertJsonValidationErrors('rejection_reason');
    }

    /**
     * TC05 — Không cho duyệt booking CANCELLED.
     */
    public function test_tc05_cannot_approve_cancelled_booking(): void
    {
        $created = $this->postJson('/api/v1/resident/amenity-bookings', $this->bookingPayload())->assertCreated()->json('booking');

        // Cư dân hủy booking
        $this->postJson("/api/v1/resident/amenity-bookings/{$created['id']}/cancel", [
            'reason' => 'Cư dân thay đổi kế hoạch',
        ])->assertOk();

        // Quản lý cố duyệt booking đã hủy
        $this->withHeader('Authorization', 'Bearer '.$this->adminToken);
        $this->patchJson("/api/v1/admin/amenities/{$this->amenityId}/bookings/{$created['id']}/status", [
            'status' => 'APPROVED',
        ])->assertStatus(409);

        $fresh = DB::table('amenity_bookings')->where('id', $created['id'])->first();
        $this->assertEquals('CANCELLED', $fresh->status);
    }

    /**
     * TC06 — Không cho duyệt booking REJECTED.
     */
    public function test_tc06_cannot_approve_rejected_booking(): void
    {
        $created = $this->postJson('/api/v1/resident/amenity-bookings', $this->bookingPayload())->assertCreated()->json('booking');

        $this->withHeader('Authorization', 'Bearer '.$this->adminToken);
        $this->patchJson("/api/v1/admin/amenities/{$this->amenityId}/bookings/{$created['id']}/status", [
            'status' => 'REJECTED',
            'rejection_reason' => 'Đã từ chối lần 1',
        ])->assertOk();

        // Cố duyệt lại booking đã bị từ chối
        $this->patchJson("/api/v1/admin/amenities/{$this->amenityId}/bookings/{$created['id']}/status", [
            'status' => 'APPROVED',
        ])->assertStatus(409);

        $fresh = DB::table('amenity_bookings')->where('id', $created['id'])->first();
        $this->assertEquals('REJECTED', $fresh->status);
    }

    /**
     * TC07 — Không cho từ chối booking COMPLETED.
     */
    public function test_tc07_cannot_reject_completed_booking(): void
    {
        $created = $this->postJson('/api/v1/resident/amenity-bookings', $this->bookingPayload())->assertCreated()->json('booking');

        $this->withHeader('Authorization', 'Bearer '.$this->adminToken);
        $this->patchJson("/api/v1/admin/amenities/{$this->amenityId}/bookings/{$created['id']}/status", [
            'status' => 'APPROVED',
        ])->assertOk();

        // Đánh dấu đã thanh toán và cập nhật CONFIRMED -> COMPLETED
        DB::table('amenity_bookings')->where('id', $created['id'])->update(['is_paid' => 1, 'status' => 'CONFIRMED']);
        $this->patchJson("/api/v1/admin/amenities/{$this->amenityId}/bookings/{$created['id']}/status", [
            'status' => 'COMPLETED',
        ])->assertOk();

        // Cố từ chối booking COMPLETED
        $this->patchJson("/api/v1/admin/amenities/{$this->amenityId}/bookings/{$created['id']}/status", [
            'status' => 'REJECTED',
            'rejection_reason' => 'Cố từ chối sau khi hoàn thành',
        ])->assertStatus(409);

        $fresh = DB::table('amenity_bookings')->where('id', $created['id'])->first();
        $this->assertEquals('COMPLETED', $fresh->status);
    }

    /**
     * TC08 — Không cho người không có quyền xét duyệt.
     */
    public function test_tc08_unauthorized_user_cannot_approve_or_reject(): void
    {
        $created = $this->postJson('/api/v1/resident/amenity-bookings', $this->bookingPayload())->assertCreated()->json('booking');

        // Không có token -> 401
        $this->withHeader('Authorization', '');
        $this->patchJson("/api/v1/admin/amenities/{$this->amenityId}/bookings/{$created['id']}/status", [
            'status' => 'APPROVED',
        ])->assertUnauthorized();

        // Token của cư dân (không có quyền AMENITY:UPDATE) -> 403
        $this->withHeader('Authorization', 'Bearer '.$this->residentToken);
        $this->patchJson("/api/v1/admin/amenities/{$this->amenityId}/bookings/{$created['id']}/status", [
            'status' => 'APPROVED',
        ])->assertForbidden();
    }

    /**
     * TC09 — Không tạo audit log trùng khi gửi lại request.
     */
    public function test_tc09_idempotent_request_does_not_create_duplicate_audit_log(): void
    {
        $created = $this->postJson('/api/v1/resident/amenity-bookings', $this->bookingPayload())->assertCreated()->json('booking');

        $this->withHeader('Authorization', 'Bearer '.$this->adminToken);

        // Duyệt lần 1
        $this->patchJson("/api/v1/admin/amenities/{$this->amenityId}/bookings/{$created['id']}/status", [
            'status' => 'APPROVED',
            'admin_notes' => 'Lần 1',
        ])->assertOk();

        $auditCount1 = DB::table('audit_logs')->where('table_name', 'amenity_bookings')->where('record_id', $created['id'])->count();
        $notifCount1 = DB::table('user_in_app_notifications')->where('recipient_user_id', $this->residentUser->id)->count();

        // Gửi lại request duyệt lần 2 với cùng dữ liệu
        $this->patchJson("/api/v1/admin/amenities/{$this->amenityId}/bookings/{$created['id']}/status", [
            'status' => 'APPROVED',
            'admin_notes' => 'Lần 1',
        ])->assertOk();

        $auditCount2 = DB::table('audit_logs')->where('table_name', 'amenity_bookings')->where('record_id', $created['id'])->count();
        $notifCount2 = DB::table('user_in_app_notifications')->where('recipient_user_id', $this->residentUser->id)->count();

        $this->assertEquals($auditCount1, $auditCount2, 'Không được ghi thêm audit log khi gửi lại cùng request duyệt.');
        $this->assertEquals($notifCount1, $notifCount2, 'Không được gửi thêm notification trùng.');
    }

    /**
     * TC10 — Cư dân nhận notification khi được duyệt.
     */
    public function test_tc10_resident_receives_notification_when_approved(): void
    {
        $created = $this->postJson('/api/v1/resident/amenity-bookings', $this->bookingPayload())->assertCreated()->json('booking');

        $this->withHeader('Authorization', 'Bearer '.$this->adminToken);
        $this->patchJson("/api/v1/admin/amenities/{$this->amenityId}/bookings/{$created['id']}/status", [
            'status' => 'APPROVED',
        ])->assertOk();

        $notification = DB::table('user_in_app_notifications')
            ->where('recipient_user_id', $this->residentUser->id)
            ->where('category', 'AMENITY')
            ->where('deep_link_url', 'like', '%'.$created['id'].'%')
            ->first();

        $this->assertNotNull($notification);
        $this->assertStringContainsString('Yêu cầu đăng ký tiện ích của bạn đã được phê duyệt.', $notification->body_message);
    }

    /**
     * TC11 — Cư dân nhận notification khi bị từ chối.
     */
    public function test_tc11_resident_receives_notification_when_rejected(): void
    {
        $created = $this->postJson('/api/v1/resident/amenity-bookings', $this->bookingPayload())->assertCreated()->json('booking');

        $this->withHeader('Authorization', 'Bearer '.$this->adminToken);
        $this->patchJson("/api/v1/admin/amenities/{$this->amenityId}/bookings/{$created['id']}/status", [
            'status' => 'REJECTED',
            'rejection_reason' => 'Không đáp ứng quy định tiếng ồn',
        ])->assertOk();

        $notification = DB::table('user_in_app_notifications')
            ->where('recipient_user_id', $this->residentUser->id)
            ->where('category', 'AMENITY')
            ->where('deep_link_url', 'like', '%'.$created['id'].'%')
            ->first();

        $this->assertNotNull($notification);
        $this->assertStringContainsString('Yêu cầu đăng ký tiện ích của bạn đã bị từ chối.', $notification->body_message);
        $this->assertStringContainsString('Không đáp ứng quy định tiếng ồn', $notification->body_message);
    }

    /**
     * TC12 — Hai quản lý duyệt đồng thời.
     */
    public function test_tc12_concurrent_approvals_are_handled_idempotently(): void
    {
        $created = $this->postJson('/api/v1/resident/amenity-bookings', $this->bookingPayload())->assertCreated()->json('booking');

        // Quản lý 1 duyệt
        $this->withHeader('Authorization', 'Bearer '.$this->adminToken);
        $resp1 = $this->patchJson("/api/v1/admin/amenities/{$this->amenityId}/bookings/{$created['id']}/status", [
            'status' => 'APPROVED',
        ]);
        $resp1->assertOk();

        // Tạo quản lý 2 với quyền SUPER_ADMIN
        $manager2 = User::factory()->create();
        $role = DB::table('roles')->where('role_code', 'SUPER_ADMIN')->first();
        $roleId = $role?->id ?? (string) Str::uuid();
        if (! $role) {
            DB::table('roles')->insert(['id' => $roleId, 'role_code' => 'SUPER_ADMIN', 'role_name' => 'Super Administrator']);
        }
        DB::table('user_roles')->insert(['id' => (string) Str::uuid(), 'role_id' => $roleId, 'user_id' => $manager2->id]);
        $token2 = $this->sessionToken($manager2);

        // Quản lý 2 duyệt lại cùng booking
        $this->withHeader('Authorization', 'Bearer '.$token2);
        $resp2 = $this->patchJson("/api/v1/admin/amenities/{$this->amenityId}/bookings/{$created['id']}/status", [
            'status' => 'APPROVED',
        ]);
        $resp2->assertOk();

        // Chỉ một trạng thái APPROVED, chỉ có 1 notification gửi đến cư dân
        $notifications = DB::table('user_in_app_notifications')
            ->where('recipient_user_id', $this->residentUser->id)
            ->where('category', 'AMENITY')
            ->where('deep_link_url', 'like', '%'.$created['id'].'%')
            ->get();
        $this->assertCount(1, $notifications);
    }

    /**
     * TC13 — Một quản lý duyệt, một quản lý từ chối đồng thời.
     */
    public function test_tc13_conflicting_approval_and_rejection_results_in_409(): void
    {
        $created = $this->postJson('/api/v1/resident/amenity-bookings', $this->bookingPayload())->assertCreated()->json('booking');

        // Quản lý 1 duyệt trước
        $this->withHeader('Authorization', 'Bearer '.$this->adminToken);
        $this->patchJson("/api/v1/admin/amenities/{$this->amenityId}/bookings/{$created['id']}/status", [
            'status' => 'APPROVED',
        ])->assertOk();

        // Quản lý 2 cố từ chối booking vừa được duyệt -> nhận 409 Conflict
        $this->patchJson("/api/v1/admin/amenities/{$this->amenityId}/bookings/{$created['id']}/status", [
            'status' => 'REJECTED',
            'rejection_reason' => 'Từ chối sau khi đã được duyệt',
        ])->assertStatus(409);

        $fresh = DB::table('amenity_bookings')->where('id', $created['id'])->first();
        $this->assertEquals('APPROVED', $fresh->status);
    }

    /**
     * TC14 — Cư dân hủy trong lúc quản lý duyệt.
     */
    public function test_tc14_cannot_approve_booking_if_resident_already_cancelled(): void
    {
        $created = $this->postJson('/api/v1/resident/amenity-bookings', $this->bookingPayload())->assertCreated()->json('booking');

        // Cư dân hủy trước
        $this->withHeader('Authorization', 'Bearer '.$this->residentToken);
        $this->postJson("/api/v1/resident/amenity-bookings/{$created['id']}/cancel", [
            'reason' => 'Cư dân hủy',
        ])->assertOk();

        // Quản lý duyệt booking -> nhận 409
        $this->withHeader('Authorization', 'Bearer '.$this->adminToken);
        $this->patchJson("/api/v1/admin/amenities/{$this->amenityId}/bookings/{$created['id']}/status", [
            'status' => 'APPROVED',
        ])->assertStatus(409);

        $fresh = DB::table('amenity_bookings')->where('id', $created['id'])->first();
        $this->assertEquals('CANCELLED', $fresh->status);
    }

    /**
     * TC15 — Từ chối booking đã thanh toán và kiểm tra trạng thái hoàn tiền/đối soát.
     */
    public function test_tc15_rejecting_booking_with_payment_triggers_review_and_refund(): void
    {
        $created = $this->postJson('/api/v1/resident/amenity-bookings', $this->bookingPayload())->assertCreated()->json('booking');

        // Giả lập cư dân đã thanh toán (có transaction_id)
        DB::table('amenity_booking_payments')->where('booking_id', $created['id'])->update([
            'status' => 'WAITING_APPROVAL',
            'bank_transaction_id' => 'BANK-TX-123456',
            'received_amount' => 150000,
            'received_at' => now(),
        ]);

        $this->withHeader('Authorization', 'Bearer '.$this->adminToken);
        $this->patchJson("/api/v1/admin/amenities/{$this->amenityId}/bookings/{$created['id']}/status", [
            'status' => 'REJECTED',
            'rejection_reason' => 'Từ chối sau khi cư dân chuyển tiền',
        ])->assertOk();

        // Kiểm tra thanh toán chuyển sang REVIEW và refund_required = 1
        $payment = DB::table('amenity_booking_payments')->where('booking_id', $created['id'])->first();
        $this->assertEquals('REVIEW', $payment->status);
        $this->assertEquals(1, $payment->refund_required);
    }

    /**
     * TC16 — Dữ liệu frontend được cập nhật sau khi xử lý (ETag invalidation).
     */
    public function test_tc16_frontend_cache_and_etag_are_invalidated_after_approval(): void
    {
        $created = $this->postJson('/api/v1/resident/amenity-bookings', $this->bookingPayload())->assertCreated()->json('booking');

        $this->withHeader('Authorization', 'Bearer '.$this->adminToken);
        $etagBefore = $this->getJson('/api/v1/admin/amenities')->assertOk()->headers->get('ETag');

        $this->patchJson("/api/v1/admin/amenities/{$this->amenityId}/bookings/{$created['id']}/status", [
            'status' => 'APPROVED',
        ])->assertOk();

        $etagAfter = $this->getJson('/api/v1/admin/amenities')->assertOk()->headers->get('ETag');
        $this->assertNotEquals($etagBefore, $etagAfter);
    }

    /**
     * TC17 — Trang danh sách phân trang và lọc chính xác.
     */
    public function test_tc17_worklist_paginates_and_filters_accurately(): void
    {
        $this->withHeader('Authorization', 'Bearer '.$this->adminToken);

        $response = $this->getJson('/api/v1/admin/amenity-booking-worklist?bucket=PENDING&page=1')->assertOk();
        $response->assertJsonStructure([
            'items',
            'counts' => ['PENDING', 'REPORTED', 'REVIEW'],
            'page',
            'total_pages',
        ]);
    }

    /**
     * TC18 — Kiểm tra luồng tiện ích không yêu cầu phê duyệt.
     */
    public function test_tc18_amenity_not_requiring_approval_is_automatically_approved(): void
    {
        // Cập nhật tiện ích không yêu cầu duyệt
        DB::table('amenities')->where('id', $this->amenityId)->update(['requires_admin_approval' => 0]);

        $this->withHeader('Authorization', 'Bearer '.$this->residentToken);
        $created = $this->postJson('/api/v1/resident/amenity-bookings', $this->bookingPayload())->assertCreated()->json('booking');

        // Tự động APPROVED
        $this->assertEquals('APPROVED', $created['status']);

        $fresh = DB::table('amenity_bookings')->where('id', $created['id'])->first();
        $this->assertEquals('APPROVED', $fresh->status);
    }

    /**
     * TC19 — Kiểm tra thanh toán không bị đánh dấu thành công sai.
     */
    public function test_tc19_cannot_confirm_or_complete_unpaid_booking(): void
    {
        $created = $this->postJson('/api/v1/resident/amenity-bookings', $this->bookingPayload())->assertCreated()->json('booking');

        $this->withHeader('Authorization', 'Bearer '.$this->adminToken);
        $this->patchJson("/api/v1/admin/amenities/{$this->amenityId}/bookings/{$created['id']}/status", [
            'status' => 'APPROVED',
        ])->assertOk();

        // Cố chuyển sang CONFIRMED khi chưa thanh toán (is_paid = 0)
        $this->patchJson("/api/v1/admin/amenities/{$this->amenityId}/bookings/{$created['id']}/status", [
            'status' => 'CONFIRMED',
        ])->assertStatus(409);

        $this->patchJson("/api/v1/admin/amenities/{$this->amenityId}/bookings/{$created['id']}/status", [
            'status' => 'COMPLETED',
        ])->assertStatus(409);
    }

    public function test_unpaid_legacy_booking_without_payment_cannot_be_confirmed_or_completed(): void
    {
        $created = $this->postJson('/api/v1/resident/amenity-bookings', $this->bookingPayload())->assertCreated()->json('booking');
        DB::table('amenity_booking_payments')->where('booking_id', $created['id'])->delete();
        DB::table('amenity_bookings')->where('id', $created['id'])->update(['status' => 'APPROVED']);
        $this->withHeader('Authorization', 'Bearer '.$this->adminToken);

        foreach ([150000, 0] as $fee) {
            DB::table('amenity_bookings')->where('id', $created['id'])->update(['total_amount' => $fee]);
            foreach (['CONFIRMED', 'COMPLETED'] as $status) {
                $this->patchJson("/api/v1/admin/amenities/{$this->amenityId}/bookings/{$created['id']}/status", [
                    'status' => $status,
                ])->assertConflict();
                $this->assertDatabaseHas('amenity_bookings', ['id' => $created['id'], 'status' => 'APPROVED', 'is_paid' => 0]);
            }
        }
    }

    public function test_free_or_paid_legacy_booking_without_payment_can_be_completed(): void
    {
        $created = $this->postJson('/api/v1/resident/amenity-bookings', $this->bookingPayload())->assertCreated()->json('booking');
        DB::table('amenity_booking_payments')->where('booking_id', $created['id'])->delete();
        $this->withHeader('Authorization', 'Bearer '.$this->adminToken);

        foreach ([['total_amount' => 0, 'deposit_amount' => 0, 'is_paid' => 0], ['total_amount' => 150000, 'deposit_amount' => 50000, 'is_paid' => 1]] as $amounts) {
            DB::table('amenity_bookings')->where('id', $created['id'])->update(['status' => 'APPROVED', ...$amounts]);
            $this->patchJson("/api/v1/admin/amenities/{$this->amenityId}/bookings/{$created['id']}/status", [
                'status' => 'COMPLETED',
            ])->assertOk();
        }
    }

    /**
     * TC20 — Regression test: chức năng đăng ký và chặn trùng lịch vẫn hoạt động.
     */
    public function test_tc20_regression_booking_and_slot_concurrency_prevention_still_works(): void
    {
        // Đặt slot thứ nhất
        $booking1 = $this->postJson('/api/v1/resident/amenity-bookings', $this->bookingPayload([
            'attendee_count' => 3,
        ]))->assertCreated()->json('booking');
        $this->assertNotNull($booking1);

        // max_capacity_per_slot = 4, booking1 đã chiếm 3 người, chỉ còn 1 chỗ
        // Người thứ 2 cố đặt 2 người -> phải bị chặn 409
        $this->withHeader('Authorization', 'Bearer '.$this->secondToken);
        $this->postJson('/api/v1/resident/amenity-bookings', $this->bookingPayload([
            'attendee_count' => 2,
        ]))->assertStatus(409);
    }
}

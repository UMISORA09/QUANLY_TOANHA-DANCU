<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. user_sessions: Composite index phục vụ xác thực Bearer token nhanh O(1)
        if (Schema::hasTable('user_sessions')) {
            $existing = collect(Schema::getIndexes('user_sessions'))->pluck('name')->all();
            if (! in_array('idx_sessions_auth_lookup', $existing, true)) {
                Schema::table('user_sessions', function (Blueprint $table) {
                    $table->index(['refresh_token_hash', 'is_revoked', 'expires_at'], 'idx_sessions_auth_lookup');
                });
            }
        }

        // 2. residents: Indexes phục vụ list pagination (tránh filesort), tìm chủ hộ, tra cứu theo user
        if (Schema::hasTable('residents')) {
            $existing = collect(Schema::getIndexes('residents'))->pluck('name')->all();

            if (! in_array('idx_residents_active_stay', $existing, true)) {
                Schema::table('residents', function (Blueprint $table) {
                    $table->index(['is_active', 'stay_start_date'], 'idx_residents_active_stay');
                });
            }

            if (! in_array('idx_residents_user_active', $existing, true)) {
                Schema::table('residents', function (Blueprint $table) {
                    $table->index(['user_id', 'is_active'], 'idx_residents_user_active');
                });
            }

            if (! in_array('idx_residents_apt_active_head', $existing, true)) {
                Schema::table('residents', function (Blueprint $table) {
                    $table->index(['apartment_id', 'is_active', 'is_head_of_household'], 'idx_residents_apt_active_head');
                });
            }
        }

        // 3. invoices: Indexes phục vụ tra cứu hóa đơn căn hộ và theo dõi quá hạn
        if (Schema::hasTable('invoices')) {
            $existing = collect(Schema::getIndexes('invoices'))->pluck('name')->all();

            if (! in_array('idx_invoices_apt_due', $existing, true)) {
                Schema::table('invoices', function (Blueprint $table) {
                    $table->index(['apartment_id', 'due_date'], 'idx_invoices_apt_due');
                });
            }

            if (! in_array('idx_invoices_status_due', $existing, true)) {
                Schema::table('invoices', function (Blueprint $table) {
                    $table->index(['status', 'due_date'], 'idx_invoices_status_due');
                });
            }
        }

        // 4. tickets: Indexes phục vụ SLA dashboard, resident portal tickets, căn hộ
        if (Schema::hasTable('tickets')) {
            $existing = collect(Schema::getIndexes('tickets'))->pluck('name')->all();

            if (! in_array('idx_tickets_status_sla', $existing, true)) {
                Schema::table('tickets', function (Blueprint $table) {
                    $table->index(['status', 'sla_deadline'], 'idx_tickets_status_sla');
                });
            }

            if (! in_array('idx_tickets_creator_created', $existing, true)) {
                Schema::table('tickets', function (Blueprint $table) {
                    $table->index(['creator_user_id', 'created_at'], 'idx_tickets_creator_created');
                });
            }

            if (! in_array('idx_tickets_apt_created', $existing, true)) {
                Schema::table('tickets', function (Blueprint $table) {
                    $table->index(['apartment_id', 'created_at'], 'idx_tickets_apt_created');
                });
            }
        }

        // 5. amenity_bookings: Indexes phục vụ lịch đặt tiện ích theo căn hộ và ngày
        if (Schema::hasTable('amenity_bookings')) {
            $existing = collect(Schema::getIndexes('amenity_bookings'))->pluck('name')->all();

            if (! in_array('idx_bookings_apt_date_time', $existing, true)) {
                Schema::table('amenity_bookings', function (Blueprint $table) {
                    $table->index(['apartment_id', 'booking_date', 'start_time'], 'idx_bookings_apt_date_time');
                });
            }

            if (! in_array('idx_bookings_date', $existing, true)) {
                Schema::table('amenity_bookings', function (Blueprint $table) {
                    $table->index(['booking_date'], 'idx_bookings_date');
                });
            }
        }

        // 6. visitor_registrations & checkin_logs: Indexes phục vụ lễ tân và bảo vệ tra cứu khách
        if (Schema::hasTable('visitor_registrations')) {
            $existing = collect(Schema::getIndexes('visitor_registrations'))->pluck('name')->all();

            if (! in_array('idx_visitors_apt_expected', $existing, true)) {
                Schema::table('visitor_registrations', function (Blueprint $table) {
                    $table->index(['apartment_id', 'expected_arrival_time'], 'idx_visitors_apt_expected');
                });
            }
        }

        if (Schema::hasTable('visitor_checkin_logs')) {
            $existing = collect(Schema::getIndexes('visitor_checkin_logs'))->pluck('name')->all();

            if (! in_array('idx_checkin_logs_checkout', $existing, true)) {
                Schema::table('visitor_checkin_logs', function (Blueprint $table) {
                    $table->index(['checkout_time'], 'idx_checkin_logs_checkout');
                });
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('user_sessions')) {
            $existing = collect(Schema::getIndexes('user_sessions'))->pluck('name')->all();
            if (in_array('idx_sessions_auth_lookup', $existing, true)) {
                Schema::table('user_sessions', function (Blueprint $table) {
                    $table->dropIndex('idx_sessions_auth_lookup');
                });
            }
        }

        if (Schema::hasTable('residents')) {
            $existing = collect(Schema::getIndexes('residents'))->pluck('name')->all();

            if (in_array('idx_residents_active_stay', $existing, true)) {
                Schema::table('residents', function (Blueprint $table) {
                    $table->dropIndex('idx_residents_active_stay');
                });
            }

            if (in_array('idx_residents_user_active', $existing, true)) {
                Schema::table('residents', function (Blueprint $table) {
                    $table->dropIndex('idx_residents_user_active');
                });
            }

            if (in_array('idx_residents_apt_active_head', $existing, true)) {
                Schema::table('residents', function (Blueprint $table) {
                    $table->dropIndex('idx_residents_apt_active_head');
                });
            }
        }

        if (Schema::hasTable('invoices')) {
            $existing = collect(Schema::getIndexes('invoices'))->pluck('name')->all();

            if (in_array('idx_invoices_apt_due', $existing, true)) {
                Schema::table('invoices', function (Blueprint $table) {
                    $table->dropIndex('idx_invoices_apt_due');
                });
            }

            if (in_array('idx_invoices_status_due', $existing, true)) {
                Schema::table('invoices', function (Blueprint $table) {
                    $table->dropIndex('idx_invoices_status_due');
                });
            }
        }

        if (Schema::hasTable('tickets')) {
            $existing = collect(Schema::getIndexes('tickets'))->pluck('name')->all();

            if (in_array('idx_tickets_status_sla', $existing, true)) {
                Schema::table('tickets', function (Blueprint $table) {
                    $table->dropIndex('idx_tickets_status_sla');
                });
            }

            if (in_array('idx_tickets_creator_created', $existing, true)) {
                Schema::table('tickets', function (Blueprint $table) {
                    $table->dropIndex('idx_tickets_creator_created');
                });
            }

            if (in_array('idx_tickets_apt_created', $existing, true)) {
                Schema::table('tickets', function (Blueprint $table) {
                    $table->dropIndex('idx_tickets_apt_created');
                });
            }
        }

        if (Schema::hasTable('amenity_bookings')) {
            $existing = collect(Schema::getIndexes('amenity_bookings'))->pluck('name')->all();

            if (in_array('idx_bookings_apt_date_time', $existing, true)) {
                Schema::table('amenity_bookings', function (Blueprint $table) {
                    $table->dropIndex('idx_bookings_apt_date_time');
                });
            }

            if (in_array('idx_bookings_date', $existing, true)) {
                Schema::table('amenity_bookings', function (Blueprint $table) {
                    $table->dropIndex('idx_bookings_date');
                });
            }
        }

        if (Schema::hasTable('visitor_registrations')) {
            $existing = collect(Schema::getIndexes('visitor_registrations'))->pluck('name')->all();

            if (in_array('idx_visitors_apt_expected', $existing, true)) {
                Schema::table('visitor_registrations', function (Blueprint $table) {
                    $table->dropIndex('idx_visitors_apt_expected');
                });
            }
        }

        if (Schema::hasTable('visitor_checkin_logs')) {
            $existing = collect(Schema::getIndexes('visitor_checkin_logs'))->pluck('name')->all();

            if (in_array('idx_checkin_logs_checkout', $existing, true)) {
                Schema::table('visitor_checkin_logs', function (Blueprint $table) {
                    $table->dropIndex('idx_checkin_logs_checkout');
                });
            }
        }
    }
};

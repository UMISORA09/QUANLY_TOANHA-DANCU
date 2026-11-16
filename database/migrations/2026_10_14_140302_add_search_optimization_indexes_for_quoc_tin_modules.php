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
        // 1. visitor_checkin_logs: Index phục vụ JOIN vcl.registration_id và ORDER BY checkin_time DESC
        if (Schema::hasTable('visitor_checkin_logs') && ! Schema::hasIndex('visitor_checkin_logs', 'idx_checkin_logs_reg_checkin')) {
            Schema::table('visitor_checkin_logs', function (Blueprint $table) {
                $table->index(['registration_id', 'checkin_time'], 'idx_checkin_logs_reg_checkin');
            });
        }

        // 2. visitor_registrations: Composite index phục vụ tính KPI thời gian thực & lọc trạng thái + ngày
        if (Schema::hasTable('visitor_registrations') && ! Schema::hasIndex('visitor_registrations', 'idx_visitors_status_expected')) {
            Schema::table('visitor_registrations', function (Blueprint $table) {
                $table->index(['qr_pass_status', 'expected_arrival_time'], 'idx_visitors_status_expected');
            });
        }

        // 3. parking_access_logs: Composite index phục vụ truy vấn 5 lượt quẹt thẻ gần nhất theo card_id
        if (Schema::hasTable('parking_access_logs') && ! Schema::hasIndex('parking_access_logs', 'idx_parking_logs_card_timestamp')) {
            Schema::table('parking_access_logs', function (Blueprint $table) {
                $table->index(['card_id', 'log_timestamp'], 'idx_parking_logs_card_timestamp');
            });
        }

        // 4. access_cards: Indexes phục vụ lọc thẻ theo căn hộ và cư dân
        if (Schema::hasTable('access_cards')) {
            if (! Schema::hasIndex('access_cards', 'idx_access_cards_apt_status')) {
                Schema::table('access_cards', function (Blueprint $table) {
                    $table->index(['assigned_apartment_id', 'status'], 'idx_access_cards_apt_status');
                });
            }

            if (! Schema::hasIndex('access_cards', 'idx_access_cards_user')) {
                Schema::table('access_cards', function (Blueprint $table) {
                    $table->index('assigned_user_id', 'idx_access_cards_user');
                });
            }
        }

        // 5. users: Indexes phục vụ UserController@index (lọc trạng thái và sắp xếp created_at desc)
        if (Schema::hasTable('users')) {
            if (! Schema::hasIndex('users', 'idx_users_status_created')) {
                Schema::table('users', function (Blueprint $table) {
                    $table->index(['status', 'created_at'], 'idx_users_status_created');
                });
            }

            if (! Schema::hasIndex('users', 'idx_users_created_at')) {
                Schema::table('users', function (Blueprint $table) {
                    $table->index('created_at', 'idx_users_created_at');
                });
            }
        }

        // 6. parcels: Composite index lọc căn hộ + ngày nhận và index tra cứu nhanh mã PIN
        if (Schema::hasTable('parcels')) {
            if (! Schema::hasIndex('parcels', 'idx_parcels_apt_received')) {
                Schema::table('parcels', function (Blueprint $table) {
                    $table->index(['apartment_id', 'received_at'], 'idx_parcels_apt_received');
                });
            }

            if (! Schema::hasIndex('parcels', 'idx_parcels_pin_code')) {
                Schema::table('parcels', function (Blueprint $table) {
                    $table->index('pickup_pin_code', 'idx_parcels_pin_code');
                });
            }
        }

        // 7. temporary_registrations: Index phục vụ sắp xếp mặc định created_at và lọc ngày
        if (Schema::hasTable('temporary_registrations')) {
            if (! Schema::hasIndex('temporary_registrations', 'idx_tempreg_created_at')) {
                Schema::table('temporary_registrations', function (Blueprint $table) {
                    $table->index('created_at', 'idx_tempreg_created_at');
                });
            }

            if (! Schema::hasIndex('temporary_registrations', 'idx_tempreg_dates')) {
                Schema::table('temporary_registrations', function (Blueprint $table) {
                    $table->index(['start_date', 'end_date'], 'idx_tempreg_dates');
                });
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('temporary_registrations')) {
            if (Schema::hasIndex('temporary_registrations', 'idx_tempreg_dates')) {
                Schema::table('temporary_registrations', function (Blueprint $table) {
                    $table->dropIndex('idx_tempreg_dates');
                });
            }

            if (Schema::hasIndex('temporary_registrations', 'idx_tempreg_created_at')) {
                Schema::table('temporary_registrations', function (Blueprint $table) {
                    $table->dropIndex('idx_tempreg_created_at');
                });
            }
        }

        if (Schema::hasTable('parcels')) {
            if (Schema::hasIndex('parcels', 'idx_parcels_pin_code')) {
                Schema::table('parcels', function (Blueprint $table) {
                    $table->dropIndex('idx_parcels_pin_code');
                });
            }

            if (Schema::hasIndex('parcels', 'idx_parcels_apt_received')) {
                Schema::table('parcels', function (Blueprint $table) {
                    $table->dropIndex('idx_parcels_apt_received');
                });
            }
        }

        if (Schema::hasTable('users')) {
            if (Schema::hasIndex('users', 'idx_users_created_at')) {
                Schema::table('users', function (Blueprint $table) {
                    $table->dropIndex('idx_users_created_at');
                });
            }

            if (Schema::hasIndex('users', 'idx_users_status_created')) {
                Schema::table('users', function (Blueprint $table) {
                    $table->dropIndex('idx_users_status_created');
                });
            }
        }

        if (Schema::hasTable('access_cards')) {
            if (Schema::hasIndex('access_cards', 'idx_access_cards_user')) {
                Schema::table('access_cards', function (Blueprint $table) {
                    $table->dropIndex('idx_access_cards_user');
                });
            }

            if (Schema::hasIndex('access_cards', 'idx_access_cards_apt_status')) {
                Schema::table('access_cards', function (Blueprint $table) {
                    $table->dropIndex('idx_access_cards_apt_status');
                });
            }
        }

        if (Schema::hasTable('parking_access_logs') && Schema::hasIndex('parking_access_logs', 'idx_parking_logs_card_timestamp')) {
            Schema::table('parking_access_logs', function (Blueprint $table) {
                $table->dropIndex('idx_parking_logs_card_timestamp');
            });
        }

        if (Schema::hasTable('visitor_registrations') && Schema::hasIndex('visitor_registrations', 'idx_visitors_status_expected')) {
            Schema::table('visitor_registrations', function (Blueprint $table) {
                $table->dropIndex('idx_visitors_status_expected');
            });
        }

        if (Schema::hasTable('visitor_checkin_logs') && Schema::hasIndex('visitor_checkin_logs', 'idx_checkin_logs_reg_checkin')) {
            Schema::table('visitor_checkin_logs', function (Blueprint $table) {
                $table->dropIndex('idx_checkin_logs_reg_checkin');
            });
        }
    }
};

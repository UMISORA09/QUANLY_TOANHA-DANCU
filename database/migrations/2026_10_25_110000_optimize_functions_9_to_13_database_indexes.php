<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Tối ưu hóa Database Indexes cho các Chức năng 9–13 (Bưu phẩm, Web Push/Email Log, Dashboard)
     */
    public function up(): void
    {
        // 1. Tối ưu bảng parcels: Bổ sung composite index (status, collected_at)
        // Phục vụ truy vấn bàn giao bưu phẩm và thống kê xu hướng theo giờ / ngày trong Dashboard
        if (Schema::hasTable('parcels')) {
            Schema::table('parcels', function (Blueprint $table) {
                if (! Schema::hasIndex('parcels', 'idx_parcels_status_collected')) {
                    $table->index(['status', 'collected_at'], 'idx_parcels_status_collected');
                }
            });
        }

        // 2. Tối ưu bảng web_push_subscriptions: Loại bỏ index đơn trùng lặp user_id
        // Đã được bao phủ 100% bởi composite index web_push_subscriptions_user_id_is_active_index (user_id, is_active)
        if (Schema::hasTable('web_push_subscriptions')) {
            Schema::table('web_push_subscriptions', function (Blueprint $table) {
                if (Schema::hasIndex('web_push_subscriptions', 'web_push_subscriptions_user_id_index')) {
                    $table->dropIndex('web_push_subscriptions_user_id_index');
                }
            });
        }

        // 3. Tối ưu bảng parcel_notification_logs: Loại bỏ index đơn trùng lặp parcel_id
        // Đã được bao phủ 100% bởi composite unique index uq_parcel_notif_chan_dest (parcel_id, channel, destination)
        if (Schema::hasTable('parcel_notification_logs')) {
            Schema::table('parcel_notification_logs', function (Blueprint $table) {
                if (Schema::hasIndex('parcel_notification_logs', 'parcel_notification_logs_parcel_id_index')) {
                    $table->dropIndex('parcel_notification_logs_parcel_id_index');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('parcels')) {
            Schema::table('parcels', function (Blueprint $table) {
                if (Schema::hasIndex('parcels', 'idx_parcels_status_collected')) {
                    $table->dropIndex('idx_parcels_status_collected');
                }
            });
        }

        if (Schema::hasTable('web_push_subscriptions')) {
            Schema::table('web_push_subscriptions', function (Blueprint $table) {
                if (! Schema::hasIndex('web_push_subscriptions', 'web_push_subscriptions_user_id_index')) {
                    $table->index('user_id', 'web_push_subscriptions_user_id_index');
                }
            });
        }

        if (Schema::hasTable('parcel_notification_logs')) {
            Schema::table('parcel_notification_logs', function (Blueprint $table) {
                if (! Schema::hasIndex('parcel_notification_logs', 'parcel_notification_logs_parcel_id_index')) {
                    $table->index('parcel_id', 'parcel_notification_logs_parcel_id_index');
                }
            });
        }
    }
};

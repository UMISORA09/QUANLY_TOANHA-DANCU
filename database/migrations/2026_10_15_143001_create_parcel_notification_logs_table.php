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
        if (! Schema::hasTable('parcel_notification_logs')) {
            Schema::create('parcel_notification_logs', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('parcel_id')->index();
                $table->uuid('recipient_user_id')->nullable()->index();
                $table->string('channel', 30)->index(); // 'EMAIL', 'WEB_PUSH'
                $table->string('destination', 255); // email address or endpoint hash
                $table->string('status', 30)->default('PENDING')->index(); // 'PENDING', 'SENT', 'FAILED'
                $table->unsignedInteger('attempts')->default(1);
                $table->text('error_message')->nullable();
                $table->timestamp('sent_at')->nullable();
                $table->timestamps();

                $table->foreign('parcel_id')->references('id')->on('parcels')->cascadeOnDelete();
                $table->foreign('recipient_user_id')->references('id')->on('users')->nullOnDelete();

                // Chống gửi trùng theo kiện hàng, kênh thông báo và địa chỉ đích
                $table->unique(['parcel_id', 'channel', 'destination'], 'uq_parcel_notif_chan_dest');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('parcel_notification_logs');
    }
};

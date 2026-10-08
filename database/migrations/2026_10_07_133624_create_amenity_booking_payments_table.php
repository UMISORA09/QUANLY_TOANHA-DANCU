<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $targetCollation = null;
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            try {
                $column = DB::selectOne(
                    "SELECT COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'amenity_bookings' AND COLUMN_NAME = 'id'"
                );
                $targetCollation = $column?->COLLATION_NAME;
            } catch (Throwable) {
                // Tiếp tục với cấu hình mặc định nếu không truy vấn được information_schema
            }
        }

        Schema::create('amenity_booking_payments', function (Blueprint $table) use ($targetCollation) {
            if ($targetCollation) {
                $table->collation = $targetCollation;
            }

            $table->uuid('id')->primary();

            $bookingIdCol = $table->uuid('booking_id')->unique();
            if ($targetCollation) {
                $bookingIdCol->collation($targetCollation);
            }
            $table->foreign('booking_id')->references('id')->on('amenity_bookings');

            $table->string('reference', 40)->unique();
            $table->decimal('amount', 14, 0);
            $table->string('bank_bin', 10);
            $table->string('bank_name', 80);
            $table->string('account_number', 50);
            $table->string('account_name', 150);
            $table->string('status', 30);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('reported_at')->nullable();
            $table->string('bank_transaction_id', 100)->nullable();
            $table->decimal('received_amount', 14, 0)->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();

            $confirmedByCol = $table->uuid('confirmed_by_user_id')->nullable();
            if ($targetCollation) {
                $confirmedByCol->collation($targetCollation);
            }
            $table->foreign('confirmed_by_user_id')->references('id')->on('users');

            $table->string('review_reason', 500)->nullable();
            $table->boolean('refund_required')->default(false);
            $table->timestamps();
            $table->unique(['bank_bin', 'bank_transaction_id'], 'amenity_payment_bank_transaction_unique');
            $table->index(['status', 'expires_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('amenity_booking_payments');
    }
};

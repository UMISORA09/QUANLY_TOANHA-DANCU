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
        if (! Schema::hasTable('debt_reminder_logs')) {
            Schema::create('debt_reminder_logs', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('invoice_id')->index();
                $table->uuid('apartment_id')->index();
                $table->string('recipient_email', 150);
                $table->string('recipient_name', 100);
                $table->string('reminder_type', 30)->default('EMAIL');
                $table->decimal('debt_amount', 12, 2);
                $table->date('due_date');
                $table->integer('days_overdue')->default(0);
                $table->string('channel_status', 30)->default('QUEUED')->index();
                $table->text('error_message')->nullable();
                $table->timestamp('sent_at')->nullable();
                $table->uuid('sent_by_user_id')->nullable()->index();
                $table->timestamps();

                $table->foreign('invoice_id')->references('id')->on('invoices')->cascadeOnDelete();
                $table->foreign('apartment_id')->references('id')->on('apartments')->cascadeOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('debt_reminder_logs');
    }
};

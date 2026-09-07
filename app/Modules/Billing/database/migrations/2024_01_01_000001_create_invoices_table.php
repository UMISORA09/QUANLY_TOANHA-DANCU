<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tạo bảng invoices — Hóa đơn.
 * Module: Billing (Hòa)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('resident_id')->index();
            $table->unsignedBigInteger('apartment_id')->index();
            $table->unsignedTinyInteger('month');
            $table->unsignedSmallInteger('year');
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->string('status', 20)->default('draft');
            $table->date('due_date')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['apartment_id', 'month', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};

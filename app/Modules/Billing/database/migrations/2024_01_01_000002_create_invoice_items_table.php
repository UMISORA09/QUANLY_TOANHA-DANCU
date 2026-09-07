<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tạo bảng invoice_items — Chi tiết hóa đơn.
 * Module: Billing (Hòa)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->string('type', 30); // electric, water, service, parking, other
            $table->string('description')->nullable();
            $table->decimal('quantity', 10, 2)->default(0);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->decimal('amount', 15, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_items');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tạo bảng unit_prices — Cài đặt đơn giá.
 * Module: Billing (Hòa)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unit_prices', function (Blueprint $table) {
            $table->id();
            $table->string('type', 30); // electric, water, service, parking
            $table->string('label');
            $table->decimal('price_per_unit', 12, 2);
            $table->string('unit', 20)->default('kWh'); // kWh, m3, tháng, ...
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['type', 'is_active', 'effective_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unit_prices');
    }
};

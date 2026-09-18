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
        Schema::create('zones', function (Blueprint $table) {
            $table->id();
            $table->string('zone_code', 50)->unique()->comment('Mã khối tòa nhà (ví dụ: BLOCK_A, BLOCK_B)');
            $table->string('zone_name', 150)->comment('Tên khối tòa nhà (ví dụ: Tòa Nhà A - Ruby Tower)');
            $table->unsignedInteger('floor_count')->comment('Số tầng nổi (bắt buộc > 0)');
            $table->unsignedInteger('basement_count')->default(1)->comment('Số tầng hầm');
            $table->unsignedInteger('total_apartments')->default(0)->comment('Tổng số căn hộ dự kiến');
            $table->string('status', 30)->default('ACTIVE')->comment('Trạng thái: ACTIVE, MAINTENANCE, INACTIVE');
            $table->text('address_line')->nullable()->comment('Địa chỉ/Vị trí khối nhà trong khuôn viên');
            $table->string('hotline_phone', 30)->nullable()->comment('Hotline lễ tân/quản lý khối');
            $table->text('description')->nullable()->comment('Mô tả chi tiết / Ghi chú kỹ thuật');
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('zones');
    }
};

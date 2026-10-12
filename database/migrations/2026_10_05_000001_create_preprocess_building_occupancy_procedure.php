<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Tạo Stored Procedure tiền xử lý (Preprocess) thống kê số liệu căn hộ và tầng trực tiếp trong MySQL.
     */
    public function up(): void
    {
        // Chỉ chạy trên MySQL / MariaDB engine
        if (DB::getDriverName() === 'mysql') {
            DB::unprepared('DROP PROCEDURE IF EXISTS sp_preprocess_building_occupancy');

            DB::unprepared('
                CREATE PROCEDURE sp_preprocess_building_occupancy(IN target_block_id CHAR(36))
                BEGIN
                    -- 1. Tiền xử lý cập nhật tổng số căn (total_units) cho từng tầng
                    IF target_block_id IS NOT NULL AND target_block_id != "" THEN
                        UPDATE floors f
                        LEFT JOIN (
                            SELECT floor_id, COUNT(*) AS unit_count
                            FROM apartments
                            WHERE deleted_at IS NULL
                            GROUP BY floor_id
                        ) a ON f.id = a.floor_id
                        SET f.total_units = COALESCE(a.unit_count, 0)
                        WHERE f.block_id = target_block_id;
                    ELSE
                        UPDATE floors f
                        LEFT JOIN (
                            SELECT floor_id, COUNT(*) AS unit_count
                            FROM apartments
                            WHERE deleted_at IS NULL
                            GROUP BY floor_id
                        ) a ON f.id = a.floor_id
                        SET f.total_units = COALESCE(a.unit_count, 0);
                    END IF;
                END
            ');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::unprepared('DROP PROCEDURE IF EXISTS sp_preprocess_building_occupancy');
        }
    }
};

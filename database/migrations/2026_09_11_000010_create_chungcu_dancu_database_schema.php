<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Danh sách 109 bảng theo thứ tự tạo trong CSDL_CHUNGCU&DANCU.sql
     */
    protected array $tables = [
        'external_providers', 'outbound_api_logs', 'inbound_webhooks', 'iot_devices',
        'roles', 'permissions', 'role_permissions', 'users', 'user_roles', 'user_sessions', 'api_keys',
        'blocks', 'floors', 'apartments', 'apartment_owners', 'residents', 'temporary_registrations',
        'emergency_contacts', 'pets', 'move_records', 'resident_ekyc_logs',
        'contracts', 'contract_signatures', 'contract_deposits', 'contract_addendums',
        'service_pricing_configs', 'pricing_tiers', 'meters', 'meter_reading_batches', 'meter_readings',
        'invoice_generation_batches', 'invoices', 'invoice_items', 'invoice_adjustments',
        'payments', 'payment_receipts', 'debt_reminder_queue',
        'amenity_categories', 'amenities', 'amenity_time_slots', 'amenity_blackouts', 'amenity_bookings',
        'ticket_categories', 'tickets', 'ticket_attachments', 'ticket_comments', 'ticket_history', 'ticket_ratings',
        'announcements', 'announcement_attachments', 'announcement_reads', 'notification_templates', 'user_in_app_notifications',
        'community_spaces', 'community_memberships', 'forum_categories', 'forum_posts', 'forum_comments', 'forum_reactions',
        'community_polls', 'community_poll_options', 'community_poll_votes', 'suggestion_boxes', 'suggestions',
        'satisfaction_surveys', 'survey_questions', 'survey_responses', 'survey_response_details',
        'vehicles', 'parking_slots', 'access_cards', 'parking_access_logs',
        'visitor_registrations', 'visitor_checkin_logs', 'security_blacklist',
        'smart_lockers', 'locker_compartments', 'parcels',
        'patrol_checkpoints', 'patrol_routes', 'patrol_route_checkpoints', 'patrol_logs', 'patrol_scans',
        'security_incidents', 'lost_and_found', 'building_assets', 'asset_maintenance_logs',
        'departments', 'staff_profiles', 'shift_types', 'staff_schedules', 'staff_attendance', 'staff_tasks', 'shift_handover_logs',
        'ai_models', 'ai_prompts', 'ai_knowledge_documents', 'ai_knowledge_chunks', 'ai_conversations', 'ai_messages',
        'ai_ocr_logs', 'ai_ticket_triages', 'ai_anomaly_alerts',
        'audit_logs', 'system_configs', 'feature_flags', 'scheduled_jobs', 'scheduled_job_logs', 'system_error_logs'
    ];

    /**
     * Danh sách các Views phân tích
     */
    protected array $views = [
        'v_revenue_by_block_and_period',
        'v_revenue_by_service_type',
        'v_apartment_occupancy_rate',
        'v_receptionist_live_dashboard',
        'v_security_live_dashboard',
        'v_ticket_sla_performance'
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('external_providers')) {
            return;
        }

        $driver = DB::getDriverName();
        
        // Tìm tệp SQL
        $sqlPath = base_path('database/schema/CSDL_CHUNGCU_DANCU.sql');
        if (!file_exists($sqlPath)) {
            $sqlPath = base_path('CSDL_CHUNGCU&DANCU.sql');
        }

        if (!file_exists($sqlPath)) {
            throw new \RuntimeException("Không tìm thấy tệp CSDL_CHUNGCU&DANCU.sql để nạp migration!");
        }

        $rawSql = file_get_contents($sqlPath);
        $adaptedSql = $this->adaptSqlForDriver($rawSql, $driver);

        // Tách thành từng câu lệnh thực thi
        $statements = preg_split('/;\s*(\r?\n|$)/', $adaptedSql);

        if ($driver === 'mysql') {
            DB::statement('SET FOREIGN_KEY_CHECKS = 0;');
        }

        foreach ($statements as $stmt) {
            $trimmed = trim($stmt);
            $cleanCode = preg_replace('/\/\*.*?\*\//s', '', $trimmed);
            $cleanCode = preg_replace('/--.*$/m', '', $cleanCode);
            if (trim($cleanCode) === '') {
                continue;
            }

            try {
                DB::unprepared($trimmed);
            } catch (\Throwable $e) {
                $msg = $e->getMessage();
                if (
                    str_contains($msg, 'already exists') ||
                    str_contains($msg, 'already an object named') ||
                    str_contains($msg, 'Duplicate key name') ||
                    str_contains($msg, 'Duplicate column name') ||
                    str_contains($msg, 'Duplicate entry')
                ) {
                    continue;
                }
                throw $e;
            }
        }

        if ($driver === 'mysql') {
            DB::statement('SET FOREIGN_KEY_CHECKS = 1;');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = DB::getDriverName();
        if ($driver === 'mysql') {
            DB::statement('SET FOREIGN_KEY_CHECKS = 0;');
        }

        // 1. Xóa các Views
        foreach ($this->views as $view) {
            DB::statement("DROP VIEW IF EXISTS {$view}");
        }

        // 2. Xóa các bảng theo thứ tự ngược lại (để tránh lỗi khóa ngoại)
        $reversedTables = array_reverse($this->tables);
        foreach ($reversedTables as $table) {
            Schema::dropIfExists($table);
        }

        if ($driver === 'mysql') {
            DB::statement('SET FOREIGN_KEY_CHECKS = 1;');
        }
    }

    /**
     * Chuyển đổi cú pháp T-SQL sang tương thích với Driver đang chạy (SQLite, MySQL, SQL Server)
     */
    protected function adaptSqlForDriver(string $sql, string $driver): string
    {
        // 1. Xóa các lệnh tạo database master và batch separator của SSMS
        $sql = preg_replace('/IF DB_ID\(.*?\)\s*IS NULL\s*BEGIN.*?END;/s', '', $sql);
        $sql = preg_replace('/^\s*USE\s+\[.*?\];?\s*$/m', '', $sql);
        $sql = preg_replace('/^\s*SET\s+.*?;?\s*$/m', '', $sql);
        $sql = preg_replace('/^\s*GO\s*$/m', '', $sql);

        // 2. Xóa các khối duplicate IF OBJECT_ID
        $sql = preg_replace('/IF OBJECT_ID\(.*?\)\s*IS NULL\s*BEGIN.*?END;?/s', '', $sql);

        // 3. Nếu không phải sqlsrv, bỏ các triggers/functions đặc thù của T-SQL
        if ($driver !== 'sqlsrv') {
            $sql = preg_replace('/CREATE\s+OR\s+ALTER\s+FUNCTION\s+.*?;\s*GO\b/s', '', $sql);
            $sql = preg_replace('/CREATE\s+OR\s+ALTER\s+FUNCTION\s+.*?RETURN\s+@\w+\s*;\s*END\s*;/si', '', $sql);
            $sql = preg_replace('/CREATE\s+OR\s+ALTER\s+TRIGGER\s+.*?;\s*GO\b/s', '', $sql);
            $sql = preg_replace('/CREATE\s+OR\s+ALTER\s+TRIGGER\s+.*?END\s*;/si', '', $sql);
            $sql = preg_replace('/IF\s+EXISTS\s*\(SELECT\s+1\s+FROM\s+sys\.triggers.*?\)\s*BEGIN.*?END;?/si', '', $sql);
        }

        // 4. Chuyển đổi kiểu dữ liệu cho SQLite
        if ($driver === 'sqlite') {
            $sql = preg_replace('/CREATE\s+OR\s+ALTER\s+VIEW/i', 'CREATE VIEW IF NOT EXISTS', $sql);
            $sql = preg_replace('/\bUNIQUEIDENTIFIER\s+PRIMARY\s+KEY\s+DEFAULT\s+NEWID\(\)/i', 'TEXT PRIMARY KEY', $sql);
            $sql = preg_replace('/\bUNIQUEIDENTIFIER\s+DEFAULT\s+NEWID\(\)/i', 'TEXT', $sql);
            $sql = preg_replace('/\bDEFAULT\s+NEWID\(\)/i', '', $sql);
            $sql = preg_replace('/\bUNIQUEIDENTIFIER\b/i', 'TEXT', $sql);
            $sql = preg_replace('/\bNVARCHAR\(MAX\)/i', 'TEXT', $sql);
            $sql = preg_replace('/\bVARCHAR\(MAX\)/i', 'TEXT', $sql);
            $sql = preg_replace('/\bDATETIME2\(\d+\)/i', 'DATETIME', $sql);
            $sql = preg_replace('/\bDATETIME2\b/i', 'DATETIME', $sql);
            $sql = preg_replace('/\bBIT\b/i', 'INTEGER', $sql);
            
            $sql = preg_replace("/N'((?:''|[^'])*)'/", "'$1'", $sql);
            $sql = preg_replace("/DEFAULT\s+N?'(\[\]|\{\})'/i", "DEFAULT ('$1')", $sql);
            $sql = preg_replace("/DEFAULT\s+'(\[\]|\{\})'/i", "DEFAULT ('$1')", $sql);

            $sql = preg_replace('/\bdbo\./i', '', $sql);
        }

        // 5. Chuyển đổi kiểu dữ liệu cho MySQL
        if ($driver === 'mysql') {
            $sql = preg_replace('/CREATE\s+OR\s+ALTER\s+VIEW/i', 'CREATE OR REPLACE VIEW', $sql);
            $sql = preg_replace('/\bCAST\(GETDATE\(\)\s+AS\s+DATE\)/i', 'CURDATE()', $sql);
            $sql = preg_replace('/\bGETDATE\(\)/i', 'NOW()', $sql);

            $sql = preg_replace('/\bUNIQUEIDENTIFIER\s+PRIMARY\s+KEY\s+DEFAULT\s+NEWID\(\)/i', 'CHAR(36) PRIMARY KEY DEFAULT (UUID())', $sql);
            $sql = preg_replace('/\bUNIQUEIDENTIFIER\s+DEFAULT\s+NEWID\(\)/i', 'CHAR(36) DEFAULT (UUID())', $sql);
            $sql = preg_replace('/\bDEFAULT\s+NEWID\(\)/i', 'DEFAULT (UUID())', $sql);
            $sql = preg_replace('/\bUNIQUEIDENTIFIER\b/i', 'CHAR(36)', $sql);

            // Bọc default của LONGTEXT trong dấu ngoặc tròn để tương thích chuẩn biểu thức MySQL
            $sql = preg_replace("/\b(NVARCHAR\(MAX\)|VARCHAR\(MAX\)|TEXT)\s+NOT\s+NULL\s+DEFAULT\s+N?'([^']*)'/i", "LONGTEXT NOT NULL DEFAULT ('$2')", $sql);
            $sql = preg_replace("/\b(NVARCHAR\(MAX\)|VARCHAR\(MAX\)|TEXT)\s+DEFAULT\s+N?'([^']*)'/i", "LONGTEXT DEFAULT ('$2')", $sql);
            $sql = preg_replace('/\bNVARCHAR\(MAX\)/i', 'LONGTEXT', $sql);
            $sql = preg_replace('/\bVARCHAR\(MAX\)/i', 'LONGTEXT', $sql);

            $sql = preg_replace('/\bDATETIME2\(\d+\)/i', 'DATETIME', $sql);
            $sql = preg_replace('/\bDATETIME2\b/i', 'DATETIME', $sql);
            $sql = preg_replace('/\bBIT\b/i', 'TINYINT(1)', $sql);

            $sql = preg_replace("/N'((?:''|[^'])*)'/", "'$1'", $sql);
            $sql = preg_replace('/\bdbo\./i', '', $sql);

            // Bỏ mệnh đề WHERE trong partial index của T-SQL không hỗ trợ trên MySQL
            $sql = preg_replace('/(CREATE\s+(?:UNIQUE\s+)?INDEX\s+[\w_]+\s+ON\s+[\w_]+\s*\([^)]+\))\s+WHERE\s+[^;]+/i', '$1', $sql);
        }

        return $sql;
    }
};

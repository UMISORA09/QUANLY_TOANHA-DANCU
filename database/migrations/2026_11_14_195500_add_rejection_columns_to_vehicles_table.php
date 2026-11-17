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
        if (Schema::hasTable('vehicles')) {
            Schema::table('vehicles', function (Blueprint $table) {
                if (! Schema::hasColumn('vehicles', 'rejection_reason')) {
                    $table->text('rejection_reason')->nullable()->after('approved_at');
                }
                if (! Schema::hasColumn('vehicles', 'rejected_by')) {
                    $table->char('rejected_by', 36)->nullable()->after('rejection_reason');
                }
                if (! Schema::hasColumn('vehicles', 'rejected_at')) {
                    $table->dateTime('rejected_at')->nullable()->after('rejected_by');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('vehicles')) {
            Schema::table('vehicles', function (Blueprint $table) {
                if (Schema::hasColumn('vehicles', 'rejected_at')) {
                    $table->dropColumn('rejected_at');
                }
                if (Schema::hasColumn('vehicles', 'rejected_by')) {
                    $table->dropColumn('rejected_by');
                }
                if (Schema::hasColumn('vehicles', 'rejection_reason')) {
                    $table->dropColumn('rejection_reason');
                }
            });
        }
    }
};

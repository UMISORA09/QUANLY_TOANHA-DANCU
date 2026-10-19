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
        Schema::table('tickets', function (Blueprint $table) {
            if (! Schema::hasColumn('tickets', 'location')) {
                $table->string('location', 255)->nullable()->after('description');
            }
            if (! Schema::hasColumn('tickets', 'version')) {
                $table->unsignedInteger('version')->default(1)->after('closed_at');
            }
        });

        Schema::table('ticket_attachments', function (Blueprint $table) {
            if (! Schema::hasColumn('ticket_attachments', 'storage_path')) {
                $table->string('storage_path', 500)->nullable()->after('file_url');
            }
            if (! Schema::hasColumn('ticket_attachments', 'mime_type')) {
                $table->string('mime_type', 100)->nullable()->after('file_type');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            if (Schema::hasColumn('tickets', 'location')) {
                $table->dropColumn('location');
            }
            if (Schema::hasColumn('tickets', 'version')) {
                $table->dropColumn('version');
            }
        });

        Schema::table('ticket_attachments', function (Blueprint $table) {
            if (Schema::hasColumn('ticket_attachments', 'storage_path')) {
                $table->dropColumn('storage_path');
            }
            if (Schema::hasColumn('ticket_attachments', 'mime_type')) {
                $table->dropColumn('mime_type');
            }
        });
    }
};

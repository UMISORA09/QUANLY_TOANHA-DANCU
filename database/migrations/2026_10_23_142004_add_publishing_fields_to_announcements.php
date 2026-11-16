<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('announcements', function (Blueprint $table): void {
            $table->string('status', 20)->default('PUBLISHED');
            $table->unsignedInteger('version')->default(1);
        });
        Schema::table('announcement_attachments', function (Blueprint $table): void {
            $table->string('storage_disk', 30)->nullable();
            $table->string('mime_type', 100)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('announcement_attachments', fn (Blueprint $table) => $table->dropColumn(['storage_disk', 'mime_type']));
        Schema::table('announcements', fn (Blueprint $table) => $table->dropColumn(['status', 'version']));
    }
};

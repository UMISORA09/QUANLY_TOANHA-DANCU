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
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'google_id')) {
                $table->string('google_id', 100)->nullable()->unique()->after('email');
            }
            if (! Schema::hasColumn('users', 'facebook_id')) {
                $table->string('facebook_id', 100)->nullable()->unique()->after('google_id');
            }
            if (! Schema::hasColumn('users', 'oauth_provider')) {
                $table->string('oauth_provider', 50)->nullable()->after('facebook_id');
            }
            $table->string('phone_number', 20)->nullable()->change();
            $table->string('password_hash', 255)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $colsToDrop = [];
            if (Schema::hasColumn('users', 'google_id')) {
                $colsToDrop[] = 'google_id';
            }
            if (Schema::hasColumn('users', 'facebook_id')) {
                $colsToDrop[] = 'facebook_id';
            }
            if (Schema::hasColumn('users', 'oauth_provider')) {
                $colsToDrop[] = 'oauth_provider';
            }
            if (! empty($colsToDrop)) {
                $table->dropColumn($colsToDrop);
            }
        });
    }
};

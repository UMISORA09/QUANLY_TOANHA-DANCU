<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        Schema::table('users', function (Blueprint $table) use ($driver) {
            if (! Schema::hasColumn('users', 'google_id')) {
                $table->string('google_id', 100)->nullable()->after('email');
                if ($driver !== 'sqlite') {
                    $table->unique('google_id');
                }
            }
            if (! Schema::hasColumn('users', 'facebook_id')) {
                $table->string('facebook_id', 100)->nullable()->after('google_id');
                if ($driver !== 'sqlite') {
                    $table->unique('facebook_id');
                }
            }
            if (! Schema::hasColumn('users', 'oauth_provider')) {
                $table->string('oauth_provider', 50)->nullable()->after('facebook_id');
            }
            if ($driver !== 'sqlite') {
                $table->string('phone_number', 20)->nullable()->change();
                $table->string('password_hash', 255)->nullable()->change();
            }
        });

        if ($driver === 'sqlite') {
            try {
                DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS users_google_id_unique ON users(google_id) WHERE google_id IS NOT NULL');
                DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS users_facebook_id_unique ON users(facebook_id) WHERE facebook_id IS NOT NULL');
            } catch (Throwable $e) {
                // Ignore if already exists
            }
        }
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

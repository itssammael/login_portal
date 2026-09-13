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
            $table->boolean('is_admin')->default(false)->index()->after('remember_token');
            $table->boolean('is_banned')->default(false)->after('is_admin');
            $table->timestamp('banned_at')->nullable()->after('is_banned');
            $table->timestamp('last_seen_at')->nullable()->after('banned_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['is_admin', 'is_banned', 'banned_at', 'last_seen_at']);
        });
    }
};

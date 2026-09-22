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
        Schema::table('sso', function (Blueprint $table) {
            $table->string('api_url')->nullable()->after('redirect_uri')
                ->comment('Explicit base URL of the connected system for verify-credentials calls. Derived from redirect_uri if blank.');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sso', function (Blueprint $table) {
            $table->dropColumn('api_url');
        });
    }
};

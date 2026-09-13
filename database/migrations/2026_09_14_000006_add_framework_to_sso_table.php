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
            if (! Schema::hasColumn('sso', 'framework')) {
                $table->string('framework')->nullable()->default('laravel_inertia')->after('redirect_uri');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sso', function (Blueprint $table) {
            if (Schema::hasColumn('sso', 'framework')) {
                $table->dropColumn('framework');
            }
        });
    }
};

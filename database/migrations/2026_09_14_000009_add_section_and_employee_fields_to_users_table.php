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
            $table->foreignId('section_id')->nullable()->after('role_id')->constrained('sections')->nullOnDelete();
            $table->string('position')->nullable()->after('section_id');
            $table->string('employee_number')->nullable()->after('position');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['section_id']);
            $table->dropColumn(['section_id', 'position', 'employee_number']);
        });
    }
};

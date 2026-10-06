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
        Schema::create('fb_participants', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable(); // Nullable for anonymous participants
            $table->foreignId('function_id')->nullable()->constrained('fb_functions')->nullOnDelete();
            $table->string('agency')->nullable();
            $table->string('designation')->nullable();
            $table->unsignedTinyInteger('years_in_designation')->default(0);
            $table->string('location')->nullable();
            $table->unsignedTinyInteger('no_of_exercises')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fb_participants');
    }
};

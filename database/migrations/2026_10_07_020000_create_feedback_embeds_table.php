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
        Schema::create('feedback_embeds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')
                ->constrained('fb_events')
                ->cascadeOnDelete()
                ->unique();
            $table->string('public_id', 36)->unique()->index();
            $table->string('client_id', 64)->unique()->index();
            $table->string('client_secret');
            $table->json('allowed_origins')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('feedback_embeds');
    }
};

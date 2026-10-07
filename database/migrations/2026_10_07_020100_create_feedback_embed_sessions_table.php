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
        Schema::create('feedback_embed_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('embed_id')
                ->constrained('feedback_embeds')
                ->cascadeOnDelete();
            $table->string('token_hash', 64)->unique()->index();
            $table->string('respondent_id', 255)->nullable();
            $table->string('respondent_hash', 64)->nullable()->index();
            $table->json('metadata')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamp('used_at')->nullable()->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('feedback_embed_sessions');
    }
};

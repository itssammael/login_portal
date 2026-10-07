<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::rename('feedback_embeds', 'fb_embeds');
        Schema::rename('feedback_embed_sessions', 'fb_embed_sessions');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::rename('fb_embed_sessions', 'feedback_embed_sessions');
        Schema::rename('fb_embeds', 'feedback_embeds');
    }
};

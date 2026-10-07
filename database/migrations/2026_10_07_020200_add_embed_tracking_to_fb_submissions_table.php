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
        Schema::table('fb_submissions', function (Blueprint $table) {
            $table->foreignId('embed_id')
                ->nullable()
                ->after('participant_id')
                ->constrained('feedback_embeds')
                ->nullOnDelete();
            $table->foreignId('embed_session_id')
                ->nullable()
                ->after('embed_id')
                ->constrained('feedback_embed_sessions')
                ->nullOnDelete();
            $table->string('respondent_hash', 64)->nullable()->after('embed_session_id')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('fb_submissions', function (Blueprint $table) {
            $table->dropForeign(['embed_id']);
            $table->dropForeign(['embed_session_id']);
            $table->dropColumn(['embed_id', 'embed_session_id', 'respondent_hash']);
        });
    }
};

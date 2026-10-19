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
        Schema::table('conversation_participants', function (Blueprint $table): void {
            $table->boolean('is_pinned')->default(false)->after('is_muted');
        });

        Schema::table('messages', function (Blueprint $table): void {
            $table->foreignId('reply_to_id')
                ->nullable()
                ->after('sender_id')
                ->constrained('messages')
                ->nullOnDelete();
            $table->timestamp('edited_at')->nullable()->after('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->dropForeign(['reply_to_id']);
            $table->dropColumn(['reply_to_id', 'edited_at']);
        });

        Schema::table('conversation_participants', function (Blueprint $table): void {
            $table->dropColumn('is_pinned');
        });
    }
};

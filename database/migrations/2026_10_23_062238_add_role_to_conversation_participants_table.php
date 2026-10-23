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
            $table->string('role')->default('member')->after('user_id');
        });

        $groupConversations = DB::table('conversations')->where('type', 'group')->pluck('id');
        foreach ($groupConversations as $convId) {
            $firstParticipantId = DB::table('conversation_participants')
                ->where('conversation_id', $convId)
                ->orderBy('id', 'asc')
                ->value('id');

            if ($firstParticipantId) {
                DB::table('conversation_participants')
                    ->where('id', $firstParticipantId)
                    ->update(['role' => 'admin']);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('conversation_participants', function (Blueprint $table): void {
            $table->dropColumn('role');
        });
    }
};

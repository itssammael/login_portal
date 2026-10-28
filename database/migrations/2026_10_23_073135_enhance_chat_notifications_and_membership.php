<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('conversation_participants', function (Blueprint $table) {
            $table->string('notification_preference', 32)->default('all')->after('is_muted');
        });

        // Safely migrate existing is_muted data
        DB::table('conversation_participants')
            ->where('is_muted', true)
            ->update(['notification_preference' => 'none']);

        DB::table('conversation_participants')
            ->where('is_muted', false)
            ->update(['notification_preference' => 'all']);

        Schema::table('conversations', function (Blueprint $table) {
            $table->string('direct_key', 64)->nullable()->unique()->after('type');
        });

        // Backfill existing direct conversations with direct_key
        $directConversations = DB::table('conversations')->where('type', 'direct')->get();
        foreach ($directConversations as $conv) {
            $userIds = DB::table('conversation_participants')
                ->where('conversation_id', $conv->id)
                ->pluck('user_id')
                ->sort()
                ->values()
                ->all();

            if (count($userIds) === 2) {
                $key = $userIds[0].':'.$userIds[1];
                $exists = DB::table('conversations')->where('direct_key', $key)->exists();
                if (! $exists) {
                    DB::table('conversations')->where('id', $conv->id)->update(['direct_key' => $key]);
                }
            }
        }

        Schema::create('message_mentions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['user_id', 'conversation_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('message_mentions');

        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn('direct_key');
        });

        Schema::table('conversation_participants', function (Blueprint $table) {
            $table->dropColumn('notification_preference');
        });
    }
};

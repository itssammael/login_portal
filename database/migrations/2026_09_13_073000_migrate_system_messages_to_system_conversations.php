<?php

use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Create a "System Notification" conversation for all users who don't have one yet
        User::all()->each(function (User $user): void {
            $existing = Conversation::where('type', 'system')
                ->whereHas('participants', fn ($q) => $q->where('user_id', $user->id))
                ->first();

            if (! $existing) {
                $conv = Conversation::create([
                    'type' => 'system',
                    'title' => 'System Notifications',
                    'last_message_at' => now(),
                ]);

                ConversationParticipant::create([
                    'conversation_id' => $conv->id,
                    'user_id' => $user->id,
                    'last_read_at' => null,
                ]);
            }
        });

        // 2. Migrate existing 'system' messages from direct conversations into the recipient's 'system' conversation
        Message::where('type', 'system')->get()->each(function (Message $message): void {
            $conv = $message->conversation;
            if ($conv && $conv->type !== 'system') {
                // Find non-sender recipient in the original conversation
                $recipient = $conv->users->first(fn ($u) => $u->id !== $message->sender_id);
                if ($recipient) {
                    $systemConv = Conversation::where('type', 'system')
                        ->whereHas('participants', fn ($q) => $q->where('user_id', $recipient->id))
                        ->first();

                    if ($systemConv) {
                        $message->update([
                            'conversation_id' => $systemConv->id,
                        ]);
                        $systemConv->update(['last_message_at' => $message->created_at]);
                    }
                }
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};

<?php

use App\Models\Message;
use App\Models\User;
use App\Services\MessageEncryptionService;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Message::query()
            ->whereNotNull('body')
            ->where('body', '!=', '')
            ->where('body', 'not like', 'ENC::%')
            ->get()
            ->each(function (Message $message): void {
                $sender = User::find($message->sender_id);
                if ($sender) {
                    $key = $sender->getOrCreateEncryptionKey();
                    $message->update([
                        'body' => MessageEncryptionService::encrypt($message->body, $key),
                    ]);
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Message::query()
            ->whereNotNull('body')
            ->where('body', 'like', 'ENC::%')
            ->get()
            ->each(function (Message $message): void {
                $sender = User::find($message->sender_id);
                if ($sender) {
                    $key = $sender->getOrCreateEncryptionKey();
                    $message->update([
                        'body' => MessageEncryptionService::decrypt($message->body, $key),
                    ]);
                }
            });
    }
};

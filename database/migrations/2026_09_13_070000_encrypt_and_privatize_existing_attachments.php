<?php

use App\Models\Message;
use App\Services\MessageEncryptionService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Storage;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Message::whereNotNull('attachment_path')->get()->each(function (Message $message): void {
            $path = $message->attachment_path;
            $publicDisk = Storage::disk('public');
            $localDisk = Storage::disk('local');

            $rawContent = null;

            if ($publicDisk->exists($path)) {
                $rawContent = $publicDisk->get($path);
                $publicDisk->delete($path);
            } elseif ($localDisk->exists($path)) {
                $rawContent = $localDisk->get($path);
            }

            if ($rawContent !== null && $message->sender) {
                $key = $message->sender->getOrCreateEncryptionKey();
                $encryptedContent = MessageEncryptionService::encryptBinary($rawContent, $key);
                $localDisk->put($path, $encryptedContent);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Message::whereNotNull('attachment_path')->get()->each(function (Message $message): void {
            $path = $message->attachment_path;
            $publicDisk = Storage::disk('public');
            $localDisk = Storage::disk('local');

            if ($localDisk->exists($path) && $message->sender) {
                $encryptedContent = $localDisk->get($path);
                $key = $message->sender->getOrCreateEncryptionKey();
                $decryptedContent = MessageEncryptionService::decryptBinary($encryptedContent, $key);

                $publicDisk->put($path, $decryptedContent);
                $localDisk->delete($path);
            }
        });
    }
};

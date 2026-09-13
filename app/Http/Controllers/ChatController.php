<?php

namespace App\Http\Controllers;

use App\Events\MessageSent;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\MessageReaction;
use App\Models\User;
use App\Services\MessageEncryptionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class ChatController extends Controller
{
    /**
     * Display the Messenger chat interface.
     */
    public function index(Request $request): Response
    {
        $currentUser = $request->user();

        // Update user activity timestamp
        if (! $currentUser->last_seen_at || $currentUser->last_seen_at->diffInMinutes(now()) >= 2) {
            $currentUser->forceFill(['last_seen_at' => now()])->saveQuietly();
        }

        // Ensure system conversation exists for current user
        Conversation::getOrCreateSystemConversationForUser($currentUser);

        // Fetch user conversations
        $conversations = Conversation::query()
            ->whereHas('participants', function ($query) use ($currentUser): void {
                $query->where('user_id', $currentUser->id);
            })
            ->with([
                'users:id,name,email,profile_photo_path,last_seen_at',
                'participants',
                'messages' => function ($query) use ($currentUser): void {
                    $query->where(function ($q) use ($currentUser): void {
                        $q->where('type', '!=', 'system')
                            ->orWhere('sender_id', '!=', $currentUser->id);
                    })->with('sender:id,name')->orderByDesc('created_at');
                },
            ])
            ->orderByDesc('last_message_at')
            ->orderByDesc('updated_at')
            ->get()
            ->map(function (Conversation $conversation) use ($currentUser): array {
                $otherUser = $conversation->type === 'direct'
                    ? $conversation->getOtherUser($currentUser->id)
                    : null;

                $latestMessage = $conversation->type === 'system'
                    ? $conversation->messages()->latest()->first()
                    : $conversation->messages->first();

                $latestMessageBody = null;

                if ($latestMessage) {
                    if ($latestMessage->is_deleted) {
                        $latestMessageBody = 'This message was deleted';
                    } elseif ($latestMessage->body !== null) {
                        $senderKey = $latestMessage->sender?->getOrCreateEncryptionKey();
                        $latestMessageBody = $senderKey
                            ? MessageEncryptionService::decrypt($latestMessage->body, $senderKey)
                            : $latestMessage->body;
                    } else {
                        $latestMessageBody = $latestMessage->type === 'image' ? '📷 Photo' : '📎 Attachment';
                    }
                }

                return [
                    'id' => $conversation->id,
                    'type' => $conversation->type,
                    'title' => $conversation->type === 'system' ? 'System Notifications' : ($conversation->type === 'group' ? $conversation->title : ($otherUser?->name ?? 'Conversation')),
                    'other_user' => $otherUser ? [
                        'id' => $otherUser->id,
                        'name' => $otherUser->name,
                        'email' => $otherUser->email,
                        'profile_photo_url' => $otherUser->profile_photo_url,
                        'is_online' => $otherUser->is_online,
                        'last_seen_at' => $otherUser->last_seen_at?->diffForHumans(),
                    ] : null,
                    'latest_message' => $latestMessage ? [
                        'id' => $latestMessage->id,
                        'body' => $latestMessageBody,
                        'type' => $latestMessage->type,
                        'sender_id' => $latestMessage->sender_id,
                        'sender_name' => $latestMessage->sender?->name ?? 'System',
                        'created_at' => $latestMessage->created_at->diffForHumans(),
                        'is_deleted' => $latestMessage->is_deleted,
                    ] : null,
                    'unread_count' => $conversation->unreadCountForUser($currentUser->id),
                    'last_message_at' => $latestMessage ? $latestMessage->created_at->diffForHumans() : $conversation->last_message_at?->diffForHumans(),
                    'is_system' => $conversation->type === 'system',
                ];
            })
            ->filter(fn (array $conv) => $conv['type'] === 'system' || $conv['latest_message'] !== null)
            ->values();

        // Determine active conversation
        $activeConversationId = $request->query('conversation');
        $activeConversation = null;

        if ($activeConversationId) {
            $convModel = Conversation::query()
                ->where('id', $activeConversationId)
                ->whereHas('participants', function ($query) use ($currentUser): void {
                    $query->where('user_id', $currentUser->id);
                })
                ->with(['users:id,name,email,profile_photo_path,last_seen_at', 'participants'])
                ->first();

            if ($convModel) {
                // Mark conversation as read for current user
                ConversationParticipant::where('conversation_id', $convModel->id)
                    ->where('user_id', $currentUser->id)
                    ->update(['last_read_at' => now()]);

                $otherUser = $convModel->type === 'direct'
                    ? $convModel->getOtherUser($currentUser->id)
                    : null;

                // Load messages (excluding system messages sent by current user)
                $messages = $convModel->messages()
                    ->where(function ($query) use ($currentUser): void {
                        $query->where('type', '!=', 'system')
                            ->orWhere('sender_id', '!=', $currentUser->id);
                    })
                    ->with([
                        'sender:id,name,email,profile_photo_path',
                        'reactions.user:id,name',
                    ])
                    ->orderBy('created_at', 'asc')
                    ->get()
                    ->map(function (Message $message) use ($currentUser): array {
                        $senderKey = $message->sender?->getOrCreateEncryptionKey();
                        $decryptedBody = (! $message->is_deleted && $message->body !== null && $senderKey)
                            ? MessageEncryptionService::decrypt($message->body, $senderKey)
                            : ($message->is_deleted ? 'This message was deleted' : $message->body);

                        return [
                            'id' => $message->id,
                            'conversation_id' => $message->conversation_id,
                            'sender_id' => $message->sender_id,
                            'sender' => [
                                'id' => $message->sender->id,
                                'name' => $message->sender->name,
                                'email' => $message->sender->email,
                                'profile_photo_url' => $message->sender->profile_photo_url,
                            ],
                            'body' => $decryptedBody,
                            'type' => $message->type,
                            'attachment_url' => $message->is_deleted ? null : $message->attachment_url,
                            'attachment_name' => $message->is_deleted ? null : $message->attachment_name,
                            'attachment_type' => $message->is_deleted ? null : $message->attachment_type,
                            'is_deleted' => $message->is_deleted,
                            'is_sender' => $message->sender_id === $currentUser->id,
                            'created_at' => $message->created_at->format('M j, g:i a'),
                            'created_at_relative' => $message->created_at->diffForHumans(),
                            'reactions' => $message->reactions->groupBy('reaction')->map(function ($group, $reaction) use ($currentUser) {
                                return [
                                    'reaction' => $reaction,
                                    'count' => $group->count(),
                                    'reacted_by_me' => $group->contains('user_id', $currentUser->id),
                                    'users' => $group->map(fn ($r) => $r->user?->name)->filter()->values(),
                                ];
                            })->values(),
                        ];
                    });

                $participants = $convModel->users->map(function (User $u): array {
                    return [
                        'id' => $u->id,
                        'name' => $u->name,
                        'email' => $u->email,
                        'profile_photo_url' => $u->profile_photo_url,
                        'is_online' => $u->is_online,
                        'last_seen_at' => $u->last_seen_at?->diffForHumans(),
                    ];
                })->values();

                $sharedAttachments = $messages
                    ->filter(fn ($m) => ! $m['is_deleted'] && ! empty($m['attachment_url']))
                    ->map(fn ($m) => [
                        'id' => $m['id'],
                        'type' => $m['type'],
                        'name' => $m['attachment_name'] ?? 'Attachment',
                        'url' => $m['attachment_url'],
                        'created_at' => $m['created_at'],
                    ])
                    ->values();

                $activeConversation = [
                    'id' => $convModel->id,
                    'type' => $convModel->type,
                    'title' => $convModel->type === 'system' ? 'System Notifications' : ($convModel->type === 'group' ? $convModel->title : ($otherUser?->name ?? 'Conversation')),
                    'is_system' => $convModel->type === 'system',
                    'created_at' => $convModel->created_at->format('M j, Y'),
                    'other_user' => $otherUser ? [
                        'id' => $otherUser->id,
                        'name' => $otherUser->name,
                        'email' => $otherUser->email,
                        'profile_photo_url' => $otherUser->profile_photo_url,
                        'is_online' => $otherUser->is_online,
                        'last_seen_at' => $otherUser->last_seen_at?->diffForHumans(),
                    ] : null,
                    'participants' => $participants,
                    'total_messages' => $messages->count(),
                    'shared_attachments' => $sharedAttachments,
                    'messages' => $messages,
                ];
            }
        }

        // Available contacts for starting new chats
        $availableUsers = User::query()
            ->where('id', '!=', $currentUser->id)
            ->where('is_banned', false)
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'profile_photo_path', 'last_seen_at'])
            ->map(function (User $user): array {
                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'profile_photo_url' => $user->profile_photo_url,
                    'is_online' => $user->is_online,
                ];
            });

        return Inertia::render('Chat/Index', [
            'conversations' => $conversations,
            'activeConversation' => $activeConversation,
            'availableUsers' => $availableUsers,
        ]);
    }

    /**
     * Start or open a direct message conversation with a recipient.
     */
    public function startDirect(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'recipient_id' => [
                'required',
                'integer',
                'different:'.$request->user()->id,
                'exists:users,id',
            ],
        ]);

        $recipientId = (int) $validated['recipient_id'];
        $currentUser = $request->user();

        // Check if direct conversation already exists
        $conversation = Conversation::query()
            ->where('type', 'direct')
            ->whereHas('participants', function ($query) use ($currentUser): void {
                $query->where('user_id', $currentUser->id);
            })
            ->whereHas('participants', function ($query) use ($recipientId): void {
                $query->where('user_id', $recipientId);
            })
            ->first();

        if (! $conversation) {
            $conversation = Conversation::create([
                'type' => 'direct',
                'last_message_at' => now(),
            ]);

            ConversationParticipant::create([
                'conversation_id' => $conversation->id,
                'user_id' => $currentUser->id,
                'last_read_at' => now(),
            ]);

            ConversationParticipant::create([
                'conversation_id' => $conversation->id,
                'user_id' => $recipientId,
                'last_read_at' => null,
            ]);
        }

        return redirect()->route('chat.index', ['conversation' => $conversation->id]);
    }

    /**
     * Create a new group chat conversation with multiple participants.
     */
    public function createGroup(Request $request): RedirectResponse
    {
        $currentUser = $request->user();

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'user_ids' => ['required', 'array', 'min:1'],
            'user_ids.*' => ['required', 'integer', 'exists:users,id', 'different:'.$currentUser->id],
        ]);

        $conversation = Conversation::create([
            'type' => 'group',
            'title' => trim($validated['title']),
            'last_message_at' => now(),
        ]);

        // Add creator as participant
        ConversationParticipant::create([
            'conversation_id' => $conversation->id,
            'user_id' => $currentUser->id,
            'last_read_at' => now(),
        ]);

        // Add selected members as participants
        $userIds = array_unique($validated['user_ids']);
        foreach ($userIds as $userId) {
            ConversationParticipant::create([
                'conversation_id' => $conversation->id,
                'user_id' => (int) $userId,
                'last_read_at' => null,
            ]);
        }

        // Broadcast real-time event
        broadcast(new MessageSent("Group \"{$conversation->title}\" created", $conversation->id, null, 'group_created'));

        return redirect()->route('chat.index', ['conversation' => $conversation->id]);
    }

    /**
     * Send a new message in the conversation.
     */
    public function sendMessage(Request $request, Conversation $conversation): RedirectResponse
    {
        $currentUser = $request->user();

        if ($conversation->type === 'system') {
            abort(403, 'System Notifications cannot be replied to.');
        }

        // Verify participant
        $isParticipant = $conversation->participants()
            ->where('user_id', $currentUser->id)
            ->exists();

        if (! $isParticipant) {
            abort(403, 'You are not a participant in this conversation.');
        }

        $request->validate([
            'body' => ['nullable', 'string', 'max:5000'],
            'attachment' => ['nullable', 'file', 'mimes:jpg,jpeg,png,gif,webp,pdf,doc,docx,txt,zip', 'max:10240'],
        ]);

        if (! $request->filled('body') && ! $request->hasFile('attachment')) {
            return back()->withErrors(['body' => 'Please provide a message or attachment.']);
        }

        $attachmentPath = null;
        $attachmentName = null;
        $attachmentType = null;
        $type = 'text';

        if ($request->hasFile('attachment')) {
            $file = $request->file('attachment');
            $attachmentName = $file->getClientOriginalName();
            $attachmentType = $file->getClientMimeType() ?: 'application/octet-stream';
            $type = str_starts_with($attachmentType, 'image/') ? 'image' : 'file';

            $senderKey = $currentUser->getOrCreateEncryptionKey();
            $rawContent = file_get_contents($file->getRealPath());
            $encryptedContent = MessageEncryptionService::encryptBinary($rawContent, $senderKey);

            $filename = Str::random(40);
            $attachmentPath = "chat_attachments/{$filename}";
            Storage::disk('local')->put($attachmentPath, $encryptedContent);
        }

        $senderKey = $currentUser->getOrCreateEncryptionKey();
        $rawBody = $request->input('body');
        $encryptedBody = $rawBody !== null ? MessageEncryptionService::encrypt($rawBody, $senderKey) : null;

        $messageModel = $conversation->messages()->create([
            'sender_id' => $currentUser->id,
            'body' => $encryptedBody,
            'type' => $type,
            'attachment_path' => $attachmentPath,
            'attachment_name' => $attachmentName,
            'attachment_type' => $attachmentType,
        ]);

        $conversation->update(['last_message_at' => now()]);

        // Broadcast real-time event via Reverb
        broadcast(new MessageSent("New message from {$currentUser->name}", $conversation->id, [
            'id' => $messageModel->id,
            'body' => $rawBody,
            'sender_id' => $currentUser->id,
        ], 'created'));

        // Mark as read for sender
        ConversationParticipant::where('conversation_id', $conversation->id)
            ->where('user_id', $currentUser->id)
            ->update(['last_read_at' => now()]);

        return back();
    }

    /**
     * Mark conversation as read.
     */
    public function markAsRead(Request $request, Conversation $conversation): RedirectResponse
    {
        $currentUser = $request->user();

        ConversationParticipant::where('conversation_id', $conversation->id)
            ->where('user_id', $currentUser->id)
            ->update(['last_read_at' => now()]);

        return back();
    }

    /**
     * Toggle reaction on a message.
     */
    public function toggleReaction(Request $request, Message $message): RedirectResponse
    {
        $currentUser = $request->user();

        // Verify participant
        $isParticipant = $message->conversation->participants()
            ->where('user_id', $currentUser->id)
            ->exists();

        if (! $isParticipant) {
            abort(403);
        }

        $validated = $request->validate([
            'reaction' => ['required', 'string', 'in:like,love,laugh,wow,sad,fire'],
        ]);

        $reactionType = $validated['reaction'];

        $existing = MessageReaction::where('message_id', $message->id)
            ->where('user_id', $currentUser->id)
            ->first();

        if ($existing) {
            if ($existing->reaction === $reactionType) {
                $existing->delete();
            } else {
                $existing->update(['reaction' => $reactionType]);
            }
        } else {
            MessageReaction::create([
                'message_id' => $message->id,
                'user_id' => $currentUser->id,
                'reaction' => $reactionType,
            ]);
        }

        broadcast(new MessageSent('Reaction updated', $message->conversation_id, null, 'reaction_toggled'));

        return back();
    }

    /**
     * Soft delete a message.
     */
    public function deleteMessage(Request $request, Message $message): RedirectResponse
    {
        $currentUser = $request->user();

        if ($message->conversation->type === 'system') {
            abort(403, 'System Notifications cannot be deleted.');
        }

        if ($message->sender_id !== $currentUser->id && ! $currentUser->isAdmin()) {
            abort(403, 'Unauthorized to delete this message.');
        }

        $message->update([
            'is_deleted' => true,
            'body' => null,
        ]);

        broadcast(new MessageSent('Message deleted', $message->conversation_id, null, 'deleted'));

        return back();
    }

    /**
     * Securely download or stream a conversation attachment for authorized participants.
     */
    public function downloadAttachment(Request $request, Message $message): SymfonyResponse
    {
        $currentUser = $request->user();

        if (! $message->attachment_path) {
            abort(404, 'Attachment not found.');
        }

        // Check if user is a participant in the conversation or an admin
        $isParticipant = $message->conversation->participants()
            ->where('user_id', $currentUser->id)
            ->exists();

        if (! $isParticipant && ! $currentUser->isAdmin()) {
            abort(403, 'Unauthorized access to this conversation attachment.');
        }

        $disk = Storage::disk('local')->exists($message->attachment_path)
            ? Storage::disk('local')
            : Storage::disk('public');

        if (! $disk->exists($message->attachment_path)) {
            abort(404, 'Attachment file not found on storage disk.');
        }

        $rawContent = $disk->get($message->attachment_path);
        $senderKey = $message->sender?->getOrCreateEncryptionKey();

        $decryptedContent = $senderKey
            ? MessageEncryptionService::decryptBinary($rawContent, $senderKey)
            : $rawContent;

        $mimeType = $message->attachment_type ?: 'application/octet-stream';
        $filename = $message->attachment_name ?: 'attachment';

        return response($decryptedContent, 200, [
            'Content-Type' => $mimeType,
            'Content-Disposition' => 'inline; filename="'.addslashes($filename).'"',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }
}

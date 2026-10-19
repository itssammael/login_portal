<?php

namespace App\Http\Controllers;

use App\Events\MessageSent;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\MessageReaction;
use App\Models\User;
use App\Models\UserEncryptionKey;
use App\Services\MessageEncryptionService;
use Illuminate\Http\JsonResponse;
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
            ])
            ->orderByDesc('last_message_at')
            ->orderByDesc('updated_at')
            ->get();

        $conversationIds = $conversations->pluck('id');

        // Batch query unread counts for all conversations in one query (no N+1)
        $unreadCounts = ConversationParticipant::query()
            ->where('user_id', $currentUser->id)
            ->whereIn('conversation_participants.conversation_id', $conversationIds)
            ->join('messages', 'messages.conversation_id', '=', 'conversation_participants.conversation_id')
            ->where('messages.sender_id', '!=', $currentUser->id)
            ->where('messages.is_deleted', false)
            ->where(function ($query): void {
                $query->whereNull('conversation_participants.last_read_at')
                    ->orWhereColumn('messages.created_at', '>', 'conversation_participants.last_read_at');
            })
            ->groupBy('conversation_participants.conversation_id')
            ->selectRaw('conversation_participants.conversation_id, count(messages.id) as unread_count')
            ->pluck('unread_count', 'conversation_id');

        // Batch query latest visible message ID for each conversation
        $systemConvIds = $conversations->where('type', 'system')->pluck('id');
        $otherConvIds = $conversations->where('type', '!=', 'system')->pluck('id');

        $latestMessageIdsQuery = Message::query()->whereIn('conversation_id', $conversationIds);
        if ($systemConvIds->isNotEmpty() && $otherConvIds->isNotEmpty()) {
            $latestMessageIdsQuery->where(function ($query) use ($systemConvIds, $otherConvIds, $currentUser): void {
                $query->whereIn('conversation_id', $systemConvIds)
                    ->orWhere(function ($q) use ($otherConvIds, $currentUser): void {
                        $q->whereIn('conversation_id', $otherConvIds)
                            ->where(function ($sub) use ($currentUser): void {
                                $sub->where('type', '!=', 'system')
                                    ->orWhere('sender_id', '!=', $currentUser->id);
                            });
                    });
            });
        } elseif ($systemConvIds->isNotEmpty()) {
            $latestMessageIdsQuery->whereIn('conversation_id', $systemConvIds);
        } else {
            $latestMessageIdsQuery->whereIn('conversation_id', $otherConvIds)
                ->where(function ($sub) use ($currentUser): void {
                    $sub->where('type', '!=', 'system')
                        ->orWhere('sender_id', '!=', $currentUser->id);
                });
        }

        $latestMessageIds = $latestMessageIdsQuery
            ->selectRaw('max(id) as id')
            ->groupBy('conversation_id')
            ->pluck('id');

        $latestMessages = Message::query()
            ->whereIn('id', $latestMessageIds)
            ->with('sender:id,name')
            ->get()
            ->keyBy('conversation_id');

        // Batch load encryption keys for senders
        $senderIds = $latestMessages->pluck('sender_id')->filter()->unique();
        $senderKeys = UserEncryptionKey::whereIn('user_id', $senderIds)->pluck('encryption_key', 'user_id');

        $participantsPreferences = ConversationParticipant::query()
            ->where('user_id', $currentUser->id)
            ->whereIn('conversation_id', $conversationIds)
            ->get()
            ->keyBy('conversation_id');

        $mappedConversations = $conversations
            ->map(function (Conversation $conversation) use ($currentUser, $unreadCounts, $latestMessages, $senderKeys, $participantsPreferences): array {
                $otherUser = $conversation->type === 'direct'
                    ? $conversation->getOtherUser($currentUser->id)
                    : null;

                $latestMessage = $latestMessages->get($conversation->id);
                $latestMessageBody = null;

                if ($latestMessage) {
                    if ($latestMessage->is_deleted) {
                        $latestMessageBody = 'This message was deleted';
                    } elseif ($latestMessage->body !== null) {
                        $senderKey = $senderKeys->get($latestMessage->sender_id) ?? $latestMessage->sender?->getOrCreateEncryptionKey();
                        $latestMessageBody = $senderKey
                            ? MessageEncryptionService::decrypt($latestMessage->body, $senderKey)
                            : $latestMessage->body;
                    } else {
                        $latestMessageBody = $latestMessage->type === 'image' ? '📷 Photo' : '📎 Attachment';
                    }
                }

                $unreadCount = (int) ($unreadCounts->get($conversation->id) ?? 0);
                $participantPref = $participantsPreferences->get($conversation->id);

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
                    'participants' => $conversation->type === 'group' ? $conversation->users->map(fn (User $user): array => [
                        'id' => $user->id,
                        'name' => $user->name,
                    ])->values()->all() : [],
                    'latest_message' => $latestMessage ? [
                        'id' => $latestMessage->id,
                        'body' => $latestMessageBody,
                        'type' => $latestMessage->type,
                        'sender_id' => $latestMessage->sender_id,
                        'sender_name' => $latestMessage->sender?->name ?? 'System',
                        'created_at' => $latestMessage->created_at->diffForHumans(),
                        'is_deleted' => $latestMessage->is_deleted,
                    ] : null,
                    'unread_count' => $unreadCount,
                    'is_pinned' => (bool) ($participantPref?->is_pinned ?? false),
                    'is_muted' => (bool) ($participantPref?->is_muted ?? false),
                    'last_message_at' => $latestMessage ? $latestMessage->created_at->diffForHumans() : ($conversation->last_message_at?->diffForHumans() ?? $conversation->created_at?->diffForHumans()),
                    'is_system' => $conversation->type === 'system',
                ];
            })
            ->filter(fn (array $conv) => $conv['type'] === 'system' || $conv['type'] === 'group' || $conv['latest_message'] !== null)
            ->sort(function (array $a, array $b): int {
                if ($a['is_pinned'] !== $b['is_pinned']) {
                    return $b['is_pinned'] <=> $a['is_pinned'];
                }

                return 0;
            })
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
                ->with([
                    'users:id,name,email,profile_photo_path,last_seen_at',
                    'participants',
                ])
                ->first();

            if ($convModel) {
                // Mark conversation as read for current user
                ConversationParticipant::where('conversation_id', $convModel->id)
                    ->where('user_id', $currentUser->id)
                    ->update(['last_read_at' => now()]);

                $otherUser = $convModel->type === 'direct'
                    ? $convModel->getOtherUser($currentUser->id)
                    : null;

                $otherParticipant = $convModel->participants->where('user_id', '!=', $currentUser->id)->sortByDesc('last_read_at')->first();
                $otherUserLastReadAt = $otherParticipant?->last_read_at;

                // Load latest 50 messages (excluding system messages sent by current user)
                $rawMessages = $convModel->messages()
                    ->where(function ($query) use ($currentUser): void {
                        $query->where('type', '!=', 'system')
                            ->orWhere('sender_id', '!=', $currentUser->id);
                    })
                    ->with([
                        'sender:id,name,email,profile_photo_path',
                        'replyTo.sender:id,name',
                        'reactions.user:id,name',
                    ])
                    ->orderByDesc('id')
                    ->limit(51)
                    ->get();

                $hasMoreMessages = $rawMessages->count() > 50;
                $pagedMessages = $rawMessages->take(50)->reverse()->values();

                $activeSenderIds = $pagedMessages->pluck('sender_id')->filter()->unique();
                $activeSenderKeys = UserEncryptionKey::whereIn('user_id', $activeSenderIds)->pluck('encryption_key', 'user_id');

                $formattedMessages = $pagedMessages->map(function (Message $message) use ($currentUser, $activeSenderKeys, $otherUserLastReadAt): array {
                    $senderKey = $activeSenderKeys->get($message->sender_id) ?? $message->sender?->getOrCreateEncryptionKey();

                    return $this->formatMessage($message, $currentUser, $senderKey, $otherUserLastReadAt);
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

                $sharedAttachments = $convModel->messages()
                    ->where('is_deleted', false)
                    ->whereNotNull('attachment_path')
                    ->orderByDesc('created_at')
                    ->limit(50)
                    ->get()
                    ->map(fn (Message $m): array => [
                        'id' => $m->id,
                        'type' => $m->type,
                        'name' => $m->attachment_name ?? 'Attachment',
                        'url' => $m->attachment_url,
                        'created_at' => $m->created_at->format('M j, g:i a'),
                    ])
                    ->values();

                $totalMessages = $convModel->messages()
                    ->where(function ($query) use ($currentUser): void {
                        $query->where('type', '!=', 'system')
                            ->orWhere('sender_id', '!=', $currentUser->id);
                    })
                    ->count();

                $currentParticipant = $convModel->participants->firstWhere('user_id', $currentUser->id);

                $activeConversation = [
                    'id' => $convModel->id,
                    'type' => $convModel->type,
                    'title' => $convModel->type === 'system' ? 'System Notifications' : ($convModel->type === 'group' ? $convModel->title : ($otherUser?->name ?? 'Conversation')),
                    'is_system' => $convModel->type === 'system',
                    'is_pinned' => (bool) ($currentParticipant?->is_pinned ?? false),
                    'is_muted' => (bool) ($currentParticipant?->is_muted ?? false),
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
                    'total_messages' => $totalMessages,
                    'shared_attachments' => $sharedAttachments,
                    'messages' => $formattedMessages,
                    'has_more' => $hasMoreMessages,
                    'oldest_id' => $pagedMessages->first()?->id,
                ];
            }
        }

        return Inertia::render('Chat/Index', [
            'conversations' => $mappedConversations,
            'activeConversation' => $activeConversation,
            'availableUsers' => [],
        ]);
    }

    /**
     * Get paginated older messages for a conversation using a cursor.
     */
    public function messages(Request $request, Conversation $conversation): JsonResponse
    {
        $currentUser = $request->user();

        // Check if user is a participant in the conversation or an admin
        $isParticipant = $conversation->participants()
            ->where('user_id', $currentUser->id)
            ->exists();

        if (! $isParticipant && ! $currentUser->isAdmin()) {
            abort(403, 'Unauthorized access to this conversation.');
        }

        $beforeId = $request->query('before_id');
        if ($beforeId !== null) {
            $beforeId = (int) $beforeId;
        }

        $rawMessages = $conversation->messages()
            ->where(function ($query) use ($currentUser): void {
                $query->where('type', '!=', 'system')
                    ->orWhere('sender_id', '!=', $currentUser->id);
            })
            ->when($beforeId, function ($query) use ($beforeId): void {
                $query->where('id', '<', $beforeId);
            })
            ->with([
                'sender:id,name,email,profile_photo_path',
                'replyTo.sender:id,name',
                'reactions.user:id,name',
            ])
            ->orderByDesc('id')
            ->limit(51)
            ->get();

        $hasMore = $rawMessages->count() > 50;
        $pagedMessages = $rawMessages->take(50)->reverse()->values();

        $senderIds = $pagedMessages->pluck('sender_id')->filter()->unique();
        $senderKeys = UserEncryptionKey::whereIn('user_id', $senderIds)->pluck('encryption_key', 'user_id');

        $otherParticipant = $conversation->participants()->where('user_id', '!=', $currentUser->id)->orderByDesc('last_read_at')->first();
        $otherUserLastReadAt = $otherParticipant?->last_read_at;

        $formattedMessages = $pagedMessages->map(function (Message $message) use ($currentUser, $senderKeys, $otherUserLastReadAt): array {
            $senderKey = $senderKeys->get($message->sender_id) ?? $message->sender?->getOrCreateEncryptionKey();

            return $this->formatMessage($message, $currentUser, $senderKey, $otherUserLastReadAt);
        });

        return response()->json([
            'messages' => $formattedMessages,
            'has_more' => $hasMore,
            'oldest_id' => $pagedMessages->first()?->id,
        ]);
    }

    /**
     * Search available contacts for starting new chats.
     */
    public function searchContacts(Request $request): JsonResponse
    {
        $currentUser = $request->user();
        $query = trim((string) $request->query('query', ''));

        $users = User::query()
            ->where('id', '!=', $currentUser->id)
            ->where('is_banned', false)
            ->when($query !== '', function ($q) use ($query): void {
                $q->where(function ($sub) use ($query): void {
                    $sub->where('name', 'like', "%{$query}%")
                        ->orWhere('email', 'like', "%{$query}%");
                });
            })
            ->orderBy('name')
            ->limit(20)
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

        return response()->json([
            'users' => $users,
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

        $groupData = [
            'id' => $conversation->id,
            'type' => 'group',
            'title' => $conversation->title,
            'other_user' => null,
            'participants' => $conversation->users->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
            ])->values()->all(),
            'latest_message' => null,
            'unread_count' => 0,
            'last_message_at' => $conversation->created_at->diffForHumans(),
            'is_system' => false,
        ];

        // Broadcast real-time event
        broadcast(new MessageSent("Group \"{$conversation->title}\" created", $conversation->id, [
            'conversation' => $groupData,
        ], 'group_created'));

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
            'reply_to_id' => ['nullable', 'integer', 'exists:messages,id'],
            'attachment' => ['nullable', 'file', 'mimes:jpg,jpeg,png,gif,webp,pdf,doc,docx,txt,zip', 'max:10240'],
        ]);

        if (! $request->filled('body') && ! $request->hasFile('attachment')) {
            return back()->withErrors(['body' => 'Please provide a message or attachment.']);
        }

        $replyToId = null;
        if ($request->filled('reply_to_id')) {
            $replyToMessage = $conversation->messages()->where('id', $request->input('reply_to_id'))->first();
            if ($replyToMessage) {
                $replyToId = $replyToMessage->id;
            }
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
            'reply_to_id' => $replyToId,
            'body' => $encryptedBody,
            'type' => $type,
            'attachment_path' => $attachmentPath,
            'attachment_name' => $attachmentName,
            'attachment_type' => $attachmentType,
            'status' => Message::STATUS_SENT,
        ]);

        $conversation->update(['last_message_at' => now()]);

        $messageModel->load('replyTo.sender');
        $formattedMessage = $this->formatMessage($messageModel, $currentUser, $senderKey);

        $previewBody = $rawBody;
        if (! $previewBody) {
            $previewBody = $type === 'image' ? '📷 Photo' : '📎 Attachment';
        }

        $conversation->loadMissing('users');

        $conversationPreview = [
            'id' => $conversation->id,
            'type' => $conversation->type,
            'title' => $conversation->type === 'system' ? 'System Notifications' : ($conversation->type === 'group' ? $conversation->title : ($currentUser->name ?? 'Conversation')),
            'other_user' => $conversation->type === 'direct' ? [
                'id' => $currentUser->id,
                'name' => $currentUser->name,
                'email' => $currentUser->email,
                'profile_photo_url' => $currentUser->profile_photo_url,
                'is_online' => $currentUser->is_online,
                'last_seen_at' => $currentUser->last_seen_at?->diffForHumans(),
            ] : null,
            'participants' => $conversation->type === 'group' ? $conversation->users->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
            ])->values()->all() : [],
            'unread_count' => 1,
            'is_pinned' => false,
            'is_muted' => false,
            'latest_message' => [
                'id' => $messageModel->id,
                'body' => $previewBody,
                'type' => $type,
                'sender_id' => $currentUser->id,
                'sender_name' => $currentUser->name,
                'created_at' => $messageModel->created_at->diffForHumans(),
                'is_deleted' => false,
            ],
            'last_message_at' => $messageModel->created_at->diffForHumans(),
            'is_system' => $conversation->type === 'system',
        ];

        // Broadcast real-time event via Reverb
        broadcast(new MessageSent("New message from {$currentUser->name}", $conversation->id, [
            'message' => $formattedMessage,
            'conversation' => $conversationPreview,
            'sender_id' => $currentUser->id,
        ], 'created'));

        // Mark as read for sender
        ConversationParticipant::where('conversation_id', $conversation->id)
            ->where('user_id', $currentUser->id)
            ->update(['last_read_at' => now()]);

        return back();
    }

    /**
     * Edit a message.
     */
    public function editMessage(Request $request, Message $message): RedirectResponse
    {
        $currentUser = $request->user();

        if ($message->sender_id !== $currentUser->id) {
            abort(403, 'Unauthorized to edit this message.');
        }

        if ($message->conversation->type === 'system') {
            abort(403, 'System messages cannot be edited.');
        }

        if ($message->is_deleted) {
            abort(400, 'Deleted messages cannot be edited.');
        }

        // 15-minute edit window
        if ($message->created_at->diffInMinutes(now()) > 15) {
            abort(403, 'Messages can only be edited within 15 minutes of sending.');
        }

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
        ]);

        $senderKey = $currentUser->getOrCreateEncryptionKey();
        $encryptedBody = MessageEncryptionService::encrypt($validated['body'], $senderKey);

        $message->update([
            'body' => $encryptedBody,
            'edited_at' => now(),
        ]);

        $message->load(['sender', 'replyTo.sender']);
        $formatted = $this->formatMessage($message, $currentUser, $senderKey);

        broadcast(new MessageSent('Message edited', $message->conversation_id, [
            'message' => $formatted,
            'message_id' => $message->id,
            'body' => $validated['body'],
            'edited_at' => $message->edited_at->toISOString(),
            'edited_at_relative' => $message->edited_at->diffForHumans(),
        ], 'edited'));

        return back();
    }

    /**
     * Broadcast ephemeral typing event to conversation participants.
     */
    public function typing(Request $request, Conversation $conversation): JsonResponse
    {
        $currentUser = $request->user();

        $isParticipant = $conversation->participants()
            ->where('user_id', $currentUser->id)
            ->exists();

        if (! $isParticipant) {
            abort(403);
        }

        broadcast(new MessageSent('typing', $conversation->id, [
            'user_id' => $currentUser->id,
            'user_name' => $currentUser->name,
        ], 'typing'))->toOthers();

        return response()->json(['status' => 'ok']);
    }

    /**
     * Search messages in active conversation by decrypting content server-side.
     */
    public function searchMessages(Request $request, Conversation $conversation): JsonResponse
    {
        $currentUser = $request->user();

        $isParticipant = $conversation->participants()
            ->where('user_id', $currentUser->id)
            ->exists();

        if (! $isParticipant && ! $currentUser->isAdmin()) {
            abort(403, 'Unauthorized access to this conversation.');
        }

        $query = trim((string) $request->query('q', ''));
        if (mb_strlen($query) < 2) {
            return response()->json(['results' => [], 'count' => 0]);
        }

        $messages = $conversation->messages()
            ->where('is_deleted', false)
            ->whereNotNull('body')
            ->with('sender:id,name')
            ->orderByDesc('id')
            ->limit(300)
            ->get();

        $senderIds = $messages->pluck('sender_id')->filter()->unique();
        $senderKeys = UserEncryptionKey::whereIn('user_id', $senderIds)->pluck('encryption_key', 'user_id');

        $needle = mb_strtolower($query);
        $matches = [];

        foreach ($messages as $msg) {
            $senderKey = $senderKeys->get($msg->sender_id) ?? $msg->sender?->getOrCreateEncryptionKey();
            $decryptedBody = $senderKey ? MessageEncryptionService::decrypt($msg->body, $senderKey) : $msg->body;

            if ($decryptedBody && str_contains(mb_strtolower($decryptedBody), $needle)) {
                $matches[] = [
                    'id' => $msg->id,
                    'sender_id' => $msg->sender_id,
                    'sender_name' => $msg->sender?->name ?? 'User',
                    'body' => $decryptedBody,
                    'created_at' => $msg->created_at->format('M j, Y g:i a'),
                    'created_at_relative' => $msg->created_at->diffForHumans(),
                ];

                if (count($matches) >= 20) {
                    break;
                }
            }
        }

        return response()->json([
            'query' => $query,
            'count' => count($matches),
            'results' => $matches,
        ]);
    }

    /**
     * Toggle pinned state for a conversation for the current user.
     */
    public function togglePin(Request $request, Conversation $conversation): RedirectResponse
    {
        $currentUser = $request->user();

        $participant = ConversationParticipant::where('conversation_id', $conversation->id)
            ->where('user_id', $currentUser->id)
            ->firstOrFail();

        $participant->update([
            'is_pinned' => ! $participant->is_pinned,
        ]);

        return back();
    }

    /**
     * Toggle muted notifications for a conversation for the current user.
     */
    public function toggleMute(Request $request, Conversation $conversation): RedirectResponse
    {
        $currentUser = $request->user();

        $participant = ConversationParticipant::where('conversation_id', $conversation->id)
            ->where('user_id', $currentUser->id)
            ->firstOrFail();

        $participant->update([
            'is_muted' => ! $participant->is_muted,
        ]);

        return back();
    }

    /**
     * Mark all conversations as read for the current user.
     */
    public function markAllAsRead(Request $request): RedirectResponse
    {
        $currentUser = $request->user();

        ConversationParticipant::where('user_id', $currentUser->id)
            ->update(['last_read_at' => now()]);

        return back();
    }

    /**
     * Get the current unread messages count for the authenticated user.
     */
    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json([
            'count' => $request->user()->unreadMessagesCount(),
        ]);
    }

    /**
     * Mark conversation as read.
     */
    public function markAsRead(Request $request, Conversation $conversation): RedirectResponse
    {
        $currentUser = $request->user();

        $isParticipant = $conversation->participants()
            ->where('user_id', $currentUser->id)
            ->exists();

        if (! $isParticipant) {
            abort(403, 'Unauthorized conversation access.');
        }

        ConversationParticipant::where('conversation_id', $conversation->id)
            ->where('user_id', $currentUser->id)
            ->update(['last_read_at' => now()]);

        broadcast(new MessageSent('Conversation read', $conversation->id, [
            'user_id' => $currentUser->id,
            'read_at' => now()->toISOString(),
        ], 'read'));

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

        $message->load('reactions.user:id,name');
        $reactionsSummary = $message->reactions->groupBy('reaction')->map(function ($group, $reaction) {
            return [
                'reaction' => $reaction,
                'count' => $group->count(),
                'users' => $group->map(fn ($r) => $r->user?->name)->filter()->values()->all(),
                'user_ids' => $group->pluck('user_id')->map(fn ($id) => (int) $id)->values()->all(),
            ];
        })->values()->all();

        broadcast(new MessageSent('Reaction updated', $message->conversation_id, [
            'message_id' => $message->id,
            'reactions' => $reactionsSummary,
        ], 'reaction_toggled'));

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

        $attachmentDeleted = ! (bool) $message->attachment_path;

        if ($message->attachment_path) {
            try {
                if (Storage::disk('local')->exists($message->attachment_path)) {
                    $attachmentDeleted = (bool) Storage::disk('local')->delete($message->attachment_path);
                } elseif (Storage::disk('public')->exists($message->attachment_path)) {
                    $attachmentDeleted = (bool) Storage::disk('public')->delete($message->attachment_path);
                } else {
                    $attachmentDeleted = true;
                }
            } catch (\Throwable) {
                $attachmentDeleted = false;
            }
        }

        $updateData = [
            'is_deleted' => true,
            'body' => null,
        ];

        if ($attachmentDeleted) {
            $updateData['attachment_path'] = null;
            $updateData['attachment_name'] = null;
            $updateData['attachment_type'] = null;
        }

        $message->update($updateData);

        broadcast(new MessageSent('Message deleted', $message->conversation_id, [
            'message_id' => $message->id,
        ], 'deleted'));

        return back();
    }

    /**
     * Format a message model into an array with decrypted body and metadata.
     *
     * @return array<string, mixed>
     */
    private function formatMessage(Message $message, User $currentUser, ?string $senderKey = null, ?\DateTimeInterface $otherUserLastReadAt = null): array
    {
        $senderKey = $senderKey ?? $message->sender?->getOrCreateEncryptionKey();
        $decryptedBody = (! $message->is_deleted && $message->body !== null && $senderKey)
            ? MessageEncryptionService::decrypt($message->body, $senderKey)
            : ($message->is_deleted ? 'This message was deleted' : $message->body);

        $replyTo = null;
        if ($message->replyTo) {
            $replySenderKey = $message->replyTo->sender?->getOrCreateEncryptionKey();
            $replyBody = (! $message->replyTo->is_deleted && $message->replyTo->body !== null && $replySenderKey)
                ? MessageEncryptionService::decrypt($message->replyTo->body, $replySenderKey)
                : ($message->replyTo->is_deleted ? 'This message was deleted' : ($message->replyTo->attachment_name ? '📎 Attachment' : ''));

            $replyTo = [
                'id' => $message->replyTo->id,
                'sender_id' => $message->replyTo->sender_id,
                'sender_name' => $message->replyTo->sender?->name ?? 'User',
                'body' => $replyBody,
                'is_deleted' => (bool) $message->replyTo->is_deleted,
            ];
        }

        $isSeen = false;
        if ($message->sender_id === $currentUser->id && $otherUserLastReadAt) {
            $isSeen = $otherUserLastReadAt >= $message->created_at;
        }

        return [
            'id' => $message->id,
            'conversation_id' => $message->conversation_id,
            'sender_id' => $message->sender_id,
            'reply_to_id' => $message->reply_to_id,
            'reply_to' => $replyTo,
            'sender' => [
                'id' => $message->sender?->id ?? $message->sender_id,
                'name' => $message->sender?->name ?? 'System',
                'email' => $message->sender?->email ?? '',
                'profile_photo_url' => $message->sender?->profile_photo_url,
            ],
            'body' => $decryptedBody,
            'type' => $message->type,
            'attachment_url' => $message->is_deleted ? null : $message->attachment_url,
            'attachment_name' => $message->is_deleted ? null : $message->attachment_name,
            'attachment_type' => $message->is_deleted ? null : $message->attachment_type,
            'status' => $isSeen ? Message::STATUS_READ : ($message->status ?? Message::STATUS_SENT),
            'is_seen' => $isSeen,
            'is_edited' => (bool) $message->edited_at,
            'edited_at' => $message->edited_at?->diffForHumans(),
            'is_deleted' => (bool) $message->is_deleted,
            'is_sender' => $message->sender_id === $currentUser->id,
            'created_at' => $message->created_at->format('M j, g:i a'),
            'created_at_relative' => $message->created_at->diffForHumans(),
            'raw_created_at' => $message->created_at->toISOString(),
            'reactions' => $message->reactions->groupBy('reaction')->map(function ($group, $reaction) use ($currentUser) {
                return [
                    'reaction' => $reaction,
                    'count' => $group->count(),
                    'reacted_by_me' => $group->contains('user_id', $currentUser->id),
                    'users' => $group->map(fn ($r) => $r->user?->name)->filter()->values()->all(),
                    'user_ids' => $group->pluck('user_id')->map(fn ($id) => (int) $id)->values()->all(),
                ];
            })->values()->all(),
        ];
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
        $sanitizedFilename = str_replace(["\r", "\n", '"', ';'], '', $filename);

        return response($decryptedContent, 200, [
            'Content-Type' => $mimeType,
            'Content-Disposition' => 'inline; filename="'.$sanitizedFilename.'"',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }
}

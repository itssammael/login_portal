<?php

namespace App\Http\Controllers\Admin;

use App\Events\MessageSent;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Role;
use App\Models\User;
use App\Services\MessageEncryptionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AdminDashboardController extends Controller
{
    /**
     * Display the Admin Overview Dashboard.
     */
    public function index(): Response
    {
        $totalUsers = User::count();
        $activeUsers24h = User::where('last_seen_at', '>=', now()->subDay())->count();
        $totalConversations = Conversation::count();
        $totalMessages = Message::count();
        $messagesToday = Message::where('created_at', '>=', now()->startOfDay())->count();
        $bannedUsers = User::where('is_banned', true)->count();

        $recentLogs = AuditLog::with('admin:id,name,email')
            ->latest()
            ->take(8)
            ->get();

        $recentConversations = Conversation::with(['users:id,name,email,profile_photo_path', 'latestMessage.sender:id,name'])
            ->withCount('messages')
            ->orderByDesc('last_message_at')
            ->take(6)
            ->get();

        return Inertia::render('Admin/Dashboard', [
            'stats' => [
                'total_users' => $totalUsers,
                'active_users_24h' => $activeUsers24h,
                'total_conversations' => $totalConversations,
                'total_messages' => $totalMessages,
                'messages_today' => $messagesToday,
                'banned_users' => $bannedUsers,
            ],
            'recentLogs' => $recentLogs,
            'recentConversations' => $recentConversations,
        ]);
    }

    /**
     * Display users management table.
     */
    public function users(Request $request): Response
    {
        $query = User::query()
            ->withCount(['messages', 'conversations']);

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($request->input('status') === 'banned') {
            $query->where('is_banned', true);
        } elseif ($request->input('status') === 'active') {
            $query->where('is_banned', false);
        }

        if ($request->input('role') === 'admin') {
            $query->where('is_admin', true);
        } elseif ($request->input('role') === 'user') {
            $query->where('is_admin', false);
        }

        $users = $query->orderByDesc('created_at')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Admin/Users', [
            'users' => $users,
            'filters' => $request->only(['search', 'status', 'role']),
        ]);
    }

    /**
     * Toggle user ban status.
     */
    public function toggleBan(Request $request, User $user): RedirectResponse
    {
        $admin = $request->user();

        if ($user->id === $admin->id) {
            return back()->with('error', 'You cannot suspend your own administrative account.');
        }

        $isBanned = ! $user->is_banned;

        $user->update([
            'is_banned' => $isBanned,
            'banned_at' => $isBanned ? now() : null,
        ]);

        AuditLog::create([
            'admin_id' => $admin->id,
            'action' => $isBanned ? 'banned_user' : 'unbanned_user',
            'target_type' => User::class,
            'target_id' => $user->id,
            'details' => [
                'target_name' => $user->name,
                'target_email' => $user->email,
                'reason' => $request->input('reason', 'Administrative action'),
            ],
        ]);

        return back()->with('success', $isBanned ? "User {$user->name} has been suspended." : "User {$user->name} has been reinstated.");
    }

    /**
     * Update user role.
     */
    public function updateUserRole(Request $request, User $user): RedirectResponse
    {
        $admin = $request->user();

        if ($user->id === $admin->id) {
            return back()->with('error', 'You cannot modify your own administrative role.');
        }

        $request->validate([
            'role_id' => ['required', 'exists:roles,id'],
        ]);

        $role = Role::findOrFail($request->input('role_id'));
        $isAdmin = $role->slug === 'admin' || $role->hasPermission('access_admin');

        $user->update([
            'role_id' => $role->id,
            'is_admin' => $isAdmin,
        ]);

        AuditLog::create([
            'admin_id' => $admin->id,
            'action' => 'updated_user_role',
            'target_type' => User::class,
            'target_id' => $user->id,
            'details' => [
                'target_name' => $user->name,
                'target_email' => $user->email,
                'role_name' => $role->name,
                'role_slug' => $role->slug,
            ],
        ]);

        return back()->with('success', "Updated role for {$user->name} to \"{$role->name}\".");
    }

    /**
     * Toggle administrator role for a user.
     */
    public function toggleAdmin(Request $request, User $user): RedirectResponse
    {
        $admin = $request->user();

        if ($user->id === $admin->id) {
            return back()->with('error', 'You cannot modify your own administrator privileges.');
        }

        $isAdmin = ! $user->is_admin;

        $user->update([
            'is_admin' => $isAdmin,
        ]);

        AuditLog::create([
            'admin_id' => $admin->id,
            'action' => $isAdmin ? 'promoted_to_admin' : 'demoted_from_admin',
            'target_type' => User::class,
            'target_id' => $user->id,
            'details' => [
                'target_name' => $user->name,
                'target_email' => $user->email,
            ],
        ]);

        return back()->with('success', $isAdmin ? "Granted Administrator role to {$user->name}." : "Revoked Administrator role from {$user->name}.");
    }

    /**
     * Display chat monitoring and moderation list.
     */
    public function chats(Request $request): Response
    {
        $query = Conversation::query()
            ->with([
                'users:id,name,email,profile_photo_path,is_banned',
                'latestMessage.sender:id,name',
            ])
            ->withCount('messages');

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->whereHas('users', function ($q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $conversations = $query->orderByDesc('last_message_at')
            ->paginate(15)
            ->withQueryString();

        // Selected conversation for inspection if requested
        $selectedChat = null;
        if ($request->filled('inspect')) {
            $inspectConv = Conversation::with([
                'users:id,name,email,profile_photo_path',
                'messages' => function ($q): void {
                    $q->with('sender:id,name,email,profile_photo_path')
                        ->orderBy('created_at', 'asc');
                },
            ])->find($request->input('inspect'));

            if ($inspectConv) {
                $selectedChat = [
                    'id' => $inspectConv->id,
                    'type' => $inspectConv->type,
                    'title' => $inspectConv->title,
                    'users' => $inspectConv->users,
                    'messages' => $inspectConv->messages->map(function ($m) {
                        $senderKey = $m->sender?->getOrCreateEncryptionKey();
                        $decryptedBody = (! $m->is_deleted && $m->body !== null && $senderKey)
                            ? MessageEncryptionService::decrypt($m->body, $senderKey)
                            : ($m->is_deleted ? '[Deleted Message]' : $m->body);

                        return [
                            'id' => $m->id,
                            'sender_id' => $m->sender_id,
                            'sender_name' => $m->sender?->name ?? 'User',
                            'body' => $decryptedBody,
                            'type' => $m->type,
                            'attachment_url' => $m->attachment_url,
                            'is_deleted' => $m->is_deleted,
                            'created_at' => $m->created_at->format('M j, Y g:i a'),
                        ];
                    }),
                ];
            }
        }

        return Inertia::render('Admin/Chats', [
            'conversations' => $conversations,
            'selectedChat' => $selectedChat,
            'filters' => $request->only(['search']),
        ]);
    }

    /**
     * Admin delete / moderate message.
     */
    public function deleteMessage(Request $request, Message $message): RedirectResponse
    {
        $admin = $request->user();

        $message->update([
            'is_deleted' => true,
            'body' => null,
        ]);

        AuditLog::create([
            'admin_id' => $admin->id,
            'action' => 'deleted_message',
            'target_type' => Message::class,
            'target_id' => $message->id,
            'details' => [
                'conversation_id' => $message->conversation_id,
                'sender_id' => $message->sender_id,
                'reason' => $request->input('reason', 'Violation of communications policy'),
            ],
        ]);

        return back()->with('success', 'Message was removed by moderation.');
    }

    /**
     * Display broadcast announcement view.
     */
    public function announcements(): Response
    {
        $recentAnnouncements = AuditLog::with('admin:id,name,email')
            ->where('action', 'broadcast_announcement')
            ->latest()
            ->take(10)
            ->get();

        return Inertia::render('Admin/Announcements', [
            'recentAnnouncements' => $recentAnnouncements,
        ]);
    }

    /**
     * Broadcast an announcement to all users.
     */
    public function broadcastAnnouncement(Request $request): RedirectResponse
    {
        $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'content' => ['required', 'string', 'max:5000'],
        ]);

        $admin = $request->user();
        $title = $request->input('title');
        $content = $request->input('content');

        // Retrieve all active users (excluding current admin)
        $users = User::where('id', '!=', $admin->id)
            ->where('is_banned', false)
            ->get();

        $count = 0;
        foreach ($users as $user) {
            $conversation = Conversation::getOrCreateSystemConversationForUser($user);

            $adminKey = $admin->getOrCreateEncryptionKey();
            $announcementText = "📢 [ANNOUNCEMENT] {$title}\n\n{$content}";
            $encryptedText = MessageEncryptionService::encrypt($announcementText, $adminKey);

            $messageModel = $conversation->messages()->create([
                'sender_id' => $admin->id,
                'body' => $encryptedText,
                'type' => 'system',
            ]);

            $conversation->update(['last_message_at' => now()]);

            // Broadcast real-time event via Reverb
            broadcast(new MessageSent("📢 Announcement: {$title}", $conversation->id, [
                'id' => $messageModel->id,
                'body' => $announcementText,
                'sender_id' => $admin->id,
            ], 'created'));

            $count++;
        }

        AuditLog::create([
            'admin_id' => $admin->id,
            'action' => 'broadcast_announcement',
            'details' => [
                'title' => $title,
                'recipients_count' => $count,
            ],
        ]);

        return back()->with('success', "Announcement broadcasted successfully to {$count} users.");
    }

    /**
     * Display administrative audit logs.
     */
    public function auditLogs(Request $request): Response
    {
        $logs = AuditLog::with('admin:id,name,email')
            ->latest()
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Admin/AuditLogs', [
            'logs' => $logs,
        ]);
    }
}

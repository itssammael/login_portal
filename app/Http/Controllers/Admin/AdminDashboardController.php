<?php

namespace App\Http\Controllers\Admin;

use App\Events\MessageSent;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\Message;
use App\Models\Role;
use App\Models\User;
use App\Services\MessageEncryptionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
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
            ->with(['role:id,name,slug,color', 'section.department:id,name,acronym'])
            ->withCount(['messages', 'conversations']);

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('employee_number', 'like', "%{$search}%")
                    ->orWhere('position', 'like', "%{$search}%");
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

        if ($request->filled('department_id')) {
            $departmentId = $request->input('department_id');
            $query->whereHas('section', function ($q) use ($departmentId): void {
                $q->where('department_id', $departmentId);
            });
        }

        $users = $query->orderByDesc('created_at')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Admin/Users', [
            'users' => $users,
            'filters' => $request->only(['search', 'status', 'role', 'department_id']),
            'roles' => Role::orderBy('name')->get(['id', 'name', 'slug', 'color']),
            'departments' => Department::with(['sections' => fn ($q) => $q->orderBy('name')])->orderBy('name')->get(['id', 'name', 'acronym']),
        ]);
    }

    /**
     * Create a new user account from the admin directory.
     */
    public function storeUser(Request $request): RedirectResponse
    {
        $admin = $request->user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'role_id' => ['nullable', 'exists:roles,id'],
            'section_id' => ['nullable', 'exists:sections,id'],
            'position' => ['nullable', 'string', 'max:255'],
            'employee_number' => ['nullable', 'string', 'max:255'],
            'is_admin' => ['nullable', 'boolean'],
        ]);

        $role = ! empty($validated['role_id']) ? Role::find($validated['role_id']) : null;
        $isAdmin = (bool) ($validated['is_admin'] ?? false) || ($role && ($role->slug === 'admin' || $role->hasPermission('access_admin')));

        $newUser = new User([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'role_id' => $validated['role_id'] ?? null,
            'section_id' => $validated['section_id'] ?? null,
            'position' => $validated['position'] ?? null,
            'employee_number' => $validated['employee_number'] ?? null,
            'is_admin' => $isAdmin,
        ]);
        $newUser->email_verified_at = now();
        $newUser->save();

        AuditLog::create([
            'admin_id' => $admin->id,
            'action' => 'created_user',
            'target_type' => User::class,
            'target_id' => $newUser->id,
            'details' => [
                'name' => $newUser->name,
                'email' => $newUser->email,
                'role' => $role?->name ?? 'User',
                'is_admin' => $newUser->is_admin,
                'position' => $newUser->position,
                'employee_number' => $newUser->employee_number,
                'section_id' => $newUser->section_id,
            ],
        ]);

        return back()->with('success', "User account for {$newUser->name} created successfully.");
    }

    /**
     * Update a user account/profile from the admin directory.
     */
    public function updateUser(Request $request, User $user): RedirectResponse
    {
        $admin = $request->user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
            'role_id' => ['nullable', 'exists:roles,id'],
            'section_id' => ['nullable', 'exists:sections,id'],
            'position' => ['nullable', 'string', 'max:255'],
            'employee_number' => ['nullable', 'string', 'max:255'],
            'is_admin' => ['nullable', 'boolean'],
        ]);

        $role = ! empty($validated['role_id']) ? Role::find($validated['role_id']) : null;

        // Determine admin status
        $isAdmin = isset($validated['is_admin']) ? (bool) $validated['is_admin'] : $user->is_admin;
        if ($role && ($role->slug === 'admin' || $role->hasPermission('access_admin'))) {
            $isAdmin = true;
        }

        // Prevent admin from removing their own admin privilege
        if ($admin->id === $user->id && ! $isAdmin) {
            return back()->with('error', 'You cannot remove your own administrator status.');
        }

        $user->name = $validated['name'];
        $user->email = $validated['email'];
        $user->role_id = $validated['role_id'] ?? null;
        $user->section_id = $validated['section_id'] ?? null;
        $user->position = $validated['position'] ?? null;
        $user->employee_number = $validated['employee_number'] ?? null;
        $user->is_admin = $isAdmin;

        if (! empty($validated['password'])) {
            $user->password = Hash::make($validated['password']);
        }

        $user->save();

        AuditLog::create([
            'admin_id' => $admin->id,
            'action' => 'updated_user_profile',
            'target_type' => User::class,
            'target_id' => $user->id,
            'details' => [
                'name' => $user->name,
                'email' => $user->email,
                'role' => $role?->name ?? 'User',
                'is_admin' => $user->is_admin,
                'position' => $user->position,
                'employee_number' => $user->employee_number,
                'section_id' => $user->section_id,
                'password_changed' => ! empty($validated['password']),
            ],
        ]);

        return back()->with('success', "Profile for {$user->name} updated successfully.");
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

        if (! $admin->canBroadcastAnnouncements()) {
            abort(403, 'Unauthorized access. You do not have permission to broadcast system announcements.');
        }

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
                'content' => $content,
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

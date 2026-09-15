<?php

use App\Events\MessageSent;
use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\DepartmentController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\SsoPortalController;
use App\Http\Controllers\Admin\SystemRuleController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\Sso\ConnectedSystemsController;
use App\Http\Controllers\Sso\SsoProviderController;
use App\Http\Middleware\CheckPendingSsoRequest;
use App\Models\AuditLog;
use App\Models\Sso;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
});

Route::get('/sso/authorize', [SsoProviderController::class, 'authorize'])->name('sso.authorize');

Route::middleware([
    'auth:sanctum',
    config('jetstream.auth_session'),
    'verified',
    CheckPendingSsoRequest::class,
])->group(function () {
    Route::get('/sso/launch/{clientName}', [SsoProviderController::class, 'launch'])->name('sso.launch');

    // Account Binding / Connected Systems Routes
    Route::get('/connected-systems', [ConnectedSystemsController::class, 'index'])->name('sso.connected-systems');
    Route::post('/connected-systems/{clientId}/bind', [ConnectedSystemsController::class, 'bind'])->name('sso.connected-systems.bind');
    Route::delete('/connected-systems/{clientId}/unbind', [ConnectedSystemsController::class, 'unbind'])->name('sso.connected-systems.unbind');

    Route::get('/dashboard', function (Request $request) {
        $latest = AuditLog::with('admin:id,name,email')
            ->where('action', 'broadcast_announcement')
            ->latest()
            ->first();

        $latestAnnouncement = $latest ? [
            'id' => $latest->id,
            'title' => $latest->details['title'] ?? 'System Announcement',
            'content' => $latest->details['content'] ?? 'Important system update has been posted.',
            'author' => $latest->admin?->name ?? 'Administrator',
            'created_at' => $latest->created_at->toIso8601String(),
            'formatted_date' => $latest->created_at->diffForHumans(),
        ] : null;

        $user = $request->user();
        $boundClientIds = $user ? $user->ssoBindings()->pluck('client_id') : collect();

        $ssoClients = Sso::where('is_active', true)
            ->whereIn('client_id', $boundClientIds)
            ->get()
            ->map(function ($client) {
                return [
                    'id' => $client->id,
                    'name' => $client->name,
                    'client_id' => $client->client_id,
                    'icon_url' => $client->icon_url,
                    'launch_url' => route('sso.launch', $client->client_id),
                ];
            });

        return Inertia::render('Dashboard', [
            'latestAnnouncement' => $latestAnnouncement,
            'ssoClients' => $ssoClients,
        ]);
    })->name('dashboard');

    Route::get('/test-broadcast', function () {
        $message = request('message', 'Hello world from Reverb');
        broadcast(new MessageSent($message));

        return response()->json([
            'status' => 'Event broadcasted successfully',
            'message' => $message,
            'connection' => config('broadcasting.default'),
        ]);
    })->name('test-broadcast');

    // Chat / Messenger Routes
    Route::get('/chat', [ChatController::class, 'index'])->name('chat.index');
    Route::get('/chat/messages/{message}/attachment', [ChatController::class, 'downloadAttachment'])->name('chat.download-attachment');
    Route::post('/chat/direct', [ChatController::class, 'startDirect'])->name('chat.start-direct');
    Route::post('/chat/group', [ChatController::class, 'createGroup'])->name('chat.create-group');
    Route::post('/chat/{conversation}/messages', [ChatController::class, 'sendMessage'])->name('chat.send-message');
    Route::post('/chat/{conversation}/read', [ChatController::class, 'markAsRead'])->name('chat.mark-read');
    Route::post('/chat/messages/{message}/reaction', [ChatController::class, 'toggleReaction'])->name('chat.reaction');
    Route::delete('/chat/messages/{message}', [ChatController::class, 'deleteMessage'])->name('chat.delete-message');

    // Admin Panel Routes
    Route::prefix('admin')->middleware('admin')->name('admin.')->group(function () {
        Route::get('/', [AdminDashboardController::class, 'index'])->name('dashboard');
        Route::get('/users', [AdminDashboardController::class, 'users'])->name('users');
        Route::post('/users', [AdminDashboardController::class, 'storeUser'])->name('users.store');
        Route::put('/users/{user}', [AdminDashboardController::class, 'updateUser'])->name('users.update');
        Route::post('/users/{user}/toggle-ban', [AdminDashboardController::class, 'toggleBan'])->name('users.toggle-ban');
        Route::post('/users/{user}/toggle-admin', [AdminDashboardController::class, 'toggleAdmin'])->name('users.toggle-admin');
        Route::post('/users/{user}/role', [AdminDashboardController::class, 'updateUserRole'])->name('users.update-role');
        Route::get('/departments', [DepartmentController::class, 'index'])->name('departments.index');
        Route::post('/departments', [DepartmentController::class, 'store'])->name('departments.store');
        Route::put('/departments/{department}', [DepartmentController::class, 'update'])->name('departments.update');
        Route::delete('/departments/{department}', [DepartmentController::class, 'destroy'])->name('departments.destroy');
        Route::post('/departments/{department}/sections', [DepartmentController::class, 'storeSection'])->name('departments.sections.store');
        Route::put('/sections/{section}', [DepartmentController::class, 'updateSection'])->name('sections.update');
        Route::delete('/sections/{section}', [DepartmentController::class, 'destroySection'])->name('sections.destroy');
        Route::get('/roles', [RoleController::class, 'index'])->name('roles.index');
        Route::post('/roles', [RoleController::class, 'store'])->name('roles.store');
        Route::put('/roles/{role}', [RoleController::class, 'update'])->name('roles.update');
        Route::delete('/roles/{role}', [RoleController::class, 'destroy'])->name('roles.destroy');
        Route::get('/rules', [SystemRuleController::class, 'index'])->name('rules.index');
        Route::post('/rules', [SystemRuleController::class, 'store'])->name('rules.store');
        Route::put('/rules/{systemRule}', [SystemRuleController::class, 'update'])->name('rules.update');
        Route::post('/rules/{systemRule}/toggle', [SystemRuleController::class, 'toggle'])->name('rules.toggle');
        Route::delete('/rules/{systemRule}', [SystemRuleController::class, 'destroy'])->name('rules.destroy');
        Route::get('/chats', [AdminDashboardController::class, 'chats'])->name('chats');
        Route::delete('/messages/{message}', [AdminDashboardController::class, 'deleteMessage'])->name('messages.delete');
        Route::get('/announcements', [AdminDashboardController::class, 'announcements'])->name('announcements');
        Route::post('/announcements/broadcast', [AdminDashboardController::class, 'broadcastAnnouncement'])->name('announcements.broadcast');
        Route::get('/audit-logs', [AdminDashboardController::class, 'auditLogs'])->name('audit-logs');
        Route::get('/sso', [SsoPortalController::class, 'index'])->name('sso.index');
        Route::post('/sso', [SsoPortalController::class, 'store'])->name('sso.store');
        Route::match(['put', 'post'], '/sso/{sso}', [SsoPortalController::class, 'update'])->name('sso.update');
        Route::post('/sso/{sso}/toggle', [SsoPortalController::class, 'toggle'])->name('sso.toggle');
        Route::post('/sso/{sso}/regenerate-secret', [SsoPortalController::class, 'regenerateSecret'])->name('sso.regenerate-secret');
        Route::delete('/sso/{sso}', [SsoPortalController::class, 'destroy'])->name('sso.destroy');
    });
});

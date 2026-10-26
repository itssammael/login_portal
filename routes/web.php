<?php

use App\Events\MessageSent;
use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\DepartmentController;
use App\Http\Controllers\Admin\FeedbackController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\SecurityController;
use App\Http\Controllers\Admin\SsoPortalController;
use App\Http\Controllers\Admin\SystemAppearanceController;
use App\Http\Controllers\Admin\SystemRuleController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\FeedbackAnalyticsController;
use App\Http\Controllers\FeedbackAnalyticsPageController;
use App\Http\Controllers\FeedbackEmbedController;
use App\Http\Controllers\FeedbackFormController;
use App\Http\Controllers\LiveFeedbackFormController;
use App\Http\Controllers\Sso\ConnectedSystemsController;
use App\Http\Controllers\Sso\SsoProviderController;
use App\Http\Middleware\CheckPendingSsoRequest;
use App\Models\AuditLog;
use App\Models\Sso;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    if (auth()->check()) {
        return redirect()->route('dashboard');
    }

    return redirect()->route('login');
});

Route::post('_boost/browser_logs', fn () => response()->noContent());

// Public Live Deployed Feedback Form Routes
Route::get('/forms/anal/{api_key}', [FeedbackAnalyticsPageController::class, 'show'])->middleware('throttle:60,1')->name('forms.analytics.public');

Route::get('/forms/e/{api_key}', [LiveFeedbackFormController::class, 'show'])->name('forms.live');
Route::post('/forms/e/{api_key}', [LiveFeedbackFormController::class, 'submit'])->middleware('throttle:30,1')->name('forms.live.submit');

// Public Embedded Feedback Form Routes (Session-authenticated & frame-protected)
Route::get('/embed/feedback/{publicId}', [FeedbackEmbedController::class, 'show'])->name('feedback.embed.show');
Route::post('/embed/feedback/{publicId}', [FeedbackEmbedController::class, 'submit'])->middleware('throttle:30,1')->name('feedback.embed.submit');

Route::get('/sso/authorize', [SsoProviderController::class, 'authorize'])->middleware('throttle:60,1')->name('sso.authorize');
Route::post('/sso/token', [SsoProviderController::class, 'token'])->middleware('throttle:60,1')->name('sso.token');

Route::middleware([
    'auth:sanctum',
    config('jetstream.auth_session'),
    'verified',
    CheckPendingSsoRequest::class,
])->group(function () {
    Route::get('/sso/launch/{clientName}', [SsoProviderController::class, 'launch'])->name('sso.launch');

    // Account Binding / Connected Systems Routes
    Route::get('/connected-systems', [ConnectedSystemsController::class, 'index'])->name('sso.connected-systems');
    Route::post('/connected-systems/{clientId}/bind', [ConnectedSystemsController::class, 'bind'])->middleware('throttle:10,1')->name('sso.connected-systems.bind');
    Route::delete('/connected-systems/{clientId}/unbind', [ConnectedSystemsController::class, 'unbind'])->name('sso.connected-systems.unbind');

    // Settings SSO Aliases
    Route::get('/settings/sso', [ConnectedSystemsController::class, 'index'])->name('settings.sso');
    Route::post('/settings/sso/{clientId}/bind', [ConnectedSystemsController::class, 'bind'])->middleware('throttle:10,1')->name('settings.sso.bind');
    Route::delete('/settings/sso/{clientId}/unbind', [ConnectedSystemsController::class, 'unbind'])->name('settings.sso.unbind');

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
        if (! app()->environment('local') && ! auth()->user()?->canAccessAdmin()) {
            abort(403, 'Unauthorized broadcast test access.');
        }

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
    Route::get('/chat/contacts', [ChatController::class, 'searchContacts'])->name('chat.contacts');
    Route::get('/chat/unread-count', [ChatController::class, 'unreadCount'])->name('chat.unread-count');
    Route::get('/chat/{conversation}/messages', [ChatController::class, 'messages'])->name('chat.messages');
    Route::get('/chat/{conversation}/search', [ChatController::class, 'searchMessages'])->name('chat.search-messages');
    Route::get('/chat/messages/{message}/attachment', [ChatController::class, 'downloadAttachment'])->name('chat.download-attachment');
    Route::post('/chat/direct', [ChatController::class, 'startDirect'])->name('chat.start-direct');
    Route::post('/chat/group', [ChatController::class, 'createGroup'])->name('chat.create-group');
    Route::patch('/chat/{conversation}/title', [ChatController::class, 'renameGroup'])->name('chat.rename-group');
    Route::post('/chat/{conversation}/members', [ChatController::class, 'addMembers'])->name('chat.add-members');
    Route::delete('/chat/{conversation}/members/{user}', [ChatController::class, 'removeMember'])->name('chat.remove-member');
    Route::post('/chat/{conversation}/members/{user}/promote', [ChatController::class, 'promoteMember'])->name('chat.promote-member');
    Route::delete('/chat/{conversation}', [ChatController::class, 'deleteGroup'])->name('chat.delete-group');
    Route::post('/chat/{conversation}/messages', [ChatController::class, 'sendMessage'])->middleware('throttle:60,1')->name('chat.send-message');
    Route::patch('/chat/messages/{message}', [ChatController::class, 'editMessage'])->name('chat.edit-message');
    Route::post('/chat/{conversation}/read', [ChatController::class, 'markAsRead'])->name('chat.mark-read');
    Route::post('/chat/mark-all-read', [ChatController::class, 'markAllAsRead'])->name('chat.mark-all-read');
    Route::post('/chat/{conversation}/pin', [ChatController::class, 'togglePin'])->name('chat.toggle-pin');
    Route::post('/chat/{conversation}/mute', [ChatController::class, 'toggleMute'])->name('chat.toggle-mute');
    Route::post('/chat/{conversation}/typing', [ChatController::class, 'typing'])->name('chat.typing');
    Route::post('/chat/messages/{message}/reaction', [ChatController::class, 'toggleReaction'])->name('chat.reaction');
    Route::delete('/chat/messages/{message}', [ChatController::class, 'deleteMessage'])->name('chat.delete-message');

    // User Feedback Form Routes
    Route::get('/feedback', [FeedbackFormController::class, 'show'])->name('feedback.form');
    Route::post('/feedback', [FeedbackFormController::class, 'submit'])->name('feedback.submit');

    Route::prefix('feedback/{feedback}/analytics')->name('feedback.analytics.')->middleware('throttle:60,1')->group(function () {
        Route::get('/publication', [FeedbackAnalyticsController::class, 'index'])->name('publication');
        Route::post('/preview', [FeedbackAnalyticsController::class, 'preview'])->name('preview');
        Route::post('/reports', [FeedbackAnalyticsController::class, 'store'])->name('store');
        Route::put('/reports/{report}', [FeedbackAnalyticsController::class, 'update'])->name('update');
        Route::post('/reports/{report}/publish', [FeedbackAnalyticsController::class, 'publish'])->name('publish');
        Route::post('/reports/{report}/unpublish', [FeedbackAnalyticsController::class, 'unpublish'])->name('unpublish');
    });

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
        Route::get('/appearance', [SystemAppearanceController::class, 'index'])->name('appearance.index');
        Route::post('/appearance', [SystemAppearanceController::class, 'update'])->name('appearance.update');

        // Security Command Center & Self-Healing Routes
        Route::prefix('security')->name('security.')->group(function (): void {
            Route::get('/', [SecurityController::class, 'index'])->name('index');
            Route::post('/block-ip', [SecurityController::class, 'blockIp'])->name('block-ip');
            Route::delete('/unblock-ip/{blockedIp}', [SecurityController::class, 'unblockIp'])->name('unblock-ip');
            Route::post('/whitelist-ip', [SecurityController::class, 'whitelistIp'])->name('whitelist-ip');
            Route::delete('/whitelist-ip/{whitelistedIp}', [SecurityController::class, 'removeWhitelistIp'])->name('remove-whitelist-ip');
            Route::post('/whitelist-ip/{whitelistedIp}/toggle', [SecurityController::class, 'toggleWhitelistIp'])->name('toggle-whitelist-ip');
            Route::post('/resolve/{threatLog}', [SecurityController::class, 'resolveThreat'])->name('resolve');
            Route::post('/self-heal', [SecurityController::class, 'runAuditAndHeal'])->name('self-heal');
            Route::post('/toggle-auto-defense', [SecurityController::class, 'toggleAutoDefense'])->name('toggle-auto-defense');
            Route::post('/clear-old-logs', [SecurityController::class, 'clearOldLogs'])->name('clear-old-logs');
        });

        // Feedback Module Admin Routes
        Route::prefix('feedback')->name('feedback.')->group(function () {
            Route::get('/setup', [FeedbackController::class, 'setup'])->name('setup');
            Route::get('/submissions', [FeedbackController::class, 'submissions'])->name('submissions');

            // Events CRUD, API Key & Embed
            Route::post('/events', [FeedbackController::class, 'storeEvent'])->name('events.store');
            Route::put('/events/{event}', [FeedbackController::class, 'updateEvent'])->name('events.update');
            Route::post('/events/{event}/regenerate-key', [FeedbackController::class, 'regenerateApiKey'])->name('events.regenerate-key');
            Route::post('/events/{event}/regenerate-embed-id', [FeedbackController::class, 'regenerateEmbedId'])->name('events.regenerate-embed-id');
            Route::post('/events/{event}/regenerate-embed-secret', [FeedbackController::class, 'regenerateEmbedSecret'])->name('events.regenerate-embed-secret');
            Route::put('/events/{event}/update-embed-origins', [FeedbackController::class, 'updateEmbedOrigins'])->name('events.update-embed-origins');
            Route::delete('/events/{event}', [FeedbackController::class, 'destroyEvent'])->name('events.destroy');

            // Functions CRUD
            Route::post('/functions', [FeedbackController::class, 'storeFunction'])->name('functions.store');
            Route::put('/functions/{function}', [FeedbackController::class, 'updateFunction'])->name('functions.update');
            Route::delete('/functions/{function}', [FeedbackController::class, 'destroyFunction'])->name('functions.destroy');

            // Agencies CRUD
            Route::post('/agencies', [FeedbackController::class, 'storeAgency'])->name('agencies.store');
            Route::delete('/agencies/{agency}', [FeedbackController::class, 'destroyAgency'])->name('agencies.destroy');

            // Designations CRUD
            Route::post('/designations', [FeedbackController::class, 'storeDesignation'])->name('designations.store');
            Route::delete('/designations/{designation}', [FeedbackController::class, 'destroyDesignation'])->name('designations.destroy');

            // Feedback Form Configuration, Status & Import
            Route::post('/forms', [FeedbackController::class, 'saveForm'])->name('forms.save');
            Route::post('/forms/{feedback}/status', [FeedbackController::class, 'toggleFormStatus'])->name('forms.status');
            Route::post('/forms/import', [FeedbackController::class, 'importForm'])->name('forms.import');
        });

        // SSO Client Management Aliases
        Route::get('/sso-clients', [SsoPortalController::class, 'index'])->name('sso-clients.index');
        Route::post('/sso-clients', [SsoPortalController::class, 'store'])->name('sso-clients.store');
        Route::match(['put', 'post'], '/sso-clients/{sso}', [SsoPortalController::class, 'update'])->name('sso-clients.update');
        Route::post('/sso-clients/{sso}/toggle', [SsoPortalController::class, 'toggle'])->name('sso-clients.toggle');
        Route::post('/sso-clients/{sso}/rotate-secret', [SsoPortalController::class, 'regenerateSecret'])->name('sso-clients.rotate-secret');
        Route::post('/sso-clients/{sso}/disable', [SsoPortalController::class, 'disable'])->name('sso-clients.disable');
        Route::post('/sso-clients/{sso}/enable', [SsoPortalController::class, 'enable'])->name('sso-clients.enable');
        Route::delete('/sso-clients/{sso}', [SsoPortalController::class, 'destroy'])->name('sso-clients.destroy');
    });
});

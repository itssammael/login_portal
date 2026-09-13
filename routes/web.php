<?php

use App\Events\MessageSent;
use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\SystemRuleController;
use App\Http\Controllers\ChatController;
use Illuminate\Foundation\Application;
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

Route::middleware([
    'auth:sanctum',
    config('jetstream.auth_session'),
    'verified',
])->group(function () {
    Route::get('/dashboard', function () {
        return Inertia::render('Dashboard');
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
        Route::post('/users/{user}/toggle-ban', [AdminDashboardController::class, 'toggleBan'])->name('users.toggle-ban');
        Route::post('/users/{user}/toggle-admin', [AdminDashboardController::class, 'toggleAdmin'])->name('users.toggle-admin');
        Route::post('/users/{user}/role', [AdminDashboardController::class, 'updateUserRole'])->name('users.update-role');
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
    });
});

<?php

use App\Http\Controllers\Api\FeedbackApiController;
use App\Http\Controllers\Api\FeedbackEmbedSessionApiController;
use App\Http\Controllers\Api\LguActivityController;
use App\Http\Controllers\Sso\SsoProviderController;
use App\Http\Middleware\AuthenticateFeedbackApiKey;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::post('/sso/token', [SsoProviderController::class, 'token'])->middleware('throttle:60,1')->name('api.sso.token');
Route::get('/sso/userinfo', [SsoProviderController::class, 'userinfo'])->name('api.sso.userinfo');

Route::get('/lgu-activities', [LguActivityController::class, 'index'])->name('api.lgu-activities');

// External Backend Embed Session Generation (Authenticated via Client ID and Client Secret)
Route::post('/feedback/embed/sessions', [FeedbackEmbedSessionApiController::class, 'store'])
    ->middleware('throttle:60,1')
    ->name('api.feedback.embed.sessions');

// Public Feedback API Routes (Protected by Event API Key and rate-limiting)
Route::middleware(['throttle:60,1', AuthenticateFeedbackApiKey::class])->prefix('feedback')->name('api.feedback.')->group(function () {
    Route::get('/form', [FeedbackApiController::class, 'form'])->name('form');
    Route::get('/lookups', [FeedbackApiController::class, 'lookups'])->name('lookups');
    Route::post('/submissions', [FeedbackApiController::class, 'submit'])->name('submissions');
});

Route::get('/announcements/latest', function () {
    $latest = AuditLog::with('admin:id,name,email')
        ->where('action', 'broadcast_announcement')
        ->latest()
        ->first();

    if (! $latest) {
        return response()->json([
            'status' => 'empty',
            'data' => null,
        ]);
    }

    return response()->json([
        'status' => 'success',
        'data' => [
            'id' => $latest->id,
            'title' => $latest->details['title'] ?? 'System Announcement',
            'content' => $latest->details['content'] ?? 'Important system update has been posted.',
            'recipients_count' => $latest->details['recipients_count'] ?? 0,
            'author' => $latest->admin?->name ?? 'System Administrator',
            'created_at' => $latest->created_at->toIso8601String(),
            'formatted_date' => $latest->created_at->diffForHumans(),
        ],
    ]);
})->name('api.announcements.latest');

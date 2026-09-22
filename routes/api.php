<?php

use App\Http\Controllers\Api\LguActivityController;
use App\Http\Controllers\Sso\SsoProviderController;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::post('/sso/token', [SsoProviderController::class, 'token'])->middleware('throttle:60,1')->name('api.sso.token');
Route::get('/sso/userinfo', [SsoProviderController::class, 'userinfo'])->name('api.sso.userinfo');

Route::get('/lgu-activities', [LguActivityController::class, 'index'])->name('api.lgu-activities');

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

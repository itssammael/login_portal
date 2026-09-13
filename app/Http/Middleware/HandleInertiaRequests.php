<?php

namespace App\Http\Middleware;

use App\Models\ConversationParticipant;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        $unreadCount = 0;
        if ($user) {
            $unreadCount = ConversationParticipant::query()
                ->where('user_id', $user->id)
                ->join('messages', 'messages.conversation_id', '=', 'conversation_participants.conversation_id')
                ->where('messages.sender_id', '!=', $user->id)
                ->where('messages.is_deleted', false)
                ->where(function ($query) {
                    $query->whereNull('conversation_participants.last_read_at')
                        ->orWhereColumn('messages.created_at', '>', 'conversation_participants.last_read_at');
                })
                ->count();
        }

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user ? array_merge($user->toArray(), [
                    'is_admin' => $user->isAdmin(),
                ]) : null,
            ],
            'unread_messages_count' => $unreadCount,
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
        ];
    }
}

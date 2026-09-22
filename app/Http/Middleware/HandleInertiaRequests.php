<?php

namespace App\Http\Middleware;

use App\Models\SsoClient;
use App\Models\SystemSetting;
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

        $unreadCount = $user ? $user->unreadMessagesCount() : 0;

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user ? array_merge($user->toArray(), [
                    'is_admin' => $user->isAdmin(),
                    'can_access_admin' => $user->canAccessAdmin(),
                    'can_broadcast_announcements' => $user->canBroadcastAnnouncements(),
                    'role' => $user->role ? [
                        'id' => $user->role->id,
                        'name' => $user->role->name,
                        'slug' => $user->role->slug,
                    ] : null,
                    'section' => $user->section ? [
                        'id' => $user->section->id,
                        'name' => $user->section->name,
                        'department' => $user->section->department ? [
                            'id' => $user->section->department->id,
                            'name' => $user->section->department->name,
                            'acronym' => $user->section->department->acronym,
                        ] : null,
                    ] : null,
                ]) : null,
            ],
            'unread_messages_count' => $unreadCount,
            'sso_applications' => fn () => $user ? SsoClient::where('is_active', true)->get()->map(function ($client) use ($user) {
                $binding = $user->ssoBindings()->where('client_id', $client->client_id)->first();

                return [
                    'id' => $client->id,
                    'client_id' => $client->client_id,
                    'name' => $client->name,
                    'description' => $client->description,
                    'icon_url' => $client->icon_url,
                    'is_bound' => ! is_null($binding),
                    'bound_username' => $binding?->external_username,
                    'bound_at' => $binding?->created_at?->diffForHumans(),
                    'launch_url' => route('sso.launch', $client->client_id),
                ];
            }) : [],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
            'system_appearance' => [
                'name' => SystemSetting::get('system_name', config('app.name', 'LGUNET Portal')),
                'logo_url' => SystemSetting::getLogoUrl(),
                'favicon_url' => SystemSetting::getFaviconUrl(),
            ],
        ];
    }
}

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <script>
            // Suppress third-party browser extension message channel errors from cluttering the console
            window.addEventListener('unhandledrejection', function (event) {
                var reason = event && (event.reason ? (event.reason.message || event.reason) : '');
                if (typeof reason === 'string' && (
                    reason.indexOf('message channel closed before a response was received') !== -1 ||
                    reason.indexOf('A listener indicated an asynchronous response by returning true') !== -1
                )) {
                    event.preventDefault();
                }
            });
        </script>
        @if (request()->isSecure())
            <meta http-equiv="Content-Security-Policy" content="upgrade-insecure-requests">
        @endif

        <title inertia>{{ \App\Models\SystemSetting::get('system_name', config('app.name', 'LGUNET Portal')) }}</title>
        <link rel="icon" id="dynamic-favicon" href="{{ \App\Models\SystemSetting::getFaviconUrl() ?? asset('favicon.ico') }}">

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        <script
            src="https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit"
            async
            defer
        ></script>
        @routes
        @vite(['resources/js/app.js', "resources/js/Pages/{$page['component']}.vue"])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>

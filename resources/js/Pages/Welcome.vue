<script setup>
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    canLogin: {
        type: Boolean,
    },
    canRegister: {
        type: Boolean,
    },
    laravelVersion: {
        type: String,
        required: true,
    },
    phpVersion: {
        type: String,
        required: true,
    },
});

function handleImageError() {
    document.getElementById('screenshot-container')?.classList.add('!hidden');
    document.getElementById('docs-card')?.classList.add('!row-span-1');
    document.getElementById('docs-card-content')?.classList.add('!flex-row');
    document.getElementById('background')?.classList.add('!hidden');
}
</script>

<template>
    <Head title="Welcome" />
    <div class="bg-cream-300 text-gray-800 min-h-screen selection:bg-forest-900 selection:text-white">
        <div class="relative min-h-screen flex flex-col items-center justify-center">
            <div class="relative w-full max-w-2xl px-6 lg:max-w-7xl py-10">
                <header class="grid grid-cols-2 items-center gap-2 pb-10 lg:grid-cols-3">
                    <div class="flex lg:justify-center lg:col-start-2">
                        <img
                            v-if="$page.props.system_appearance?.logo_url"
                            :src="$page.props.system_appearance.logo_url"
                            :alt="$page.props.system_appearance?.name || 'System Logo'"
                            class="h-12 w-auto object-contain lg:h-16"
                        />
                        <svg v-else class="h-12 w-auto text-forest-900 lg:h-16" viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M11.395 44.428C4.557 40.198 0 32.632 0 24 0 10.745 10.745 0 24 0a23.891 23.891 0 0113.997 4.502c-.2 17.907-11.097 33.245-26.602 39.926z" fill="#1b4332" />
                            <path d="M14.134 45.885A23.914 23.914 0 0024 48c13.255 0 24-10.745 24-24 0-3.516-.756-6.856-2.115-9.866-4.659 15.143-16.608 27.092-31.75 31.751z" fill="#1b4332" />
                        </svg>
                    </div>
                    <nav v-if="canLogin" class="-mx-3 flex flex-1 justify-end items-center space-x-2">
                        <Link
                            v-if="$page.props.auth.user"
                            :href="route('dashboard')"
                            class="px-4 py-2 bg-forest-900 hover:bg-forest-950 text-white text-sm font-semibold rounded-xl shadow-xs transition"
                        >
                            Dashboard
                        </Link>

                        <template v-else>
                            <Link
                                :href="route('login')"
                                class="px-4 py-2 text-gray-800 hover:text-forest-900 text-sm font-semibold rounded-xl hover:bg-cream-200 transition"
                            >
                                Log in
                            </Link>

                            <Link
                                v-if="canRegister"
                                :href="route('register')"
                                class="px-4 py-2 bg-forest-900 hover:bg-forest-950 text-white text-sm font-semibold rounded-xl shadow-xs transition"
                            >
                                Register
                            </Link>
                        </template>
                    </nav>
                </header>

                <main class="mt-6">
                    <div class="grid gap-6 lg:grid-cols-2 lg:gap-8">
                        <a
                            href="https://laravel.com/docs"
                            id="docs-card"
                            class="flex flex-col items-start gap-6 overflow-hidden rounded-2xl bg-cream-200 border border-cream-500/50 p-6 shadow-xs transition duration-300 hover:shadow-md hover:border-cream-500 md:row-span-3 lg:p-10 lg:pb-10 group"
                        >
                            <div id="screenshot-container" class="relative flex w-full flex-1 items-stretch">
                                <img
                                    src="https://laravel.com/assets/img/welcome/docs-light.svg"
                                    alt="Laravel documentation screenshot"
                                    class="aspect-video h-full w-full flex-1 rounded-xl object-top object-cover drop-shadow-xs"
                                    @error="handleImageError"
                                />
                                <div
                                    class="absolute -bottom-16 -left-16 h-40 w-[calc(100%+8rem)] bg-gradient-to-b from-transparent via-cream-200 to-cream-200"
                                ></div>
                            </div>

                            <div class="relative flex items-center gap-6 lg:items-end w-full">
                                <div id="docs-card-content" class="flex items-start gap-6 lg:flex-col flex-1">
                                    <div class="flex size-12 shrink-0 items-center justify-center rounded-2xl bg-forest-900 text-white sm:size-14 shadow-xs">
                                        <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.967 8.967 0 00-6 2.292m0-14.25v14.25" />
                                        </svg>
                                    </div>

                                    <div class="pt-3 sm:pt-5 lg:pt-0">
                                        <h2 class="text-xl font-bold text-gray-900">Documentation</h2>

                                        <p class="mt-4 text-sm/relaxed text-gray-700">
                                            Local Government Unit (LGU) Portal offers comprehensive tools and integrated management systems covering administration, monitoring, communication metrics, and citizen services.
                                        </p>
                                    </div>
                                </div>

                                <svg class="size-6 shrink-0 text-forest-900 group-hover:translate-x-1 transition-transform" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12h15m0 0l-6.75-6.75M19.5 12l-6.75 6.75"/></svg>
                            </div>
                        </a>

                        <div
                            class="flex items-start gap-4 rounded-2xl bg-cream-200 border border-cream-500/50 p-6 shadow-xs transition duration-300 hover:shadow-md hover:border-cream-500 lg:pb-10"
                        >
                            <div class="flex size-12 shrink-0 items-center justify-center rounded-2xl bg-lime-200 text-forest-900 sm:size-14 shadow-xs">
                                <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M8.625 12a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H8.25m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H12m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 01-2.555-.337A5.972 5.972 0 015.41 20.97a5.969 5.969 0 01-.474-.065 4.48 4.48 0 00.978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25z" />
                                </svg>
                            </div>

                            <div class="pt-1">
                                <h2 class="text-lg font-bold text-gray-900">Direct Messaging & Collaboration</h2>

                                <p class="mt-2 text-sm/relaxed text-gray-700">
                                    Instant real-time communication between administrators, officers, and community members with encrypted messaging and media attachments.
                                </p>
                            </div>
                        </div>

                        <div
                            class="flex items-start gap-4 rounded-2xl bg-cream-200 border border-cream-500/50 p-6 shadow-xs transition duration-300 hover:shadow-md hover:border-cream-500 lg:pb-10"
                        >
                            <div class="flex size-12 shrink-0 items-center justify-center rounded-2xl bg-golden-200 text-golden-800 sm:size-14 shadow-xs">
                                <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M10.34 15.84c-.688-.06-1.386-.09-2.09-.09H7.5a4.5 4.5 0 110-9h.75c.704 0 1.402-.03 2.09-.09m0 9.18c.253.962.584 1.892.985 2.783.247.55.06 1.21-.463 1.511l-.657.38c-.551.318-1.26.117-1.527-.455a20.01 20.01 0 01-1.68-4.219m3.342-.001c.421-.037.842-.083 1.262-.137m0 0a20.088 20.088 0 001.32-6.496m-1.32 6.496a20.088 20.088 0 01-1.32-6.496m0 0c.42-.054.841-.1 1.262-.137m0 0c1.077-.14 2.162-.213 3.25-.213h.75a4.5 4.5 0 110 9h-.75c-1.088 0-2.173-.073-3.25-.213" />
                                </svg>
                            </div>

                            <div class="pt-1">
                                <h2 class="text-lg font-bold text-gray-900">Broadcast Announcements</h2>

                                <p class="mt-2 text-sm/relaxed text-gray-700">
                                    Publish high-priority system announcements and emergency broadcasts directly to all users with instant real-time updates.
                                </p>
                            </div>
                        </div>

                        <div class="flex items-start gap-4 rounded-2xl bg-cream-200 border border-cream-500/50 p-6 shadow-xs transition duration-300 hover:shadow-md hover:border-cream-500 lg:pb-10">
                            <div class="flex size-12 shrink-0 items-center justify-center rounded-2xl bg-cream-100 text-forest-900 sm:size-14 shadow-xs border border-cream-500/40">
                                <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-2.171 7.143a9 9 0 01-6.195-2.613l-.337-.337a9 9 0 01-2.613-6.195 9 9 0 012.613-6.195l.337-.337a9 9 0 016.195-2.613 9 9 0 016.195 2.613l.337.337a9 9 0 012.613 6.195 9 9 0 01-2.613 6.195l-.337.337a9 9 0 01-6.195 2.613z" />
                                </svg>
                            </div>

                            <div class="pt-1">
                                <h2 class="text-lg font-bold text-gray-900">Audit & Governance</h2>

                                <p class="mt-2 text-sm/relaxed text-gray-700">
                                    Full audit trail tracking administrative actions, role management, moderation decisions, and system rules enforcement.
                                </p>
                            </div>
                        </div>
                    </div>
                </main>

                <footer class="py-16 text-center text-xs text-gray-500">
                    Local Government Portal &bull; Laravel v{{ laravelVersion }} (PHP v{{ phpVersion }})
                </footer>
            </div>
        </div>
    </div>
</template>

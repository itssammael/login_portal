<script setup>
import { Link } from '@inertiajs/vue3';
</script>

<template>
    <div class="bg-forest-900 mb-6 rounded-2xl shadow-md px-4 border border-forest-800">
        <nav class="flex space-x-2 sm:space-x-3 overflow-x-auto py-2.5" aria-label="Admin Tabs">
            <Link
                :href="route('admin.dashboard')"
                class="px-3.5 py-2 text-sm font-semibold rounded-xl transition-all whitespace-nowrap flex items-center space-x-2"
                :class="route().current('admin.dashboard') ? 'bg-[#2d6a4f] text-white shadow-xs' : 'text-emerald-100/75 hover:text-white hover:bg-forest-800/60'"
            >
                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 016 3.75h2.25A2.25 2.25 0 0110.5 6v2.25a2.25 2.25 0 01-2.25 2.25H6a2.25 2.25 0 01-2.25-2.25V6zM3.75 15.75A2.25 2.25 0 016 13.5h2.25a2.25 2.25 0 012.25 2.25V18a2.25 2.25 0 01-2.25 2.25H6A2.25 2.25 0 013.75 18v-2.25zM13.5 6a2.25 2.25 0 012.25-2.25H18A2.25 2.25 0 0120.25 6v2.25A2.25 2.25 0 0118 10.5h-2.25a2.25 2.25 0 01-2.25-2.25V6zM13.5 15.75a2.25 2.25 0 012.25-2.25H18a2.25 2.25 0 012.25 2.25V18A2.25 2.25 0 0118 20.25h-2.25A2.25 2.25 0 0113.5 18v-2.25z" />
                </svg>
                <span>Overview</span>
            </Link>

            <Link
                :href="route('admin.users')"
                class="px-3.5 py-2 text-sm font-semibold rounded-xl transition-all whitespace-nowrap flex items-center space-x-2"
                :class="route().current('admin.users') ? 'bg-[#2d6a4f] text-white shadow-xs' : 'text-emerald-100/75 hover:text-white hover:bg-forest-800/60'"
            >
                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" />
                </svg>
                <span>Users</span>
            </Link>

            <Link
                :href="route('admin.roles.index')"
                class="px-3.5 py-2 text-sm font-semibold rounded-xl transition-all whitespace-nowrap flex items-center space-x-2"
                :class="route().current('admin.roles.*') ? 'bg-[#2d6a4f] text-white shadow-xs' : 'text-emerald-100/75 hover:text-white hover:bg-forest-800/60'"
            >
                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z" />
                </svg>
                <span>Roles & Permissions</span>
            </Link>

            <Link
                :href="route('admin.rules.index')"
                class="px-3.5 py-2 text-sm font-semibold rounded-xl transition-all whitespace-nowrap flex items-center space-x-2"
                :class="route().current('admin.rules.*') ? 'bg-[#2d6a4f] text-white shadow-xs' : 'text-emerald-100/75 hover:text-white hover:bg-forest-800/60'"
            >
                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 6h9.75M10.5 6a1.5 1.5 0 11-3 0m3 0a1.5 1.5 0 10-3 0M3.75 6H7.5m3 12h9.75m-9.75 0a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m-3.75 0H7.5m9-6h3.75m-3.75 0a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m-9.75 0h9.75" />
                </svg>
                <span>System Rules Engine</span>
            </Link>

            <Link
                :href="route('admin.chats')"
                class="px-3.5 py-2 text-sm font-semibold rounded-xl transition-all whitespace-nowrap flex items-center space-x-2"
                :class="route().current('admin.chats') ? 'bg-[#2d6a4f] text-white shadow-xs' : 'text-emerald-100/75 hover:text-white hover:bg-forest-800/60'"
            >
                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8.625 12a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H8.25m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H12m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 01-2.555-.337A5.972 5.972 0 015.41 20.97a5.969 5.969 0 01-.474-.065 4.48 4.48 0 00.978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25z" />
                </svg>
                <span>Chat Moderation</span>
            </Link>

            <Link
                :href="route('admin.announcements')"
                class="px-3.5 py-2 text-sm font-semibold rounded-xl transition-all whitespace-nowrap flex items-center space-x-2"
                :class="route().current('admin.announcements') ? 'bg-[#2d6a4f] text-white shadow-xs' : 'text-emerald-100/75 hover:text-white hover:bg-forest-800/60'"
            >
                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M10.34 15.84c-.688-.06-1.386-.09-2.09-.09H7.5a4.5 4.5 0 110-9h.75c.704 0 1.402-.03 2.09-.09m0 9.18c.253.962.584 1.892.985 2.783.247.55.06 1.21-.463 1.511l-.657.38c-.551.318-1.26.117-1.527-.461a20.845 20.845 0 01-1.44-4.282m3.102.069a18.03 18.03 0 01-.59-4.59c0-1.586.205-3.124.59-4.59m0 9.18a23.848 23.848 0 018.835 2.535M10.34 6.66a23.847 23.847 0 008.835-2.535m0 0A23.74 23.74 0 0018.795 3m.38 1.125a23.91 23.91 0 011.014 5.395m-1.014 8.855c-.118.38-.245.754-.38 1.125m.38-1.125a23.91 23.91 0 001.014-5.395m0-3.46c.495.41.811 1.035.811 1.73 0 .695-.316 1.32-.811 1.73m0-3.46a24.347 24.347 0 010 3.46" />
                </svg>
                <span>Announcements</span>
            </Link>

            <Link
                :href="route('admin.audit-logs')"
                class="px-3.5 py-2 text-sm font-semibold rounded-xl transition-all whitespace-nowrap flex items-center space-x-2"
                :class="route().current('admin.audit-logs') ? 'bg-[#2d6a4f] text-white shadow-xs' : 'text-emerald-100/75 hover:text-white hover:bg-forest-800/60'"
            >
                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <span>Audit Logs</span>
            </Link>
        </nav>
    </div>
</template>

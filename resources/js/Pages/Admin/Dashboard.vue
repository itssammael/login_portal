<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import AdminNav from '@/Components/AdminNav.vue';

defineProps({
    stats: {
        type: Object,
        required: true,
    },
    recentLogs: {
        type: Array,
        default: () => [],
    },
    recentConversations: {
        type: Array,
        default: () => [],
    },
});
</script>

<template>
    <AppLayout title="Admin Dashboard">
        <template #header>
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="font-bold text-xl text-gray-900 leading-tight flex items-center space-x-2">
                        <span>🛡️ Administrator Command Center</span>
                    </h2>
                    <p class="text-xs text-gray-500 mt-0.5">Platform monitoring, communication metrics, and user management</p>
                </div>
            </div>
        </template>

        <div class="py-6">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <!-- Navigation Tabs -->
                <AdminNav />

                <!-- Flash Message Alerts -->
                <div v-if="$page.props.flash?.success" class="mb-6 p-4 bg-emerald-50 border border-emerald-200 text-emerald-800 rounded-xl text-sm font-medium flex items-center shadow-xs">
                    <svg class="size-5 me-2 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    {{ $page.props.flash.success }}
                </div>

                <div v-if="$page.props.flash?.error" class="mb-6 p-4 bg-red-50 border border-red-200 text-red-800 rounded-xl text-sm font-medium flex items-center shadow-xs">
                    <svg class="size-5 me-2 text-red-600" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" />
                    </svg>
                    {{ $page.props.flash.error }}
                </div>

                <!-- Metric Cards Grid -->
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5 mb-8">
                    <!-- Total Users -->
                    <div class="bg-cream-200 p-5 rounded-2xl shadow-xs border border-cream-500/50 hover:shadow-md transition">
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-xs font-bold text-gray-600 uppercase tracking-wider">Total Users</span>
                            <div class="size-9 rounded-xl bg-lime-200 text-forest-900 flex items-center justify-center font-bold">
                                <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" />
                                </svg>
                            </div>
                        </div>
                        <div class="text-2xl font-extrabold text-gray-950">{{ stats.total_users }}</div>
                        <p class="text-xs text-forest-700 font-bold mt-1 flex items-center">
                            <span class="size-1.5 rounded-full bg-forest-500 me-1.5 animate-pulse"></span>
                            {{ stats.active_users_24h }} active in last 24h
                        </p>
                    </div>

                    <!-- Total Conversations -->
                    <div class="bg-cream-200 p-5 rounded-2xl shadow-xs border border-cream-500/50 hover:shadow-md transition">
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-xs font-bold text-gray-600 uppercase tracking-wider">Conversations</span>
                            <div class="size-9 rounded-xl bg-lime-200 text-forest-900 flex items-center justify-center font-bold">
                                <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M8.625 12a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H8.25m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H12m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 01-2.555-.337A5.972 5.972 0 015.41 20.97a5.969 5.969 0 01-.474-.065 4.48 4.48 0 00.978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25z" />
                                </svg>
                            </div>
                        </div>
                        <div class="text-2xl font-extrabold text-gray-950">{{ stats.total_conversations }}</div>
                        <p class="text-xs text-gray-600 font-medium mt-1">Direct message channels</p>
                    </div>

                    <!-- Total Messages Sent -->
                    <div class="bg-cream-200 p-5 rounded-2xl shadow-xs border border-cream-500/50 hover:shadow-md transition">
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-xs font-bold text-gray-600 uppercase tracking-wider">Messages Sent</span>
                            <div class="size-9 rounded-xl bg-lime-200 text-forest-900 flex items-center justify-center font-bold">
                                <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 12L3.269 3.126A59.768 59.768 0 0121.485 12 59.77 59.77 0 013.27 20.876L5.999 12zm0 0h7.5" />
                                </svg>
                            </div>
                        </div>
                        <div class="text-2xl font-extrabold text-gray-950">{{ stats.total_messages }}</div>
                        <p class="text-xs text-forest-800 font-medium mt-1">
                            +{{ stats.messages_today }} messages sent today
                        </p>
                    </div>

                    <!-- Suspended Accounts -->
                    <div class="bg-cream-200 p-5 rounded-2xl shadow-xs border border-cream-500/50 hover:shadow-md transition">
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-xs font-bold text-gray-600 uppercase tracking-wider">Suspended Users</span>
                            <div class="size-9 rounded-xl bg-lime-200 text-forest-900 flex items-center justify-center font-bold">
                                <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636" />
                                </svg>
                            </div>
                        </div>
                        <div class="text-2xl font-extrabold" :class="stats.banned_users > 0 ? 'text-amber-700' : 'text-gray-950'">
                            {{ stats.banned_users }}
                        </div>
                        <p class="text-xs text-gray-600 font-medium mt-1">Enforced suspensions</p>
                    </div>
                </div>

                <!-- Two Column Layout: Recent Chats & Recent Admin Logs -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                    <!-- Recent Conversations -->
                    <div class="bg-cream-200 rounded-2xl shadow-xs border border-cream-500/50 p-6">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="font-bold text-gray-950 text-base">Active Chat Threads</h3>
                            <Link :href="route('admin.chats')" class="text-xs text-forest-900 hover:text-forest-700 font-bold">
                                View all →
                            </Link>
                        </div>

                        <div class="divide-y divide-cream-500/40">
                            <template v-if="recentConversations.length > 0">
                                <div
                                    v-for="chat in recentConversations"
                                    :key="chat.id"
                                    class="py-3 flex items-center justify-between hover:bg-cream-100 rounded-xl px-2 transition"
                                >
                                    <div class="flex items-center space-x-3 min-w-0">
                                        <div class="flex -space-x-2 overflow-hidden shrink-0">
                                            <div
                                                v-for="u in chat.users.slice(0, 2)"
                                                :key="u.id"
                                                class="inline-flex items-center justify-center size-8 rounded-full bg-lime-200 text-forest-900 text-xs font-bold ring-2 ring-cream-200"
                                            >
                                                {{ u.name.split(' ').map(n => n[0]).join('').substring(0, 2).toUpperCase() }}
                                            </div>
                                        </div>
                                        <div class="min-w-0">
                                            <p class="text-sm font-semibold text-gray-950 truncate">
                                                {{ chat.users.map(u => u.name).join(' & ') }}
                                            </p>
                                            <p class="text-xs text-gray-600 truncate">
                                                {{ chat.latest_message ? chat.latest_message.sender?.name + ': ' + (chat.latest_message.body || 'Attachment') : 'No messages' }}
                                            </p>
                                        </div>
                                    </div>

                                    <div class="text-right shrink-0 ms-2">
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-lime-200/80 text-forest-900">
                                            {{ chat.messages_count }} msgs
                                        </span>
                                    </div>
                                </div>
                            </template>

                            <div v-else class="py-8 text-center text-gray-500 text-xs">
                                No chat conversations active yet.
                            </div>
                        </div>
                    </div>

                    <!-- Recent Administrative Audit Logs -->
                    <div class="bg-cream-200 rounded-2xl shadow-xs border border-cream-500/50 p-6">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="font-bold text-gray-950 text-base">Recent Audit Trail</h3>
                            <Link :href="route('admin.audit-logs')" class="text-xs text-forest-900 hover:text-forest-700 font-bold">
                                View all logs →
                            </Link>
                        </div>

                        <div class="divide-y divide-cream-500/40">
                            <template v-if="recentLogs.length > 0">
                                <div
                                    v-for="log in recentLogs"
                                    :key="log.id"
                                    class="py-3 flex items-center justify-between hover:bg-cream-100 rounded-xl px-2 transition"
                                >
                                    <div class="min-w-0">
                                        <div class="flex items-center space-x-2">
                                            <span class="font-bold text-xs text-gray-950">
                                                {{ log.admin?.name || 'Administrator' }}
                                            </span>
                                            <span class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider bg-lime-200/80 text-forest-900">
                                                {{ log.action.replace('_', ' ') }}
                                            </span>
                                        </div>
                                        <p class="text-xs text-gray-600 mt-1 truncate">
                                            {{ log.details?.target_name ? 'Target: ' + log.details.target_name : (log.details?.title || 'System action executed') }}
                                        </p>
                                    </div>
                                    <div class="text-[11px] text-gray-500 shrink-0 ms-2 font-medium">
                                        {{ new Date(log.created_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) }}
                                    </div>
                                </div>
                            </template>

                            <div v-else class="py-8 text-center text-gray-500 text-xs">
                                No audit records logged.
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </AppLayout>
</template>

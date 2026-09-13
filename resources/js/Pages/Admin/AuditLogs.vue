<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import AdminNav from '@/Components/AdminNav.vue';

defineProps({
    logs: {
        type: Object,
        required: true,
    },
});

const getActionBadgeClass = (action) => {
    switch (action) {
        case 'banned_user':
            return 'bg-red-100 text-red-800 border-red-200';
        case 'unbanned_user':
            return 'bg-emerald-100 text-emerald-800 border-emerald-200';
        case 'promoted_to_admin':
            return 'bg-indigo-100 text-indigo-800 border-indigo-200';
        case 'demoted_from_admin':
            return 'bg-amber-100 text-amber-800 border-amber-200';
        case 'deleted_message':
            return 'bg-rose-100 text-rose-800 border-rose-200';
        case 'broadcast_announcement':
            return 'bg-purple-100 text-purple-800 border-purple-200';
        default:
            return 'bg-gray-100 text-gray-800 border-gray-200';
    }
};

const formatActionName = (action) => {
    return action.replace(/_/g, ' ').toUpperCase();
};
</script>

<template>
    <AppLayout title="Admin - Audit Logs">
        <template #header>
            <AdminNav />
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="font-bold text-xl text-gray-900 leading-tight">
                        Administrative Audit Logs
                    </h2>
                    <p class="text-xs text-gray-500 mt-0.5">Chronological record of moderation, role adjustments, and broadcast actions</p>
                </div>
            </div>
        </template>

        <div class="py-6">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

                <div class="bg-cream-200 rounded-2xl shadow-xs border border-cream-500/50 overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-cream-500/20 text-left text-sm">
                            <thead class="bg-cream-100/90 text-forest-900 text-xs font-semibold uppercase tracking-wider">
                                <tr>
                                    <th class="px-6 py-3.5">Timestamp</th>
                                    <th class="px-6 py-3.5">Administrator</th>
                                    <th class="px-6 py-3.5">Action</th>
                                    <th class="px-6 py-3.5">Details</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-cream-500/20">
                                <template v-if="logs.data.length > 0">
                                    <tr
                                        v-for="log in logs.data"
                                        :key="log.id"
                                        class="hover:bg-cream-300/40 transition"
                                    >
                                        <td class="px-6 py-4 whitespace-nowrap text-xs text-gray-500 font-mono">
                                            {{ new Date(log.created_at).toLocaleString() }}
                                        </td>

                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="font-bold text-gray-900 text-xs">{{ log.admin?.name || 'System' }}</div>
                                            <div class="text-[11px] text-gray-500">{{ log.admin?.email }}</div>
                                        </td>

                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <span 
                                                class="inline-flex items-center px-2.5 py-1 rounded-md text-[11px] font-bold border"
                                                :class="getActionBadgeClass(log.action)"
                                            >
                                                {{ formatActionName(log.action) }}
                                            </span>
                                        </td>

                                        <td class="px-6 py-4 text-xs text-gray-700">
                                            <div v-if="log.details" class="space-y-0.5">
                                                <div v-for="(val, key) in log.details" :key="key">
                                                    <span class="font-semibold text-gray-600">{{ key }}:</span> {{ val }}
                                                </div>
                                            </div>
                                            <span v-else class="text-gray-400 italic">No additional details</span>
                                        </td>
                                    </tr>
                                </template>

                                <tr v-else>
                                    <td colspan="4" class="px-6 py-8 text-center text-gray-400 text-xs">
                                        No audit log entries recorded yet.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <div v-if="logs.links && logs.links.length > 3" class="p-4 border-t border-cream-500/30 flex items-center justify-between">
                        <div class="text-xs text-gray-500">
                            Showing {{ logs.from }} to {{ logs.to }} of {{ logs.total }} audit entries
                        </div>
                        <div class="flex space-x-1">
                            <Link
                                v-for="link in logs.links"
                                :key="link.label"
                                :href="link.url || '#'"
                                class="px-3 py-1.5 text-xs font-medium rounded-lg transition"
                                :class="[
                                    link.active ? 'bg-forest-900 text-white' : 'text-gray-800 hover:bg-cream-300',
                                    !link.url ? 'opacity-40 pointer-events-none' : ''
                                ]"
                            >
                                <span v-html="link.label"></span>
                            </Link>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </AppLayout>
</template>

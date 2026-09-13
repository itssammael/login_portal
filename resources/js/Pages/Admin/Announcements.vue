<script setup>
import { useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import AdminNav from '@/Components/AdminNav.vue';

defineProps({
    recentAnnouncements: {
        type: Array,
        default: () => [],
    },
});

const form = useForm({
    title: '',
    content: '',
});

const sendAnnouncement = () => {
    if (confirm('Broadcast this system announcement to all active users on the platform?')) {
        form.post(route('admin.announcements.broadcast'), {
            onSuccess: () => {
                form.reset();
            },
        });
    }
};
</script>

<template>
    <AppLayout title="Admin - System Announcements">
        <template #header>
            <AdminNav />
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="font-bold text-xl text-gray-900 leading-tight">
                        Broadcast System Announcements
                    </h2>
                    <p class="text-xs text-gray-500 mt-0.5">Send high-priority direct message announcements to every user's inbox</p>
                </div>
            </div>
        </template>

        <div class="py-6">
            <div class="max-w-8xl mx-auto px-4 sm:px-6 lg:px-8">
                

                <!-- Flash Alerts -->
                <div v-if="$page.props.flash?.success" class="mb-6 p-4 bg-emerald-50 border border-emerald-200 text-emerald-800 rounded-xl text-sm font-medium flex items-center shadow-xs">
                    <svg class="size-5 me-2 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    {{ $page.props.flash.success }}
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                    <!-- Form Column -->
                    <div class="lg:col-span-2 bg-cream-200 rounded-2xl shadow-xs border border-cream-500/50 p-6">
                        <h3 class="font-bold text-gray-900 text-base mb-4 flex items-center space-x-2">
                            <span>📢 Compose Broadcast Message</span>
                        </h3>

                        <form @submit.prevent="sendAnnouncement" class="space-y-4">
                            <div>
                                <label class="block text-xs font-bold text-forest-900 uppercase tracking-wider mb-1.5">
                                    Announcement Title
                                </label>
                                <input
                                    v-model="form.title"
                                    type="text"
                                    placeholder="e.g. Scheduled System Maintenance / Platform Update"
                                    class="w-full px-4 py-2.5 bg-[#fffef9] border border-cream-500 focus:bg-white focus:border-forest-600 focus:ring-2 focus:ring-forest-200 text-gray-900 text-sm rounded-xl transition"
                                    required
                                />
                                <div v-if="form.errors.title" class="text-xs text-red-600 mt-1">{{ form.errors.title }}</div>
                            </div>

                            <div>
                                <label class="block text-xs font-bold text-forest-900 uppercase tracking-wider mb-1.5">
                                    Announcement Content
                                </label>
                                <textarea
                                    v-model="form.content"
                                    rows="6"
                                    placeholder="Type the full announcement message here..."
                                    class="w-full px-4 py-2.5 bg-[#fffef9] border border-cream-500 focus:bg-white focus:border-forest-600 focus:ring-2 focus:ring-forest-200 text-gray-900 text-sm rounded-xl transition"
                                    required
                                ></textarea>
                                <div v-if="form.errors.content" class="text-xs text-red-600 mt-1">{{ form.errors.content }}</div>
                            </div>

                            <div class="flex items-center justify-between pt-2">
                                <p class="text-xs text-gray-500">
                                    This will create or append to direct chat threads with all platform users.
                                </p>
                                <button
                                    type="submit"
                                    :disabled="form.processing || !form.title.trim() || !form.content.trim()"
                                    class="px-5 py-2.5 bg-forest-900 hover:bg-forest-950 disabled:opacity-40 text-white text-sm font-semibold rounded-xl shadow-xs transition transform active:scale-95"
                                >
                                    {{ form.processing ? 'Broadcasting...' : 'Broadcast to All Users' }}
                                </button>
                            </div>
                        </form>
                    </div>

                    <!-- Past Broadcasts Column -->
                    <div class="bg-cream-200 rounded-2xl shadow-xs border border-cream-500/50 p-6">
                        <h3 class="font-bold text-gray-900 text-base mb-4">Past Broadcasts</h3>

                        <div class="divide-y divide-cream-500/20">
                            <template v-if="recentAnnouncements.length > 0">
                                <div
                                    v-for="item in recentAnnouncements"
                                    :key="item.id"
                                    class="py-3"
                                >
                                    <div class="font-semibold text-xs text-gray-900 leading-snug">
                                        {{ item.details?.title || 'System Broadcast' }}
                                    </div>
                                    <div class="text-[11px] text-gray-500 mt-1 flex items-center justify-between">
                                        <span>Sent to {{ item.details?.recipients_count || 0 }} recipients</span>
                                        <span>{{ new Date(item.created_at).toLocaleDateString() }}</span>
                                    </div>
                                </div>
                            </template>

                            <div v-else class="py-8 text-center text-gray-500 text-xs">
                                No previous broadcasts.
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </AppLayout>
</template>

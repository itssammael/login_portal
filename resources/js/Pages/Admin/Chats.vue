<script setup>
import { ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import AdminNav from '@/Components/AdminNav.vue';

const props = defineProps({
    conversations: {
        type: Object,
        required: true,
    },
    selectedChat: {
        type: Object,
        default: null,
    },
    filters: {
        type: Object,
        default: () => ({}),
    },
});

const search = ref(props.filters.search || '');

// Moderation Modal state
const showModerateModal = ref(false);
const messageToModerate = ref(null);
const moderationReason = ref('Inappropriate content');

const searchChats = () => {
    router.get(route('admin.chats'), {
        search: search.value || undefined,
    }, {
        preserveState: true,
        replace: true,
    });
};

const inspectChat = (chatId) => {
    router.get(route('admin.chats'), {
        search: search.value || undefined,
        inspect: chatId,
    }, {
        preserveScroll: true,
        preserveState: true,
    });
};

const closeInspection = () => {
    router.get(route('admin.chats'), {
        search: search.value || undefined,
    }, {
        preserveScroll: true,
        preserveState: true,
    });
};

const openModerateModal = (messageId) => {
    messageToModerate.value = messageId;
    moderationReason.value = 'Inappropriate content';
    showModerateModal.value = true;
};

const closeModerateModal = () => {
    showModerateModal.value = false;
    messageToModerate.value = null;
    moderationReason.value = 'Inappropriate content';
};

const submitModerateDelete = () => {
    if (!messageToModerate.value) return;
    router.delete(route('admin.messages.delete', messageToModerate.value), {
        data: { reason: moderationReason.value || 'Inappropriate content' },
        preserveScroll: true,
        onSuccess: () => {
            closeModerateModal();
        },
    });
};

const setQuickReason = (reason) => {
    moderationReason.value = reason;
};
</script>

<template>
    <AppLayout title="Admin - Chat Monitoring">
        <template #header>
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="font-bold text-xl text-gray-900 leading-tight">
                        Chat Monitoring & Content Moderation
                    </h2>
                    <p class="text-xs text-gray-600 mt-0.5">Audit platform conversations, review message threads, and remove policy violations</p>
                </div>
            </div>
        </template>

        <div class="py-6">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <AdminNav />

                <!-- Flash Alerts -->
                <div v-if="$page.props.flash?.success" class="mb-6 p-4 bg-emerald-50 border border-emerald-300 text-emerald-900 rounded-xl text-sm font-medium flex items-center shadow-xs">
                    <svg class="size-5 me-2 text-emerald-600 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    {{ $page.props.flash.success }}
                </div>

                <!-- Search Input Toolbar -->
                <div class="bg-cream-200 p-4 rounded-2xl shadow-xs border border-cream-500/50 mb-6 flex items-center justify-between">
                    <div class="relative flex-1 max-w-md">
                        <input
                            v-model="search"
                            @keyup.enter="searchChats"
                            type="text"
                            placeholder="Filter by participant name or email..."
                            class="w-full pl-10 pr-4 py-2 bg-[#fffef9] border border-cream-500 focus:bg-white focus:border-forest-600 focus:ring-2 focus:ring-forest-200 text-sm rounded-xl transition text-gray-900 placeholder:text-gray-400"
                        />
                        <svg class="size-4 text-gray-400 absolute left-3.5 top-3" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
                        </svg>
                    </div>

                    <button
                        @click="searchChats"
                        class="px-4 py-2 bg-forest-900 hover:bg-forest-950 text-white text-sm font-semibold rounded-xl transition shadow-xs"
                    >
                        Search
                    </button>
                </div>

                <!-- Conversations Grid / Table -->
                <div class="bg-cream-200 rounded-2xl shadow-xs border border-cream-500/50 overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-cream-500/30 text-left text-sm">
                            <thead class="bg-cream-100/70 text-gray-600 text-xs font-semibold uppercase tracking-wider">
                                <tr>
                                    <th class="px-6 py-3.5">Conversation</th>
                                    <th class="px-6 py-3.5">Participants</th>
                                    <th class="px-6 py-3.5">Total Messages</th>
                                    <th class="px-6 py-3.5">Last Message</th>
                                    <th class="px-6 py-3.5 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-cream-500/20">
                                <tr
                                    v-for="chat in conversations.data"
                                    :key="chat.id"
                                    class="hover:bg-cream-100/40 transition"
                                >
                                    <td class="px-6 py-4 whitespace-nowrap font-medium text-gray-900">
                                        <div class="flex items-center space-x-2">
                                            <span class="size-2 rounded-full bg-forest-600"></span>
                                            <span>#{{ chat.id }} - {{ chat.type === 'direct' ? 'Direct Message' : chat.title }}</span>
                                        </div>
                                    </td>

                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex items-center space-x-2">
                                            <div class="flex -space-x-2 overflow-hidden">
                                                <img
                                                    v-for="u in chat.users"
                                                    :key="u.id"
                                                    :src="u.profile_photo_url || 'https://ui-avatars.com/api/?name=' + encodeURIComponent(u.name)"
                                                    :title="u.name"
                                                    class="inline-block size-7 rounded-full ring-2 ring-cream-200 object-cover"
                                                />
                                            </div>
                                            <span class="text-xs text-gray-600 truncate max-w-xs">
                                                {{ chat.users.map(u => u.name).join(', ') }}
                                            </span>
                                        </div>
                                    </td>

                                    <td class="px-6 py-4 whitespace-nowrap text-xs font-semibold text-gray-700">
                                        {{ chat.messages_count }} msgs
                                    </td>

                                    <td class="px-6 py-4 whitespace-nowrap text-xs text-gray-500">
                                        {{ chat.last_message_at ? new Date(chat.last_message_at).toLocaleString() : 'N/A' }}
                                    </td>

                                    <td class="px-6 py-4 whitespace-nowrap text-right">
                                        <button
                                            @click="inspectChat(chat.id)"
                                            class="inline-flex items-center px-3 py-1.5 bg-cream-100 hover:bg-cream-300 text-forest-900 text-xs font-semibold rounded-lg border border-cream-500/40 transition"
                                        >
                                            <svg class="size-3.5 me-1" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" />
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                            </svg>
                                            Inspect Transcript
                                        </button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <div v-if="conversations.links && conversations.links.length > 3" class="p-4 border-t border-cream-500/30 flex items-center justify-between">
                        <div class="text-xs text-gray-600">
                            Showing {{ conversations.from }} to {{ conversations.to }} of {{ conversations.total }} chats
                        </div>
                        <div class="flex space-x-1">
                            <Link
                                v-for="link in conversations.links"
                                :key="link.label"
                                :href="link.url || '#'"
                                class="px-3 py-1.5 text-xs font-medium rounded-lg transition"
                                :class="[
                                    link.active ? 'bg-forest-900 text-white shadow-xs' : 'text-gray-700 hover:bg-cream-300',
                                    !link.url ? 'opacity-40 pointer-events-none' : ''
                                ]"
                            >
                                <span v-html="link.label"></span>
                            </Link>
                        </div>
                    </div>
                </div>

                <!-- Chat Transcript Inspection Modal -->
                <div 
                    v-if="selectedChat"
                    class="fixed inset-0 z-40 overflow-y-auto bg-black/40 backdrop-blur-xs flex items-center justify-center p-4"
                >
                    <div class="bg-cream-200 rounded-2xl shadow-2xl max-w-2xl w-full overflow-hidden border border-cream-500/60 flex flex-col max-h-[85vh]">
                        <!-- Modal Header -->
                        <div class="p-4 border-b border-cream-500/30 flex items-center justify-between bg-cream-100/60">
                            <div>
                                <h3 class="font-bold text-base text-gray-900">
                                    Moderation Inspection: Chat #{{ selectedChat.id }}
                                </h3>
                                <p class="text-xs text-gray-600">
                                    Participants: {{ selectedChat.users.map(u => u.name).join(', ') }}
                                </p>
                            </div>
                            <button @click="closeInspection" class="p-1 text-gray-400 hover:text-gray-600">
                                <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>

                        <!-- Message Transcript List -->
                        <div class="flex-1 overflow-y-auto p-6 space-y-4">
                            <template v-if="selectedChat.messages.length > 0">
                                <div
                                    v-for="msg in selectedChat.messages"
                                    :key="msg.id"
                                    class="p-3 rounded-xl border transition"
                                    :class="msg.is_deleted ? 'bg-red-50/70 border-red-200 opacity-70' : 'bg-cream-100 border-cream-500/40'"
                                >
                                    <div class="flex items-center justify-between mb-1">
                                        <div class="flex items-center space-x-2">
                                            <span class="font-bold text-xs text-gray-900">{{ msg.sender_name }}</span>
                                            <span class="text-[10px] text-gray-500">{{ msg.created_at }}</span>
                                        </div>
                                        <button
                                            v-if="!msg.is_deleted"
                                            @click="openModerateModal(msg.id)"
                                            class="text-xs text-red-600 hover:text-red-800 font-semibold px-2 py-0.5 rounded hover:bg-red-50 transition"
                                            title="Delete message from chat"
                                        >
                                            Delete / Moderate
                                        </button>
                                        <span v-else class="text-[10px] font-bold text-red-600">
                                            [Moderated/Deleted]
                                        </span>
                                    </div>

                                    <!-- Attachment Preview if present -->
                                    <div v-if="msg.attachment_url && msg.type === 'image'" class="my-2">
                                        <img :src="msg.attachment_url" class="max-h-36 rounded-lg object-cover" />
                                    </div>

                                    <p class="text-sm text-gray-800 whitespace-pre-wrap">{{ msg.body }}</p>
                                </div>
                            </template>

                            <div v-else class="text-center py-8 text-gray-500 text-sm">
                                No messages logged in this chat.
                            </div>
                        </div>

                        <!-- Modal Footer -->
                        <div class="p-3 border-t border-cream-500/30 bg-cream-100/60 flex justify-end">
                            <button
                                @click="closeInspection"
                                class="px-4 py-2 bg-cream-100 hover:bg-cream-300 text-gray-800 text-sm font-semibold rounded-xl border border-cream-500/40 transition"
                            >
                                Close Inspection
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Custom Moderation Reason Modal Dialog -->
                <div
                    v-if="showModerateModal"
                    class="fixed inset-0 z-50 overflow-y-auto bg-black/40 backdrop-blur-xs flex items-center justify-center p-4"
                >
                    <div class="bg-cream-200 rounded-3xl max-w-md w-full shadow-2xl overflow-hidden transform transition-all border border-cream-500/60">
                        <div class="p-6">
                            <div class="flex items-center space-x-3 mb-4">
                                <div class="size-10 rounded-2xl bg-red-100 text-red-600 flex items-center justify-center shrink-0">
                                    <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.008v.008H12v-.008z" />
                                    </svg>
                                </div>
                                <div>
                                    <h3 class="text-base font-bold text-gray-900">Moderate & Delete Message</h3>
                                    <p class="text-xs text-gray-600">Specify the administrative reason for removing this message</p>
                                </div>
                            </div>

                            <!-- Reason Input -->
                            <div class="space-y-3">
                                <div>
                                    <label class="block text-xs font-bold text-gray-700 uppercase tracking-wider mb-1.5">Moderation Reason *</label>
                                    <input
                                        v-model="moderationReason"
                                        type="text"
                                        placeholder="Enter moderation reason..."
                                        class="w-full px-3.5 py-2.5 bg-[#fffef9] border border-cream-500 focus:bg-white focus:border-red-500 focus:ring-2 focus:ring-red-200 rounded-xl text-sm transition text-gray-900"
                                        required
                                        @keyup.enter="submitModerateDelete"
                                    />
                                </div>

                                <!-- Quick Reason Chips -->
                                <div>
                                    <span class="block text-[11px] font-semibold text-gray-500 mb-1.5">Quick Presets:</span>
                                    <div class="flex flex-wrap gap-1.5">
                                        <button
                                            type="button"
                                            @click="setQuickReason('Inappropriate content')"
                                            class="px-2.5 py-1 text-xs rounded-lg border font-medium transition"
                                            :class="moderationReason === 'Inappropriate content' ? 'bg-red-100 border-red-300 text-red-800' : 'bg-cream-100 border-cream-500/40 text-gray-700 hover:bg-cream-300'"
                                        >
                                            Inappropriate content
                                        </button>
                                        <button
                                            type="button"
                                            @click="setQuickReason('Spam / Unsolicited')"
                                            class="px-2.5 py-1 text-xs rounded-lg border font-medium transition"
                                            :class="moderationReason === 'Spam / Unsolicited' ? 'bg-red-100 border-red-300 text-red-800' : 'bg-cream-100 border-cream-500/40 text-gray-700 hover:bg-cream-300'"
                                        >
                                            Spam / Unsolicited
                                        </button>
                                        <button
                                            type="button"
                                            @click="setQuickReason('Harassment / Abuse')"
                                            class="px-2.5 py-1 text-xs rounded-lg border font-medium transition"
                                            :class="moderationReason === 'Harassment / Abuse' ? 'bg-red-100 border-red-300 text-red-800' : 'bg-cream-100 border-cream-500/40 text-gray-700 hover:bg-cream-300'"
                                        >
                                            Harassment / Abuse
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <!-- Actions -->
                            <div class="mt-6 flex items-center justify-end space-x-3">
                                <button
                                    type="button"
                                    @click="closeModerateModal"
                                    class="px-4 py-2 bg-cream-100 hover:bg-cream-300 text-gray-700 text-sm font-semibold rounded-xl border border-cream-500/40 transition"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="button"
                                    @click="submitModerateDelete"
                                    class="px-5 py-2 bg-red-600 hover:bg-red-700 text-white text-sm font-semibold rounded-xl shadow-xs transition"
                                >
                                    Delete Message
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </AppLayout>
</template>

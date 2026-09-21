<script setup>
import { ref, computed, watch, nextTick, onMounted } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import DialogModal from '@/Components/DialogModal.vue';

const props = defineProps({
    conversations: {
        type: Array,
        default: () => [],
    },
    activeConversation: {
        type: Object,
        default: null,
    },
    availableUsers: {
        type: Array,
        default: () => [],
    },
});

const searchQuery = ref('');
const showNewChatModal = ref(false);
const newChatSearch = ref('');
const messagesContainer = ref(null);
const fileInput = ref(null);
const selectedFile = ref(null);
const selectedFilePreview = ref(null);
const showEmojiPicker = ref(false);
const soundEnabled = ref(true);
const activeReactionMessageId = ref(null);
const showInfoPanel = ref(false);

const emojis = ['👍', '❤️', '😂', '😮', '😢', '🔥', '🎉', '👏', '🙏', '💯', '✨', '🚀'];
const reactionMap = {
    'like': '👍',
    'love': '❤️',
    'laugh': '😂',
    'wow': '😮',
    'sad': '😢',
    'fire': '🔥',
};

const messageForm = useForm({
    body: '',
    attachment: null,
});

// Filter conversations based on search
const filteredConversations = computed(() => {
    if (!searchQuery.value.trim()) return props.conversations;
    const q = searchQuery.value.toLowerCase();
    return props.conversations.filter(c => 
        (c.title && c.title.toLowerCase().includes(q)) ||
        (c.other_user && c.other_user.name.toLowerCase().includes(q))
    );
});

// Filter available users for starting new chat
const filteredUsers = computed(() => {
    if (!newChatSearch.value.trim()) return props.availableUsers;
    const q = newChatSearch.value.toLowerCase();
    return props.availableUsers.filter(u => 
        u.name.toLowerCase().includes(q) || u.email.toLowerCase().includes(q)
    );
});

// Scroll messages to bottom
const scrollToBottom = (smooth = true) => {
    nextTick(() => {
        if (messagesContainer.value) {
            messagesContainer.value.scrollTo({
                top: messagesContainer.value.scrollHeight,
                behavior: smooth ? 'smooth' : 'auto',
            });
        }
    });
};

const isDragging = ref(false);
const dragCounter = ref(0);

const processFile = (file) => {
    if (!file) return;

    selectedFile.value = file;
    messageForm.attachment = file;

    if (file.type.startsWith('image/')) {
        const reader = new FileReader();
        reader.onload = (e) => {
            selectedFilePreview.value = e.target.result;
        };
        reader.readAsDataURL(file);
    } else {
        selectedFilePreview.value = null;
    }
};

// Handle file selection
const onFileSelected = (event) => {
    const file = event.target.files[0];
    processFile(file);
};

const handleDragEnter = (e) => {
    e.preventDefault();
    e.stopPropagation();
    if (!props.activeConversation) return;
    dragCounter.value++;
    if (e.dataTransfer.items && e.dataTransfer.items.length > 0) {
        isDragging.value = true;
    }
};

const handleDragLeave = (e) => {
    e.preventDefault();
    e.stopPropagation();
    if (!props.activeConversation) return;
    dragCounter.value--;
    if (dragCounter.value <= 0) {
        isDragging.value = false;
        dragCounter.value = 0;
    }
};

const handleDragOver = (e) => {
    e.preventDefault();
    e.stopPropagation();
};

const handleDrop = (e) => {
    e.preventDefault();
    e.stopPropagation();
    isDragging.value = false;
    dragCounter.value = 0;

    if (!props.activeConversation) return;

    if (e.dataTransfer.files && e.dataTransfer.files.length > 0) {
        const file = e.dataTransfer.files[0];
        processFile(file);
    }
};

const clearSelectedFile = () => {
    selectedFile.value = null;
    selectedFilePreview.value = null;
    messageForm.attachment = null;
    if (fileInput.value) {
        fileInput.value.value = '';
    }
};

const insertEmoji = (emoji) => {
    messageForm.body += emoji;
    showEmojiPicker.value = false;
};

// Send message
const sendMessage = () => {
    if (!props.activeConversation) return;
    if (!messageForm.body.trim() && !messageForm.attachment) return;

    messageForm.post(route('chat.send-message', props.activeConversation.id), {
        preserveScroll: true,
        onSuccess: () => {
            messageForm.reset('body');
            clearSelectedFile();
            scrollToBottom(true);
        },
    });
};

// Start or select direct conversation
const startDirectChat = (recipientId) => {
    showNewChatModal.value = false;
    newChatSearch.value = '';

    router.post(route('chat.start-direct'), {
        recipient_id: recipientId,
    }, {
        preserveScroll: true,
    });
};

const newChatType = ref('direct');
const groupForm = useForm({
    title: '',
    user_ids: [],
});

const toggleGroupUser = (userId) => {
    const idx = groupForm.user_ids.indexOf(userId);
    if (idx > -1) {
        groupForm.user_ids.splice(idx, 1);
    } else {
        groupForm.user_ids.push(userId);
    }
};

const createGroupChat = () => {
    if (!groupForm.title.trim() || groupForm.user_ids.length === 0) return;

    groupForm.post(route('chat.create-group'), {
        onSuccess: () => {
            showNewChatModal.value = false;
            newChatSearch.value = '';
            groupForm.reset();
            newChatType.value = 'direct';
        },
    });
};

// Toggle emoji reaction on message
const toggleReaction = (messageId, reactionKey) => {
    activeReactionMessageId.value = null;
    router.post(route('chat.reaction', messageId), {
        reaction: reactionKey,
    }, {
        preserveScroll: true,
    });
};

// Delete message state & actions
const deletingMessageId = ref(null);

const promptDeleteMessage = (messageId) => {
    deletingMessageId.value = messageId;
};

const closeDeleteModal = () => {
    deletingMessageId.value = null;
};

const confirmDeleteMessage = () => {
    if (!deletingMessageId.value) return;
    router.delete(route('chat.delete-message', deletingMessageId.value), {
        preserveScroll: true,
        onFinish: () => closeDeleteModal(),
    });
};

// Auto scroll on initial mount and when active conversation messages change
onMounted(() => {
    scrollToBottom(false);

    if (window.Echo) {
        window.Echo.channel('public-chat')
            .listen('.message.sent', (e) => {
                console.log('[Reverb Echo] Broadcast message received:', e);
                router.reload({ only: ['conversations', 'activeConversation'] });
            });
    }
});

watch(() => props.activeConversation?.messages?.length, () => {
    scrollToBottom(true);
});

watch(() => props.activeConversation?.id, () => {
    scrollToBottom(false);
});
</script>

<template>
    <AppLayout title="Messenger">
        <!-- <template #header>
            <div class="flex items-center justify-between">
                <div class="flex items-center space-x-3">
                    <div class="w-3 h-3 rounded-full bg-emerald-500 animate-pulse"></div>
                    <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                        Realtime Messenger
                    </h2>
                </div>

               
                   
             
            </div>
        </template> -->

        <div class="flex-1 flex flex-col min-h-0 p-2 sm:p-3 bg-cream-300">
            <div class="max-w-8xl w-full mx-auto flex-1 flex flex-col min-h-0">
                <!-- Messenger Container -->
                <div class="bg-cream-200 rounded-2xl shadow-sm border border-cream-500/60 overflow-hidden flex flex-1 min-h-0">
                    
                    <!-- Left Sidebar (Conversations List) -->
                    <div 
                        class="w-full md:w-80 lg:w-96 flex-shrink-0 border-r border-cream-500/60 flex flex-col bg-cream-200 min-h-0 h-full"
                        :class="{'hidden md:flex': activeConversation, 'flex': !activeConversation}"
                    >
                        <!-- Sidebar Header & Search -->
                        <div class="p-4 border-b border-cream-500/40 bg-cream-200">
                            <div class="flex items-center justify-between mb-3">
                                <h3 class="text-lg font-bold text-gray-900 tracking-tight">Chats</h3>
                                <span class="text-xs font-semibold px-2.5 py-1 bg-lime-200 text-forest-900 rounded-full">
                                    {{ conversations.length }} active
                                </span>
                            </div>
                            <div class="flex items-center gap-2">
                                <div class="relative flex-1">
                                    <input
                                        v-model="searchQuery"
                                        type="text"
                                        placeholder="Search conversations..."
                                        class="w-full pl-9 pr-4 py-2 bg-[#fffef7] text-gray-900 text-sm border border-cream-500/60 focus:border-forest-600 focus:ring-2 focus:ring-forest-200 rounded-xl transition duration-150 placeholder:text-gray-400"
                                    />
                                    <svg class="size-4 text-gray-400 absolute left-3 top-2.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
                                    </svg>
                                </div>
                                <div>
                                    <button
                                        @click="showNewChatModal = true"
                                        class="inline-flex items-center px-4 py-2 bg-forest-500 hover:bg-forest-600 active:bg-forest-700 text-white text-sm font-semibold rounded-xl shadow-xs transition-all duration-150 transform active:scale-95"
                                    >
                                        <svg class="size-4 me-1.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                                        </svg>
                                        New Chat
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Conversation List Items -->
                        <div class="flex-1 overflow-y-auto divide-y divide-cream-500/20 p-2 space-y-1">
                            <template v-if="filteredConversations.length > 0">
                                <Link
                                    v-for="conv in filteredConversations"
                                    :key="conv.id"
                                    :href="route('chat.index', { conversation: conv.id })"
                                    class="flex items-center p-3 rounded-xl transition duration-150 group relative"
                                    :class="activeConversation?.id === conv.id ? 'bg-[#ede1b8] shadow-xs border border-cream-500/50' : 'hover:bg-cream-300/60'"
                                >
                                    <!-- Avatar & Presence Dot -->
                                    <div class="relative shrink-0 me-3">
                                        <div
                                            v-if="conv.is_system || conv.type === 'system'"
                                            class="size-12 rounded-full bg-golden-400 text-forest-900 flex items-center justify-center shadow-xs ring-2 ring-cream-300"
                                        >
                                            <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75v-.7V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0m5.714 0a3 3 0 11-5.714 0" />
                                            </svg>
                                        </div>
                                        <img
                                            v-else
                                            :src="conv.other_user?.profile_photo_url || 'https://ui-avatars.com/api/?name=' + encodeURIComponent(conv.title)"
                                            :alt="conv.title"
                                            class="size-12 rounded-full object-cover ring-2 ring-cream-300"
                                        />
                                        <span
                                            v-if="conv.other_user?.is_online && !conv.is_system && conv.type !== 'system'"
                                            class="absolute bottom-0 right-0 size-3.5 bg-forest-500 border-2 border-white rounded-full shadow-xs"
                                            title="Active now"
                                        ></span>
                                    </div>

                                    <!-- Conversation Details -->
                                    <div class="min-w-0 flex-1">
                                        <div class="flex items-center justify-between mb-1">
                                            <h4 class="text-sm font-semibold text-gray-900 truncate group-hover:text-forest-900 transition">
                                                {{ (conv.is_system || conv.type === 'system') ? 'System Notifications' : conv.title }}
                                            </h4>
                                            <span class="text-xs text-gray-500 shrink-0 ms-2">
                                                {{ conv.latest_message?.created_at || conv.last_message_at }}
                                            </span>
                                        </div>

                                        <div class="flex items-center justify-between">
                                            <p 
                                                class="text-xs truncate"
                                                :class="conv.unread_count > 0 ? 'font-bold text-gray-900' : 'text-gray-500'"
                                            >
                                                <span v-if="conv.latest_message?.sender_name" class="text-gray-500">
                                                    {{ conv.latest_message.sender_name }}:
                                                </span>
                                                {{ conv.latest_message?.body || 'No messages yet' }}
                                            </p>

                                            <!-- Unread Count Badge -->
                                            <span
                                                v-if="conv.unread_count > 0"
                                                class="ms-2 px-2 py-0.5 text-[11px] font-extrabold bg-forest-600 text-white rounded-full shrink-0 shadow-xs animate-bounce"
                                            >
                                                {{ conv.unread_count }}
                                            </span>
                                        </div>
                                    </div>
                                </Link>
                            </template>

                            <div v-else class="p-8 text-center text-gray-500">
                                <svg class="size-12 mx-auto text-gray-400 mb-2" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M8.625 12a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H8.25m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H12m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 01-2.555-.337A5.972 5.972 0 015.41 20.97a5.969 5.969 0 01-.474-.065 4.48 4.48 0 00.978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25z" />
                                </svg>
                                <p class="text-sm font-medium">No conversations found</p>
                                <button
                                    @click="showNewChatModal = true"
                                    class="mt-3 text-xs text-forest-800 hover:text-forest-950 font-semibold"
                                >
                                    + Start a conversation
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Right Chat Area -->
                    <div 
                        class="flex-1 flex flex-col bg-cream-300 min-h-0 h-full relative"
                        :class="{'flex': activeConversation, 'hidden md:flex': !activeConversation}"
                        @dragenter="handleDragEnter"
                        @dragover="handleDragOver"
                        @dragleave="handleDragLeave"
                        @drop="handleDrop"
                    >
                        <!-- Drag & Drop Visual Overlay -->
                        <div 
                            v-if="isDragging && activeConversation" 
                            class="absolute inset-0 z-50 bg-forest-900/90 backdrop-blur-xs m-4 rounded-3xl border-4 border-dashed border-golden-400 flex flex-col items-center justify-center text-white transition-all duration-200 shadow-2xl pointer-events-none"
                        >
                            <div class="p-4 bg-white/20 rounded-full mb-3 animate-bounce">
                                <svg class="size-10 text-golden-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 16.5V9.75m0 0l3 3m-3-3l-3 3M6.75 19.5a4.5 4.5 0 01-1.41-8.775 5.25 5.25 0 0110.233-2.33 3 3 0 013.758 3.848A3.752 3.752 0 0118 19.5H6.75z" />
                                </svg>
                            </div>
                            <h3 class="text-xl font-bold tracking-tight">Drop file to attach</h3>
                            <p class="text-xs text-golden-200 mt-1 font-medium">Upload photo, document, or attachment</p>
                        </div>

                        <template v-if="activeConversation">
                            <!-- Active Chat Header -->
                            <div class="h-16 px-6 border-b border-cream-500/40 flex items-center justify-between bg-cream-200/90 z-10">
                                <div class="flex items-center space-x-3">
                                    <!-- Mobile Back Button -->
                                    <Link
                                        :href="route('chat.index')"
                                        class="md:hidden p-1.5 -ml-2 text-forest-900 hover:bg-cream-300 rounded-lg transition"
                                    >
                                        <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5" />
                                        </svg>
                                    </Link>

                                    <!-- User Avatar & Status -->
                                    <div class="relative">
                                        <div
                                            v-if="activeConversation.is_system || activeConversation.type === 'system'"
                                            class="size-10 rounded-full bg-golden-400 text-forest-900 flex items-center justify-center shadow-xs ring-2 ring-cream-300"
                                        >
                                            <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75v-.7V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0m5.714 0a3 3 0 11-5.714 0" />
                                            </svg>
                                        </div>
                                        <img
                                            v-else
                                            :src="activeConversation.other_user?.profile_photo_url || 'https://ui-avatars.com/api/?name=' + encodeURIComponent(activeConversation.title)"
                                            :alt="activeConversation.title"
                                            class="size-10 rounded-full object-cover ring-2 ring-cream-300"
                                        />
                                        <span
                                            v-if="activeConversation.other_user?.is_online && !activeConversation.is_system && activeConversation.type !== 'system'"
                                            class="absolute bottom-0 right-0 size-3 bg-forest-500 border-2 border-white rounded-full"
                                        ></span>
                                    </div>

                                    <div>
                                        <h3 class="font-bold text-gray-900 text-sm md:text-base leading-tight">
                                            {{ (activeConversation.is_system || activeConversation.type === 'system') ? 'System Notification' : activeConversation.title }}
                                        </h3>
                                        <p class="text-xs text-gray-500 flex items-center">
                                            <span v-if="activeConversation.is_system || activeConversation.type === 'system'" class="text-amber-700 font-semibold">
                                                Official System Notifications
                                            </span>
                                            <span v-else-if="activeConversation.other_user?.is_online" class="text-forest-700 font-medium">
                                                Active now
                                            </span>
                                            <span v-else-if="activeConversation.other_user?.last_seen_at">
                                                Active {{ activeConversation.other_user.last_seen_at }}
                                            </span>
                                            <span v-else>
                                                Direct Chat
                                            </span>
                                        </p>
                                    </div>
                                </div>

                                <!-- Header Right Action Icons (User Profile, Gear Settings) -->
                                <div class="flex items-center space-x-1 text-forest-900">
                                    <button
                                        @click="showInfoPanel = !showInfoPanel"
                                        class="p-2 text-forest-900 hover:text-forest-700 hover:bg-cream-400/40 rounded-xl transition"
                                        title="User Information"
                                    >
                                        <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z" />
                                        </svg>
                                    </button>

                                    <button
                                        @click="showInfoPanel = !showInfoPanel"
                                        class="p-2 text-forest-900 hover:text-forest-700 hover:bg-cream-400/40 rounded-xl transition"
                                        title="Chat Settings"
                                    >
                                        <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.325.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 011.37.49l1.296 2.247a1.125 1.125 0 01-.26 1.431l-1.003.827c-.293.241-.438.613-.43.992a7.723 7.723 0 010 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.955.26 1.43l-1.298 2.247a1.125 1.125 0 01-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.6 6.6 0 01-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 01-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 01-1.369-.49l-1.297-2.247a1.125 1.125 0 01.26-1.431l1.004-.827c.292-.24.437-.613.43-.991a6.932 6.932 0 010-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 01-.26-1.43l1.297-2.247a1.125 1.125 0 011.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.28z" />
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                        </svg>
                                    </button>
                                </div>
                            </div>

                            <!-- Horizontal flex layout for Messages + Info Drawer -->
                            <div class="flex-1 flex flex-row min-h-0 overflow-hidden relative">
                                <!-- Messages & Input Column -->
                                <div class="flex-1 flex flex-col min-h-0 overflow-hidden">
                                    <!-- Messages Container -->
                                    <div 
                                        ref="messagesContainer"
                                        class="flex-1 overflow-y-auto p-6 space-y-4 bg-cream-300"
                                    >
                                        <template v-if="activeConversation.messages.length > 0">
                                            <div
                                                v-for="message in activeConversation.messages"
                                                :key="message.id"
                                                class="flex flex-col"
                                                :class="message.is_sender ? 'items-end' : 'items-start'"
                                            >
                                                <!-- Sender Name in group/chat if not sender -->
                                                <span 
                                                    v-if="!message.is_sender"
                                                    class="text-[11px] font-semibold text-gray-700 mb-1 block pl-2"
                                                >
                                                    {{ message.sender.name }}
                                                </span>

                                                <!-- Message & Reaction Bar Row -->
                                                <div class="group flex items-center space-x-2.5 max-w-[90%] md:max-w-[80%]">
                                                    <!-- FOR SENDER: Reaction Popup Bar (Left side of bubble) -->
                                                    <div
                                                        v-if="message.is_sender && !message.is_deleted"
                                                        class="opacity-0 group-hover:opacity-100 focus-within:opacity-100 transition-opacity duration-150 flex items-center bg-cream-100 shadow-md border border-cream-500/40 rounded-full px-2.5 py-1 space-x-1 shrink-0 z-10"
                                                    >
                                                        <button
                                                            v-for="(emoji, reactionKey) in reactionMap"
                                                            :key="reactionKey"
                                                            @click="toggleReaction(message.id, reactionKey)"
                                                            class="hover:scale-125 transition-transform text-sm p-0.5"
                                                            :title="reactionKey"
                                                        >
                                                            {{ emoji }}
                                                        </button>

                                                        <!-- Delete button for sender -->
                                                        <button
                                                            v-if="!activeConversation.is_system && activeConversation.type !== 'system'"
                                                            @click="promptDeleteMessage(message.id)"
                                                            class="p-1 text-gray-400 hover:text-red-500 transition border-l border-cream-400 pl-1.5 ml-0.5"
                                                            title="Delete message"
                                                        >
                                                            <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                                <path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" />
                                                            </svg>
                                                        </button>
                                                    </div>

                                                    <!-- Message Content Container (Bubble & Reaction Badges) -->
                                                    <div class="flex flex-col" :class="message.is_sender ? 'items-end' : 'items-start'">
                                                        <!-- Message Bubble (Matches Reference Image 1) -->
                                                        <div
                                                            class="px-4 py-2.5 shadow-xs text-sm break-words relative transition"
                                                            :class="[
                                                                message.is_sender
                                                                    ? 'bg-golden-500 text-gray-950 font-medium rounded-2xl rounded-tr-xs border border-golden-600/30'
                                                                    : 'bg-golden-400 text-gray-900 font-medium rounded-2xl rounded-tl-xs border border-golden-500/30',
                                                                message.is_deleted ? 'italic opacity-60 text-xs' : ''
                                                            ]"
                                                        >
                                                            <!-- Image Attachment -->
                                                            <div v-if="message.attachment_url && message.type === 'image'" class="mb-2 overflow-hidden rounded-xl">
                                                                <a :href="message.attachment_url" target="_blank" rel="noopener noreferrer">
                                                                    <img 
                                                                        :src="message.attachment_url" 
                                                                        :alt="message.attachment_name"
                                                                        class="max-h-64 rounded-xl object-cover hover:opacity-95 transition"
                                                                    />
                                                                </a>
                                                            </div>

                                                            <!-- Document / File Attachment (Matches Reference Image 1 Attachment Card) -->
                                                            <div v-else-if="message.attachment_url && message.type === 'file'" class="mb-1">
                                                                <a
                                                                    :href="message.attachment_url"
                                                                    target="_blank"
                                                                    download
                                                                    class="flex items-center space-x-2 px-3.5 py-2.5 rounded-xl text-xs font-semibold shadow-xs"
                                                                    :class="message.is_sender ? 'bg-golden-600/20 text-gray-950 hover:bg-golden-600/30' : 'bg-golden-500/80 text-gray-950 hover:bg-golden-500'"
                                                                >
                                                                    <svg class="size-4 shrink-0 text-gray-800" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
                                                                    </svg>
                                                                    <span class="truncate">{{ message.attachment_name || 'Download file' }}</span>
                                                                </a>
                                                            </div>

                                                            <!-- Message Body -->
                                                            <p v-if="message.body" class="whitespace-pre-wrap leading-relaxed">
                                                                {{ message.body }}
                                                            </p>
                                                        </div>

                                                        <!-- Reaction Badges Attached Below Bubble -->
                                                        <div 
                                                            v-if="message.reactions && message.reactions.length > 0"
                                                            class="flex flex-wrap gap-1 mt-1"
                                                            :class="message.is_sender ? 'justify-end' : 'justify-start'"
                                                        >
                                                            <button
                                                                v-for="reaction in message.reactions"
                                                                :key="reaction.reaction"
                                                                @click="toggleReaction(message.id, reaction.reaction)"
                                                                class="inline-flex items-center space-x-1 px-2 py-0.5 rounded-full text-xs font-medium border shadow-xs transition"
                                                                :class="reaction.reacted_by_me ? 'bg-cream-100 border-forest-600 text-forest-900' : 'bg-cream-200 border-cream-500/50 text-gray-700'"
                                                            >
                                                                <span>{{ reactionMap[reaction.reaction] || reaction.reaction }}</span>
                                                                <span v-if="reaction.count > 1" class="text-[10px] font-bold">{{ reaction.count }}</span>
                                                            </button>
                                                        </div>
                                                    </div>

                                                    <!-- FOR RECEIVER: Reaction Popup Bar (Right side of bubble) -->
                                                    <div
                                                        v-if="!message.is_sender && !message.is_deleted"
                                                        class="opacity-0 group-hover:opacity-100 focus-within:opacity-100 transition-opacity duration-150 flex items-center bg-cream-100 shadow-md border border-cream-500/40 rounded-full px-2.5 py-1 space-x-1 shrink-0 z-10"
                                                    >
                                                        <button
                                                            v-for="(emoji, reactionKey) in reactionMap"
                                                            :key="reactionKey"
                                                            @click="toggleReaction(message.id, reactionKey)"
                                                            class="hover:scale-125 transition-transform text-sm p-0.5"
                                                            :title="reactionKey"
                                                        >
                                                            {{ emoji }}
                                                        </button>
                                                    </div>
                                                </div>

                                                <!-- Message Timestamp (Under Bubble) -->
                                                <div 
                                                    class="text-[10px] text-gray-500 mt-1 px-1 flex items-center space-x-1"
                                                    :class="message.is_sender ? 'justify-end' : 'justify-start'"
                                                >
                                                    <span>{{ message.created_at }}</span>
                                                </div>
                                            </div>
                                        </template>

                                        <div v-else class="h-full flex flex-col items-center justify-center text-center p-8 text-gray-500">
                                            <div class="size-16 rounded-full bg-cream-200 border border-cream-500/50 flex items-center justify-center text-forest-900 mb-3">
                                                <svg class="size-8" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M7.5 8.25h9m-9 3H12m-9.75 1.51c0 1.6 1.123 2.994 2.707 3.227 1.129.166 2.27.293 3.423.379.35.026.67.21.865.501L12 21l2.755-4.133a1.14 1.14 0 01.865-.501 48.172 48.172 0 003.423-.379c1.584-.233 2.707-1.626 2.707-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0012 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018z" />
                                                </svg>
                                            </div>
                                            <p class="text-sm font-semibold text-gray-800">No messages in this chat yet</p>
                                            <p class="text-xs text-gray-500 mt-1">Say hello to break the ice! 👋</p>
                                        </div>
                                    </div>

                                    <!-- Input Bar (Matches Reference Image 1) -->
                                    <div
                                        v-if="activeConversation.is_system || activeConversation.type === 'system'"
                                        class="p-4 bg-golden-100/60 border-t border-cream-500/40 flex items-center justify-center space-x-2 text-golden-800 text-xs font-semibold"
                                    >
                                        <svg class="size-4 text-golden-700 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />
                                        </svg>
                                        <span>System Notifications are read-only and cannot be replied to.</span>
                                    </div>

                                    <div v-else class="p-4 border-t border-cream-500/40 bg-cream-300 relative">
                                        <!-- File Preview Banner -->
                                        <div v-if="selectedFile" class="mb-3 p-2.5 bg-cream-200 rounded-xl flex items-center justify-between border border-cream-500/60">
                                            <div class="flex items-center space-x-2 truncate">
                                                <img v-if="selectedFilePreview" :src="selectedFilePreview" class="size-10 rounded-lg object-cover" />
                                                <svg v-else class="size-6 text-forest-800" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M18.375 12.739l-7.693 7.693a4.5 4.5 0 01-6.364-6.364l10.94-10.94A3 3 0 1119.5 7.372L8.552 18.32m.009-.01l-.01.01m5.699-9.941l-7.81 7.81a1.5 1.5 0 002.112 2.13" />
                                                </svg>
                                                <span class="text-xs font-medium text-forest-900 truncate">{{ selectedFile.name }}</span>
                                            </div>
                                            <button @click="clearSelectedFile" class="text-forest-700 hover:text-red-500 p-1">
                                                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                                </svg>
                                            </button>
                                        </div>

                                        <!-- Quick Emoji Popover -->
                                        <div 
                                            v-if="showEmojiPicker"
                                            class="absolute bottom-20 left-6 bg-cream-200 border border-cream-500/60 shadow-xl rounded-2xl p-3 flex flex-wrap gap-2 w-64 z-30"
                                        >
                                            <button
                                                v-for="e in emojis"
                                                :key="e"
                                                @click="insertEmoji(e)"
                                                class="hover:scale-125 transition-transform text-lg p-1.5"
                                            >
                                                {{ e }}
                                            </button>
                                        </div>

                                        <!-- Input Controls Form (Pill shaped with send button) -->
                                        <form @submit.prevent="sendMessage" class="flex items-center space-x-3">
                                            <!-- Hidden File Input -->
                                            <input
                                                ref="fileInput"
                                                type="file"
                                                class="hidden"
                                                accept="image/*,.pdf,.doc,.docx,.zip,.txt"
                                                @change="onFileSelected"
                                            />

                                            <!-- Pill Wrapper for attachment icon, emoji icon, and textarea -->
                                            <div class="flex-1 flex items-center bg-[#ebe2c8] border border-cream-500/50 rounded-full px-3.5 py-1 focus-within:ring-2 focus-within:ring-forest-600/30 focus-within:border-forest-600 transition">
                                                <!-- Attachment Button -->
                                                <button
                                                    type="button"
                                                    @click="$refs.fileInput.click()"
                                                    class="p-1.5 text-forest-900 hover:text-forest-700 hover:bg-cream-400/40 rounded-full transition shrink-0"
                                                    title="Attach file or photo"
                                                >
                                                    <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M18.375 12.739l-7.693 7.693a4.5 4.5 0 01-6.364-6.364l10.94-10.94A3 3 0 1119.5 7.372L8.552 18.32m.009-.01l-.01.01m5.699-9.941l-7.81 7.81a1.5 1.5 0 002.112 2.13" />
                                                    </svg>
                                                </button>

                                                <!-- Emoji Button -->
                                                <button
                                                    type="button"
                                                    @click="showEmojiPicker = !showEmojiPicker"
                                                    class="p-1.5 text-forest-900 hover:text-forest-700 hover:bg-cream-400/40 rounded-full transition shrink-0"
                                                    title="Insert emoji"
                                                >
                                                    <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.182 15.182a4.5 4.5 0 01-6.364 0M21 12a9 9 0 11-18 0 9 9 0 0118 0zM9.75 9.75c0 .414-.168.75-.375.75S9 10.164 9 9.75 9.168 9 9.375 9s.375.336.375.75zm-.375 0h.008v.015h-.008V9.75zm5.625 0c0 .414-.168.75-.375.75s-.375-.336-.375-.75.168-.75.375-.75.375.336.375.75zm-.375 0h.008v.015h-.008V9.75z" />
                                                    </svg>
                                                </button>

                                                <!-- Textarea -->
                                                <textarea
                                                    v-model="messageForm.body"
                                                    rows="1"
                                                    placeholder="Type a message... (Press Enter to send)"
                                                    class="w-full resize-none py-1.5 px-3 bg-transparent text-sm text-gray-900 placeholder:text-gray-600 border-0 focus:ring-0 max-h-32"
                                                    @keydown.enter.exact.prevent="sendMessage"
                                                ></textarea>
                                            </div>

                                            <!-- Send Button (Forest Green Circle at Far Right) -->
                                            <button
                                                type="submit"
                                                :disabled="messageForm.processing || (!messageForm.body.trim() && !messageForm.attachment)"
                                                class="size-10 bg-forest-900 hover:bg-forest-950 disabled:opacity-40 text-white rounded-full shadow-md flex items-center justify-center transition-all transform active:scale-95 shrink-0"
                                                title="Send message"
                                            >
                                                <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 12L3.269 3.126A59.768 59.768 0 0121.485 12 59.77 59.77 0 013.27 20.876L5.999 12zm0 0h7.5" />
                                                </svg>
                                            </button>
                                        </form>
                                    </div>
                                </div>

                                <!-- Conversation Information Right Drawer / Sidebar -->
                                <div 
                                    v-if="showInfoPanel" 
                                    class="w-72 lg:w-80 border-l border-cream-500/50 bg-cream-200 flex flex-col shrink-0 overflow-y-auto h-full z-20 transition-all duration-300"
                                >
                                    <!-- Header -->
                                    <div class="p-4 border-b border-cream-500/40 flex items-center justify-between bg-cream-100">
                                        <h3 class="font-bold text-sm text-gray-900 flex items-center">
                                            <svg class="size-4 text-forest-800 me-2" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zM12 8.25h.008v.008H12V8.25z" />
                                            </svg>
                                            <span>Conversation Info</span>
                                        </h3>
                                        <button @click="showInfoPanel = false" class="text-gray-500 hover:text-gray-700 p-1 rounded-lg hover:bg-cream-300 transition">
                                            <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                            </svg>
                                        </button>
                                    </div>

                                    <div class="p-5 space-y-6 flex-1">
                                        <!-- Overview Card -->
                                        <div class="flex flex-col items-center text-center pb-5 border-b border-cream-500/40">
                                            <div class="relative mb-3">
                                                <img
                                                    :src="activeConversation.other_user?.profile_photo_url || 'https://ui-avatars.com/api/?name=' + encodeURIComponent(activeConversation.title)"
                                                    :alt="activeConversation.title"
                                                    class="size-20 rounded-full object-cover ring-4 ring-cream-300 shadow-sm"
                                                />
                                                <span
                                                    v-if="activeConversation.other_user?.is_online"
                                                    class="absolute bottom-1 right-1 size-4 bg-forest-500 border-2 border-white rounded-full"
                                                ></span>
                                            </div>
                                            <h4 class="font-bold text-base text-gray-900 leading-tight">
                                                {{ (activeConversation.is_system || activeConversation.type === 'system') ? 'System Notifications' : activeConversation.title }}
                                            </h4>
                                            <p v-if="activeConversation.other_user" class="text-xs text-gray-500 mt-0.5">
                                                {{ activeConversation.other_user.email }}
                                            </p>

                                            <div class="flex items-center space-x-2 mt-3">
                                                <span 
                                                    class="px-2.5 py-1 rounded-full text-xs font-semibold uppercase tracking-wider"
                                                    :class="activeConversation.type === 'group' ? 'bg-lime-200 text-forest-900 border border-forest-500/30' : 'bg-forest-100 text-forest-800 border border-forest-500/20'"
                                                >
                                                    {{ activeConversation.type === 'group' ? 'Group Chat' : 'Direct Message' }}
                                                </span>
                                                <span class="text-xs text-gray-500">
                                                    {{ activeConversation.total_messages || 0 }} messages
                                                </span>
                                            </div>
                                        </div>

                                        <!-- Participants Section -->
                                        <div>
                                            <div class="flex items-center justify-between mb-3">
                                                <h5 class="text-xs font-bold text-gray-500 uppercase tracking-wider">
                                                    Participants ({{ activeConversation.participants?.length || 0 }})
                                                </h5>
                                            </div>
                                            <div class="space-y-2">
                                                <div 
                                                    v-for="participant in activeConversation.participants"
                                                    :key="participant.id"
                                                    class="flex items-center justify-between p-2 hover:bg-cream-300/60 rounded-xl transition"
                                                >
                                                    <div class="flex items-center space-x-3 truncate me-2">
                                                        <div class="relative shrink-0">
                                                            <img
                                                                :src="participant.profile_photo_url || 'https://ui-avatars.com/api/?name=' + encodeURIComponent(participant.name)"
                                                                :alt="participant.name"
                                                                class="size-8 rounded-full object-cover ring-1 ring-cream-400"
                                                            />
                                                            <span
                                                                v-if="participant.is_online"
                                                                class="absolute bottom-0 right-0 size-2.5 bg-forest-500 border-2 border-white rounded-full"
                                                            ></span>
                                                        </div>
                                                        <div class="truncate text-left">
                                                            <div class="text-xs font-semibold text-gray-900 truncate">
                                                                {{ participant.name }}
                                                                <span v-if="participant.id === $page.props.auth.user.id" class="text-[10px] text-forest-800 font-normal ms-1">(You)</span>
                                                            </div>
                                                            <div class="text-[11px] text-gray-500 truncate">{{ participant.email }}</div>
                                                        </div>
                                                    </div>
                                                    <span v-if="participant.is_online" class="text-[10px] font-bold text-forest-800 bg-lime-200 px-2 py-0.5 rounded-full shrink-0">
                                                        Online
                                                    </span>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Shared Attachments Section -->
                                        <div v-if="activeConversation.shared_attachments && activeConversation.shared_attachments.length > 0">
                                            <div class="flex items-center justify-between mb-3">
                                                <h5 class="text-xs font-bold text-gray-500 uppercase tracking-wider">
                                                    Shared Attachments ({{ activeConversation.shared_attachments.length }})
                                                </h5>
                                            </div>
                                            <div class="grid grid-cols-2 gap-2">
                                                <a
                                                    v-for="file in activeConversation.shared_attachments"
                                                    :key="file.id"
                                                    :href="file.url"
                                                    target="_blank"
                                                    download
                                                    class="p-2 border border-cream-500/40 bg-cream-100 hover:bg-cream-300/80 rounded-xl flex flex-col items-center text-center transition group"
                                                >
                                                    <img 
                                                        v-if="file.type === 'image'" 
                                                        :src="file.url" 
                                                        class="size-14 rounded-lg object-cover mb-1 group-hover:scale-105 transition" 
                                                    />
                                                    <svg v-else class="size-7 text-forest-800 mb-1" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
                                                    </svg>
                                                    <span class="text-[11px] font-medium text-gray-800 truncate w-full group-hover:text-forest-900">
                                                        {{ file.name }}
                                                    </span>
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </template>

                        <!-- Empty State: No active conversation selected -->
                        <div v-else class="flex-1 flex flex-col items-center justify-center text-center p-8 bg-cream-300">
                            <div class="size-20 rounded-2xl bg-cream-200 border border-cream-500/50 flex items-center justify-center text-forest-900 mb-4 shadow-xs">
                                <svg class="size-10" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M8.625 12a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H8.25m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H12m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 01-2.555-.337A5.972 5.972 0 015.41 20.97a5.969 5.969 0 01-.474-.065 4.48 4.48 0 00.978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25z" />
                                </svg>
                            </div>
                            <h3 class="text-lg font-bold text-gray-900 mb-1">Your Messages</h3>
                            <p class="text-sm text-gray-600 max-w-sm mb-6">
                                Send direct messages, photos, documents, and emojis to anyone on the platform with instant realtime updates.
                            </p>
                            <button
                                @click="showNewChatModal = true"
                                class="inline-flex items-center px-5 py-2.5 bg-forest-900 hover:bg-forest-950 text-white text-sm font-semibold rounded-xl shadow-xs transition transform active:scale-95"
                            >
                                <svg class="size-4 me-2" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                                </svg>
                                Start New Conversation
                            </button>
                        </div>

                    </div>

                </div>
            </div>
        </div>

        <!-- New Chat / Group Chat Modal -->
        <div 
            v-if="showNewChatModal"
            class="fixed inset-0 z-50 overflow-y-auto bg-gray-900/50 backdrop-blur-xs flex items-center justify-center p-4"
        >
            <div class="bg-cream-200 rounded-2xl shadow-2xl max-w-md w-full overflow-hidden border border-cream-500/60 flex flex-col max-h-[85vh]">
                <!-- Modal Header & Tabs -->
                <div class="p-4 border-b border-cream-500/40">
                    <div class="flex items-center justify-between mb-3">
                        <h3 class="font-bold text-base text-gray-900">
                            {{ newChatType === 'group' ? 'Create Group Chat' : 'New Direct Message' }}
                        </h3>
                        <button @click="showNewChatModal = false" class="text-gray-500 hover:text-gray-700 p-1">
                            <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    <!-- Type Switcher Tabs -->
                    <div class="flex p-1 bg-cream-300/80 rounded-xl space-x-1">
                        <button
                            @click="newChatType = 'direct'"
                            class="flex-1 py-1.5 text-xs font-semibold rounded-lg transition"
                            :class="newChatType === 'direct' ? 'bg-cream-100 text-forest-900 font-bold shadow-xs' : 'text-gray-600 hover:text-gray-900'"
                        >
                            Direct Message
                        </button>
                        <button
                            @click="newChatType = 'group'"
                            class="flex-1 py-1.5 text-xs font-semibold rounded-lg transition"
                            :class="newChatType === 'group' ? 'bg-cream-100 text-forest-900 font-bold shadow-xs' : 'text-gray-600 hover:text-gray-900'"
                        >
                            New Group Chat
                        </button>
                    </div>
                </div>

                <!-- DIRECT MESSAGE TAB CONTENT -->
                <template v-if="newChatType === 'direct'">
                    <div class="p-4 border-b border-cream-500/40 bg-cream-100/50">
                        <div class="relative">
                            <input
                                v-model="newChatSearch"
                                type="text"
                                placeholder="Type a contact's name or email..."
                                class="w-full pl-9 pr-4 py-2 bg-[#fffef7] text-gray-900 text-sm border border-cream-500/60 focus:border-forest-600 focus:ring-2 focus:ring-forest-200 rounded-xl transition"
                            />
                            <svg class="size-4 text-gray-400 absolute left-3 top-2.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
                            </svg>
                        </div>
                    </div>

                    <div class="overflow-y-auto p-2 divide-y divide-cream-500/20 flex-1 min-h-[220px]">
                        <template v-if="filteredUsers.length > 0">
                            <button
                                v-for="user in filteredUsers"
                                :key="user.id"
                                @click="startDirectChat(user.id)"
                                class="w-full flex items-center p-3 hover:bg-cream-300/60 rounded-xl transition text-left group"
                            >
                                <div class="relative me-3 shrink-0">
                                    <img
                                        :src="user.profile_photo_url || 'https://ui-avatars.com/api/?name=' + encodeURIComponent(user.name)"
                                        :alt="user.name"
                                        class="size-10 rounded-full object-cover ring-1 ring-cream-400"
                                    />
                                    <span
                                        v-if="user.is_online"
                                        class="absolute bottom-0 right-0 size-3 bg-forest-500 border-2 border-white rounded-full"
                                    ></span>
                                </div>
                                <div class="min-w-0 flex-1">
                                    <h4 class="text-sm font-semibold text-gray-900 group-hover:text-forest-900 transition truncate">
                                        {{ user.name }}
                                    </h4>
                                    <p class="text-xs text-gray-500 truncate">{{ user.email }}</p>
                                </div>
                                <svg class="size-5 text-gray-400 group-hover:text-forest-900 transition transform group-hover:translate-x-1" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                                </svg>
                            </button>
                        </template>

                        <div v-else class="p-8 text-center text-gray-400 text-sm">
                            No contacts match "{{ newChatSearch }}"
                        </div>
                    </div>
                </template>

                <!-- GROUP CHAT TAB CONTENT -->
                <template v-else>
                    <div class="p-4 border-b border-cream-500/40 bg-cream-100/50 space-y-3">
                        <div>
                            <label class="block text-xs font-semibold text-gray-700 mb-1">Group Name</label>
                            <input
                                v-model="groupForm.title"
                                type="text"
                                placeholder="e.g. Project Alpha, Marketing Team"
                                class="w-full px-3.5 py-2 bg-[#fffef7] text-gray-900 text-sm border border-cream-500/60 focus:border-forest-600 focus:ring-2 focus:ring-forest-200 rounded-xl transition"
                            />
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-gray-700 mb-1">Add Members</label>
                            <div class="relative">
                                <input
                                    v-model="newChatSearch"
                                    type="text"
                                    placeholder="Search contacts..."
                                    class="w-full pl-9 pr-4 py-2 bg-[#fffef7] text-gray-900 text-sm border border-cream-500/60 focus:border-forest-600 focus:ring-2 focus:ring-forest-200 rounded-xl transition"
                                />
                                <svg class="size-4 text-gray-400 absolute left-3 top-2.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
                                </svg>
                            </div>
                        </div>

                        <!-- Selected Members Badges -->
                        <div v-if="groupForm.user_ids.length > 0" class="flex flex-wrap gap-1.5 pt-1">
                            <span
                                v-for="userId in groupForm.user_ids"
                                :key="userId"
                                class="inline-flex items-center space-x-1.5 px-2.5 py-1 bg-lime-200 text-forest-900 text-xs font-semibold rounded-full border border-forest-500/30"
                            >
                                <span>{{ availableUsers.find(u => u.id === userId)?.name || 'User' }}</span>
                                <button @click="toggleGroupUser(userId)" class="text-forest-700 hover:text-red-600">
                                    <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                </button>
                            </span>
                        </div>
                    </div>

                    <!-- Member Selection List -->
                    <div class="overflow-y-auto p-2 divide-y divide-cream-500/20 flex-1 min-h-[180px]">
                        <template v-if="filteredUsers.length > 0">
                            <div
                                v-for="user in filteredUsers"
                                :key="user.id"
                                @click="toggleGroupUser(user.id)"
                                class="w-full flex items-center p-3 hover:bg-cream-300/60 rounded-xl transition text-left cursor-pointer group"
                            >
                                <input
                                    type="checkbox"
                                    :checked="groupForm.user_ids.includes(user.id)"
                                    class="size-4 text-forest-900 rounded-md border-cream-500 me-3 focus:ring-forest-600 pointer-events-none"
                                />
                                <div class="relative me-3 shrink-0">
                                    <img
                                        :src="user.profile_photo_url || 'https://ui-avatars.com/api/?name=' + encodeURIComponent(user.name)"
                                        :alt="user.name"
                                        class="size-10 rounded-full object-cover ring-1 ring-cream-400"
                                    />
                                    <span
                                        v-if="user.is_online"
                                        class="absolute bottom-0 right-0 size-3 bg-forest-500 border-2 border-white rounded-full"
                                    ></span>
                                </div>
                                <div class="min-w-0 flex-1">
                                    <h4 class="text-sm font-semibold text-gray-900 group-hover:text-forest-900 transition truncate">
                                        {{ user.name }}
                                    </h4>
                                    <p class="text-xs text-gray-500 truncate">{{ user.email }}</p>
                                </div>
                            </div>
                        </template>
                        <div v-else class="p-8 text-center text-gray-400 text-sm">
                            No contacts match "{{ newChatSearch }}"
                        </div>
                    </div>

                    <!-- Group Footer Actions -->
                    <div class="p-3 border-t border-cream-500/40 bg-cream-100 flex justify-end">
                        <button
                            @click="createGroupChat"
                            :disabled="!groupForm.title.trim() || groupForm.user_ids.length === 0 || groupForm.processing"
                            class="px-5 py-2 bg-forest-900 hover:bg-forest-950 disabled:opacity-40 text-white text-xs font-semibold rounded-xl shadow-xs transition"
                        >
                            {{ groupForm.processing ? 'Creating Group...' : 'Create Group Chat' }}
                        </button>
                    </div>
                </template>
            </div>
        </div>

        <!-- Delete Message Confirmation Dialog Modal -->
        <DialogModal :show="deletingMessageId !== null" max-width="sm" @close="closeDeleteModal">
            <template #title>
                <div class="flex items-center space-x-3 pt-2">
                    <div class="size-10 rounded-xl bg-red-100 text-red-800 flex items-center justify-center shrink-0">
                        <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" />
                        </svg>
                    </div>
                    <div>
                        <h3 class="text-base font-bold text-gray-900">Delete Message</h3>
                    </div>
                </div>
            </template>

            <template #content>
                <p class="text-sm text-gray-700 leading-relaxed">
                    Are you sure you want to delete this message for everyone? This action cannot be undone.
                </p>
            </template>

            <template #footer>
                <div class="flex items-center space-x-2">
                    <button
                        type="button"
                        @click="closeDeleteModal"
                        class="px-4 py-2 bg-cream-100 hover:bg-cream-300 text-gray-700 rounded-xl text-xs font-bold border border-cream-400 transition cursor-pointer"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        @click="confirmDeleteMessage"
                        class="px-4 py-2 bg-red-700 hover:bg-red-800 text-white rounded-xl text-xs font-bold shadow-xs transition cursor-pointer"
                    >
                        Delete for Everyone
                    </button>
                </div>
            </template>
        </DialogModal>
    </AppLayout>
</template>

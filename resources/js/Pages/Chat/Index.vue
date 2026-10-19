<script setup>
import { ref, computed, watch, nextTick, onMounted, onBeforeUnmount } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import axios from 'axios';
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

const page = usePage();
const currentUser = computed(() => page.props.auth?.user || {});

const searchQuery = ref('');
const showNewChatModal = ref(false);
const newChatSearch = ref('');
const messagesContainer = ref(null);
const composerTextarea = ref(null);
const fileInput = ref(null);
const selectedFile = ref(null);
const selectedFilePreview = ref(null);
const fileValidationError = ref('');
const showEmojiPicker = ref(false);
const activeMessageMenuId = ref(null);
const showInfoPanel = ref(false);

// Local state for sidebar conversations list
const conversationsList = ref([...props.conversations]);
watch(() => props.conversations, (newVal) => {
    conversationsList.value = [...(newVal || [])];
}, { deep: true });

// Local state for active conversation messages and pagination cursor
const activeMessages = ref([...(props.activeConversation?.messages || [])]);
const hasMoreMessages = ref(!!props.activeConversation?.has_more);
const isLoadingOlder = ref(false);

const pendingMessages = ref([]);
const hasNewMessagesBelow = ref(false);
const newIncomingCount = ref(0);

// Lazy contacts state for New Chat modal
const contacts = ref([]);
const isLoadingContacts = ref(false);
const selectedGroupUsers = ref([]);
let searchDebounceTimeout = null;

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

// All messages combined (server messages + local pending messages)
const allMessages = computed(() => {
    if (!props.activeConversation) return [];
    const serverMsgs = activeMessages.value || [];
    const activePending = pendingMessages.value.filter(
        p => p.conversation_id === props.activeConversation.id
    );
    if (activePending.length === 0) return serverMsgs;
    return [...serverMsgs, ...activePending];
});

// Filter conversations based on sidebar search
const filteredConversations = computed(() => {
    if (!searchQuery.value.trim()) return conversationsList.value;
    const q = searchQuery.value.toLowerCase();
    return conversationsList.value.filter(c => 
        (c.title && c.title.toLowerCase().includes(q)) ||
        (c.other_user && c.other_user.name && c.other_user.name.toLowerCase().includes(q)) ||
        (c.participants && c.participants.some(p => p.name && p.name.toLowerCase().includes(q)))
    );
});

// Check if message container scroll is near bottom
const isNearBottom = () => {
    if (!messagesContainer.value) return true;
    const { scrollTop, scrollHeight, clientHeight } = messagesContainer.value;
    return scrollHeight - scrollTop - clientHeight <= 120;
};

const onMessagesScroll = () => {
    if (isNearBottom()) {
        hasNewMessagesBelow.value = false;
        newIncomingCount.value = 0;
    }
};

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

const scrollToNewest = () => {
    hasNewMessagesBelow.value = false;
    newIncomingCount.value = 0;
    scrollToBottom(true);
};

// Load older messages with cursor while preserving scroll position
const loadOlderMessages = async () => {
    if (isLoadingOlder.value || !hasMoreMessages.value || !props.activeConversation) return;

    isLoadingOlder.value = true;
    const container = messagesContainer.value;
    const prevScrollHeight = container ? container.scrollHeight : 0;
    const prevScrollTop = container ? container.scrollTop : 0;

    const firstMsg = activeMessages.value[0];
    const beforeId = firstMsg ? firstMsg.id : null;

    try {
        const response = await axios.get(route('chat.messages', props.activeConversation.id), {
            params: { before_id: beforeId },
        });

        if (response.data && Array.isArray(response.data.messages)) {
            activeMessages.value = [...response.data.messages, ...activeMessages.value];
            hasMoreMessages.value = !!response.data.has_more;

            await nextTick();
            if (container) {
                container.scrollTop = container.scrollHeight - prevScrollHeight + prevScrollTop;
            }
        }
    } catch (error) {
        console.error('Failed to load older messages:', error);
    } finally {
        isLoadingOlder.value = false;
    }
};

// Drag and drop state
const isDragging = ref(false);
const dragCounter = ref(0);

// File validation constraints
const allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'doc', 'docx', 'txt', 'zip'];
const maxFileSize = 10 * 1024 * 1024; // 10 MB

const validateAndProcessFile = (file) => {
    fileValidationError.value = '';
    if (!file) return;

    if (file.size > maxFileSize) {
        fileValidationError.value = `"${file.name}" exceeds the 10 MB maximum upload limit.`;
        return;
    }

    const ext = file.name.split('.').pop()?.toLowerCase();
    if (!ext || !allowedExtensions.includes(ext)) {
        fileValidationError.value = `File format .${ext || 'unknown'} is not supported. Please upload an image, PDF, DOC, DOCX, TXT, or ZIP file.`;
        return;
    }

    processFile(file);
};

const processFile = (file) => {
    if (!file) return;

    // Revoke previous object URL if any
    if (selectedFilePreview.value) {
        URL.revokeObjectURL(selectedFilePreview.value);
        selectedFilePreview.value = null;
    }

    selectedFile.value = file;
    messageForm.attachment = file;

    if (file.type.startsWith('image/')) {
        selectedFilePreview.value = URL.createObjectURL(file);
    }
};

const onFileSelected = (event) => {
    const file = event.target.files[0];
    if (file) {
        validateAndProcessFile(file);
    }
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
        validateAndProcessFile(file);
    }
};

const clearSelectedFile = () => {
    if (selectedFilePreview.value) {
        URL.revokeObjectURL(selectedFilePreview.value);
        selectedFilePreview.value = null;
    }
    selectedFile.value = null;
    messageForm.attachment = null;
    fileValidationError.value = '';
    if (fileInput.value) {
        fileInput.value.value = '';
    }
};

const insertEmoji = (emoji) => {
    messageForm.body += emoji;
    showEmojiPicker.value = false;
    nextTick(() => {
        resizeComposer();
        focusComposer();
    });
};

// Auto-growing composer textarea
const resizeComposer = () => {
    const el = composerTextarea.value;
    if (!el) return;
    el.style.height = 'auto';
    const maxHeight = 160;
    el.style.height = `${Math.min(el.scrollHeight, maxHeight)}px`;
    el.style.overflowY = el.scrollHeight > maxHeight ? 'auto' : 'hidden';
};

watch(() => messageForm.body, () => {
    nextTick(resizeComposer);
});

const focusComposer = () => {
    if (composerTextarea.value) {
        composerTextarea.value.focus();
    }
};

// Enter key sends; Shift+Enter creates a new line
const onComposerKeydown = (e) => {
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        sendMessage();
    }
};

// Helper to format date matching server format
const formatCurrentTime = () => {
    const now = new Date();
    const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    const month = months[now.getMonth()];
    const day = now.getDate();
    let hours = now.getHours();
    const minutes = now.getMinutes().toString().padStart(2, '0');
    const ampm = hours >= 12 ? 'pm' : 'am';
    hours = hours % 12;
    hours = hours ? hours : 12;
    return `${month} ${day}, ${hours}:${minutes} ${ampm}`;
};

// Send a single pending message via Inertia post
const sendPendingMessage = (pendingMsg) => {
    pendingMsg.status = 'sending';
    let isSuccess = false;

    router.post(
        route('chat.send-message', pendingMsg.conversation_id),
        {
            body: pendingMsg.body || '',
            attachment: pendingMsg.attachment_file || null,
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                isSuccess = true;
                pendingMsg.status = 'sent';
                nextTick(() => {
                    const idx = pendingMessages.value.findIndex(p => p.id === pendingMsg.id);
                    if (idx !== -1) {
                        pendingMessages.value.splice(idx, 1);
                    }
                });
            },
            onError: (err) => {
                console.error('Send message error:', err);
                pendingMsg.status = 'failed';
            },
            onFinish: () => {
                if (!isSuccess && pendingMsg.status === 'sending') {
                    pendingMsg.status = 'failed';
                }
            },
        }
    );
};

// Resend a failed message
const resendMessage = (pendingMsg) => {
    sendPendingMessage(pendingMsg);
};

// Send message (optimistic update)
const sendMessage = () => {
    if (!props.activeConversation) return;
    if (!messageForm.body.trim() && !messageForm.attachment) return;

    const bodyText = messageForm.body;
    const fileObj = selectedFile.value;
    const previewUrl = selectedFilePreview.value;
    const conversationId = props.activeConversation.id;

    const tempId = 'pending-' + Date.now() + '-' + Math.random().toString(36).substring(2, 9);
    const user = currentUser.value;

    const pendingMsg = ref({
        id: tempId,
        conversation_id: conversationId,
        sender_id: user.id,
        sender: {
            id: user.id,
            name: user.name,
            email: user.email,
            profile_photo_url: user.profile_photo_url,
        },
        body: bodyText,
        type: fileObj ? (fileObj.type.startsWith('image/') ? 'image' : 'file') : 'text',
        attachment_url: previewUrl,
        attachment_name: fileObj ? fileObj.name : null,
        attachment_type: fileObj ? fileObj.type : null,
        attachment_file: fileObj,
        is_deleted: false,
        is_sender: true,
        created_at: formatCurrentTime(),
        status: 'sending',
    });

    pendingMessages.value.push(pendingMsg.value);

    messageForm.reset('body');
    selectedFile.value = null;
    selectedFilePreview.value = null;
    messageForm.attachment = null;
    fileValidationError.value = '';
    if (fileInput.value) fileInput.value.value = '';
    nextTick(resizeComposer);

    scrollToBottom(true);
    hasNewMessagesBelow.value = false;
    newIncomingCount.value = 0;

    sendPendingMessage(pendingMsg.value);
};

// Lazy contact search in modal
const fetchContacts = async (query = '') => {
    isLoadingContacts.value = true;
    try {
        const res = await axios.get(route('chat.contacts'), {
            params: { query: query.trim() },
        });
        contacts.value = res.data.users || [];
    } catch (err) {
        console.error('Failed to fetch contacts:', err);
    } finally {
        isLoadingContacts.value = false;
    }
};

const onContactSearchInput = () => {
    if (searchDebounceTimeout) clearTimeout(searchDebounceTimeout);
    searchDebounceTimeout = setTimeout(() => {
        fetchContacts(newChatSearch.value);
    }, 280);
};

watch(showNewChatModal, (isOpen) => {
    if (isOpen) {
        newChatSearch.value = '';
        fetchContacts('');
    }
});

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

const toggleGroupUser = (user) => {
    const idx = groupForm.user_ids.indexOf(user.id);
    if (idx > -1) {
        groupForm.user_ids.splice(idx, 1);
        const selIdx = selectedGroupUsers.value.findIndex(u => u.id === user.id);
        if (selIdx > -1) selectedGroupUsers.value.splice(selIdx, 1);
    } else {
        groupForm.user_ids.push(user.id);
        if (!selectedGroupUsers.value.some(u => u.id === user.id)) {
            selectedGroupUsers.value.push(user);
        }
    }
};

const removeGroupUserById = (userId) => {
    const idx = groupForm.user_ids.indexOf(userId);
    if (idx > -1) groupForm.user_ids.splice(idx, 1);
    const selIdx = selectedGroupUsers.value.findIndex(u => u.id === userId);
    if (selIdx > -1) selectedGroupUsers.value.splice(selIdx, 1);
};

const createGroupChat = () => {
    if (!groupForm.title.trim() || groupForm.user_ids.length === 0) return;

    groupForm.post(route('chat.create-group'), {
        onSuccess: () => {
            showNewChatModal.value = false;
            newChatSearch.value = '';
            groupForm.reset();
            selectedGroupUsers.value = [];
            newChatType.value = 'direct';
        },
    });
};

// Toggle emoji reaction on message
const toggleReaction = (messageId, reactionKey) => {
    if (typeof messageId === 'string' && messageId.startsWith('pending-')) return;
    activeReactionMessageId.value = null;
    activeMessageMenuId.value = null;

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
    activeMessageMenuId.value = null;
};

const closeDeleteModal = () => {
    deletingMessageId.value = null;
};

const confirmDeleteMessage = () => {
    if (!deletingMessageId.value) return;
    if (typeof deletingMessageId.value === 'string' && deletingMessageId.value.startsWith('pending-')) {
        const idx = pendingMessages.value.findIndex(p => p.id === deletingMessageId.value);
        if (idx !== -1) {
            pendingMessages.value.splice(idx, 1);
        }
        closeDeleteModal();
        return;
    }
    router.delete(route('chat.delete-message', deletingMessageId.value), {
        preserveScroll: true,
        onFinish: () => closeDeleteModal(),
    });
};

// Real-Time Echo Subscriptions & Targeted Local Updates
let subscribedConversationId = null;

const setupEchoListeners = () => {
    if (!window.Echo || !currentUser.value.id) return;

    // 1. Listen on user channel for conversation previews and unread badges
    window.Echo.private(`App.Models.User.${currentUser.value.id}`)
        .listen('.message.sent', handleUserChannelEvent);

    // 2. Listen on active conversation channel if open
    updateConversationChannelSubscription(props.activeConversation?.id);
};

const updateConversationChannelSubscription = (newConvId) => {
    if (!window.Echo) return;

    if (subscribedConversationId && subscribedConversationId !== newConvId) {
        window.Echo.leave(`chat.conversation.${subscribedConversationId}`);
        subscribedConversationId = null;
    }

    if (newConvId && newConvId !== subscribedConversationId) {
        subscribedConversationId = newConvId;
        window.Echo.private(`chat.conversation.${newConvId}`)
            .listen('.message.sent', handleConversationChannelEvent);
    }
};

const handleUserChannelEvent = (e) => {
    if (e.action === 'created') {
        const convIndex = conversationsList.value.findIndex(c => c.id === e.conversation_id);
        const senderId = e.data?.sender_id ?? e.data?.message?.sender_id;
        const isFromOther = senderId && senderId !== currentUser.value.id;

        if (convIndex !== -1) {
            const conv = conversationsList.value[convIndex];
            if (e.data?.conversation?.latest_message) {
                conv.latest_message = e.data.conversation.latest_message;
                conv.last_message_at = e.data.conversation.last_message_at;
            }
            if (props.activeConversation?.id !== e.conversation_id && isFromOther) {
                conv.unread_count = (conv.unread_count || 0) + 1;
            }
            // Move conversation to top of list
            conversationsList.value.splice(convIndex, 1);
            conversationsList.value.unshift(conv);
        } else if (e.data?.conversation) {
            const newConv = { ...e.data.conversation };
            if (props.activeConversation?.id === e.conversation_id || !isFromOther) {
                newConv.unread_count = 0;
            }
            conversationsList.value.unshift(newConv);
        }
    } else if (e.action === 'group_created') {
        if (e.data?.conversation && !conversationsList.value.some(c => c.id === e.data.conversation.id)) {
            conversationsList.value.unshift(e.data.conversation);
        }
    } else if (e.action === 'deleted') {
        const conv = conversationsList.value.find(c => c.id === e.conversation_id);
        if (conv && conv.latest_message?.id === e.data?.message_id) {
            conv.latest_message.body = 'This message was deleted';
            conv.latest_message.is_deleted = true;
        }
    }
};

const handleConversationChannelEvent = (e) => {
    if (!props.activeConversation || props.activeConversation.id !== e.conversation_id) return;

    if (e.action === 'created') {
        const msg = e.data?.message;
        if (!msg) return;

        const isMe = msg.sender_id === currentUser.value.id;

        // Reconcile pending message if current user was sender
        if (isMe) {
            const pendingIndex = pendingMessages.value.findIndex(
                p => p.conversation_id === e.conversation_id && (p.body === msg.body || p.status === 'sending' || p.status === 'sent')
            );
            if (pendingIndex !== -1) {
                pendingMessages.value.splice(pendingIndex, 1);
            }
        }

        // Avoid duplicate message appending
        if (!activeMessages.value.some(m => m.id === msg.id)) {
            activeMessages.value.push({
                ...msg,
                is_sender: isMe,
            });

            if (isMe || isNearBottom()) {
                scrollToBottom(true);
            } else {
                hasNewMessagesBelow.value = true;
                newIncomingCount.value++;
            }

            if (!isMe) {
                // Silently mark as read on server in background via Axios
                axios.post(route('chat.mark-read', e.conversation_id)).catch(() => {});
            }
        }
    } else if (e.action === 'reaction_toggled') {
        const messageId = e.data?.message_id;
        const reactions = e.data?.reactions;
        if (messageId && Array.isArray(reactions)) {
            const targetMsg = activeMessages.value.find(m => m.id === messageId);
            if (targetMsg) {
                targetMsg.reactions = reactions.map(r => ({
                    ...r,
                    reacted_by_me: r.user_ids ? r.user_ids.includes(currentUser.value.id) : false,
                }));
            }
        }
    } else if (e.action === 'deleted') {
        const messageId = e.data?.message_id;
        if (messageId) {
            const targetMsg = activeMessages.value.find(m => m.id === messageId);
            if (targetMsg) {
                targetMsg.is_deleted = true;
                targetMsg.body = 'This message was deleted';
                targetMsg.attachment_url = null;
                targetMsg.attachment_name = null;
            }
        }
    }
};

// Watch active conversation switches
watch(() => props.activeConversation?.id, (newId, oldId) => {
    if (newId !== oldId) {
        activeMessages.value = [...(props.activeConversation?.messages || [])];
        hasMoreMessages.value = !!props.activeConversation?.has_more;
        hasNewMessagesBelow.value = false;
        newIncomingCount.value = 0;
        activeMessageMenuId.value = null;
        clearSelectedFile();
        updateConversationChannelSubscription(newId);
        scrollToBottom(false);
        nextTick(focusComposer);
    }
});

// Merge props.activeConversation.messages updates by message ID while preserving locally loaded older messages
watch(() => props.activeConversation?.messages, (newMsgs) => {
    if (!newMsgs || !props.activeConversation) return;

    const incomingMap = new Map(newMsgs.map(m => [m.id, m]));
    const updated = activeMessages.value.map(existingMsg => {
        if (incomingMap.has(existingMsg.id)) {
            const incoming = incomingMap.get(existingMsg.id);
            incomingMap.delete(existingMsg.id);
            return { ...existingMsg, ...incoming };
        }
        return existingMsg;
    });

    for (const msg of incomingMap.values()) {
        if (!updated.some(m => m.id === msg.id)) {
            updated.push(msg);
        }
    }

    activeMessages.value = updated;
}, { deep: true });

// Close open message action menus when pressing Escape
const handleGlobalKeydown = (e) => {
    if (e.key === 'Escape') {
        activeMessageMenuId.value = null;
        showEmojiPicker.value = false;
    }
};

onMounted(() => {
    scrollToBottom(false);
    setupEchoListeners();
    nextTick(focusComposer);
    window.addEventListener('keydown', handleGlobalKeydown);
});

onBeforeUnmount(() => {
    window.removeEventListener('keydown', handleGlobalKeydown);
    if (window.Echo) {
        if (currentUser.value.id) {
            window.Echo.private(`App.Models.User.${currentUser.value.id}`)
                .stopListening('.message.sent', handleUserChannelEvent);
        }
        if (subscribedConversationId) {
            window.Echo.leave(`chat.conversation.${subscribedConversationId}`);
        }
    }
    if (selectedFilePreview.value) {
        URL.revokeObjectURL(selectedFilePreview.value);
    }
    if (searchDebounceTimeout) {
        clearTimeout(searchDebounceTimeout);
    }
});
</script>

<template>
    <AppLayout title="Messenger">
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
                                    {{ conversationsList.length }} active
                                </span>
                            </div>
                            <div class="flex items-center gap-2">
                                <div class="relative flex-1">
                                    <input
                                        v-model="searchQuery"
                                        type="text"
                                        placeholder="Search conversations..."
                                        class="w-full pl-9 pr-4 py-2 bg-[#fffef7] text-gray-900 text-sm border border-cream-500/60 focus:border-forest-600 focus:ring-2 focus:ring-forest-200 rounded-xl transition duration-150 placeholder:text-gray-400"
                                        aria-label="Search conversations"
                                    />
                                    <svg class="size-4 text-gray-400 absolute left-3 top-2.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
                                    </svg>
                                </div>
                                <div>
                                    <button
                                        @click="showNewChatModal = true"
                                        class="inline-flex items-center px-4 py-2 bg-forest-500 hover:bg-forest-600 active:bg-forest-700 text-white text-sm font-semibold rounded-xl shadow-xs transition-all duration-150 transform active:scale-95 cursor-pointer"
                                        aria-label="Start new conversation"
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
                                            v-if="conv.type === 'group'"
                                            class="absolute bottom-0 right-0 size-4 bg-forest-700 text-white rounded-full flex items-center justify-center shadow-xs ring-1 ring-white"
                                            title="Group chat"
                                        >
                                            <svg class="size-2.5" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 003.741-.479 3 3 0 00-4.682-2.72m.94 3.199l-.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0112 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 016 18.719m12 0a5.971 5.971 0 00-.941-3.197m0 0A5.995 5.995 0 0012 12.75a5.995 5.995 0 00-5.058 2.772m0 0a3 3 0 00-4.681 2.72 8.986 8.986 0 003.74.477m.94-3.197a5.971 5.971 0 00-.94 3.197M15 6.75a3 3 0 11-6 0 3 3 0 016 0zm6 3a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0zm-13.5 0a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0z" />
                                            </svg>
                                        </span>
                                        <span
                                            v-else-if="conv.other_user?.is_online && !conv.is_system && conv.type !== 'system'"
                                            class="absolute bottom-0 right-0 size-3.5 bg-forest-500 border-2 border-white rounded-full shadow-xs"
                                            title="Active now"
                                        ></span>
                                    </div>

                                    <!-- Conversation Details -->
                                    <div class="min-w-0 flex-1">
                                        <div class="flex items-center justify-between mb-1">
                                            <h4 class="text-sm font-semibold text-gray-900 truncate group-hover:text-forest-900 transition flex items-center gap-1.5">
                                                <span>{{ (conv.is_system || conv.type === 'system') ? 'System Notifications' : conv.title }}</span>
                                                <span v-if="conv.type === 'group'" class="text-[10px] font-semibold px-1.5 py-0.5 bg-lime-200 text-forest-900 rounded-md shrink-0">Group</span>
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
                                    class="mt-3 text-xs text-forest-800 hover:text-forest-950 font-semibold cursor-pointer"
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
                                        aria-label="Back to conversations list"
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
                                            v-if="activeConversation.type === 'group'"
                                            class="absolute bottom-0 right-0 size-3.5 bg-forest-700 text-white rounded-full flex items-center justify-center shadow-xs ring-1 ring-white"
                                            title="Group chat"
                                        >
                                            <svg class="size-2" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 003.741-.479 3 3 0 00-4.682-2.72m.94 3.199l-.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0112 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 016 18.719m12 0a5.971 5.971 0 00-.941-3.197m0 0A5.995 5.995 0 0012 12.75a5.995 5.995 0 00-5.058 2.772m0 0a3 3 0 00-4.681 2.72 8.986 8.986 0 003.74.477m.94-3.197a5.971 5.971 0 00-.94 3.197M15 6.75a3 3 0 11-6 0 3 3 0 016 0zm6 3a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0zm-13.5 0a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0z" />
                                            </svg>
                                        </span>
                                        <span
                                            v-else-if="activeConversation.other_user?.is_online && !activeConversation.is_system && activeConversation.type !== 'system'"
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
                                            <span v-else-if="activeConversation.type === 'group'" class="text-forest-700 font-medium">
                                                Group Chat &bull; {{ activeConversation.participants?.length || 0 }} members
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
                                        class="p-2 text-forest-900 hover:text-forest-700 hover:bg-cream-400/40 rounded-xl transition cursor-pointer"
                                        title="User Information"
                                        aria-label="View user profile and conversation information"
                                    >
                                        <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z" />
                                        </svg>
                                    </button>

                                    <button
                                        @click="showInfoPanel = !showInfoPanel"
                                        class="p-2 text-forest-900 hover:text-forest-700 hover:bg-cream-400/40 rounded-xl transition cursor-pointer"
                                        title="Chat Settings"
                                        aria-label="Open conversation settings"
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
                                <div class="flex-1 flex flex-col min-h-0 overflow-hidden relative">
                                    <!-- Messages Scroll Container -->
                                    <div 
                                        ref="messagesContainer"
                                        @scroll="onMessagesScroll"
                                        class="flex-1 overflow-y-auto p-6 space-y-4 bg-cream-300"
                                    >
                                        <!-- Load Older Messages Button (Top of message history) -->
                                        <div v-if="hasMoreMessages" class="flex justify-center pb-2 pt-1">
                                            <button
                                                type="button"
                                                @click="loadOlderMessages"
                                                :disabled="isLoadingOlder"
                                                class="inline-flex items-center px-4 py-1.5 text-xs font-semibold text-forest-900 bg-cream-200 hover:bg-cream-100 rounded-full border border-cream-500/50 shadow-xs transition duration-150 disabled:opacity-50 cursor-pointer"
                                                aria-label="Load older messages"
                                            >
                                                <svg v-if="isLoadingOlder" class="size-3.5 me-1.5 animate-spin text-forest-900" fill="none" viewBox="0 0 24 24">
                                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                                </svg>
                                                <svg v-else class="size-3.5 me-1.5 text-forest-800" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m0-15l-4.5 4.5m4.5-4.5l4.5 4.5" />
                                                </svg>
                                                <span>{{ isLoadingOlder ? 'Loading older messages...' : 'Load older messages' }}</span>
                                            </button>
                                        </div>

                                        <template v-if="allMessages.length > 0">
                                            <div
                                                v-for="message in allMessages"
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
                                                    <!-- Accessible Actions & Reactions for Sender (Left of bubble) -->
                                                    <div
                                                        v-if="message.is_sender && !message.is_deleted && message.status !== 'sending' && message.status !== 'failed'"
                                                        class="flex items-center space-x-1 shrink-0 z-10"
                                                    >
                                                        <!-- Trigger toggle for mobile/keyboard users -->
                                                        <button
                                                            type="button"
                                                            @click="activeMessageMenuId = activeMessageMenuId === message.id ? null : message.id"
                                                            class="p-1 rounded-full text-forest-900/60 hover:text-forest-900 hover:bg-cream-400/50 transition focus:opacity-100 focus:outline-hidden"
                                                            :class="activeMessageMenuId === message.id ? 'opacity-100 bg-cream-400/50' : 'opacity-0 group-hover:opacity-100 group-focus-within:opacity-100'"
                                                            aria-label="Message reactions and actions"
                                                        >
                                                            <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 6.75a.75.75 0 110-1.5.75.75 0 010 1.5zM12 12.75a.75.75 0 110-1.5.75.75 0 010 1.5zM12 18.75a.75.75 0 110-1.5.75.75 0 010 1.5z" />
                                                            </svg>
                                                        </button>

                                                        <!-- Popup Reaction Bar -->
                                                        <div
                                                            class="transition-opacity duration-150 flex items-center bg-cream-100 shadow-md border border-cream-500/40 rounded-full px-2.5 py-1 space-x-1"
                                                            :class="activeMessageMenuId === message.id ? 'opacity-100' : 'opacity-0 group-hover:opacity-100 group-focus-within:opacity-100 pointer-events-none group-hover:pointer-events-auto group-focus-within:pointer-events-auto'"
                                                        >
                                                            <button
                                                                v-for="(emoji, reactionKey) in reactionMap"
                                                                :key="reactionKey"
                                                                type="button"
                                                                @click="toggleReaction(message.id, reactionKey)"
                                                                class="hover:scale-125 transition-transform text-sm p-0.5 cursor-pointer focus:outline-hidden focus:ring-1 focus:ring-forest-600 rounded-sm"
                                                                :title="reactionKey"
                                                                :aria-label="'React with ' + reactionKey"
                                                            >
                                                                {{ emoji }}
                                                            </button>

                                                            <!-- Delete button for sender -->
                                                            <button
                                                                v-if="!activeConversation.is_system && activeConversation.type !== 'system'"
                                                                type="button"
                                                                @click="promptDeleteMessage(message.id)"
                                                                class="p-1 text-gray-400 hover:text-red-500 transition border-l border-cream-400 pl-1.5 ml-0.5 cursor-pointer focus:outline-hidden focus:ring-1 focus:ring-red-500 rounded-sm"
                                                                title="Delete message"
                                                                aria-label="Delete message"
                                                            >
                                                                <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" />
                                                                </svg>
                                                            </button>
                                                        </div>
                                                    </div>

                                                    <!-- Message Content Container (Bubble & Reaction Badges) -->
                                                    <div class="flex flex-col" :class="message.is_sender ? 'items-end' : 'items-start'">
                                                        <!-- Message Bubble -->
                                                        <div
                                                            class="px-4 py-2.5 shadow-xs text-sm break-words relative transition"
                                                            :class="[
                                                                message.is_sender
                                                                    ? 'bg-golden-500 text-gray-950 font-medium rounded-2xl rounded-tr-xs border border-golden-600/30'
                                                                    : 'bg-golden-400 text-gray-900 font-medium rounded-2xl rounded-tl-xs border border-golden-500/30',
                                                                message.is_deleted ? 'italic opacity-60 text-xs' : '',
                                                                message.status === 'sending' ? 'opacity-90' : '',
                                                                message.status === 'failed' ? 'border-red-400 bg-amber-100' : ''
                                                            ]"
                                                        >
                                                            <!-- Image Attachment -->
                                                            <div v-if="message.attachment_url && message.type === 'image'" class="mb-2 overflow-hidden rounded-xl">
                                                                <a :href="message.attachment_url" target="_blank" rel="noopener noreferrer">
                                                                    <img 
                                                                        :src="message.attachment_url" 
                                                                        :alt="message.attachment_name || 'Attached photo'"
                                                                        class="max-h-64 rounded-xl object-cover hover:opacity-95 transition"
                                                                    />
                                                                </a>
                                                            </div>

                                                            <!-- Document / File Attachment -->
                                                            <div v-else-if="(message.attachment_url || message.attachment_name) && message.type === 'file'" class="mb-1">
                                                                <a
                                                                    :href="message.attachment_url || '#'"
                                                                    :target="message.attachment_url ? '_blank' : '_self'"
                                                                    :download="!!message.attachment_url"
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
                                                                type="button"
                                                                @click="toggleReaction(message.id, reaction.reaction)"
                                                                class="inline-flex items-center space-x-1 px-2 py-0.5 rounded-full text-xs font-medium border shadow-xs transition cursor-pointer"
                                                                :class="reaction.reacted_by_me ? 'bg-cream-100 border-forest-600 text-forest-900 font-bold' : 'bg-cream-200 border-cream-500/50 text-gray-700'"
                                                                :aria-label="`Reacted ${reaction.count} times with ${reaction.reaction}`"
                                                            >
                                                                <span>{{ reactionMap[reaction.reaction] || reaction.reaction }}</span>
                                                                <span v-if="reaction.count > 1" class="text-[10px] font-bold">{{ reaction.count }}</span>
                                                            </button>
                                                        </div>
                                                    </div>

                                                    <!-- Accessible Actions & Reactions for Receiver (Right of bubble) -->
                                                    <div
                                                        v-if="!message.is_sender && !message.is_deleted"
                                                        class="flex items-center space-x-1 shrink-0 z-10"
                                                    >
                                                        <!-- Popup Reaction Bar -->
                                                        <div
                                                            class="transition-opacity duration-150 flex items-center bg-cream-100 shadow-md border border-cream-500/40 rounded-full px-2.5 py-1 space-x-1"
                                                            :class="activeMessageMenuId === message.id ? 'opacity-100' : 'opacity-0 group-hover:opacity-100 group-focus-within:opacity-100 pointer-events-none group-hover:pointer-events-auto group-focus-within:pointer-events-auto'"
                                                        >
                                                            <button
                                                                v-for="(emoji, reactionKey) in reactionMap"
                                                                :key="reactionKey"
                                                                type="button"
                                                                @click="toggleReaction(message.id, reactionKey)"
                                                                class="hover:scale-125 transition-transform text-sm p-0.5 cursor-pointer focus:outline-hidden focus:ring-1 focus:ring-forest-600 rounded-sm"
                                                                :title="reactionKey"
                                                                :aria-label="'React with ' + reactionKey"
                                                            >
                                                                {{ emoji }}
                                                            </button>
                                                        </div>

                                                        <!-- Trigger toggle for mobile/keyboard users -->
                                                        <button
                                                            type="button"
                                                            @click="activeMessageMenuId = activeMessageMenuId === message.id ? null : message.id"
                                                            class="p-1 rounded-full text-forest-900/60 hover:text-forest-900 hover:bg-cream-400/50 transition focus:opacity-100 focus:outline-hidden"
                                                            :class="activeMessageMenuId === message.id ? 'opacity-100 bg-cream-400/50' : 'opacity-0 group-hover:opacity-100 group-focus-within:opacity-100'"
                                                            aria-label="Message reactions"
                                                        >
                                                            <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 6.75a.75.75 0 110-1.5.75.75 0 010 1.5zM12 12.75a.75.75 0 110-1.5.75.75 0 010 1.5zM12 18.75a.75.75 0 110-1.5.75.75 0 010 1.5z" />
                                                            </svg>
                                                        </button>
                                                    </div>
                                                </div>

                                                <!-- Message Timestamp & Status (Under Bubble) -->
                                                <div 
                                                    class="text-[10px] text-gray-500 mt-1 px-1 flex items-center space-x-1.5"
                                                    :class="message.is_sender ? 'justify-end' : 'justify-start'"
                                                >
                                                    <span>{{ message.created_at }}</span>
                                                    <template v-if="message.is_sender">
                                                        <span class="text-gray-400">•</span>
                                                        <!-- Sending Status -->
                                                        <span v-if="message.status === 'sending'" class="text-amber-700 font-semibold inline-flex items-center space-x-1">
                                                            <svg class="size-3 animate-spin text-amber-700" fill="none" viewBox="0 0 24 24">
                                                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                                            </svg>
                                                            <span>Sending</span>
                                                        </span>
                                                        <!-- Failed to Send Status + Resend Button -->
                                                        <span v-else-if="message.status === 'failed'" class="text-red-600 font-semibold inline-flex items-center space-x-1">
                                                            <span>Failed to Send</span>
                                                            <button
                                                                type="button"
                                                                @click="resendMessage(message)"
                                                                class="ms-1 px-1.5 py-0.5 text-[10px] font-bold bg-red-100 hover:bg-red-200 text-red-700 rounded transition inline-flex items-center space-x-1 border border-red-300 cursor-pointer"
                                                                title="Resend message"
                                                                aria-label="Resend failed message"
                                                            >
                                                                <svg class="size-3" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99" />
                                                                </svg>
                                                                <span>Resend</span>
                                                            </button>
                                                        </span>
                                                        <!-- Sent Status -->
                                                        <span v-else class="text-gray-500 font-medium">
                                                            Sent
                                                        </span>
                                                    </template>
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

                                    <!-- Floating "New Messages" Button -->
                                    <button
                                        v-if="hasNewMessagesBelow"
                                        type="button"
                                        @click="scrollToNewest"
                                        class="absolute bottom-24 right-8 z-30 inline-flex items-center space-x-2 px-3.5 py-2 bg-forest-900 hover:bg-forest-950 text-white text-xs font-bold rounded-full shadow-lg border border-golden-400/50 cursor-pointer animate-bounce transition-transform active:scale-95"
                                        aria-label="Scroll to newest messages"
                                    >
                                        <span>New messages</span>
                                        <span v-if="newIncomingCount > 1" class="px-1.5 py-0.5 bg-lime-400 text-forest-950 rounded-full text-[10px] font-extrabold leading-none">{{ newIncomingCount }}</span>
                                        <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 13.5L12 21m0 0l-7.5-7.5m7.5 7.5V3" />
                                        </svg>
                                    </button>

                                    <!-- Input Bar -->
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
                                        <!-- File Validation Error Banner -->
                                        <div v-if="fileValidationError" class="mb-3 p-2.5 bg-red-50 border border-red-200 text-red-700 text-xs rounded-xl flex items-center justify-between">
                                            <div class="flex items-center space-x-2">
                                                <svg class="size-4 shrink-0 text-red-500" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" />
                                                </svg>
                                                <span>{{ fileValidationError }}</span>
                                            </div>
                                            <button type="button" @click="fileValidationError = ''" class="text-red-500 hover:text-red-700 p-1" aria-label="Dismiss error">
                                                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                                </svg>
                                            </button>
                                        </div>

                                        <!-- File Preview Banner -->
                                        <div v-if="selectedFile" class="mb-3 p-2.5 bg-cream-200 rounded-xl flex items-center justify-between border border-cream-500/60">
                                            <div class="flex items-center space-x-2 truncate">
                                                <img v-if="selectedFilePreview" :src="selectedFilePreview" alt="Attachment preview" class="size-10 rounded-lg object-cover" />
                                                <svg v-else class="size-6 text-forest-800" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M18.375 12.739l-7.693 7.693a4.5 4.5 0 01-6.364-6.364l10.94-10.94A3 3 0 1119.5 7.372L8.552 18.32m.009-.01l-.01.01m5.699-9.941l-7.81 7.81a1.5 1.5 0 002.112 2.13" />
                                                </svg>
                                                <span class="text-xs font-medium text-forest-900 truncate">{{ selectedFile.name }}</span>
                                            </div>
                                            <button type="button" @click="clearSelectedFile" class="text-forest-700 hover:text-red-500 p-1 cursor-pointer" aria-label="Remove attachment">
                                                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                                </svg>
                                            </button>
                                        </div>

                                        <!-- Quick Emoji Popover -->
                                        <div 
                                            v-if="showEmojiPicker"
                                            class="absolute bottom-20 left-6 bg-cream-200 border border-cream-500/60 shadow-xl rounded-2xl p-3 flex flex-wrap gap-2 w-64 z-30"
                                            role="dialog"
                                            aria-label="Emoji Picker"
                                        >
                                            <button
                                                v-for="e in emojis"
                                                :key="e"
                                                type="button"
                                                @click="insertEmoji(e)"
                                                class="hover:scale-125 transition-transform text-lg p-1.5 cursor-pointer focus:outline-hidden focus:ring-1 focus:ring-forest-600 rounded-sm"
                                                :aria-label="'Insert ' + e"
                                            >
                                                {{ e }}
                                            </button>
                                        </div>

                                        <!-- Input Controls Form -->
                                        <form @submit.prevent="sendMessage" class="flex items-center space-x-3">
                                            <!-- Hidden File Input -->
                                            <input
                                                ref="fileInput"
                                                type="file"
                                                class="hidden"
                                                accept="image/*,.pdf,.doc,.docx,.zip,.txt"
                                                @change="onFileSelected"
                                                aria-label="File upload"
                                            />

                                            <!-- Pill Wrapper for attachment icon, emoji icon, and textarea -->
                                            <div class="flex-1 flex items-center bg-[#ebe2c8] border border-cream-500/50 rounded-full px-3.5 py-1 focus-within:ring-2 focus-within:ring-forest-600/30 focus-within:border-forest-600 transition">
                                                <!-- Attachment Button -->
                                                <button
                                                    type="button"
                                                    @click="$refs.fileInput.click()"
                                                    class="p-1.5 text-forest-900 hover:text-forest-700 hover:bg-cream-400/40 rounded-full transition shrink-0 cursor-pointer"
                                                    title="Attach file or photo"
                                                    aria-label="Attach file or photo"
                                                >
                                                    <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M18.375 12.739l-7.693 7.693a4.5 4.5 0 01-6.364-6.364l10.94-10.94A3 3 0 1119.5 7.372L8.552 18.32m.009-.01l-.01.01m5.699-9.941l-7.81 7.81a1.5 1.5 0 002.112 2.13" />
                                                    </svg>
                                                </button>

                                                <!-- Emoji Button -->
                                                <button
                                                    type="button"
                                                    @click="showEmojiPicker = !showEmojiPicker"
                                                    class="p-1.5 text-forest-900 hover:text-forest-700 hover:bg-cream-400/40 rounded-full transition shrink-0 cursor-pointer"
                                                    title="Insert emoji"
                                                    aria-label="Insert emoji"
                                                >
                                                    <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.182 15.182a4.5 4.5 0 01-6.364 0M21 12a9 9 0 11-18 0 9 9 0 0118 0zM9.75 9.75c0 .414-.168.75-.375.75S9 10.164 9 9.75 9.168 9 9.375 9s.375.336.375.75zm-.375 0h.008v.015h-.008V9.75zm5.625 0c0 .414-.168.75-.375.75s-.375-.336-.375-.75.168-.75.375-.75.375.336.375.75zm-.375 0h.008v.015h-.008V9.75z" />
                                                    </svg>
                                                </button>

                                                <!-- Auto-growing Textarea -->
                                                <textarea
                                                    ref="composerTextarea"
                                                    v-model="messageForm.body"
                                                    rows="1"
                                                    placeholder="Type a message... (Enter to send, Shift+Enter for newline)"
                                                    class="w-full resize-none py-1.5 px-3 bg-transparent text-sm text-gray-900 placeholder:text-gray-600 border-0 focus:ring-0 overflow-y-hidden"
                                                    @keydown="onComposerKeydown"
                                                    aria-label="Type message content"
                                                ></textarea>
                                            </div>

                                            <!-- Send Button (Forest Green Circle at Far Right) -->
                                            <button
                                                type="submit"
                                                :disabled="messageForm.processing || (!messageForm.body.trim() && !messageForm.attachment)"
                                                class="size-10 bg-forest-900 hover:bg-forest-950 disabled:opacity-40 text-white rounded-full shadow-md flex items-center justify-center transition-all transform active:scale-95 shrink-0 cursor-pointer"
                                                title="Send message"
                                                aria-label="Send message"
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
                                        <button @click="showInfoPanel = false" class="text-gray-500 hover:text-gray-700 p-1 rounded-lg hover:bg-cream-300 transition cursor-pointer" aria-label="Close conversation information">
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
                                                        alt="Attachment"
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
                                class="inline-flex items-center px-5 py-2.5 bg-forest-900 hover:bg-forest-950 text-white text-sm font-semibold rounded-xl shadow-xs transition transform active:scale-95 cursor-pointer"
                                aria-label="Start new conversation"
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
            role="dialog"
            aria-modal="true"
            aria-label="New chat modal"
        >
            <div class="bg-cream-200 rounded-2xl shadow-2xl max-w-md w-full overflow-hidden border border-cream-500/60 flex flex-col max-h-[85vh]">
                <!-- Modal Header & Tabs -->
                <div class="p-4 border-b border-cream-500/40">
                    <div class="flex items-center justify-between mb-3">
                        <h3 class="font-bold text-base text-gray-900">
                            {{ newChatType === 'group' ? 'Create Group Chat' : 'New Direct Message' }}
                        </h3>
                        <button @click="showNewChatModal = false" class="text-gray-500 hover:text-gray-700 p-1 cursor-pointer" aria-label="Close dialog">
                            <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    <!-- Type Switcher Tabs -->
                    <div class="flex p-1 bg-cream-300/80 rounded-xl space-x-1">
                        <button
                            @click="newChatType = 'direct'"
                            class="flex-1 py-1.5 text-xs font-semibold rounded-lg transition cursor-pointer"
                            :class="newChatType === 'direct' ? 'bg-cream-100 text-forest-900 font-bold shadow-xs' : 'text-gray-600 hover:text-gray-900'"
                        >
                            Direct Message
                        </button>
                        <button
                            @click="newChatType = 'group'"
                            class="flex-1 py-1.5 text-xs font-semibold rounded-lg transition cursor-pointer"
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
                                @input="onContactSearchInput"
                                type="text"
                                placeholder="Type a contact's name or email..."
                                class="w-full pl-9 pr-4 py-2 bg-[#fffef7] text-gray-900 text-sm border border-cream-500/60 focus:border-forest-600 focus:ring-2 focus:ring-forest-200 rounded-xl transition"
                                aria-label="Search contacts"
                            />
                            <svg class="size-4 text-gray-400 absolute left-3 top-2.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
                            </svg>
                        </div>
                    </div>

                    <div class="overflow-y-auto p-2 divide-y divide-cream-500/20 flex-1 min-h-[220px]">
                        <!-- Loading State -->
                        <div v-if="isLoadingContacts" class="p-8 text-center text-forest-800 flex flex-col items-center justify-center">
                            <svg class="size-6 animate-spin text-forest-700 mb-2" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                            <span class="text-xs font-semibold">Searching contacts...</span>
                        </div>

                        <template v-else-if="contacts.length > 0">
                            <button
                                v-for="user in contacts"
                                :key="user.id"
                                @click="startDirectChat(user.id)"
                                class="w-full flex items-center p-3 hover:bg-cream-300/60 rounded-xl transition text-left group cursor-pointer"
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
                            {{ newChatSearch.trim() ? `No contacts match "${newChatSearch}"` : 'No contacts available' }}
                        </div>
                    </div>
                </template>

                <!-- GROUP CHAT TAB CONTENT -->
                <template v-else>
                    <div class="p-4 border-b border-cream-500/40 bg-cream-100/50 space-y-3">
                        <div>
                            <label for="group-chat-name" class="block text-xs font-semibold text-gray-700 mb-1">Group Name</label>
                            <input
                                id="group-chat-name"
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
                                    @input="onContactSearchInput"
                                    type="text"
                                    placeholder="Search contacts..."
                                    class="w-full pl-9 pr-4 py-2 bg-[#fffef7] text-gray-900 text-sm border border-cream-500/60 focus:border-forest-600 focus:ring-2 focus:ring-forest-200 rounded-xl transition"
                                    aria-label="Search contacts for group"
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
                                <span>{{ selectedGroupUsers.find(u => u.id === userId)?.name || contacts.find(u => u.id === userId)?.name || 'User' }}</span>
                                <button type="button" @click="removeGroupUserById(userId)" class="text-forest-700 hover:text-red-600 cursor-pointer" aria-label="Remove member">
                                    <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                </button>
                            </span>
                        </div>
                    </div>

                    <!-- Member Selection List -->
                    <div class="overflow-y-auto p-2 divide-y divide-cream-500/20 flex-1 min-h-[180px]">
                        <!-- Loading State -->
                        <div v-if="isLoadingContacts" class="p-8 text-center text-forest-800 flex flex-col items-center justify-center">
                            <svg class="size-6 animate-spin text-forest-700 mb-2" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                            <span class="text-xs font-semibold">Searching contacts...</span>
                        </div>

                        <template v-else-if="contacts.length > 0">
                            <div
                                v-for="user in contacts"
                                :key="user.id"
                                @click="toggleGroupUser(user)"
                                class="w-full flex items-center p-3 hover:bg-cream-300/60 rounded-xl transition text-left cursor-pointer group"
                            >
                                <input
                                    type="checkbox"
                                    :checked="groupForm.user_ids.includes(user.id)"
                                    class="size-4 text-forest-900 rounded-md border-cream-500 me-3 focus:ring-forest-600 pointer-events-none"
                                    :aria-label="`Select ${user.name}`"
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
                            {{ newChatSearch.trim() ? `No contacts match "${newChatSearch}"` : 'No contacts available' }}
                        </div>
                    </div>

                    <!-- Group Footer Actions -->
                    <div class="p-3 border-t border-cream-500/40 bg-cream-100 flex justify-end">
                        <button
                            @click="createGroupChat"
                            :disabled="!groupForm.title.trim() || groupForm.user_ids.length === 0 || groupForm.processing"
                            class="px-5 py-2 bg-forest-900 hover:bg-forest-950 disabled:opacity-40 text-white text-xs font-semibold rounded-xl shadow-xs transition cursor-pointer"
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

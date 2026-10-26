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

// Sidebar & Search State
const searchQuery = ref('');
const sidebarFilter = ref('all'); // 'all' | 'unread'
const showNewChatModal = ref(false);
const newChatSearch = ref('');
const showInfoPanel = ref(false);

// Local state for sidebar conversations list
const conversationsList = ref([...props.conversations]);
watch(() => props.conversations, (newVal) => {
    conversationsList.value = [...(newVal || [])];
}, { deep: true });

watch(() => props.activeConversation, (active) => {
    if (active?.id) {
        const currentConv = conversationsList.value.find(c => c.id === active.id);
        if (currentConv) {
            currentConv.unread_count = 0;
            currentConv.has_unread_mentions = false;
            currentConv.unread_mentions_count = 0;
        }
    }
}, { immediate: true });

const unreadConversationsCount = computed(() => {
    return conversationsList.value.filter(c => (c.unread_count || 0) > 0 || c.has_unread_mentions).length;
});

// Filter conversations based on sidebar search and unread filter
const filteredConversations = computed(() => {
    let list = conversationsList.value;

    if (sidebarFilter.value === 'unread') {
        list = list.filter(c => (c.unread_count || 0) > 0 || c.has_unread_mentions);
    }

    if (!searchQuery.value.trim()) return list;

    const q = searchQuery.value.toLowerCase();
    return list.filter(c =>
        (c.title && c.title.toLowerCase().includes(q)) ||
        (c.other_user && c.other_user.name && c.other_user.name.toLowerCase().includes(q)) ||
        (c.participants && c.participants.some(p => p.name && p.name.toLowerCase().includes(q)))
    );
});

// Active conversation state & messages
const messagesContainer = ref(null);
const composerTextarea = ref(null);
const fileInput = ref(null);
const selectedFile = ref(null);
const selectedFilePreview = ref(null);
const fileValidationError = ref('');
const showEmojiPicker = ref(false);
const activeMessageMenuId = ref(null);
const activeConversationMenuId = ref(null);

const activeMessages = ref([...(props.activeConversation?.messages || [])]);
const hasMoreMessages = ref(!!props.activeConversation?.has_more);
const isLoadingOlder = ref(false);
const pendingMessages = ref([]);
const hasNewMessagesBelow = ref(false);
const newIncomingCount = ref(0);
const highlightedMessageId = ref(null);

// In-chat Search State
const showChatSearch = ref(false);
const chatSearchQuery = ref('');
const chatSearchResults = ref([]);
const isSearchingChat = ref(false);
const hasSearchedChat = ref(false);
let chatSearchTimeout = null;

// Reply & Edit State
const replyingToMessage = ref(null);
const editingMessage = ref(null);

// Upload progress & cancellation
const uploadProgress = ref(null);
let activeUploadCancelToken = null;

// Lightbox State
const lightboxImage = ref(null); // { url, name }

// Shared Attachments Tab
const sharedTab = ref('all'); // 'all' | 'media' | 'files'

// Connectivity & Toast State
const isOnline = ref(typeof navigator !== 'undefined' ? navigator.onLine : true);
const isReconnecting = ref(false);
const toastMessage = ref('');
let toastTimeout = null;

const showToast = (msg) => {
    toastMessage.value = msg;
    if (toastTimeout) clearTimeout(toastTimeout);
    toastTimeout = setTimeout(() => {
        toastMessage.value = '';
    }, 2500);
};

// Typing Indicators State
const typingUsers = ref({}); // { [userId]: { name, timeout } }
let typingDebounceTimeout = null;

const activeTypingText = computed(() => {
    const names = Object.values(typingUsers.value).map(u => u.name);
    if (names.length === 0) return '';
    if (names.length === 1) return `${names[0]} is typing...`;
    if (names.length === 2) return `${names[0]} and ${names[1]} are typing...`;
    return `${names[0]} and ${names.length - 1} others are typing...`;
});

// Lazy contacts state for New Chat modal
const contacts = ref([]);
const isLoadingContacts = ref(false);
const selectedGroupUsers = ref([]);
let contactSearchDebounceTimeout = null;

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

// All messages combined
const allMessages = computed(() => {
    if (!props.activeConversation) return [];
    const serverMsgs = activeMessages.value || [];
    const activePending = pendingMessages.value.filter(
        p => p.conversation_id === props.activeConversation.id
    );
    if (activePending.length === 0) return serverMsgs;
    return [...serverMsgs, ...activePending];
});

// Filtered Shared Attachments
const filteredSharedAttachments = computed(() => {
    const list = props.activeConversation?.shared_attachments || [];
    if (sharedTab.value === 'media') {
        return list.filter(f => f.type === 'image');
    }
    if (sharedTab.value === 'files') {
        return list.filter(f => f.type !== 'image');
    }
    return list;
});

// Draft Persistence in LocalStorage
const getDraftKey = (convId) => `chat_draft_${convId}`;

const loadDraft = (convId) => {
    if (!convId) return '';
    try {
        return localStorage.getItem(getDraftKey(convId)) || '';
    } catch {
        return '';
    }
};

const saveDraft = (convId, text) => {
    if (!convId) return;
    try {
        if (text && text.trim()) {
            localStorage.setItem(getDraftKey(convId), text);
        } else {
            localStorage.removeItem(getDraftKey(convId));
        }
    } catch {}
};

const clearDraft = (convId) => {
    if (!convId) return;
    try {
        localStorage.removeItem(getDraftKey(convId));
    } catch {}
};

// Scroll helper
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

// Scroll to specific message and apply temporary highlight
const scrollToMessage = (messageId) => {
    nextTick(() => {
        const el = document.getElementById(`message-${messageId}`);
        if (el) {
            el.scrollIntoView({ behavior: 'smooth', block: 'center' });
            highlightedMessageId.value = messageId;
            setTimeout(() => {
                if (highlightedMessageId.value === messageId) {
                    highlightedMessageId.value = null;
                }
            }, 2500);
        }
    });
};

// Load older messages with cursor
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

// Drag and drop state
const isDragging = ref(false);
const dragCounter = ref(0);

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

watch(() => messageForm.body, (newVal) => {
    if (props.activeConversation?.id && !editingMessage.value) {
        saveDraft(props.activeConversation.id, newVal);
    }
    nextTick(resizeComposer);
});

const focusComposer = () => {
    if (composerTextarea.value) {
        composerTextarea.value.focus();
    }
};

// Emit real-time typing notification debounced
const emitTyping = () => {
    if (!props.activeConversation || editingMessage.value) return;
    if (typingDebounceTimeout) return;

    typingDebounceTimeout = setTimeout(() => {
        typingDebounceTimeout = null;
    }, 2500);

    axios.post(route('chat.typing', props.activeConversation.id)).catch(() => {});
};

// Mention autocomplete state & logic
const showMentionDropdown = ref(false);
const mentionQuery = ref('');
const mentionStartIndex = ref(-1);
const selectedMentionIndex = ref(0);

// Conversation members eligible to be mentioned (excluding current user and banned members)
const mentionableMembers = computed(() => {
    if (!props.activeConversation) return [];
    if (props.activeConversation.is_system || props.activeConversation.type === 'system') return [];

    let members = [];
    if (Array.isArray(props.activeConversation.participants) && props.activeConversation.participants.length > 0) {
        members = props.activeConversation.participants.filter(p => p.id !== currentUser.value.id && !p.is_banned);
    } else if (props.activeConversation.other_user && props.activeConversation.other_user.id !== currentUser.value.id && !props.activeConversation.other_user.is_banned) {
        members = [props.activeConversation.other_user];
    }
    return members;
});

// Filter mentionable members based on query typed after @
const filteredMentionMembers = computed(() => {
    if (!mentionableMembers.value.length) return [];
    const q = mentionQuery.value.trim().toLowerCase();
    if (!q) {
        return mentionableMembers.value;
    }
    return mentionableMembers.value.filter(m => {
        const username = (m.username || '').toLowerCase();
        const name = (m.name || '').toLowerCase();
        return username.includes(q) || name.includes(q);
    });
});

// Inspect textarea cursor position to check if user is typing an @mention
const checkMentionTrigger = () => {
    const el = composerTextarea.value;
    if (!el || !props.activeConversation || props.activeConversation.is_system) {
        showMentionDropdown.value = false;
        return;
    }

    const cursorPos = el.selectionStart;
    const textBeforeCursor = messageForm.body.slice(0, cursorPos);
    const lastAtIndex = textBeforeCursor.lastIndexOf('@');

    if (lastAtIndex === -1) {
        showMentionDropdown.value = false;
        return;
    }

    // Ensure @ is either at the start of text or preceded by whitespace
    const charBeforeAt = lastAtIndex > 0 ? textBeforeCursor[lastAtIndex - 1] : ' ';
    if (!/\s/.test(charBeforeAt)) {
        showMentionDropdown.value = false;
        return;
    }

    const query = textBeforeCursor.slice(lastAtIndex + 1);
    // Dismiss if whitespace has been entered after @ (mention finished or cancelled)
    if (/\s/.test(query)) {
        showMentionDropdown.value = false;
        return;
    }

    mentionQuery.value = query;
    mentionStartIndex.value = lastAtIndex;
    showMentionDropdown.value = true;
    selectedMentionIndex.value = 0;
};

// Select and insert a mention into the message
const selectMention = (member) => {
    const el = composerTextarea.value;
    const cursorPos = el ? el.selectionStart : messageForm.body.length;
    const before = messageForm.body.slice(0, mentionStartIndex.value);
    const after = messageForm.body.slice(cursorPos);
    const handle = member.username || member.name;
    const mentionText = `@${handle} `;

    messageForm.body = before + mentionText + after;
    showMentionDropdown.value = false;
    selectedMentionIndex.value = 0;

    nextTick(() => {
        resizeComposer();
        if (composerTextarea.value) {
            const newPos = before.length + mentionText.length;
            composerTextarea.value.focus();
            composerTextarea.value.setSelectionRange(newPos, newPos);
        }
    });
};

const onComposerInput = () => {
    emitTyping();
    checkMentionTrigger();
};

const onComposerBlur = () => {
    setTimeout(() => {
        showMentionDropdown.value = false;
    }, 180);
};

const onComposerKeyup = (e) => {
    if (['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(e.key)) {
        checkMentionTrigger();
    }
};

// Enter key sends; Shift+Enter creates a new line; Arrow keys & Enter/Tab handle mention navigation
const onComposerKeydown = (e) => {
    if (showMentionDropdown.value && filteredMentionMembers.value.length > 0) {
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            selectedMentionIndex.value = (selectedMentionIndex.value + 1) % filteredMentionMembers.value.length;
            return;
        }
        if (e.key === 'ArrowUp') {
            e.preventDefault();
            selectedMentionIndex.value = (selectedMentionIndex.value - 1 + filteredMentionMembers.value.length) % filteredMentionMembers.value.length;
            return;
        }
        if ((e.key === 'Enter' && !e.shiftKey) || e.key === 'Tab') {
            e.preventDefault();
            const member = filteredMentionMembers.value[selectedMentionIndex.value];
            if (member) {
                selectMention(member);
            }
            return;
        }
        if (e.key === 'Escape') {
            e.preventDefault();
            e.stopPropagation();
            showMentionDropdown.value = false;
            return;
        }
    }

    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        if (editingMessage.value) {
            submitEditMessage();
        } else {
            sendMessage();
        }
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

// Send a pending message via Inertia post
const sendPendingMessage = (pendingMsg) => {
    pendingMsg.status = 'sending';
    uploadProgress.value = pendingMsg.attachment_file ? 5 : null;

    router.post(
        route('chat.send-message', pendingMsg.conversation_id),
        {
            body: pendingMsg.body || '',
            reply_to_id: pendingMsg.reply_to_id || null,
            attachment: pendingMsg.attachment_file || null,
        },
        {
            preserveScroll: true,
            onProgress: (p) => {
                if (p && p.percentage) {
                    uploadProgress.value = p.percentage;
                }
            },
            onSuccess: () => {
                uploadProgress.value = null;
                pendingMsg.status = 'sent';
                clearDraft(pendingMsg.conversation_id);
                nextTick(() => {
                    const idx = pendingMessages.value.findIndex(p => p.id === pendingMsg.id);
                    if (idx !== -1) {
                        pendingMessages.value.splice(idx, 1);
                    }
                });
            },
            onError: (err) => {
                uploadProgress.value = null;
                console.error('Send message error:', err);
                pendingMsg.status = 'failed';
            },
            onFinish: () => {
                uploadProgress.value = null;
                if (pendingMsg.status === 'sending') {
                    pendingMsg.status = 'failed';
                }
            },
        }
    );
};

const cancelUpload = () => {
    uploadProgress.value = null;
    clearSelectedFile();
};

const resendMessage = (pendingMsg) => {
    sendPendingMessage(pendingMsg);
};

const removePendingMessage = (pendingMsgId) => {
    const idx = pendingMessages.value.findIndex(p => p.id === pendingMsgId);
    if (idx !== -1) {
        pendingMessages.value.splice(idx, 1);
    }
};

// Send message (optimistic update)
const sendMessage = () => {
    if (!props.activeConversation) return;
    if (!messageForm.body.trim() && !messageForm.attachment) return;

    const bodyText = messageForm.body;
    const fileObj = selectedFile.value;
    const previewUrl = selectedFilePreview.value;
    const conversationId = props.activeConversation.id;
    const replyMeta = replyingToMessage.value ? { ...replyingToMessage.value } : null;

    const tempId = 'pending-' + Date.now() + '-' + Math.random().toString(36).substring(2, 9);
    const user = currentUser.value;

    const pendingMsg = {
        id: tempId,
        conversation_id: conversationId,
        sender_id: user.id,
        reply_to_id: replyMeta ? replyMeta.id : null,
        reply_to: replyMeta,
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
    };

    pendingMessages.value.push(pendingMsg);

    messageForm.reset('body');
    selectedFile.value = null;
    selectedFilePreview.value = null;
    messageForm.attachment = null;
    fileValidationError.value = '';
    replyingToMessage.value = null;
    if (fileInput.value) fileInput.value.value = '';
    nextTick(resizeComposer);

    scrollToBottom(true);
    hasNewMessagesBelow.value = false;
    newIncomingCount.value = 0;

    sendPendingMessage(pendingMsg);
};

// Reply to message
const startReply = (message) => {
    cancelEditMessage();
    activeMessageMenuId.value = null;
    replyingToMessage.value = {
        id: message.id,
        sender_name: message.sender?.name || 'User',
        body: message.body || (message.attachment_name ? '📎 Attachment' : ''),
    };
    nextTick(focusComposer);
};

const cancelReply = () => {
    replyingToMessage.value = null;
};

// Copy message text
const copyMessageText = async (message) => {
    activeMessageMenuId.value = null;
    if (!message.body) return;
    try {
        await navigator.clipboard.writeText(message.body);
        showToast('Message copied to clipboard');
    } catch {
        showToast('Failed to copy text');
    }
};

// Edit message (15-min window)
const isWithin15Minutes = (rawDate) => {
    if (!rawDate) return false;
    const time = new Date(rawDate).getTime();
    if (isNaN(time)) return true;
    return (Date.now() - time) <= 15 * 60 * 1000;
};

const canEditMessage = (message) => {
    return message.is_sender &&
        !message.is_deleted &&
        message.type === 'text' &&
        isWithin15Minutes(message.raw_created_at || message.created_at);
};

const startEditMessage = (message) => {
    cancelReply();
    activeMessageMenuId.value = null;
    editingMessage.value = {
        id: message.id,
        originalBody: message.body,
    };
    messageForm.body = message.body;
    nextTick(() => {
        resizeComposer();
        focusComposer();
    });
};

const cancelEditMessage = () => {
    if (!editingMessage.value) return;
    editingMessage.value = null;
    messageForm.body = loadDraft(props.activeConversation?.id);
    nextTick(resizeComposer);
};

const submitEditMessage = () => {
    if (!editingMessage.value || !messageForm.body.trim()) return;

    const messageId = editingMessage.value.id;
    const newBody = messageForm.body;

    router.patch(
        route('chat.edit-message', messageId),
        { body: newBody },
        {
            preserveScroll: true,
            onSuccess: () => {
                editingMessage.value = null;
                messageForm.body = loadDraft(props.activeConversation?.id);
                showToast('Message updated');
                nextTick(resizeComposer);
            },
            onError: (err) => {
                console.error('Failed to edit message:', err);
                showToast('Error editing message');
            },
        }
    );
};

// Pin / Unpin Conversation
const togglePinConversation = (conv) => {
    activeConversationMenuId.value = null;
    router.post(route('chat.toggle-pin', conv.id), {}, {
        preserveScroll: true,
        onSuccess: () => {
            conv.is_pinned = !conv.is_pinned;
            if (props.activeConversation?.id === conv.id) {
                props.activeConversation.is_pinned = conv.is_pinned;
            }
            conversationsList.value.sort((a, b) => {
                if (a.is_pinned !== b.is_pinned) return b.is_pinned ? 1 : -1;
                return 0;
            });
            showToast(conv.is_pinned ? 'Conversation pinned' : 'Conversation unpinned');
        },
    });
};

// Mute / Unmute Conversation
const toggleMuteConversation = (conv) => {
    activeConversationMenuId.value = null;
    router.post(route('chat.toggle-mute', conv.id), {}, {
        preserveScroll: true,
        onSuccess: () => {
            conv.is_muted = !conv.is_muted;
            if (props.activeConversation?.id === conv.id) {
                props.activeConversation.is_muted = conv.is_muted;
            }
            showToast(conv.is_muted ? 'Notifications muted' : 'Notifications unmuted');
        },
    });
};

// Mark individual conversation as read
const markConversationAsRead = (conv) => {
    activeConversationMenuId.value = null;
    axios.post(route('chat.mark-read', conv.id))
        .then(() => {
            conv.unread_count = 0;
            conv.has_unread_mentions = false;
            conv.unread_mentions_count = 0;
            showToast('Marked as read');
        })
        .catch(() => {});
};

// Mark all conversations as read
const markAllConversationsAsRead = () => {
    router.post(route('chat.mark-all-read'), {}, {
        preserveScroll: true,
        onSuccess: () => {
            conversationsList.value.forEach(c => {
                c.unread_count = 0;
                c.has_unread_mentions = false;
                c.unread_mentions_count = 0;
            });
            showToast('All conversations marked as read');
        },
    });
};

// In-chat Search
const onChatSearchInput = () => {
    if (chatSearchTimeout) clearTimeout(chatSearchTimeout);
    const q = chatSearchQuery.value.trim();
    if (q.length < 2) {
        chatSearchResults.value = [];
        hasSearchedChat.value = false;
        isSearchingChat.value = false;
        return;
    }

    isSearchingChat.value = true;
    chatSearchTimeout = setTimeout(async () => {
        try {
            const res = await axios.get(route('chat.search-messages', props.activeConversation.id), {
                params: { q },
            });
            chatSearchResults.value = res.data.results || [];
            hasSearchedChat.value = true;
        } catch (err) {
            console.error('Chat search failed:', err);
        } finally {
            isSearchingChat.value = false;
        }
    }, 300);
};

const clearChatSearch = () => {
    chatSearchQuery.value = '';
    chatSearchResults.value = [];
    hasSearchedChat.value = false;
    showChatSearch.value = false;
};

// Lightbox
const openLightbox = (url, name) => {
    lightboxImage.value = { url, name };
};

const closeLightbox = () => {
    lightboxImage.value = null;
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
    if (contactSearchDebounceTimeout) clearTimeout(contactSearchDebounceTimeout);
    contactSearchDebounceTimeout = setTimeout(() => {
        fetchContacts(newChatSearch.value);
    }, 280);
};

watch(showNewChatModal, (isOpen) => {
    if (isOpen) {
        newChatSearch.value = '';
        fetchContacts('');
    }
});

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
        removePendingMessage(deletingMessageId.value);
        closeDeleteModal();
        return;
    }
    router.delete(route('chat.delete-message', deletingMessageId.value), {
        preserveScroll: true,
        onFinish: () => closeDeleteModal(),
    });
};

// Group Admin Management State & Actions
const showRenameGroupModal = ref(false);
const renameGroupTitle = ref('');
const isRenamingGroup = ref(false);

const showAddMembersModal = ref(false);
const addMembersSearch = ref('');
const addMembersSelectedIds = ref([]);
const addMembersSelectedUsers = ref([]);
const isAddingMembers = ref(false);

const memberToRemove = ref(null);
const isRemovingMember = ref(false);

const memberToPromote = ref(null);
const isPromotingMember = ref(false);

const showDeleteGroupModal = ref(false);
const isDeletingGroup = ref(false);

const addableContacts = computed(() => {
    if (!props.activeConversation?.participants) return contacts.value;
    const participantIds = new Set(props.activeConversation.participants.map(p => p.id));
    return contacts.value.filter(c => !participantIds.has(c.id));
});

const openRenameGroupModal = () => {
    renameGroupTitle.value = props.activeConversation?.title || '';
    showRenameGroupModal.value = true;
};

const confirmRenameGroup = () => {
    if (!renameGroupTitle.value.trim() || !props.activeConversation) return;
    isRenamingGroup.value = true;

    router.patch(route('chat.rename-group', props.activeConversation.id), {
        title: renameGroupTitle.value.trim(),
    }, {
        preserveScroll: true,
        onSuccess: () => {
            showRenameGroupModal.value = false;
            showToast('Group renamed successfully');
        },
        onError: (err) => {
            showToast(err.title || err.error || 'Failed to rename group');
        },
        onFinish: () => {
            isRenamingGroup.value = false;
        },
    });
};

const openAddMembersModal = () => {
    addMembersSearch.value = '';
    addMembersSelectedIds.value = [];
    addMembersSelectedUsers.value = [];
    showAddMembersModal.value = true;
    fetchContacts('');
};

const onAddMembersSearchInput = () => {
    if (contactSearchDebounceTimeout) clearTimeout(contactSearchDebounceTimeout);
    contactSearchDebounceTimeout = setTimeout(() => {
        fetchContacts(addMembersSearch.value);
    }, 280);
};

const toggleAddMemberSelection = (user) => {
    const idx = addMembersSelectedIds.value.indexOf(user.id);
    if (idx > -1) {
        addMembersSelectedIds.value.splice(idx, 1);
        const selIdx = addMembersSelectedUsers.value.findIndex(u => u.id === user.id);
        if (selIdx > -1) addMembersSelectedUsers.value.splice(selIdx, 1);
    } else {
        addMembersSelectedIds.value.push(user.id);
        addMembersSelectedUsers.value.push(user);
    }
};

const confirmAddMembers = () => {
    if (addMembersSelectedIds.value.length === 0 || !props.activeConversation) return;
    isAddingMembers.value = true;

    router.post(route('chat.add-members', props.activeConversation.id), {
        user_ids: addMembersSelectedIds.value,
    }, {
        preserveScroll: true,
        onSuccess: () => {
            showAddMembersModal.value = false;
            addMembersSelectedIds.value = [];
            addMembersSelectedUsers.value = [];
            showToast('Members added to group');
        },
        onError: (err) => {
            showToast(err.user_ids || err.error || 'Failed to add members');
        },
        onFinish: () => {
            isAddingMembers.value = false;
        },
    });
};

const promptRemoveMember = (participant) => {
    memberToRemove.value = participant;
};

const confirmRemoveMember = () => {
    if (!memberToRemove.value || !props.activeConversation) return;
    isRemovingMember.value = true;

    router.delete(route('chat.remove-member', [props.activeConversation.id, memberToRemove.value.id]), {
        preserveScroll: true,
        onSuccess: () => {
            showToast(`${memberToRemove.value.name} was removed from the group`);
            memberToRemove.value = null;
        },
        onError: (err) => {
            showToast(err.error || 'Failed to remove member');
        },
        onFinish: () => {
            isRemovingMember.value = false;
        },
    });
};

const promptPromoteMember = (participant) => {
    memberToPromote.value = participant;
};

const confirmPromoteMember = () => {
    if (!memberToPromote.value || !props.activeConversation) return;
    isPromotingMember.value = true;

    router.post(route('chat.promote-member', [props.activeConversation.id, memberToPromote.value.id]), {}, {
        preserveScroll: true,
        onSuccess: () => {
            showToast(`${memberToPromote.value.name} was promoted to group admin`);
            memberToPromote.value = null;
        },
        onError: (err) => {
            showToast(err.error || 'Failed to promote member');
        },
        onFinish: () => {
            isPromotingMember.value = false;
        },
    });
};

const promptDeleteGroup = () => {
    showDeleteGroupModal.value = true;
};

const confirmDeleteGroup = () => {
    if (!props.activeConversation) return;
    isDeletingGroup.value = true;

    router.delete(route('chat.delete-group', props.activeConversation.id), {
        onSuccess: () => {
            showDeleteGroupModal.value = false;
            showToast('Group deleted successfully');
        },
        onError: (err) => {
            showToast(err.error || 'Failed to delete group');
        },
        onFinish: () => {
            isDeletingGroup.value = false;
        },
    });
};

// Real-Time Echo Subscriptions
let subscribedConversationId = null;

const setupEchoListeners = () => {
    if (!window.Echo || !currentUser.value.id) return;

    window.Echo.private(`App.Models.User.${currentUser.value.id}`)
        .listen('.message.sent', handleUserChannelEvent);

    updateConversationChannelSubscription(props.activeConversation?.id);

    // Track connectivity if Reverb/Pusher is accessible
    const pusher = window.Echo.connector?.pusher;
    if (pusher && pusher.connection) {
        pusher.connection.bind('state_change', (states) => {
            if (states.current === 'connecting' || states.current === 'unavailable') {
                isReconnecting.value = true;
            } else if (states.current === 'connected') {
                isReconnecting.value = false;
            }
        });
    }
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

const reorderConversation = (conv) => {
    if (conv.is_pinned) {
        conversationsList.value.unshift(conv);
    } else {
        let lastPinnedIdx = -1;
        for (let i = conversationsList.value.length - 1; i >= 0; i--) {
            if (conversationsList.value[i].is_pinned) {
                lastPinnedIdx = i;
                break;
            }
        }
        if (lastPinnedIdx === -1) {
            conversationsList.value.unshift(conv);
        } else {
            conversationsList.value.splice(lastPinnedIdx + 1, 0, conv);
        }
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
                const mentionedIds = e.data?.mentioned_user_ids || e.data?.message?.mentioned_user_ids || [];
                if (Array.isArray(mentionedIds) && mentionedIds.includes(currentUser.value.id)) {
                    conv.has_unread_mentions = true;
                    conv.unread_mentions_count = (conv.unread_mentions_count || 0) + 1;
                }
            }
            conversationsList.value.splice(convIndex, 1);
            reorderConversation(conv);
        } else if (e.data?.conversation) {
            const newConv = { ...e.data.conversation };
            if (props.activeConversation?.id === e.conversation_id || !isFromOther) {
                newConv.unread_count = 0;
                newConv.has_unread_mentions = false;
                newConv.unread_mentions_count = 0;
            } else {
                const mentionedIds = e.data?.mentioned_user_ids || e.data?.message?.mentioned_user_ids || [];
                if (Array.isArray(mentionedIds) && mentionedIds.includes(currentUser.value.id)) {
                    newConv.has_unread_mentions = true;
                    newConv.unread_mentions_count = (newConv.unread_mentions_count || 0) + 1;
                }
            }
            reorderConversation(newConv);
        }
    } else if (e.action === 'group_created') {
        if (e.data?.conversation && !conversationsList.value.some(c => c.id === e.data.conversation.id)) {
            reorderConversation(e.data.conversation);
        }
    } else if (e.action === 'edited') {
        const conv = conversationsList.value.find(c => c.id === e.conversation_id);
        if (conv && conv.latest_message?.id === e.data?.message_id) {
            conv.latest_message.body = e.data?.body ?? conv.latest_message.body;
        }
        if (conv && props.activeConversation?.id !== e.conversation_id) {
            const mentionedIds = e.data?.mentioned_user_ids || [];
            const prevMentionedIds = e.data?.previous_mentioned_user_ids || [];
            if (Array.isArray(mentionedIds)) {
                if (mentionedIds.includes(currentUser.value.id)) {
                    conv.has_unread_mentions = true;
                    if (!conv.unread_mentions_count) {
                        conv.unread_mentions_count = 1;
                    }
                } else if (prevMentionedIds.includes(currentUser.value.id)) {
                    conv.has_unread_mentions = false;
                    conv.unread_mentions_count = Math.max(0, (conv.unread_mentions_count || 1) - 1);
                }
            }
        }
    } else if (e.action === 'deleted') {
        const conv = conversationsList.value.find(c => c.id === e.conversation_id);
        if (conv && conv.latest_message?.id === e.data?.message_id) {
            conv.latest_message.body = 'This message was deleted';
            conv.latest_message.is_deleted = true;
        }
    } else if (e.action === 'group_renamed') {
        const conv = conversationsList.value.find(c => c.id === e.conversation_id);
        if (conv && e.data?.title) {
            conv.title = e.data.title;
        }
    } else if (e.action === 'members_added') {
        const isCurrentAdded = Array.isArray(e.data?.participants) && e.data.participants.some(p => (p.id || p) === currentUser.value.id);
        if (isCurrentAdded && e.data?.conversation && !conversationsList.value.some(c => c.id === e.data.conversation.id)) {
            reorderConversation(e.data.conversation);
        }
    } else if (e.action === 'group_deleted') {
        conversationsList.value = conversationsList.value.filter(c => c.id !== e.conversation_id);
        if (props.activeConversation?.id === e.conversation_id) {
            showToast('This group chat was deleted');
            router.visit(route('chat.index'));
        }
    } else if (e.action === 'member_removed' && e.data?.user_id === currentUser.value.id) {
        conversationsList.value = conversationsList.value.filter(c => c.id !== e.conversation_id);
        if (props.activeConversation?.id === e.conversation_id) {
            showToast('You were removed from this group');
            router.visit(route('chat.index'));
        }
    }
};

const handleConversationChannelEvent = (e) => {
    if (!props.activeConversation || props.activeConversation.id !== e.conversation_id) return;

    if (e.action === 'created') {
        const msg = e.data?.message;
        if (!msg) return;

        const isMe = msg.sender_id === currentUser.value.id;

        if (isMe) {
            const pendingIndex = pendingMessages.value.findIndex(
                p => p.conversation_id === e.conversation_id && (p.body === msg.body || p.status === 'sending' || p.status === 'sent')
            );
            if (pendingIndex !== -1) {
                pendingMessages.value.splice(pendingIndex, 1);
            }
        }

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
                axios.post(route('chat.mark-read', e.conversation_id)).catch(() => {});
            }
        }
    } else if (e.action === 'edited') {
        const messageId = e.data?.message_id;
        const targetMsg = activeMessages.value.find(m => m.id === messageId);
        if (targetMsg) {
            targetMsg.body = e.data?.body ?? targetMsg.body;
            targetMsg.is_edited = true;
            targetMsg.edited_at = e.data?.edited_at_relative ?? 'just now';
            if (e.data?.mentioned_user_ids) {
                targetMsg.mentioned_user_ids = e.data.mentioned_user_ids;
            }
        }
    } else if (e.action === 'typing') {
        const userId = e.data?.user_id;
        const userName = e.data?.user_name;
        if (userId && userId !== currentUser.value.id) {
            if (typingUsers.value[userId]?.timeout) {
                clearTimeout(typingUsers.value[userId].timeout);
            }
            const timeout = setTimeout(() => {
                delete typingUsers.value[userId];
            }, 3500);
            typingUsers.value[userId] = { name: userName, timeout };
        }
    } else if (e.action === 'read') {
        if (e.data?.user_id && e.data.user_id === currentUser.value.id) {
            return;
        }
        const readAt = e.data?.read_at ? new Date(e.data.read_at).getTime() : Date.now();
        activeMessages.value.forEach(m => {
            if (m.is_sender) {
                const msgTime = new Date(m.raw_created_at || m.created_at).getTime();
                if (!isNaN(msgTime) && msgTime <= readAt) {
                    m.is_seen = true;
                    m.status = 2; // STATUS_READ
                }
            }
        });
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
    } else if (e.action === 'group_renamed') {
        if (props.activeConversation && props.activeConversation.id === e.conversation_id && e.data?.title) {
            props.activeConversation.title = e.data.title;
        }
        const conv = conversationsList.value.find(c => c.id === e.conversation_id);
        if (conv && e.data?.title) {
            conv.title = e.data.title;
        }
    } else if (e.action === 'members_added') {
        if (props.activeConversation && props.activeConversation.id === e.conversation_id && Array.isArray(e.data?.participants)) {
            if (!props.activeConversation.participants) props.activeConversation.participants = [];
            e.data.participants.forEach(p => {
                if (!props.activeConversation.participants.some(existing => existing.id === p.id)) {
                    props.activeConversation.participants.push(p);
                }
            });
        }
    } else if (e.action === 'member_removed') {
        if (props.activeConversation && props.activeConversation.id === e.conversation_id) {
            if (e.data?.user_id === currentUser.value.id) {
                showToast('You were removed from this group');
                router.visit(route('chat.index'));
                return;
            }
            if (props.activeConversation.participants) {
                props.activeConversation.participants = props.activeConversation.participants.filter(p => p.id !== e.data.user_id);
            }
        }
    } else if (e.action === 'member_promoted') {
        if (props.activeConversation && props.activeConversation.id === e.conversation_id) {
            if (props.activeConversation.participants) {
                const p = props.activeConversation.participants.find(p => p.id === e.data.user_id);
                if (p) {
                    p.role = 'admin';
                    p.is_admin = true;
                }
            }
            if (e.data?.user_id === currentUser.value.id) {
                props.activeConversation.is_admin = true;
                showToast('You were promoted to group admin');
            }
        }
    } else if (e.action === 'group_deleted') {
        conversationsList.value = conversationsList.value.filter(c => c.id !== e.conversation_id);
        if (props.activeConversation && props.activeConversation.id === e.conversation_id) {
            showToast('This group chat was deleted');
            router.visit(route('chat.index'));
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
        activeConversationMenuId.value = null;
        replyingToMessage.value = null;
        cancelEditMessage();
        clearSelectedFile();
        clearChatSearch();
        typingUsers.value = {};
        updateConversationChannelSubscription(newId);

        // Restore draft for this conversation
        messageForm.body = loadDraft(newId);

        scrollToBottom(false);
        nextTick(() => {
            resizeComposer();
            focusComposer();
        });
    }
});

// Merge props.activeConversation.messages updates by ID
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

// Close open menus and modals on Escape
const handleGlobalKeydown = (e) => {
    if (e.key === 'Escape') {
        activeMessageMenuId.value = null;
        activeConversationMenuId.value = null;
        showEmojiPicker.value = false;
        if (lightboxImage.value) {
            closeLightbox();
        }
        if (editingMessage.value) {
            cancelEditMessage();
        }
        if (replyingToMessage.value) {
            cancelReply();
        }
    }
};

const handleOnline = () => { isOnline.value = true; isReconnecting.value = false; };
const handleOffline = () => { isOnline.value = false; isReconnecting.value = true; };

onMounted(() => {
    scrollToBottom(false);
    setupEchoListeners();
    if (props.activeConversation?.id) {
        messageForm.body = loadDraft(props.activeConversation.id);
    }
    nextTick(() => {
        resizeComposer();
        focusComposer();
    });
    window.addEventListener('keydown', handleGlobalKeydown);
    window.addEventListener('online', handleOnline);
    window.addEventListener('offline', handleOffline);
});

onBeforeUnmount(() => {
    window.removeEventListener('keydown', handleGlobalKeydown);
    window.removeEventListener('online', handleOnline);
    window.removeEventListener('offline', handleOffline);
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
    if (contactSearchDebounceTimeout) clearTimeout(contactSearchDebounceTimeout);
    if (chatSearchTimeout) clearTimeout(chatSearchTimeout);
    if (toastTimeout) clearTimeout(toastTimeout);
    Object.values(typingUsers.value).forEach(u => clearTimeout(u.timeout));
});
</script>

<template>
    <AppLayout title="Messenger">
        <!-- Toast Notification Alert -->
        <div 
            v-if="toastMessage" 
            role="status" 
            aria-live="polite" 
            class="fixed top-5 right-5 z-50 bg-forest-900 text-white px-4 py-2 rounded-xl shadow-lg text-xs font-semibold flex items-center space-x-2 border border-golden-400 animate-fadeIn"
        >
            <svg class="size-4 text-golden-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            <span>{{ toastMessage }}</span>
        </div>

        <div class="flex-1 flex flex-col min-h-0 p-2 sm:p-3 bg-cream-300">
            <div class="max-w-8xl w-full mx-auto flex-1 flex flex-col min-h-0">
                <!-- Messenger Container -->
                <div class="bg-cream-200 rounded-2xl shadow-sm border border-cream-500/60 overflow-hidden flex flex-1 min-h-0">
                    
                    <!-- Left Sidebar (Conversations List) -->
                    <div 
                        class="w-full md:w-80 lg:w-96 flex-shrink-0 border-r border-cream-500/60 flex flex-col bg-cream-200 min-h-0 h-full"
                        :class="{'hidden md:flex': activeConversation, 'flex': !activeConversation}"
                    >
                        <!-- Sidebar Header, Tabs & Search -->
                        <div class="p-3 border-b border-cream-500/40 bg-cream-200 space-y-2.5">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center space-x-2">
                                    <h3 class="text-lg font-bold text-gray-900 tracking-tight">Chats</h3>
                                    <span class="text-xs font-semibold px-2 py-0.5 bg-lime-200 text-forest-900 rounded-full">
                                        {{ conversationsList.length }}
                                    </span>
                                </div>
                                <div class="flex items-center space-x-1.5">
                                    <!-- Mark All As Read Button -->
                                    <button
                                        type="button"
                                        @click="markAllConversationsAsRead"
                                        :disabled="unreadConversationsCount === 0"
                                        class="p-1.5 text-forest-900 hover:text-forest-700 hover:bg-cream-300/60 rounded-xl transition disabled:opacity-40 cursor-pointer"
                                        title="Mark all as read"
                                        aria-label="Mark all conversations as read"
                                    >
                                        <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                                        </svg>
                                    </button>
                                    <!-- New Chat Button -->
                                    <button
                                        @click="showNewChatModal = true"
                                        class="inline-flex items-center px-3 py-1.5 bg-forest-500 hover:bg-forest-600 active:bg-forest-700 text-white text-xs font-semibold rounded-xl shadow-xs transition cursor-pointer"
                                        aria-label="Start new conversation"
                                    >
                                        <svg class="size-3.5 me-1" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                                        </svg>
                                        New Chat
                                    </button>
                                </div>
                            </div>

                            <!-- Filter Tabs: All vs Unread -->
                            <div class="flex p-0.5 bg-cream-300/80 rounded-xl space-x-1 border border-cream-500/40">
                                <button
                                    type="button"
                                    @click="sidebarFilter = 'all'"
                                    class="flex-1 py-1 text-xs font-semibold rounded-lg transition cursor-pointer"
                                    :class="sidebarFilter === 'all' ? 'bg-cream-100 text-forest-900 font-bold shadow-xs' : 'text-gray-600 hover:text-gray-900'"
                                >
                                    All Chats
                                </button>
                                <button
                                    type="button"
                                    @click="sidebarFilter = 'unread'"
                                    class="flex-1 py-1 text-xs font-semibold rounded-lg transition flex items-center justify-center space-x-1 cursor-pointer"
                                    :class="sidebarFilter === 'unread' ? 'bg-cream-100 text-forest-900 font-bold shadow-xs' : 'text-gray-600 hover:text-gray-900'"
                                >
                                    <span>Unread</span>
                                    <span v-if="unreadConversationsCount > 0" class="px-1.5 py-0.2 bg-forest-600 text-white text-[10px] rounded-full font-bold">
                                        {{ unreadConversationsCount }}
                                    </span>
                                </button>
                            </div>

                            <!-- Search Input -->
                            <div class="relative">
                                <input
                                    v-model="searchQuery"
                                    type="text"
                                    placeholder="Search conversations..."
                                    class="w-full pl-9 pr-4 py-1.5 bg-[#fffef7] text-gray-900 text-sm border border-cream-500/60 focus:border-forest-600 focus:ring-2 focus:ring-forest-200 rounded-xl transition placeholder:text-gray-400"
                                    aria-label="Search conversations"
                                />
                                <svg class="size-4 text-gray-400 absolute left-3 top-2.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
                                </svg>
                            </div>
                        </div>

                        <!-- Conversation List Items -->
                        <div class="flex-1 overflow-y-auto divide-y divide-cream-500/20 p-2 space-y-1">
                            <template v-if="filteredConversations.length > 0">
                                <div
                                    v-for="conv in filteredConversations"
                                    :key="conv.id"
                                    class="rounded-xl transition duration-150 group relative"
                                    :class="activeConversation?.id === conv.id ? 'bg-[#ede1b8] shadow-xs border border-cream-500/50' : 'hover:bg-cream-300/60'"
                                >
                                    <Link
                                        :href="route('chat.index', { conversation: conv.id })"
                                        class="flex items-center p-3"
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
                                                    <!-- Pin Badge -->
                                                    <span v-if="conv.is_pinned" class="text-golden-600 shrink-0" title="Pinned conversation">📌</span>
                                                    <span>{{ (conv.is_system || conv.type === 'system') ? 'System Notifications' : conv.title }}</span>
                                                    <span v-if="conv.type === 'group'" class="text-[10px] font-semibold px-1.5 py-0.5 bg-lime-200 text-forest-900 rounded-md shrink-0">Group</span>
                                                    <!-- Muted Badge -->
                                                    <span v-if="conv.is_muted" class="text-gray-400 shrink-0" title="Notifications muted">🔕</span>
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

                                                <!-- Badges Container -->
                                                <div class="flex items-center gap-1.5 shrink-0 ms-2">
                                                    <!-- @ Mention Indicator -->
                                                    <span
                                                        v-if="conv.has_unread_mentions || (conv.unread_mentions_count || 0) > 0"
                                                        class="size-5 flex items-center justify-center text-[11px] font-black bg-amber-500 text-white rounded-full shadow-xs ring-1 ring-white/50 shrink-0"
                                                        title="You were mentioned in this conversation"
                                                    >
                                                        @
                                                    </span>

                                                    <!-- Unread Count Badge -->
                                                    <span
                                                        v-if="conv.unread_count > 0"
                                                        class="px-2 py-0.5 text-[11px] font-extrabold bg-forest-600 text-white rounded-full shrink-0 shadow-xs animate-bounce"
                                                    >
                                                        {{ conv.unread_count }}
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                    </Link>

                                    <!-- Quick Conversation Hover / Options Menu -->
                                    <div
                                        class="absolute right-2 top-2 opacity-100 md:opacity-0 md:group-hover:opacity-100 focus-within:opacity-100 transition z-10"
                                        :class="{ '!opacity-100': activeConversationMenuId === conv.id }"
                                    >
                                        <button
                                            type="button"
                                            @click.stop="activeConversationMenuId = activeConversationMenuId === conv.id ? null : conv.id"
                                            class="p-1 text-gray-500 hover:text-forest-900 bg-cream-100 rounded-lg shadow-xs border border-cream-400"
                                            :aria-label="`Options for ${conv.title}`"
                                        >
                                            <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 6.75a.75.75 0 110-1.5.75.75 0 010 1.5zM12 12.75a.75.75 0 110-1.5.75.75 0 010 1.5zM12 18.75a.75.75 0 110-1.5.75.75 0 010 1.5z" />
                                            </svg>
                                        </button>

                                        <div
                                            v-if="activeConversationMenuId === conv.id"
                                            class="absolute right-0 top-7 w-36 bg-cream-100 border border-cream-500/60 shadow-lg rounded-xl py-1 z-30 text-xs"
                                        >
                                            <button
                                                type="button"
                                                @click="togglePinConversation(conv)"
                                                class="w-full text-left px-3 py-1.5 hover:bg-cream-300 text-gray-800 flex items-center space-x-1.5"
                                            >
                                                <span>{{ conv.is_pinned ? 'Unpin' : 'Pin' }}</span>
                                            </button>
                                            <button
                                                type="button"
                                                @click="toggleMuteConversation(conv)"
                                                class="w-full text-left px-3 py-1.5 hover:bg-cream-300 text-gray-800 flex items-center space-x-1.5"
                                            >
                                                <span>{{ conv.is_muted ? 'Unmute' : 'Mute' }}</span>
                                            </button>
                                            <button
                                                v-if="conv.unread_count > 0"
                                                type="button"
                                                @click="markConversationAsRead(conv)"
                                                class="w-full text-left px-3 py-1.5 hover:bg-cream-300 text-gray-800 flex items-center space-x-1.5"
                                            >
                                                <span>Mark as read</span>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </template>

                            <div v-else class="p-8 text-center text-gray-500">
                                <p class="text-sm font-medium">No conversations found</p>
                                <button
                                    v-if="sidebarFilter === 'unread'"
                                    @click="sidebarFilter = 'all'"
                                    class="mt-2 text-xs text-forest-800 font-semibold cursor-pointer underline"
                                >
                                    Show all chats
                                </button>
                                <button
                                    v-else
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
                            <p class="text-xs text-golden-200 mt-1 font-medium">Upload photo or document up to 10 MB</p>
                        </div>

                        <template v-if="activeConversation">
                            <!-- Active Chat Header -->
                            <div class="h-16 px-4 sm:px-6 border-b border-cream-500/40 flex items-center justify-between bg-cream-200/90 z-10 shrink-0">
                                <div class="flex items-center space-x-3 truncate me-2">
                                    <!-- Mobile Back Button -->
                                    <Link
                                        :href="route('chat.index')"
                                        class="md:hidden p-1.5 -ml-1 text-forest-900 hover:bg-cream-300 rounded-lg transition"
                                        aria-label="Back to conversations list"
                                    >
                                        <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5" />
                                        </svg>
                                    </Link>

                                    <!-- User Avatar & Status -->
                                    <div class="relative shrink-0">
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

                                    <div class="truncate">
                                        <h3 class="font-bold text-gray-900 text-sm md:text-base leading-tight truncate flex items-center space-x-1.5">
                                            <span>{{ (activeConversation.is_system || activeConversation.type === 'system') ? 'System Notifications' : activeConversation.title }}</span>
                                            <span v-if="activeConversation.is_pinned" class="text-xs" title="Pinned">📌</span>
                                            <span v-if="activeConversation.is_muted" class="text-xs" title="Muted">🔕</span>
                                        </h3>
                                        <p class="text-xs text-gray-500 flex items-center truncate">
                                            <span v-if="!isOnline || isReconnecting" class="text-amber-700 font-semibold inline-flex items-center space-x-1">
                                                <span class="size-2 rounded-full bg-amber-500 animate-pulse"></span>
                                                <span>Reconnecting...</span>
                                            </span>
                                            <span v-else-if="activeTypingText" class="text-forest-800 font-semibold animate-pulse">
                                                {{ activeTypingText }}
                                            </span>
                                            <span v-else-if="activeConversation.is_system || activeConversation.type === 'system'" class="text-amber-700 font-semibold">
                                                Official System Notifications
                                            </span>
                                            <span v-else-if="activeConversation.type === 'group'" class="text-forest-700 font-medium truncate">
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

                                <!-- Header Action Icons -->
                                <div class="flex items-center space-x-1 text-forest-900 shrink-0">
                                    <!-- Search In Conversation Button -->
                                    <button
                                        @click="showChatSearch = !showChatSearch"
                                        class="p-2 text-forest-900 hover:text-forest-700 hover:bg-cream-400/40 rounded-xl transition cursor-pointer"
                                        title="Search in conversation"
                                        aria-label="Search messages in this conversation"
                                    >
                                        <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
                                        </svg>
                                    </button>

                                    <!-- Pin Conversation Toggle -->
                                    <button
                                        @click="togglePinConversation(activeConversation)"
                                        class="p-2 text-forest-900 hover:text-forest-700 hover:bg-cream-400/40 rounded-xl transition cursor-pointer"
                                        :title="activeConversation.is_pinned ? 'Unpin chat' : 'Pin chat'"
                                        :aria-label="activeConversation.is_pinned ? 'Unpin conversation' : 'Pin conversation'"
                                    >
                                        <span class="text-base leading-none">{{ activeConversation.is_pinned ? '📌' : '📍' }}</span>
                                    </button>

                                    <!-- Conversation Info / Settings Button -->
                                    <button
                                        @click="showInfoPanel = !showInfoPanel"
                                        class="p-2 text-forest-900 hover:text-forest-700 hover:bg-cream-400/40 rounded-xl transition cursor-pointer"
                                        title="Conversation Info"
                                        aria-label="View user profile and conversation information"
                                    >
                                        <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zM12 8.25h.008v.008H12V8.25z" />
                                        </svg>
                                    </button>
                                </div>
                            </div>

                            <!-- Expandable In-Conversation Search Bar -->
                            <div v-if="showChatSearch" class="p-3 bg-cream-200 border-b border-cream-500/50 shadow-inner space-y-2 animate-fadeIn">
                                <div class="flex items-center space-x-2">
                                    <div class="relative flex-1">
                                        <input
                                            v-model="chatSearchQuery"
                                            @input="onChatSearchInput"
                                            type="text"
                                            placeholder="Search message text in this chat..."
                                            class="w-full pl-9 pr-8 py-1.5 bg-[#fffef7] text-gray-900 text-xs border border-cream-500/60 focus:border-forest-600 focus:ring-1 focus:ring-forest-200 rounded-xl transition placeholder:text-gray-400"
                                            autofocus
                                            aria-label="Search messages in active conversation"
                                        />
                                        <svg class="size-4 text-gray-400 absolute left-3 top-2" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
                                        </svg>
                                        <button
                                            v-if="chatSearchQuery"
                                            @click="chatSearchQuery = ''; chatSearchResults = []; hasSearchedChat = false;"
                                            class="absolute right-2.5 top-2 text-gray-400 hover:text-gray-600"
                                            aria-label="Clear search query"
                                        >
                                            <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                            </svg>
                                        </button>
                                    </div>
                                    <button
                                        @click="clearChatSearch"
                                        class="px-2.5 py-1 text-xs font-semibold text-gray-600 hover:text-gray-900 rounded-lg hover:bg-cream-300 transition"
                                        aria-label="Close search"
                                    >
                                        Close
                                    </button>
                                </div>

                                <!-- Search Results Dropdown List -->
                                <div v-if="isSearchingChat" class="text-xs text-gray-500 py-2 text-center">
                                    Searching messages...
                                </div>
                                <div v-else-if="hasSearchedChat" class="max-h-48 overflow-y-auto divide-y divide-cream-500/30 bg-cream-100 rounded-xl border border-cream-500/40">
                                    <div class="p-2 text-[11px] font-bold text-gray-600 bg-cream-200/60 flex justify-between">
                                        <span>{{ chatSearchResults.length }} result(s) found</span>
                                    </div>
                                    <template v-if="chatSearchResults.length > 0">
                                        <button
                                            v-for="res in chatSearchResults"
                                            :key="res.id"
                                            @click="scrollToMessage(res.id)"
                                            class="w-full p-2.5 text-left hover:bg-cream-200/80 transition flex flex-col space-y-0.5 cursor-pointer"
                                        >
                                            <div class="flex justify-between items-center text-[10px] text-gray-500">
                                                <span class="font-bold text-gray-800">{{ res.sender_name }}</span>
                                                <span>{{ res.created_at_relative }}</span>
                                            </div>
                                            <p class="text-xs text-gray-900 line-clamp-2">{{ res.body }}</p>
                                        </button>
                                    </template>
                                    <div v-else class="p-4 text-center text-xs text-gray-500">
                                        No messages match "{{ chatSearchQuery }}"
                                    </div>
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
                                        class="flex-1 overflow-y-auto p-4 sm:p-6 space-y-4 bg-cream-300"
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
                                                :id="`message-${message.id}`"
                                                :key="message.id"
                                                class="flex flex-col transition-colors duration-500 rounded-xl p-1"
                                                :class="[
                                                    message.is_sender ? 'items-end' : 'items-start',
                                                    highlightedMessageId === message.id ? 'bg-golden-300/60 ring-2 ring-golden-500' : ''
                                                ]"
                                            >
                                                <!-- Sender Name in group/chat if not sender -->
                                                <span 
                                                    v-if="!message.is_sender"
                                                    class="text-[11px] font-semibold text-gray-700 mb-1 block pl-2"
                                                >
                                                    {{ message.sender.name }}
                                                </span>

                                                <!-- Message Bubble Row -->
                                                <div class="group flex items-center space-x-2 max-w-[92%] sm:max-w-[85%] md:max-w-[75%]">
                                                    
                                                    <!-- Actions Menu Toggle (Left of sender's bubble) -->
                                                    <div
                                                        v-if="message.is_sender && !message.is_deleted && message.status !== 'sending' && message.status !== 'failed'"
                                                        class="relative shrink-0 z-10"
                                                    >
                                                        <button
                                                            type="button"
                                                            @click.stop="activeMessageMenuId = activeMessageMenuId === message.id ? null : message.id"
                                                            class="p-1.5 rounded-full text-forest-900/60 hover:text-forest-900 hover:bg-cream-400/50 transition opacity-80 md:opacity-0 md:group-hover:opacity-100 focus:opacity-100"
                                                            aria-label="Message options"
                                                        >
                                                            <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 6.75a.75.75 0 110-1.5.75.75 0 010 1.5zM12 12.75a.75.75 0 110-1.5.75.75 0 010 1.5zM12 18.75a.75.75 0 110-1.5.75.75 0 010 1.5z" />
                                                            </svg>
                                                        </button>

                                                        <!-- Action Dropdown for Sender -->
                                                        <div
                                                            v-if="activeMessageMenuId === message.id"
                                                            class="absolute right-0 bottom-7 bg-cream-100 shadow-xl border border-cream-500/50 rounded-xl py-1.5 w-36 z-30 text-xs"
                                                        >
                                                            <!-- Quick Reactions Row -->
                                                            <div class="flex items-center justify-around px-1.5 py-1 border-b border-cream-400/40 mb-1">
                                                                <button
                                                                    v-for="(emoji, reactionKey) in reactionMap"
                                                                    :key="reactionKey"
                                                                    type="button"
                                                                    @click="toggleReaction(message.id, reactionKey)"
                                                                    class="hover:scale-125 transition-transform text-sm cursor-pointer p-0.5"
                                                                    :title="reactionKey"
                                                                >
                                                                    {{ emoji }}
                                                                </button>
                                                            </div>
                                                            <button
                                                                type="button"
                                                                @click="startReply(message)"
                                                                class="w-full text-left px-3 py-1 hover:bg-cream-300 text-gray-800 flex items-center space-x-1.5"
                                                            >
                                                                <span>↩ Reply</span>
                                                            </button>
                                                            <button
                                                                v-if="canEditMessage(message)"
                                                                type="button"
                                                                @click="startEditMessage(message)"
                                                                class="w-full text-left px-3 py-1 hover:bg-cream-300 text-gray-800 flex items-center space-x-1.5"
                                                            >
                                                                <span>✏ Edit</span>
                                                            </button>
                                                            <button
                                                                type="button"
                                                                @click="copyMessageText(message)"
                                                                class="w-full text-left px-3 py-1 hover:bg-cream-300 text-gray-800 flex items-center space-x-1.5"
                                                            >
                                                                <span>📋 Copy text</span>
                                                            </button>
                                                            <button
                                                                type="button"
                                                                @click="promptDeleteMessage(message.id)"
                                                                class="w-full text-left px-3 py-1 hover:bg-red-100 text-red-600 flex items-center space-x-1.5"
                                                            >
                                                                <span>🗑 Delete</span>
                                                            </button>
                                                        </div>
                                                    </div>

                                                    <!-- Message Content Container (Bubble & Reaction Badges) -->
                                                    <div class="flex flex-col" :class="message.is_sender ? 'items-end' : 'items-start'">
                                                        <!-- Quoted Reply Box (if message is a reply) -->
                                                        <div
                                                            v-if="message.reply_to"
                                                            @click="scrollToMessage(message.reply_to.id)"
                                                            class="mb-1 px-3 py-1.5 rounded-lg text-xs border border-cream-500/40 bg-cream-100/90 hover:bg-cream-200/90 transition cursor-pointer max-w-full truncate shadow-2xs"
                                                            :class="message.is_sender ? 'border-r-4 border-r-golden-600' : 'border-l-4 border-l-forest-600'"
                                                        >
                                                            <div class="font-bold text-[10px] text-forest-900 truncate">
                                                                {{ message.reply_to.sender_name }}
                                                            </div>
                                                            <div class="text-gray-700 truncate italic text-[11px]">
                                                                {{ message.reply_to.body || 'Attachment' }}
                                                            </div>
                                                        </div>

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
                                                            <!-- Image Attachment with Inline Lightbox trigger -->
                                                            <div v-if="message.attachment_url && message.type === 'image'" class="mb-2 overflow-hidden rounded-xl">
                                                                <button
                                                                    type="button"
                                                                    @click="openLightbox(message.attachment_url, message.attachment_name || 'Photo')"
                                                                    class="block cursor-pointer focus:outline-hidden"
                                                                    aria-label="View photo in lightbox"
                                                                >
                                                                    <img 
                                                                        :src="message.attachment_url" 
                                                                        :alt="message.attachment_name || 'Attached photo'"
                                                                        class="max-h-64 rounded-xl object-cover hover:opacity-95 transition"
                                                                    />
                                                                </button>
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

                                                    <!-- Actions Menu Toggle (Right of receiver's bubble) -->
                                                    <div
                                                        v-if="!message.is_sender && !message.is_deleted"
                                                        class="relative shrink-0 z-10"
                                                    >
                                                        <button
                                                            type="button"
                                                            @click.stop="activeMessageMenuId = activeMessageMenuId === message.id ? null : message.id"
                                                            class="p-1.5 rounded-full text-forest-900/60 hover:text-forest-900 hover:bg-cream-400/50 transition opacity-80 md:opacity-0 md:group-hover:opacity-100 focus:opacity-100"
                                                            aria-label="Message options"
                                                        >
                                                            <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 6.75a.75.75 0 110-1.5.75.75 0 010 1.5zM12 12.75a.75.75 0 110-1.5.75.75 0 010 1.5zM12 18.75a.75.75 0 110-1.5.75.75 0 010 1.5z" />
                                                            </svg>
                                                        </button>

                                                        <!-- Action Dropdown for Receiver -->
                                                        <div
                                                            v-if="activeMessageMenuId === message.id"
                                                            class="absolute left-0 bottom-7 bg-cream-100 shadow-xl border border-cream-500/50 rounded-xl py-1.5 w-36 z-30 text-xs"
                                                        >
                                                            <!-- Quick Reactions Row -->
                                                            <div class="flex items-center justify-around px-1.5 py-1 border-b border-cream-400/40 mb-1">
                                                                <button
                                                                    v-for="(emoji, reactionKey) in reactionMap"
                                                                    :key="reactionKey"
                                                                    type="button"
                                                                    @click="toggleReaction(message.id, reactionKey)"
                                                                    class="hover:scale-125 transition-transform text-sm cursor-pointer p-0.5"
                                                                    :title="reactionKey"
                                                                >
                                                                    {{ emoji }}
                                                                </button>
                                                            </div>
                                                            <button
                                                                type="button"
                                                                @click="startReply(message)"
                                                                class="w-full text-left px-3 py-1 hover:bg-cream-300 text-gray-800 flex items-center space-x-1.5"
                                                            >
                                                                <span>↩ Reply</span>
                                                            </button>
                                                            <button
                                                                type="button"
                                                                @click="copyMessageText(message)"
                                                                class="w-full text-left px-3 py-1 hover:bg-cream-300 text-gray-800 flex items-center space-x-1.5"
                                                            >
                                                                <span>📋 Copy text</span>
                                                            </button>
                                                        </div>
                                                    </div>
                                                </div>

                                                <!-- Message Timestamp & Read Status (Under Bubble) -->
                                                <div 
                                                    class="text-[10px] text-gray-500 mt-1 px-1 flex items-center space-x-1.5"
                                                    :class="message.is_sender ? 'justify-end' : 'justify-start'"
                                                >
                                                    <span>{{ message.created_at }}</span>
                                                    <!-- Edited indicator -->
                                                    <span v-if="message.is_edited" class="text-gray-400 italic font-medium">(edited)</span>
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
                                                        <!-- Failed Status with Retry and Remove -->
                                                        <span v-else-if="message.status === 'failed'" class="text-red-600 font-semibold inline-flex items-center space-x-1.5">
                                                            <span>Failed</span>
                                                            <button
                                                                type="button"
                                                                @click="resendMessage(message)"
                                                                class="text-forest-800 hover:underline font-bold"
                                                                aria-label="Retry sending message"
                                                            >
                                                                Retry
                                                            </button>
                                                            <span>&bull;</span>
                                                            <button
                                                                type="button"
                                                                @click="removePendingMessage(message.id)"
                                                                class="text-red-700 hover:underline"
                                                                aria-label="Remove message"
                                                            >
                                                                Remove
                                                            </button>
                                                        </span>
                                                        <!-- Seen Status -->
                                                        <span v-else-if="message.is_seen" class="text-forest-700 font-bold inline-flex items-center space-x-0.5" title="Seen by recipient">
                                                            <span>Seen</span>
                                                            <span class="text-xs">✓✓</span>
                                                        </span>
                                                        <!-- Delivered Status -->
                                                        <span v-else-if="message.status === 1 || message.status === 'delivered'" class="text-gray-500 font-medium inline-flex items-center space-x-0.5" title="Delivered">
                                                            <span>Delivered</span>
                                                            <span class="text-xs">✓✓</span>
                                                        </span>
                                                        <!-- Sent Status -->
                                                        <span v-else class="text-gray-500 font-medium inline-flex items-center space-x-0.5" title="Sent">
                                                            <span>Sent</span>
                                                            <span class="text-xs">✓</span>
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

                                    <!-- Input Bar Container -->
                                    <div
                                        v-if="activeConversation.is_system || activeConversation.type === 'system'"
                                        class="p-4 bg-golden-100/60 border-t border-cream-500/40 flex items-center justify-center space-x-2 text-golden-800 text-xs font-semibold"
                                    >
                                        <svg class="size-4 text-golden-700 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />
                                        </svg>
                                        <span>System Notifications are read-only and cannot be replied to.</span>
                                    </div>

                                    <div v-else class="p-3 sm:p-4 border-t border-cream-500/40 bg-cream-300 relative">
                                        <!-- Replying-to Preview Bar -->
                                        <div v-if="replyingToMessage" class="mb-2 p-2 bg-cream-100 rounded-xl border border-cream-500/60 flex items-center justify-between text-xs animate-fadeIn">
                                            <div class="flex items-center space-x-2 truncate">
                                                <span class="text-forest-800 font-bold">Replying to {{ replyingToMessage.sender_name }}:</span>
                                                <span class="text-gray-600 truncate italic">{{ replyingToMessage.body }}</span>
                                            </div>
                                            <button type="button" @click="cancelReply" class="text-gray-400 hover:text-gray-700 p-1 cursor-pointer" aria-label="Cancel reply">
                                                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                                </svg>
                                            </button>
                                        </div>

                                        <!-- Editing-message Banner -->
                                        <div v-if="editingMessage" class="mb-2 p-2 bg-golden-100 rounded-xl border border-golden-400/60 flex items-center justify-between text-xs animate-fadeIn">
                                            <div class="flex items-center space-x-1.5 text-golden-900 font-semibold truncate">
                                                <span>✏ Editing message</span>
                                                <span class="text-gray-500 font-normal truncate">(Press Enter to save, Esc to cancel)</span>
                                            </div>
                                            <button type="button" @click="cancelEditMessage" class="text-golden-800 hover:text-gray-900 p-1 cursor-pointer" aria-label="Cancel editing">
                                                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                                </svg>
                                            </button>
                                        </div>

                                        <!-- File Upload Progress Bar -->
                                        <div v-if="uploadProgress !== null" class="mb-2 p-2 bg-cream-100 rounded-xl border border-cream-500/60 flex items-center justify-between space-x-3 text-xs">
                                            <div class="flex-1 flex items-center space-x-2">
                                                <span class="font-bold text-forest-900">Uploading: {{ uploadProgress }}%</span>
                                                <div class="flex-1 bg-cream-300 rounded-full h-1.5 overflow-hidden">
                                                    <div class="bg-forest-600 h-full transition-all duration-150" :style="{ width: `${uploadProgress}%` }"></div>
                                                </div>
                                            </div>
                                            <button type="button" @click="cancelUpload" class="text-red-600 hover:text-red-800 font-bold" aria-label="Cancel upload">
                                                Cancel
                                            </button>
                                        </div>

                                        <!-- File Validation Error Banner -->
                                        <div v-if="fileValidationError" class="mb-2 p-2.5 bg-red-50 border border-red-200 text-red-700 text-xs rounded-xl flex items-center justify-between">
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
                                        <div v-if="selectedFile" class="mb-2 p-2.5 bg-cream-200 rounded-xl flex items-center justify-between border border-cream-500/60">
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
                                            class="absolute bottom-20 left-4 bg-cream-200 border border-cream-500/60 shadow-xl rounded-2xl p-3 flex flex-wrap gap-2 w-64 z-30"
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

                                        <!-- Mention Autocomplete Popover -->
                                        <div
                                            v-if="showMentionDropdown && filteredMentionMembers.length > 0"
                                            class="absolute bottom-16 sm:bottom-20 left-3 right-3 sm:right-auto sm:left-14 sm:w-80 max-h-56 overflow-y-auto bg-[#faf6ed] border border-cream-500/70 shadow-2xl rounded-2xl p-1.5 z-40 divide-y divide-cream-300/60"
                                            role="listbox"
                                            aria-label="Mention members"
                                        >
                                            <div class="px-3 py-1.5 text-[10px] font-bold uppercase tracking-wider text-forest-800 flex items-center justify-between">
                                                <span>Mention member</span>
                                                <span class="text-gray-500 font-normal hidden sm:inline">↑↓ to navigate, Enter to select</span>
                                            </div>
                                            <div class="space-y-0.5 pt-1">
                                                <button
                                                    v-for="(member, idx) in filteredMentionMembers"
                                                    :key="member.id"
                                                    type="button"
                                                    @mousedown.prevent="selectMention(member)"
                                                    class="w-full flex items-center gap-2.5 px-3 py-2 rounded-xl text-left transition cursor-pointer"
                                                    :class="selectedMentionIndex === idx ? 'bg-cream-300 text-forest-950 font-medium' : 'hover:bg-cream-200/80 text-gray-800'"
                                                    role="option"
                                                    :aria-selected="selectedMentionIndex === idx"
                                                >
                                                    <img
                                                        :src="member.profile_photo_url || 'https://ui-avatars.com/api/?name=' + encodeURIComponent(member.name)"
                                                        :alt="member.name"
                                                        class="size-7 rounded-full object-cover shrink-0 ring-1 ring-cream-400"
                                                    />
                                                    <div class="min-w-0 flex-1 truncate">
                                                        <div class="text-xs font-semibold truncate text-gray-900 flex items-center gap-1.5">
                                                            <span>{{ member.name }}</span>
                                                            <span v-if="member.role === 'admin' || member.is_admin" class="text-[9px] px-1 py-0.2 bg-lime-200 text-forest-900 rounded font-bold">Admin</span>
                                                        </div>
                                                        <div class="text-[11px] text-gray-500 truncate">
                                                            @{{ member.username || member.name }}
                                                        </div>
                                                    </div>
                                                </button>
                                            </div>
                                        </div>

                                        <!-- Input Controls Form -->
                                        <form @submit.prevent="editingMessage ? submitEditMessage() : sendMessage()" class="flex items-center space-x-2 sm:space-x-3">
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
                                            <div class="flex-1 flex items-center bg-[#ebe2c8] border border-cream-500/50 rounded-full px-3 py-1 focus-within:ring-2 focus-within:ring-forest-600/30 focus-within:border-forest-600 transition">
                                                <!-- Attachment Button (disabled during edit) -->
                                                <button
                                                    v-if="!editingMessage"
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
                                                    @input="onComposerInput"
                                                    @click="checkMentionTrigger"
                                                    @keyup="onComposerKeyup"
                                                    @blur="onComposerBlur"
                                                    rows="1"
                                                    :placeholder="editingMessage ? 'Edit your message...' : 'Type a message... (Enter to send, Shift+Enter for newline)'"
                                                    class="w-full resize-none py-1.5 px-3 bg-transparent text-sm text-gray-900 placeholder:text-gray-600 border-0 focus:ring-0 overflow-y-hidden"
                                                    @keydown="onComposerKeydown"
                                                    aria-label="Type message content"
                                                ></textarea>
                                            </div>

                                            <!-- Send / Save Button -->
                                            <button
                                                type="submit"
                                                :disabled="messageForm.processing || (!messageForm.body.trim() && !messageForm.attachment)"
                                                class="size-10 bg-forest-900 hover:bg-forest-950 disabled:opacity-40 text-white rounded-full shadow-md flex items-center justify-center transition-all transform active:scale-95 shrink-0 cursor-pointer"
                                                :title="editingMessage ? 'Save changes' : 'Send message'"
                                                :aria-label="editingMessage ? 'Save changes' : 'Send message'"
                                            >
                                                <svg v-if="editingMessage" class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                                                </svg>
                                                <svg v-else class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 12L3.269 3.126A59.768 59.768 0 0121.485 12 59.77 59.77 0 013.27 20.876L5.999 12zm0 0h7.5" />
                                                </svg>
                                            </button>
                                        </form>
                                    </div>
                                </div>

                                <!-- Conversation Information Panel (Slide-Over Drawer on Mobile, Sidebar on Desktop) -->
                                <div 
                                    v-if="showInfoPanel"
                                    class="fixed inset-0 z-40 md:relative md:inset-auto md:z-20 flex md:block"
                                    role="region"
                                    aria-label="Conversation details"
                                >
                                    <!-- Mobile Backdrop Overlay -->
                                    <div 
                                        class="fixed inset-0 bg-gray-900/40 backdrop-blur-2xs md:hidden"
                                        @click="showInfoPanel = false"
                                    ></div>

                                    <!-- Panel Container -->
                                    <div 
                                        class="relative ms-auto md:ms-0 w-80 lg:w-88 border-l border-cream-500/50 bg-cream-200 flex flex-col shrink-0 overflow-y-auto h-full shadow-2xl md:shadow-none transition-all duration-300 z-50 md:z-auto"
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
                                                <div class="flex items-center justify-center space-x-1.5">
                                                    <h4 class="font-bold text-base text-gray-900 leading-tight">
                                                        {{ (activeConversation.is_system || activeConversation.type === 'system') ? 'System Notifications' : activeConversation.title }}
                                                    </h4>
                                                    <button
                                                        v-if="activeConversation.type === 'group' && activeConversation.is_admin"
                                                        type="button"
                                                        @click="openRenameGroupModal"
                                                        class="text-gray-400 hover:text-forest-800 p-1 rounded-lg hover:bg-cream-300 transition cursor-pointer"
                                                        title="Rename Group"
                                                        aria-label="Rename Group"
                                                    >
                                                        <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                            <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L6.832 19.82a4.5 4.5 0 01-1.897 1.13l-2.685.8.8-2.685a4.5 4.5 0 011.13-1.897L16.863 4.487zm0 0L19.5 7.125" />
                                                        </svg>
                                                    </button>
                                                </div>
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

                                                <!-- Quick Actions Row -->
                                                <div class="flex flex-wrap items-center justify-center gap-2 mt-4">
                                                    <button
                                                        type="button"
                                                        @click="togglePinConversation(activeConversation)"
                                                        class="px-3 py-1.5 text-xs font-semibold rounded-xl border border-cream-500/60 bg-cream-100 hover:bg-cream-300 transition flex items-center space-x-1 text-gray-800"
                                                    >
                                                        <span>{{ activeConversation.is_pinned ? '📌 Pinned' : '📍 Pin' }}</span>
                                                    </button>
                                                    <button
                                                        type="button"
                                                        @click="toggleMuteConversation(activeConversation)"
                                                        class="px-3 py-1.5 text-xs font-semibold rounded-xl border border-cream-500/60 bg-cream-100 hover:bg-cream-300 transition flex items-center space-x-1 text-gray-800"
                                                    >
                                                        <span>{{ activeConversation.is_muted ? '🔕 Muted' : '🔔 Mute' }}</span>
                                                    </button>
                                                    <button
                                                        v-if="activeConversation.type === 'group' && activeConversation.is_admin"
                                                        type="button"
                                                        @click="promptDeleteGroup"
                                                        class="px-3 py-1.5 text-xs font-semibold rounded-xl border border-red-300 bg-red-50 hover:bg-red-100 transition flex items-center space-x-1 text-red-700 cursor-pointer"
                                                        title="Delete Group"
                                                    >
                                                        <span>🗑️ Delete Group</span>
                                                    </button>
                                                </div>
                                            </div>

                                            <!-- Participants Section -->
                                            <div>
                                                <div class="flex items-center justify-between mb-3">
                                                    <h5 class="text-xs font-bold text-gray-500 uppercase tracking-wider">
                                                        Participants ({{ activeConversation.participants?.length || 0 }})
                                                    </h5>
                                                    <button
                                                        v-if="activeConversation.type === 'group' && activeConversation.is_admin"
                                                        type="button"
                                                        @click="openAddMembersModal"
                                                        class="text-xs font-semibold text-forest-800 hover:text-forest-950 flex items-center space-x-1 hover:underline cursor-pointer"
                                                    >
                                                        <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                                                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                                                        </svg>
                                                        <span>Add Member</span>
                                                    </button>
                                                </div>
                                                <div class="space-y-2">
                                                    <div 
                                                        v-for="participant in activeConversation.participants"
                                                        :key="participant.id"
                                                        class="flex items-center justify-between p-2 hover:bg-cream-300/60 rounded-xl transition group"
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
                                                                <div class="text-xs font-semibold text-gray-900 flex items-center space-x-1 truncate">
                                                                    <span class="truncate">{{ participant.name }}</span>
                                                                    <span v-if="participant.id === $page.props.auth.user.id" class="text-[10px] text-forest-800 font-normal shrink-0">(You)</span>
                                                                    <span
                                                                        v-if="activeConversation.type === 'group' && (participant.is_admin || participant.role === 'admin')"
                                                                        class="ms-1 px-1.5 py-0.2 text-[9px] font-bold bg-amber-100 text-amber-900 border border-amber-300/80 rounded-full shrink-0"
                                                                    >
                                                                        Admin
                                                                    </span>
                                                                </div>
                                                                <div class="text-[11px] text-gray-500 truncate">{{ participant.email }}</div>
                                                            </div>
                                                        </div>

                                                        <!-- Actions for Group Admins -->
                                                        <div class="flex items-center space-x-1 shrink-0">
                                                            <span v-if="participant.is_online" class="text-[10px] font-bold text-forest-800 bg-lime-200 px-2 py-0.5 rounded-full shrink-0">
                                                                Online
                                                            </span>

                                                            <template v-if="activeConversation.type === 'group' && activeConversation.is_admin && participant.id !== $page.props.auth.user.id && !participant.is_admin && participant.role !== 'admin'">
                                                                <!-- Promote to Admin -->
                                                                <button
                                                                    type="button"
                                                                    @click.stop="promptPromoteMember(participant)"
                                                                    class="p-1 text-gray-400 hover:text-amber-800 hover:bg-cream-200 rounded-lg transition cursor-pointer"
                                                                    title="Promote to Group Admin"
                                                                    :aria-label="`Promote ${participant.name} to Admin`"
                                                                >
                                                                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M11.48 3.499a.562.562 0 011.04 0l2.125 5.111a.563.563 0 00.475.345l5.518.442c.499.04.701.663.321.988l-4.204 3.602a.563.563 0 00-.182.557l1.285 5.385a.562.562 0 01-.84.61l-4.725-2.885a.563.563 0 00-.586 0L6.982 20.54a.562.562 0 01-.84-.61l1.285-5.386a.562.562 0 00-.182-.557l-4.204-3.602a.563.563 0 01.321-.988l5.518-.442a.563.563 0 00.475-.345L11.48 3.5z" />
                                                                    </svg>
                                                                </button>

                                                                <!-- Remove Member -->
                                                                <button
                                                                    type="button"
                                                                    @click.stop="promptRemoveMember(participant)"
                                                                    class="p-1 text-gray-400 hover:text-red-700 hover:bg-red-50 rounded-lg transition cursor-pointer"
                                                                    title="Remove from Group"
                                                                    :aria-label="`Remove ${participant.name} from group`"
                                                                >
                                                                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M22 10.5h-6m-2.25-4.125a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zM4 19.235v-.11a6.375 6.375 0 0112.75 0v.109A12.318 12.318 0 0110.374 21c-2.331 0-4.512-.645-6.374-1.765z" />
                                                                    </svg>
                                                                </button>
                                                            </template>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>

                                            <!-- Shared Attachments Section with Tabs -->
                                            <div v-if="activeConversation.shared_attachments && activeConversation.shared_attachments.length > 0">
                                                <div class="flex items-center justify-between mb-2">
                                                    <h5 class="text-xs font-bold text-gray-500 uppercase tracking-wider">
                                                        Shared Attachments
                                                    </h5>
                                                </div>

                                                <!-- Tabs: All vs Media vs Files -->
                                                <div class="flex p-0.5 bg-cream-300 rounded-lg space-x-1 mb-3 text-[11px] font-semibold border border-cream-400">
                                                    <button
                                                        type="button"
                                                        @click="sharedTab = 'all'"
                                                        class="flex-1 py-1 rounded transition text-center"
                                                        :class="sharedTab === 'all' ? 'bg-cream-100 text-forest-900 font-bold shadow-2xs' : 'text-gray-600'"
                                                    >
                                                        All
                                                    </button>
                                                    <button
                                                        type="button"
                                                        @click="sharedTab = 'media'"
                                                        class="flex-1 py-1 rounded transition text-center"
                                                        :class="sharedTab === 'media' ? 'bg-cream-100 text-forest-900 font-bold shadow-2xs' : 'text-gray-600'"
                                                    >
                                                        Media
                                                    </button>
                                                    <button
                                                        type="button"
                                                        @click="sharedTab = 'files'"
                                                        class="flex-1 py-1 rounded transition text-center"
                                                        :class="sharedTab === 'files' ? 'bg-cream-100 text-forest-900 font-bold shadow-2xs' : 'text-gray-600'"
                                                    >
                                                        Files
                                                    </button>
                                                </div>

                                                <div class="grid grid-cols-2 gap-2">
                                                    <div
                                                        v-for="file in filteredSharedAttachments"
                                                        :key="file.id"
                                                        class="p-2 border border-cream-500/40 bg-cream-100 hover:bg-cream-300/80 rounded-xl flex flex-col items-center text-center transition group relative"
                                                    >
                                                        <button
                                                            v-if="file.type === 'image'"
                                                            type="button"
                                                            @click="openLightbox(file.url, file.name)"
                                                            class="w-full flex flex-col items-center cursor-pointer"
                                                        >
                                                            <img 
                                                                :src="file.url" 
                                                                alt="Attachment"
                                                                class="size-14 rounded-lg object-cover mb-1 group-hover:scale-105 transition" 
                                                            />
                                                            <span class="text-[11px] font-medium text-gray-800 truncate w-full group-hover:text-forest-900">
                                                                {{ file.name }}
                                                            </span>
                                                        </button>
                                                        <a
                                                            v-else
                                                            :href="file.url"
                                                            target="_blank"
                                                            download
                                                            class="w-full flex flex-col items-center"
                                                        >
                                                            <svg class="size-7 text-forest-800 mb-1" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
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

        <!-- Image Lightbox Modal -->
        <div
            v-if="lightboxImage"
            class="fixed inset-0 z-50 bg-black/90 backdrop-blur-sm flex flex-col items-center justify-center p-4"
            role="dialog"
            aria-modal="true"
            aria-label="Image preview"
            @click.self="closeLightbox"
        >
            <div class="absolute top-4 right-4 flex items-center space-x-2">
                <a
                    :href="lightboxImage.url"
                    target="_blank"
                    download
                    class="p-2 text-white/80 hover:text-white bg-white/10 hover:bg-white/20 rounded-xl transition cursor-pointer"
                    title="Download image"
                    aria-label="Download image"
                >
                    <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" />
                    </svg>
                </a>
                <button
                    @click="closeLightbox"
                    class="p-2 text-white/80 hover:text-white bg-white/10 hover:bg-white/20 rounded-xl transition cursor-pointer"
                    aria-label="Close lightbox"
                >
                    <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
            <div class="max-w-4xl max-h-[85vh] flex flex-col items-center">
                <img
                    :src="lightboxImage.url"
                    :alt="lightboxImage.name"
                    class="max-w-full max-h-[80vh] object-contain rounded-xl shadow-2xl"
                />
                <p class="text-white/80 text-xs mt-3 font-medium">{{ lightboxImage.name }}</p>
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

        <!-- Remove Member Confirmation Dialog Modal -->
        <DialogModal :show="memberToRemove !== null" max-width="sm" @close="memberToRemove = null">
            <template #title>
                <div class="flex items-center space-x-3 pt-2">
                    <div class="size-10 rounded-xl bg-red-100 text-red-800 flex items-center justify-center shrink-0">
                        <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M22 10.5h-6m-2.25-4.125a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zM4 19.235v-.11a6.375 6.375 0 0112.75 0v.109A12.318 12.318 0 0110.374 21c-2.331 0-4.512-.645-6.374-1.765z" />
                        </svg>
                    </div>
                    <div>
                        <h3 class="text-base font-bold text-gray-900">Remove Member</h3>
                    </div>
                </div>
            </template>

            <template #content>
                <p class="text-sm text-gray-700 leading-relaxed">
                    Are you sure you want to remove <strong class="text-gray-900">{{ memberToRemove?.name }}</strong> from this group? They will no longer be able to view or send messages in this conversation.
                </p>
            </template>

            <template #footer>
                <div class="flex items-center space-x-2">
                    <button
                        type="button"
                        @click="memberToRemove = null"
                        class="px-4 py-2 bg-cream-100 hover:bg-cream-300 text-gray-700 rounded-xl text-xs font-bold border border-cream-400 transition cursor-pointer"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        :disabled="isRemovingMember"
                        @click="confirmRemoveMember"
                        class="px-4 py-2 bg-red-700 hover:bg-red-800 disabled:opacity-50 text-white rounded-xl text-xs font-bold shadow-xs transition cursor-pointer"
                    >
                        {{ isRemovingMember ? 'Removing...' : 'Remove Member' }}
                    </button>
                </div>
            </template>
        </DialogModal>

        <!-- Promote Member Confirmation Dialog Modal -->
        <DialogModal :show="memberToPromote !== null" max-width="sm" @close="memberToPromote = null">
            <template #title>
                <div class="flex items-center space-x-3 pt-2">
                    <div class="size-10 rounded-xl bg-amber-100 text-amber-800 flex items-center justify-center shrink-0">
                        <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M11.48 3.499a.562.562 0 011.04 0l2.125 5.111a.563.563 0 00.475.345l5.518.442c.499.04.701.663.321.988l-4.204 3.602a.563.563 0 00-.182.557l1.285 5.385a.562.562 0 01-.84.61l-4.725-2.885a.563.563 0 00-.586 0L6.982 20.54a.562.562 0 01-.84-.61l1.285-5.386a.562.562 0 00-.182-.557l-4.204-3.602a.563.563 0 01.321-.988l5.518-.442a.563.563 0 00.475-.345L11.48 3.5z" />
                        </svg>
                    </div>
                    <div>
                        <h3 class="text-base font-bold text-gray-900">Promote to Group Admin</h3>
                    </div>
                </div>
            </template>

            <template #content>
                <p class="text-sm text-gray-700 leading-relaxed">
                    Are you sure you want to promote <strong class="text-gray-900">{{ memberToPromote?.name }}</strong> to a group admin? They will gain elevated permissions to rename the group, add members, remove non-admin members, and delete the group.
                </p>
            </template>

            <template #footer>
                <div class="flex items-center space-x-2">
                    <button
                        type="button"
                        @click="memberToPromote = null"
                        class="px-4 py-2 bg-cream-100 hover:bg-cream-300 text-gray-700 rounded-xl text-xs font-bold border border-cream-400 transition cursor-pointer"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        :disabled="isPromotingMember"
                        @click="confirmPromoteMember"
                        class="px-4 py-2 bg-amber-600 hover:bg-amber-700 disabled:opacity-50 text-white rounded-xl text-xs font-bold shadow-xs transition cursor-pointer"
                    >
                        {{ isPromotingMember ? 'Promoting...' : 'Promote to Admin' }}
                    </button>
                </div>
            </template>
        </DialogModal>

        <!-- Delete Group Confirmation Dialog Modal -->
        <DialogModal :show="showDeleteGroupModal" max-width="sm" @close="showDeleteGroupModal = false">
            <template #title>
                <div class="flex items-center space-x-3 pt-2">
                    <div class="size-10 rounded-xl bg-red-100 text-red-800 flex items-center justify-center shrink-0">
                        <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" />
                        </svg>
                    </div>
                    <div>
                        <h3 class="text-base font-bold text-gray-900">Delete Group Chat</h3>
                    </div>
                </div>
            </template>

            <template #content>
                <p class="text-sm text-gray-700 leading-relaxed">
                    Are you sure you want to permanently delete <strong class="text-gray-900">&ldquo;{{ activeConversation?.title }}&rdquo;</strong>? All messages, shared media, and files will be removed for all members. This action cannot be undone.
                </p>
            </template>

            <template #footer>
                <div class="flex items-center space-x-2">
                    <button
                        type="button"
                        @click="showDeleteGroupModal = false"
                        class="px-4 py-2 bg-cream-100 hover:bg-cream-300 text-gray-700 rounded-xl text-xs font-bold border border-cream-400 transition cursor-pointer"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        :disabled="isDeletingGroup"
                        @click="confirmDeleteGroup"
                        class="px-4 py-2 bg-red-700 hover:bg-red-800 disabled:opacity-50 text-white rounded-xl text-xs font-bold shadow-xs transition cursor-pointer"
                    >
                        {{ isDeletingGroup ? 'Deleting...' : 'Delete Group' }}
                    </button>
                </div>
            </template>
        </DialogModal>

        <!-- Rename Group Modal -->
        <DialogModal :show="showRenameGroupModal" max-width="md" @close="showRenameGroupModal = false">
            <template #title>
                <div class="flex items-center space-x-3 pt-2">
                    <div class="size-10 rounded-xl bg-forest-100 text-forest-800 flex items-center justify-center shrink-0">
                        <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L6.832 19.82a4.5 4.5 0 01-1.897 1.13l-2.685.8.8-2.685a4.5 4.5 0 011.13-1.897L16.863 4.487zm0 0L19.5 7.125" />
                        </svg>
                    </div>
                    <div>
                        <h3 class="text-base font-bold text-gray-900">Rename Group Chat</h3>
                    </div>
                </div>
            </template>

            <template #content>
                <div class="space-y-3">
                    <label class="block text-xs font-bold text-gray-700">Group Name</label>
                    <input
                        v-model="renameGroupTitle"
                        type="text"
                        maxlength="255"
                        placeholder="Enter new group name..."
                        class="w-full px-3 py-2 border border-cream-400 rounded-xl bg-cream-50 text-sm focus:outline-hidden focus:ring-2 focus:ring-forest-500"
                        @keydown.enter.prevent="confirmRenameGroup"
                    />
                </div>
            </template>

            <template #footer>
                <div class="flex items-center space-x-2">
                    <button
                        type="button"
                        @click="showRenameGroupModal = false"
                        class="px-4 py-2 bg-cream-100 hover:bg-cream-300 text-gray-700 rounded-xl text-xs font-bold border border-cream-400 transition cursor-pointer"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        :disabled="!renameGroupTitle.trim() || isRenamingGroup"
                        @click="confirmRenameGroup"
                        class="px-4 py-2 bg-forest-900 hover:bg-forest-950 disabled:opacity-50 text-white rounded-xl text-xs font-bold shadow-xs transition cursor-pointer"
                    >
                        {{ isRenamingGroup ? 'Saving...' : 'Save Name' }}
                    </button>
                </div>
            </template>
        </DialogModal>

        <!-- Add Members to Group Modal -->
        <DialogModal :show="showAddMembersModal" max-width="md" @close="showAddMembersModal = false">
            <template #title>
                <div class="flex items-center space-x-3 pt-2">
                    <div class="size-10 rounded-xl bg-forest-100 text-forest-800 flex items-center justify-center shrink-0">
                        <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19 7.5v3m0 0v3m0-3h3m-3 0h-3m-2.25-4.125a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zM4 19.235v-.11a6.375 6.375 0 0112.75 0v.109A12.318 12.318 0 0110.374 21c-2.331 0-4.512-.645-6.374-1.765z" />
                        </svg>
                    </div>
                    <div>
                        <h3 class="text-base font-bold text-gray-900">Add Members to Group</h3>
                    </div>
                </div>
            </template>

            <template #content>
                <div class="space-y-4">
                    <!-- Search Input -->
                    <div class="relative">
                        <input
                            v-model="addMembersSearch"
                            type="text"
                            placeholder="Search contacts by name or email..."
                            @input="onAddMembersSearchInput"
                            class="w-full px-3 py-2 pl-9 border border-cream-400 rounded-xl bg-cream-50 text-sm focus:outline-hidden focus:ring-2 focus:ring-forest-500"
                        />
                        <svg class="size-4 text-gray-400 absolute left-3 top-3" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
                        </svg>
                    </div>

                    <!-- Selected Members Pills -->
                    <div v-if="addMembersSelectedUsers.length > 0" class="flex flex-wrap gap-1.5 p-2 bg-cream-200/50 rounded-xl border border-cream-400/50 max-h-24 overflow-y-auto">
                        <span
                            v-for="user in addMembersSelectedUsers"
                            :key="user.id"
                            class="inline-flex items-center space-x-1 px-2.5 py-1 bg-forest-900 text-white rounded-lg text-xs font-medium"
                        >
                            <span>{{ user.name }}</span>
                            <button type="button" @click="toggleAddMemberSelection(user)" class="hover:text-red-300 cursor-pointer">
                                &times;
                            </button>
                        </span>
                    </div>

                    <!-- Contacts List -->
                    <div class="border border-cream-400/60 rounded-xl divide-y divide-cream-300 max-h-56 overflow-y-auto bg-cream-100">
                        <div v-if="isLoadingContacts" class="p-6 text-center text-xs text-gray-500">
                            Loading contacts...
                        </div>
                        <template v-else-if="addableContacts.length > 0">
                            <div
                                v-for="user in addableContacts"
                                :key="user.id"
                                @click="toggleAddMemberSelection(user)"
                                class="p-2.5 flex items-center justify-between hover:bg-cream-200 cursor-pointer transition"
                            >
                                <div class="flex items-center space-x-3">
                                    <img
                                        :src="user.profile_photo_url || 'https://ui-avatars.com/api/?name=' + encodeURIComponent(user.name)"
                                        :alt="user.name"
                                        class="size-8 rounded-full object-cover ring-1 ring-cream-400"
                                    />
                                    <div>
                                        <div class="text-xs font-semibold text-gray-900">{{ user.name }}</div>
                                        <div class="text-[11px] text-gray-500">{{ user.email }}</div>
                                    </div>
                                </div>
                                <input
                                    type="checkbox"
                                    :checked="addMembersSelectedIds.includes(user.id)"
                                    class="size-4 rounded border-cream-400 text-forest-800 focus:ring-forest-500 pointer-events-none"
                                />
                            </div>
                        </template>
                        <div v-else class="p-6 text-center text-xs text-gray-400">
                            {{ addMembersSearch.trim() ? `No contacts match "${addMembersSearch}"` : 'All available contacts are already in this group' }}
                        </div>
                    </div>
                </div>
            </template>

            <template #footer>
                <div class="flex items-center space-x-2">
                    <button
                        type="button"
                        @click="showAddMembersModal = false"
                        class="px-4 py-2 bg-cream-100 hover:bg-cream-300 text-gray-700 rounded-xl text-xs font-bold border border-cream-400 transition cursor-pointer"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        :disabled="addMembersSelectedIds.length === 0 || isAddingMembers"
                        @click="confirmAddMembers"
                        class="px-4 py-2 bg-forest-900 hover:bg-forest-950 disabled:opacity-50 text-white rounded-xl text-xs font-bold shadow-xs transition cursor-pointer"
                    >
                        {{ isAddingMembers ? 'Adding...' : `Add Selected (${addMembersSelectedIds.length})` }}
                    </button>
                </div>
            </template>
        </DialogModal>
    </AppLayout>
</template>

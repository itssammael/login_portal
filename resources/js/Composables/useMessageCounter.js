import { ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { useToast } from '@/Composables/useToast';

// Global singleton state so state and listeners persist across SPA navigations
const unreadCount = ref(0);
const isListening = ref(false);
const processedEventKeys = new Set();
let activeUserId = null;

const NOTIFICATION_SOUND_URL = '/assets/audio/notification/740422__anthonyrox__message-notification-3.wav';
let notificationAudio = null;

/**
 * Play the custom message notification sound from public/assets/audio/notification/...
 */
function playNotificationSound() {
    try {
        if (!notificationAudio) {
            notificationAudio = new Audio(NOTIFICATION_SOUND_URL);
            notificationAudio.preload = 'auto';
        }
        notificationAudio.currentTime = 0;
        const playPromise = notificationAudio.play();
        if (playPromise !== undefined) {
            playPromise.catch(() => {
                // Fallback to Web Audio synthesis if HTMLAudioElement fails
                playNotificationChime();
            });
        }
    } catch {
        playNotificationChime();
    }
}

/**
 * Fallback Web Audio API chime.
 */
function playNotificationChime() {
    try {
        const AudioCtx = window.AudioContext || window.webkitAudioContext;
        if (!AudioCtx) return;
        const ctx = new AudioCtx();

        if (ctx.state === 'suspended') {
            ctx.resume();
        }

        const now = ctx.currentTime;
        const osc1 = ctx.createOscillator();
        const osc2 = ctx.createOscillator();
        const gainNode = ctx.createGain();

        osc1.type = 'sine';
        osc1.frequency.setValueAtTime(587.33, now);
        osc1.frequency.exponentialRampToValueAtTime(880, now + 0.12);

        osc2.type = 'sine';
        osc2.frequency.setValueAtTime(880, now + 0.12);
        osc2.frequency.exponentialRampToValueAtTime(1174.66, now + 0.25);

        gainNode.gain.setValueAtTime(0.04, now);
        gainNode.gain.exponentialRampToValueAtTime(0.001, now + 0.35);

        osc1.connect(gainNode);
        osc2.connect(gainNode);
        gainNode.connect(ctx.destination);

        osc1.start(now);
        osc1.stop(now + 0.15);
        osc2.start(now + 0.12);
        osc2.stop(now + 0.35);
    } catch {
        // Audio error ignored safely
    }
}

export function useMessageCounter() {
    const page = usePage();
    const toast = useToast();

    // Initialize initial count from page props
    if (page.props.unread_messages_count !== undefined) {
        unreadCount.value = Number(page.props.unread_messages_count) || 0;
    }

    // Keep unreadCount reactive to Inertia navigation prop changes
    watch(
        () => page.props.unread_messages_count,
        (newVal) => {
            if (newVal !== undefined && newVal !== null) {
                unreadCount.value = Number(newVal) || 0;
            }
        },
        { immediate: true }
    );

    /**
     * Fetch the exact unread messages count from the server.
     */
    const refreshUnreadCount = async () => {
        try {
            const url = typeof route === 'function' ? route('chat.unread-count') : '/chat/unread-count';
            const response = await axios.get(url);
            if (response.data && typeof response.data.count === 'number') {
                unreadCount.value = response.data.count;
                if (page.props) {
                    page.props.unread_messages_count = response.data.count;
                }
            }
        } catch {
            // Silently handle offline/request errors
        }
    };

    /**
     * Handle incoming real-time broadcast message event.
     */
    const handleIncomingMessage = (e) => {
        if (!e) return;

        const currentUserId = Number(page.props.auth?.user?.id);
        if (!currentUserId) return;

        // Ignore messages sent by the current user
        if (e.data?.sender_id && Number(e.data.sender_id) === currentUserId) {
            return;
        }

        // If participant_ids is present, verify current user is a recipient
        if (Array.isArray(e.participant_ids) && e.participant_ids.length > 0) {
            const isParticipant = e.participant_ids.map(Number).includes(currentUserId);
            if (!isParticipant) {
                return;
            }
        }

        // Deduplicate in case event is received via both public and private channels
        const dedupeKey = `${e.action || 'created'}-${e.conversation_id || 0}-${e.data?.id || e.message || Date.now()}`;
        if (processedEventKeys.has(dedupeKey)) {
            return;
        }
        processedEventKeys.add(dedupeKey);
        setTimeout(() => processedEventKeys.delete(dedupeKey), 4000);

        // Check if user is currently on the chat page AND looking at this exact conversation
        const isChatRoute = typeof route === 'function' && route().current('chat.*');
        const activeConvId = page.props.activeConversation?.id;
        const isActivelyViewing = isChatRoute && activeConvId && Number(activeConvId) === Number(e.conversation_id);

        if (e.action === 'created') {
            if (!isActivelyViewing) {
                // Optimistically increment the unread counter immediately
                unreadCount.value += 1;
                if (page.props) {
                    page.props.unread_messages_count = unreadCount.value;
                }

                // Show notification toast
                const toastTitle = e.message?.includes('Announcement') ? '📢 Announcement' : '💬 Messenger';
                const toastMessage = e.message || 'You received a new message';
                toast.info(toastMessage, toastTitle);

                // Play notification audio sound
                playNotificationSound();
            }

            // Sync with backend to guarantee database-level accuracy
            refreshUnreadCount();
        } else if (e.action === 'deleted') {
            refreshUnreadCount();
        }
    };

    /**
     * Initialize Echo listeners if not already initialized for this user.
     */
    const initListeners = () => {
        const currentUserId = Number(page.props.auth?.user?.id);
        if (!currentUserId || !window.Echo) return;

        // If already listening for this same user, do not duplicate subscriptions
        if (isListening.value && activeUserId === currentUserId) {
            return;
        }

        activeUserId = currentUserId;
        isListening.value = true;

        // 1. Listen on public chat channel
        window.Echo.channel('public-chat')
            .listen('.message.sent', handleIncomingMessage);

        // 2. Listen on user-specific private channel
        window.Echo.private(`App.Models.User.${currentUserId}`)
            .listen('.message.sent', handleIncomingMessage);
    };

    return {
        unreadCount,
        initListeners,
        refreshUnreadCount,
        handleIncomingMessage,
    };
}

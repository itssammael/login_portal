<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue';
import { usePage } from '@inertiajs/vue3';

const props = defineProps({
    modelValue: {
        type: String,
        default: '',
    },
    siteKey: {
        type: String,
        default: '',
    },
    action: {
        type: String,
        default: 'feedback_submit',
    },
    theme: {
        type: String,
        default: 'light',
    },
});

const emit = defineEmits(['update:modelValue', 'verified', 'expired', 'error']);

const page = usePage();
const containerRef = ref(null);
const configMissing = ref(false);
const scriptLoadFailed = ref(false);

let widgetId = null;
let isRendered = false;
let pollTimer = null;
let timeoutTimer = null;
let scriptElement = null;
let lastEmittedToken = '';

const resolvedSiteKey = computed(() => {
    const key =
        props.siteKey ||
        page?.props?.turnstile?.site_key ||
        page?.props?.turnstileSiteKey ||
        import.meta.env.VITE_TURNSTILE_SITE_KEY ||
        '';
    return typeof key === 'string' ? key.trim() : '';
});

const clearTimersAndListeners = () => {
    if (pollTimer !== null) {
        clearInterval(pollTimer);
        pollTimer = null;
    }
    if (timeoutTimer !== null) {
        clearTimeout(timeoutTimer);
        timeoutTimer = null;
    }
    if (scriptElement) {
        scriptElement.removeEventListener('load', onScriptLoad);
        scriptElement.removeEventListener('error', onScriptError);
        scriptElement = null;
    }
};

const emitToken = (token) => {
    lastEmittedToken = token;
    emit('update:modelValue', token);
};

const renderWidget = () => {
    if (isRendered || widgetId !== null || !containerRef.value) {
        return;
    }

    if (!resolvedSiteKey.value) {
        configMissing.value = true;
        emitToken('');
        return;
    }

    if (typeof window === 'undefined' || !window.turnstile || typeof window.turnstile.render !== 'function') {
        return;
    }

    clearTimersAndListeners();
    configMissing.value = false;
    scriptLoadFailed.value = false;
    isRendered = true;

    try {
        containerRef.value.innerHTML = '';
        widgetId = window.turnstile.render(containerRef.value, {
            sitekey: resolvedSiteKey.value,
            action: props.action,
            theme: props.theme,
            callback: (token) => {
                scriptLoadFailed.value = false;
                emitToken(typeof token === 'string' ? token : '');
                emit('verified', token);
            },
            'expired-callback': () => {
                emitToken('');
                emit('expired');
            },
            'error-callback': () => {
                emitToken('');
                emit('error');
            },
        });
    } catch {
        isRendered = false;
        widgetId = null;
        scriptLoadFailed.value = true;
        emitToken('');
        emit('error');
    }
};

const onScriptLoad = () => {
    if (typeof window !== 'undefined' && window.turnstile && typeof window.turnstile.render === 'function') {
        renderWidget();
    }
};

const onScriptError = () => {
    clearTimersAndListeners();
    scriptLoadFailed.value = true;
    emitToken('');
    emit('error');
};

const reset = () => {
    emitToken('');
    if (typeof window === 'undefined' || !window.turnstile) {
        return;
    }

    if (widgetId !== null && typeof window.turnstile.reset === 'function') {
        try {
            window.turnstile.reset(widgetId);
        } catch {
            // Ignore widget reset errors
        }
    } else if (!isRendered) {
        renderWidget();
    }
};

watch(
    () => props.modelValue,
    (newVal) => {
        if (!newVal && lastEmittedToken) {
            lastEmittedToken = '';
            if (typeof window !== 'undefined' && window.turnstile && widgetId !== null && typeof window.turnstile.reset === 'function') {
                try {
                    window.turnstile.reset(widgetId);
                } catch {
                    // Ignore widget reset errors
                }
            }
        }
    }
);

onMounted(() => {
    if (!resolvedSiteKey.value) {
        configMissing.value = true;
        emitToken('');
        return;
    }

    if (typeof window !== 'undefined' && window.turnstile && typeof window.turnstile.render === 'function') {
        renderWidget();
        return;
    }

    if (typeof document !== 'undefined') {
        scriptElement = document.querySelector('script[src*="challenges.cloudflare.com/turnstile/v0/api.js"]');
        if (scriptElement) {
            scriptElement.addEventListener('load', onScriptLoad);
            scriptElement.addEventListener('error', onScriptError);
        }
    }

    pollTimer = setInterval(() => {
        if (typeof window !== 'undefined' && window.turnstile && typeof window.turnstile.render === 'function') {
            renderWidget();
        }
    }, 100);

    timeoutTimer = setTimeout(() => {
        if (!isRendered) {
            clearTimersAndListeners();
            scriptLoadFailed.value = true;
            emitToken('');
            emit('error');
        }
    }, 10000);
});

onBeforeUnmount(() => {
    clearTimersAndListeners();

    if (widgetId !== null && typeof window !== 'undefined' && window.turnstile && typeof window.turnstile.remove === 'function') {
        try {
            window.turnstile.remove(widgetId);
        } catch {
            // Ignore widget cleanup errors
        }
    }

    widgetId = null;
    isRendered = false;
    lastEmittedToken = '';
});

defineExpose({
    reset,
});
</script>

<template>
    <div class="turnstile-widget-wrapper space-y-2">
        <div ref="containerRef" data-testid="turnstile-container" class="min-h-[65px] flex items-center"></div>

        <p
            v-if="configMissing"
            data-testid="turnstile-config-error"
            class="text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded-xl px-3 py-2 font-medium"
        >
            Security verification is not configured for this form.
        </p>

        <p
            v-else-if="scriptLoadFailed"
            data-testid="turnstile-script-error"
            class="text-xs text-red-700 bg-red-50 border border-red-200 rounded-xl px-3 py-2 font-medium"
        >
            Unable to load Cloudflare security verification. Please check your connection and refresh the page.
        </p>
    </div>
</template>

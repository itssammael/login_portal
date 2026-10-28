<script>
const qrCache = new Map();
</script>

<script setup>
import { computed, nextTick, onMounted, onUnmounted, ref } from 'vue';
import axios from 'axios';

const props = defineProps({
    form: { type: Object, required: true },
    url: { type: String, required: true },
});

const emit = defineEmits(['close']);

const modalCardRef = ref(null);
const closeButtonRef = ref(null);
let previouslyFocusedElement = null;
let focusRestored = false;

const loading = ref(false);
const error = ref('');
const svgContent = ref('');
const copied = ref(false);
const downloading = ref(false);

const formTitle = computed(() => props.form.event?.name || 'Feedback Form');
const formDetails = computed(() => props.form.event?.details || '');
const altText = computed(() => `QR code for ${formTitle.value} feedback form`);

const svgDataUrl = computed(() => {
    if (!svgContent.value) return '';
    return `data:image/svg+xml;charset=utf-8,${encodeURIComponent(svgContent.value)}`;
});

const fetchQrCode = async () => {
    if (!props.url) return;

    if (qrCache.has(props.url)) {
        svgContent.value = qrCache.get(props.url);
        error.value = '';
        loading.value = false;
        return;
    }

    loading.value = true;
    error.value = '';

    try {
        const response = await axios.get(route('feedback.qr-code'), {
            params: { url: props.url },
        });
        const svg = response.data?.svg || '';
        svgContent.value = svg;
        qrCache.set(props.url, svg);
    } catch (err) {
        error.value = err.response?.data?.message || 'Failed to generate QR code. Please try again.';
    } finally {
        loading.value = false;
    }
};

const copyUrl = async () => {
    try {
        await navigator.clipboard.writeText(props.url);
    } catch {
        const input = document.createElement('textarea');
        input.value = props.url;
        input.style.position = 'fixed';
        input.style.opacity = '0';
        document.body.appendChild(input);
        input.select();
        document.execCommand('copy');
        input.remove();
    }
    copied.value = true;
    window.setTimeout(() => {
        copied.value = false;
    }, 1800);
};

const downloadPng = () => {
    if (!svgContent.value || downloading.value) return;
    downloading.value = true;

    try {
        const size = 1000;
        const canvas = document.createElement('canvas');
        canvas.width = size;
        canvas.height = size;
        const ctx = canvas.getContext('2d');
        if (!ctx) {
            downloading.value = false;
            return;
        }

        // Draw clean white background
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 0, size, size);

        const img = new Image();
        const svgBlob = new Blob([svgContent.value], { type: 'image/svg+xml;charset=utf-8' });
        const blobUrl = URL.createObjectURL(svgBlob);

        img.onload = () => {
            const padding = 80;
            ctx.drawImage(img, padding, padding, size - padding * 2, size - padding * 2);
            URL.revokeObjectURL(blobUrl);

            const a = document.createElement('a');
            const slug = (props.form.event?.name || 'feedback-form')
                .toLowerCase()
                .replace(/[^a-z0-9]+/g, '-')
                .replace(/^-|-$/g, '') || 'feedback-form';
            a.download = `${slug}-qr-code.png`;
            a.href = canvas.toDataURL('image/png');
            document.body.appendChild(a);
            a.click();
            a.remove();
            downloading.value = false;
        };

        img.onerror = () => {
            URL.revokeObjectURL(blobUrl);
            downloading.value = false;
        };

        img.src = blobUrl;
    } catch {
        downloading.value = false;
    }
};

const restoreFocus = () => {
    if (focusRestored) return;
    focusRestored = true;
    if (previouslyFocusedElement && typeof previouslyFocusedElement.focus === 'function') {
        previouslyFocusedElement.focus();
    }
};

const closeModal = () => {
    restoreFocus();
    emit('close');
};

const getFocusableElements = () => {
    if (!modalCardRef.value) return [];
    const selector = [
        'a[href]',
        'button:not([disabled])',
        'textarea:not([disabled])',
        'input:not([disabled])',
        'select:not([disabled])',
        '[tabindex]:not([tabindex="-1"])',
    ].join(', ');

    return Array.from(modalCardRef.value.querySelectorAll(selector)).filter(
        (el) => el.offsetParent !== null || el.offsetWidth > 0 || el.offsetHeight > 0
    );
};

const onKeydown = (event) => {
    if (event.key === 'Escape') {
        event.preventDefault();
        closeModal();
        return;
    }

    if (event.key === 'Tab') {
        const focusable = getFocusableElements();
        if (focusable.length === 0) {
            event.preventDefault();
            return;
        }

        const first = focusable[0];
        const last = focusable[focusable.length - 1];

        if (event.shiftKey) {
            if (document.activeElement === first || !modalCardRef.value?.contains(document.activeElement)) {
                event.preventDefault();
                last.focus();
            }
        } else {
            if (document.activeElement === last || !modalCardRef.value?.contains(document.activeElement)) {
                event.preventDefault();
                first.focus();
            }
        }
    }
};

onMounted(() => {
    previouslyFocusedElement = document.activeElement;
    window.addEventListener('keydown', onKeydown);
    fetchQrCode();

    nextTick(() => {
        if (closeButtonRef.value) {
            closeButtonRef.value.focus();
        } else {
            const focusable = getFocusableElements();
            if (focusable.length > 0) {
                focusable[0].focus();
            } else {
                modalCardRef.value?.focus();
            }
        }
    });
});

onUnmounted(() => {
    window.removeEventListener('keydown', onKeydown);
    restoreFocus();
});
</script>

<template>
    <div
        class="fixed inset-0 z-50 overflow-y-auto bg-gray-950/65 p-4 backdrop-blur-sm"
        role="dialog"
        aria-modal="true"
        aria-labelledby="qr-modal-title"
        @click.self="closeModal"
    >
        <div class="mx-auto flex min-h-full max-w-lg items-center justify-center">
            <div
                ref="modalCardRef"
                tabindex="-1"
                class="w-full overflow-hidden rounded-3xl border border-cream-500 bg-cream-100 shadow-2xl outline-none"
            >
                <!-- Header -->
                <header class="flex items-start justify-between gap-4 border-b border-cream-500 bg-white px-5 py-5 sm:px-7">
                    <div>
                        <p class="text-xs font-bold uppercase tracking-[0.16em] text-forest-700">QR Code</p>
                        <h2 id="qr-modal-title" class="text-lg font-black text-gray-950">{{ formTitle }}</h2>
                        <p v-if="formDetails" class="mt-0.5 text-xs text-gray-600 line-clamp-1">{{ formDetails }}</p>
                    </div>
                    <button
                        ref="closeButtonRef"
                        type="button"
                        class="rounded-xl p-2 text-gray-500 hover:bg-cream-200 cursor-pointer transition-colors"
                        aria-label="Close QR modal"
                        @click="closeModal"
                    >
                        ✕
                    </button>
                </header>

                <!-- Body -->
                <div class="space-y-6 p-5 sm:p-7">
                    <!-- Live Form URL Display -->
                    <div class="rounded-2xl border border-cream-500 bg-white p-3.5">
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-[11px] font-bold uppercase tracking-wide text-gray-500">Form URL</span>
                            <button
                                type="button"
                                class="text-xs font-bold text-forest-700 hover:text-forest-900 transition-colors cursor-pointer"
                                @click="copyUrl"
                            >
                                {{ copied ? '✓ Copied' : 'Copy' }}
                            </button>
                        </div>
                        <code class="mt-1 block break-all text-xs text-forest-900">{{ url }}</code>
                    </div>

                    <!-- QR Code Area -->
                    <div class="flex flex-col items-center justify-center">
                        <div class="relative flex size-64 sm:size-72 items-center justify-center rounded-2xl border border-cream-500 bg-white p-4 shadow-sm">
                            <!-- Loading state -->
                            <div v-if="loading" class="flex flex-col items-center justify-center gap-2 text-gray-500">
                                <div class="size-8 animate-spin rounded-full border-2 border-forest-900 border-t-transparent"></div>
                                <span class="text-xs font-medium">Generating QR code...</span>
                            </div>

                            <!-- Error state -->
                            <div v-else-if="error" class="flex flex-col items-center justify-center gap-3 p-4 text-center">
                                <p class="text-xs text-red-600 font-medium">{{ error }}</p>
                                <button
                                    type="button"
                                    class="rounded-lg bg-cream-200 px-3 py-1.5 text-xs font-bold text-gray-800 hover:bg-cream-300 cursor-pointer"
                                    @click="fetchQrCode"
                                >
                                    Retry
                                </button>
                            </div>

                            <!-- QR Image -->
                            <img
                                v-else-if="svgDataUrl"
                                :src="svgDataUrl"
                                :alt="altText"
                                class="size-full object-contain"
                            />
                        </div>
                        <p class="mt-3 text-center text-xs text-gray-500">
                            Scan with a mobile camera or QR reader to open the form directly.
                        </p>
                    </div>
                </div>

                <!-- Footer -->
                <footer class="flex flex-col-reverse gap-2 border-t border-cream-500 bg-white px-5 py-4 sm:flex-row sm:items-center sm:justify-end sm:px-7">
                    <button
                        type="button"
                        class="rounded-xl bg-white px-4 py-2.5 text-xs font-bold text-gray-700 ring-1 ring-cream-500 hover:bg-cream-200 cursor-pointer transition-colors"
                        @click="closeModal"
                    >
                        Close
                    </button>
                    <button
                        type="button"
                        :disabled="!svgContent || loading || downloading"
                        class="inline-flex items-center justify-center gap-1.5 rounded-xl bg-forest-900 px-4 py-2.5 text-xs font-black text-white shadow-sm hover:bg-forest-950 disabled:opacity-50 cursor-pointer transition-colors"
                        @click="downloadPng"
                    >
                        <span v-if="downloading">Preparing PNG...</span>
                        <span v-else>Download QR (PNG)</span>
                    </button>
                </footer>
            </div>
        </div>
    </div>
</template>

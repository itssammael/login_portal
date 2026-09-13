<script setup>
import { watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { useToast } from '@/Composables/useToast';

const page = usePage();
const { toasts, remove, success, error, warning, info } = useToast();

// Watch for flash messages sent from Laravel controllers
watch(
    () => page.props.flash,
    (flash) => {
        if (!flash) return;

        if (flash.success) {
            success(flash.success, 'Success');
        }
        if (flash.error) {
            error(flash.error, 'Action Failed');
        }
        if (flash.warning) {
            warning(flash.warning, 'Notice');
        }
        if (flash.info) {
            info(flash.info, 'Information');
        }
        if (flash.status) {
            info(flash.status, 'System Notice');
        }
        if (flash.message) {
            info(flash.message, 'Notification');
        }
    },
    { deep: true, immediate: true }
);

// Watch for form validation errors returned by Inertia
watch(
    () => page.props.errors,
    (errors) => {
        if (!errors || Object.keys(errors).length === 0) return;

        const messages = Object.values(errors);
        if (messages.length === 1) {
            error(messages[0], 'Validation Error');
        } else if (messages.length > 1) {
            error(`Please fix ${messages.length} errors on the form.`, 'Validation Errors');
        }
    },
    { deep: true }
);
</script>

<template>
    <div
        class="fixed top-5 right-5 z-[9999] flex flex-col space-y-3 max-w-sm w-full pointer-events-none px-4 sm:px-0"
        aria-live="polite"
    >
        <TransitionGroup
            enter-active-class="transform ease-out duration-300 transition-all"
            enter-from-class="translate-y-2 opacity-0 sm:translate-y-0 sm:translate-x-4"
            enter-to-class="translate-y-0 opacity-100 sm:translate-x-0"
            leave-active-class="transition ease-in duration-200"
            leave-from-class="opacity-100 scale-100"
            leave-to-class="opacity-0 scale-95 translate-x-4"
        >
            <div
                v-for="toast in toasts"
                :key="toast.id"
                class="pointer-events-auto w-full p-4 rounded-2xl shadow-xl border backdrop-blur-md transition-all duration-200 flex items-start space-x-3.5"
                :class="[
                    toast.type === 'success' ? 'bg-white/95 border-emerald-200 text-slate-800 shadow-emerald-500/5' : '',
                    toast.type === 'error' ? 'bg-white/95 border-rose-200 text-slate-800 shadow-rose-500/5' : '',
                    toast.type === 'warning' ? 'bg-white/95 border-amber-200 text-slate-800 shadow-amber-500/5' : '',
                    toast.type === 'info' ? 'bg-white/95 border-indigo-200 text-slate-800 shadow-indigo-500/5' : '',
                ]"
            >
                <!-- Toast Icon -->
                <div class="shrink-0 pt-0.5">
                    <!-- Success Icon -->
                    <div
                        v-if="toast.type === 'success'"
                        class="size-8 rounded-xl bg-emerald-100 text-emerald-600 flex items-center justify-center shadow-xs"
                    >
                        <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                        </svg>
                    </div>

                    <!-- Error Icon -->
                    <div
                        v-else-if="toast.type === 'error'"
                        class="size-8 rounded-xl bg-rose-100 text-rose-600 flex items-center justify-center shadow-xs"
                    >
                        <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
                        </svg>
                    </div>

                    <!-- Warning Icon -->
                    <div
                        v-else-if="toast.type === 'warning'"
                        class="size-8 rounded-xl bg-amber-100 text-amber-600 flex items-center justify-center shadow-xs"
                    >
                        <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m0 3.75h.007v.008H12v-.008zM12 3v.008" />
                        </svg>
                    </div>

                    <!-- Info Icon -->
                    <div
                        v-else
                        class="size-8 rounded-xl bg-indigo-100 text-indigo-600 flex items-center justify-center shadow-xs"
                    >
                        <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z" />
                        </svg>
                    </div>
                </div>

                <!-- Toast Text Content -->
                <div class="flex-1 min-w-0 pr-1">
                    <h4 class="text-sm font-bold text-gray-900 leading-tight">
                        {{ toast.title }}
                    </h4>
                    <p v-if="toast.message" class="text-xs font-medium text-gray-600 mt-1 leading-relaxed break-words">
                        {{ toast.message }}
                    </p>
                </div>

                <!-- Close Button -->
                <button
                    @click="remove(toast.id)"
                    type="button"
                    class="shrink-0 p-1 text-gray-400 hover:text-gray-600 rounded-lg hover:bg-gray-100 transition"
                    aria-label="Dismiss toast"
                >
                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
        </TransitionGroup>
    </div>
</template>

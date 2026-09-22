<script setup>
import { ref, computed } from 'vue';
import { useForm, Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import AdminNav from '@/Components/AdminNav.vue';

const props = defineProps({
    appearance: {
        type: Object,
        required: true,
    },
});

const form = useForm({
    name: props.appearance.name || 'LGUNET Portal',
    logo: null,
    favicon: null,
    remove_logo: false,
    remove_favicon: false,
});

const logoPreview = ref(null);
const faviconPreview = ref(null);
const logoInputRef = ref(null);
const faviconInputRef = ref(null);

const activeLogoUrl = computed(() => {
    if (form.remove_logo) {
        return null;
    }
    if (logoPreview.value) {
        return logoPreview.value;
    }
    return props.appearance.logo_url;
});

const activeFaviconUrl = computed(() => {
    if (form.remove_favicon) {
        return null;
    }
    if (faviconPreview.value) {
        return faviconPreview.value;
    }
    return props.appearance.favicon_url;
});

const onLogoSelected = (event) => {
    const file = event.target.files?.[0];
    if (!file) return;

    form.logo = file;
    form.remove_logo = false;
    logoPreview.value = URL.createObjectURL(file);
};

const onFaviconSelected = (event) => {
    const file = event.target.files?.[0];
    if (!file) return;

    form.favicon = file;
    form.remove_favicon = false;
    faviconPreview.value = URL.createObjectURL(file);
};

const removeLogo = () => {
    form.logo = null;
    logoPreview.value = null;
    form.remove_logo = true;
    if (logoInputRef.value) {
        logoInputRef.value.value = '';
    }
};

const cancelRemoveLogo = () => {
    form.remove_logo = false;
};

const removeFavicon = () => {
    form.favicon = null;
    faviconPreview.value = null;
    form.remove_favicon = true;
    if (faviconInputRef.value) {
        faviconInputRef.value.value = '';
    }
};

const cancelRemoveFavicon = () => {
    form.remove_favicon = false;
};

const submit = () => {
    form.post(route('admin.appearance.update'), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            if (logoPreview.value) {
                URL.revokeObjectURL(logoPreview.value);
                logoPreview.value = null;
            }
            if (faviconPreview.value) {
                URL.revokeObjectURL(faviconPreview.value);
                faviconPreview.value = null;
            }
            form.logo = null;
            form.favicon = null;
            form.remove_logo = false;
            form.remove_favicon = false;
            if (logoInputRef.value) logoInputRef.value.value = '';
            if (faviconInputRef.value) faviconInputRef.value.value = '';
        },
    });
};
</script>

<template>
    <AppLayout title="Admin - System Appearance">
        <template #header>
            <AdminNav />
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                <div>
                    <div class="flex items-center space-x-2">
                        <h2 class="font-bold text-xl text-gray-900 leading-tight">
                            System Appearance
                        </h2>
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-emerald-100 text-emerald-800">
                            Branding & Assets
                        </span>
                    </div>
                    <p class="text-xs text-gray-500 mt-1">
                        Customize the portal branding, system name, application logo, and browser favicon across the system.
                    </p>
                </div>

                <div class="flex items-center space-x-3">
                    <span v-if="form.isDirty" class="inline-flex items-center text-xs font-semibold text-amber-700 bg-amber-50 px-2.5 py-1 rounded-lg border border-amber-200">
                        ● Unsaved Changes
                    </span>
                </div>
            </div>
        </template>

        <div class="py-6">
            <div class="max-w-8xl mx-auto px-4 sm:px-6 lg:px-8">
                <!-- Flash Success Alert -->
                <div
                    v-if="$page.props.flash?.success"
                    class="mb-6 p-4 bg-emerald-50 border border-emerald-200 text-emerald-800 rounded-2xl text-sm font-medium flex items-center justify-between shadow-xs animate-fade-in"
                >
                    <div class="flex items-center space-x-2.5">
                        <svg class="size-5 text-emerald-600 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        <span>{{ $page.props.flash.success }}</span>
                    </div>
                </div>

                <!-- Flash Error Alert -->
                <div
                    v-if="$page.props.flash?.error"
                    class="mb-6 p-4 bg-red-50 border border-red-200 text-red-800 rounded-2xl text-sm font-medium flex items-center justify-between shadow-xs"
                >
                    <div class="flex items-center space-x-2.5">
                        <svg class="size-5 text-red-600 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 7.5h.008v.008H12v-.008z" />
                        </svg>
                        <span>{{ $page.props.flash.error }}</span>
                    </div>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-12 gap-8 items-start">
                    <!-- Left: Appearance Form (7 cols) -->
                    <div class="lg:col-span-7 space-y-6">
                        <form @submit.prevent="submit" class="space-y-6">
                            <!-- 1. System Name Card -->
                            <div class="bg-cream-200 rounded-2xl shadow-xs border border-cream-500/50 p-6 sm:p-7">
                                <div class="flex items-center justify-between mb-4 pb-3 border-b border-cream-400/50">
                                    <div class="flex items-center space-x-2.5">
                                        <div class="size-9 rounded-xl bg-forest-900 text-white flex items-center justify-center font-bold text-sm shadow-xs">
                                            1
                                        </div>
                                        <div>
                                            <h3 class="font-bold text-gray-900 text-base">System Title & Name</h3>
                                            <p class="text-xs text-gray-500">Sets the public title shown across the portal and browser tabs</p>
                                        </div>
                                    </div>
                                </div>

                                <div class="space-y-3">
                                    <label for="system_name" class="block text-xs font-bold text-forest-900 uppercase tracking-wider">
                                        System Name
                                    </label>
                                    <input
                                        id="system_name"
                                        v-model="form.name"
                                        type="text"
                                        required
                                        maxlength="255"
                                        placeholder="e.g. LGUNET Portal or City of San Fernando Portal"
                                        class="w-full px-4 py-2.5 bg-[#fffef9] border border-cream-500 focus:bg-white focus:border-forest-600 focus:ring-2 focus:ring-forest-200 text-gray-900 font-medium text-sm rounded-xl transition shadow-xs"
                                    />
                                    <div v-if="form.errors.name" class="text-xs text-red-600 font-semibold mt-1">
                                        {{ form.errors.name }}
                                    </div>
                                    <p class="text-xs text-gray-500 leading-relaxed">
                                        This name appears in your browser tab title, login page, sidebar, and automated system notifications.
                                    </p>
                                </div>
                            </div>

                            <!-- 2. System Logo Card -->
                            <div class="bg-cream-200 rounded-2xl shadow-xs border border-cream-500/50 p-6 sm:p-7">
                                <div class="flex items-center justify-between mb-4 pb-3 border-b border-cream-400/50">
                                    <div class="flex items-center space-x-2.5">
                                        <div class="size-9 rounded-xl bg-forest-900 text-white flex items-center justify-center font-bold text-sm shadow-xs">
                                            2
                                        </div>
                                        <div>
                                            <h3 class="font-bold text-gray-900 text-base">System Logo</h3>
                                            <p class="text-xs text-gray-500">Official logo displayed in the sidebar header and auth screens</p>
                                        </div>
                                    </div>

                                    <span v-if="activeLogoUrl" class="text-[11px] font-bold px-2 py-0.5 rounded-full bg-emerald-100 text-emerald-800">
                                        Custom Logo Active
                                    </span>
                                    <span v-else class="text-[11px] font-bold px-2 py-0.5 rounded-full bg-gray-200 text-gray-700">
                                        Default Mark
                                    </span>
                                </div>

                                <div class="space-y-4">
                                    <div class="flex flex-col sm:flex-row sm:items-center gap-5 p-4 rounded-xl bg-[#fffef9] border border-cream-400/80">
                                        <!-- Logo Display Box -->
                                        <div class="size-20 rounded-2xl border-2 border-dashed border-forest-800/30 bg-cream-100 flex items-center justify-center p-2 shrink-0 overflow-hidden shadow-inner">
                                            <img
                                                v-if="activeLogoUrl"
                                                :src="activeLogoUrl"
                                                alt="System Logo Preview"
                                                class="max-h-full max-w-full object-contain"
                                            />
                                            <svg
                                                v-else
                                                class="size-10 text-forest-900"
                                                viewBox="0 0 48 48"
                                                fill="none"
                                                xmlns="http://www.w3.org/2000/svg"
                                            >
                                                <path d="M11.395 44.428C4.557 40.198 0 32.632 0 24 0 10.745 10.745 0 24 0a23.891 23.891 0 0113.997 4.502c-.2 17.907-11.097 33.245-26.602 39.926z" fill="#1b4332" />
                                                <path d="M14.134 45.885A23.914 23.914 0 0024 48c13.255 0 24-10.745 24-24 0-3.516-.756-6.856-2.115-9.866-4.659 15.143-16.608 27.092-31.75 31.751z" fill="#1b4332" />
                                            </svg>
                                        </div>

                                        <!-- Controls -->
                                        <div class="flex-1 space-y-2">
                                            <div class="flex items-center space-x-3">
                                                <label class="cursor-pointer inline-flex items-center px-4 py-2 bg-forest-900 hover:bg-forest-950 text-white text-xs font-bold rounded-xl shadow-xs transition duration-150">
                                                    <svg class="size-4 me-1.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5" />
                                                    </svg>
                                                    <span>{{ activeLogoUrl ? 'Change Logo' : 'Upload Logo' }}</span>
                                                    <input
                                                        ref="logoInputRef"
                                                        type="file"
                                                        accept="image/png,image/jpeg,image/svg+xml,image/webp,image/gif"
                                                        class="hidden"
                                                        @change="onLogoSelected"
                                                    />
                                                </label>

                                                <button
                                                    v-if="activeLogoUrl && !form.remove_logo"
                                                    type="button"
                                                    @click="removeLogo"
                                                    class="inline-flex items-center px-3 py-2 bg-red-50 hover:bg-red-100 text-red-700 text-xs font-semibold rounded-xl border border-red-200 transition"
                                                >
                                                    <svg class="size-3.5 me-1" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" />
                                                    </svg>
                                                    Reset to Default
                                                </button>

                                                <button
                                                    v-if="form.remove_logo"
                                                    type="button"
                                                    @click="cancelRemoveLogo"
                                                    class="inline-flex items-center px-3 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 text-xs font-semibold rounded-xl transition"
                                                >
                                                    Undo Reset
                                                </button>
                                            </div>

                                            <p class="text-[11px] text-gray-500">
                                                Recommended: Transparent PNG or SVG, minimum 128x128px (Max 2MB).
                                            </p>
                                        </div>
                                    </div>
                                    <div v-if="form.errors.logo" class="text-xs text-red-600 font-semibold mt-1">
                                        {{ form.errors.logo }}
                                    </div>
                                </div>
                            </div>

                            <!-- 3. System Favicon Card -->
                            <div class="bg-cream-200 rounded-2xl shadow-xs border border-cream-500/50 p-6 sm:p-7">
                                <div class="flex items-center justify-between mb-4 pb-3 border-b border-cream-400/50">
                                    <div class="flex items-center space-x-2.5">
                                        <div class="size-9 rounded-xl bg-forest-900 text-white flex items-center justify-center font-bold text-sm shadow-xs">
                                            3
                                        </div>
                                        <div>
                                            <h3 class="font-bold text-gray-900 text-base">System Favicon</h3>
                                            <p class="text-xs text-gray-500">Icon displayed in browser address tabs, bookmarks, and shortcuts</p>
                                        </div>
                                    </div>

                                    <span v-if="activeFaviconUrl" class="text-[11px] font-bold px-2 py-0.5 rounded-full bg-emerald-100 text-emerald-800">
                                        Custom Favicon
                                    </span>
                                    <span v-else class="text-[11px] font-bold px-2 py-0.5 rounded-full bg-gray-200 text-gray-700">
                                        Default Favicon
                                    </span>
                                </div>

                                <div class="space-y-4">
                                    <div class="flex flex-col sm:flex-row sm:items-center gap-5 p-4 rounded-xl bg-[#fffef9] border border-cream-400/80">
                                        <!-- Favicon Multi-size Display Box -->
                                        <div class="flex items-center space-x-3 p-3 rounded-2xl bg-cream-100 border border-cream-300 shrink-0">
                                            <div class="flex flex-col items-center space-y-1">
                                                <div class="size-8 rounded-lg bg-white border border-gray-200 flex items-center justify-center p-1 shadow-xs overflow-hidden">
                                                    <img
                                                        :src="activeFaviconUrl || '/favicon.ico'"
                                                        alt="32px Favicon"
                                                        class="size-5 object-contain"
                                                    />
                                                </div>
                                                <span class="text-[10px] text-gray-400 font-mono">32px</span>
                                            </div>

                                            <div class="flex flex-col items-center space-y-1">
                                                <div class="size-6 rounded-md bg-white border border-gray-200 flex items-center justify-center p-0.5 shadow-xs overflow-hidden">
                                                    <img
                                                        :src="activeFaviconUrl || '/favicon.ico'"
                                                        alt="16px Favicon"
                                                        class="size-3.5 object-contain"
                                                    />
                                                </div>
                                                <span class="text-[10px] text-gray-400 font-mono">16px</span>
                                            </div>
                                        </div>

                                        <!-- Controls -->
                                        <div class="flex-1 space-y-2">
                                            <div class="flex items-center space-x-3">
                                                <label class="cursor-pointer inline-flex items-center px-4 py-2 bg-forest-900 hover:bg-forest-950 text-white text-xs font-bold rounded-xl shadow-xs transition duration-150">
                                                    <svg class="size-4 me-1.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5" />
                                                    </svg>
                                                    <span>{{ activeFaviconUrl ? 'Change Favicon' : 'Upload Favicon' }}</span>
                                                    <input
                                                        ref="faviconInputRef"
                                                        type="file"
                                                        accept=".ico,image/x-icon,image/png,image/svg+xml,image/webp"
                                                        class="hidden"
                                                        @change="onFaviconSelected"
                                                    />
                                                </label>

                                                <button
                                                    v-if="activeFaviconUrl && !form.remove_favicon"
                                                    type="button"
                                                    @click="removeFavicon"
                                                    class="inline-flex items-center px-3 py-2 bg-red-50 hover:bg-red-100 text-red-700 text-xs font-semibold rounded-xl border border-red-200 transition"
                                                >
                                                    <svg class="size-3.5 me-1" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" />
                                                    </svg>
                                                    Reset to Default
                                                </button>

                                                <button
                                                    v-if="form.remove_favicon"
                                                    type="button"
                                                    @click="cancelRemoveFavicon"
                                                    class="inline-flex items-center px-3 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 text-xs font-semibold rounded-xl transition"
                                                >
                                                    Undo Reset
                                                </button>
                                            </div>

                                            <p class="text-[11px] text-gray-500">
                                                Accepted formats: .ico, .png, .svg (Max 1MB). Square format recommended (e.g. 32x32, 64x64).
                                            </p>
                                        </div>
                                    </div>
                                    <div v-if="form.errors.favicon" class="text-xs text-red-600 font-semibold mt-1">
                                        {{ form.errors.favicon }}
                                    </div>
                                </div>
                            </div>

                            <!-- Submit / Action Footer -->
                            <div class="flex items-center justify-between pt-2">
                                <p class="text-xs text-gray-500">
                                    Changes take effect immediately across all sessions and pages upon saving.
                                </p>

                                <button
                                    type="submit"
                                    :disabled="form.processing"
                                    class="inline-flex items-center px-6 py-3 bg-forest-900 hover:bg-forest-950 disabled:opacity-50 text-white font-bold text-sm rounded-xl shadow-md transition duration-150 cursor-pointer"
                                >
                                    <svg
                                        v-if="form.processing"
                                        class="animate-spin -ml-1 mr-2 size-4 text-white"
                                        fill="none"
                                        viewBox="0 0 24 24"
                                    >
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                                    </svg>
                                    <svg v-else class="size-4 me-2" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                                    </svg>
                                    <span>{{ form.processing ? 'Saving Changes...' : 'Save Appearance' }}</span>
                                </button>
                            </div>
                        </form>
                    </div>

                    <!-- Right: Live Interactive Previews (5 cols) -->
                    <div class="lg:col-span-5 space-y-6">
                        <div class="bg-forest-900 rounded-2xl shadow-lg border border-forest-800 p-6 text-white">
                            <div class="flex items-center justify-between mb-5 pb-3 border-b border-forest-800">
                                <div class="flex items-center space-x-2">
                                    <span class="size-2.5 rounded-full bg-emerald-400 animate-ping"></span>
                                    <h3 class="font-bold text-white text-base">Live Preview</h3>
                                </div>
                                <span class="text-[11px] font-mono uppercase tracking-widest text-emerald-300">
                                    Real-time
                                </span>
                            </div>

                            <div class="space-y-6">
                                <!-- Preview 1: Browser Tab Simulation -->
                                <div>
                                    <div class="text-[11px] font-bold text-emerald-200/80 uppercase tracking-wider mb-2 flex items-center justify-between">
                                        <span>Browser Tab Preview</span>
                                        <span class="text-[10px] text-emerald-300/60 font-normal">Title + Favicon</span>
                                    </div>

                                    <!-- Mock Browser Window Top Bar -->
                                    <div class="bg-[#1f2937] rounded-xl p-2.5 shadow-md border border-gray-700/60">
                                        <div class="flex items-center space-x-1.5 pb-2 border-b border-gray-700/50 mb-2">
                                            <span class="size-2.5 rounded-full bg-red-500"></span>
                                            <span class="size-2.5 rounded-full bg-yellow-500"></span>
                                            <span class="size-2.5 rounded-full bg-green-500"></span>
                                            <span class="text-[10px] text-gray-400 font-mono ml-2 truncate">https://lgunet.local/dashboard</span>
                                        </div>

                                        <!-- The Tab -->
                                        <div class="bg-[#374151] rounded-lg px-3 py-1.5 flex items-center space-x-2 max-w-[280px] shadow-xs">
                                            <img
                                                :src="activeFaviconUrl || '/favicon.ico'"
                                                alt="Favicon"
                                                class="size-4 shrink-0 object-contain rounded"
                                            />
                                            <span class="text-xs font-medium text-gray-100 truncate">
                                                {{ form.name || 'LGUNET Portal' }}
                                            </span>
                                            <svg class="size-3 text-gray-400 shrink-0 ml-auto" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                            </svg>
                                        </div>
                                    </div>
                                </div>

                                <!-- Preview 2: Sidebar Header Simulation -->
                                <div>
                                    <div class="text-[11px] font-bold text-emerald-200/80 uppercase tracking-wider mb-2 flex items-center justify-between">
                                        <span>Sidebar Header Preview</span>
                                        <span class="text-[10px] text-emerald-300/60 font-normal">Logo + Title</span>
                                    </div>

                                    <div class="bg-[#152e22] rounded-xl p-4 border border-forest-700/70 shadow-inner flex items-center justify-between">
                                        <div class="flex items-center space-x-3 min-w-0">
                                            <div class="size-9 rounded-xl bg-forest-800/80 border border-emerald-500/30 flex items-center justify-center p-1.5 shrink-0 overflow-hidden">
                                                <img
                                                    v-if="activeLogoUrl"
                                                    :src="activeLogoUrl"
                                                    alt="Logo Preview"
                                                    class="max-h-full max-w-full object-contain"
                                                />
                                                <svg
                                                    v-else
                                                    class="size-6 text-white"
                                                    viewBox="0 0 48 48"
                                                    fill="none"
                                                    xmlns="http://www.w3.org/2000/svg"
                                                >
                                                    <path d="M11.395 44.428C4.557 40.198 0 32.632 0 24 0 10.745 10.745 0 24 0a23.891 23.891 0 0113.997 4.502c-.2 17.907-11.097 33.245-26.602 39.926z" fill="#52b788" />
                                                    <path d="M14.134 45.885A23.914 23.914 0 0024 48c13.255 0 24-10.745 24-24 0-3.516-.756-6.856-2.115-9.866-4.659 15.143-16.608 27.092-31.75 31.751z" fill="#52b788" />
                                                </svg>
                                            </div>
                                            <div class="truncate">
                                                <span class="block text-sm font-bold text-white tracking-tight truncate">
                                                    {{ form.name || 'LGUNET Portal' }}
                                                </span>
                                                <span class="block text-[10px] text-emerald-300/80 uppercase font-semibold tracking-wider">
                                                    Management System
                                                </span>
                                            </div>
                                        </div>

                                        <div class="p-1 rounded-lg bg-forest-800/60 text-emerald-200">
                                            <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5" />
                                            </svg>
                                        </div>
                                    </div>
                                </div>

                                <!-- Preview 3: Auth Card Header Simulation -->
                                <div>
                                    <div class="text-[11px] font-bold text-emerald-200/80 uppercase tracking-wider mb-2 flex items-center justify-between">
                                        <span>Sign-In Card Preview</span>
                                        <span class="text-[10px] text-emerald-300/60 font-normal">Auth Screen</span>
                                    </div>

                                    <div class="bg-cream-100 rounded-xl p-5 border border-cream-400 text-center text-gray-900 shadow-sm">
                                        <div class="size-14 mx-auto mb-2 flex items-center justify-center">
                                            <img
                                                v-if="activeLogoUrl"
                                                :src="activeLogoUrl"
                                                alt="Auth Card Logo"
                                                class="max-h-full max-w-full object-contain"
                                            />
                                            <svg
                                                v-else
                                                class="size-12 text-forest-900"
                                                viewBox="0 0 48 48"
                                                fill="none"
                                                xmlns="http://www.w3.org/2000/svg"
                                            >
                                                <path d="M11.395 44.428C4.557 40.198 0 32.632 0 24 0 10.745 10.745 0 24 0a23.891 23.891 0 0113.997 4.502c-.2 17.907-11.097 33.245-26.602 39.926z" fill="#1b4332" />
                                                <path d="M14.134 45.885A23.914 23.914 0 0024 48c13.255 0 24-10.745 24-24 0-3.516-.756-6.856-2.115-9.866-4.659 15.143-16.608 27.092-31.75 31.751z" fill="#1b4332" />
                                            </svg>
                                        </div>

                                        <h4 class="text-sm font-bold text-gray-900">
                                            Welcome to {{ form.name || 'LGUNET Portal' }}
                                        </h4>
                                        <p class="text-[11px] text-gray-500 mt-0.5">Enter your credentials to continue</p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- System Information Card -->
                        <div class="bg-cream-200 rounded-2xl shadow-xs border border-cream-500/50 p-5">
                            <h4 class="font-bold text-gray-900 text-xs uppercase tracking-wider mb-2 flex items-center space-x-1.5">
                                <svg class="size-4 text-forest-700" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z" />
                                </svg>
                                <span>Storage & Persistence</span>
                            </h4>
                            <p class="text-xs text-gray-600 leading-relaxed">
                                Uploaded logos and favicons are stored in your secure public storage disk. When a new logo or favicon is uploaded, old assets are automatically cleaned up to prevent orphaned files.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

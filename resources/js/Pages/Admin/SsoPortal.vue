<script setup>
import { ref, computed } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import AdminNav from '@/Components/AdminNav.vue';
import {
    FRAMEWORK_OPTIONS,
    getFrameworkMeta,
    getIntegrationGuide,
    printIntegrationGuide,
    downloadIntegrationPdf,
} from '@/Utils/ssoIntegrationDocs';

const props = defineProps({
    clients: {
        type: Array,
        required: true,
    },
    stats: {
        type: Object,
        required: true,
    },
    frameworks: {
        type: Object,
        default: () => ({}),
    },
    filters: {
        type: Object,
        default: () => ({ search: '', status: 'all' }),
    },
});

const searchQuery = ref(props.filters.search || '');
const statusFilter = ref(props.filters.status || 'all');
const copiedField = ref(null);
const copiedCodeKey = ref(null);

// Modal state
const showModal = ref(false);
const editingClient = ref(null);
const showDeleteConfirm = ref(false);
const clientToDelete = ref(null);
const showRegenerateConfirm = ref(false);
const clientToRegenerate = ref(null);
const visibleSecrets = ref({});

// Documentation Modal state
const showDocsModal = ref(false);
const docsClient = ref(null);
const selectedDocsFramework = ref('laravel_inertia');
const showModalDocsPreview = ref(false);
const docsModalTab = ref('steps'); // 'steps' | 'ai_agent'
const modalPreviewTab = ref('steps'); // 'steps' | 'ai_agent'
const copiedAiPromptKey = ref(null);

const form = useForm({
    name: '',
    client_id: '',
    client_secret: '',
    redirect_uri: '',
    framework: 'laravel_inertia',
    is_active: true,
    icon: null,
    remove_icon: false,
});

const iconPreview = ref(null);
const iconFileInput = ref(null);

const triggerFileInput = () => {
    if (iconFileInput.value) {
        iconFileInput.value.click();
    }
};

const onIconFileChange = (e) => {
    const file = e.target.files[0];
    if (file) {
        form.icon = file;
        form.remove_icon = false;
        const reader = new FileReader();
        reader.onload = (event) => {
            iconPreview.value = event.target.result;
        };
        reader.readAsDataURL(file);
    }
};

const removeIcon = () => {
    form.icon = null;
    form.remove_icon = true;
    iconPreview.value = null;
    if (iconFileInput.value) {
        iconFileInput.value.value = '';
    }
};

const applyFilters = () => {
    router.get(route('admin.sso.index'), {
        search: searchQuery.value || undefined,
        status: statusFilter.value !== 'all' ? statusFilter.value : undefined,
    }, {
        preserveState: true,
        replace: true,
    });
};

const filterByStatus = (status) => {
    statusFilter.value = status;
    applyFilters();
};

const generateRandomString = (length = 40) => {
    const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    let result = '';
    const array = new Uint8Array(length);
    crypto.getRandomValues(array);
    for (let i = 0; i < length; i++) {
        result += chars[array[i] % chars.length];
    }
    return result;
};

const generateClientId = () => {
    form.client_id = 'client_' + generateRandomString(16);
};

const generateClientSecret = () => {
    form.client_secret = generateRandomString(64);
};

const openCreateModal = () => {
    editingClient.value = null;
    form.reset();
    form.clearErrors();
    form.name = '';
    form.client_id = 'client_' + generateRandomString(16);
    form.client_secret = generateRandomString(64);
    form.redirect_uri = '';
    form.framework = 'laravel_inertia';
    form.is_active = true;
    form.icon = null;
    form.remove_icon = false;
    iconPreview.value = null;
    showModalDocsPreview.value = false;
    if (iconFileInput.value) iconFileInput.value.value = '';
    showModal.value = true;
};

const openEditModal = (client) => {
    editingClient.value = client;
    form.clearErrors();
    form.name = client.name;
    form.client_id = client.client_id;
    form.client_secret = client.client_secret;
    form.redirect_uri = client.redirect_uri;
    form.framework = client.framework || 'laravel_inertia';
    form.is_active = !!client.is_active;
    form.icon = null;
    form.remove_icon = false;
    iconPreview.value = client.icon_url || null;
    showModalDocsPreview.value = false;
    if (iconFileInput.value) iconFileInput.value.value = '';
    showModal.value = true;
};

const closeModal = () => {
    showModal.value = false;
    editingClient.value = null;
    form.reset();
    form.clearErrors();
    iconPreview.value = null;
    form.icon = null;
    form.remove_icon = false;
    form.framework = 'laravel_inertia';
    showModalDocsPreview.value = false;
    if (iconFileInput.value) iconFileInput.value.value = '';
};

const submitForm = () => {
    if (editingClient.value) {
        form.transform((data) => ({
            ...data,
            _method: 'PUT',
        })).post(route('admin.sso.update', editingClient.value.id), {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => closeModal(),
        });
    } else {
        form.post(route('admin.sso.store'), {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => closeModal(),
        });
    }
};

const toggleClient = (client) => {
    router.post(route('admin.sso.toggle', client.id), {}, {
        preserveScroll: true,
    });
};

const confirmRegenerate = (client) => {
    clientToRegenerate.value = client;
    showRegenerateConfirm.value = true;
};

const executeRegenerateSecret = () => {
    if (!clientToRegenerate.value) return;
    router.post(route('admin.sso.regenerate-secret', clientToRegenerate.value.id), {}, {
        preserveScroll: true,
        onSuccess: () => {
            showRegenerateConfirm.value = false;
            clientToRegenerate.value = null;
        },
    });
};

const confirmDelete = (client) => {
    clientToDelete.value = client;
    showDeleteConfirm.value = true;
};

const executeDelete = () => {
    if (!clientToDelete.value) return;
    router.delete(route('admin.sso.destroy', clientToDelete.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            showDeleteConfirm.value = false;
            clientToDelete.value = null;
        },
    });
};

const toggleSecretVisibility = (clientId) => {
    visibleSecrets.value[clientId] = !visibleSecrets.value[clientId];
};

const copyToClipboard = async (text, fieldKey) => {
    try {
        await navigator.clipboard.writeText(text);
        copiedField.value = fieldKey;
        setTimeout(() => {
            if (copiedField.value === fieldKey) {
                copiedField.value = null;
            }
        }, 2000);
    } catch (err) {
        console.error('Failed to copy text: ', err);
    }
};

const openDocsModal = (client) => {
    docsClient.value = client;
    selectedDocsFramework.value = client.framework || 'laravel_inertia';
    docsModalTab.value = 'steps';
    showDocsModal.value = true;
};

const closeDocsModal = () => {
    showDocsModal.value = false;
    docsClient.value = null;
    docsModalTab.value = 'steps';
};

const copyAiPromptToClipboard = async (promptText, key) => {
    try {
        await navigator.clipboard.writeText(promptText);
        copiedAiPromptKey.value = key;
        setTimeout(() => {
            if (copiedAiPromptKey.value === key) {
                copiedAiPromptKey.value = null;
            }
        }, 2000);
    } catch (err) {
        console.error('Failed to copy AI prompt: ', err);
    }
};

const activeClientDocData = computed(() => {
    if (!docsClient.value) return null;
    return getIntegrationGuide({
        clientName: docsClient.value.name,
        clientId: docsClient.value.client_id,
        clientSecret: docsClient.value.client_secret,
        redirectUri: docsClient.value.redirect_uri,
        frameworkKey: selectedDocsFramework.value,
        portalUrl: window?.location?.origin || 'http://localhost:8000',
    });
});

const activeModalDocData = computed(() => {
    return getIntegrationGuide({
        clientName: form.name || 'New Application',
        clientId: form.client_id || 'client_id',
        clientSecret: form.client_secret || 'client_secret',
        redirectUri: form.redirect_uri || 'http://localhost:8001/sso/callback',
        frameworkKey: form.framework || 'laravel_inertia',
        portalUrl: window?.location?.origin || 'http://localhost:8000',
    });
});

const copyCodeToClipboard = async (code, key) => {
    try {
        await navigator.clipboard.writeText(code);
        copiedCodeKey.value = key;
        setTimeout(() => {
            if (copiedCodeKey.value === key) {
                copiedCodeKey.value = null;
            }
        }, 2000);
    } catch (err) {
        console.error('Failed to copy code: ', err);
    }
};

const downloadDocsPdf = (docData) => {
    if (docData) {
        downloadIntegrationPdf(docData);
    }
};

const printDocsPdf = (docData) => {
    if (docData) {
        printIntegrationGuide(docData);
    }
};
</script>

<template>
    <AppLayout title="Admin - SSO Portal">
        <template #header>
            <AdminNav />
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h2 class="font-bold text-xl text-gray-900 leading-tight">
                        SSO Portal Management
                    </h2>
                    <p class="text-xs text-gray-600 mt-0.5">
                        Manage dynamic Single Sign-On (SSO) client credentials, redirect URIs, and access tokens for connected portals.
                    </p>
                </div>

                <button
                    @click="openCreateModal"
                    class="inline-flex items-center justify-center px-4 py-2 bg-forest-900 hover:bg-forest-950 text-white text-sm font-semibold rounded-xl shadow-xs transition"
                >
                    <svg class="size-4 me-1.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                    </svg>
                    Register SSO Client
                </button>
            </div>
        </template>

        <div class="py-6">
            <div class="max-w-8xl mx-auto px-4 sm:px-6 lg:px-8">

                <!-- Flash Alerts -->
                <div v-if="$page.props.flash?.success" class="mb-6 p-4 bg-emerald-50 border border-emerald-300 text-emerald-900 rounded-xl text-sm font-medium flex items-center shadow-xs">
                    <svg class="size-5 me-2 text-emerald-600 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    {{ $page.props.flash.success }}
                </div>

                <div v-if="$page.props.flash?.error" class="mb-6 p-4 bg-red-50 border border-red-300 text-red-900 rounded-xl text-sm font-medium flex items-center shadow-xs">
                    <svg class="size-5 me-2 text-red-600 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" />
                    </svg>
                    {{ $page.props.flash.error }}
                </div>

                <!-- Stats Overview Row -->
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-5 mb-6">
                    <div class="bg-cream-200 p-5 rounded-2xl shadow-xs border border-cream-500/50 flex items-center space-x-4">
                        <div class="p-3 bg-cream-100 text-forest-900 rounded-xl shrink-0">
                            <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 5.25a3 3 0 013 3m3 0a6 6 0 01-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 1121.75 8.25z" />
                            </svg>
                        </div>
                        <div>
                            <div class="text-xs text-gray-600 font-medium">Configured Clients</div>
                            <div class="text-2xl font-bold text-gray-900 mt-0.5">{{ stats.total_clients }}</div>
                        </div>
                    </div>

                    <div class="bg-cream-200 p-5 rounded-2xl shadow-xs border border-cream-500/50 flex items-center space-x-4">
                        <div class="p-3 bg-lime-200 text-forest-900 rounded-xl shrink-0">
                            <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                        </div>
                        <div>
                            <div class="text-xs text-gray-600 font-medium">Active Integrations</div>
                            <div class="text-2xl font-bold text-forest-800 mt-0.5">{{ stats.active_clients }}</div>
                        </div>
                    </div>

                    <div class="bg-cream-200 p-5 rounded-2xl shadow-xs border border-cream-500/50 flex items-center space-x-4">
                        <div class="p-3 bg-amber-100 text-amber-800 rounded-xl shrink-0">
                            <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636" />
                            </svg>
                        </div>
                        <div>
                            <div class="text-xs text-gray-600 font-medium">Disabled Clients</div>
                            <div class="text-2xl font-bold text-gray-700 mt-0.5">{{ stats.inactive_clients }}</div>
                        </div>
                    </div>
                </div>

                <!-- Search and Filters Bar -->
                <div class="bg-cream-200 p-4 rounded-2xl shadow-xs border border-cream-500/50 mb-6 flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                    <div class="flex flex-wrap items-center gap-2">
                        <button
                            @click="filterByStatus('all')"
                            class="px-3.5 py-1.5 text-xs font-semibold rounded-lg transition"
                            :class="statusFilter === 'all' ? 'bg-forest-900 text-white shadow-xs' : 'bg-cream-100 text-gray-700 hover:bg-cream-300'"
                        >
                            All Clients ({{ stats.total_clients }})
                        </button>
                        <button
                            @click="filterByStatus('active')"
                            class="px-3.5 py-1.5 text-xs font-semibold rounded-lg transition"
                            :class="statusFilter === 'active' ? 'bg-forest-900 text-white shadow-xs' : 'bg-cream-100 text-gray-700 hover:bg-cream-300'"
                        >
                            Active ({{ stats.active_clients }})
                        </button>
                        <button
                            @click="filterByStatus('inactive')"
                            class="px-3.5 py-1.5 text-xs font-semibold rounded-lg transition"
                            :class="statusFilter === 'inactive' ? 'bg-forest-900 text-white shadow-xs' : 'bg-cream-100 text-gray-700 hover:bg-cream-300'"
                        >
                            Disabled ({{ stats.inactive_clients }})
                        </button>
                    </div>

                    <div class="relative w-full md:w-72">
                        <input
                            v-model="searchQuery"
                            @keyup.enter="applyFilters"
                            type="text"
                            placeholder="Search client name or ID..."
                            class="w-full bg-cream-100 border border-cream-400/80 rounded-xl px-3.5 py-2 pl-9 text-xs text-gray-800 placeholder-gray-500 focus:ring-2 focus:ring-forest-800 focus:border-forest-800"
                        />
                        <svg class="size-4 text-gray-400 absolute left-3 top-2.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
                        </svg>
                    </div>
                </div>

                <!-- Client List Grid -->
                <div v-if="clients.length > 0" class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <div
                        v-for="client in clients"
                        :key="client.id"
                        class="bg-cream-200 rounded-2xl shadow-xs border border-cream-500/60 p-6 flex flex-col justify-between hover:shadow-md transition duration-200"
                    >
                        <div>
                            <!-- Header: Name, Active Toggle & Status Badge -->
                            <div class="flex items-start justify-between gap-3 mb-4">
                                <div class="flex items-center space-x-3">
                                    <div v-if="client.icon_url" class="size-11 rounded-xl bg-white p-1 border border-cream-400/60 shadow-2xs flex items-center justify-center overflow-hidden shrink-0">
                                        <img :src="client.icon_url" :alt="client.name" class="w-full h-full object-contain rounded-lg" />
                                    </div>
                                    <div v-else class="p-2.5 bg-forest-900/10 text-forest-900 rounded-xl border border-forest-900/15 shrink-0">
                                        <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 011.242 7.244l-4.5 4.5a4.5 4.5 0 01-6.364-6.364l1.757-1.757m13.35-.622l1.757-1.757a4.5 4.5 0 00-6.364-6.364l-4.5 4.5a4.5 4.5 0 001.242 7.244" />
                                        </svg>
                                    </div>
                                    <div>
                                        <h3 class="font-bold text-gray-900 text-base leading-snug">{{ client.name }}</h3>
                                        <div class="flex items-center flex-wrap gap-1.5 mt-1">
                                            <span
                                                class="inline-flex items-center px-2 py-0.5 text-[11px] font-semibold rounded-md"
                                                :class="client.is_active ? 'bg-emerald-100 text-emerald-800 border border-emerald-300' : 'bg-gray-100 text-gray-600 border border-gray-300'"
                                            >
                                                <span class="size-1.5 rounded-full me-1.5" :class="client.is_active ? 'bg-emerald-500 animate-pulse' : 'bg-gray-400'"></span>
                                                {{ client.is_active ? 'Active SSO Client' : 'Disabled' }}
                                            </span>
                                            <span
                                                class="inline-flex items-center px-2 py-0.5 text-[11px] font-semibold rounded-md border"
                                                :class="getFrameworkMeta(client.framework).badgeColor"
                                            >
                                                <span class="me-1">{{ getFrameworkMeta(client.framework).icon }}</span>
                                                {{ getFrameworkMeta(client.framework).shortName }}
                                            </span>
                                        </div>
                                    </div>
                                </div>

                                <!-- Toggle Button -->
                                <button
                                    @click="toggleClient(client)"
                                    type="button"
                                    class="relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-forest-800"
                                    :class="client.is_active ? 'bg-forest-900' : 'bg-gray-300'"
                                    :title="client.is_active ? 'Click to disable' : 'Click to activate'"
                                >
                                    <span
                                        aria-hidden="true"
                                        class="pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow-xs ring-0 transition duration-200 ease-in-out"
                                        :class="client.is_active ? 'translate-x-5' : 'translate-x-0'"
                                    />
                                </button>
                            </div>

                            <!-- Credentials Box -->
                            <div class="space-y-3 bg-cream-100/70 p-4 rounded-xl border border-cream-400/60 mb-5">
                                <!-- Client ID -->
                                <div>
                                    <div class="flex items-center justify-between text-xs text-gray-600 font-semibold mb-1">
                                        <span>CLIENT ID</span>
                                        <button
                                            @click="copyToClipboard(client.client_id, 'id_' + client.id)"
                                            type="button"
                                            class="text-forest-800 hover:text-forest-950 font-medium text-[11px] flex items-center space-x-1 transition"
                                        >
                                            <svg v-if="copiedField === 'id_' + client.id" class="size-3 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                                            </svg>
                                            <svg v-else class="size-3" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M15.666 3.888A2.25 2.25 0 0013.5 2.25h-3c-1.03 0-1.9.693-2.166 1.638m7.332 0c.055.194.084.4.084.612v0a.75.75 0 01-.75.75H9a.75.75 0 01-.75-.75v0c0-.212.03-.418.084-.612m7.332 0c.646.049 1.288.11 1.927.184 1.1.128 1.907 1.077 1.907 2.185V19.5a2.25 2.25 0 01-2.25 2.25H6.75A2.25 2.25 0 014.5 19.5V6.257c0-1.108.806-2.057 1.907-2.185a48.208 48.208 0 011.927-.184" />
                                            </svg>
                                            <span>{{ copiedField === 'id_' + client.id ? 'Copied!' : 'Copy' }}</span>
                                        </button>
                                    </div>
                                    <div class="font-mono text-xs text-gray-900 bg-cream-200/80 px-3 py-1.5 rounded-lg border border-cream-400/50 break-all select-all">
                                        {{ client.client_id }}
                                    </div>
                                </div>

                                <!-- Client Secret -->
                                <div>
                                    <div class="flex items-center justify-between text-xs text-gray-600 font-semibold mb-1">
                                        <span>CLIENT SECRET</span>
                                        <div class="flex items-center space-x-2">
                                            <button
                                                @click="toggleSecretVisibility(client.id)"
                                                type="button"
                                                class="text-gray-600 hover:text-gray-900 font-medium text-[11px] flex items-center space-x-1 transition"
                                            >
                                                <span>{{ visibleSecrets[client.id] ? 'Hide' : 'Reveal' }}</span>
                                            </button>
                                            <span class="text-gray-300">|</span>
                                            <button
                                                @click="copyToClipboard(client.client_secret, 'secret_' + client.id)"
                                                type="button"
                                                class="text-forest-800 hover:text-forest-950 font-medium text-[11px] flex items-center space-x-1 transition"
                                            >
                                                <svg v-if="copiedField === 'secret_' + client.id" class="size-3 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                                                </svg>
                                                <svg v-else class="size-3" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.666 3.888A2.25 2.25 0 0013.5 2.25h-3c-1.03 0-1.9.693-2.166 1.638m7.332 0c.055.194.084.4.084.612v0a.75.75 0 01-.75.75H9a.75.75 0 01-.75-.75v0c0-.212.03-.418.084-.612m7.332 0c.646.049 1.288.11 1.927.184 1.1.128 1.907 1.077 1.907 2.185V19.5a2.25 2.25 0 01-2.25 2.25H6.75A2.25 2.25 0 014.5 19.5V6.257c0-1.108.806-2.057 1.907-2.185a48.208 48.208 0 011.927-.184" />
                                                </svg>
                                                <span>{{ copiedField === 'secret_' + client.id ? 'Copied!' : 'Copy' }}</span>
                                            </button>
                                        </div>
                                    </div>
                                    <div class="font-mono text-xs text-gray-900 bg-cream-200/80 px-3 py-1.5 rounded-lg border border-cream-400/50 break-all select-all flex items-center justify-between">
                                        <span v-if="visibleSecrets[client.id]">{{ client.client_secret }}</span>
                                        <span v-else class="text-gray-500 tracking-wider">••••••••••••••••••••••••••••••••</span>
                                    </div>
                                </div>

                                <!-- Redirect URI -->
                                <div>
                                    <div class="flex items-center justify-between text-xs text-gray-600 font-semibold mb-1">
                                        <span>REDIRECT URI</span>
                                        <button
                                            @click="copyToClipboard(client.redirect_uri, 'uri_' + client.id)"
                                            type="button"
                                            class="text-forest-800 hover:text-forest-950 font-medium text-[11px] flex items-center space-x-1 transition"
                                        >
                                            <svg v-if="copiedField === 'uri_' + client.id" class="size-3 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                                            </svg>
                                            <svg v-else class="size-3" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M15.666 3.888A2.25 2.25 0 0013.5 2.25h-3c-1.03 0-1.9.693-2.166 1.638m7.332 0c.055.194.084.4.084.612v0a.75.75 0 01-.75.75H9a.75.75 0 01-.75-.75v0c0-.212.03-.418.084-.612m7.332 0c.646.049 1.288.11 1.927.184 1.1.128 1.907 1.077 1.907 2.185V19.5a2.25 2.25 0 01-2.25 2.25H6.75A2.25 2.25 0 014.5 19.5V6.257c0-1.108.806-2.057 1.907-2.185a48.208 48.208 0 011.927-.184" />
                                            </svg>
                                            <span>{{ copiedField === 'uri_' + client.id ? 'Copied!' : 'Copy' }}</span>
                                        </button>
                                    </div>
                                    <div class="font-mono text-xs text-emerald-800 bg-cream-200/80 px-3 py-1.5 rounded-lg border border-cream-400/50 break-all select-all">
                                        {{ client.redirect_uri }}
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Card Action Footer -->
                        <div class="flex flex-wrap items-center justify-between gap-2 border-t border-cream-400/60 pt-4 mt-2">
                            <div class="flex items-center space-x-2">
                                <button
                                    @click="openDocsModal(client)"
                                    type="button"
                                    class="inline-flex items-center text-xs font-semibold text-forest-800 hover:text-forest-950 bg-forest-50 hover:bg-forest-100 px-2.5 py-1.5 rounded-lg border border-forest-200 transition"
                                    title="View full step-by-step SSO setup & download PDF"
                                >
                                    <svg class="size-3.5 me-1 text-forest-700" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.967 8.967 0 00-6 2.292m0-14.25v14.25" />
                                    </svg>
                                    Integration Guide
                                </button>

                                <button
                                    @click="confirmRegenerate(client)"
                                    type="button"
                                    class="inline-flex items-center text-xs font-semibold text-amber-800 hover:text-amber-950 transition px-2 py-1.5"
                                >
                                    <svg class="size-3.5 me-1.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99" />
                                    </svg>
                                    Regenerate
                                </button>
                            </div>

                            <div class="flex items-center space-x-2">
                                <button
                                    @click="openEditModal(client)"
                                    type="button"
                                    class="px-3 py-1.5 text-xs font-semibold bg-cream-100 hover:bg-cream-300 text-gray-800 rounded-lg border border-cream-400 transition"
                                >
                                    Edit
                                </button>
                                <button
                                    @click="confirmDelete(client)"
                                    type="button"
                                    class="px-3 py-1.5 text-xs font-semibold bg-red-50 hover:bg-red-100 text-red-700 rounded-lg border border-red-200 transition"
                                >
                                    Delete
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Empty State -->
                <div v-else class="bg-cream-200 rounded-2xl border border-cream-500/50 p-12 text-center">
                    <div class="size-16 mx-auto bg-cream-300 text-forest-900 rounded-2xl flex items-center justify-center mb-4">
                        <svg class="size-8" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 5.25a3 3 0 013 3m3 0a6 6 0 01-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 1121.75 8.25z" />
                        </svg>
                    </div>
                    <h3 class="text-base font-bold text-gray-900 mb-1">No SSO Clients Found</h3>
                    <p class="text-xs text-gray-600 max-w-sm mx-auto mb-6">
                        No SSO clients match your active filters. Register a new client application to enable unified authentication across portals.
                    </p>
                    <button
                        @click="openCreateModal"
                        class="inline-flex items-center px-4 py-2 bg-forest-900 hover:bg-forest-950 text-white text-xs font-semibold rounded-xl transition"
                    >
                        Register First SSO Client
                    </button>
                </div>
            </div>
        </div>

        <!-- Create/Edit Modal -->
        <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-xs">
            <div class="bg-cream-200 rounded-2xl shadow-xl border border-cream-500 w-full max-w-2xl max-h-[92vh] flex flex-col overflow-hidden">
                <div class="flex items-center justify-between p-6 pb-4 border-b border-cream-400/60 shrink-0">
                    <div>
                        <h3 class="text-lg font-bold text-gray-900">
                            {{ editingClient ? 'Edit SSO Client' : 'Register New SSO Client' }}
                        </h3>
                        <p class="text-xs text-gray-600 mt-0.5">
                            Enroll client applications and generate framework-specific integration guides.
                        </p>
                    </div>
                    <button @click="closeModal" class="text-gray-400 hover:text-gray-600 p-1">
                        <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <form @submit.prevent="submitForm" class="p-6 overflow-y-auto space-y-4">
                    <!-- Client Name -->
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1">Client / Portal Name</label>
                        <input
                            v-model="form.name"
                            type="text"
                            placeholder="e.g. LFEWS 2.0 or Project Tracker"
                            class="w-full bg-cream-100 border border-cream-400 rounded-xl px-3.5 py-2 text-sm text-gray-900 focus:ring-2 focus:ring-forest-800"
                            required
                        />
                        <div v-if="form.errors.name" class="text-red-600 text-xs mt-1">{{ form.errors.name }}</div>
                    </div>

                    <!-- Client Icon Upload -->
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1">SSO Client Icon (Displayed in Dashboard)</label>
                        <div class="flex items-center space-x-4">
                            <!-- Preview Box -->
                            <div class="size-14 rounded-2xl bg-cream-100 border border-cream-400/80 shadow-2xs flex items-center justify-center overflow-hidden shrink-0">
                                <img
                                    v-if="iconPreview"
                                    :src="iconPreview"
                                    alt="Client Icon Preview"
                                    class="w-full h-full object-contain p-1 rounded-xl"
                                />
                                <svg v-else class="size-6 text-gray-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 001.5-1.5V6a1.5 1.5 0 00-1.5-1.5H3.75A1.5 1.5 0 002.25 6v12a1.5 1.5 0 001.5 1.5zm10.5-11.25h.008v.008h-.008V8.25zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z" />
                                </svg>
                            </div>

                            <!-- Upload & Remove Buttons -->
                            <div class="space-y-1">
                                <input
                                    ref="iconFileInput"
                                    type="file"
                                    accept="image/png,image/jpeg,image/jpg,image/svg+xml,image/webp"
                                    @change="onIconFileChange"
                                    class="hidden"
                                />
                                <div class="flex items-center space-x-2">
                                    <button
                                        @click="triggerFileInput"
                                        type="button"
                                        class="px-3 py-1.5 text-xs font-semibold bg-cream-100 hover:bg-cream-300 text-gray-800 rounded-xl border border-cream-400 transition inline-flex items-center space-x-1.5 cursor-pointer"
                                    >
                                        <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5" />
                                        </svg>
                                        <span>{{ iconPreview ? 'Change Icon' : 'Upload Icon' }}</span>
                                    </button>
                                    <button
                                        v-if="iconPreview"
                                        @click="removeIcon"
                                        type="button"
                                        class="px-2.5 py-1.5 text-xs font-semibold text-red-600 hover:text-red-800 transition cursor-pointer"
                                    >
                                        Remove
                                    </button>
                                </div>
                                <p class="text-[11px] text-gray-500">
                                    PNG, JPG, SVG or WebP (max 2MB).
                                </p>
                            </div>
                        </div>
                        <div v-if="form.errors.icon" class="text-red-600 text-xs mt-1">{{ form.errors.icon }}</div>
                    </div>

                    <!-- Target Framework / Programming Language Selection -->
                    <div>
                        <div class="flex items-center justify-between mb-1">
                            <label class="block text-xs font-semibold text-gray-700">
                                Target Framework / Programming Language
                            </label>
                            <span class="text-[11px] font-medium text-forest-800">
                                Step-by-step instructions adapt to this selection
                            </span>
                        </div>
                        <select
                            v-model="form.framework"
                            class="w-full bg-cream-100 border border-cream-400 rounded-xl px-3.5 py-2.5 text-sm font-medium text-gray-900 focus:ring-2 focus:ring-forest-800"
                        >
                            <option v-for="fw in FRAMEWORK_OPTIONS" :key="fw.key" :value="fw.key">
                                {{ fw.icon }} {{ fw.name }}
                            </option>
                        </select>
                        <div v-if="form.errors.framework" class="text-red-600 text-xs mt-1">{{ form.errors.framework }}</div>
                        <p class="text-[11px] text-gray-500 mt-1">
                            {{ getFrameworkMeta(form.framework).description }}
                        </p>
                    </div>

                    <!-- Client ID -->
                    <div>
                        <div class="flex items-center justify-between mb-1">
                            <label class="block text-xs font-semibold text-gray-700">Client ID</label>
                            <button
                                @click="generateClientId"
                                type="button"
                                class="text-[11px] font-semibold text-forest-800 hover:text-forest-950"
                            >
                                Generate New
                            </button>
                        </div>
                        <input
                            v-model="form.client_id"
                            type="text"
                            placeholder="e.g. lfews-portal"
                            class="w-full font-mono bg-cream-100 border border-cream-400 rounded-xl px-3.5 py-2 text-sm text-gray-900 focus:ring-2 focus:ring-forest-800"
                            required
                        />
                        <div v-if="form.errors.client_id" class="text-red-600 text-xs mt-1">{{ form.errors.client_id }}</div>
                    </div>

                    <!-- Client Secret -->
                    <div>
                        <div class="flex items-center justify-between mb-1">
                            <label class="block text-xs font-semibold text-gray-700">Client Secret</label>
                            <button
                                @click="generateClientSecret"
                                type="button"
                                class="text-[11px] font-semibold text-forest-800 hover:text-forest-950"
                            >
                                Generate Random
                            </button>
                        </div>
                        <input
                            v-model="form.client_secret"
                            type="text"
                            placeholder="64-character secret key"
                            class="w-full font-mono bg-cream-100 border border-cream-400 rounded-xl px-3.5 py-2 text-sm text-gray-900 focus:ring-2 focus:ring-forest-800"
                            required
                        />
                        <div v-if="form.errors.client_secret" class="text-red-600 text-xs mt-1">{{ form.errors.client_secret }}</div>
                    </div>

                    <!-- Redirect URI -->
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1">Redirect URI</label>
                        <input
                            v-model="form.redirect_uri"
                            type="url"
                            placeholder="https://portal.example.com/sso/callback"
                            class="w-full font-mono bg-cream-100 border border-cream-400 rounded-xl px-3.5 py-2 text-sm text-gray-900 focus:ring-2 focus:ring-forest-800"
                            required
                        />
                        <div v-if="form.errors.redirect_uri" class="text-red-600 text-xs mt-1">{{ form.errors.redirect_uri }}</div>
                        <p class="text-[11px] text-gray-500 mt-1">Must be an authorized endpoint on the client application receiving SSO tokens.</p>
                    </div>

                    <!-- Collapsible Step-by-Step Integration Guide Preview -->
                    <div class="bg-cream-100/90 rounded-xl border border-cream-400/80 p-3.5">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center space-x-2">
                                <span class="text-lg">{{ getFrameworkMeta(form.framework).icon }}</span>
                                <div>
                                    <div class="text-xs font-bold text-gray-900">
                                        Step-by-Step Guide for {{ getFrameworkMeta(form.framework).shortName }}
                                    </div>
                                    <div class="text-[11px] text-gray-500">
                                        Code snippets dynamically update with your credentials above.
                                    </div>
                                </div>
                            </div>
                            <div class="flex items-center space-x-2">
                                <button
                                    @click.prevent="downloadDocsPdf(activeModalDocData)"
                                    type="button"
                                    class="px-2.5 py-1 text-[11px] font-semibold text-forest-900 bg-white hover:bg-forest-50 rounded-lg border border-forest-300/80 transition flex items-center space-x-1 cursor-pointer"
                                    title="Download PDF containing BOTH Step-by-Step Instructions & AI Agent Prompt"
                                >
                                    <svg class="size-3 text-forest-700" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" />
                                    </svg>
                                    <span>Download PDF (Step-by-Step + AI Prompt)</span>
                                </button>
                                <button
                                    @click.prevent="showModalDocsPreview = !showModalDocsPreview"
                                    type="button"
                                    class="px-2.5 py-1 text-[11px] font-semibold text-gray-700 bg-white hover:bg-cream-200 rounded-lg border border-cream-400 transition cursor-pointer"
                                >
                                    {{ showModalDocsPreview ? 'Hide Snippets' : 'Preview Steps' }}
                                </button>
                            </div>
                        </div>

                        <!-- Expanded Content with Mode Switcher -->
                        <div v-if="showModalDocsPreview && activeModalDocData" class="mt-3.5 pt-3.5 border-t border-cream-300 space-y-3">
                            <!-- Tabs: Steps vs AI Agent -->
                            <div class="flex items-center space-x-2 bg-cream-200/90 p-1 rounded-xl border border-cream-300/80 w-fit">
                                <button
                                    @click.prevent="modalPreviewTab = 'steps'"
                                    type="button"
                                    class="px-3 py-1.5 text-xs font-semibold rounded-lg transition cursor-pointer"
                                    :class="modalPreviewTab === 'steps' ? 'bg-forest-900 text-white shadow-xs' : 'text-gray-700 hover:bg-cream-100'"
                                >
                                    📋 Step-by-Step Instructions
                                </button>
                                <button
                                    @click.prevent="modalPreviewTab = 'ai_agent'"
                                    type="button"
                                    class="px-3 py-1.5 text-xs font-semibold rounded-lg transition flex items-center space-x-1.5 cursor-pointer"
                                    :class="modalPreviewTab === 'ai_agent' ? 'bg-forest-900 text-white shadow-xs' : 'text-gray-700 hover:bg-cream-100'"
                                >
                                    <span>🤖</span>
                                    <span>Integration using AI Agent</span>
                                </button>
                            </div>

                            <!-- Option 1: Step-by-step Steps -->
                            <div v-if="modalPreviewTab === 'steps'" class="space-y-3">
                                <div
                                    v-for="(step, sIdx) in activeModalDocData.steps"
                                    :key="sIdx"
                                    class="bg-white rounded-xl border border-cream-300 p-3 shadow-2xs"
                                >
                                    <div class="flex items-center justify-between mb-1">
                                        <span class="text-xs font-bold text-gray-900">
                                            Step {{ sIdx + 1 }}: {{ step.title }}
                                        </span>
                                        <button
                                            v-if="step.code"
                                            @click.prevent="copyCodeToClipboard(step.code, 'modal_step_' + sIdx)"
                                            type="button"
                                            class="text-[11px] font-semibold text-forest-800 hover:text-forest-950 flex items-center space-x-1 cursor-pointer"
                                        >
                                            <span>{{ copiedCodeKey === 'modal_step_' + sIdx ? 'Copied!' : 'Copy Code' }}</span>
                                        </button>
                                    </div>
                                    <p class="text-[11px] text-gray-600 mb-2 leading-relaxed">{{ step.description }}</p>
                                    <pre v-if="step.code" class="bg-gray-900 text-gray-100 text-[11px] font-mono p-2.5 rounded-lg overflow-x-auto select-all leading-relaxed whitespace-pre-wrap"><code>{{ step.code }}</code></pre>
                                </div>
                            </div>

                            <!-- Option 2: AI Agent Prompt -->
                            <div v-else-if="modalPreviewTab === 'ai_agent'" class="space-y-3">
                                <div class="bg-white rounded-xl border border-cream-300 p-4 shadow-2xs space-y-3">
                                    <div class="flex items-center justify-between">
                                        <div class="flex items-center space-x-2">
                                            <span class="p-1.5 bg-forest-900/10 text-forest-900 rounded-lg text-sm">🤖</span>
                                            <div>
                                                <div class="text-xs font-bold text-gray-900">
                                                    Prompt for AI Coding Agent
                                                </div>
                                                <div class="text-[11px] text-gray-500">
                                                    Tailored for {{ getFrameworkMeta(form.framework).name }}
                                                </div>
                                            </div>
                                        </div>
                                        <button
                                            @click.prevent="copyAiPromptToClipboard(activeModalDocData.aiPrompt, 'modal_preview_ai')"
                                            type="button"
                                            class="px-3 py-1 text-xs font-semibold text-forest-900 bg-cream-100 hover:bg-forest-50 rounded-lg border border-cream-300 transition flex items-center space-x-1.5 cursor-pointer shadow-2xs"
                                        >
                                            <svg v-if="copiedAiPromptKey === 'modal_preview_ai'" class="size-3.5 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                                            </svg>
                                            <svg v-else class="size-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M15.666 3.888A2.25 2.25 0 0013.5 2.25h-3c-1.03 0-1.9.693-2.166 1.638m7.332 0c.055.194.084.4.084.612v0a.75.75 0 01-.75.75H9a.75.75 0 01-.75-.75v0c0-.212.03-.418.084-.612m7.332 0c.646.049 1.288.11 1.927.184 1.1.128 1.907 1.077 1.907 2.185V19.5a2.25 2.25 0 01-2.25 2.25H6.75A2.25 2.25 0 014.5 19.5V6.257c0-1.108.806-2.057 1.907-2.185a48.208 48.208 0 011.927-.184" />
                                            </svg>
                                            <span>{{ copiedAiPromptKey === 'modal_preview_ai' ? 'Copied!' : 'Copy AI Prompt' }}</span>
                                        </button>
                                    </div>
                                    <p class="text-[11px] text-gray-600 leading-relaxed bg-cream-50 p-2.5 rounded-lg border border-cream-200">
                                        Paste this prompt into your AI coding assistant (Google Antigravity, Claude Code, Cursor, GitHub Copilot, ChatGPT) to autonomously implement the SSO integration end-to-end.
                                    </p>
                                    <pre class="bg-gray-900 text-gray-100 text-[11px] font-mono p-3 rounded-lg overflow-x-auto select-all leading-relaxed whitespace-pre-wrap"><code>{{ activeModalDocData.aiPrompt }}</code></pre>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Active Toggle -->
                    <div class="flex items-center space-x-3 pt-2">
                        <input
                            id="is_active"
                            v-model="form.is_active"
                            type="checkbox"
                            class="size-4 text-forest-900 rounded-md border-cream-400 focus:ring-forest-800"
                        />
                        <label for="is_active" class="text-xs font-semibold text-gray-800">
                            Active SSO Client (Allow users to authorize and sign in)
                        </label>
                    </div>

                    <!-- Action Buttons -->
                    <div class="flex items-center justify-end space-x-3 pt-4 border-t border-cream-400/60 shrink-0">
                        <button
                            @click="closeModal"
                            type="button"
                            class="px-4 py-2 text-xs font-semibold text-gray-700 bg-cream-100 hover:bg-cream-300 rounded-xl border border-cream-400 transition"
                        >
                            Cancel
                        </button>
                        <button
                            type="submit"
                            :disabled="form.processing"
                            class="px-4 py-2 text-xs font-semibold text-white bg-forest-900 hover:bg-forest-950 rounded-xl shadow-xs transition disabled:opacity-50"
                        >
                            {{ editingClient ? 'Update Client' : 'Register Client' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Regenerate Secret Confirmation Modal -->
        <div v-if="showRegenerateConfirm" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-xs">
            <div class="bg-cream-200 rounded-2xl shadow-xl border border-amber-300/80 w-full max-w-md p-6">
                <div class="flex items-center space-x-3 text-amber-700 mb-4">
                    <div class="p-2 bg-amber-100 rounded-xl">
                        <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
                        </svg>
                    </div>
                    <h3 class="text-base font-bold text-gray-900">Regenerate Client Secret?</h3>
                </div>
                <p class="text-xs text-gray-700 mb-2">
                    Are you sure you want to regenerate the secret for <strong class="text-gray-900">{{ clientToRegenerate?.name }}</strong>?
                </p>
                <p class="text-[11px] text-amber-800 bg-amber-50 p-2.5 rounded-lg border border-amber-200 mb-5">
                    <strong>Warning:</strong> The existing secret key will immediately stop working. You must update the corresponding client application's configuration or <code class="font-mono">.env</code> with the new secret.
                </p>
                <div class="flex items-center justify-end space-x-3">
                    <button
                        @click="showRegenerateConfirm = false; clientToRegenerate = null;"
                        type="button"
                        class="px-4 py-2 text-xs font-semibold text-gray-700 bg-cream-100 hover:bg-cream-300 rounded-xl border border-cream-400 transition"
                    >
                        Cancel
                    </button>
                    <button
                        @click="executeRegenerateSecret"
                        type="button"
                        class="px-4 py-2 text-xs font-semibold text-white bg-amber-700 hover:bg-amber-800 rounded-xl shadow-xs transition"
                    >
                        Yes, Regenerate Secret
                    </button>
                </div>
            </div>
        </div>

        <!-- Delete Confirmation Modal -->
        <div v-if="showDeleteConfirm" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-xs">
            <div class="bg-cream-200 rounded-2xl shadow-xl border border-red-300/80 w-full max-w-md p-6">
                <div class="flex items-center space-x-3 text-red-700 mb-4">
                    <div class="p-2 bg-red-100 rounded-xl">
                        <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
                        </svg>
                    </div>
                    <h3 class="text-base font-bold text-gray-900">Delete SSO Client?</h3>
                </div>
                <p class="text-xs text-gray-700 mb-5">
                    Are you sure you want to delete <strong class="text-gray-900">{{ clientToDelete?.name }}</strong>? Connected users will no longer be able to single sign-on into this portal. This action cannot be undone.
                </p>
                <div class="flex items-center justify-end space-x-3">
                    <button
                        @click="showDeleteConfirm = false; clientToDelete = null;"
                        type="button"
                        class="px-4 py-2 text-xs font-semibold text-gray-700 bg-cream-100 hover:bg-cream-300 rounded-xl border border-cream-400 transition"
                    >
                        Cancel
                    </button>
                    <button
                        @click="executeDelete"
                        type="button"
                        class="px-4 py-2 text-xs font-semibold text-white bg-red-700 hover:bg-red-800 rounded-xl shadow-xs transition"
                    >
                        Yes, Delete Client
                    </button>
                </div>
            </div>
        </div>

        <!-- Dedicated SSO Integration Guide & PDF Modal -->
        <div v-if="showDocsModal && docsClient" class="fixed inset-0 z-50 flex items-center justify-center p-3 sm:p-5 bg-black/60 backdrop-blur-xs">
            <div class="bg-cream-200 rounded-2xl shadow-2xl border border-cream-500 w-full max-w-4xl max-h-[92vh] flex flex-col overflow-hidden">
                <!-- Modal Header -->
                <div class="p-5 sm:p-6 bg-cream-300/80 border-b border-cream-400 flex items-start justify-between gap-4 shrink-0">
                    <div class="flex items-center space-x-3.5">
                        <div v-if="docsClient.icon_url" class="size-12 rounded-xl bg-white p-1 border border-cream-400/80 shadow-2xs flex items-center justify-center overflow-hidden shrink-0">
                            <img :src="docsClient.icon_url" :alt="docsClient.name" class="w-full h-full object-contain rounded-lg" />
                        </div>
                        <div v-else class="p-2.5 bg-forest-900/10 text-forest-900 rounded-xl border border-forest-900/15 shrink-0">
                            <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.967 8.967 0 00-6 2.292m0-14.25v14.25" />
                            </svg>
                        </div>
                        <div>
                            <div class="flex items-center space-x-2">
                                <h3 class="text-lg font-bold text-gray-900">{{ docsClient.name }}</h3>
                                <span class="text-xs text-gray-500 font-mono">({{ docsClient.client_id }})</span>
                            </div>
                            <p class="text-xs text-gray-600 mt-0.5">
                                Step-by-Step SSO Integration Guide & Dynamic Code Generator
                            </p>
                        </div>
                    </div>

                    <!-- PDF Actions & Close -->
                    <div class="flex items-center space-x-2 shrink-0">
                        <button
                            @click="downloadDocsPdf(activeClientDocData)"
                            type="button"
                            class="inline-flex items-center px-3 py-1.5 text-xs font-semibold text-white bg-forest-900 hover:bg-forest-950 rounded-xl shadow-xs transition cursor-pointer"
                            title="Download PDF containing BOTH Step-by-Step Instructions and AI Agent Prompt"
                        >
                            <svg class="size-3.5 me-1.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" />
                            </svg>
                            Download Complete PDF (Both Parts)
                        </button>
                        <button
                            @click="printDocsPdf(activeClientDocData)"
                            type="button"
                            class="inline-flex items-center px-3 py-1.5 text-xs font-semibold text-gray-800 bg-cream-100 hover:bg-cream-300 rounded-xl border border-cream-400 transition cursor-pointer"
                        >
                            <svg class="size-3.5 me-1.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6.72 13.829c-.24-1.023-.641-2.022-1.2-2.977m0 0A9.015 9.015 0 0112 3c4.97 0 9 4.03 9 9 0 1.637-.438 3.172-1.2 4.5M4.5 19.5h15a2.25 2.25 0 002.25-2.25V9a2.25 2.25 0 00-2.25-2.25H4.5A2.25 2.25 0 002.25 9v8.25A2.25 2.25 0 004.5 19.5z" />
                            </svg>
                            Print / PDF
                        </button>
                        <button @click="closeDocsModal" class="text-gray-400 hover:text-gray-600 p-1">
                            <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                </div>

                <!-- Framework Selector Tabs -->
                <div class="px-6 py-3 bg-cream-200 border-b border-cream-400/60 flex items-center space-x-2 overflow-x-auto shrink-0">
                    <span class="text-xs font-semibold text-gray-600 shrink-0 me-2">Framework / Stack:</span>
                    <button
                        v-for="fw in FRAMEWORK_OPTIONS"
                        :key="fw.key"
                        @click="selectedDocsFramework = fw.key"
                        type="button"
                        class="px-3 py-1.5 text-xs font-semibold rounded-lg shrink-0 transition flex items-center space-x-1.5 cursor-pointer"
                        :class="selectedDocsFramework === fw.key ? 'bg-forest-900 text-white shadow-xs' : 'bg-cream-100 text-gray-700 hover:bg-cream-300 border border-cream-400/70'"
                    >
                        <span>{{ fw.icon }}</span>
                        <span>{{ fw.shortName }}</span>
                    </button>
                </div>

                <!-- Guide Option Switcher: Step-by-Step vs AI Agent -->
                <div class="px-6 py-2.5 bg-cream-100/90 border-b border-cream-400/60 flex flex-wrap items-center justify-between gap-3 shrink-0">
                    <div class="flex items-center space-x-2">
                        <button
                            @click="docsModalTab = 'steps'"
                            type="button"
                            class="px-3 py-1.5 text-xs font-bold rounded-lg transition flex items-center space-x-1.5 cursor-pointer"
                            :class="docsModalTab === 'steps' ? 'bg-forest-900 text-white shadow-xs' : 'bg-cream-200 text-gray-700 hover:bg-cream-300 border border-cream-300'"
                        >
                            <span>📋</span>
                            <span>Step-by-Step Manual Guide</span>
                        </button>
                        <button
                            @click="docsModalTab = 'ai_agent'"
                            type="button"
                            class="px-3 py-1.5 text-xs font-bold rounded-lg transition flex items-center space-x-1.5 cursor-pointer"
                            :class="docsModalTab === 'ai_agent' ? 'bg-forest-900 text-white shadow-xs' : 'bg-cream-200 text-gray-700 hover:bg-cream-300 border border-cream-300'"
                        >
                            <span>🤖</span>
                            <span>Integration using AI Agent</span>
                        </button>
                    </div>

                    <div v-if="docsModalTab === 'ai_agent'" class="flex items-center space-x-2">
                        <button
                            @click="copyAiPromptToClipboard(activeClientDocData.aiPrompt, 'modal_ai_prompt')"
                            type="button"
                            class="px-3 py-1.5 text-xs font-semibold text-forest-900 bg-white hover:bg-forest-50 rounded-lg border border-forest-300 shadow-2xs transition flex items-center space-x-1.5 cursor-pointer"
                        >
                            <svg v-if="copiedAiPromptKey === 'modal_ai_prompt'" class="size-3.5 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                            </svg>
                            <svg v-else class="size-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15.666 3.888A2.25 2.25 0 0013.5 2.25h-3c-1.03 0-1.9.693-2.166 1.638m7.332 0c.055.194.084.4.084.612v0a.75.75 0 01-.75.75H9a.75.75 0 01-.75-.75v0c0-.212.03-.418.084-.612m7.332 0c.646.049 1.288.11 1.927.184 1.1.128 1.907 1.077 1.907 2.185V19.5a2.25 2.25 0 01-2.25 2.25H6.75A2.25 2.25 0 014.5 19.5V6.257c0-1.108.806-2.057 1.907-2.185a48.208 48.208 0 011.927-.184" />
                            </svg>
                            <span>{{ copiedAiPromptKey === 'modal_ai_prompt' ? 'Copied Prompt!' : 'Copy AI Prompt' }}</span>
                        </button>
                    </div>
                </div>

                <!-- Modal Body (Scrollable) -->
                <div v-if="activeClientDocData" class="flex-1 overflow-y-auto p-6 space-y-6">
                    <!-- Client Credentials & Overview Card -->
                    <div class="bg-cream-100 rounded-xl border border-cream-400/80 p-4 space-y-3">
                        <div class="flex items-center justify-between">
                            <div class="text-xs font-bold text-gray-800 uppercase tracking-wider">
                                Client Credentials & Target Endpoints
                            </div>
                            <span class="text-xs text-forest-800 font-semibold">
                                {{ activeClientDocData.frameworkName }}
                            </span>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-3 font-mono text-xs">
                            <div class="bg-white p-2.5 rounded-lg border border-cream-300">
                                <span class="text-gray-500 block text-[10px] uppercase font-sans font-semibold">Client ID</span>
                                <span class="text-gray-900 font-bold select-all break-all">{{ activeClientDocData.clientId }}</span>
                            </div>
                            <div class="bg-white p-2.5 rounded-lg border border-cream-300">
                                <span class="text-gray-500 block text-[10px] uppercase font-sans font-semibold">Client Secret</span>
                                <span class="text-gray-900 font-bold select-all break-all">{{ activeClientDocData.clientSecret }}</span>
                            </div>
                            <div class="bg-white p-2.5 rounded-lg border border-cream-300">
                                <span class="text-gray-500 block text-[10px] uppercase font-sans font-semibold">Redirect URI</span>
                                <span class="text-emerald-800 font-bold select-all break-all">{{ activeClientDocData.redirectUri }}</span>
                            </div>
                        </div>
                        <div class="text-[11px] text-gray-600 bg-white/60 p-2 rounded-lg border border-cream-200">
                            <strong>OAuth 2.0 Base URL:</strong> <code class="font-mono text-forest-900">{{ activeClientDocData.portalUrl }}</code> | <strong>Authorize Endpoint:</strong> <code class="font-mono">{{ activeClientDocData.portalUrl }}/oauth/authorize</code> | <strong>Token Endpoint:</strong> <code class="font-mono">{{ activeClientDocData.portalUrl }}/oauth/token</code> | <strong>User Info Endpoint:</strong> <code class="font-mono">{{ activeClientDocData.portalUrl }}/api/user</code>
                        </div>
                    </div>

                    <!-- Option 1: Step-by-Step Instructions -->
                    <div v-if="docsModalTab === 'steps'" class="space-y-4">
                        <div
                            v-for="(step, sIdx) in activeClientDocData.steps"
                            :key="sIdx"
                            class="bg-cream-100 rounded-xl border border-cream-400/80 p-5 space-y-2.5 shadow-2xs"
                        >
                            <div class="flex items-center justify-between">
                                <h4 class="text-sm font-bold text-gray-900 flex items-center space-x-2">
                                    <span class="size-6 bg-forest-900 text-white rounded-full flex items-center justify-center text-xs font-bold">
                                        {{ sIdx + 1 }}
                                    </span>
                                    <span>{{ step.title }}</span>
                                </h4>

                                <button
                                    v-if="step.code"
                                    @click="copyCodeToClipboard(step.code, 'client_step_' + sIdx)"
                                    type="button"
                                    class="inline-flex items-center space-x-1 text-xs font-semibold px-2.5 py-1 bg-white hover:bg-forest-50 text-forest-900 rounded-lg border border-cream-300 transition cursor-pointer"
                                >
                                    <svg v-if="copiedCodeKey === 'client_step_' + sIdx" class="size-3 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                                    </svg>
                                    <svg v-else class="size-3" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.666 3.888A2.25 2.25 0 0013.5 2.25h-3c-1.03 0-1.9.693-2.166 1.638m7.332 0c.055.194.084.4.084.612v0a.75.75 0 01-.75.75H9a.75.75 0 01-.75-.75v0c0-.212.03-.418.084-.612m7.332 0c.646.049 1.288.11 1.927.184 1.1.128 1.907 1.077 1.907 2.185V19.5a2.25 2.25 0 01-2.25 2.25H6.75A2.25 2.25 0 014.5 19.5V6.257c0-1.108.806-2.057 1.907-2.185a48.208 48.208 0 011.927-.184" />
                                    </svg>
                                    <span>{{ copiedCodeKey === 'client_step_' + sIdx ? 'Copied!' : 'Copy Code' }}</span>
                                </button>
                            </div>

                            <p class="text-xs text-gray-700 leading-relaxed">{{ step.description }}</p>

                            <div v-if="step.code" class="relative mt-2">
                                <pre class="bg-gray-900 text-gray-100 text-xs font-mono p-4 rounded-xl overflow-x-auto select-all leading-relaxed whitespace-pre-wrap"><code>{{ step.code }}</code></pre>
                            </div>
                        </div>
                    </div>

                    <!-- Option 2: AI Agent Prompt -->
                    <div v-else-if="docsModalTab === 'ai_agent'" class="space-y-4">
                        <div class="bg-cream-100 rounded-xl border border-cream-400/80 p-5 space-y-4 shadow-2xs">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center space-x-3">
                                    <div class="size-10 bg-forest-900 text-white rounded-xl flex items-center justify-center text-lg">
                                        🤖
                                    </div>
                                    <div>
                                        <h4 class="text-sm font-bold text-gray-900">
                                            Autonomous Implementation Prompt for {{ activeClientDocData.frameworkName }}
                                        </h4>
                                        <p class="text-xs text-gray-500">
                                            Ready for Google Antigravity, Claude Code, Cursor, GitHub Copilot, or ChatGPT
                                        </p>
                                    </div>
                                </div>

                                <button
                                    @click="copyAiPromptToClipboard(activeClientDocData.aiPrompt, 'body_ai_prompt')"
                                    type="button"
                                    class="inline-flex items-center space-x-1.5 text-xs font-semibold px-3 py-1.5 bg-white hover:bg-forest-50 text-forest-900 rounded-xl border border-cream-300 transition cursor-pointer shadow-xs"
                                >
                                    <svg v-if="copiedAiPromptKey === 'body_ai_prompt'" class="size-3.5 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                                    </svg>
                                    <svg v-else class="size-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.666 3.888A2.25 2.25 0 0013.5 2.25h-3c-1.03 0-1.9.693-2.166 1.638m7.332 0c.055.194.084.4.084.612v0a.75.75 0 01-.75.75H9a.75.75 0 01-.75-.75v0c0-.212.03-.418.084-.612m7.332 0c.646.049 1.288.11 1.927.184 1.1.128 1.907 1.077 1.907 2.185V19.5a2.25 2.25 0 01-2.25 2.25H6.75A2.25 2.25 0 014.5 19.5V6.257c0-1.108.806-2.057 1.907-2.185a48.208 48.208 0 011.927-.184" />
                                    </svg>
                                    <span>{{ copiedAiPromptKey === 'body_ai_prompt' ? 'Copied to Clipboard!' : 'Copy AI Prompt' }}</span>
                                </button>
                            </div>

                            <div class="p-3 bg-forest-900/5 border border-forest-900/15 rounded-xl text-xs text-forest-950 flex items-start space-x-2.5">
                                <svg class="size-4 text-forest-800 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z" />
                                </svg>
                                <div class="leading-relaxed">
                                    This prompt provides your AI assistant with the complete context: registered OAuth client credentials, required database migrations, controllers, route handlers, session management, and UI login buttons customized for <strong>{{ activeClientDocData.frameworkName }}</strong>.
                                </div>
                            </div>

                            <div class="relative">
                                <pre class="bg-gray-900 text-gray-100 text-xs font-mono p-4 rounded-xl overflow-x-auto select-all leading-relaxed whitespace-pre-wrap"><code>{{ activeClientDocData.aiPrompt }}</code></pre>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Modal Footer -->
                <div class="p-4 sm:p-5 bg-cream-300/60 border-t border-cream-400 flex flex-wrap items-center justify-between gap-3 shrink-0">
                    <span class="text-xs text-gray-700">
                        📄 The exported PDF contains <strong>BOTH</strong> the <strong>Step-by-Step Manual Instructions</strong> and the <strong>AI Agent Prompt</strong>.
                    </span>
                    <div class="flex items-center space-x-3">
                        <button
                            @click="closeDocsModal"
                            type="button"
                            class="px-4 py-2 text-xs font-semibold text-gray-700 bg-cream-100 hover:bg-cream-300 rounded-xl border border-cream-400 transition"
                        >
                            Close
                        </button>
                        <button
                            @click="downloadDocsPdf(activeClientDocData)"
                            type="button"
                            class="px-4 py-2 text-xs font-semibold text-white bg-forest-900 hover:bg-forest-950 rounded-xl shadow-xs transition flex items-center space-x-1.5 cursor-pointer"
                        >
                            <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" />
                            </svg>
                            <span>Download Complete PDF (Step-by-Step + AI Agent)</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

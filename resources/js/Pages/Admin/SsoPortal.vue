<script setup>
import { ref, computed } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import AdminNav from '@/Components/AdminNav.vue';

const props = defineProps({
    clients: {
        type: Array,
        required: true,
    },
    stats: {
        type: Object,
        required: true,
    },
    filters: {
        type: Object,
        default: () => ({ search: '', status: 'all' }),
    },
});

const searchQuery = ref(props.filters.search || '');
const statusFilter = ref(props.filters.status || 'all');
const copiedField = ref(null);

// Modal state
const showModal = ref(false);
const editingClient = ref(null);
const showDeleteConfirm = ref(false);
const clientToDelete = ref(null);
const showRegenerateConfirm = ref(false);
const clientToRegenerate = ref(null);
const visibleSecrets = ref({});

const form = useForm({
    name: '',
    client_id: '',
    client_secret: '',
    redirect_uri: '',
    is_active: true,
});

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
    form.is_active = true;
    showModal.value = true;
};

const openEditModal = (client) => {
    editingClient.value = client;
    form.clearErrors();
    form.name = client.name;
    form.client_id = client.client_id;
    form.client_secret = client.client_secret;
    form.redirect_uri = client.redirect_uri;
    form.is_active = !!client.is_active;
    showModal.value = true;
};

const closeModal = () => {
    showModal.value = false;
    editingClient.value = null;
    form.reset();
    form.clearErrors();
};

const submitForm = () => {
    if (editingClient.value) {
        form.put(route('admin.sso.update', editingClient.value.id), {
            preserveScroll: true,
            onSuccess: () => closeModal(),
        });
    } else {
        form.post(route('admin.sso.store'), {
            preserveScroll: true,
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
                                    <div class="p-2.5 bg-forest-900/10 text-forest-900 rounded-xl border border-forest-900/15">
                                        <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 011.242 7.244l-4.5 4.5a4.5 4.5 0 01-6.364-6.364l1.757-1.757m13.35-.622l1.757-1.757a4.5 4.5 0 00-6.364-6.364l-4.5 4.5a4.5 4.5 0 001.242 7.244" />
                                        </svg>
                                    </div>
                                    <div>
                                        <h3 class="font-bold text-gray-900 text-base leading-snug">{{ client.name }}</h3>
                                        <span
                                            class="inline-flex items-center px-2 py-0.5 text-[11px] font-semibold rounded-md mt-1"
                                            :class="client.is_active ? 'bg-emerald-100 text-emerald-800 border border-emerald-300' : 'bg-gray-100 text-gray-600 border border-gray-300'"
                                        >
                                            <span class="size-1.5 rounded-full me-1.5" :class="client.is_active ? 'bg-emerald-500 animate-pulse' : 'bg-gray-400'"></span>
                                            {{ client.is_active ? 'Active SSO Client' : 'Disabled' }}
                                        </span>
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
                        <div class="flex items-center justify-between border-t border-cream-400/60 pt-4 mt-2">
                            <button
                                @click="confirmRegenerate(client)"
                                type="button"
                                class="inline-flex items-center text-xs font-semibold text-amber-800 hover:text-amber-950 transition"
                            >
                                <svg class="size-3.5 me-1.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99" />
                                </svg>
                                Regenerate Secret
                            </button>

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
            <div class="bg-cream-200 rounded-2xl shadow-xl border border-cream-500 w-full max-w-lg p-6 overflow-hidden">
                <div class="flex items-center justify-between pb-4 border-b border-cream-400/60">
                    <h3 class="text-lg font-bold text-gray-900">
                        {{ editingClient ? 'Edit SSO Client' : 'Register New SSO Client' }}
                    </h3>
                    <button @click="closeModal" class="text-gray-400 hover:text-gray-600">
                        <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <form @submit.prevent="submitForm" class="mt-4 space-y-4">
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
                    <div class="flex items-center justify-end space-x-3 pt-4 border-t border-cream-400/60">
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
    </AppLayout>
</template>

<script setup>
import { ref } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import AdminNav from '@/Components/AdminNav.vue';

const props = defineProps({
    rules: {
        type: Array,
        required: true,
    },
    categories: {
        type: Object,
        required: true,
    },
    ruleTypes: {
        type: Object,
        required: true,
    },
    stats: {
        type: Object,
        required: true,
    },
    filters: {
        type: Object,
        default: () => ({}),
    },
});

const currentCategory = ref(props.filters.category || '');
const searchQuery = ref(props.filters.search || '');

// Modal state
const showModal = ref(false);
const editingRule = ref(null);
const showDeleteConfirm = ref(false);
const ruleToDelete = ref(null);

const form = useForm({
    name: '',
    key: '',
    category: 'chat_moderation',
    description: '',
    rule_type: 'boolean',
    value: 'true',
    is_active: true,
    priority: 1,
});

const applyFilters = () => {
    router.get(route('admin.rules.index'), {
        category: currentCategory.value || undefined,
        search: searchQuery.value || undefined,
    }, {
        preserveState: true,
        replace: true,
    });
};

const filterByCategory = (categoryKey) => {
    currentCategory.value = categoryKey;
    applyFilters();
};

const openCreateModal = () => {
    editingRule.value = null;
    form.reset();
    form.clearErrors();
    form.category = currentCategory.value || 'chat_moderation';
    form.rule_type = 'boolean';
    form.value = 'true';
    form.is_active = true;
    form.priority = 1;
    showModal.value = true;
};

const openEditModal = (rule) => {
    editingRule.value = rule;
    form.clearErrors();
    form.name = rule.name;
    form.key = rule.key;
    form.category = rule.category;
    form.description = rule.description || '';
    form.rule_type = rule.rule_type;
    form.value = rule.value || '';
    form.is_active = !!rule.is_active;
    form.priority = rule.priority ?? 0;
    showModal.value = true;
};

const closeModal = () => {
    showModal.value = false;
    editingRule.value = null;
    form.reset();
    form.clearErrors();
};

const toggleRule = (rule) => {
    router.post(route('admin.rules.toggle', rule.id), {}, {
        preserveScroll: true,
    });
};

const saveRule = () => {
    if (editingRule.value) {
        form.put(route('admin.rules.update', editingRule.value.id), {
            preserveScroll: true,
            onSuccess: () => closeModal(),
        });
    } else {
        form.post(route('admin.rules.store'), {
            preserveScroll: true,
            onSuccess: () => closeModal(),
        });
    }
};

const confirmDelete = (rule) => {
    ruleToDelete.value = rule;
    showDeleteConfirm.value = true;
};

const deleteRule = () => {
    if (!ruleToDelete.value) return;
    router.delete(route('admin.rules.destroy', ruleToDelete.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            showDeleteConfirm.value = false;
            ruleToDelete.value = null;
        },
    });
};

const getCategoryBadgeClasses = (catKey) => {
    switch (catKey) {
        case 'chat_moderation':
            return 'bg-blue-100 text-blue-800 border-blue-200';
        case 'security':
            return 'bg-purple-100 text-purple-800 border-purple-200';
        case 'user_access':
            return 'bg-amber-100 text-amber-800 border-amber-200';
        case 'general':
        default:
            return 'bg-indigo-100 text-indigo-800 border-indigo-200';
    }
};
</script>

<template>
    <AppLayout title="Admin - System Rules Engine">
        <template #header>
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="font-bold text-xl text-gray-900 leading-tight">
                        System Rules Engine
                    </h2>
                    <p class="text-xs text-gray-500 mt-0.5">Configure platform moderation rules, rate limits, security controls, and automated policies</p>
                </div>

                <button
                    @click="openCreateModal"
                    class="inline-flex items-center px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold rounded-xl shadow-xs transition"
                >
                    <svg class="size-4 me-1.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                    </svg>
                    Create System Rule
                </button>
            </div>
        </template>

        <div class="py-6">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <AdminNav />

                <!-- Flash Alerts -->
                <div v-if="$page.props.flash?.success" class="mb-6 p-4 bg-emerald-50 border border-emerald-200 text-emerald-800 rounded-xl text-sm font-medium flex items-center shadow-xs">
                    <svg class="size-5 me-2 text-emerald-600 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    {{ $page.props.flash.success }}
                </div>

                <div v-if="$page.props.flash?.error" class="mb-6 p-4 bg-red-50 border border-red-200 text-red-800 rounded-xl text-sm font-medium flex items-center shadow-xs">
                    <svg class="size-5 me-2 text-red-600 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" />
                    </svg>
                    {{ $page.props.flash.error }}
                </div>

                <!-- Stats Overview Row -->
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5 mb-6">
                    <div class="bg-white p-5 rounded-2xl shadow-xs border border-gray-100 flex items-center space-x-4">
                        <div class="p-3 bg-indigo-50 text-indigo-600 rounded-xl shrink-0">
                            <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 6h9.75M10.5 6a1.5 1.5 0 11-3 0m3 0a1.5 1.5 0 10-3 0M3.75 6H7.5m3 12h9.75m-9.75 0a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m-3.75 0H7.5m9-6h3.75m-3.75 0a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m-9.75 0h9.75" />
                            </svg>
                        </div>
                        <div>
                            <div class="text-xs text-gray-500 font-medium">Configured Rules</div>
                            <div class="text-2xl font-bold text-gray-900 mt-0.5">{{ stats.total_rules }}</div>
                        </div>
                    </div>

                    <div class="bg-white p-5 rounded-2xl shadow-xs border border-gray-100 flex items-center space-x-4">
                        <div class="p-3 bg-emerald-50 text-emerald-600 rounded-xl shrink-0">
                            <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                        </div>
                        <div>
                            <div class="text-xs text-gray-500 font-medium">Active Enforcements</div>
                            <div class="text-2xl font-bold text-emerald-600 mt-0.5">{{ stats.active_rules }}</div>
                        </div>
                    </div>

                    <div class="bg-white p-5 rounded-2xl shadow-xs border border-gray-100 flex items-center space-x-4">
                        <div class="p-3 bg-amber-50 text-amber-600 rounded-xl shrink-0">
                            <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636" />
                            </svg>
                        </div>
                        <div>
                            <div class="text-xs text-gray-500 font-medium">Disabled Rules</div>
                            <div class="text-2xl font-bold text-amber-600 mt-0.5">{{ stats.inactive_rules }}</div>
                        </div>
                    </div>

                    <div class="bg-white p-5 rounded-2xl shadow-xs border border-gray-100 flex items-center space-x-4">
                        <div class="p-3 bg-purple-50 text-purple-600 rounded-xl shrink-0">
                            <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 016 3.75h2.25A2.25 2.25 0 0110.5 6v2.25a2.25 2.25 0 01-2.25 2.25H6a2.25 2.25 0 01-2.25-2.25V6zM3.75 15.75A2.25 2.25 0 016 13.5h2.25a2.25 2.25 0 012.25 2.25V18a2.25 2.25 0 01-2.25 2.25H6A2.25 2.25 0 013.75 18v-2.25zM13.5 6a2.25 2.25 0 012.25-2.25H18A2.25 2.25 0 0120.25 6v2.25A2.25 2.25 0 0118 10.5h-2.25a2.25 2.25 0 01-2.25-2.25V6zM13.5 15.75a2.25 2.25 0 012.25-2.25H18a2.25 2.25 0 012.25 2.25V18A2.25 2.25 0 0118 20.25h-2.25A2.25 2.25 0 0113.5 18v-2.25z" />
                            </svg>
                        </div>
                        <div>
                            <div class="text-xs text-gray-500 font-medium">Rule Categories</div>
                            <div class="text-2xl font-bold text-gray-900 mt-0.5">{{ stats.categories_count }}</div>
                        </div>
                    </div>
                </div>

                <!-- Category Filtering Tabs & Search Toolbar -->
                <div class="bg-white p-4 rounded-2xl shadow-xs border border-gray-100 mb-6 space-y-4">
                    <!-- Category Tabs -->
                    <div class="flex items-center space-x-2 overflow-x-auto pb-1 border-b border-gray-100">
                        <button
                            @click="filterByCategory('')"
                            class="px-3.5 py-1.5 text-xs font-semibold rounded-xl transition whitespace-nowrap"
                            :class="!currentCategory ? 'bg-indigo-600 text-white shadow-xs' : 'text-gray-600 hover:bg-gray-100'"
                        >
                            All Categories
                        </button>
                        <button
                            v-for="(label, key) in categories"
                            :key="key"
                            @click="filterByCategory(key)"
                            class="px-3.5 py-1.5 text-xs font-semibold rounded-xl transition whitespace-nowrap"
                            :class="currentCategory === key ? 'bg-indigo-600 text-white shadow-xs' : 'text-gray-600 hover:bg-gray-100'"
                        >
                            {{ label }}
                        </button>
                    </div>

                    <!-- Search Input -->
                    <div class="relative max-w-md">
                        <input
                            v-model="searchQuery"
                            @keyup.enter="applyFilters"
                            type="text"
                            placeholder="Filter rules by name, key, or description (Press Enter)..."
                            class="w-full pl-10 pr-4 py-2 bg-gray-50 border border-gray-200 focus:bg-white focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200 text-sm rounded-xl transition"
                        />
                        <svg class="size-4 text-gray-400 absolute left-3.5 top-3" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
                        </svg>
                    </div>
                </div>

                <!-- Rules Grid -->
                <div v-if="rules.length > 0" class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <div
                        v-for="rule in rules"
                        :key="rule.id"
                        class="bg-white rounded-2xl border border-gray-100 shadow-xs hover:shadow-md transition-all duration-200 flex flex-col justify-between overflow-hidden"
                    >
                        <div class="p-6">
                            <!-- Card Header -->
                            <div class="flex items-start justify-between">
                                <div>
                                    <div class="flex items-center space-x-2">
                                        <h3 class="font-bold text-base text-gray-900 leading-tight">{{ rule.name }}</h3>
                                        <span
                                            class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[11px] font-bold border"
                                            :class="getCategoryBadgeClasses(rule.category)"
                                        >
                                            {{ categories[rule.category] || rule.category }}
                                        </span>
                                    </div>
                                    <code class="text-[11px] font-mono text-gray-400 bg-gray-50 px-2 py-0.5 rounded mt-1 inline-block border border-gray-100">
                                        {{ rule.key }}
                                    </code>
                                </div>

                                <!-- Active Toggle Switch -->
                                <button
                                    @click="toggleRule(rule)"
                                    type="button"
                                    class="relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none"
                                    :class="rule.is_active ? 'bg-emerald-500' : 'bg-gray-300'"
                                >
                                    <span
                                        class="pointer-events-none inline-block size-5 transform rounded-full bg-white shadow-md ring-0 transition duration-200 ease-in-out"
                                        :class="rule.is_active ? 'translate-x-5' : 'translate-x-0'"
                                    ></span>
                                </button>
                            </div>

                            <!-- Description -->
                            <p class="text-xs text-gray-600 mt-3 leading-relaxed">
                                {{ rule.description || 'No description provided for this rule.' }}
                            </p>

                            <!-- Rule Configuration Value Badge -->
                            <div class="mt-4 pt-3 border-t border-gray-100 flex items-center justify-between text-xs">
                                <span class="font-semibold text-gray-400 uppercase tracking-wider text-[11px]">Rule Value / Limit</span>

                                <div>
                                    <span
                                        v-if="rule.rule_type === 'boolean'"
                                        class="inline-flex items-center px-2.5 py-1 rounded-lg font-bold text-xs"
                                        :class="rule.value === 'true' || rule.value === '1' ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : 'bg-rose-50 text-rose-700 border border-rose-200'"
                                    >
                                        {{ rule.value === 'true' || rule.value === '1' ? 'ENABLED (TRUE)' : 'DISABLED (FALSE)' }}
                                    </span>

                                    <span
                                        v-else-if="rule.rule_type === 'integer'"
                                        class="inline-flex items-center px-2.5 py-1 rounded-lg font-mono font-bold bg-indigo-50 text-indigo-800 border border-indigo-200 text-xs"
                                    >
                                        {{ rule.value }}
                                    </span>

                                    <span
                                        v-else
                                        class="inline-flex items-center px-2.5 py-1 rounded-lg font-mono text-xs bg-gray-100 text-gray-800 border border-gray-200"
                                    >
                                        {{ rule.value || 'None' }}
                                    </span>
                                </div>
                            </div>
                        </div>

                        <!-- Card Footer -->
                        <div class="bg-gray-50/80 px-6 py-3 border-t border-gray-100 flex items-center justify-between">
                            <span class="text-[11px] text-gray-400 font-medium">
                                Priority Weight: <strong class="text-gray-700">{{ rule.priority }}</strong>
                            </span>

                            <div class="flex items-center space-x-2">
                                <button
                                    @click="openEditModal(rule)"
                                    class="px-3 py-1 text-xs font-semibold text-indigo-600 hover:text-indigo-800 hover:bg-indigo-50 rounded-lg transition"
                                >
                                    Edit Rule
                                </button>
                                <button
                                    @click="confirmDelete(rule)"
                                    class="px-3 py-1 text-xs font-semibold text-red-600 hover:text-red-800 hover:bg-red-50 rounded-lg transition"
                                >
                                    Delete
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Empty State -->
                <div v-else class="bg-white p-12 rounded-2xl border border-gray-100 text-center">
                    <div class="size-12 rounded-full bg-gray-100 text-gray-400 flex items-center justify-center mx-auto mb-3">
                        <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 6h9.75M10.5 6a1.5 1.5 0 11-3 0m3 0a1.5 1.5 0 10-3 0M3.75 6H7.5m3 12h9.75m-9.75 0a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m-3.75 0H7.5m9-6h3.75m-3.75 0a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m-9.75 0h9.75" />
                        </svg>
                    </div>
                    <h3 class="text-base font-bold text-gray-900">No System Rules Found</h3>
                    <p class="text-xs text-gray-500 mt-1">No operational rules match the active category filter or search criteria.</p>
                </div>
            </div>
        </div>

        <!-- Create / Edit System Rule Modal -->
        <div v-if="showModal" class="fixed inset-0 z-50 overflow-y-auto bg-gray-900/60 backdrop-blur-xs flex items-center justify-center p-4">
            <div class="bg-white rounded-3xl max-w-2xl w-full shadow-2xl overflow-hidden transform transition-all">
                <!-- Modal Header -->
                <div class="px-6 py-5 border-b border-gray-100 flex items-center justify-between bg-gray-50/50">
                    <div>
                        <h3 class="text-lg font-bold text-gray-900">
                            {{ editingRule ? 'Edit Rule: ' + editingRule.name : 'Create New System Rule' }}
                        </h3>
                        <p class="text-xs text-gray-500 mt-0.5">Configure rule parameters, category scope, and active enforcement state</p>
                    </div>

                    <button @click="closeModal" class="text-gray-400 hover:text-gray-600 p-1 rounded-lg hover:bg-gray-100 transition">
                        <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <!-- Modal Body -->
                <form @submit.prevent="saveRule" class="p-6 space-y-4">
                    <!-- Rule Name & Key -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-bold text-gray-700 uppercase tracking-wider mb-1.5">Rule Name *</label>
                            <input
                                v-model="form.name"
                                type="text"
                                placeholder="e.g., Maximum Upload Limit"
                                class="w-full px-3.5 py-2.5 bg-gray-50 border border-gray-200 focus:bg-white focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200 rounded-xl text-sm transition"
                                required
                            />
                            <div v-if="form.errors.name" class="text-xs text-red-600 mt-1 font-medium">{{ form.errors.name }}</div>
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-gray-700 uppercase tracking-wider mb-1.5">System Key / Slug</label>
                            <input
                                v-model="form.key"
                                type="text"
                                placeholder="e.g., max_upload_limit"
                                :disabled="!!editingRule"
                                class="w-full px-3.5 py-2.5 bg-gray-50 border border-gray-200 focus:bg-white focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200 rounded-xl text-sm transition disabled:opacity-60"
                            />
                            <div v-if="form.errors.key" class="text-xs text-red-600 mt-1 font-medium">{{ form.errors.key }}</div>
                        </div>
                    </div>

                    <!-- Category & Rule Type -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-bold text-gray-700 uppercase tracking-wider mb-1.5">Category Scope *</label>
                            <select
                                v-model="form.category"
                                class="w-full px-3.5 py-2.5 bg-gray-50 border border-gray-200 focus:bg-white focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200 rounded-xl text-sm transition"
                                required
                            >
                                <option v-for="(label, key) in categories" :key="key" :value="key">
                                    {{ label }}
                                </option>
                            </select>
                            <div v-if="form.errors.category" class="text-xs text-red-600 mt-1 font-medium">{{ form.errors.category }}</div>
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-gray-700 uppercase tracking-wider mb-1.5">Data Type *</label>
                            <select
                                v-model="form.rule_type"
                                class="w-full px-3.5 py-2.5 bg-gray-50 border border-gray-200 focus:bg-white focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200 rounded-xl text-sm transition"
                                required
                            >
                                <option v-for="(label, key) in ruleTypes" :key="key" :value="key">
                                    {{ label }}
                                </option>
                            </select>
                            <div v-if="form.errors.rule_type" class="text-xs text-red-600 mt-1 font-medium">{{ form.errors.rule_type }}</div>
                        </div>
                    </div>

                    <!-- Value Input (Dynamic based on rule_type) -->
                    <div>
                        <label class="block text-xs font-bold text-gray-700 uppercase tracking-wider mb-1.5">Rule Value / Parameter *</label>

                        <!-- Boolean Radio Selector -->
                        <div v-if="form.rule_type === 'boolean'" class="flex items-center space-x-4 bg-gray-50 p-3 rounded-xl border border-gray-200">
                            <label class="flex items-center space-x-2 cursor-pointer text-xs font-bold text-gray-800">
                                <input type="radio" v-model="form.value" value="true" class="text-indigo-600 focus:ring-indigo-500" />
                                <span>Enabled (true)</span>
                            </label>
                            <label class="flex items-center space-x-2 cursor-pointer text-xs font-bold text-gray-800">
                                <input type="radio" v-model="form.value" value="false" class="text-indigo-600 focus:ring-indigo-500" />
                                <span>Disabled (false)</span>
                            </label>
                        </div>

                        <!-- Integer Input -->
                        <input
                            v-else-if="form.rule_type === 'integer'"
                            v-model="form.value"
                            type="number"
                            placeholder="Numeric threshold e.g. 50"
                            class="w-full px-3.5 py-2.5 bg-gray-50 border border-gray-200 focus:bg-white focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200 rounded-xl text-sm transition"
                        />

                        <!-- String / JSON Textarea -->
                        <textarea
                            v-else
                            v-model="form.value"
                            rows="3"
                            placeholder="Text or JSON configuration string..."
                            class="w-full px-3.5 py-2.5 bg-gray-50 border border-gray-200 focus:bg-white focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200 rounded-xl text-sm transition font-mono"
                        ></textarea>

                        <div v-if="form.errors.value" class="text-xs text-red-600 mt-1 font-medium">{{ form.errors.value }}</div>
                    </div>

                    <!-- Description -->
                    <div>
                        <label class="block text-xs font-bold text-gray-700 uppercase tracking-wider mb-1.5">Description</label>
                        <textarea
                            v-model="form.description"
                            rows="2"
                            placeholder="Detailed explanation of rule impact and behavior..."
                            class="w-full px-3.5 py-2.5 bg-gray-50 border border-gray-200 focus:bg-white focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200 rounded-xl text-sm transition"
                        ></textarea>
                    </div>

                    <!-- Priority & Active Checkbox -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 items-center pt-2">
                        <div>
                            <label class="block text-xs font-bold text-gray-700 uppercase tracking-wider mb-1.5">Execution Priority Order</label>
                            <input
                                v-model="form.priority"
                                type="number"
                                min="0"
                                max="999"
                                class="w-full px-3.5 py-2.5 bg-gray-50 border border-gray-200 focus:bg-white focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200 rounded-xl text-sm transition"
                            />
                        </div>

                        <div class="flex items-center space-x-3 md:mt-5">
                            <input
                                v-model="form.is_active"
                                id="is_active_toggle"
                                type="checkbox"
                                class="size-4 text-indigo-600 focus:ring-indigo-500 border-gray-300 rounded"
                            />
                            <label for="is_active_toggle" class="text-xs font-bold text-gray-800 cursor-pointer">
                                Active Enforcement
                            </label>
                        </div>
                    </div>

                    <!-- Modal Actions -->
                    <div class="pt-4 border-t border-gray-100 flex items-center justify-end space-x-3">
                        <button
                            type="button"
                            @click="closeModal"
                            class="px-4 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 text-sm font-semibold rounded-xl transition"
                        >
                            Cancel
                        </button>
                        <button
                            type="submit"
                            :disabled="form.processing"
                            class="px-5 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold rounded-xl shadow-xs transition disabled:opacity-50"
                        >
                            {{ form.processing ? 'Saving...' : (editingRule ? 'Update Rule' : 'Create Rule') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Delete Confirmation Modal -->
        <div v-if="showDeleteConfirm" class="fixed inset-0 z-50 overflow-y-auto bg-gray-900/60 backdrop-blur-xs flex items-center justify-center p-4">
            <div class="bg-white rounded-3xl max-w-md w-full shadow-2xl p-6 text-center">
                <div class="size-12 rounded-full bg-red-100 text-red-600 flex items-center justify-center mx-auto mb-4">
                    <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.008v.008H12v-.008z" />
                    </svg>
                </div>

                <h3 class="text-lg font-bold text-gray-900">Delete System Rule</h3>
                <p class="text-xs text-gray-500 mt-2">
                    Are you sure you want to delete rule <strong class="text-gray-800">"{{ ruleToDelete?.name }}"</strong>? This operational rule will be permanently removed.
                </p>

                <div class="mt-6 flex items-center justify-center space-x-3">
                    <button
                        @click="showDeleteConfirm = false"
                        class="px-4 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 text-sm font-semibold rounded-xl transition"
                    >
                        Cancel
                    </button>
                    <button
                        @click="deleteRule"
                        class="px-5 py-2 bg-red-600 hover:bg-red-700 text-white text-sm font-semibold rounded-xl shadow-xs transition"
                    >
                        Yes, Delete Rule
                    </button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

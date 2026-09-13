<script setup>
import { ref } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import AdminNav from '@/Components/AdminNav.vue';

const props = defineProps({
    roles: {
        type: Array,
        required: true,
    },
    availablePermissions: {
        type: Object,
        required: true,
    },
    availableColors: {
        type: Array,
        required: true,
    },
    stats: {
        type: Object,
        required: true,
    },
});

// Modal state
const showModal = ref(false);
const editingRole = ref(null);
const showDeleteConfirm = ref(false);
const roleToDelete = ref(null);

// Form state using Inertia useForm
const form = useForm({
    name: '',
    slug: '',
    description: '',
    color: 'indigo',
    permissions: [],
});

const openCreateModal = () => {
    editingRole.value = null;
    form.reset();
    form.clearErrors();
    form.color = 'indigo';
    form.permissions = [];
    showModal.value = true;
};

const openEditModal = (role) => {
    editingRole.value = role;
    form.clearErrors();
    form.name = role.name;
    form.slug = role.slug;
    form.description = role.description || '';
    form.color = role.color || 'indigo';
    form.permissions = role.permissions ? [...role.permissions] : [];
    showModal.value = true;
};

const closeModal = () => {
    showModal.value = false;
    editingRole.value = null;
    form.reset();
    form.clearErrors();
};

const togglePermission = (key) => {
    const index = form.permissions.indexOf(key);
    if (index > -1) {
        form.permissions.splice(index, 1);
    } else {
        form.permissions.push(key);
    }
};

const selectAllPermissions = () => {
    form.permissions = Object.keys(props.availablePermissions);
};

const clearAllPermissions = () => {
    form.permissions = [];
};

const saveRole = () => {
    if (editingRole.value) {
        form.put(route('admin.roles.update', editingRole.value.id), {
            preserveScroll: true,
            onSuccess: () => closeModal(),
        });
    } else {
        form.post(route('admin.roles.store'), {
            preserveScroll: true,
            onSuccess: () => closeModal(),
        });
    }
};

const confirmDelete = (role) => {
    roleToDelete.value = role;
    showDeleteConfirm.value = true;
};

const deleteRole = () => {
    if (!roleToDelete.value) return;
    router.delete(route('admin.roles.destroy', roleToDelete.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            showDeleteConfirm.value = false;
            roleToDelete.value = null;
        },
    });
};

const getColorBadgeClasses = (colorKey) => {
    switch (colorKey) {
        case 'purple':
            return 'bg-purple-100 text-purple-800 border-purple-200';
        case 'blue':
            return 'bg-blue-100 text-blue-800 border-blue-200';
        case 'emerald':
            return 'bg-emerald-100 text-emerald-800 border-emerald-200';
        case 'amber':
            return 'bg-amber-100 text-amber-800 border-amber-200';
        case 'rose':
            return 'bg-rose-100 text-rose-800 border-rose-200';
        case 'gray':
            return 'bg-gray-100 text-gray-800 border-gray-200';
        case 'indigo':
        default:
            return 'bg-indigo-100 text-indigo-800 border-indigo-200';
    }
};
</script>

<template>
    <AppLayout title="Admin - Roles & Permissions">
        <template #header>
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="font-bold text-xl text-gray-900 leading-tight">
                        Roles & Permissions Management
                    </h2>
                    <p class="text-xs text-gray-500 mt-0.5">Configure access roles, permissions, and administrative capabilities</p>
                </div>

                <button
                    @click="openCreateModal"
                    class="inline-flex items-center px-4 py-2 bg-forest-900 hover:bg-forest-950 text-white text-sm font-semibold rounded-xl shadow-xs transition"
                >
                    <svg class="size-4 me-1.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                    </svg>
                    Create New Role
                </button>
            </div>
        </template>

        <div class="py-6">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <AdminNav />

                <!-- Flash Notifications -->
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

                <!-- Metrics Row -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-5 mb-6">
                    <div class="bg-cream-200 p-5 rounded-2xl shadow-xs border border-cream-500/50 flex items-center space-x-4">
                        <div class="p-3 bg-lime-200 text-forest-900 rounded-xl shrink-0">
                            <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z" />
                            </svg>
                        </div>
                        <div>
                            <div class="text-xs text-gray-500 font-medium">Total Defined Roles</div>
                            <div class="text-2xl font-bold text-gray-900 mt-0.5">{{ stats.total_roles }}</div>
                        </div>
                    </div>

                    <div class="bg-cream-200 p-5 rounded-2xl shadow-xs border border-cream-500/50 flex items-center space-x-4">
                        <div class="p-3 bg-lime-200 text-forest-900 rounded-xl shrink-0">
                            <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 013 18.75V8.25A2.25 2.25 0 015.25 6H10" />
                            </svg>
                        </div>
                        <div>
                            <div class="text-xs text-gray-500 font-medium">Custom User Roles</div>
                            <div class="text-2xl font-bold text-gray-900 mt-0.5">{{ stats.custom_roles }}</div>
                        </div>
                    </div>

                    <div class="bg-cream-200 p-5 rounded-2xl shadow-xs border border-cream-500/50 flex items-center space-x-4">
                        <div class="p-3 bg-lime-200 text-forest-900 rounded-xl shrink-0">
                            <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" />
                            </svg>
                        </div>
                        <div>
                            <div class="text-xs text-gray-500 font-medium">Assigned Users</div>
                            <div class="text-2xl font-bold text-gray-900 mt-0.5">{{ stats.total_assigned_users }}</div>
                        </div>
                    </div>
                </div>

                <!-- Roles Grid -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <div
                        v-for="role in roles"
                        :key="role.id"
                        class="bg-cream-200 rounded-2xl border border-cream-500/50 shadow-xs hover:shadow-md transition-all duration-200 flex flex-col justify-between overflow-hidden"
                    >
                        <div class="p-6">
                            <!-- Card Header -->
                            <div class="flex items-start justify-between">
                                <div>
                                    <div class="flex items-center space-x-2">
                                        <h3 class="font-bold text-lg text-gray-900">{{ role.name }}</h3>
                                        <span
                                            class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold border"
                                            :class="getColorBadgeClasses(role.color)"
                                        >
                                            {{ role.slug }}
                                        </span>
                                    </div>
                                    <p class="text-xs text-gray-500 mt-1">{{ role.description || 'No description provided.' }}</p>
                                </div>

                                <div class="shrink-0 text-right">
                                    <span
                                        v-if="role.is_system"
                                        class="inline-flex items-center px-2 py-0.5 rounded-md text-[11px] font-semibold bg-cream-100 text-gray-700 border border-cream-400"
                                    >
                                        🔒 System Protected
                                    </span>
                                    <span
                                        v-else
                                        class="inline-flex items-center px-2 py-0.5 rounded-md text-[11px] font-semibold bg-lime-200 text-forest-900 border border-forest-500/30"
                                    >
                                        ✨ Custom Role
                                    </span>
                                </div>
                            </div>

                            <!-- Permissions List -->
                            <div class="mt-5">
                                <div class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">Granted Capabilities</div>

                                <div v-if="role.permissions && role.permissions.length > 0" class="flex flex-wrap gap-1.5">
                                    <span
                                        v-for="permKey in role.permissions"
                                        :key="permKey"
                                        class="inline-flex items-center px-2.5 py-1 rounded-lg text-xs font-medium bg-cream-100 text-gray-800 border border-cream-400"
                                    >
                                        <svg class="size-3.5 me-1 text-forest-700 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                                        </svg>
                                        {{ availablePermissions[permKey] || permKey }}
                                    </span>
                                </div>
                                <div v-else class="text-xs text-gray-400 italic bg-cream-100/50 p-2 rounded-lg border border-dashed border-cream-400">
                                    No special administrative permissions granted. Standard user permissions apply.
                                </div>
                            </div>
                        </div>

                        <!-- Card Footer -->
                        <div class="bg-cream-100/80 px-6 py-3.5 border-t border-cream-500/30 flex items-center justify-between">
                            <div class="text-xs text-gray-600 font-medium">
                                <span class="font-bold text-gray-900">{{ role.users_count }}</span> assigned {{ role.users_count === 1 ? 'user' : 'users' }}
                            </div>

                            <div class="flex items-center space-x-2">
                                <button
                                    @click="openEditModal(role)"
                                    class="px-3 py-1.5 text-xs font-semibold text-forest-900 hover:text-forest-950 hover:bg-cream-300 rounded-lg transition"
                                >
                                    Edit Role
                                </button>

                                <button
                                    v-if="!role.is_system"
                                    @click="confirmDelete(role)"
                                    class="px-3 py-1.5 text-xs font-semibold text-red-600 hover:text-red-800 hover:bg-red-50 rounded-lg transition"
                                >
                                    Delete
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Create / Edit Modal -->
        <div v-if="showModal" class="fixed inset-0 z-50 overflow-y-auto bg-gray-900/60 backdrop-blur-xs flex items-center justify-center p-4">
            <div class="bg-cream-200 rounded-3xl max-w-2xl w-full shadow-2xl overflow-hidden border border-cream-500/60 transform transition-all">
                <!-- Modal Header -->
                <div class="px-6 py-5 border-b border-cream-500/40 flex items-center justify-between bg-cream-100">
                    <div>
                        <h3 class="text-lg font-bold text-gray-900">
                            {{ editingRole ? 'Edit Role: ' + editingRole.name : 'Create New Role' }}
                        </h3>
                        <p class="text-xs text-gray-500 mt-0.5">Define name, color badge, and access control capabilities</p>
                    </div>

                    <button @click="closeModal" class="text-gray-500 hover:text-gray-700 p-1 rounded-lg hover:bg-cream-300 transition">
                        <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <!-- Modal Body -->
                <form @submit.prevent="saveRole" class="p-6 space-y-5">
                    <!-- Role Name & Slug -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-bold text-forest-900 uppercase tracking-wider mb-1.5">Role Name *</label>
                            <input
                                v-model="form.name"
                                type="text"
                                placeholder="e.g., Community Manager"
                                class="w-full px-3.5 py-2.5 bg-[#fffef9] border border-cream-500 focus:border-forest-600 focus:ring-2 focus:ring-forest-200 rounded-xl text-gray-900 text-sm transition"
                                required
                            />
                            <div v-if="form.errors.name" class="text-xs text-red-600 mt-1 font-medium">{{ form.errors.name }}</div>
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-forest-900 uppercase tracking-wider mb-1.5">Slug / Identifier</label>
                            <input
                                v-model="form.slug"
                                type="text"
                                placeholder="Auto-generated if left blank"
                                :disabled="editingRole?.is_system"
                                class="w-full px-3.5 py-2.5 bg-[#fffef9] border border-cream-500 focus:border-forest-600 focus:ring-2 focus:ring-forest-200 rounded-xl text-gray-900 text-sm transition disabled:opacity-60"
                            />
                            <div v-if="form.errors.slug" class="text-xs text-red-600 mt-1 font-medium">{{ form.errors.slug }}</div>
                        </div>
                    </div>

                    <!-- Description -->
                    <div>
                        <label class="block text-xs font-bold text-forest-900 uppercase tracking-wider mb-1.5">Description</label>
                        <textarea
                            v-model="form.description"
                            rows="2"
                            placeholder="Brief description of responsibilities and scope..."
                            class="w-full px-3.5 py-2.5 bg-[#fffef9] border border-cream-500 focus:border-forest-600 focus:ring-2 focus:ring-forest-200 rounded-xl text-gray-900 text-sm transition"
                        ></textarea>
                        <div v-if="form.errors.description" class="text-xs text-red-600 mt-1 font-medium">{{ form.errors.description }}</div>
                    </div>

                    <!-- Color Badge Selection -->
                    <div>
                        <label class="block text-xs font-bold text-forest-900 uppercase tracking-wider mb-2">Badge Color Theme</label>
                        <div class="flex flex-wrap gap-2">
                            <button
                                v-for="color in availableColors"
                                :key="color.key"
                                type="button"
                                @click="form.color = color.key"
                                class="px-3 py-1.5 rounded-xl text-xs font-semibold border flex items-center space-x-1.5 transition"
                                :class="[
                                    color.bg,
                                    color.text,
                                    color.border,
                                    form.color === color.key ? 'ring-2 ring-forest-600 ring-offset-1 font-bold' : 'opacity-80 hover:opacity-100'
                                ]"
                            >
                                <span class="size-2 rounded-full bg-current"></span>
                                <span>{{ color.label }}</span>
                            </button>
                        </div>
                    </div>

                    <!-- Permissions Selection -->
                    <div>
                        <div class="flex items-center justify-between mb-2">
                            <label class="block text-xs font-bold text-forest-900 uppercase tracking-wider">Permissions & Capabilities</label>
                            <div class="space-x-2">
                                <button type="button" @click="selectAllPermissions" class="text-xs text-forest-800 hover:underline font-medium">Select All</button>
                                <span class="text-gray-300">|</span>
                                <button type="button" @click="clearAllPermissions" class="text-xs text-gray-500 hover:underline font-medium">Clear All</button>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 bg-cream-100 p-4 rounded-2xl border border-cream-500/40">
                            <label
                                v-for="(label, permKey) in availablePermissions"
                                :key="permKey"
                                class="flex items-start p-2.5 rounded-xl bg-[#fffef9] border border-cream-500/60 hover:border-forest-600 cursor-pointer transition"
                            >
                                <input
                                    type="checkbox"
                                    :checked="form.permissions.includes(permKey)"
                                    @change="togglePermission(permKey)"
                                    class="mt-0.5 rounded text-forest-900 focus:ring-forest-600 size-4 border-cream-500"
                                />
                                <span class="ms-2.5 text-xs font-semibold text-gray-800 leading-tight">
                                    {{ label }}
                                </span>
                            </label>
                        </div>
                    </div>

                    <!-- Modal Actions -->
                    <div class="pt-4 border-t border-cream-500/40 flex items-center justify-end space-x-3">
                        <button
                            type="button"
                            @click="closeModal"
                            class="px-4 py-2 bg-cream-100 hover:bg-cream-300 text-gray-800 text-sm font-semibold rounded-xl transition"
                        >
                            Cancel
                        </button>
                        <button
                            type="submit"
                            :disabled="form.processing"
                            class="px-5 py-2 bg-forest-900 hover:bg-forest-950 text-white text-sm font-semibold rounded-xl shadow-xs transition disabled:opacity-50"
                        >
                            {{ form.processing ? 'Saving...' : (editingRole ? 'Update Role' : 'Create Role') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Delete Confirmation Modal -->
        <div v-if="showDeleteConfirm" class="fixed inset-0 z-50 overflow-y-auto bg-gray-900/60 backdrop-blur-xs flex items-center justify-center p-4">
            <div class="bg-cream-200 rounded-3xl max-w-md w-full shadow-2xl p-6 text-center border border-cream-500/60">
                <div class="size-12 rounded-full bg-red-100 text-red-600 flex items-center justify-center mx-auto mb-4">
                    <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.008v.008H12v-.008z" />
                    </svg>
                </div>

                <h3 class="text-lg font-bold text-gray-900">Delete Custom Role</h3>
                <p class="text-xs text-gray-500 mt-2">
                    Are you sure you want to delete role <strong class="text-gray-800">"{{ roleToDelete?.name }}"</strong>? Any users currently assigned to this role will be reassigned to the default regular user role.
                </p>

                <div class="mt-6 flex items-center justify-center space-x-3">
                    <button
                        @click="showDeleteConfirm = false"
                        class="px-4 py-2 bg-cream-100 hover:bg-cream-300 text-gray-800 text-sm font-semibold rounded-xl transition"
                    >
                        Cancel
                    </button>
                    <button
                        @click="deleteRole"
                        class="px-5 py-2 bg-red-600 hover:bg-red-700 text-white text-sm font-semibold rounded-xl shadow-xs transition"
                    >
                        Yes, Delete Role
                    </button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

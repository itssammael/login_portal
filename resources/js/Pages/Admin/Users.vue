<script setup>
import { ref, computed } from 'vue';
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import AdminNav from '@/Components/AdminNav.vue';
import DialogModal from '@/Components/DialogModal.vue';

const props = defineProps({
    users: {
        type: Object,
        required: true,
    },
    filters: {
        type: Object,
        default: () => ({}),
    },
    roles: {
        type: Array,
        default: () => [],
    },
    departments: {
        type: Array,
        default: () => [],
    },
});

const search = ref(props.filters.search || '');
const role = ref(props.filters.role || '');
const status = ref(props.filters.status || '');
const departmentFilter = ref(props.filters.department_id || '');

const applyFilters = () => {
    router.get(route('admin.users'), {
        search: search.value || undefined,
        role: role.value || undefined,
        status: status.value || undefined,
        department_id: departmentFilter.value || undefined,
    }, {
        preserveState: true,
        replace: true,
    });
};

// Create User Modal State
const showCreateModal = ref(false);
const selectedDepartmentId = ref('');

const availableSections = computed(() => {
    if (!selectedDepartmentId.value) {
        return [];
    }
    const dept = props.departments.find(d => d.id === Number(selectedDepartmentId.value));
    return dept ? (dept.sections || []) : [];
});

const onDepartmentChange = () => {
    createUserForm.section_id = '';
};

const createUserForm = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
    role_id: '',
    section_id: '',
    position: '',
    employee_number: '',
    is_admin: false,
});

const openCreateModal = () => {
    createUserForm.reset();
    createUserForm.clearErrors();
    selectedDepartmentId.value = '';
    showCreateModal.value = true;
};

const closeCreateModal = () => {
    showCreateModal.value = false;
    createUserForm.reset();
    createUserForm.clearErrors();
    selectedDepartmentId.value = '';
};

const submitCreateUser = () => {
    createUserForm.post(route('admin.users.store'), {
        preserveScroll: true,
        onSuccess: () => closeCreateModal(),
    });
};

// Edit User Modal State
const showEditModal = ref(false);
const editingUser = ref(null);
const editSelectedDepartmentId = ref('');

const editAvailableSections = computed(() => {
    if (!editSelectedDepartmentId.value) {
        return [];
    }
    const dept = props.departments.find(d => d.id === Number(editSelectedDepartmentId.value));
    return dept ? (dept.sections || []) : [];
});

const onEditDepartmentChange = () => {
    editUserForm.section_id = '';
};

const editUserForm = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
    role_id: '',
    section_id: '',
    position: '',
    employee_number: '',
    is_admin: false,
});

const openEditModal = (user) => {
    editingUser.value = user;
    editUserForm.clearErrors();
    editUserForm.name = user.name || '';
    editUserForm.email = user.email || '';
    editUserForm.password = '';
    editUserForm.password_confirmation = '';
    editUserForm.role_id = user.role_id || (user.role ? user.role.id : '') || '';
    editUserForm.section_id = user.section_id || '';
    editUserForm.position = user.position || '';
    editUserForm.employee_number = user.employee_number || '';
    editUserForm.is_admin = !!user.is_admin;

    // Set department from section if section exists
    if (user.section && user.section.department_id) {
        editSelectedDepartmentId.value = user.section.department_id;
    } else if (user.section && user.section.department && user.section.department.id) {
        editSelectedDepartmentId.value = user.section.department.id;
    } else if (user.section_id) {
        for (const dept of props.departments) {
            if (dept.sections && dept.sections.some(s => s.id === user.section_id)) {
                editSelectedDepartmentId.value = dept.id;
                break;
            }
        }
    } else {
        editSelectedDepartmentId.value = '';
    }

    showEditModal.value = true;
};

const closeEditModal = () => {
    showEditModal.value = false;
    editingUser.value = null;
    editUserForm.reset();
    editUserForm.clearErrors();
    editSelectedDepartmentId.value = '';
};

const submitUpdateUser = () => {
    if (!editingUser.value) return;
    editUserForm.put(route('admin.users.update', editingUser.value.id), {
        preserveScroll: true,
        onSuccess: () => closeEditModal(),
    });
};

// Action Confirmation State
const confirmingActionUser = ref(null);
const actionType = ref(''); // 'toggleAdmin' or 'toggleBan'
const isActionProcessing = ref(false);

const toggleBan = (user) => {
    confirmingActionUser.value = user;
    actionType.value = 'toggleBan';
};

const toggleAdmin = (user) => {
    confirmingActionUser.value = user;
    actionType.value = 'toggleAdmin';
};

const closeConfirmModal = () => {
    confirmingActionUser.value = null;
    actionType.value = '';
    isActionProcessing.value = false;
};

const executeConfirmedAction = () => {
    if (!confirmingActionUser.value) return;
    const user = confirmingActionUser.value;
    isActionProcessing.value = true;

    if (actionType.value === 'toggleAdmin') {
        router.post(route('admin.users.toggle-admin', user.id), {}, {
            preserveScroll: true,
            onFinish: () => closeConfirmModal(),
        });
    } else if (actionType.value === 'toggleBan') {
        router.post(route('admin.users.toggle-ban', user.id), {}, {
            preserveScroll: true,
            onFinish: () => closeConfirmModal(),
        });
    }
};
</script>

<template>
    <AppLayout title="Admin - User Management">
        <template #header>
            <AdminNav />
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <div>
                    <h2 class="font-bold text-xl text-gray-900 leading-tight">
                        User Directory & Moderation
                    </h2>
                    <p class="text-xs text-gray-500 mt-0.5">
                        Manage municipal personnel, assign roles, track workforce departments, and enforce access rules
                    </p>
                </div>
                <button
                    @click="openCreateModal"
                    type="button"
                    class="inline-flex items-center space-x-2 px-4 py-2.5 bg-forest-900 hover:bg-forest-950 text-white text-xs font-bold rounded-xl shadow-xs transition cursor-pointer self-start sm:self-auto"
                >
                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                    </svg>
                    <span>Create User</span>
                </button>
            </div>
        </template>

        <div class="py-6">
            <div class="max-w-8xl mx-auto px-4 sm:px-6 lg:px-8">

                <!-- Flash Alerts -->
                <div v-if="$page.props.flash?.success" class="mb-6 p-4 bg-emerald-50 border border-emerald-200 text-emerald-800 rounded-xl text-sm font-medium flex items-center shadow-xs">
                    <svg class="size-5 me-2 text-emerald-600 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <span>{{ $page.props.flash.success }}</span>
                </div>

                <div v-if="$page.props.flash?.error" class="mb-6 p-4 bg-red-50 border border-red-200 text-red-800 rounded-xl text-sm font-medium flex items-center shadow-xs">
                    <svg class="size-5 me-2 text-red-600 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" />
                    </svg>
                    <span>{{ $page.props.flash.error }}</span>
                </div>

                <!-- Filters & Search Toolbar -->
                <div class="bg-cream-200 p-4 rounded-2xl shadow-xs border border-cream-500/50 mb-6 flex flex-col lg:flex-row lg:items-center justify-between gap-4">
                    <div class="relative flex-1 max-w-md">
                        <input
                            v-model="search"
                            @keyup.enter="applyFilters"
                            type="text"
                            placeholder="Search by name, email, employee #, or position..."
                            class="w-full pl-10 pr-4 py-2 bg-[#fffef9] border border-cream-500 text-gray-900 focus:border-forest-600 focus:ring-2 focus:ring-forest-200 text-sm rounded-xl transition"
                        />
                        <svg class="size-4 text-gray-400 absolute left-3.5 top-3" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
                        </svg>
                    </div>

                    <div class="flex flex-wrap items-center gap-2.5">
                        <!-- Department Filter -->
                        <select
                            v-model="departmentFilter"
                            @change="applyFilters"
                            class="py-2 pl-3 pr-8 bg-[#fffef9] border border-cream-500 text-gray-900 text-sm rounded-xl focus:border-forest-600 focus:ring-2 focus:ring-forest-200"
                        >
                            <option value="">All Departments</option>
                            <option v-for="dept in departments" :key="dept.id" :value="dept.id">
                                {{ dept.acronym ? `${dept.acronym} - ` : '' }}{{ dept.name }}
                            </option>
                        </select>

                        <!-- Role Filter -->
                        <select
                            v-model="role"
                            @change="applyFilters"
                            class="py-2 pl-3 pr-8 bg-[#fffef9] border border-cream-500 text-gray-900 text-sm rounded-xl focus:border-forest-600 focus:ring-2 focus:ring-forest-200"
                        >
                            <option value="">All Roles</option>
                            <option value="admin">Administrators</option>
                            <option value="user">Regular Users</option>
                        </select>

                        <!-- Status Filter -->
                        <select
                            v-model="status"
                            @change="applyFilters"
                            class="py-2 pl-3 pr-8 bg-[#fffef9] border border-cream-500 text-gray-900 text-sm rounded-xl focus:border-forest-600 focus:ring-2 focus:ring-forest-200"
                        >
                            <option value="">All Statuses</option>
                            <option value="active">Active</option>
                            <option value="banned">Suspended</option>
                        </select>

                        <button
                            @click="applyFilters"
                            class="px-4 py-2 bg-forest-900 hover:bg-forest-950 text-white text-sm font-semibold rounded-xl transition shadow-xs cursor-pointer"
                        >
                            Filter
                        </button>
                    </div>
                </div>

                <!-- Users Table -->
                <div class="bg-cream-200 rounded-2xl shadow-xs border border-cream-500/50 overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-cream-500/20 text-left text-sm">
                            <thead class="bg-cream-100/90 text-forest-900 text-xs font-semibold uppercase tracking-wider">
                                <tr>
                                    <th class="px-6 py-3.5">User Details</th>
                                    <th class="px-6 py-3.5">Department & Section</th>
                                    <th class="px-6 py-3.5">Role</th>
                                    <th class="px-6 py-3.5">Status</th>
                                    <th class="px-6 py-3.5">Chat Activity</th>
                                    <th class="px-6 py-3.5">Registered</th>
                                    <th class="px-6 py-3.5 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-cream-500/20">
                                <tr
                                    v-for="user in users.data"
                                    :key="user.id"
                                    class="hover:bg-cream-300/40 transition"
                                >
                                    <!-- User Info -->
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex items-center space-x-3">
                                            <div class="relative shrink-0">
                                                <img
                                                    :src="user.profile_photo_url || 'https://ui-avatars.com/api/?name=' + encodeURIComponent(user.name)"
                                                    :alt="user.name"
                                                    class="size-10 rounded-full object-cover ring-1 ring-cream-400"
                                                />
                                                <span
                                                    v-if="user.is_online"
                                                    class="absolute bottom-0 right-0 size-2.5 bg-forest-500 border-2 border-white rounded-full"
                                                ></span>
                                            </div>
                                            <div>
                                                <div class="font-bold text-gray-900 leading-tight">{{ user.name }}</div>
                                                <div class="text-xs text-gray-500">{{ user.email }}</div>
                                                <!-- Position & Employee ID -->
                                                <div v-if="user.employee_number || user.position" class="mt-1 flex flex-wrap items-center gap-1.5 text-[11px]">
                                                    <span v-if="user.employee_number" class="font-mono font-bold text-gray-700 bg-cream-300/80 px-1.5 py-0.2 rounded border border-cream-400/60">
                                                        {{ user.employee_number }}
                                                    </span>
                                                    <span v-if="user.position" class="text-gray-700 font-medium">
                                                        {{ user.position }}
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                    </td>

                                    <!-- Department & Section -->
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div v-if="user.section" class="space-y-0.5">
                                            <div class="font-bold text-gray-900 text-xs flex items-center space-x-1.5">
                                                <span class="size-1.5 rounded-full bg-forest-600 shrink-0"></span>
                                                <span>{{ user.section.department?.acronym || user.section.department?.name }}</span>
                                            </div>
                                            <div class="text-[11px] text-gray-600 pl-3">
                                                {{ user.section.name }}
                                            </div>
                                        </div>
                                        <span v-else class="text-xs text-gray-400 italic">
                                            Unassigned
                                        </span>
                                    </td>

                                    <!-- Role -->
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span
                                            v-if="user.is_admin"
                                            class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold bg-lime-200 text-forest-900"
                                        >
                                            🛡️ Admin
                                        </span>
                                        <span
                                            v-else-if="user.role"
                                            class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-cream-100 text-gray-800 border border-cream-400"
                                        >
                                            {{ user.role.name }}
                                        </span>
                                        <span
                                            v-else
                                            class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-cream-100 text-gray-800 border border-cream-400"
                                        >
                                            User
                                        </span>
                                    </td>

                                    <!-- Status -->
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span
                                            v-if="user.is_banned"
                                            class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold bg-red-100 text-red-800"
                                        >
                                            ⛔ Suspended
                                        </span>
                                        <span
                                            v-else
                                            class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-emerald-100 text-emerald-800"
                                        >
                                            ✓ Active
                                        </span>
                                    </td>

                                    <!-- Chat Activity -->
                                    <td class="px-6 py-4 whitespace-nowrap text-xs text-gray-600">
                                        <div><span class="font-semibold text-gray-900">{{ user.messages_count }}</span> messages</div>
                                        <div class="text-gray-500">{{ user.conversations_count }} conversations</div>
                                    </td>

                                    <!-- Registered -->
                                    <td class="px-6 py-4 whitespace-nowrap text-xs text-gray-500">
                                        {{ new Date(user.created_at).toLocaleDateString() }}
                                    </td>

                                    <!-- Actions -->
                                    <td class="px-6 py-4 whitespace-nowrap text-right space-x-1.5">
                                        <!-- Edit Profile Button -->
                                        <button
                                            @click="openEditModal(user)"
                                            class="px-2.5 py-1 text-xs font-semibold rounded-lg bg-cream-100 border border-cream-500 text-forest-900 hover:bg-cream-300 transition cursor-pointer shadow-xs inline-flex items-center gap-1"
                                            title="Update User Profile"
                                        >
                                            <svg class="size-3.5 text-forest-800" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" />
                                            </svg>
                                            <span>Edit</span>
                                        </button>

                                        <template v-if="user.id !== $page.props.auth.user.id">
                                            <!-- Toggle Admin -->
                                            <button
                                                @click="toggleAdmin(user)"
                                                class="px-2.5 py-1 text-xs font-medium rounded-lg border border-cream-500 text-forest-900 hover:bg-cream-300 transition cursor-pointer"
                                            >
                                                {{ user.is_admin ? 'Demote' : 'Make Admin' }}
                                            </button>

                                            <!-- Toggle Ban -->
                                            <button
                                                @click="toggleBan(user)"
                                                class="px-2.5 py-1 text-xs font-medium rounded-lg border transition cursor-pointer"
                                                :class="user.is_banned ? 'border-emerald-300 text-emerald-700 hover:bg-emerald-50' : 'border-red-300 text-red-700 hover:bg-red-50'"
                                            >
                                                {{ user.is_banned ? 'Reinstate' : 'Suspend' }}
                                            </button>
                                        </template>

                                        <span v-else class="text-xs text-gray-500 italic pl-1">
                                            (You)
                                        </span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <div v-if="users.links && users.links.length > 3" class="p-4 border-t border-cream-500/30 flex items-center justify-between">
                        <div class="text-xs text-gray-500">
                            Showing {{ users.from }} to {{ users.to }} of {{ users.total }} users
                        </div>
                        <div class="flex space-x-1">
                            <Link
                                v-for="link in users.links"
                                :key="link.label"
                                :href="link.url || '#'"
                                class="px-3 py-1.5 text-xs font-medium rounded-lg transition"
                                :class="[
                                    link.active ? 'bg-forest-900 text-white' : 'text-gray-800 hover:bg-cream-300',
                                    !link.url ? 'opacity-40 pointer-events-none' : ''
                                ]"
                            >
                                <span v-html="link.label"></span>
                            </Link>
                        </div>
                    </div>
                </div>

            </div>
        </div>

        <!-- ==================== CREATE USER MODAL ==================== -->
        <div v-if="showCreateModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-xs">
            <div class="bg-cream-200 rounded-3xl shadow-xl border border-cream-500/60 w-full max-w-2xl max-h-[90vh] overflow-y-auto animate-in fade-in zoom-in duration-150">
                <!-- Modal Header -->
                <div class="flex items-center justify-between p-5 bg-forest-900 text-white sticky top-0 z-10">
                    <div class="flex items-center space-x-2.5">
                        <div class="p-1.5 bg-emerald-500/20 rounded-xl text-emerald-200">
                            <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19 7.5v3m0 0v3m0-3h3m-3 0h-3m-2.25-4.125a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zM4 19.235v-.11a6.375 6.375 0 0112.75 0v.109A12.318 12.318 0 0110.374 21c-2.331 0-4.512-.645-6.374-1.766z" />
                            </svg>
                        </div>
                        <h3 class="font-bold text-base">Create New User Account</h3>
                    </div>
                    <button @click="closeCreateModal" type="button" class="text-emerald-100 hover:text-white p-1 rounded-lg">
                        <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <!-- Modal Body Form -->
                <form @submit.prevent="submitCreateUser" class="p-6 space-y-4">
                    <!-- Basic Information -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-bold text-forest-900 uppercase tracking-wider mb-1">Full Name *</label>
                            <input
                                v-model="createUserForm.name"
                                type="text"
                                placeholder="e.g. Juan Dela Cruz"
                                class="w-full px-3.5 py-2 bg-[#fffef9] border border-cream-500 focus:border-forest-600 focus:ring-2 focus:ring-forest-200 rounded-xl text-gray-900 text-sm"
                                required
                            />
                            <div v-if="createUserForm.errors.name" class="text-xs text-red-600 mt-1">{{ createUserForm.errors.name }}</div>
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-forest-900 uppercase tracking-wider mb-1">Email Address *</label>
                            <input
                                v-model="createUserForm.email"
                                type="email"
                                placeholder="e.g. juan@lgunet.gov.ph"
                                class="w-full px-3.5 py-2 bg-[#fffef9] border border-cream-500 focus:border-forest-600 focus:ring-2 focus:ring-forest-200 rounded-xl text-gray-900 text-sm"
                                required
                            />
                            <div v-if="createUserForm.errors.email" class="text-xs text-red-600 mt-1">{{ createUserForm.errors.email }}</div>
                        </div>
                    </div>

                    <!-- Password Fields -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-bold text-forest-900 uppercase tracking-wider mb-1">Password *</label>
                            <input
                                v-model="createUserForm.password"
                                type="password"
                                placeholder="Minimum 8 characters"
                                class="w-full px-3.5 py-2 bg-[#fffef9] border border-cream-500 focus:border-forest-600 focus:ring-2 focus:ring-forest-200 rounded-xl text-gray-900 text-sm"
                                required
                            />
                            <div v-if="createUserForm.errors.password" class="text-xs text-red-600 mt-1">{{ createUserForm.errors.password }}</div>
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-forest-900 uppercase tracking-wider mb-1">Confirm Password *</label>
                            <input
                                v-model="createUserForm.password_confirmation"
                                type="password"
                                placeholder="Re-enter password"
                                class="w-full px-3.5 py-2 bg-[#fffef9] border border-cream-500 focus:border-forest-600 focus:ring-2 focus:ring-forest-200 rounded-xl text-gray-900 text-sm"
                                required
                            />
                        </div>
                    </div>

                    <!-- Department & Section Assignment -->
                    <div class="p-4 bg-cream-100/80 rounded-2xl border border-cream-500/50 space-y-3">
                        <h4 class="text-xs font-extrabold uppercase tracking-wider text-forest-900 flex items-center space-x-1.5">
                            <svg class="size-4 text-forest-800" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3.75a.75.75 0 01.75-.75h1.5a.75.75 0 01.75.75V21m6 0v-3.75a.75.75 0 01.75-.75h1.5a.75.75 0 01.75.75V21" />
                            </svg>
                            <span>Organizational Placement</span>
                        </h4>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-bold text-gray-800 mb-1">Department</label>
                                <select
                                    v-model="selectedDepartmentId"
                                    @change="onDepartmentChange"
                                    class="w-full px-3 py-2 bg-[#fffef9] border border-cream-500 rounded-xl text-gray-900 text-sm focus:border-forest-600 focus:ring-2 focus:ring-forest-200"
                                >
                                    <option value="">-- None / Unassigned --</option>
                                    <option v-for="dept in departments" :key="dept.id" :value="dept.id">
                                        {{ dept.acronym ? `[${dept.acronym}] ` : '' }}{{ dept.name }}
                                    </option>
                                </select>
                            </div>

                            <div>
                                <label class="block text-xs font-bold text-gray-800 mb-1">Section</label>
                                <select
                                    v-model="createUserForm.section_id"
                                    :disabled="!selectedDepartmentId || availableSections.length === 0"
                                    class="w-full px-3 py-2 bg-[#fffef9] border border-cream-500 rounded-xl text-gray-900 text-sm focus:border-forest-600 focus:ring-2 focus:ring-forest-200 disabled:opacity-50"
                                >
                                    <option value="">{{ selectedDepartmentId ? (availableSections.length ? '-- Select Section --' : 'No sections in this department') : 'Select department first' }}</option>
                                    <option v-for="sec in availableSections" :key="sec.id" :value="sec.id">
                                        {{ sec.name }}
                                    </option>
                                </select>
                                <div v-if="createUserForm.errors.section_id" class="text-xs text-red-600 mt-1">{{ createUserForm.errors.section_id }}</div>
                            </div>
                        </div>

                        <!-- Position & Employee ID -->
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-bold text-gray-800 mb-1">Position / Job Title</label>
                                <input
                                    v-model="createUserForm.position"
                                    type="text"
                                    placeholder="e.g. Administrative Aide IV"
                                    class="w-full px-3 py-2 bg-[#fffef9] border border-cream-500 rounded-xl text-gray-900 text-sm focus:border-forest-600 focus:ring-2 focus:ring-forest-200"
                                />
                                <div v-if="createUserForm.errors.position" class="text-xs text-red-600 mt-1">{{ createUserForm.errors.position }}</div>
                            </div>

                            <div>
                                <label class="block text-xs font-bold text-gray-800 mb-1">Employee Number</label>
                                <input
                                    v-model="createUserForm.employee_number"
                                    type="text"
                                    placeholder="e.g. EMP-2026-0042"
                                    class="w-full px-3 py-2 bg-[#fffef9] border border-cream-500 rounded-xl text-gray-900 text-sm focus:border-forest-600 focus:ring-2 focus:ring-forest-200"
                                />
                                <div v-if="createUserForm.errors.employee_number" class="text-xs text-red-600 mt-1">{{ createUserForm.errors.employee_number }}</div>
                            </div>
                        </div>
                    </div>

                    <!-- Role & Administrative Privileges -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 items-center">
                        <div>
                            <label class="block text-xs font-bold text-forest-900 uppercase tracking-wider mb-1">Role Assignment</label>
                            <select
                                v-model="createUserForm.role_id"
                                class="w-full px-3.5 py-2 bg-[#fffef9] border border-cream-500 focus:border-forest-600 focus:ring-2 focus:ring-forest-200 rounded-xl text-gray-900 text-sm"
                            >
                                <option value="">Default User</option>
                                <option v-for="r in roles" :key="r.id" :value="r.id">
                                    {{ r.name }}
                                </option>
                            </select>
                            <div v-if="createUserForm.errors.role_id" class="text-xs text-red-600 mt-1">{{ createUserForm.errors.role_id }}</div>
                        </div>

                        <div class="pt-4 sm:pt-6">
                            <label class="relative flex items-center space-x-3 cursor-pointer select-none">
                                <input
                                    v-model="createUserForm.is_admin"
                                    type="checkbox"
                                    class="size-4.5 rounded text-forest-900 border-cream-500 focus:ring-forest-600"
                                />
                                <div>
                                    <span class="text-sm font-bold text-gray-900">Grant Administrator Access</span>
                                    <span class="block text-xs text-gray-500">Allow access to administrative dashboard and controls</span>
                                </div>
                            </label>
                        </div>
                    </div>

                    <!-- Modal Actions -->
                    <div class="flex items-center justify-end space-x-2 pt-4 border-t border-cream-400">
                        <button
                            @click="closeCreateModal"
                            type="button"
                            class="px-4 py-2 bg-cream-100 hover:bg-cream-300 text-gray-700 rounded-xl text-xs font-bold border border-cream-400 transition"
                        >
                            Cancel
                        </button>
                        <button
                            type="submit"
                            :disabled="createUserForm.processing"
                            class="px-5 py-2 bg-forest-900 hover:bg-forest-950 text-white rounded-xl text-xs font-bold shadow-xs transition disabled:opacity-50 flex items-center space-x-1.5"
                        >
                            <span>{{ createUserForm.processing ? 'Creating User...' : 'Create Account' }}</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Edit User Profile Modal -->
        <div
            v-if="showEditModal && editingUser"
            class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-xs"
        >
            <div class="bg-cream-200 border border-cream-500 rounded-2xl max-w-2xl w-full p-6 shadow-2xl space-y-6 max-h-[90vh] overflow-y-auto">
                <div class="flex items-center justify-between border-b border-cream-400 pb-4">
                    <div class="flex items-center space-x-3">
                        <div class="size-10 rounded-xl bg-forest-900 text-cream-200 flex items-center justify-center font-bold">
                            <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                            </svg>
                        </div>
                        <div>
                            <h3 class="text-lg font-bold text-gray-900">Update User Profile</h3>
                            <p class="text-xs text-gray-600">Modify personnel profile details, organizational assignment, and system roles.</p>
                        </div>
                    </div>
                    <button
                        @click="closeEditModal"
                        class="text-gray-400 hover:text-gray-600 p-1.5 rounded-lg hover:bg-cream-300 transition"
                    >
                        <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <form @submit.prevent="submitUpdateUser" class="space-y-4">
                    <!-- Name & Email -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-bold text-forest-900 uppercase tracking-wider mb-1">Full Name *</label>
                            <input
                                v-model="editUserForm.name"
                                type="text"
                                placeholder="e.g. Juan Dela Cruz"
                                class="w-full px-3.5 py-2 bg-[#fffef9] border border-cream-500 focus:border-forest-600 focus:ring-2 focus:ring-forest-200 rounded-xl text-gray-900 text-sm"
                                required
                            />
                            <div v-if="editUserForm.errors.name" class="text-xs text-red-600 mt-1">{{ editUserForm.errors.name }}</div>
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-forest-900 uppercase tracking-wider mb-1">Email Address *</label>
                            <input
                                v-model="editUserForm.email"
                                type="email"
                                placeholder="name@lgunet.gov.ph"
                                class="w-full px-3.5 py-2 bg-[#fffef9] border border-cream-500 focus:border-forest-600 focus:ring-2 focus:ring-forest-200 rounded-xl text-gray-900 text-sm"
                                required
                            />
                            <div v-if="editUserForm.errors.email" class="text-xs text-red-600 mt-1">{{ editUserForm.errors.email }}</div>
                        </div>
                    </div>

                    <!-- Department & Section Assignment -->
                    <div class="p-4 bg-cream-100/80 rounded-2xl border border-cream-500/50 space-y-3">
                        <h4 class="text-xs font-extrabold uppercase tracking-wider text-forest-900 flex items-center space-x-1.5">
                            <svg class="size-4 text-forest-800" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3.75a.75.75 0 01.75-.75h1.5a.75.75 0 01.75.75V21m6 0v-3.75a.75.75 0 01.75-.75h1.5a.75.75 0 01.75.75V21" />
                            </svg>
                            <span>Organizational Placement</span>
                        </h4>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-bold text-gray-800 mb-1">Department</label>
                                <select
                                    v-model="editSelectedDepartmentId"
                                    @change="onEditDepartmentChange"
                                    class="w-full px-3 py-2 bg-[#fffef9] border border-cream-500 rounded-xl text-gray-900 text-sm focus:border-forest-600 focus:ring-2 focus:ring-forest-200"
                                >
                                    <option value="">-- None / Unassigned --</option>
                                    <option v-for="dept in departments" :key="dept.id" :value="dept.id">
                                        {{ dept.acronym ? `[${dept.acronym}] ` : '' }}{{ dept.name }}
                                    </option>
                                </select>
                            </div>

                            <div>
                                <label class="block text-xs font-bold text-gray-800 mb-1">Section</label>
                                <select
                                    v-model="editUserForm.section_id"
                                    :disabled="!editSelectedDepartmentId || editAvailableSections.length === 0"
                                    class="w-full px-3 py-2 bg-[#fffef9] border border-cream-500 rounded-xl text-gray-900 text-sm focus:border-forest-600 focus:ring-2 focus:ring-forest-200 disabled:opacity-50"
                                >
                                    <option value="">{{ editSelectedDepartmentId ? (editAvailableSections.length ? '-- Select Section --' : 'No sections in this department') : 'Select department first' }}</option>
                                    <option v-for="sec in editAvailableSections" :key="sec.id" :value="sec.id">
                                        {{ sec.name }}
                                    </option>
                                </select>
                                <div v-if="editUserForm.errors.section_id" class="text-xs text-red-600 mt-1">{{ editUserForm.errors.section_id }}</div>
                            </div>
                        </div>

                        <!-- Position & Employee ID -->
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-bold text-gray-800 mb-1">Position / Job Title</label>
                                <input
                                    v-model="editUserForm.position"
                                    type="text"
                                    placeholder="e.g. Administrative Aide IV"
                                    class="w-full px-3 py-2 bg-[#fffef9] border border-cream-500 rounded-xl text-gray-900 text-sm focus:border-forest-600 focus:ring-2 focus:ring-forest-200"
                                />
                                <div v-if="editUserForm.errors.position" class="text-xs text-red-600 mt-1">{{ editUserForm.errors.position }}</div>
                            </div>

                            <div>
                                <label class="block text-xs font-bold text-gray-800 mb-1">Employee Number</label>
                                <input
                                    v-model="editUserForm.employee_number"
                                    type="text"
                                    placeholder="e.g. EMP-2026-0042"
                                    class="w-full px-3 py-2 bg-[#fffef9] border border-cream-500 rounded-xl text-gray-900 text-sm focus:border-forest-600 focus:ring-2 focus:ring-forest-200"
                                />
                                <div v-if="editUserForm.errors.employee_number" class="text-xs text-red-600 mt-1">{{ editUserForm.errors.employee_number }}</div>
                            </div>
                        </div>
                    </div>

                    <!-- Role & Administrative Privileges -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 items-center">
                        <div>
                            <label class="block text-xs font-bold text-forest-900 uppercase tracking-wider mb-1">Role Assignment</label>
                            <select
                                v-model="editUserForm.role_id"
                                class="w-full px-3.5 py-2 bg-[#fffef9] border border-cream-500 focus:border-forest-600 focus:ring-2 focus:ring-forest-200 rounded-xl text-gray-900 text-sm"
                            >
                                <option value="">Default User</option>
                                <option v-for="r in roles" :key="r.id" :value="r.id">
                                    {{ r.name }}
                                </option>
                            </select>
                            <div v-if="editUserForm.errors.role_id" class="text-xs text-red-600 mt-1">{{ editUserForm.errors.role_id }}</div>
                        </div>

                        <div class="pt-4 sm:pt-6">
                            <label class="relative flex items-center space-x-3 select-none" :class="editingUser.id === $page.props.auth.user.id ? 'opacity-70 cursor-not-allowed' : 'cursor-pointer'">
                                <input
                                    v-model="editUserForm.is_admin"
                                    type="checkbox"
                                    :disabled="editingUser.id === $page.props.auth.user.id"
                                    class="size-4.5 rounded text-forest-900 border-cream-500 focus:ring-forest-600"
                                />
                                <div>
                                    <span class="text-sm font-bold text-gray-900">Administrator Privileges</span>
                                    <span class="block text-xs text-gray-500">
                                        {{ editingUser.id === $page.props.auth.user.id ? 'Cannot demote current active account' : 'Grant full access to admin controls & records' }}
                                    </span>
                                </div>
                            </label>
                        </div>
                    </div>

                    <!-- Optional Password Reset -->
                    <div class="p-4 bg-[#fbf8ee] rounded-2xl border border-cream-400 space-y-3">
                        <div class="flex items-center justify-between">
                            <h4 class="text-xs font-bold uppercase tracking-wider text-forest-900 flex items-center space-x-1.5">
                                <svg class="size-4 text-forest-700" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                                </svg>
                                <span>Reset Password (Optional)</span>
                            </h4>
                            <span class="text-[11px] text-gray-500 italic">Leave empty to keep existing password</span>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-medium text-gray-700 mb-1">New Password</label>
                                <input
                                    v-model="editUserForm.password"
                                    type="password"
                                    placeholder="Leave blank to keep current"
                                    class="w-full px-3 py-2 bg-[#fffef9] border border-cream-500 rounded-xl text-gray-900 text-sm focus:border-forest-600 focus:ring-2 focus:ring-forest-200"
                                />
                                <div v-if="editUserForm.errors.password" class="text-xs text-red-600 mt-1">{{ editUserForm.errors.password }}</div>
                            </div>

                            <div>
                                <label class="block text-xs font-medium text-gray-700 mb-1">Confirm New Password</label>
                                <input
                                    v-model="editUserForm.password_confirmation"
                                    type="password"
                                    placeholder="Confirm new password"
                                    class="w-full px-3 py-2 bg-[#fffef9] border border-cream-500 rounded-xl text-gray-900 text-sm focus:border-forest-600 focus:ring-2 focus:ring-forest-200"
                                />
                            </div>
                        </div>
                    </div>

                    <!-- Modal Actions -->
                    <div class="flex items-center justify-end space-x-2 pt-4 border-t border-cream-400">
                        <button
                            @click="closeEditModal"
                            type="button"
                            class="px-4 py-2 bg-cream-100 hover:bg-cream-300 text-gray-700 rounded-xl text-xs font-bold border border-cream-400 transition cursor-pointer"
                        >
                            Cancel
                        </button>
                        <button
                            type="submit"
                            :disabled="editUserForm.processing"
                            class="px-5 py-2 bg-forest-900 hover:bg-forest-950 text-white rounded-xl text-xs font-bold shadow-xs transition disabled:opacity-50 flex items-center space-x-1.5 cursor-pointer"
                        >
                            <span>{{ editUserForm.processing ? 'Saving Changes...' : 'Save Changes' }}</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Action Confirmation Dialog Modal -->
        <DialogModal :show="confirmingActionUser !== null" max-width="md" @close="closeConfirmModal">
            <template #title>
                <div class="flex items-center space-x-3 pt-2">
                    <div
                        class="size-10 rounded-xl flex items-center justify-center shrink-0"
                        :class="actionType === 'toggleAdmin'
                            ? (confirmingActionUser?.is_admin ? 'bg-amber-100 text-amber-800' : 'bg-forest-100 text-forest-900')
                            : (confirmingActionUser?.is_banned ? 'bg-emerald-100 text-emerald-800' : 'bg-red-100 text-red-800')"
                    >
                        <svg v-if="actionType === 'toggleAdmin'" class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z" />
                        </svg>
                        <svg v-else-if="confirmingActionUser?.is_banned" class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        <svg v-else class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636" />
                        </svg>
                    </div>
                    <div>
                        <h3 class="text-base font-bold text-gray-900">
                            <template v-if="actionType === 'toggleAdmin'">
                                {{ confirmingActionUser?.is_admin ? 'Revoke Admin Privileges' : 'Grant Administrator Privileges' }}
                            </template>
                            <template v-else>
                                {{ confirmingActionUser?.is_banned ? 'Reinstate User Account' : 'Suspend User Account' }}
                            </template>
                        </h3>
                    </div>
                </div>
            </template>

            <template #content>
                <p class="text-sm text-gray-700 leading-relaxed">
                    <template v-if="actionType === 'toggleAdmin'">
                        Are you sure you want to {{ confirmingActionUser?.is_admin ? 'revoke admin privileges from' : 'grant administrator privileges to' }}
                        <span class="font-bold text-gray-900">"{{ confirmingActionUser?.name }}"</span>?
                    </template>
                    <template v-else>
                        Are you sure you want to {{ confirmingActionUser?.is_banned ? 'reinstate' : 'suspend' }} user
                        <span class="font-bold text-gray-900">"{{ confirmingActionUser?.name }}"</span>?
                    </template>
                </p>
            </template>

            <template #footer>
                <div class="flex items-center space-x-2">
                    <button
                        type="button"
                        @click="closeConfirmModal"
                        class="px-4 py-2 bg-cream-100 hover:bg-cream-300 text-gray-700 rounded-xl text-xs font-bold border border-cream-400 transition cursor-pointer"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        @click="executeConfirmedAction"
                        :disabled="isActionProcessing"
                        class="px-4 py-2 rounded-xl text-xs font-bold text-white shadow-xs transition disabled:opacity-50 cursor-pointer"
                        :class="actionType === 'toggleAdmin'
                            ? (confirmingActionUser?.is_admin ? 'bg-amber-700 hover:bg-amber-800' : 'bg-forest-900 hover:bg-forest-950')
                            : (confirmingActionUser?.is_banned ? 'bg-emerald-700 hover:bg-emerald-800' : 'bg-red-700 hover:bg-red-800')"
                    >
                        {{ isActionProcessing ? 'Processing...' : 'Confirm' }}
                    </button>
                </div>
            </template>
        </DialogModal>
    </AppLayout>
</template>

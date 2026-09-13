<script setup>
import { ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import AdminNav from '@/Components/AdminNav.vue';

const props = defineProps({
    users: {
        type: Object,
        required: true,
    },
    filters: {
        type: Object,
        default: () => ({}),
    },
});

const search = ref(props.filters.search || '');
const role = ref(props.filters.role || '');
const status = ref(props.filters.status || '');

const applyFilters = () => {
    router.get(route('admin.users'), {
        search: search.value || undefined,
        role: role.value || undefined,
        status: status.value || undefined,
    }, {
        preserveState: true,
        replace: true,
    });
};

// Toggle user ban
const toggleBan = (user) => {
    const action = user.is_banned ? 'reinstate' : 'suspend';
    if (confirm(`Are you sure you want to ${action} user "${user.name}"?`)) {
        router.post(route('admin.users.toggle-ban', user.id), {}, {
            preserveScroll: true,
        });
    }
};

// Toggle user admin
const toggleAdmin = (user) => {
    const action = user.is_admin ? 'revoke admin privileges from' : 'grant administrator privileges to';
    if (confirm(`Are you sure you want to ${action} "${user.name}"?`)) {
        router.post(route('admin.users.toggle-admin', user.id), {}, {
            preserveScroll: true,
        });
    }
};
</script>

<template>
    <AppLayout title="Admin - User Management">
        <template #header>
            <AdminNav />
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="font-bold text-xl text-gray-900 leading-tight">
                        User Directory & Moderation
                    </h2>
                    <p class="text-xs text-gray-500 mt-0.5">Manage user roles, enforce platform rules, and view chat statistics</p>
                </div>
            </div>
        </template>

        <div class="py-6">
            <div class="max-w-8xl mx-auto px-4 sm:px-6 lg:px-8">

                <!-- Flash Alerts -->
                <div v-if="$page.props.flash?.success" class="mb-6 p-4 bg-emerald-50 border border-emerald-200 text-emerald-800 rounded-xl text-sm font-medium flex items-center shadow-xs">
                    <svg class="size-5 me-2 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    {{ $page.props.flash.success }}
                </div>

                <div v-if="$page.props.flash?.error" class="mb-6 p-4 bg-red-50 border border-red-200 text-red-800 rounded-xl text-sm font-medium flex items-center shadow-xs">
                    <svg class="size-5 me-2 text-red-600" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" />
                    </svg>
                    {{ $page.props.flash.error }}
                </div>

                <!-- Filters & Search Toolbar -->
                <div class="bg-cream-200 p-4 rounded-2xl shadow-xs border border-cream-500/50 mb-6 flex flex-col md:flex-row md:items-center justify-between gap-4">
                    <div class="relative flex-1 max-w-md">
                        <input
                            v-model="search"
                            @keyup.enter="applyFilters"
                            type="text"
                            placeholder="Search by name or email (Press Enter)..."
                            class="w-full pl-10 pr-4 py-2 bg-[#fffef9] border border-cream-500 text-gray-900 focus:border-forest-600 focus:ring-2 focus:ring-forest-200 text-sm rounded-xl transition"
                        />
                        <svg class="size-4 text-gray-400 absolute left-3.5 top-3" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
                        </svg>
                    </div>

                    <div class="flex items-center space-x-3">
                        <select
                            v-model="role"
                            @change="applyFilters"
                            class="py-2 pl-3 pr-8 bg-[#fffef9] border border-cream-500 text-gray-900 text-sm rounded-xl focus:border-forest-600 focus:ring-2 focus:ring-forest-200"
                        >
                            <option value="">All Roles</option>
                            <option value="admin">Administrators</option>
                            <option value="user">Regular Users</option>
                        </select>

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
                            class="px-4 py-2 bg-forest-900 hover:bg-forest-950 text-white text-sm font-semibold rounded-xl transition shadow-xs"
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
                                    <th class="px-6 py-3.5">User</th>
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
                                            </div>
                                        </div>
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
                                    <td class="px-6 py-4 whitespace-nowrap text-right space-x-2">
                                        <template v-if="user.id !== $page.props.auth.user.id">
                                            <!-- Toggle Admin -->
                                            <button
                                                @click="toggleAdmin(user)"
                                                class="px-2.5 py-1 text-xs font-medium rounded-lg border border-cream-500 text-forest-900 hover:bg-cream-300 transition"
                                            >
                                                {{ user.is_admin ? 'Demote' : 'Make Admin' }}
                                            </button>

                                            <!-- Toggle Ban -->
                                            <button
                                                @click="toggleBan(user)"
                                                class="px-2.5 py-1 text-xs font-medium rounded-lg border transition"
                                                :class="user.is_banned ? 'border-emerald-300 text-emerald-700 hover:bg-emerald-50' : 'border-red-300 text-red-700 hover:bg-red-50'"
                                            >
                                                {{ user.is_banned ? 'Reinstate' : 'Suspend' }}
                                            </button>
                                        </template>

                                        <span v-else class="text-xs text-gray-500 italic">
                                            Current Admin
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
    </AppLayout>
</template>

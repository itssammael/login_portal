<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { Link, router } from '@inertiajs/vue3';

const accountsOpen = ref(false);
const systemOpen = ref(false);

const accountsRef = ref(null);
const systemRef = ref(null);

const isAccountsActive = computed(() => {
    return route().current('admin.users*') ||
        route().current('admin.departments.*') ||
        route().current('admin.roles.*');
});

const isSystemActive = computed(() => {
    return route().current('admin.rules.*') ||
        route().current('admin.appearance.*') ||
        route().current('admin.sso.*') ||
        route().current('admin.sso-clients.*') ||
        route().current('admin.audit-logs');
});

const toggleAccounts = () => {
    accountsOpen.value = !accountsOpen.value;
    if (accountsOpen.value) {
        systemOpen.value = false;
    }
};

const toggleSystem = () => {
    systemOpen.value = !systemOpen.value;
    if (systemOpen.value) {
        accountsOpen.value = false;
    }
};

const closeAll = () => {
    accountsOpen.value = false;
    systemOpen.value = false;
};

const handleClickOutside = (event) => {
    const isInsideAccounts = accountsRef.value && accountsRef.value.contains(event.target);
    const isInsideSystem = systemRef.value && systemRef.value.contains(event.target);

    if (!isInsideAccounts && !isInsideSystem) {
        closeAll();
    }
};

const handleKeyDown = (event) => {
    if (event.key === 'Escape') {
        closeAll();
    }
};

let removeNavigateListener = null;

onMounted(() => {
    document.addEventListener('click', handleClickOutside);
    document.addEventListener('keydown', handleKeyDown);
    removeNavigateListener = router.on('navigate', () => {
        closeAll();
    });
});

onUnmounted(() => {
    document.removeEventListener('click', handleClickOutside);
    document.removeEventListener('keydown', handleKeyDown);
    if (removeNavigateListener) {
        removeNavigateListener();
    }
});
</script>

<template>
    <div class="bg-forest-900 mb-6 rounded-2xl shadow-md px-4 border border-forest-800 relative z-30">
        <nav class="flex flex-wrap items-center gap-2 py-2.5" aria-label="Admin Tabs">
            <!-- Overview Tab -->
            <Link
                :href="route('admin.dashboard')"
                class="px-3.5 py-2 text-sm font-semibold rounded-xl transition-all whitespace-nowrap flex items-center space-x-2"
                :class="route().current('admin.dashboard') ? 'bg-[#2d6a4f] text-white shadow-xs' : 'text-emerald-100/75 hover:text-white hover:bg-forest-800/60'"
            >
                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 016 3.75h2.25A2.25 2.25 0 0110.5 6v2.25a2.25 2.25 0 01-2.25 2.25H6a2.25 2.25 0 01-2.25-2.25V6zM3.75 15.75A2.25 2.25 0 016 13.5h2.25a2.25 2.25 0 012.25 2.25V18a2.25 2.25 0 01-2.25 2.25H6A2.25 2.25 0 013.75 18v-2.25zM13.5 6a2.25 2.25 0 012.25-2.25H18A2.25 2.25 0 0120.25 6v2.25A2.25 2.25 0 0118 10.5h-2.25a2.25 2.25 0 01-2.25-2.25V6zM13.5 15.75a2.25 2.25 0 012.25-2.25H18a2.25 2.25 0 012.25 2.25V18A2.25 2.25 0 0118 20.25h-2.25A2.25 2.25 0 0113.5 18v-2.25z" />
                </svg>
                <span>Overview</span>
            </Link>

            <!-- Accounts Floating Dropdown -->
            <div ref="accountsRef" class="relative">
                <button
                    type="button"
                    @click="toggleAccounts"
                    class="px-3.5 py-2 text-sm font-semibold rounded-xl transition-all whitespace-nowrap flex items-center space-x-2 focus:outline-none focus:ring-2 focus:ring-emerald-400/30"
                    :class="isAccountsActive ? 'bg-[#2d6a4f] text-white shadow-xs' : (accountsOpen ? 'bg-forest-800/80 text-white' : 'text-emerald-100/75 hover:text-white hover:bg-forest-800/60')"
                    aria-haspopup="true"
                    :aria-expanded="accountsOpen"
                >
                    <svg class="size-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" />
                    </svg>
                    <span>Accounts</span>
                    <svg class="size-3.5 transition-transform duration-200" :class="{ 'rotate-180': accountsOpen }" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
                    </svg>
                </button>

                <!-- Floating Dropdown Menu -->
                <transition
                    enter-active-class="transition ease-out duration-150 transform"
                    enter-from-class="opacity-0 scale-95 -translate-y-2"
                    enter-to-class="opacity-100 scale-100 translate-y-0"
                    leave-active-class="transition ease-in duration-100 transform"
                    leave-from-class="opacity-100 scale-100 translate-y-0"
                    leave-to-class="opacity-0 scale-95 -translate-y-2"
                >
                    <div
                        v-show="accountsOpen"
                        class="absolute start-0 top-full mt-2 w-64 rounded-2xl bg-forest-900 border border-forest-700/80 shadow-2xl p-1.5 z-50 ring-1 ring-black/20 focus:outline-none backdrop-blur-md"
                    >
                        <div class="px-3 py-1.5 text-[10px] font-bold uppercase tracking-wider text-emerald-300/60">
                            Accounts
                        </div>

                        <!-- Users -->
                        <Link
                            :href="route('admin.users')"
                            @click="closeAll"
                            class="flex items-center space-x-2.5 px-3 py-2 text-sm rounded-xl transition-all font-medium"
                            :class="route().current('admin.users*') ? 'bg-[#2d6a4f] text-white shadow-xs' : 'text-emerald-100/80 hover:text-white hover:bg-forest-800/70'"
                        >
                            <svg class="size-4 shrink-0 text-emerald-300" :class="{ 'text-white': route().current('admin.users*') }" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" />
                            </svg>
                            <span class="flex-1">Users</span>
                            <span v-if="route().current('admin.users*')" class="size-1.5 rounded-full bg-emerald-300"></span>
                        </Link>

                        <!-- Departments & Sections -->
                        <Link
                            :href="route('admin.departments.index')"
                            @click="closeAll"
                            class="flex items-center space-x-2.5 px-3 py-2 text-sm rounded-xl transition-all font-medium"
                            :class="route().current('admin.departments.*') ? 'bg-[#2d6a4f] text-white shadow-xs' : 'text-emerald-100/80 hover:text-white hover:bg-forest-800/70'"
                        >
                            <svg class="size-4 shrink-0 text-emerald-300" :class="{ 'text-white': route().current('admin.departments.*') }" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3.75a.75.75 0 01.75-.75h1.5a.75.75 0 01.75.75V21m6 0v-3.75a.75.75 0 01.75-.75h1.5a.75.75 0 01.75.75V21" />
                            </svg>
                            <span class="flex-1">Departments & Sections</span>
                            <span v-if="route().current('admin.departments.*')" class="size-1.5 rounded-full bg-emerald-300"></span>
                        </Link>

                        <!-- Roles & Permissions -->
                        <Link
                            :href="route('admin.roles.index')"
                            @click="closeAll"
                            class="flex items-center space-x-2.5 px-3 py-2 text-sm rounded-xl transition-all font-medium"
                            :class="route().current('admin.roles.*') ? 'bg-[#2d6a4f] text-white shadow-xs' : 'text-emerald-100/80 hover:text-white hover:bg-forest-800/70'"
                        >
                            <svg class="size-4 shrink-0 text-emerald-300" :class="{ 'text-white': route().current('admin.roles.*') }" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z" />
                            </svg>
                            <span class="flex-1">Roles & Permissions</span>
                            <span v-if="route().current('admin.roles.*')" class="size-1.5 rounded-full bg-emerald-300"></span>
                        </Link>
                    </div>
                </transition>
            </div>

            <!-- System Floating Dropdown -->
            <div ref="systemRef" class="relative">
                <button
                    type="button"
                    @click="toggleSystem"
                    class="px-3.5 py-2 text-sm font-semibold rounded-xl transition-all whitespace-nowrap flex items-center space-x-2 focus:outline-none focus:ring-2 focus:ring-emerald-400/30"
                    :class="isSystemActive ? 'bg-[#2d6a4f] text-white shadow-xs' : (systemOpen ? 'bg-forest-800/80 text-white' : 'text-emerald-100/75 hover:text-white hover:bg-forest-800/60')"
                    aria-haspopup="true"
                    :aria-expanded="systemOpen"
                >
                    <svg class="size-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.325.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 011.37.49l1.296 2.247a1.125 1.125 0 01-.26 1.431l-1.003.827c-.293.241-.438.613-.43.992a7.723 7.723 0 010 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.955.26 1.43l-1.298 2.247a1.125 1.125 0 01-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 01-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 01-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 01-1.369-.49l-1.297-2.247a1.125 1.125 0 01.26-1.431l1.004-.827c.292-.241.437-.613.43-.991a6.932 6.932 0 010-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 01-.26-1.43l1.297-2.247a1.125 1.125 0 011.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.28z" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                    </svg>
                    <span>System</span>
                    <svg class="size-3.5 transition-transform duration-200" :class="{ 'rotate-180': systemOpen }" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
                    </svg>
                </button>

                <!-- Floating Dropdown Menu -->
                <transition
                    enter-active-class="transition ease-out duration-150 transform"
                    enter-from-class="opacity-0 scale-95 -translate-y-2"
                    enter-to-class="opacity-100 scale-100 translate-y-0"
                    leave-active-class="transition ease-in duration-100 transform"
                    leave-from-class="opacity-100 scale-100 translate-y-0"
                    leave-to-class="opacity-0 scale-95 -translate-y-2"
                >
                    <div
                        v-show="systemOpen"
                        class="absolute start-0 top-full mt-2 w-64 rounded-2xl bg-forest-900 border border-forest-700/80 shadow-2xl p-1.5 z-50 ring-1 ring-black/20 focus:outline-none backdrop-blur-md"
                    >
                        <div class="px-3 py-1.5 text-[10px] font-bold uppercase tracking-wider text-emerald-300/60">
                            System
                        </div>
                         <!-- System Appearance -->
                        <Link
                            :href="route('admin.appearance.index')"
                            @click="closeAll"
                            class="flex items-center space-x-2.5 px-3 py-2 text-sm rounded-xl transition-all font-medium"
                            :class="route().current('admin.appearance.*') ? 'bg-[#2d6a4f] text-white shadow-xs' : 'text-emerald-100/80 hover:text-white hover:bg-forest-800/70'"
                        >
                            <svg class="size-4 shrink-0 text-emerald-300" :class="{ 'text-white': route().current('admin.appearance.*') }" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4.098 19.902a3.75 3.75 0 005.304 0l6.401-6.402M6.75 21A3.75 3.75 0 013 17.25V4.125C3 3.504 3.504 3 4.125 3h5.25c.621 0 1.125.504 1.125 1.125v4.072M6.75 21a3.75 3.75 0 003.75-3.75V8.197M6.75 21h13.125c.621 0 1.125-.504 1.125-1.125v-5.25c0-.621-.504-1.125-1.125-1.125h-4.072M10.5 8.197a3.75 3.75 0 003.75-3.75V3" />
                            </svg>
                            <span class="flex-1">Appearance</span>
                            <span v-if="route().current('admin.appearance.*')" class="size-1.5 rounded-full bg-emerald-300"></span>
                        </Link>

                        <!-- System Rules Engine -->
                        <Link
                            :href="route('admin.rules.index')"
                            @click="closeAll"
                            class="flex items-center space-x-2.5 px-3 py-2 text-sm rounded-xl transition-all font-medium"
                            :class="route().current('admin.rules.*') ? 'bg-[#2d6a4f] text-white shadow-xs' : 'text-emerald-100/80 hover:text-white hover:bg-forest-800/70'"
                        >
                            <svg class="size-4 shrink-0 text-emerald-300" :class="{ 'text-white': route().current('admin.rules.*') }" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 6h9.75M10.5 6a1.5 1.5 0 11-3 0m3 0a1.5 1.5 0 10-3 0M3.75 6H7.5m3 12h9.75m-9.75 0a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m-3.75 0H7.5m9-6h3.75m-3.75 0a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m-9.75 0h9.75" />
                            </svg>
                            <span class="flex-1">Rules Engine</span>
                            <span v-if="route().current('admin.rules.*')" class="size-1.5 rounded-full bg-emerald-300"></span>
                        </Link>

                       
                        <!-- SSO Portal -->
                        <Link
                            :href="route('admin.sso.index')"
                            @click="closeAll"
                            class="flex items-center space-x-2.5 px-3 py-2 text-sm rounded-xl transition-all font-medium"
                            :class="(route().current('admin.sso.*') || route().current('admin.sso-clients.*')) ? 'bg-[#2d6a4f] text-white shadow-xs' : 'text-emerald-100/80 hover:text-white hover:bg-forest-800/70'"
                        >
                            <svg class="size-4 shrink-0 text-emerald-300" :class="{ 'text-white': (route().current('admin.sso.*') || route().current('admin.sso-clients.*')) }" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 5.25a3 3 0 013 3m3 0a6 6 0 01-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 1121.75 8.25z" />
                            </svg>
                            <span class="flex-1">SSO Clients</span>
                            <span v-if="route().current('admin.sso.*') || route().current('admin.sso-clients.*')" class="size-1.5 rounded-full bg-emerald-300"></span>
                        </Link>

                        <!-- Chat Moderation -->
                        <Link
                            :href="route('admin.chats')"
                            @click="closeAll"
                            class="flex items-center space-x-2.5 px-3 py-2 text-sm rounded-xl transition-all font-medium"
                            :class="route().current('admin.chats') ? 'bg-[#2d6a4f] text-white shadow-xs' : 'text-emerald-100/80 hover:text-white hover:bg-forest-800/70'"
                        >
                            <svg class="size-4 shrink-0 text-emerald-300" :class="{ 'text-white': route().current('admin.chats') }" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M8.625 12a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H8.25m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H12m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 01-2.555-.337A5.972 5.972 0 015.41 20.97a5.969 5.969 0 01-.474-.065 4.48 4.48 0 00.978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25z" />
                            </svg>
                            <span class="flex-1">Chat Moderation</span>
                            <span v-if="route().current('admin.chats')" class="size-1.5 rounded-full bg-emerald-300"></span>
                        </Link>

                        <!-- Audit Log -->
                        <Link
                            :href="route('admin.audit-logs')"
                            @click="closeAll"
                            class="flex items-center space-x-2.5 px-3 py-2 text-sm rounded-xl transition-all font-medium"
                            :class="route().current('admin.audit-logs') ? 'bg-[#2d6a4f] text-white shadow-xs' : 'text-emerald-100/80 hover:text-white hover:bg-forest-800/70'"
                        >
                            <svg class="size-4 shrink-0 text-emerald-300" :class="{ 'text-white': route().current('admin.audit-logs') }" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                            <span class="flex-1">Audit Log</span>
                            <span v-if="route().current('admin.audit-logs')" class="size-1.5 rounded-full bg-emerald-300"></span>
                        </Link>
                    </div>
                </transition>
            </div>

          

            <!-- Announcements Tab -->
            <Link
                :href="route('admin.announcements')"
                class="px-3.5 py-2 text-sm font-semibold rounded-xl transition-all whitespace-nowrap flex items-center space-x-2"
                :class="route().current('admin.announcements') ? 'bg-[#2d6a4f] text-white shadow-xs' : 'text-emerald-100/75 hover:text-white hover:bg-forest-800/60'"
            >
                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M10.34 15.84c-.688-.06-1.386-.09-2.09-.09H7.5a4.5 4.5 0 110-9h.75c.704 0 1.402-.03 2.09-.09m0 9.18c.253.962.584 1.892.985 2.783.247.55.06 1.21-.463 1.511l-.657.38c-.551.318-1.26.117-1.527-.461a20.845 20.845 0 01-1.44-4.282m3.102.069a18.03 18.03 0 01-.59-4.59c0-1.586.205-3.124.59-4.59m0 9.18a23.848 23.848 0 018.835 2.535M10.34 6.66a23.847 23.847 0 008.835-2.535m0 0A23.74 23.74 0 0018.795 3m.38 1.125a23.91 23.91 0 011.014 5.395m-1.014 8.855c-.118.38-.245.754-.38 1.125m.38-1.125a23.91 23.91 0 001.014-5.395m0-3.46c.495.41.811 1.035.811 1.73 0 .695-.316 1.32-.811 1.73m0-3.46a24.347 24.347 0 010 3.46" />
                </svg>
                <span>Announcements</span>
            </Link>
        </nav>
    </div>
</template>

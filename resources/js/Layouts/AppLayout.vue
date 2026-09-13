<script setup>
import { ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import ApplicationMark from '@/Components/ApplicationMark.vue';
import Banner from '@/Components/Banner.vue';
import DialogModal from '@/Components/DialogModal.vue';
import Dropdown from '@/Components/Dropdown.vue';
import DropdownLink from '@/Components/DropdownLink.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';

defineProps({
    title: String,
});

const isCollapsed = ref(localStorage.getItem('sidebar_collapsed') === 'false');
const showingMobileSidebar = ref(false);
const showingAnnouncementModal = ref(false);

const announcementForm = useForm({
    title: '',
    content: '',
});

const openAnnouncementModal = () => {
    announcementForm.reset();
    announcementForm.clearErrors();
    showingAnnouncementModal.value = true;
};

const closeAnnouncementModal = () => {
    showingAnnouncementModal.value = false;
    announcementForm.reset();
    announcementForm.clearErrors();
};

const sendAnnouncement = () => {
    announcementForm.post(route('admin.announcements.broadcast'), {
        preserveScroll: true,
        onSuccess: () => {
            closeAnnouncementModal();
        },
    });
};

const toggleSidebar = () => {
    isCollapsed.value = !isCollapsed.value;
    localStorage.setItem('sidebar_collapsed', isCollapsed.value ? 'true' : 'false');
};

const switchToTeam = (team) => {
    router.put(route('current-team.update'), {
        team_id: team.id,
    }, {
        preserveState: false,
    });
};

const logout = () => {
    router.post(route('logout'));
};
</script>

<template>
    <div>
        <Head :title="title" />

        <Banner />

        <div class="h-dvh w-full bg-gray-100 flex overflow-hidden">
            <!-- Left Vertical Sidebar (Desktop) -->
            <aside
                class="hidden md:flex flex-col bg-white border-r border-gray-200 shrink-0 h-full z-30 justify-between transition-all duration-300 ease-in-out"
                :class="isCollapsed ? 'w-20' : 'w-64 lg:w-72'"
            >
                <!-- Top Sidebar Header & Brand with Toggle Button -->
                <div
                    class="p-4 border-b border-gray-100 flex items-center justify-between shrink-0"
                    :class="isCollapsed ? 'px-2 flex-col space-y-3' : 'px-5'"
                >
                    <Link :href="route('dashboard')" class="flex items-center space-x-3 shrink-0">
                        <ApplicationMark class="block h-9 w-auto" />
                    </Link>

                    <!-- Collapse / Expand Toggle Button -->
                    <button
                        @click="toggleSidebar"
                        type="button"
                        class="p-1.5 rounded-xl text-gray-400 hover:text-gray-700 hover:bg-gray-100 transition"
                        :title="isCollapsed ? 'Expand Sidebar' : 'Collapse Sidebar'"
                    >
                        <svg
                            class="size-5 transition-transform duration-300"
                            :class="isCollapsed ? 'rotate-180' : ''"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke-width="2"
                            stroke="currentColor"
                        >
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5" />
                        </svg>
                    </button>
                </div>

                <!-- Navigation Links -->
                <div class="flex-1 py-5 space-y-1.5 overflow-y-auto" :class="isCollapsed ? 'px-2' : 'px-3.5'">
                    <div
                        v-if="!isCollapsed"
                        class="px-3 text-[11px] font-bold text-gray-400 uppercase tracking-wider mb-2"
                    >
                        Navigation
                    </div>

                    <!-- Dashboard -->
                    <Link
                        :href="route('dashboard')"
                        class="flex items-center rounded-xl text-sm font-semibold transition-all duration-150 group relative"
                        :class="[
                            route().current('dashboard') ? 'bg-indigo-600 text-white shadow-sm' : 'text-gray-600 hover:text-gray-900 hover:bg-gray-100/80',
                            isCollapsed ? 'justify-center p-3' : 'space-x-3 px-3.5 py-2.5'
                        ]"
                        :title="isCollapsed ? 'Dashboard' : ''"
                    >
                        <svg class="size-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 016 3.75h2.25A2.25 2.25 0 0110.5 6v2.25a2.25 2.25 0 01-2.25 2.25H6a2.25 2.25 0 01-2.25-2.25V6zM3.75 15.75A2.25 2.25 0 016 13.5h2.25a2.25 2.25 0 012.25 2.25V18a2.25 2.25 0 01-2.25 2.25H6A2.25 2.25 0 013.75 18v-2.25zM13.5 6a2.25 2.25 0 012.25-2.25H18A2.25 2.25 0 0120.25 6v2.25A2.25 2.25 0 0118 10.5h-2.25a2.25 2.25 0 01-2.25-2.25V6zM13.5 15.75a2.25 2.25 0 012.25-2.25H18a2.25 2.25 0 012.25 2.25V18A2.25 2.25 0 0118 20.25h-2.25A2.25 2.25 0 0113.5 18v-2.25z" />
                        </svg>
                        <span v-if="!isCollapsed" class="truncate">Dashboard</span>
                    </Link>

                    <!-- Messenger -->
                    <Link
                        :href="route('chat.index')"
                        class="flex items-center rounded-xl text-sm font-semibold transition-all duration-150 relative"
                        :class="[
                            route().current('chat.*') ? 'bg-indigo-600 text-white shadow-sm' : 'text-gray-600 hover:text-gray-900 hover:bg-gray-100/80',
                            isCollapsed ? 'justify-center p-3' : 'justify-between px-3.5 py-2.5'
                        ]"
                        :title="isCollapsed ? 'Messenger' : ''"
                    >
                        <div class="flex items-center" :class="isCollapsed ? '' : 'space-x-3'">
                            <img src="/assets/imgs/icons/chat.png" alt="chat_icon" class="size-5 shrink-0">
                            <span v-if="!isCollapsed" class="truncate">Messenger</span>
                        </div>

                        <!-- Unread Badge -->
                        <span
                            v-if="$page.props.unread_messages_count > 0"
                            class="px-2 py-0.5 text-xs font-bold rounded-full animate-pulse"
                            :class="[
                                isCollapsed ? 'absolute -top-1 -right-1 size-4 p-0 flex items-center justify-center text-[10px] bg-red-500 text-white ring-2 ring-white' : (route().current('chat.*') ? 'bg-white text-indigo-600' : 'bg-indigo-600 text-white')
                            ]"
                        >
                            {{ isCollapsed ? ($page.props.unread_messages_count > 9 ? '9+' : $page.props.unread_messages_count) : $page.props.unread_messages_count }}
                        </span>
                    </Link>
                     <!-- Broadcast Announcements Modal Trigger (Admins Only) -->
                    <button
                        v-if="$page.props.auth.user?.is_admin"
                        @click="openAnnouncementModal"
                        type="button"
                        class="w-full flex items-center rounded-xl text-sm font-semibold transition-all duration-150 text-gray-600 hover:text-gray-900 hover:bg-gray-100/80 cursor-pointer"
                        :class="[
                            isCollapsed ? 'justify-center p-3' : 'space-x-3 px-3.5 py-2.5'
                        ]"
                        :title="isCollapsed ? 'Broadcast Announcement' : ''"
                    >
                        <img src="/assets/imgs/icons/promotion.png" alt="announcement_icon" class="size-5 shrink-0">
                        <span v-if="!isCollapsed" class="truncate">Announcements</span>
                    </button>

                    <!-- Admin Panel (Admins Only) -->
                    <Link
                        v-if="$page.props.auth.user?.is_admin"
                        :href="route('admin.dashboard')"
                        class="flex items-center rounded-xl text-sm font-semibold transition-all duration-150"
                        :class="[
                            route().current('admin.*') ? 'bg-indigo-600 text-white shadow-sm' : 'text-gray-600 hover:text-gray-900 hover:bg-gray-100/80',
                            isCollapsed ? 'justify-center p-3' : 'space-x-3 px-3.5 py-2.5'
                        ]"
                        :title="isCollapsed ? 'Admin Panel' : ''"
                    >
                        <img src="/assets/imgs/icons/admin-panel.png" alt="admin_panel_icon" class="size-5 shrink-0">
                        <span v-if="!isCollapsed" class="truncate">Admin Panel</span>
                    </Link>

                   

                    <!-- Team Management Section if enabled -->
                    <template v-if="$page.props.jetstream.hasTeamFeatures && !isCollapsed">
                        <div class="pt-4 px-3 text-[11px] font-bold text-gray-400 uppercase tracking-wider mb-2">Team</div>
                        <div class="px-2">
                            <Dropdown align="left" width="60">
                                <template #trigger>
                                    <button type="button" class="w-full flex items-center justify-between px-3 py-2 border border-gray-200 text-sm font-medium rounded-xl text-gray-700 bg-gray-50 hover:bg-white transition">
                                        <span class="truncate">{{ $page.props.auth.user.current_team.name }}</span>
                                        <svg class="size-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 15L12 18.75 15.75 15m-7.5-6L12 5.25 15.75 9" />
                                        </svg>
                                    </button>
                                </template>

                                <template #content>
                                    <div class="w-60">
                                        <div class="block px-4 py-2 text-xs text-gray-400">Manage Team</div>
                                        <DropdownLink :href="route('teams.show', $page.props.auth.user.current_team)">
                                            Team Settings
                                        </DropdownLink>
                                        <DropdownLink v-if="$page.props.jetstream.canCreateTeams" :href="route('teams.create')">
                                            Create New Team
                                        </DropdownLink>

                                        <template v-if="$page.props.auth.user.all_teams.length > 1">
                                            <div class="border-t border-gray-200" />
                                            <div class="block px-4 py-2 text-xs text-gray-400">Switch Teams</div>
                                            <template v-for="team in $page.props.auth.user.all_teams" :key="team.id">
                                                <form @submit.prevent="switchToTeam(team)">
                                                    <DropdownLink as="button">
                                                        <div class="flex items-center">
                                                            <svg v-if="team.id == $page.props.auth.user.current_team_id" class="me-2 size-5 text-green-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                                            </svg>
                                                            <div>{{ team.name }}</div>
                                                        </div>
                                                    </DropdownLink>
                                                </form>
                                            </template>
                                        </template>
                                    </div>
                                </template>
                            </Dropdown>
                        </div>
                    </template>
                </div>

                <!-- Bottom User Profile Footer -->
                <div class="border-t border-gray-100 bg-gray-50/60 shrink-0" :class="isCollapsed ? 'p-2 flex justify-center' : 'p-4'">
                    <Dropdown align="top-left" width="48">
                        <template #trigger>
                            <button
                                class="rounded-xl hover:bg-white transition border border-transparent hover:border-gray-200"
                                :class="isCollapsed ? 'p-1.5' : 'w-full flex items-center justify-between p-2'"
                                :title="isCollapsed ? $page.props.auth.user.name : ''"
                            >
                                <div class="flex items-center truncate" :class="isCollapsed ? 'justify-center' : 'space-x-3 me-2'">
                                    <img
                                        class="size-9 rounded-full object-cover ring-1 ring-gray-200 shrink-0"
                                        :src="$page.props.auth.user.profile_photo_url"
                                        :alt="$page.props.auth.user.name"
                                    />
                                    <div v-if="!isCollapsed" class="text-left truncate">
                                        <div class="text-xs font-bold text-gray-900 truncate leading-tight">{{ $page.props.auth.user.name }}</div>
                                        <div class="text-[11px] text-gray-500 truncate mt-0.5">{{ $page.props.auth.user.email }}</div>
                                    </div>
                                </div>
                                <svg v-if="!isCollapsed" class="size-4 text-gray-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 15L12 18.75 15.75 15m-7.5-6L12 5.25 15.75 9" />
                                </svg>
                            </button>
                        </template>

                        <template #content>
                            <div class="block px-4 py-2 text-xs text-gray-400 font-semibold uppercase tracking-wider">
                                Account Management
                            </div>

                            <DropdownLink :href="route('profile.show')">
                                Profile Settings
                            </DropdownLink>

                            <DropdownLink v-if="$page.props.jetstream.hasApiFeatures" :href="route('api-tokens.index')">
                                API Tokens
                            </DropdownLink>

                            <div class="border-t border-gray-200" />

                            <form @submit.prevent="logout">
                                <DropdownLink as="button" class="text-red-600 font-semibold">
                                    Log Out
                                </DropdownLink>
                            </form>
                        </template>
                    </Dropdown>
                </div>
            </aside>

            <!-- Mobile Top Header Bar (Only visible on small screens < md) -->
            <div class="md:hidden fixed top-0 inset-x-0 z-40 bg-white border-b border-gray-200 px-4 py-3 flex items-center justify-between">
                <Link :href="route('dashboard')" class="flex items-center space-x-2">
                    <ApplicationMark class="h-8 w-auto" />
                </Link>

                <button
                    @click="showingMobileSidebar = !showingMobileSidebar"
                    class="p-2 rounded-xl text-gray-600 hover:bg-gray-100 transition"
                >
                    <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path v-if="!showingMobileSidebar" stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
                        <path v-else stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            <!-- Mobile Slide-over Sidebar Drawer -->
            <div v-if="showingMobileSidebar" class="md:hidden fixed inset-0 z-50 flex">
                <div class="fixed inset-0 bg-gray-900/50 backdrop-blur-xs" @click="showingMobileSidebar = false"></div>

                <aside class="relative bg-white w-72 h-full shadow-2xl flex flex-col justify-between z-10">
                    <div class="p-5 border-b border-gray-100 flex items-center justify-between">
                        <ApplicationMark class="h-8 w-auto" />
                        <button @click="showingMobileSidebar = false" class="text-gray-400 hover:text-gray-600">
                            <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    <div class="flex-1 px-4 py-4 space-y-1.5 overflow-y-auto">
                        <Link
                            :href="route('dashboard')"
                            @click="showingMobileSidebar = false"
                            class="flex items-center space-x-3 px-3.5 py-2.5 rounded-xl text-sm font-semibold transition"
                            :class="route().current('dashboard') ? 'bg-indigo-600 text-white' : 'text-gray-600 hover:bg-gray-100'"
                        >
                            <span>Dashboard</span>
                        </Link>

                        <Link
                            :href="route('chat.index')"
                            @click="showingMobileSidebar = false"
                            class="flex items-center justify-between px-3.5 py-2.5 rounded-xl text-sm font-semibold transition"
                            :class="route().current('chat.*') ? 'bg-indigo-600 text-white' : 'text-gray-600 hover:bg-gray-100'"
                        >
                            <span>Messenger</span>
                            <span v-if="$page.props.unread_messages_count > 0" class="px-2 py-0.5 text-xs font-bold bg-blue-600 text-white rounded-full">
                                {{ $page.props.unread_messages_count }}
                            </span>
                        </Link>

                        <Link
                            v-if="$page.props.auth.user?.is_admin"
                            :href="route('admin.dashboard')"
                            @click="showingMobileSidebar = false"
                            class="flex items-center space-x-3 px-3.5 py-2.5 rounded-xl text-sm font-semibold transition"
                            :class="route().current('admin.*') ? 'bg-indigo-600 text-white' : 'text-gray-600 hover:bg-gray-100'"
                        >
                            <span>Admin Panel</span>
                        </Link>

                        <button
                            v-if="$page.props.auth.user?.is_admin"
                            @click="showingMobileSidebar = false; openAnnouncementModal();"
                            class="w-full flex items-center space-x-3 px-3.5 py-2.5 rounded-xl text-sm font-semibold transition text-gray-600 hover:bg-gray-100"
                        >
                            <svg class="size-5 shrink-0 text-amber-500" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M10.34 15.84c-.688-.06-1.386-.09-2.09-.09H7.5a4.5 4.5 0 110-9h.75c.704 0 1.402-.03 2.09-.09m0 9.18c.253.962.584 1.892.985 2.783.247.55.06 1.21-.463 1.511l-.657.38c-.551.318-1.26.117-1.527-.461a20.845 20.845 0 01-1.44-4.282m3.102.069a18.03 18.03 0 01-.59-4.59c0-1.586.205-3.124.59-4.59m0 9.18a23.848 23.848 0 018.835 2.535M10.34 6.66a23.847 23.847 0 008.835-2.535m0 0A23.74 23.74 0 0018.795 3m.38 1.125a23.91 23.91 0 011.014 5.395m-1.014 8.855c-.118.38-.245.754-.38 1.125m.38-1.125a23.91 23.91 0 001.014-5.395m0-3.46c.495.41.811 1.035.811 1.73 0 .695-.316 1.32-.811 1.73m0-3.46a24.347 24.347 0 010 3.46" />
                            </svg>
                            <span>Announcements</span>
                        </button>

                        <div class="pt-4 border-t border-gray-100">
                            <Link :href="route('profile.show')" @click="showingMobileSidebar = false" class="block px-3.5 py-2 text-sm text-gray-700 hover:bg-gray-100 rounded-xl">
                                Profile Settings
                            </Link>
                            <button @click="logout" class="w-full text-left px-3.5 py-2 text-sm text-red-600 hover:bg-red-50 rounded-xl font-semibold">
                                Log Out
                            </button>
                        </div>
                    </div>
                </aside>
            </div>

            <!-- Right Main Area Container -->
            <div class="flex-1 flex flex-col min-h-0 overflow-hidden pt-14 md:pt-0">
                <!-- Page Heading (Slot) -->
                <header v-if="$slots.header" class="bg-white border-b border-gray-200/80 px-6 py-4 shrink-0 shadow-2xs">
                    <div class="max-w-8xl mx-auto">
                        <slot name="header" />
                    </div>
                </header>

                <!-- Main Content Body -->
                <main class="flex-1 flex flex-col min-h-0 overflow-y-auto">
                    <slot />
                </main>
            </div>
        </div>

        <!-- Broadcast System Announcement Modal -->
        <DialogModal :show="showingAnnouncementModal" @close="closeAnnouncementModal">
            <template #title>
                <div class="flex items-center space-x-3 text-gray-900 font-bold">
                    <div class="p-2 bg-amber-100 text-amber-600 rounded-xl">
                        <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M10.34 15.84c-.688-.06-1.386-.09-2.09-.09H7.5a4.5 4.5 0 110-9h.75c.704 0 1.402-.03 2.09-.09m0 9.18c.253.962.584 1.892.985 2.783.247.55.06 1.21-.463 1.511l-.657.38c-.551.318-1.26.117-1.527-.461a20.845 20.845 0 01-1.44-4.282m3.102.069a18.03 18.03 0 01-.59-4.59c0-1.586.205-3.124.59-4.59m0 9.18a23.848 23.848 0 018.835 2.535M10.34 6.66a23.847 23.847 0 008.835-2.535m0 0A23.74 23.74 0 0018.795 3m.38 1.125a23.91 23.91 0 011.014 5.395m-1.014 8.855c-.118.38-.245.754-.38 1.125m.38-1.125a23.91 23.91 0 001.014-5.395m0-3.46c.495.41.811 1.035.811 1.73 0 .695-.316 1.32-.811 1.73m0-3.46a24.347 24.347 0 010 3.46" />
                        </svg>
                    </div>
                    <span>Broadcast System Announcement</span>
                </div>
            </template>

            <template #content>
                <p class="text-xs text-gray-500 mb-4">
                    Send a high-priority system announcement to all registered users. This will create or update direct message threads with every user.
                </p>

                <div class="space-y-4">
                    <div>
                        <InputLabel for="modal_announcement_title" value="ANNOUNCEMENT TITLE" class="text-xs font-bold uppercase tracking-wider text-gray-500" />
                        <TextInput
                            id="modal_announcement_title"
                            v-model="announcementForm.title"
                            type="text"
                            class="mt-1.5 block w-full rounded-xl border-gray-200 focus:border-indigo-500 focus:ring-indigo-500 text-sm"
                            placeholder="e.g. Scheduled System Maintenance / Platform Update"
                            required
                        />
                        <InputError :message="announcementForm.errors.title" class="mt-1" />
                    </div>

                    <div>
                        <InputLabel for="modal_announcement_content" value="ANNOUNCEMENT CONTENT" class="text-xs font-bold uppercase tracking-wider text-gray-500" />
                        <textarea
                            id="modal_announcement_content"
                            v-model="announcementForm.content"
                            rows="5"
                            class="mt-1.5 block w-full rounded-xl border-gray-200 focus:border-indigo-500 focus:ring-indigo-500 text-sm"
                            placeholder="Type the full announcement message here..."
                            required
                        ></textarea>
                        <InputError :message="announcementForm.errors.content" class="mt-1" />
                    </div>
                </div>
            </template>

            <template #footer>
                <div class="flex items-center space-x-3">
                    <SecondaryButton @click="closeAnnouncementModal">
                        Cancel
                    </SecondaryButton>

                    <PrimaryButton
                        @click="sendAnnouncement"
                        :disabled="announcementForm.processing"
                        class="bg-indigo-600 hover:bg-indigo-700 active:bg-indigo-800 rounded-xl font-bold shadow-md shadow-indigo-500/20"
                    >
                        {{ announcementForm.processing ? 'Broadcasting...' : 'Broadcast to All Users' }}
                    </PrimaryButton>
                </div>
            </template>
        </DialogModal>
    </div>
</template>

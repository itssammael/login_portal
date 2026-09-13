<script setup>
import { ref, computed, onMounted } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import axios from 'axios';

const props = defineProps({
    latestAnnouncement: {
        type: Object,
        default: null,
    },
});

// Dynamic Current Month & Year formatting
const currentDate = new Date();
const currentMonthYear = computed(() => {
    return currentDate.toLocaleString('default', { month: 'long', year: 'numeric' });
});

// Announcements state
const announcement = ref(props.latestAnnouncement);
const loadingAnnouncement = ref(false);

const fetchLatestAnnouncement = async () => {
    try {
        loadingAnnouncement.value = true;
        const response = await axios.get('/api/announcements/latest');
        if (response.data && response.data.data) {
            announcement.value = response.data.data;
        }
    } catch (e) {
        console.error('Failed to fetch latest announcement:', e);
    } finally {
        loadingAnnouncement.value = false;
    }
};

// LGU Activities API state
const activities = ref([]);
const loadingActivities = ref(true);
const activitiesError = ref(null);

const fetchActivities = async () => {
    try {
        loadingActivities.value = true;
        activitiesError.value = null;
        const response = await axios.get('/api/lgu-activities');
        if (response.data && response.data.data) {
            activities.value = response.data.data;
        }
    } catch (err) {
        console.error('Error fetching LGU activities:', err);
        activitiesError.value = 'Unable to load LGU activities at this moment.';
    } finally {
        loadingActivities.value = false;
    }
};

onMounted(() => {
    fetchActivities();
    if (!announcement.value) {
        fetchLatestAnnouncement();
    }

    // Listen for broadcast announcement if Echo is active
    if (window.Echo) {
        window.Echo.channel('chat')
            .listen('MessageSent', (e) => {
                if (e.message && e.message.includes('Announcement:')) {
                    fetchLatestAnnouncement();
                }
            });
    }
});
</script>

<template>
    <AppLayout title="Dashboard">
        <div class="min-h-full bg-cream-300 p-4 sm:p-6 lg:p-8">
            <div class="max-w-8xl mx-auto">
                <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-stretch">
                    
                    <!-- Left Column (approx 58% width on desktop) -->
                    <div class="lg:col-span-7 flex flex-col gap-6">
                        
                        <!-- Panel 1: Latest System Announcement (Vibrant Sky Blue from Image 3) -->
                        <div class="bg-[#60a5fa] rounded-3xl p-6 sm:p-7 shadow-sm border border-blue-400/40 transition hover:shadow-md">
                            <div class="flex items-center justify-between gap-3 mb-2">
                                <h2 class="text-xl sm:text-2xl font-extrabold text-gray-950 tracking-tight">
                                    Latest System Announcement
                                </h2>
                                <span v-if="announcement" class="shrink-0 px-3 py-1 rounded-full text-xs font-extrabold uppercase tracking-wider bg-golden-500 text-amber-950 shadow-xs">
                                    BROADCAST
                                </span>
                            </div>

                            <!-- Announcement Content -->
                            <div class="mt-3">
                                <template v-if="announcement">
                                    <h3 class="text-base sm:text-lg font-bold text-gray-950 leading-snug">
                                        {{ announcement.title }}
                                    </h3>
                                    <p class="text-sm text-gray-900 leading-relaxed mt-2 whitespace-pre-line">
                                        {{ announcement.content }}
                                    </p>
                                    <div class="mt-4 pt-3 border-t border-blue-300/50 flex flex-wrap items-center justify-between gap-2 text-xs text-gray-900/90 font-semibold">
                                        <div class="flex items-center space-x-1.5">
                                            <svg class="size-4 text-gray-900" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z" />
                                            </svg>
                                            <span>From: {{ announcement.author || 'System Administrator' }}</span>
                                        </div>
                                        <div v-if="announcement.formatted_date" class="flex items-center space-x-1">
                                            <svg class="size-3.5 text-gray-900" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                                            </svg>
                                            <span>{{ announcement.formatted_date }}</span>
                                        </div>
                                    </div>
                                </template>

                                <template v-else-if="loadingAnnouncement">
                                    <div class="animate-pulse space-y-2 py-2">
                                        <div class="h-4 bg-blue-300/60 rounded w-2/3"></div>
                                        <div class="h-3 bg-blue-300/40 rounded w-full"></div>
                                        <div class="h-3 bg-blue-300/40 rounded w-4/5"></div>
                                    </div>
                                </template>

                                <template v-else>
                                    <p class="text-sm font-medium text-blue-950 italic">
                                        No recent system announcements. All services are currently operational.
                                    </p>
                                </template>
                            </div>
                        </div>

                        <!-- Connected Systems (SSO Portal Applications) -->
                        <div class="bg-cream-200 rounded-3xl p-6 sm:p-7 shadow-xs border border-cream-500/50 flex-1 flex flex-col justify-between transition hover:shadow-sm">
                            <div class="flex items-center justify-between gap-3 mb-4">
                                <div class="flex items-center space-x-2.5">
                                    <div class="p-2 bg-amber-500/20 text-amber-950 rounded-xl">
                                        <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 16.875h3.375m0 0h3.375m-3.375 0V13.5m0 3.375v3.375M6 10.5h2.25a2.25 2.25 0 002.25-2.25V6a2.25 2.25 0 00-2.25-2.25H6A2.25 2.25 0 003.75 6v2.25A2.25 2.25 0 006 10.5zm0 9.75h2.25a2.25 2.25 0 002.25-2.25v-2.25a2.25 2.25 0 00-2.25-2.25H6a2.25 2.25 0 00-2.25 2.25v2.25A2.25 2.25 0 006 20.25zM15 10.5h2.25a2.25 2.25 0 002.25-2.25V6a2.25 2.25 0 00-2.25-2.25H15A2.25 2.25 0 0012.75 6v2.25a2.25 2.25 0 002.25 2.25z" />
                                        </svg>
                                    </div>
                                    <h2 class="text-xl sm:text-2xl font-extrabold text-gray-950 tracking-tight">
                                        My Systems
                                    </h2>
                                </div>
                                <span class="px-3 py-1 rounded-full text-xs font-extrabold uppercase tracking-wider bg-forest-900 text-white shadow-xs">
                                    SSO ENABLED
                                </span>
                            </div>

                            <p class="text-xs text-gray-700 font-medium mb-4">
                                Authenticate once with Login Portal to seamlessly access supported external applications.
                            </p>

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 my-auto">
                                <!-- System Card 1: LFews 2.0 -->
                                <div class="bg-[#fffef7] rounded-2xl p-5 border border-cream-500/60 shadow-2xs hover:shadow-md transition duration-200 flex flex-col justify-between group">
                                    <div>
                                        <div class="flex items-center justify-between mb-3">
                                            <div class="size-10 rounded-xl bg-blue-500/15 text-blue-800 flex items-center justify-center font-bold text-lg">
                                                🌊
                                            </div>
                                            <span class="text-[10px] font-bold text-blue-900 bg-blue-100 px-2 py-0.5 rounded-full uppercase">
                                                Flood Warning
                                            </span>
                                        </div>
                                        <h3 class="font-bold text-base text-gray-950 group-hover:text-blue-700 transition">
                                            LFews 2.0
                                        </h3>
                                        <p class="text-xs text-gray-600 mt-1 leading-relaxed">
                                            Local Flood Early Warning System monitoring & sensor analytics.
                                        </p>
                                    </div>

                                    <a
                                        href="/sso/launch/lfews"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="mt-4 inline-flex items-center justify-center space-x-2 w-full px-4 py-2.5 bg-forest-900 hover:bg-forest-950 text-white font-bold text-xs rounded-xl shadow-xs transition cursor-pointer"
                                    >
                                        <span>Open System</span>
                                        <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" />
                                        </svg>
                                    </a>
                                </div>

                                <!-- System Card 2: Project Tracker -->
                                <div class="bg-[#fffef7] rounded-2xl p-5 border border-cream-500/60 shadow-2xs hover:shadow-md transition duration-200 flex flex-col justify-between group">
                                    <div>
                                        <div class="flex items-center justify-between mb-3">
                                            <div class="size-10 rounded-xl bg-amber-500/15 text-amber-800 flex items-center justify-center font-bold text-lg">
                                                📋
                                            </div>
                                            <span class="text-[10px] font-bold text-amber-900 bg-amber-100 px-2 py-0.5 rounded-full uppercase">
                                                Management
                                            </span>
                                        </div>
                                        <h3 class="font-bold text-base text-gray-950 group-hover:text-amber-800 transition">
                                            Project Tracker
                                        </h3>
                                        <p class="text-xs text-gray-600 mt-1 leading-relaxed">
                                            Task board, workflow tracking & department activities portal.
                                        </p>
                                    </div>

                                    <a
                                        href="/sso/launch/project_tracker"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="mt-4 inline-flex items-center justify-center space-x-2 w-full px-4 py-2.5 bg-forest-900 hover:bg-forest-950 text-white font-bold text-xs rounded-xl shadow-xs transition cursor-pointer"
                                    >

                                        <span>Open System</span>
                                        <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" />
                                        </svg>
                                    </a>
                                </div>
                            </div>
                        </div>


                    </div>

                    <!-- Right Column (approx 42% width on desktop) -->
                    <div class="lg:col-span-5 flex flex-col gap-6">

                        <!-- Panel 3: Weather Widget (Vibrant Blue Gradient with Forest Green & Soft Cream Eclipse Circles) -->
                        <div class="rounded-3xl p-6 sm:p-7 shadow-md relative overflow-hidden text-white bg-gradient-to-r from-[#38bdf8] via-[#60a5fa] to-[#3b82f6] flex items-center justify-between min-h-[175px]">
                            <!-- Weather Information (Left) -->
                            <div class="z-10 flex flex-col justify-between h-full">
                                <!-- Temperature -->
                                <div class="text-5xl sm:text-6xl font-light tracking-tight text-white leading-none">
                                    20°
                                </div>

                                <!-- Condition -->
                                <div class="flex items-center space-x-2 mt-3 text-white font-medium text-sm sm:text-base">
                                    <div class="flex items-center justify-center size-5 bg-white/20 backdrop-blur-xs rounded-full p-0.5">
                                        <svg class="size-3.5 text-white" viewBox="0 0 24 24" fill="currentColor">
                                            <path d="M19.35 10.04C18.67 6.59 15.64 4 12 4 9.11 4 6.6 5.64 5.35 8.04 2.34 8.36 0 10.91 0 14c0 3.31 2.69 6 6 6h13c2.76 0 5-2.24 5-5 0-2.64-2.05-4.78-4.65-4.96zM19 18H6c-2.21 0-4-1.79-4-4 0-2.05 1.53-3.76 3.56-3.97l1.07-.11.5-.95C8.08 7.14 9.94 6 12 6c2.62 0 4.88 1.86 5.39 4.43l.3 1.5 1.53.11c1.56.1 2.78 1.41 2.78 2.96 0 1.65-1.35 3-3 3z" />
                                        </svg>
                                    </div>
                                    <span class="tracking-wide">Mostly Cloudy</span>
                                </div>

                                <!-- Precipitation & Wind -->
                                <div class="mt-3 text-xs text-white/90 font-normal space-y-0.5">
                                    <div>Precipitation: 20%</div>
                                    <div>Wind: 3 mph</div>
                                </div>
                            </div>

                            <!-- Stylized Graphic (Right): Overlapping Eclipse Circles matching Image 3 -->
                            <div class="relative w-36 h-36 sm:w-40 sm:h-40 shrink-0 flex items-center justify-center">
                                <!-- Subtle background radial ring -->
                                <div class="absolute inset-0 rounded-full border border-white/10 scale-110"></div>
                                <div class="absolute inset-0 rounded-full bg-radial from-white/10 to-transparent"></div>

                                <!-- Dark Forest Green Circle -->
                                <div class="absolute size-28 sm:size-32 rounded-full bg-gradient-to-tr from-[#0f2e1e] via-[#1b4332] to-[#2d6a4f] shadow-2xl ring-2 ring-white/10 transform translate-x-2"></div>

                                <!-- Overlapping Pale Soft Cream Circle -->
                                <div class="absolute size-18 sm:size-20 rounded-full bg-[#fef9c3] shadow-xl ring-1 ring-white/40 transform -translate-x-4 translate-y-2"></div>
                            </div>
                        </div>

                        <!-- Panel 4: LGU Activities for {current_month & current_year} (Warm Cream from Image 3) -->
                        <div class="bg-cream-200 rounded-3xl p-6 sm:p-7 shadow-xs border border-cream-500/50 flex-1 flex flex-col transition hover:shadow-sm">
                            <div class="flex items-center justify-between gap-3 mb-4 pb-2 border-b border-cream-500/40">
                                <h2 class="text-xl sm:text-2xl font-extrabold text-gray-950 tracking-tight leading-tight">
                                    LGU Activities for {{ currentMonthYear }}
                                </h2>
                                <button
                                    @click="fetchActivities"
                                    type="button"
                                    class="p-1.5 text-forest-900 hover:text-forest-950 hover:bg-cream-400/50 rounded-xl transition cursor-pointer"
                                    title="Refresh activities from API"
                                >
                                    <svg
                                        class="size-4.5"
                                        :class="{ 'animate-spin': loadingActivities }"
                                        fill="none"
                                        viewBox="0 0 24 24"
                                        stroke-width="2"
                                        stroke="currentColor"
                                    >
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99" />
                                    </svg>
                                </button>
                            </div>

                            <!-- Activities List Content from API -->
                            <div class="flex-1 flex flex-col justify-start">
                                
                                <!-- Loading State -->
                                <div v-if="loadingActivities" class="space-y-3 py-3">
                                    <div v-for="i in 3" :key="i" class="animate-pulse bg-white/70 rounded-2xl p-4 flex space-x-3 items-center border border-cream-500/30">
                                        <div class="size-12 bg-cream-400/80 rounded-xl shrink-0"></div>
                                        <div class="flex-1 space-y-2">
                                            <div class="h-3.5 bg-cream-400/80 rounded w-3/4"></div>
                                            <div class="h-2.5 bg-cream-400/60 rounded w-1/2"></div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Error State -->
                                <div v-else-if="activitiesError" class="p-4 bg-red-100/80 border border-red-200 text-red-800 rounded-2xl text-xs text-center my-auto">
                                    {{ activitiesError }}
                                    <button @click="fetchActivities" class="block mx-auto mt-2 underline font-bold">Try again</button>
                                </div>

                                <!-- Loaded Activities List -->
                                <div v-else-if="activities.length > 0" class="space-y-3 max-h-[440px] overflow-y-auto pr-1">
                                    <div
                                        v-for="item in activities"
                                        :key="item.id"
                                        class="bg-[#fffef7] hover:bg-white backdrop-blur-xs rounded-2xl p-3.5 sm:p-4 border border-cream-500/40 shadow-2xs hover:shadow-xs transition duration-150 flex items-start space-x-3.5 group"
                                    >
                                        <!-- Date Badge (Golden/Tan from Image 3) -->
                                        <div class="shrink-0 flex flex-col items-center justify-center size-12 sm:size-13 bg-golden-200/70 rounded-xl border border-golden-300/80 text-amber-950 group-hover:bg-forest-900 group-hover:text-white transition">
                                            <span class="text-[10px] font-extrabold uppercase leading-none tracking-wider">{{ item.month_short }}</span>
                                            <span class="text-base sm:text-lg font-black leading-tight mt-0.5">{{ item.day }}</span>
                                        </div>

                                        <!-- Detail Section -->
                                        <div class="flex-1 min-w-0">
                                            <div class="flex items-center justify-between gap-2">
                                                <span class="px-2 py-0.5 rounded-md text-[10px] font-bold uppercase tracking-wider bg-golden-200 text-amber-900">
                                                    {{ item.category }}
                                                </span>
                                                <span
                                                    v-if="item.status === 'completed'"
                                                    class="text-[10px] font-bold text-white bg-forest-500 px-2 py-0.5 rounded-md uppercase"
                                                >
                                                    COMPLETED
                                                </span>
                                                <span
                                                    v-else
                                                    class="text-[10px] font-bold text-forest-950 bg-forest-400 px-2 py-0.5 rounded-md uppercase"
                                                >
                                                    UPCOMING
                                                </span>
                                            </div>

                                            <h3 class="font-bold text-sm text-gray-900 mt-1 leading-snug truncate group-hover:text-forest-900 transition">
                                                {{ item.title }}
                                            </h3>

                                            <div class="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] text-gray-600">
                                                <div v-if="item.time" class="flex items-center space-x-1">
                                                    <svg class="size-3 text-gray-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                                                    </svg>
                                                    <span>{{ item.time }}</span>
                                                </div>
                                                <div v-if="item.location" class="flex items-center space-x-1 truncate">
                                                    <svg class="size-3 text-gray-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z" />
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z" />
                                                    </svg>
                                                    <span class="truncate">{{ item.location }}</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Empty State -->
                                <div v-else class="text-center py-10 px-4 my-auto bg-white/40 rounded-2xl border border-cream-500/30">
                                    <svg class="size-9 text-forest-900/60 mx-auto mb-2" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.253M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5" />
                                    </svg>
                                    <p class="text-sm font-bold text-gray-800">No scheduled activities for this month</p>
                                    <p class="text-xs text-gray-600 mt-0.5">Check back later for new municipal advisories and events.</p>
                                </div>

                            </div>
                        </div>

                    </div>

                </div>
            </div>
        </div>
    </AppLayout>
</template>


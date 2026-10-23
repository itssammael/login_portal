<script setup>
import { ref, computed, onBeforeUnmount, onMounted } from 'vue';
import { Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import axios from 'axios';

const props = defineProps({
    latestAnnouncement: {
        type: Object,
        default: null,
    },
    ssoClients: {
        type: Array,
        default: () => [],
    },
});

const getSystemEmoji = (system) => {
    const id = (system.client_id || '').toLowerCase();
    const name = (system.name || '').toLowerCase();
    if (id.includes('lfews') || name.includes('flood') || name.includes('lfews')) return '🌊';
    if (id.includes('tracker') || name.includes('project') || name.includes('track')) return '📋';
    if (id.includes('gis') || name.includes('map')) return '🗺️';
    if (id.includes('health')) return '🏥';
    return '🔐';
};

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

const weatherStations = ref([]);
const waterLevels = ref([]);
const loadingMonitoring = ref(true);
const monitoringError = ref(false);
let monitoringRefreshTimer;

const primaryWeather = computed(() => weatherStations.value[0] || null);
const weatherObservation = computed(() => primaryWeather.value?.latest_observation || null);
const weatherCondition = computed(() => {
    if (!weatherObservation.value) return 'Loading conditions';
    if (Number(weatherObservation.value.precipitation_rate) > 0) return 'Rain showers';
    if (Number(weatherObservation.value.humidity) >= 80) return 'Mostly Cloudy';
    if (Number(weatherObservation.value.solar_radiation) > 300) return 'Sunny';

    return 'Partly Cloudy';
});

const formatNumber = (value, digits = 1) => Number(value || 0).toFixed(digits);
const formatReadingTime = (value) => {
    if (!value) return 'Awaiting reading';

    return new Intl.DateTimeFormat('en-PH', {
        month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit', hour12: false,
    }).format(new Date(value.replace(' ', 'T')));
};
const waterLevelWidth = (sensor) => {
    const level = Number(sensor.latest_data?.sensor_data || 0);
    const dangerLevel = Number(sensor.level_4 || 1);

    return `${Math.min(Math.max((level / dangerLevel) * 100, 0), 100)}%`;
};
const thresholdPosition = (threshold, dangerLevel) => `${Math.min((Number(threshold) / Number(dangerLevel)) * 100, 96)}%`;
const waterLevelTone = (sensor) => {
    const level = Number(sensor.latest_data?.sensor_data || 0);
    if (level >= Number(sensor.level_4)) return 'bg-red-500';
    if (level >= Number(sensor.level_3)) return 'bg-orange-500';
    if (level >= Number(sensor.level_2)) return 'bg-amber-400';

    return 'bg-sky-500';
};

const fetchMonitoring = async () => {
    try {
        loadingMonitoring.value = true;
        monitoringError.value = false;

        // 1. Try local same-origin proxy first (eliminates cross-origin CORS barriers)
        try {
            const proxyRes = await fetch('/api/monitoring/live', {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            });
            if (proxyRes.ok) {
                const proxyData = await proxyRes.json();
                if (proxyData.status === 'success' && (proxyData.weather?.length > 0 || proxyData.water?.length > 0)) {
                    weatherStations.value = proxyData.weather || [];
                    waterLevels.value = proxyData.water || [];
                    return;
                }
            }
        } catch (proxyErr) {
            console.warn('Local monitoring proxy error, falling back to direct public endpoints:', proxyErr);
        }

        // 2. Fallback: Direct public endpoints with omitted credentials to comply with wildcard CORS
        const fetchPublic = async (url) => {
            const res = await fetch(url, {
                method: 'GET',
                credentials: 'omit',
                headers: { Accept: 'application/json' },
            });
            if (!res.ok) throw new Error(`HTTP error ${res.status}`);
            return res.json();
        };

        const results = await Promise.allSettled([
            fetchPublic('https://lfews.lguportal.ink/api/latest-weather-observation'),
            fetchPublic('https://lfews.lguportal.ink/api/live-water-level-data'),
        ]);

        const weatherResult = results[0].status === 'fulfilled' ? results[0].value?.data : null;
        const waterResult = results[1].status === 'fulfilled' ? results[1].value?.data : null;

        if (!weatherResult && !waterResult) {
            throw new Error('Both public monitoring endpoints failed to load');
        }

        weatherStations.value = weatherResult || [];
        waterLevels.value = waterResult || [];
    } catch (error) {
        console.error('Unable to load live weather and river monitoring data:', error);
        monitoringError.value = true;
    } finally {
        loadingMonitoring.value = false;
    }
};

onMounted(() => {
    fetchActivities();
    fetchMonitoring();
    monitoringRefreshTimer = window.setInterval(fetchMonitoring, 300000);
    if (!announcement.value) {
        fetchLatestAnnouncement();
    }

    // Listen for broadcast announcement if Echo is active
    if (window.Echo) {
        window.Echo.channel('public-chat')
            .listen('.message.sent', (e) => {
                if (e.message && e.message.includes('Announcement:')) {
                    fetchLatestAnnouncement();
                }
            });
    }
});

onBeforeUnmount(() => {
    window.clearInterval(monitoringRefreshTimer);
});
</script>

<template>
    <AppLayout title="Dashboard">
        <div class="min-h-full bg-cream-300 p-4 sm:p-6 lg:p-8">
            <div class="max-w-8xl mx-auto">
                <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-stretch">
                    
                    <!-- Left Column (approx 58% width on desktop) -->
                    <div class="lg:col-span-7 flex flex-col gap-6">
                        
                        <!-- Panel 1: Latest System Announcement (Green Gradient within #22C55E to #4ADE80) -->
                        <div class="bg-gradient-to-r from-[#22c55e] to-[#4ade80] rounded-3xl p-6 sm:p-7 shadow-sm border border-[#16a34a]/30 transition hover:shadow-md">
                            <div class="flex items-center justify-between gap-3 mb-2">
                                <h2 class="text-xl sm:text-2xl font-extrabold text-forest-950 tracking-tight">
                                    Latest System Announcement
                                </h2>
                                <span v-if="announcement" class="shrink-0 px-3 py-1 rounded-full text-xs font-extrabold uppercase tracking-wider bg-golden-500 text-amber-950 shadow-xs">
                                    BROADCAST
                                </span>
                            </div>

                            <!-- Announcement Content -->
                            <div class="mt-3">
                                <template v-if="announcement">
                                    <h3 class="text-base sm:text-lg font-bold text-forest-950 leading-snug">
                                        {{ announcement.title }}
                                    </h3>
                                    <p class="text-sm text-forest-950/90 font-medium leading-relaxed mt-2 whitespace-pre-line">
                                        {{ announcement.content }}
                                    </p>
                                    <div class="mt-4 pt-3 border-t border-forest-700/25 flex flex-wrap items-center justify-between gap-2 text-xs text-forest-950 font-bold">
                                        <div class="flex items-center space-x-1.5">
                                            <svg class="size-4 text-forest-950" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z" />
                                            </svg>
                                            <span>From: {{ announcement.author || 'System Administrator' }}</span>
                                        </div>
                                        <div v-if="announcement.formatted_date" class="flex items-center space-x-1">
                                            <svg class="size-3.5 text-forest-950" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                                            </svg>
                                            <span>{{ announcement.formatted_date }}</span>
                                        </div>
                                    </div>
                                </template>

                                <template v-else-if="loadingAnnouncement">
                                    <div class="animate-pulse space-y-2 py-2">
                                        <div class="h-4 bg-forest-700/20 rounded w-2/3"></div>
                                        <div class="h-3 bg-forest-700/15 rounded w-full"></div>
                                        <div class="h-3 bg-forest-700/15 rounded w-4/5"></div>
                                    </div>
                                </template>

                                <template v-else>
                                    <p class="text-sm font-semibold text-forest-950 italic">
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

                            <div v-if="ssoClients && ssoClients.length > 0" class="grid grid-cols-1 sm:grid-cols-2 justify-items-center gap-4 my-auto">
                                <div
                                    v-for="system in ssoClients"
                                    :key="system.id"
                                    class="w-[220px] rounded-2xl p-4 bg-white/80 border border-cream-500/60 shadow-2xs hover:shadow-md transition duration-200 flex flex-col items-center justify-between group"
                                >
                                    <!-- SSO Client Icon -->
                                    <div v-if="system.icon_url" class="size-24 rounded-2xl bg-white p-2 border border-cream-400/50 shadow-2xs flex items-center justify-center overflow-hidden shrink-0 mb-3">
                                        <img :src="system.icon_url" :alt="system.name" class="w-full h-full object-contain rounded-lg" />
                                    </div>
                                    <div v-else class="size-16 rounded-2xl bg-forest-900/10 text-forest-900 flex items-center justify-center font-bold text-3xl shrink-0 mb-3">
                                        {{ getSystemEmoji(system) }}
                                    </div>

                                    <!-- Client / Portal Name -->
                                    <h3 class="font-bold text-center text-base text-gray-950 leading-snug truncate mb-4 w-full">
                                        {{ system.name }}
                                    </h3>

                                    <!-- Open System Button -->
                                    <a
                                        :href="system.launch_url"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="inline-flex items-center justify-center space-x-2 w-full px-4 py-2.5 bg-forest-900 hover:bg-forest-950 text-white font-bold text-xs rounded-xl shadow-xs transition cursor-pointer"
                                    >
                                        <span>Open System</span>
                                        <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" />
                                        </svg>
                                    </a>
                                </div>
                            </div>
                            <div v-else class="text-center py-8 px-4 my-auto bg-white/40 rounded-2xl border border-cream-500/30">
                                <svg class="size-8 text-forest-900/50 mx-auto mb-2" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 16.875h3.375m0 0h3.375m-3.375 0V13.5m0 3.375v3.375M6 10.5h2.25a2.25 2.25 0 002.25-2.25V6a2.25 2.25 0 00-2.25-2.25H6A2.25 2.25 0 003.75 6v2.25A2.25 2.25 0 006 10.5zm0 9.75h2.25a2.25 2.25 0 002.25-2.25v-2.25a2.25 2.25 0 00-2.25-2.25H6a2.25 2.25 0 00-2.25 2.25v2.25A2.25 2.25 0 006 20.25zM15 10.5h2.25a2.25 2.25 0 002.25-2.25V6a2.25 2.25 0 00-2.25-2.25H15A2.25 2.25 0 0012.75 6v2.25a2.25 2.25 0 002.25 2.25z" />
                                </svg>
                                <p class="text-sm font-bold text-gray-800">No connected systems bound</p>
                                <p class="text-xs text-gray-600 mt-0.5 mb-3">You do not have any external systems linked to your account yet.</p>
                                <Link :href="route('sso.connected-systems')" class="inline-flex items-center px-3.5 py-2 bg-forest-900 hover:bg-forest-950 text-white rounded-xl text-xs font-bold shadow-xs transition">
                                    Manage Connected Systems
                                </Link>
                            </div>
                        </div>


                    </div>

                    <!-- Right Column (approx 42% width on desktop) -->
                    <div class="lg:col-span-5 flex flex-col gap-6">

                        <!-- Panel 3: Live Weather & River Monitoring -->
                        <section class="h-[175px] overflow-hidden rounded-3xl border border-sky-200 bg-sky-50 shadow-md" aria-labelledby="monitoring-title">
                            <div v-if="loadingMonitoring" class="grid h-full grid-cols-5 animate-pulse">
                                <div class="col-span-2 bg-sky-600/70"></div>
                                <div class="col-span-3 space-y-3 p-5"><div class="h-5 w-2/3 rounded bg-sky-100"></div><div class="h-10 rounded-2xl bg-white"></div><div class="h-10 rounded-2xl bg-white"></div></div>
                            </div>
                            <div v-else-if="monitoringError" class="flex h-full flex-col items-center justify-center gap-2 p-5 text-center"><svg class="size-7 text-sky-700" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 9v3.75m0 3h.008M4.93 19h14.14c1.54 0 2.5-1.67 1.73-3L13.73 3.5c-.77-1.33-2.69-1.33-3.46 0L3.2 16c-.77 1.33.19 3 1.73 3Z" /></svg><p class="text-xs font-bold text-slate-700">Live monitoring is temporarily unavailable.</p><button type="button" class="rounded-lg bg-sky-600 px-3 py-1.5 text-[11px] font-bold text-white hover:bg-sky-700" @click="fetchMonitoring">Try again</button></div>
                            <div v-else class="grid h-full grid-cols-5">
                                <div class="relative col-span-2 overflow-hidden bg-gradient-to-br from-blue-700 via-sky-600 to-cyan-400 p-4 text-white">
                                    <div class="absolute -right-5 top-7 size-20 rounded-full bg-amber-300/90 shadow-[0_0_22px_rgba(250,204,21,.85)]"></div>
                                    <div class="absolute right-1 top-14 h-9 w-20 rounded-full bg-white/90 shadow-lg before:absolute before:-left-3 before:top-3 before:size-7 before:rounded-full before:bg-white/90"></div>
                                    <div class="relative z-10"><h2 id="monitoring-title" class="text-sm font-black leading-none">Weather Conditions</h2><p class="mt-1 text-[9px] font-semibold text-sky-100">Latest observations from {{ weatherStations.length }} stations</p><p class="mt-4 text-4xl font-light leading-none">{{ formatNumber(weatherObservation?.temperature) }}°</p><p class="mt-2 max-w-[7.5rem] truncate text-xs font-black">{{ primaryWeather?.name || 'No station data' }}</p><div class="mt-1 flex items-center gap-1.5 text-[10px] font-medium text-sky-50"><span class="size-1.5 rounded-full bg-emerald-300"></span><span>{{ weatherCondition }} · {{ formatReadingTime(weatherObservation?.date_time) }}</span></div></div>
                                    <div class="absolute inset-x-3 bottom-2 flex gap-1.5"><div v-for="station in weatherStations.slice(0, 3)" :key="station.name" class="min-w-0 flex-1 rounded-lg border border-white/20 bg-blue-950/15 px-1.5 py-1 backdrop-blur-sm"><p class="truncate text-[8px] font-bold">{{ station.name }}</p><p class="text-xs font-black">{{ formatNumber(station.latest_observation?.temperature) }}°</p></div></div>
                                </div>
                                <div class="col-span-3 bg-[radial-gradient(circle_at_top_right,_rgba(186,230,253,.8),_transparent_43%)] p-3 sm:p-4"><div class="flex items-center gap-2"><svg class="size-5 shrink-0 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M3 7c2.1 0 2.1 2 4.2 2s2.1-2 4.2-2 2.1 2 4.2 2 2.1-2 4.2-2M3 12c2.1 0 2.1 2 4.2 2s2.1-2 4.2-2 2.1 2 4.2 2 2.1-2 4.2-2M3 17c2.1 0 2.1 2 4.2 2s2.1-2 4.2-2 2.1 2 4.2 2 2.1-2 4.2-2" /></svg><div><h3 class="text-sm font-black leading-none text-slate-800">River Water Levels</h3><p class="mt-0.5 text-[9px] font-semibold text-slate-500">Latest readings from {{ waterLevels.length }} sensors</p></div><button type="button" class="ml-auto rounded-md p-1 text-sky-700 hover:bg-sky-100" title="Refresh monitoring data" @click="fetchMonitoring"><svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8 8 0 0 0 4.582 9m0 0H9m11 11v-5h-.581m0 0A8 8 0 0 1 4.582 15m14.837 0H15" /></svg></button></div><div class="mt-2 space-y-1.5"><div v-for="sensor in waterLevels.slice(0, 3)" :key="sensor.name" class="grid grid-cols-[minmax(0,1fr)_42px_72px] items-center gap-2 rounded-xl bg-white/80 px-2 py-1.5 shadow-sm"><div class="min-w-0"><div class="flex items-center gap-1.5"><span class="size-1.5 shrink-0 rounded-full" :class="sensor.state ? 'bg-emerald-400' : 'bg-gray-400'"></span><p class="truncate text-[10px] font-black text-slate-800">{{ sensor.name }}</p></div><p class="mt-0.5 truncate pl-3 text-[8px] font-medium text-slate-400">{{ formatReadingTime(sensor.latest_data?.date) }}</p></div><p class="text-right text-sm font-black leading-none text-slate-800">{{ formatNumber(sensor.latest_data?.sensor_data, 2) }}<span class="block mt-0.5 text-[8px] font-bold text-slate-400">cm</span></p><div class="relative h-2 overflow-visible rounded-full bg-sky-100"><div class="absolute inset-y-0 left-0 rounded-full" :class="waterLevelTone(sensor)" :style="{ width: waterLevelWidth(sensor) }"></div><span class="absolute -top-3.5 text-[7px] font-black text-amber-600" :style="{ left: thresholdPosition(sensor.level_2, sensor.level_4) }">L2</span><span class="absolute -top-3.5 text-[7px] font-black text-orange-600" :style="{ left: thresholdPosition(sensor.level_3, sensor.level_4) }">L3</span><span class="absolute -top-3.5 right-0 text-[7px] font-black text-red-600">L4</span></div></div></div></div>
                            </div>
                        </section>

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

<script setup>
import { ref, computed } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import AdminNav from '@/Components/AdminNav.vue';
import DialogModal from '@/Components/DialogModal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import DangerButton from '@/Components/DangerButton.vue';
import TextInput from '@/Components/TextInput.vue';
import InputLabel from '@/Components/InputLabel.vue';

const props = defineProps({
    stats: {
        type: Object,
        required: true,
    },
    timeline: {
        type: Array,
        default: () => [],
    },
    categories: {
        type: Array,
        default: () => [],
    },
    topProbedUrls: {
        type: Array,
        default: () => [],
    },
    topOffendingIps: {
        type: Array,
        default: () => [],
    },
    threatLogs: {
        type: Object,
        required: true,
    },
    blockedIps: {
        type: Array,
        default: () => [],
    },
    recentHealingLogs: {
        type: Array,
        default: () => [],
    },
    diagnosticFindings: {
        type: Array,
        default: () => [],
    },
    filters: {
        type: Object,
        default: () => ({}),
    },
});

// Filter state
const filterForm = ref({
    search: props.filters.search || '',
    threat_type: props.filters.threat_type || '',
    severity: props.filters.severity || '',
    resolved: props.filters.resolved ?? '',
});

const applyFilters = () => {
    router.get(route('admin.security.index'), filterForm.value, {
        preserveState: true,
        preserveScroll: true,
    });
};

const resetFilters = () => {
    filterForm.value = {
        search: '',
        threat_type: '',
        severity: '',
        resolved: '',
    };
    applyFilters();
};

// Modals state
const inspectingThreat = ref(null);
const showQuarantineModal = ref(false);
const showQuarantinedListModal = ref(false);
const isHealingRunning = ref(false);

const quarantineForm = useForm({
    ip_address: '',
    reason: '',
    duration_minutes: 60,
});

const openQuarantineModal = (ip = '') => {
    quarantineForm.reset();
    quarantineForm.ip_address = ip;
    quarantineForm.reason = ip ? 'Manual administrative quarantine' : '';
    showQuarantineModal.value = true;
};

const submitQuarantine = () => {
    quarantineForm.post(route('admin.security.block-ip'), {
        preserveScroll: true,
        onSuccess: () => {
            showQuarantineModal.value = false;
            quarantineForm.reset();
        },
    });
};

const unblockIp = (blockedIp) => {
    if (confirm(`Are you sure you want to unblock IP address ${blockedIp.ip_address}?`)) {
        router.delete(route('admin.security.unblock-ip', blockedIp.id), {
            preserveScroll: true,
        });
    }
};

const resolveThreat = (threat) => {
    router.post(route('admin.security.resolve', threat.id), {}, {
        preserveScroll: true,
    });
};

const triggerSelfHealing = () => {
    isHealingRunning.value = true;
    router.post(route('admin.security.self-heal'), {}, {
        preserveScroll: true,
        onFinish: () => {
            isHealingRunning.value = false;
        },
    });
};

const toggleAutoDefense = () => {
    router.post(route('admin.security.toggle-auto-defense'), {}, {
        preserveScroll: true,
    });
};

// Max timeline calculation for proportional height
const maxTimelineTotal = computed(() => {
    const max = Math.max(...props.timeline.map((t) => t.total), 1);
    return max;
});

const formatTimeLabel = (point) => {
    if (!point) return '';
    if (point.timestamp) {
        try {
            const d = new Date(point.timestamp);
            return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', hour12: false });
        } catch (e) {
            return point.label;
        }
    }
    return point.label;
};

// Severity styling helpers
const getSeverityBadgeClass = (severity) => {
    switch (severity) {
        case 'critical':
            return 'bg-red-500/15 text-red-700 border-red-300/80';
        case 'high':
            return 'bg-amber-500/15 text-amber-800 border-amber-300/80';
        case 'medium':
            return 'bg-yellow-500/15 text-yellow-800 border-yellow-300/80';
        default:
            return 'bg-slate-500/15 text-slate-700 border-slate-300/80';
    }
};

const formatThreatType = (type) => {
    const types = {
        '404_probe': '404 URL Probe',
        'ddos_rate_limit': 'DDoS / Velocity Burst',
        'sqli_attempt': 'SQL Injection Probe',
        'xss_attempt': 'XSS Script Probe',
        'path_traversal': 'Path Traversal',
        'malicious_scanner': 'Scanner Bot',
        'suspicious_auth': 'Suspicious Auth',
    };
    return types[type] || type.replace('_', ' ');
};
</script>

<template>
    <AppLayout title="Security Command Center">
        <template #header>
            <AdminNav />
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h2 class="font-bold text-xl text-gray-900 leading-tight flex items-center space-x-2">
                        <span>🛡️ Security Command Center</span>
                    </h2>
                    <p class="text-xs text-gray-500 mt-0.5">Real-time threat detection, 404 scanning forensics, DDoS defense, and self-healing engine</p>
                </div>

                <div class="flex items-center space-x-2.5">
                    <button
                        type="button"
                        @click="triggerSelfHealing"
                        :disabled="isHealingRunning"
                        class="px-3.5 py-2 bg-forest-900 hover:bg-forest-950 text-white rounded-xl text-xs font-semibold shadow-xs flex items-center space-x-1.5 transition disabled:opacity-50"
                    >
                        <svg class="size-4" :class="{ 'animate-spin': isHealingRunning }" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99" />
                        </svg>
                        <span>{{ isHealingRunning ? 'Analyzing & Patching...' : 'Run Auto-Heal & Audit' }}</span>
                    </button>

                    <button
                        type="button"
                        @click="openQuarantineModal()"
                        class="px-3.5 py-2 bg-cream-300 hover:bg-cream-400 text-forest-950 border border-cream-500 rounded-xl text-xs font-semibold shadow-xs flex items-center space-x-1.5 transition"
                    >
                        <svg class="size-4 text-forest-800" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m0-10.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z" />
                        </svg>
                        <span>Quarantine IP</span>
                    </button>
                </div>
            </div>
        </template>

        <div class="py-6">
            <div class="max-w-8xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
                <!-- Flash Alerts -->
                <div v-if="$page.props.flash?.success" class="p-4 bg-emerald-50 border border-emerald-200 text-emerald-800 rounded-2xl text-sm font-medium flex items-center shadow-xs">
                    <svg class="size-5 me-2 text-emerald-600 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <span>{{ $page.props.flash.success }}</span>
                </div>

                <!-- Posture Score & Live Defense Status Banner -->
                <div class="bg-forest-900 text-white rounded-3xl p-6 shadow-md border border-forest-800 flex flex-col lg:flex-row lg:items-center justify-between gap-6 relative overflow-hidden">
                    <div class="flex items-center space-x-5 z-10">
                        <div class="relative size-20 shrink-0 flex items-center justify-center rounded-2xl bg-forest-800/80 border border-forest-700/80 shadow-inner">
                            <span class="text-3xl font-black text-emerald-300">{{ stats.score }}</span>
                            <span class="text-[10px] uppercase font-bold text-emerald-400 absolute bottom-1.5">/ 100</span>
                        </div>

                        <div>
                            <div class="flex items-center space-x-2.5">
                                <span class="px-2.5 py-0.5 rounded-full text-xs font-bold uppercase tracking-wider bg-emerald-500/20 text-emerald-300 border border-emerald-400/30">
                                    {{ stats.posture }}
                                </span>
                                <span class="flex items-center space-x-1.5 text-xs text-emerald-100/80 font-medium">
                                    <span class="size-2 rounded-full animate-ping" :class="stats.auto_defense_enabled ? 'bg-emerald-400' : 'bg-amber-400'"></span>
                                    <span>{{ stats.auto_defense_enabled ? 'Automated Defense Engine Live' : 'Passive Monitoring Only' }}</span>
                                </span>
                            </div>
                            <h3 class="text-lg font-bold text-white mt-1">Platform Security & Self-Healing Defense</h3>
                            <p class="text-xs text-cream-200/80 max-w-xl mt-0.5 leading-relaxed">
                                Automated real-time inspection intercepts probing bots, brute-force requests, and exploit attempts before they reach business logic.
                            </p>
                        </div>
                    </div>

                    <div class="flex items-center space-x-3 z-10 shrink-0">
                        <button
                            type="button"
                            @click="toggleAutoDefense"
                            class="px-4 py-2.5 rounded-xl text-xs font-semibold transition flex items-center space-x-2 border"
                            :class="stats.auto_defense_enabled
                                ? 'bg-emerald-500/15 border-emerald-400/40 text-emerald-200 hover:bg-emerald-500/25'
                                : 'bg-amber-500/15 border-amber-400/40 text-amber-200 hover:bg-amber-500/25'"
                        >
                            <span class="size-2 rounded-full" :class="stats.auto_defense_enabled ? 'bg-emerald-400' : 'bg-amber-400'"></span>
                            <span>{{ stats.auto_defense_enabled ? 'Auto-Quarantine: Active' : 'Auto-Quarantine: Disabled' }}</span>
                        </button>

                        <button
                            type="button"
                            @click="showQuarantinedListModal = true"
                            class="px-3.5 py-2.5 bg-forest-800 hover:bg-forest-700/80 text-white rounded-xl text-xs font-semibold transition border border-forest-700/70 flex items-center space-x-1.5"
                        >
                            <span>View Quarantined IPs ({{ stats.blocked_ips_count }})</span>
                        </button>
                    </div>

                    <!-- Decorative background glow -->
                    <div class="absolute -right-10 -bottom-10 size-48 rounded-full bg-emerald-500/10 blur-3xl pointer-events-none"></div>
                </div>

                <!-- 4 Metric Cards Grid -->
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5">
                    <!-- Total Threats 24h -->
                    <div class="bg-cream-200 p-5 rounded-2xl shadow-xs border border-cream-500/50 hover:shadow-md transition">
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-xs font-bold text-gray-600 uppercase tracking-wider">Threats Intercepted</span>
                            <div class="size-9 rounded-xl bg-lime-200 text-forest-900 flex items-center justify-center font-bold">
                                <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m0-10.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z" />
                                </svg>
                            </div>
                        </div>
                        <div class="text-2xl font-extrabold text-gray-950">{{ stats.total_threats_24h }}</div>
                        <p class="text-xs text-forest-800 font-medium mt-1">Last 24 hours activity</p>
                    </div>

                    <!-- 404 Probes -->
                    <div class="bg-cream-200 p-5 rounded-2xl shadow-xs border border-cream-500/50 hover:shadow-md transition">
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-xs font-bold text-gray-600 uppercase tracking-wider">404 Probes Detected</span>
                            <div class="size-9 rounded-xl bg-amber-200 text-amber-900 flex items-center justify-center font-bold">
                                <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
                                </svg>
                            </div>
                        </div>
                        <div class="text-2xl font-extrabold text-gray-950">{{ stats.probes_404_24h }}</div>
                        <p class="text-xs text-amber-800 font-medium mt-1">Sensitive file scan attempts</p>
                    </div>

                    <!-- DDoS / Rate Limiting -->
                    <div class="bg-cream-200 p-5 rounded-2xl shadow-xs border border-cream-500/50 hover:shadow-md transition">
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-xs font-bold text-gray-600 uppercase tracking-wider">DDoS / Burst Blocks</span>
                            <div class="size-9 rounded-xl bg-orange-200 text-orange-900 flex items-center justify-center font-bold">
                                <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z" />
                                </svg>
                            </div>
                        </div>
                        <div class="text-2xl font-extrabold text-gray-950">{{ stats.ddos_blocks_24h }}</div>
                        <p class="text-xs text-orange-800 font-medium mt-1">High-velocity throttled bursts</p>
                    </div>

                    <!-- Blocked IPs -->
                    <div class="bg-cream-200 p-5 rounded-2xl shadow-xs border border-cream-500/50 hover:shadow-md transition">
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-xs font-bold text-gray-600 uppercase tracking-wider">Quarantined IPs</span>
                            <div class="size-9 rounded-xl bg-red-200 text-red-900 flex items-center justify-center font-bold">
                                <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636" />
                                </svg>
                            </div>
                        </div>
                        <div class="text-2xl font-extrabold text-gray-950">{{ stats.blocked_ips_count }}</div>
                        <p class="text-xs text-red-800 font-medium mt-1">Active defense isolation</p>
                    </div>
                </div>

                <!-- Visual Analytics Row 1: 24h Attack Timeline & Threat Categories -->
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <!-- Timeline Graph (2 Columns) -->
                    <div class="lg:col-span-2 bg-[#fffef9] p-6 rounded-3xl border border-cream-400/80 shadow-xs flex flex-col justify-between">
                        <div>
                            <div class="flex items-center justify-between mb-4">
                                <div>
                                    <h3 class="text-sm font-bold text-gray-950 uppercase tracking-wider">Attack Velocity & Threat Timeline</h3>
                                    <p class="text-xs text-gray-500 mt-0.5">24-hour volume distribution by threat classification</p>
                                </div>
                                <div class="flex items-center space-x-3 text-[11px] font-semibold text-gray-600">
                                    <span class="flex items-center space-x-1"><span class="size-2 rounded-full bg-amber-500"></span><span>404 Probes</span></span>
                                    <span class="flex items-center space-x-1"><span class="size-2 rounded-full bg-orange-500"></span><span>DDoS</span></span>
                                    <span class="flex items-center space-x-1"><span class="size-2 rounded-full bg-red-500"></span><span>Exploits</span></span>
                                </div>
                            </div>

                            <!-- Interactive Bar Chart -->
                            <div class="h-44 flex items-end gap-2 pt-6 pb-2 border-b border-cream-300">
                                <div
                                    v-for="(point, idx) in timeline"
                                    :key="idx"
                                    class="flex-1 flex flex-col items-center h-full justify-end group relative"
                                >
                                    <!-- Tooltip -->
                                    <div class="absolute bottom-full mb-2 hidden group-hover:flex flex-col bg-forest-950 text-white text-[10px] p-2 rounded-lg shadow-xl z-20 whitespace-nowrap pointer-events-none">
                                        <span class="font-bold border-b border-white/20 pb-1 mb-1">{{ formatTimeLabel(point) }} — Total: {{ point.total }}</span>
                                        <span>Probes: {{ point.probes }}</span>
                                        <span>DDoS: {{ point.ddos }}</span>
                                        <span>Exploits: {{ point.exploits }}</span>
                                    </div>

                                    <!-- Stacked Column -->
                                    <div class="w-full max-w-[28px] flex flex-col justify-end rounded-t-md overflow-hidden bg-cream-300 transition-all group-hover:opacity-90"
                                         :style="{ height: point.total > 0 ? `${Math.max(14, Math.round((point.total / maxTimelineTotal) * 100))}%` : '4px' }">
                                        <div v-if="point.exploits > 0" class="bg-red-500 w-full shrink-0" :style="{ flex: point.exploits, height: `${(point.exploits / point.total) * 100}%` }"></div>
                                        <div v-if="point.ddos > 0" class="bg-orange-500 w-full shrink-0" :style="{ flex: point.ddos, height: `${(point.ddos / point.total) * 100}%` }"></div>
                                        <div v-if="point.probes > 0" class="bg-amber-500 w-full shrink-0" :style="{ flex: point.probes, height: `${(point.probes / point.total) * 100}%` }"></div>
                                    </div>

                                    <span class="text-[9px] text-gray-500 font-semibold mt-2">{{ formatTimeLabel(point) }}</span>
                                </div>
                            </div>
                        </div>

                        <div class="flex items-center justify-between text-xs text-gray-500 pt-3">
                            <span>Sampling: 2-hour rolling intervals</span>
                            <span class="text-forest-800 font-semibold">Continuous Ingestion</span>
                        </div>
                    </div>

                    <!-- Category Breakdown (1 Column) -->
                    <div class="bg-[#fffef9] p-6 rounded-3xl border border-cream-400/80 shadow-xs flex flex-col justify-between">
                        <div>
                            <h3 class="text-sm font-bold text-gray-950 uppercase tracking-wider mb-1">Threat Distribution</h3>
                            <p class="text-xs text-gray-500 mb-4">Breakdown of incidents over the last 7 days</p>

                            <div v-if="categories.length === 0" class="py-8 text-center text-xs text-gray-400">
                                No threats recorded in this window.
                            </div>

                            <div v-else class="space-y-3.5">
                                <div v-for="cat in categories" :key="cat.key" class="space-y-1">
                                    <div class="flex items-center justify-between text-xs font-semibold">
                                        <span class="text-gray-800">{{ cat.name }}</span>
                                        <span class="text-forest-900 font-bold">{{ cat.count }}</span>
                                    </div>
                                    <div class="w-full bg-cream-300 h-2 rounded-full overflow-hidden">
                                        <div
                                            class="h-full rounded-full bg-forest-700"
                                            :style="{ width: `${Math.min(100, (cat.count / Math.max(...categories.map(c => c.count), 1)) * 100)}%` }"
                                        ></div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="pt-4 border-t border-cream-300/80 text-[11px] text-gray-500 flex items-center justify-between">
                            <span>Engine: Heuristic Pattern Engine</span>
                            <span class="text-emerald-700 font-bold">100% Monitored</span>
                        </div>
                    </div>
                </div>

                <!-- Visual Analytics Row 2: Top Probed URLs & Top Offending IPs -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <!-- Top Probed Endpoints (404 Probes) -->
                    <div class="bg-[#fffef9] p-6 rounded-3xl border border-cream-400/80 shadow-xs">
                        <div class="flex items-center justify-between mb-4">
                            <div>
                                <h3 class="text-sm font-bold text-gray-950 uppercase tracking-wider">Top Probed Endpoints</h3>
                                <p class="text-xs text-gray-500 mt-0.5">Most scanned URLs and configuration files</p>
                            </div>
                            <span class="text-xs font-bold px-2.5 py-1 bg-amber-100 text-amber-800 rounded-lg">404 Probes</span>
                        </div>

                        <div v-if="topProbedUrls.length === 0" class="py-6 text-center text-xs text-gray-400">
                            No endpoint scans recorded yet.
                        </div>

                        <div v-else class="divide-y divide-cream-300/70">
                            <div v-for="item in topProbedUrls" :key="item.url" class="py-2.5 flex items-center justify-between gap-3 text-xs">
                                <div class="min-w-0 flex-1">
                                    <div class="font-mono font-bold text-gray-900 truncate" :title="item.url">
                                        {{ item.url }}
                                    </div>
                                </div>
                                <div class="flex items-center space-x-2 shrink-0">
                                    <span class="px-2 py-0.5 bg-cream-300 text-forest-950 rounded-md font-bold text-[11px]">
                                        {{ item.count }} hits
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Top Attacking IPs -->
                    <div class="bg-[#fffef9] p-6 rounded-3xl border border-cream-400/80 shadow-xs">
                        <div class="flex items-center justify-between mb-4">
                            <div>
                                <h3 class="text-sm font-bold text-gray-950 uppercase tracking-wider">Top Threat Source IPs</h3>
                                <p class="text-xs text-gray-500 mt-0.5">Offending client IP addresses and quarantine status</p>
                            </div>
                            <span class="text-xs font-bold px-2.5 py-1 bg-red-100 text-red-800 rounded-lg">Attacker Origin</span>
                        </div>

                        <div v-if="topOffendingIps.length === 0" class="py-6 text-center text-xs text-gray-400">
                            No offending IPs detected yet.
                        </div>

                        <div v-else class="divide-y divide-cream-300/70">
                            <div v-for="item in topOffendingIps" :key="item.client_ipv4 || item.ip_address" class="py-2.5 flex items-center justify-between gap-3 text-xs">
                                <div class="flex flex-col">
                                    <div class="flex items-center space-x-2">
                                        <span class="font-mono font-bold text-gray-900">{{ item.client_ipv4 || item.ip_address }}</span>
                                        <span v-if="item.client_ipv4" class="px-1.5 py-0.5 bg-emerald-100 text-emerald-800 text-[9px] font-bold rounded">
                                            IPv4
                                        </span>
                                        <span v-else-if="item.ip_address.includes(':')" class="px-1.5 py-0.5 bg-purple-100 text-purple-800 text-[9px] font-bold rounded">
                                            IPv6
                                        </span>
                                        <span v-if="item.is_blocked" class="px-2 py-0.5 bg-red-500/15 text-red-700 text-[10px] font-bold rounded-full border border-red-300">
                                            Quarantined
                                        </span>
                                        <span v-else class="px-2 py-0.5 bg-emerald-500/15 text-emerald-800 text-[10px] font-bold rounded-full border border-emerald-300">
                                            Active
                                        </span>
                                    </div>
                                    <div v-if="item.client_ipv6 || (item.client_ipv4 && item.ip_address !== item.client_ipv4)" class="text-[10px] text-gray-500 font-mono truncate max-w-[220px]" :title="item.client_ipv6 || item.ip_address">
                                        IPv6: {{ item.client_ipv6 || item.ip_address }}
                                    </div>
                                </div>

                                <div class="flex items-center space-x-3 shrink-0">
                                    <span class="text-gray-500 text-[11px]">{{ item.last_seen }}</span>
                                    <span class="px-2 py-0.5 bg-cream-300 text-forest-950 rounded-md font-bold text-[11px]">
                                        {{ item.count }} attempts
                                    </span>
                                    <button
                                        v-if="!item.is_blocked"
                                        type="button"
                                        @click="openQuarantineModal(item.client_ipv4 || item.ip_address)"
                                        class="text-xs text-red-700 hover:text-red-900 font-bold underline ml-1"
                                    >
                                        Block
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Self-Healing Control Center & System Health Audit -->
                <div class="bg-[#fffef9] p-6 rounded-3xl border border-cream-400/80 shadow-xs">
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between pb-4 border-b border-cream-300 gap-3">
                        <div>
                            <div class="flex items-center space-x-2">
                                <span class="size-2 rounded-full bg-emerald-500 animate-pulse"></span>
                                <h3 class="text-sm font-bold text-gray-950 uppercase tracking-wider">Self-Healing Diagnostic & Remediation</h3>
                            </div>
                            <p class="text-xs text-gray-500 mt-0.5">Automated vulnerability auditing, permission validation, token hygiene, and config auto-patches</p>
                        </div>
                        <button
                            type="button"
                            @click="triggerSelfHealing"
                            :disabled="isHealingRunning"
                            class="px-3 py-1.5 bg-forest-900 hover:bg-forest-950 text-white rounded-xl text-xs font-semibold shadow-xs flex items-center space-x-1.5 transition self-start sm:self-auto"
                        >
                            <span>Run Security Check & Patch</span>
                        </button>
                    </div>

                    <!-- Findings Checklist -->
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 my-5">
                        <div
                            v-for="finding in diagnosticFindings"
                            :key="finding.check"
                            class="p-4 rounded-2xl border transition"
                            :class="finding.status === 'secure'
                                ? 'bg-emerald-50/50 border-emerald-200'
                                : (finding.status === 'vulnerable' ? 'bg-red-50/60 border-red-200' : 'bg-amber-50/60 border-amber-200')"
                        >
                            <div class="flex items-center justify-between mb-1">
                                <h4 class="text-xs font-bold text-gray-900">{{ finding.title }}</h4>
                                <span
                                    class="text-[10px] uppercase font-bold px-2 py-0.5 rounded-full"
                                    :class="finding.status === 'secure'
                                        ? 'bg-emerald-200 text-emerald-900'
                                        : (finding.status === 'vulnerable' ? 'bg-red-200 text-red-900' : 'bg-amber-200 text-amber-900')"
                                >
                                    {{ finding.status }}
                                </span>
                            </div>
                            <p class="text-xs text-gray-600 mt-1 leading-relaxed">{{ finding.message }}</p>
                        </div>
                    </div>

                    <!-- Recent Self-Healing Interventions Log -->
                    <div class="pt-3 border-t border-cream-300">
                        <h4 class="text-xs font-bold text-forest-900 uppercase tracking-wider mb-2">Recent Self-Healing Automated Interventions</h4>
                        <div v-if="recentHealingLogs.length === 0" class="text-xs text-gray-400 py-2">
                            No self-healing remediations executed yet. System is in healthy baseline state.
                        </div>
                        <div v-else class="space-y-2">
                            <div
                                v-for="log in recentHealingLogs"
                                :key="log.id"
                                class="flex items-center justify-between text-xs py-1.5 px-3 bg-cream-200/60 rounded-xl"
                            >
                                <div class="flex items-center space-x-2">
                                    <span class="px-2 py-0.5 bg-forest-900 text-white rounded text-[10px] font-bold">
                                        {{ log.action_type }}
                                    </span>
                                    <span class="text-gray-800 font-medium">{{ log.description }}</span>
                                </div>
                                <span class="text-[11px] text-gray-500 shrink-0">{{ new Date(log.created_at).toLocaleString() }}</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Threat Logs Table Section -->
                <div class="bg-[#fffef9] p-6 rounded-3xl border border-cream-400/80 shadow-xs">
                    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-5">
                        <div>
                            <h3 class="text-sm font-bold text-gray-950 uppercase tracking-wider">Live Security Threat Logs</h3>
                            <p class="text-xs text-gray-500 mt-0.5">Real-time log of 404 URL scans, DDoS bursts, and intercepted attack vectors</p>
                        </div>

                        <!-- Filters -->
                        <div class="flex flex-wrap items-center gap-2">
                            <input
                                v-model="filterForm.search"
                                @keyup.enter="applyFilters"
                                type="text"
                                placeholder="Search IP, URL, or Bot..."
                                class="px-3 py-1.5 bg-cream-100 border border-cream-400 rounded-xl text-xs w-44 sm:w-56 focus:border-forest-600"
                            />

                            <select
                                v-model="filterForm.threat_type"
                                @change="applyFilters"
                                class="px-2.5 py-1.5 bg-cream-100 border border-cream-400 rounded-xl text-xs font-medium text-gray-700"
                            >
                                <option value="">All Categories</option>
                                <option value="404_probe">404 Probes</option>
                                <option value="ddos_rate_limit">DDoS / Rate Limit</option>
                                <option value="sqli_attempt">SQL Injection</option>
                                <option value="xss_attempt">XSS Attacks</option>
                                <option value="path_traversal">Path Traversal</option>
                                <option value="malicious_scanner">Scanners / Bots</option>
                            </select>

                            <select
                                v-model="filterForm.severity"
                                @change="applyFilters"
                                class="px-2.5 py-1.5 bg-cream-100 border border-cream-400 rounded-xl text-xs font-medium text-gray-700"
                            >
                                <option value="">All Severities</option>
                                <option value="critical">Critical</option>
                                <option value="high">High</option>
                                <option value="medium">Medium</option>
                                <option value="low">Low</option>
                            </select>

                            <button
                                type="button"
                                @click="applyFilters"
                                class="px-3 py-1.5 bg-forest-900 hover:bg-forest-950 text-white rounded-xl text-xs font-semibold"
                            >
                                Filter
                            </button>

                            <button
                                v-if="filterForm.search || filterForm.threat_type || filterForm.severity || filterForm.resolved"
                                type="button"
                                @click="resetFilters"
                                class="px-2.5 py-1.5 bg-cream-300 hover:bg-cream-400 text-gray-700 rounded-xl text-xs font-semibold"
                                title="Reset filters"
                            >
                                ✕
                            </button>
                        </div>
                    </div>

                    <!-- Logs Table -->
                    <div class="overflow-x-auto rounded-2xl border border-cream-300">
                        <table class="min-w-full divide-y divide-cream-300 text-xs">
                            <thead class="bg-cream-200 text-forest-950 uppercase tracking-wider font-bold text-[10px]">
                                <tr>
                                    <th class="px-4 py-3 text-left">Timestamp</th>
                                    <th class="px-4 py-3 text-left">Client IP</th>
                                    <th class="px-4 py-3 text-left">Category</th>
                                    <th class="px-4 py-3 text-left">Severity</th>
                                    <th class="px-4 py-3 text-left">Targeted URL</th>
                                    <th class="px-4 py-3 text-left">Action Taken</th>
                                    <th class="px-4 py-3 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-cream-200 bg-white">
                                <tr v-if="threatLogs.data.length === 0">
                                    <td colspan="7" class="px-4 py-8 text-center text-gray-400">
                                        No security threat logs found matching the filter criteria.
                                    </td>
                                </tr>

                                <tr
                                    v-for="log in threatLogs.data"
                                    :key="log.id"
                                    class="hover:bg-cream-100/50 transition"
                                >
                                    <td class="px-4 py-3 whitespace-nowrap text-gray-500 font-mono text-[11px]">
                                        {{ new Date(log.created_at).toLocaleString() }}
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap">
                                        <div class="flex items-center space-x-1.5">
                                            <span class="font-mono font-bold text-gray-900">{{ log.client_ipv4 || log.ip_address }}</span>
                                            <span v-if="log.client_ipv4" class="px-1.5 py-0.5 bg-emerald-100 text-emerald-800 text-[9px] font-bold rounded">
                                                IPv4
                                            </span>
                                            <span v-else-if="log.ip_address.includes(':')" class="px-1.5 py-0.5 bg-purple-100 text-purple-800 text-[9px] font-bold rounded">
                                                IPv6
                                            </span>
                                        </div>
                                        <div v-if="log.client_ipv6 || (log.client_ipv4 && log.ip_address !== log.client_ipv4)" class="text-[10px] text-gray-400 font-mono truncate max-w-[180px]" :title="log.client_ipv6 || log.ip_address">
                                            {{ log.client_ipv6 || log.ip_address }}
                                        </div>
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap">
                                        <span class="font-semibold text-gray-800">
                                            {{ formatThreatType(log.threat_type) }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap">
                                        <span class="px-2 py-0.5 rounded-md font-bold uppercase text-[10px] border" :class="getSeverityBadgeClass(log.severity)">
                                            {{ log.severity }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 max-w-xs truncate font-mono text-[11px] text-gray-700" :title="log.url">
                                        <span class="font-bold text-gray-900 mr-1.5">{{ log.method }}</span>
                                        <span>{{ log.url }}</span>
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap">
                                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold"
                                            :class="log.action_taken === 'auto_blocked' ? 'bg-red-100 text-red-800' : 'bg-cream-300 text-forest-900'">
                                            {{ log.action_taken }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap text-right space-x-2">
                                        <button
                                            type="button"
                                            @click="inspectingThreat = log"
                                            class="text-forest-800 hover:text-forest-950 font-bold underline"
                                        >
                                            Inspect
                                        </button>
                                        <button
                                            type="button"
                                            @click="openQuarantineModal(log.client_ipv4 || log.ip_address)"
                                            class="text-red-700 hover:text-red-900 font-bold underline"
                                        >
                                            Block IP
                                        </button>
                                        <button
                                            v-if="!log.is_resolved"
                                            type="button"
                                            @click="resolveThreat(log)"
                                            class="text-emerald-700 hover:text-emerald-900 font-bold underline"
                                        >
                                            Resolve
                                        </button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <div v-if="threatLogs.links && threatLogs.links.length > 3" class="flex items-center justify-between pt-4 text-xs">
                        <div class="text-gray-500">
                            Showing {{ threatLogs.from || 0 }} to {{ threatLogs.to || 0 }} of {{ threatLogs.total }} incidents
                        </div>
                        <div class="flex items-center space-x-1">
                            <Link
                                v-for="(link, i) in threatLogs.links"
                                :key="i"
                                :href="link.url || '#'"
                                v-html="link.label"
                                class="px-3 py-1.5 rounded-lg border font-semibold transition"
                                :class="link.active ? 'bg-forest-900 text-white border-forest-900' : (link.url ? 'bg-cream-200 border-cream-400 text-gray-700 hover:bg-cream-300' : 'opacity-40 pointer-events-none')"
                            />
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Inspect Threat Modal -->
        <DialogModal :show="inspectingThreat !== null" @close="inspectingThreat = null">
            <template #title>
                <div class="flex items-center space-x-2">
                    <span>Threat Forensics & Payload Inspection</span>
                </div>
            </template>

            <template #content>
                <div v-if="inspectingThreat" class="space-y-4 text-xs">
                    <div class="grid grid-cols-2 sm:grid-cols-3 gap-3 bg-cream-100 p-3.5 rounded-xl border border-cream-300">
                        <div>
                            <span class="block text-gray-500 text-[10px] uppercase font-bold">Public IPv4</span>
                            <span class="font-mono font-bold text-gray-900">{{ inspectingThreat.client_ipv4 || 'Not detected' }}</span>
                        </div>
                        <div>
                            <span class="block text-gray-500 text-[10px] uppercase font-bold">IPv6 / Gateway IP</span>
                            <span class="font-mono font-bold text-gray-900 break-all text-[11px]">{{ inspectingThreat.client_ipv6 || inspectingThreat.ip_address }}</span>
                        </div>
                        <div>
                            <span class="block text-gray-500 text-[10px] uppercase font-bold">Severity</span>
                            <span class="font-bold uppercase" :class="inspectingThreat.severity === 'critical' ? 'text-red-700' : 'text-amber-800'">
                                {{ inspectingThreat.severity }}
                            </span>
                        </div>
                        <div>
                            <span class="block text-gray-500 text-[10px] uppercase font-bold">Threat Type</span>
                            <span class="font-semibold text-gray-900">{{ formatThreatType(inspectingThreat.threat_type) }}</span>
                        </div>
                        <div>
                            <span class="block text-gray-500 text-[10px] uppercase font-bold">Action Taken</span>
                            <span class="font-semibold text-gray-900">{{ inspectingThreat.action_taken }}</span>
                        </div>
                        <div>
                            <span class="block text-gray-500 text-[10px] uppercase font-bold">Detection Source</span>
                            <span class="font-semibold text-gray-900">{{ inspectingThreat.payload?.ip_detection_source || 'Standard Gateway' }}</span>
                        </div>
                    </div>

                    <div>
                        <span class="block text-gray-500 text-[10px] uppercase font-bold mb-1">Requested URL</span>
                        <div class="p-2.5 bg-gray-950 text-emerald-400 font-mono rounded-xl break-all">
                            {{ inspectingThreat.method }} {{ inspectingThreat.url }}
                        </div>
                    </div>

                    <div>
                        <span class="block text-gray-500 text-[10px] uppercase font-bold mb-1">User Agent Header</span>
                        <div class="p-2.5 bg-cream-200 text-gray-800 font-mono rounded-xl break-all text-[11px]">
                            {{ inspectingThreat.user_agent || 'Not provided' }}
                        </div>
                    </div>

                    <div v-if="inspectingThreat.payload">
                        <span class="block text-gray-500 text-[10px] uppercase font-bold mb-1">Extracted Payload / Forensic Signature</span>
                        <pre class="p-3 bg-gray-950 text-emerald-300 rounded-xl overflow-x-auto text-[11px] font-mono leading-relaxed">{{ JSON.stringify(inspectingThreat.payload, null, 2) }}</pre>
                    </div>
                </div>
            </template>

            <template #footer>
                <div class="flex items-center justify-between w-full">
                    <button
                        v-if="inspectingThreat"
                        type="button"
                        @click="openQuarantineModal(inspectingThreat.client_ipv4 || inspectingThreat.ip_address); inspectingThreat = null"
                        class="px-3 py-1.5 bg-red-600 hover:bg-red-700 text-white rounded-xl text-xs font-semibold"
                    >
                        Quarantine {{ inspectingThreat.client_ipv4 || inspectingThreat.ip_address }}
                    </button>
                    <SecondaryButton @click="inspectingThreat = null">
                        Close
                    </SecondaryButton>
                </div>
            </template>
        </DialogModal>

        <!-- Quarantine IP Form Modal -->
        <DialogModal :show="showQuarantineModal" @close="showQuarantineModal = false">
            <template #title>
                <span>Quarantine Offending IP Address</span>
            </template>

            <template #content>
                <div class="space-y-4">
                    <p class="text-xs text-gray-600">
                        Quarantined IP addresses will immediately receive HTTP 403 Forbidden at the gateway before any controller or database queries execute.
                    </p>

                    <div>
                        <InputLabel for="ip_address" value="IP Address" />
                        <TextInput
                            id="ip_address"
                            v-model="quarantineForm.ip_address"
                            type="text"
                            class="mt-1 block w-full text-xs"
                            placeholder="e.g. 198.51.100.45"
                            required
                        />
                    </div>

                    <div>
                        <InputLabel for="reason" value="Quarantine Reason" />
                        <TextInput
                            id="reason"
                            v-model="quarantineForm.reason"
                            type="text"
                            class="mt-1 block w-full text-xs"
                            placeholder="e.g. Repeated 404 URL scans & SQLi probes"
                            required
                        />
                    </div>

                    <div>
                        <InputLabel for="duration_minutes" value="Quarantine Duration (Minutes, leave blank for permanent)" />
                        <TextInput
                            id="duration_minutes"
                            v-model="quarantineForm.duration_minutes"
                            type="number"
                            class="mt-1 block w-full text-xs"
                            placeholder="60"
                        />
                    </div>
                </div>
            </template>

            <template #footer>
                <SecondaryButton @click="showQuarantineModal = false" class="me-2">
                    Cancel
                </SecondaryButton>
                <DangerButton @click="submitQuarantine" :disabled="quarantineForm.processing">
                    Confirm Quarantine
                </DangerButton>
            </template>
        </DialogModal>

        <!-- View Quarantined List Modal -->
        <DialogModal :show="showQuarantinedListModal" @close="showQuarantinedListModal = false" max-width="3xl">
            <template #title>
                <span>Active Quarantined IP Addresses ({{ blockedIps.length }})</span>
            </template>

            <template #content>
                <div v-if="blockedIps.length === 0" class="py-8 text-center text-xs text-gray-400">
                    No active quarantined IP addresses.
                </div>

                <div v-else class="max-h-96 overflow-y-auto divide-y divide-cream-300 text-xs">
                    <div
                        v-for="blocked in blockedIps"
                        :key="blocked.id"
                        class="py-3 flex items-center justify-between gap-3"
                    >
                        <div>
                            <div class="flex items-center space-x-2">
                                <span class="font-mono font-bold text-gray-900">{{ blocked.client_ipv4 || blocked.ip_address }}</span>
                                <span v-if="blocked.client_ipv4" class="px-1.5 py-0.5 bg-emerald-100 text-emerald-800 text-[9px] font-bold rounded">
                                    IPv4
                                </span>
                                <span v-else-if="blocked.ip_address.includes(':')" class="px-1.5 py-0.5 bg-purple-100 text-purple-800 text-[9px] font-bold rounded">
                                    IPv6
                                </span>
                                <span class="px-2 py-0.5 bg-red-100 text-red-800 text-[10px] font-bold rounded-full">
                                    {{ blocked.threat_count }} violations
                                </span>
                            </div>
                            <div v-if="blocked.client_ipv6 || (blocked.client_ipv4 && blocked.ip_address !== blocked.client_ipv4)" class="text-[10px] text-gray-500 font-mono mt-0.5">
                                IPv6/Gateway: {{ blocked.client_ipv6 || blocked.ip_address }}
                            </div>
                            <p class="text-xs text-gray-500 mt-0.5">{{ blocked.reason }}</p>
                            <span class="text-[10px] text-gray-400">
                                Quarantined by {{ blocked.blocked_by }}
                                <span v-if="blocked.blocked_until"> — expires {{ new Date(blocked.blocked_until).toLocaleString() }}</span>
                                <span v-else> — Permanent</span>
                            </span>
                        </div>

                        <button
                            type="button"
                            @click="unblockIp(blocked)"
                            class="px-3 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl text-xs font-semibold shrink-0"
                        >
                            Unblock
                        </button>
                    </div>
                </div>
            </template>

            <template #footer>
                <SecondaryButton @click="showQuarantinedListModal = false">
                    Close
                </SecondaryButton>
            </template>
        </DialogModal>
    </AppLayout>
</template>

<script setup>
import { ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import AdminNav from '@/Components/AdminNav.vue';

const props = defineProps({
    submissions: {
        type: Object,
        required: true,
    },
    filterEvents: {
        type: Array,
        required: true,
    },
    filterAgencies: {
        type: Array,
        required: true,
    },
    filterDesignations: {
        type: Array,
        required: true,
    },
    filters: {
        type: Object,
        default: () => ({}),
    },
});

const filterForm = ref({
    event_id: props.filters.event_id || '',
    agency: props.filters.agency || '',
    designation: props.filters.designation || '',
    search: props.filters.search || '',
});

const applyFilters = () => {
    router.get(route('admin.feedback.submissions'), filterForm.value, {
        preserveState: true,
        preserveScroll: true,
    });
};

const resetFilters = () => {
    filterForm.value = {
        event_id: '',
        agency: '',
        designation: '',
        search: '',
    };
    applyFilters();
};

// Response Detail Modal
const showDetailModal = ref(false);
const selectedSubmission = ref(null);

const viewSubmission = (submission) => {
    selectedSubmission.value = submission;
    showDetailModal.value = true;
};

const closeDetailModal = () => {
    showDetailModal.value = false;
    selectedSubmission.value = null;
};

const formatDate = (isoString) => {
    if (!isoString) return '—';
    const d = new Date(isoString);
    return d.toLocaleString(undefined, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
};

const getFieldValue = (submission, key) => {
    if (!submission?.data) return null;
    return submission.data[key] ?? null;
};

const getOptionDisplayLabel = (field, val) => {
    if (!field.options || !Array.isArray(field.options)) return val;
    const match = field.options.find(opt => opt.value === val);
    return match ? match.label : val;
};
</script>

<template>
    <AppLayout title="Admin - Feedback Submissions">
        <template #header>
            <AdminNav />
            <div class="flex items-center justify-between flex-wrap gap-4">
                <div>
                    <h2 class="font-bold text-xl text-gray-900 leading-tight">
                        Feedback Responses & Submissions
                    </h2>
                    <p class="text-xs text-gray-500 mt-0.5">
                        Browse, filter, and review submitted feedback data rendered against event schemas
                    </p>
                </div>

                <Link
                    :href="route('admin.feedback.setup')"
                    class="inline-flex items-center px-4 py-2 bg-cream-100 hover:bg-cream-300 text-gray-800 text-sm font-semibold rounded-xl border border-cream-400 transition"
                >
                    <svg class="size-4 me-1.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 6h9.75M10.5 6a1.5 1.5 0 11-3 0m3 0a1.5 1.5 0 10-3 0M3.75 6H7.5m3 12h9.75m-9.75 0a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m-3.75 0H7.5m9-6h3.75m-3.75 0a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m-9.75 0h9.75" />
                    </svg>
                    Module Setup
                </Link>
            </div>
        </template>

        <div class="py-6">
            <div class="max-w-8xl mx-auto px-4 sm:px-6 lg:px-8">
                <!-- Filters Bar -->
                <div class="bg-cream-200 p-4 rounded-2xl border border-cream-500/50 shadow-xs mb-6">
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                        <div>
                            <label class="block text-[11px] font-bold text-forest-900 uppercase tracking-wider mb-1">Filter by Event</label>
                            <select
                                v-model="filterForm.event_id"
                                @change="applyFilters"
                                class="w-full px-3 py-2 bg-[#fffef9] border border-cream-500 focus:border-forest-600 rounded-xl text-xs font-semibold text-gray-800"
                            >
                                <option value="">All Events</option>
                                <option v-for="ev in filterEvents" :key="ev.id" :value="ev.id">
                                    {{ ev.name }} {{ ev.deleted_at ? '(Archived)' : '' }}
                                </option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-[11px] font-bold text-forest-900 uppercase tracking-wider mb-1">Filter by Agency</label>
                            <select
                                v-model="filterForm.agency"
                                @change="applyFilters"
                                class="w-full px-3 py-2 bg-[#fffef9] border border-cream-500 focus:border-forest-600 rounded-xl text-xs font-semibold text-gray-800"
                            >
                                <option value="">All Agencies</option>
                                <option v-for="ag in filterAgencies" :key="ag" :value="ag">
                                    {{ ag }}
                                </option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-[11px] font-bold text-forest-900 uppercase tracking-wider mb-1">Filter by Designation</label>
                            <select
                                v-model="filterForm.designation"
                                @change="applyFilters"
                                class="w-full px-3 py-2 bg-[#fffef9] border border-cream-500 focus:border-forest-600 rounded-xl text-xs font-semibold text-gray-800"
                            >
                                <option value="">All Designations</option>
                                <option v-for="des in filterDesignations" :key="des" :value="des">
                                    {{ des }}
                                </option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-[11px] font-bold text-forest-900 uppercase tracking-wider mb-1">Search Keyword</label>
                            <div class="flex space-x-1.5">
                                <input
                                    v-model="filterForm.search"
                                    @keyup.enter="applyFilters"
                                    type="text"
                                    placeholder="Name, Agency, Location..."
                                    class="flex-1 px-3 py-2 bg-[#fffef9] border border-cream-500 focus:border-forest-600 rounded-xl text-xs"
                                />
                                <button
                                    type="button"
                                    @click="applyFilters"
                                    class="px-3 py-2 bg-forest-900 hover:bg-forest-950 text-white rounded-xl text-xs font-semibold"
                                >
                                    Filter
                                </button>
                                <button
                                    v-if="filterForm.event_id || filterForm.agency || filterForm.designation || filterForm.search"
                                    type="button"
                                    @click="resetFilters"
                                    class="px-2.5 py-2 bg-cream-300 hover:bg-cream-400 text-gray-700 rounded-xl text-xs font-semibold"
                                    title="Clear all filters"
                                >
                                    ✕
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Submissions Table -->
                <div class="bg-cream-200 rounded-2xl border border-cream-500/50 shadow-xs overflow-hidden">
                    <div v-if="submissions.data.length === 0" class="p-12 text-center">
                        <div class="size-16 rounded-full bg-cream-100 text-gray-400 flex items-center justify-center mx-auto mb-4">
                            <svg class="size-8" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 12h16.5m-16.5 3.75h16.5M3.75 19.5h16.5M5.625 4.5h12.75a1.875 1.875 0 010 3.75H5.625a1.875 1.875 0 010-3.75z" />
                            </svg>
                        </div>
                        <h3 class="text-base font-bold text-gray-900">No Feedback Submissions Found</h3>
                        <p class="text-xs text-gray-500 max-w-sm mx-auto mt-1">
                            No submissions recorded matching your filter parameters.
                        </p>
                    </div>

                    <div v-else class="overflow-x-auto">
                        <table class="w-full text-left text-xs">
                            <thead class="bg-cream-300 text-forest-900 font-bold uppercase tracking-wider text-[11px] border-b border-cream-500/50">
                                <tr>
                                    <th class="px-5 py-3.5">Participant</th>
                                    <th class="px-5 py-3.5">Function & Agency</th>
                                    <th class="px-5 py-3.5">Designation</th>
                                    <th class="px-5 py-3.5">Event</th>
                                    <th class="px-5 py-3.5">Submitted At</th>
                                    <th class="px-5 py-3.5 text-right">Action</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-cream-500/30">
                                <tr v-for="s in submissions.data" :key="s.id" class="hover:bg-cream-100 transition">
                                    <td class="px-5 py-3.5">
                                        <span v-if="s.participant?.name" class="font-bold text-gray-900 block">
                                            {{ s.participant.name }}
                                        </span>
                                        <span v-else class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-semibold bg-gray-100 text-gray-600 border border-gray-300">
                                            👤 Anonymous
                                        </span>
                                    </td>
                                    <td class="px-5 py-3.5">
                                        <div class="font-semibold text-forest-900">
                                            {{ s.participant?.function?.function || 'Unassigned Role' }}
                                        </div>
                                        <div class="text-gray-500 text-[11px]">
                                            {{ s.participant?.agency || '—' }}
                                        </div>
                                    </td>
                                    <td class="px-5 py-3.5 text-gray-700">
                                        {{ s.participant?.designation || '—' }}
                                        <span v-if="s.participant?.years_in_designation" class="text-gray-400 text-[10px] block">
                                            ({{ s.participant.years_in_designation }} yrs)
                                        </span>
                                    </td>
                                    <td class="px-5 py-3.5 text-gray-800 font-medium">
                                        {{ s.feedback?.event?.name || 'Archived Event #' + s.feedback?.event_id }}
                                    </td>
                                    <td class="px-5 py-3.5 text-gray-600 whitespace-nowrap">
                                        {{ formatDate(s.created_at) }}
                                    </td>
                                    <td class="px-5 py-3.5 text-right">
                                        <button
                                            @click="viewSubmission(s)"
                                            class="inline-flex items-center px-3 py-1.5 bg-forest-900 hover:bg-forest-950 text-white rounded-xl text-xs font-semibold shadow-xs transition"
                                        >
                                            <svg class="size-3.5 me-1" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" />
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                            </svg>
                                            View Answers
                                        </button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination Links -->
                    <div v-if="submissions.links && submissions.links.length > 3" class="p-4 border-t border-cream-500/40 flex items-center justify-between">
                        <div class="text-xs text-gray-500">
                            Showing {{ submissions.from }} to {{ submissions.to }} of {{ submissions.total }} submissions
                        </div>
                        <div class="flex items-center space-x-1">
                            <template v-for="(link, i) in submissions.links" :key="i">
                                <Link
                                    v-if="link.url"
                                    :href="link.url"
                                    class="px-3 py-1 text-xs rounded-lg transition"
                                    :class="link.active ? 'bg-forest-900 text-white font-bold' : 'bg-cream-100 hover:bg-cream-300 text-gray-700'"
                                    v-html="link.label"
                                />
                                <span
                                    v-else
                                    class="px-3 py-1 text-xs text-gray-400"
                                    v-html="link.label"
                                />
                            </template>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Submission Detail Review Modal -->
        <div v-if="showDetailModal && selectedSubmission" class="fixed inset-0 z-50 overflow-y-auto bg-gray-900/60 backdrop-blur-xs flex items-center justify-center p-4">
            <div class="bg-cream-200 rounded-3xl max-w-2xl w-full shadow-2xl p-6 border border-cream-500/60 max-h-[90vh] flex flex-col">
                <div class="flex items-center justify-between pb-4 border-b border-cream-500/40 shrink-0">
                    <div>
                        <span class="inline-flex items-center px-2 py-0.5 rounded-md text-[10px] font-bold uppercase tracking-wider bg-lime-200 text-forest-900 border border-forest-500/20">
                            Submission #{{ selectedSubmission.id }}
                        </span>
                        <h3 class="text-lg font-bold text-gray-900 mt-1">
                            {{ selectedSubmission.feedback?.event?.name || 'Feedback Submission Details' }}
                        </h3>
                    </div>
                    <button @click="closeDetailModal" class="text-gray-400 hover:text-gray-600">
                        <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <div class="mt-4 space-y-4 overflow-y-auto pr-1 flex-1">
                    <!-- Participant Summary Card -->
                    <div class="bg-[#fffef9] p-4 rounded-2xl border border-cream-500/60 shadow-xs">
                        <div class="text-[11px] font-bold uppercase tracking-wider text-forest-900 mb-2">
                            Participant Information
                        </div>
                        <div class="grid grid-cols-2 sm:grid-cols-3 gap-3 text-xs">
                            <div>
                                <span class="text-gray-500 block">Name:</span>
                                <span class="font-bold text-gray-900">
                                    {{ selectedSubmission.participant?.name || 'Anonymous' }}
                                </span>
                            </div>
                            <div>
                                <span class="text-gray-500 block">Function:</span>
                                <span class="font-semibold text-forest-900">{{ selectedSubmission.participant?.function?.function || '—' }}</span>
                            </div>
                            <div>
                                <span class="text-gray-500 block">Agency:</span>
                                <span class="font-medium text-gray-800">{{ selectedSubmission.participant?.agency || '—' }}</span>
                            </div>
                            <div>
                                <span class="text-gray-500 block">Designation:</span>
                                <span class="font-medium text-gray-800">{{ selectedSubmission.participant?.designation || '—' }}</span>
                            </div>
                            <div>
                                <span class="text-gray-500 block">Location:</span>
                                <span class="font-medium text-gray-800">{{ selectedSubmission.participant?.location || '—' }}</span>
                            </div>
                            <div>
                                <span class="text-gray-500 block">Submitted At:</span>
                                <span class="font-medium text-gray-800">{{ formatDate(selectedSubmission.created_at) }}</span>
                            </div>
                        </div>
                    </div>

                    <!-- Rendered Answers -->
                    <div class="space-y-3">
                        <div class="text-xs font-bold uppercase tracking-wider text-forest-900">
                            Questionnaire Responses
                        </div>

                        <div
                            v-for="(field, fIdx) in selectedSubmission.feedback?.schema?.fields || []"
                            :key="field.id"
                            class="p-4 bg-[#fffef9] rounded-2xl border border-cream-500/60 shadow-xs space-y-2"
                        >
                            <div class="flex items-start justify-between gap-2">
                                <span class="text-xs font-bold text-gray-900 leading-snug">
                                    {{ fIdx + 1 }}. {{ field.particular }}
                                </span>
                                <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase bg-lime-200 text-forest-900 shrink-0">
                                    {{ field.type }}
                                </span>
                            </div>

                            <!-- Value Display -->
                            <div class="pt-1">
                                <!-- Text Type -->
                                <div v-if="field.type === 'text'" class="text-xs text-gray-800 bg-cream-100 p-3 rounded-xl border border-cream-400/40 whitespace-pre-wrap">
                                    {{ getFieldValue(selectedSubmission, field.id) || 'No response provided.' }}
                                </div>

                                <!-- Radio or Select Type with Display Label -->
                                <div v-else-if="field.type === 'radio' || field.type === 'select'" class="inline-flex items-center px-3 py-1.5 rounded-xl text-xs font-semibold bg-emerald-100 text-emerald-900 border border-emerald-300">
                                    {{ getOptionDisplayLabel(field, getFieldValue(selectedSubmission, field.id)) }}
                                </div>

                                <!-- Checkbox Type -->
                                <div v-else-if="field.type === 'checkbox'" class="flex flex-wrap gap-1.5">
                                    <template v-if="Array.isArray(getFieldValue(selectedSubmission, field.id)) && getFieldValue(selectedSubmission, field.id).length > 0">
                                        <span
                                            v-for="(val, vIdx) in getFieldValue(selectedSubmission, field.id)"
                                            :key="vIdx"
                                            class="inline-flex items-center px-2.5 py-1 rounded-lg text-xs font-semibold bg-lime-200 text-forest-900 border border-forest-500/20"
                                        >
                                            ✓ {{ getOptionDisplayLabel(field, val) }}
                                        </span>
                                    </template>
                                    <span v-else class="text-xs text-gray-400 italic">None selected</span>
                                </div>

                                <!-- Fallback -->
                                <div v-else class="text-xs text-gray-800 font-mono">
                                    {{ JSON.stringify(getFieldValue(selectedSubmission, field.id)) }}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="pt-4 mt-4 border-t border-cream-500/40 flex items-center justify-end shrink-0">
                    <button
                        type="button"
                        @click="closeDetailModal"
                        class="px-5 py-2 bg-forest-900 hover:bg-forest-950 text-white text-sm font-semibold rounded-xl shadow-xs transition"
                    >
                        Close
                    </button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

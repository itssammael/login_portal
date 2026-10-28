<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import FeedbackAnalyticsReport from './FeedbackAnalyticsReport.vue';
import { isDescendantOf, moveBlock, reorderBlock, reportBlocks } from './feedbackAnalyticsLayout';

const aspectW = 8.333333333;
const aspectH = 11.111111111;

const props = defineProps({ form: Object });
const emit = defineEmits(['close', 'publication']);

const reports = ref([]);
const questions = ref([]);
const selectedId = ref(null);
const publicReportId = ref(null);
const publication = ref({ has_published_report: false, public_url: null });
const busy = ref(false);
const error = ref('');
const notice = ref('');
const preview = ref(null);
const confirming = ref(false);
const reviewed = ref(false);
const approveSensitive = ref(false);
const selectedKey = ref('page');
const config = ref(null);
const sidebarOpen = ref(true);
const blockLibraryOpen = ref(false);
const pageSettingsOpen = ref(false);
const settingsPanelRef = ref(null);

const isAdmin = computed(() => Boolean(usePage().props.auth?.user?.is_admin));
const selectedReport = computed(() => reports.value.find((report) => report.id === selectedId.value));
const selectedBlock = computed(() => config.value ? reportBlocks(config.value).find((block) => block.key === selectedKey.value) : null);
const blankSections = computed(() => (config.value?.sections || []).filter((s) => s.kind === 'blank'));
const chartTypes = ['bar', 'column', 'line', 'area', 'pie', 'donut', 'radar', 'table', 'kpi'];
const borderStyles = ['solid', 'dashed', 'dotted', 'double', 'none'];

const clone = (value) => JSON.parse(JSON.stringify(value));
const aggregates = (question) => questions.value.find((item) => item.id === question)?.type === 'number'
    ? ['mean', 'sum', 'median', 'min', 'max', 'stddev']
    : ['count', 'percentage'];

const url = (name, report = selectedId.value) => route(`feedback.analytics.${name}`, {
    feedback: props.form.id,
    ...(report ? { report } : {}),
});

async function request(name, method = 'GET', body = null) {
    const response = await fetch(url(name), {
        method,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
        },
        ...(body ? { body: JSON.stringify(body) } : {}),
    });

    const result = await response.json().catch(() => ({}));
    if (!response.ok) {
        throw new Error(Object.values(result.errors || {}).flat().join(' ') || result.message || 'The request failed. Please try again.');
    }

    return result;
}

async function run(action) {
    busy.value = true;
    error.value = '';
    notice.value = '';
    try {
        await action();
    } catch (failure) {
        error.value = failure.message;
    } finally {
        busy.value = false;
    }
}

function normalizeLayout() {
    config.value.columns = 12;
    (config.value.sections || []).forEach((s) => {
        s.id ??= crypto.randomUUID();
    });
    const allBlocks = reportBlocks(config.value);
    const validBlankIds = new Set(
        (config.value.sections || [])
            .filter((s) => s.kind === 'blank')
            .map((s) => s.id),
    );

    allBlocks.forEach((block) => {
        if (block.kind === 'section' || block.kind === 'blank') {
            block.item.id ??= crypto.randomUUID();
        }
        if (
            block.item.parent_id
            && (
                !validBlankIds.has(block.item.parent_id)
                || block.item.parent_id === block.item.id
                || (block.kind === 'blank' && isDescendantOf(config.value, block.item.parent_id, block.item.id))
            )
        ) {
            block.item.parent_id = null;
        }
        block.item.parent_id ??= null;
        block.item.width ??= block.kind === 'section' || block.kind === 'blank' ? 12 : 6;
        if (block.item.width > 12) block.item.width = 12;
        if (block.item.width < 1) block.item.width = 1;
        block.item.height ??= block.kind === 'section' ? 3 : 5;
        if (block.item.height > 9) block.item.height = 9;
        if (block.item.height < 1) block.item.height = 1;

        if (block.kind === 'blank') {
            block.item.show_heading ??= true;
            block.item.rows = Math.min(Math.max(Number(block.item.rows) || 1, 1), 12);
            block.item.columns = Math.min(Math.max(Number(block.item.columns) || 12, 1), 12);
            block.item.border_width = Math.min(Math.max(Number(block.item.border_width ?? 1), 0), 16);
            block.item.border_radius = Math.min(Math.max(Number(block.item.border_radius ?? 16), 0), 48);
            block.item.border_style ??= 'solid';
            block.item.border_color ??= '#e7dcc3';
        }
    });

    const parentBuckets = new Set([null, ...validBlankIds]);
    parentBuckets.forEach((parentId) => {
        reportBlocks(config.value, parentId).forEach((block, index) => {
            block.item.position = index;
        });
    });
}

function onBlockWidthChange(item) {
    if (!item.width || item.width < 1) item.width = 1;
    if (item.width > 12) item.width = 12;
    config.value.columns = 12;
}

function onBlockHeightChange(item) {
    if (!item.height || item.height < 1) item.height = 1;
    if (item.height > 9) item.height = 9;
}

function onBlockParentChange(block) {
    if (!block) return;
    const newParentId = block.item.parent_id || null;
    reorderBlock(config.value, block.key, null, newParentId);
    normalizeLayout();
}

function newReport() {
    selectedId.value = null;
    selectedKey.value = 'page';
    config.value = {
        title: `${props.form.event.name} — Feedback report`,
        description: '',
        footer: '',
        columns: 12,
        show_submission_count: true,
        refresh_seconds: 300,
        period_start: null,
        period_end: null,
        sections: [],
        charts: [],
    };
    preview.value = null;
}

function selectReport() {
    if (!selectedId.value) return newReport();
    config.value = clone(selectedReport.value.configuration);
    config.value.columns = 12;
    normalizeLayout();
    selectedKey.value = 'page';
    refreshPreview();
}

function defaultTargetParentId() {
    if (selectedBlock.value?.kind === 'blank') {
        return selectedBlock.value.item.id;
    }
    if (selectedBlock.value?.item?.parent_id) {
        return selectedBlock.value.item.parent_id;
    }
    return null;
}

function createChart(question = questions.value[0], parentId = null) {
    if (!question || config.value.charts.length >= 24) return null;
    const chart = {
        id: crypto.randomUUID(),
        title: question.particular,
        question: question.id,
        type: question.type === 'number' ? 'kpi' : 'bar',
        aggregation: question.type === 'number' ? 'mean' : 'count',
        color: '#24543c',
        legend: true,
        labels: true,
        annotation: '',
        width: 6,
        height: 5,
        parent_id: parentId || null,
        position: reportBlocks(config.value, parentId || null).length,
    };
    config.value.charts.push(chart);
    return `chart:${chart.id}`;
}

function createCustomChart(parentId = null) {
    if (config.value.charts.length >= 24) return null;
    const firstQuestion = questions.value[0] || null;
    const chart = {
        id: crypto.randomUUID(),
        kind: 'custom',
        title: 'Custom Graph',
        question: firstQuestion?.id || null,
        type: 'column',
        aggregation: 'mean',
        color: '#24543c',
        legend: true,
        labels: true,
        annotation: '',
        width: 6,
        height: 5,
        parent_id: parentId || null,
        position: reportBlocks(config.value, parentId || null).length,
        rows: [
            firstQuestion
                ? {
                    id: crypto.randomUUID(),
                    label: firstQuestion.particular,
                    source: 'question',
                    question: firstQuestion.id,
                    operation: 'average',
                    value: null,
                }
                : {
                    id: crypto.randomUUID(),
                    label: 'Row 1',
                    source: 'manual',
                    question: null,
                    operation: 'average',
                    value: 0,
                },
        ],
    };
    config.value.charts.push(chart);
    return `chart:${chart.id}`;
}

function addCustomChartRow(chartItem, source = 'question') {
    chartItem.rows ??= [];
    if (chartItem.rows.length >= 24) return;
    const firstQuestion = questions.value[0] || null;
    const actualSource = source === 'question' && firstQuestion ? 'question' : 'manual';
    chartItem.rows.push({
        id: crypto.randomUUID(),
        label: actualSource === 'question' ? firstQuestion.particular : `Row ${chartItem.rows.length + 1}`,
        source: actualSource,
        question: actualSource === 'question' ? firstQuestion.id : null,
        operation: 'average',
        value: actualSource === 'manual' ? 0 : null,
    });
}

function removeCustomChartRow(chartItem, index) {
    if (!chartItem.rows || chartItem.rows.length <= 1) return;
    chartItem.rows.splice(index, 1);
}

function onCustomRowQuestionChange(row) {
    const q = questions.value.find((item) => item.id === row.question);
    if (q && (!row.label || row.label.startsWith('Row '))) {
        row.label = q.particular;
    }
}

function createSection(parentId = null) {
    if (config.value.sections.length >= 24) return null;
    const section = {
        id: crypto.randomUUID(),
        kind: 'text',
        title: 'Report notes',
        body: '',
        width: 12,
        height: 3,
        parent_id: parentId || null,
        position: reportBlocks(config.value, parentId || null).length,
    };
    config.value.sections.push(section);
    return `section:${section.id}`;
}

function createBlankSection(parentId = null) {
    if (config.value.sections.length >= 24) return null;
    const section = {
        id: crypto.randomUUID(),
        kind: 'blank',
        title: 'Blank section',
        show_heading: true,
        body: '',
        width: 12,
        height: 5,
        rows: 1,
        columns: 12,
        border_width: 1,
        border_radius: 16,
        border_style: 'solid',
        border_color: '#e7dcc3',
        parent_id: parentId || null,
        position: reportBlocks(config.value, parentId || null).length,
    };
    config.value.sections.push(section);
    return `section:${section.id}`;
}

function handleBlockSelect(key) {
    if (key === 'library') {
        blockLibraryOpen.value = true;
        sidebarOpen.value = true;
        return;
    }

    selectedKey.value = key;
    sidebarOpen.value = true;

    if (key === 'page') {
        pageSettingsOpen.value = true;
    } else if (key?.startsWith('chart:') || key?.startsWith('section:')) {
        pageSettingsOpen.value = true;
        const target = reportBlocks(config.value).find((block) => block.key === key);
        if (target?.kind === 'blank') {
            blockLibraryOpen.value = true;
        }
    }

    nextTick(() => {
        settingsPanelRef.value?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    });
}

function addBlock(kind, question = questions.value[0], explicitParentId = undefined) {
    const parentId = explicitParentId !== undefined ? explicitParentId : defaultTargetParentId();
    let key = null;
    if (kind === 'blank') {
        key = createBlankSection(parentId);
    } else if (kind === 'section') {
        key = createSection(parentId);
    } else if (kind === 'custom-chart') {
        key = createCustomChart(parentId);
    } else {
        key = createChart(question, parentId);
    }
    if (key) {
        normalizeLayout();
        handleBlockSelect(key);
    }
}

function templates() {
    config.value.charts = [];
    questions.value.slice(0, 24).forEach((question) => createChart(question));
    normalizeLayout();
    const key = config.value.charts[0] ? `chart:${config.value.charts[0].id}` : 'page';
    handleBlockSelect(key);
}

function dragLibrary(event, kind, question = null) {
    event.dataTransfer.effectAllowed = 'copy';
    event.dataTransfer.setData('application/x-feedback-analytics-block', JSON.stringify({
        source: 'library',
        kind,
        question: question?.id,
    }));
}

function dragBlock({ event, key }) {
    event.dataTransfer.effectAllowed = 'move';
    event.dataTransfer.setData('application/x-feedback-analytics-block', JSON.stringify({
        source: 'canvas',
        key,
    }));
}

function dropBlock({ event, beforeKey, parentId = null }) {
    try {
        const payload = JSON.parse(event.dataTransfer.getData('application/x-feedback-analytics-block'));
        if (payload.source === 'canvas') {
            reorderBlock(config.value, payload.key, beforeKey, parentId);
        }
        if (payload.source === 'library') {
            let key = null;
            if (payload.kind === 'blank') {
                key = createBlankSection(parentId);
            } else if (payload.kind === 'section') {
                key = createSection(parentId);
            } else if (payload.kind === 'custom-chart') {
                key = createCustomChart(parentId);
            } else {
                key = createChart(questions.value.find((question) => question.id === payload.question), parentId);
            }
            if (key) {
                reorderBlock(config.value, key, beforeKey, parentId);
                handleBlockSelect(key);
            }
        }
        normalizeLayout();
    } catch {
        error.value = 'The block could not be placed. Try dragging it again.';
    }
}

function updateCanvasText({ key, field, value }) {
    if (key === 'page') {
        config.value[field] = value;
    } else {
        const target = reportBlocks(config.value).find((block) => block.key === key);
        if (target) {
            target.item[field] = value;
        }
    }
}

function removeSelected() {
    if (!selectedBlock.value) return;
    const { kind, item } = selectedBlock.value;
    if (kind === 'blank' && item.id) {
        reportBlocks(config.value, item.id).forEach((child) => {
            child.item.parent_id = item.parent_id || null;
        });
    }
    const collection = kind === 'chart' ? 'charts' : 'sections';
    config.value[collection] = config.value[collection].filter((entry) => entry !== item);
    normalizeLayout();
    selectedKey.value = 'page';
}

async function load() {
    const result = await request('publication');
    reports.value = result.reports;
    questions.value = result.questions.filter((field) => (!field.option_source || field.option_source === 'static') && !field.allow_custom_value);
    publication.value = result.publication;
    publicReportId.value = result.selected_report_id;
    emit('publication', result.publication);
}

async function refreshPreview() {
    if (!config.value?.charts.length) {
        preview.value = { charts: {}, submission_count: null, refreshed_at: null };
        return;
    }
    preview.value = await request('preview', 'POST', { configuration: config.value });
}

async function save() {
    normalizeLayout();
    const result = await request(selectedId.value ? 'update' : 'store', selectedId.value ? 'PUT' : 'POST', {
        configuration: config.value,
    });
    selectedId.value = result.report.id;
    await load();
    notice.value = 'Draft saved. Publication changes only when you explicitly publish.';
}

async function publish() {
    await save();
    await request('publish', 'POST', {
        confirm_public: true,
        replace_report_id: publicReportId.value,
        approve_sensitive: approveSensitive.value,
    });
    await load();
    confirming.value = false;
    notice.value = 'Analytics page published.';
}

async function unpublish() {
    await request('unpublish', 'POST');
    await load();
    notice.value = 'Analytics page is now unavailable.';
}

async function copyLink() {
    await navigator.clipboard.writeText(new URL(publication.value.public_url, window.location.origin).href);
    notice.value = 'Analytics URL copied.';
}

const handleKeyDown = (event) => {
    if (event.key === 'Escape') {
        emit('close');
    }
};

watch(() => [
    config.value?.period_start,
    config.value?.period_end,
    config.value?.charts?.map((c) => {
        const rowsSig = (c.rows || []).map((r) => `${r.id}:${r.label}:${r.source}:${r.question}:${r.operation}:${r.value}`).join('|');
        return `${c.id}:${c.kind || 'standard'}:${c.question}:${c.aggregation}:${rowsSig}`;
    }).join(','),
], () => {
    preview.value = null;
});

onMounted(() => {
    window.addEventListener('keydown', handleKeyDown);
    run(async () => {
        await load();
        newReport();
    });
});

onBeforeUnmount(() => {
    window.removeEventListener('keydown', handleKeyDown);
});
</script>

<template>
    <div
        class="fixed inset-0 z-50 flex h-screen w-screen flex-col overflow-hidden bg-cream-100 font-sans text-gray-900 select-none animate-in fade-in duration-150"
        role="dialog"
        aria-modal="true"
        aria-labelledby="analytics-title"
    >
        <!-- Top Google Forms-Style Navigation Header -->
        <header class="bg-[#fffef9] border-b border-cream-400 px-4 sm:px-6 py-2.5 flex items-center justify-between shrink-0 shadow-xs z-30">
            <div class="flex items-center space-x-2.5 sm:space-x-3 truncate">
                <!-- Hamburger Icon Button to Collapse / Expand the Left Sidebar -->
                <button
                    type="button"
                    class="p-2 rounded-xl border border-cream-400 bg-white hover:bg-cream-200 text-gray-700 transition cursor-pointer shadow-2xs flex items-center justify-center shrink-0"
                    :class="sidebarOpen ? 'bg-cream-200 text-forest-900 border-forest-600' : ''"
                    :title="sidebarOpen ? 'Collapse sidebar (Block Library & Settings)' : 'Expand sidebar (Block Library & Settings)'"
                    :aria-label="sidebarOpen ? 'Collapse sidebar' : 'Expand sidebar'"
                    @click="sidebarOpen = !sidebarOpen"
                >
                    <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
                    </svg>
                </button>

                <div class="size-8 rounded-lg bg-forest-900 text-lime-200 flex items-center justify-center font-black text-sm shrink-0 shadow-2xs">
                    📊
                </div>

                <div class="truncate">
                    <h2 id="analytics-title" class="text-sm sm:text-base font-bold text-gray-900 leading-tight truncate">
                        Analytics Report Builder
                    </h2>
                    <p class="text-[11px] sm:text-xs text-gray-500 truncate">
                        {{ form.event?.name }} · Drafts are private until published.
                    </p>
                </div>
            </div>

            <div class="flex items-center space-x-2 shrink-0">
                <span class="text-xs font-semibold px-2.5 py-1 rounded-full bg-cream-200 text-gray-700">
                    {{ selectedReport?.status ? `${selectedReport.status.charAt(0).toUpperCase() + selectedReport.status.slice(1)}` : 'Unsaved draft' }}
                </span>
                <button
                    type="button"
                    class="px-3 py-1.5 bg-cream-100 hover:bg-cream-200 text-gray-700 border border-cream-400 text-xs font-bold rounded-xl transition cursor-pointer"
                    @click="emit('close')"
                >
                    Close
                </button>
            </div>
        </header>

        <!-- Saved Reports & Actions Toolbar -->
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-cream-400 bg-cream-50 px-4 sm:px-6 py-2.5 shrink-0 z-20">
            <div class="flex flex-wrap items-center gap-2">
                <label class="flex items-center gap-2 text-xs font-bold uppercase tracking-wide text-gray-600">
                    <span>Saved reports:</span>
                    <select
                        v-model="selectedId"
                        :disabled="busy"
                        class="rounded-lg border-cream-400 bg-white py-1 px-2.5 text-xs font-medium text-gray-800 normal-case shadow-2xs focus:border-forest-600 focus:ring-forest-600"
                        @change="selectReport"
                    >
                        <option :value="null">New report</option>
                        <option v-for="report in reports" :key="report.id" :value="report.id">
                            {{ report.configuration?.title || 'Untitled report' }} ({{ report.status }})
                        </option>
                    </select>
                </label>
                <button
                    type="button"
                    class="rounded-lg border border-cream-400 bg-white px-2.5 py-1 text-xs font-semibold text-gray-700 shadow-2xs hover:bg-cream-200 transition cursor-pointer disabled:opacity-50"
                    :disabled="busy"
                    @click="newReport"
                >
                    ＋ New report
                </button>
                <button
                    type="button"
                    class="rounded-lg border border-cream-400 bg-white px-2.5 py-1 text-xs font-semibold text-gray-700 shadow-2xs hover:bg-cream-200 transition cursor-pointer disabled:opacity-50"
                    :disabled="busy"
                    @click="run(refreshPreview)"
                >
                    ↺ Refresh canvas data
                </button>
                <button
                    v-if="!sidebarOpen"
                    type="button"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-forest-800 bg-white px-2.5 py-1 text-xs font-bold text-forest-900 shadow-2xs hover:bg-cream-200 transition cursor-pointer"
                    title="Open Library & Settings sidebar"
                    @click="sidebarOpen = true"
                >
                    <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
                    </svg>
                    <span>Show Tools (15%)</span>
                </button>
            </div>

            <div v-if="selectedKey !== 'page'" class="flex items-center gap-1.5 text-xs text-gray-500">
                <span class="size-2 rounded-full bg-emerald-500"></span>
                <span class="font-medium">
                    Selected: {{ selectedBlock ? (selectedBlock.kind === 'chart' ? selectedBlock.item.title : (selectedBlock.kind === 'blank' ? (selectedBlock.item.title || 'Blank section') : 'Section notes')) : 'Page' }}
                </span>
                <button type="button" class="text-xs font-bold text-forest-800 underline hover:text-forest-950 ml-1 cursor-pointer" @click="handleBlockSelect('page')">
                    Edit Page Settings
                </button>
            </div>
        </div>

        <!-- Alert messages -->
        <div v-if="error || notice" class="px-4 sm:px-6 pt-3 shrink-0">
            <p v-if="error" role="alert" class="rounded-xl bg-red-50 border border-red-200 p-3 text-xs sm:text-sm text-red-800">{{ error }}</p>
            <p v-if="notice" role="status" class="rounded-xl bg-emerald-50 border border-emerald-200 p-3 text-xs sm:text-sm text-emerald-800">{{ notice }}</p>
        </div>

        <!-- Main 2-Grid Workspace: Left Grid (max 15vw, collapsible) + Right Grid (Visual Canvas) -->
        <main
            v-if="config"
            class="flex-1 min-h-0 grid overflow-hidden bg-[#f8f5ee] transition-all duration-300"
            :style="{ gridTemplateColumns: sidebarOpen ? 'minmax(0, 15vw) minmax(0, 1fr)' : '0px minmax(0, 1fr)' }"
        >
            <!-- LEFT GRID: Stacked Block Library + Page Settings (Max 15% view width, collapsible) -->
            <aside
                class="h-full overflow-y-auto overflow-x-hidden border-r border-cream-400 bg-white transition-all duration-300 flex flex-col min-w-0"
                :class="sidebarOpen ? 'w-[20vw] max-w-[15vw] p-3 opacity-100' : 'w-0 max-w-0 p-0 border-0 opacity-0 overflow-hidden pointer-events-none'"
            >
                <!-- Sidebar header with collapse action -->
                <div class="flex items-center justify-between pb-2 mb-3 border-b border-cream-300 shrink-0">
                    <span class="text-xs font-black uppercase tracking-wider text-forest-950 truncate">Tools & Settings</span>
                    <button
                        type="button"
                        class="p-1 rounded-lg text-gray-500 hover:bg-cream-200 hover:text-gray-900 transition cursor-pointer shrink-0"
                        title="Collapse sidebar"
                        aria-label="Collapse sidebar"
                        @click="sidebarOpen = false"
                    >
                        <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M18.75 19.5l-7.5-7.5 7.5-7.5m-6 15L5.25 12l7.5-7.5" />
                        </svg>
                    </button>
                </div>

                <div class="space-y-2.5 pb-6 flex-1">
                    <!-- ACCORDION ITEM 1: BLOCK LIBRARY -->
                    <div class="rounded-xl border border-cream-400 bg-[#fffefc] overflow-hidden shadow-2xs shrink-0 transition-colors">
                        <button
                            type="button"
                            class="w-full flex items-center justify-between p-2.5 text-left bg-cream-50 hover:bg-cream-100/80 transition cursor-pointer select-none"
                            :class="blockLibraryOpen ? 'border-b border-cream-300 bg-cream-100/60' : ''"
                            :aria-expanded="blockLibraryOpen"
                            @click="blockLibraryOpen = !blockLibraryOpen"
                        >
                            <div class="flex items-center gap-2 min-w-0">
                                <span class="size-6 rounded-md bg-forest-900 text-lime-200 flex items-center justify-center text-xs shrink-0 font-bold">
                                    ⊞
                                </span>
                                <div class="min-w-0 truncate">
                                    <h3 class="text-xs font-bold text-gray-900 truncate">Block Library</h3>
                                    <p class="text-[10px] text-gray-500 truncate">Drag or click blocks</p>
                                </div>
                            </div>
                            <svg
                                class="size-4 text-gray-500 transition-transform duration-200 shrink-0 ml-1"
                                :class="blockLibraryOpen ? 'rotate-180 text-forest-800' : ''"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke-width="2"
                                stroke="currentColor"
                            >
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
                            </svg>
                        </button>

                        <div v-show="blockLibraryOpen" class="p-2.5 space-y-3">
                            <div class="space-y-2.5">
                                <div>
                                    <p class="mb-1 text-[11px] font-bold text-gray-600">Content</p>
                                    <div class="space-y-1.5">
                                        <button
                                            type="button"
                                            draggable="true"
                                            class="library-block"
                                            @dragstart="dragLibrary($event, 'blank')"
                                            @click="addBlock('blank')"
                                        >
                                            <span class="font-bold text-forest-800">▢</span>
                                            <span class="min-w-0 truncate">
                                                <b>Blank Section</b>
                                                <small>Container with rows, cols &amp; borders</small>
                                            </span>
                                        </button>
                                        <button
                                            type="button"
                                            draggable="true"
                                            class="library-block"
                                            @dragstart="dragLibrary($event, 'section')"
                                            @click="addBlock('section')"
                                        >
                                            <span class="font-bold text-forest-800">¶</span>
                                            <span class="min-w-0 truncate">
                                                <b>Text section</b>
                                                <small>Heading and notes</small>
                                            </span>
                                        </button>
                                    </div>
                                </div>

                                <div>
                                    <p class="mb-1 text-[11px] font-bold text-gray-600">Charts & KPI cards</p>
                                    <div class="space-y-1.5">
                                        <button
                                            type="button"
                                            draggable="true"
                                            class="library-block"
                                            @dragstart="dragLibrary($event, 'custom-chart')"
                                            @click="addBlock('custom-chart')"
                                        >
                                            <span class="font-bold text-forest-800">📊</span>
                                            <span class="min-w-0 truncate">
                                                <b class="truncate">Custom Graph</b>
                                                <small class="truncate">Custom data table (SUM / AVERAGE)</small>
                                            </span>
                                        </button>
                                        <button
                                            v-for="question in questions"
                                            :key="question.id"
                                            type="button"
                                            draggable="true"
                                            class="library-block"
                                            @dragstart="dragLibrary($event, 'chart', question)"
                                            @click="addBlock('chart', question)"
                                        >
                                            <span class="font-bold text-forest-800">{{ question.type === 'number' ? '◎' : '▥' }}</span>
                                            <span class="min-w-0 truncate">
                                                <b class="truncate">{{ question.particular }}</b>
                                                <small class="truncate">{{ question.type === 'number' ? 'Numeric KPI' : 'Chart / table' }}</small>
                                            </span>
                                        </button>
                                    </div>
                                    <p v-if="!questions.length" class="text-[10px] leading-4 text-gray-500 mt-1">
                                        No structured questions can be made public.
                                    </p>
                                </div>
                            </div>

                            <button
                                type="button"
                                class="mt-2 w-full rounded-xl border border-cream-400 bg-cream-50 hover:bg-cream-100 px-2 py-1.5 text-[11px] font-bold text-gray-700 shadow-2xs transition disabled:opacity-50 cursor-pointer"
                                :disabled="busy || !questions.length"
                                @click="templates"
                            >
                                Use default templates
                            </button>
                        </div>
                    </div>

                    <!-- ACCORDION ITEM 2: PAGE & BLOCK SETTINGS -->
                    <div
                        ref="settingsPanelRef"
                        class="rounded-xl border border-cream-400 bg-[#fffefc] overflow-hidden shadow-2xs transition-colors"
                        :class="selectedBlock ? 'ring-2 ring-forest-600/30 border-forest-600' : ''"
                    >
                        <button
                            type="button"
                            class="w-full flex items-center justify-between p-2.5 text-left bg-cream-50 hover:bg-cream-100/80 transition cursor-pointer select-none"
                            :class="pageSettingsOpen ? 'border-b border-cream-300 bg-cream-100/60' : ''"
                            :aria-expanded="pageSettingsOpen"
                            @click="pageSettingsOpen = !pageSettingsOpen"
                        >
                            <div class="flex items-center gap-2 min-w-0">
                                <span class="size-6 rounded-md bg-cream-200 text-forest-900 flex items-center justify-center text-xs shrink-0 font-bold">
                                    ⚙
                                </span>
                                <div class="min-w-0 truncate">
                                    <h3 class="text-xs font-bold text-gray-900 truncate">
                                        {{ selectedBlock ? `${selectedBlock.kind === 'chart' ? (selectedBlock.item.kind === 'custom' ? 'Custom Graph' : 'Chart') : (selectedBlock.kind === 'blank' ? 'Blank Section' : 'Section')} Settings` : 'Page Settings' }}
                                    </h3>
                                    <p class="text-[10px] text-gray-500 truncate">
                                        {{ selectedBlock ? (selectedBlock.kind === 'chart' ? selectedBlock.item.title : (selectedBlock.kind === 'blank' ? 'Grid & border design' : 'Section text')) : 'Title, layout, refresh' }}
                                    </p>
                                </div>
                            </div>
                            <svg
                                class="size-4 text-gray-500 transition-transform duration-200 shrink-0 ml-1"
                                :class="pageSettingsOpen ? 'rotate-180 text-forest-800' : ''"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke-width="2"
                                stroke="currentColor"
                            >
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
                            </svg>
                        </button>

                        <div v-show="pageSettingsOpen" class="p-2.5 space-y-3">
                            <div v-if="selectedBlock" class="flex items-center justify-between pb-1.5 border-b border-cream-200">
                                <span class="text-[10px] font-bold uppercase tracking-wider text-emerald-800">Selected Block</span>
                                <button
                                    type="button"
                                    class="text-[10px] font-bold text-forest-800 underline hover:text-forest-950 cursor-pointer"
                                    @click="handleBlockSelect('page')"
                                >
                                    Edit Page Settings
                                </button>
                            </div>

                            <!-- Page Settings Form -->
                            <div v-if="!selectedBlock" class="space-y-3">
                                <label class="field">
                                    <span>Report title</span>
                                    <input v-model="config.title" maxlength="500" class="text-xs" />
                                </label>
                                <label class="field">
                                    <span>Description</span>
                                    <textarea v-model="config.description" maxlength="2000" rows="2" class="text-xs" />
                                </label>
                                <label class="field">
                                    <span>Grid layout</span>
                                    <div class="rounded-lg border border-cream-400 bg-cream-50/70 px-2.5 py-1.5 text-xs text-gray-700 flex items-center justify-between">
                                        <span class="font-bold text-forest-900">12 Columns Grid</span>
                                        <span class="text-[10px] text-gray-500 font-mono">8.333% per col</span>
                                    </div>
                                </label>
                                <label class="field">
                                    <span>Period start</span>
                                    <input v-model="config.period_start" type="date" class="text-xs" />
                                </label>
                                <label class="field">
                                    <span>Period end</span>
                                    <input v-model="config.period_end" type="date" class="text-xs" />
                                </label>
                                <label class="field">
                                    <span>Refresh rate</span>
                                    <select v-model.number="config.refresh_seconds" class="text-xs">
                                        <option :value="300">Every 5 min</option>
                                        <option :value="900">Every 15 min</option>
                                        <option :value="3600">Hourly</option>
                                        <option :value="86400">Daily</option>
                                    </select>
                                </label>
                                <label class="flex items-start gap-1.5 text-[11px] text-gray-700">
                                    <input v-model="config.show_submission_count" type="checkbox" class="mt-0.5 rounded border-gray-300 text-forest-700 focus:ring-forest-600">
                                    <span>Show response count</span>
                                </label>
                                <label class="field">
                                    <span>Footer</span>
                                    <textarea v-model="config.footer" maxlength="1000" rows="2" class="text-xs" />
                                </label>
                            </div>

                            <!-- Chart & Custom Graph Settings Form -->
                            <div v-else-if="selectedBlock.kind === 'chart'" class="space-y-3">
                                <label class="field">
                                    <span>Chart title</span>
                                    <input v-model="selectedBlock.item.title" maxlength="500" class="text-xs" />
                                </label>
                                <label v-if="blankSections.length" class="field">
                                    <span>Parent container</span>
                                    <select
                                        v-model="selectedBlock.item.parent_id"
                                        class="text-xs"
                                        @change="onBlockParentChange(selectedBlock)"
                                    >
                                        <option :value="null">Main Canvas (Root)</option>
                                        <option v-for="sec in blankSections" :key="sec.id" :value="sec.id">
                                            {{ sec.title || 'Blank Section' }}
                                        </option>
                                    </select>
                                </label>

                                <!-- Custom Data Table Editor for Custom Graph -->
                                <div v-if="selectedBlock.item.kind === 'custom'" class="space-y-2 rounded-xl border border-cream-400 bg-cream-50/60 p-2">
                                    <div class="flex items-center justify-between">
                                        <span class="text-[11px] font-bold text-forest-950">Custom Data Table</span>
                                        <span class="text-[10px] text-gray-500">{{ (selectedBlock.item.rows || []).length }} row(s)</span>
                                    </div>

                                    <div class="space-y-2 max-h-64 overflow-y-auto pr-0.5">
                                        <div
                                            v-for="(row, rIdx) in selectedBlock.item.rows"
                                            :key="row.id || rIdx"
                                            class="rounded-lg border border-cream-300 bg-white p-2 space-y-1.5 shadow-2xs"
                                        >
                                            <div class="flex items-center justify-between gap-1">
                                                <span class="text-[10px] font-bold text-gray-500">Row #{{ rIdx + 1 }}</span>
                                                <button
                                                    v-if="selectedBlock.item.rows.length > 1"
                                                    type="button"
                                                    class="text-[10px] font-bold text-red-700 hover:underline cursor-pointer"
                                                    @click="removeCustomChartRow(selectedBlock.item, rIdx)"
                                                >
                                                    Remove
                                                </button>
                                            </div>

                                            <label class="field">
                                                <span>Row Label</span>
                                                <input v-model="row.label" maxlength="500" placeholder="Row label…" class="text-xs" />
                                            </label>

                                            <div class="grid grid-cols-2 gap-1.5">
                                                <label class="field">
                                                    <span>Data Source</span>
                                                    <select v-model="row.source" class="text-xs">
                                                        <option value="question">Question</option>
                                                        <option value="manual">Custom Value</option>
                                                    </select>
                                                </label>
                                                <label v-if="row.source === 'question'" class="field">
                                                    <span>Calculation</span>
                                                    <select v-model="row.operation" class="text-xs font-semibold">
                                                        <option value="average">AVERAGE</option>
                                                        <option value="sum">SUM</option>
                                                    </select>
                                                </label>
                                                <label v-else class="field">
                                                    <span>Value</span>
                                                    <input v-model.number="row.value" type="number" step="any" class="text-xs" />
                                                </label>
                                            </div>

                                            <label v-if="row.source === 'question'" class="field">
                                                <span>Question (e.g. Ratings)</span>
                                                <select v-model="row.question" class="text-xs" @change="onCustomRowQuestionChange(row)">
                                                    <option v-for="question in questions" :key="question.id" :value="question.id">
                                                        {{ question.particular }}
                                                    </option>
                                                </select>
                                            </label>
                                        </div>
                                    </div>

                                    <div class="flex flex-wrap gap-1.5 pt-1">
                                        <button
                                            type="button"
                                            class="flex-1 rounded-lg border border-forest-700 bg-white hover:bg-emerald-50 px-2 py-1 text-[10px] font-bold text-forest-900 cursor-pointer"
                                            @click="addCustomChartRow(selectedBlock.item, 'question')"
                                        >
                                            ＋ Question Row (SUM/AVG)
                                        </button>
                                        <button
                                            type="button"
                                            class="rounded-lg border border-cream-400 bg-white hover:bg-cream-100 px-2 py-1 text-[10px] font-semibold text-gray-700 cursor-pointer"
                                            @click="addCustomChartRow(selectedBlock.item, 'manual')"
                                        >
                                            ＋ Custom Value
                                        </button>
                                    </div>
                                </div>

                                <template v-else>
                                    <label class="field">
                                        <span>Question</span>
                                        <select v-model="selectedBlock.item.question" class="text-xs" @change="selectedBlock.item.aggregation = aggregates(selectedBlock.item.question)[0]">
                                            <option v-for="question in questions" :key="question.id" :value="question.id">{{ question.particular }}</option>
                                        </select>
                                    </label>
                                </template>

                                <label class="field">
                                    <span>Visualization</span>
                                    <select v-model="selectedBlock.item.type" class="text-xs">
                                        <option v-for="type in chartTypes" :key="type">{{ type }}</option>
                                    </select>
                                </label>
                                <label v-if="selectedBlock.item.kind !== 'custom'" class="field">
                                    <span>Calculation</span>
                                    <select v-model="selectedBlock.item.aggregation" class="text-xs">
                                        <option v-for="aggregation in aggregates(selectedBlock.item.question)" :key="aggregation">{{ aggregation }}</option>
                                    </select>
                                </label>
                                <label class="field">
                                    <span>Width</span>
                                    <select
                                        v-model.number="selectedBlock.item.width"
                                        class="text-xs font-medium"
                                        @change="onBlockWidthChange(selectedBlock.item)"
                                    >
                                        <option v-for="w in 12" :key="w" :value="w">
                                            {{ w }} {{ w === 1 ? 'col' : 'cols' }} ({{ (w * aspectW).toFixed(1) }}%)
                                        </option>
                                    </select>
                                </label>
                                <label class="field">
                                    <span>Height</span>
                                    <select
                                        v-model.number="selectedBlock.item.height"
                                        class="text-xs font-medium"
                                        @change="onBlockHeightChange(selectedBlock.item)"
                                    >
                                        <option v-for="h in 9" :key="h" :value="h">
                                            {{ h }} {{ h === 1 ? 'row' : 'rows' }} ({{ (h * aspectH).toFixed(1) }}%)
                                        </option>
                                    </select>
                                </label>
                                <label class="field">
                                    <span>Color</span>
                                    <input v-model="selectedBlock.item.color" type="color" class="h-8 p-1" />
                                </label>
                                <div class="flex flex-wrap gap-2 text-[11px] text-gray-700">
                                    <label class="flex items-center gap-1.5"><input v-model="selectedBlock.item.legend" type="checkbox" class="rounded border-gray-300 text-forest-700 focus:ring-forest-600">Legend</label>
                                    <label class="flex items-center gap-1.5"><input v-model="selectedBlock.item.labels" type="checkbox" class="rounded border-gray-300 text-forest-700 focus:ring-forest-600">Labels</label>
                                </div>
                                <label class="field">
                                    <span>Annotation</span>
                                    <textarea v-model="selectedBlock.item.annotation" maxlength="1000" rows="2" class="text-xs" />
                                </label>
                                <button
                                    type="button"
                                    class="mt-2 w-full rounded-xl border border-red-300 bg-red-50/50 hover:bg-red-50 px-2 py-1 text-xs font-semibold text-red-800 transition cursor-pointer"
                                    @click="removeSelected"
                                >
                                    {{ selectedBlock.item.kind === 'custom' ? 'Remove custom graph' : 'Remove chart' }}
                                </button>
                            </div>

                            <!-- Blank Section Container Settings Form -->
                            <div v-else-if="selectedBlock.kind === 'blank'" class="space-y-3">
                                <div class="space-y-1">
                                    <div class="flex items-center justify-between gap-2">
                                        <span class="text-xs font-bold text-[#374151]">Section heading (optional)</span>
                                        <label class="inline-flex items-center gap-1.5 cursor-pointer select-none text-[11px] font-semibold text-gray-600">
                                            <input
                                                type="checkbox"
                                                :checked="selectedBlock.item.show_heading === false"
                                                class="sr-only peer"
                                                @change="selectedBlock.item.show_heading = !$event.target.checked"
                                            />
                                            <span class="relative w-7 h-4 bg-gray-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-3 after:w-3 after:transition-all peer-checked:bg-forest-800"></span>
                                            <span>Hide</span>
                                        </label>
                                    </div>
                                    <label v-if="selectedBlock.item.show_heading !== false" class="field">
                                        <input v-model="selectedBlock.item.title" maxlength="500" placeholder="Optional heading…" class="text-xs" />
                                    </label>
                                </div>
                                <label v-if="blankSections.filter((s) => !isDescendantOf(config, s.id, selectedBlock.item.id)).length" class="field">
                                    <span>Parent container</span>
                                    <select
                                        v-model="selectedBlock.item.parent_id"
                                        class="text-xs"
                                        @change="onBlockParentChange(selectedBlock)"
                                    >
                                        <option :value="null">Main Canvas (Root)</option>
                                        <option
                                            v-for="sec in blankSections.filter((s) => !isDescendantOf(config, s.id, selectedBlock.item.id))"
                                            :key="sec.id"
                                            :value="sec.id"
                                        >
                                            {{ sec.title || 'Blank Section' }}
                                        </option>
                                    </select>
                                </label>

                                <div class="grid grid-cols-2 gap-2">
                                    <label class="field">
                                        <span>Rows</span>
                                        <select v-model.number="selectedBlock.item.rows" class="text-xs font-medium">
                                            <option v-for="r in 12" :key="r" :value="r">
                                                {{ r }} {{ r === 1 ? 'row' : 'rows' }}
                                            </option>
                                        </select>
                                    </label>
                                    <label class="field">
                                        <span>Columns</span>
                                        <select v-model.number="selectedBlock.item.columns" class="text-xs font-medium">
                                            <option v-for="c in 12" :key="c" :value="c">
                                                {{ c }} {{ c === 1 ? 'col' : 'cols' }}
                                            </option>
                                        </select>
                                    </label>
                                </div>

                                <div class="grid grid-cols-2 gap-2">
                                    <label class="field">
                                        <span>Width</span>
                                        <select
                                            v-model.number="selectedBlock.item.width"
                                            class="text-xs font-medium"
                                            @change="onBlockWidthChange(selectedBlock.item)"
                                        >
                                            <option v-for="w in 12" :key="w" :value="w">
                                                {{ w }} {{ w === 1 ? 'col' : 'cols' }}
                                            </option>
                                        </select>
                                    </label>
                                    <label class="field">
                                        <span>Height</span>
                                        <select
                                            v-model.number="selectedBlock.item.height"
                                            class="text-xs font-medium"
                                            @change="onBlockHeightChange(selectedBlock.item)"
                                        >
                                            <option v-for="h in 9" :key="h" :value="h">
                                                {{ h }} {{ h === 1 ? 'row' : 'rows' }}
                                            </option>
                                        </select>
                                    </label>
                                </div>

                                <div class="pt-2 border-t border-cream-200 space-y-2.5">
                                    <p class="text-[11px] font-bold text-gray-700">Border Design</p>
                                    <div class="grid grid-cols-2 gap-2">
                                        <label class="field">
                                            <span>Style</span>
                                            <select v-model="selectedBlock.item.border_style" class="text-xs capitalize">
                                                <option v-for="style in borderStyles" :key="style" :value="style">{{ style }}</option>
                                            </select>
                                        </label>
                                        <label class="field">
                                            <span>Thickness (px)</span>
                                            <input
                                                v-model.number="selectedBlock.item.border_width"
                                                type="number"
                                                min="0"
                                                max="16"
                                                class="text-xs"
                                            />
                                        </label>
                                    </div>
                                    <div class="grid grid-cols-2 gap-2">
                                        <label class="field">
                                            <span>Radius (px)</span>
                                            <input
                                                v-model.number="selectedBlock.item.border_radius"
                                                type="number"
                                                min="0"
                                                max="48"
                                                class="text-xs"
                                            />
                                        </label>
                                        <label class="field">
                                            <span>Border color</span>
                                            <input v-model="selectedBlock.item.border_color" type="color" class="h-8 p-1" />
                                        </label>
                                    </div>
                                </div>

                                <div class="pt-2 border-t border-cream-200 space-y-1.5">
                                    <p class="text-[11px] font-bold text-gray-700">Insert block inside</p>
                                    <div class="flex flex-wrap gap-1.5">
                                        <button
                                            type="button"
                                            class="rounded-lg border border-cream-400 bg-cream-50 hover:bg-cream-100 px-2 py-1 text-[11px] font-semibold text-forest-900 cursor-pointer"
                                            @click="addBlock('blank', null, selectedBlock.item.id)"
                                        >
                                            ＋ Blank section
                                        </button>
                                        <button
                                            type="button"
                                            class="rounded-lg border border-cream-400 bg-cream-50 hover:bg-cream-100 px-2 py-1 text-[11px] font-semibold text-forest-900 cursor-pointer"
                                            @click="addBlock('section', null, selectedBlock.item.id)"
                                        >
                                            ＋ Text section
                                        </button>
                                        <button
                                            type="button"
                                            class="rounded-lg border border-cream-400 bg-cream-50 hover:bg-cream-100 px-2 py-1 text-[11px] font-semibold text-forest-900 cursor-pointer"
                                            @click="addBlock('custom-chart', null, selectedBlock.item.id)"
                                        >
                                            ＋ Custom Graph
                                        </button>
                                        <button
                                            v-if="questions.length"
                                            type="button"
                                            class="rounded-lg border border-cream-400 bg-cream-50 hover:bg-cream-100 px-2 py-1 text-[11px] font-semibold text-forest-900 cursor-pointer"
                                            @click="addBlock('chart', questions[0], selectedBlock.item.id)"
                                        >
                                            ＋ Chart / KPI
                                        </button>
                                    </div>
                                </div>

                                <button
                                    type="button"
                                    class="mt-2 w-full rounded-xl border border-red-300 bg-red-50/50 hover:bg-red-50 px-2 py-1 text-xs font-semibold text-red-800 transition cursor-pointer"
                                    @click="removeSelected"
                                >
                                    Remove blank section
                                </button>
                            </div>

                            <!-- Text Section Settings Form -->
                            <div v-else class="space-y-3">
                                <label class="field">
                                    <span>Section title</span>
                                    <input v-model="selectedBlock.item.title" maxlength="500" class="text-xs" />
                                </label>
                                <label v-if="blankSections.length" class="field">
                                    <span>Parent container</span>
                                    <select
                                        v-model="selectedBlock.item.parent_id"
                                        class="text-xs"
                                        @change="onBlockParentChange(selectedBlock)"
                                    >
                                        <option :value="null">Main Canvas (Root)</option>
                                        <option v-for="sec in blankSections" :key="sec.id" :value="sec.id">
                                            {{ sec.title || 'Blank Section' }}
                                        </option>
                                    </select>
                                </label>
                                <label class="field">
                                    <span>Text</span>
                                    <textarea v-model="selectedBlock.item.body" maxlength="3000" rows="4" class="text-xs" />
                                </label>
                                <label class="field">
                                    <span>Width</span>
                                    <select
                                        v-model.number="selectedBlock.item.width"
                                        class="text-xs font-medium"
                                        @change="onBlockWidthChange(selectedBlock.item)"
                                    >
                                        <option v-for="w in 12" :key="w" :value="w">
                                            {{ w }} {{ w === 1 ? 'col' : 'cols' }} ({{ (w * aspectW).toFixed(1) }}%)
                                        </option>
                                    </select>
                                </label>
                                <label class="field">
                                    <span>Height</span>
                                    <select
                                        v-model.number="selectedBlock.item.height"
                                        class="text-xs font-medium"
                                        @change="onBlockHeightChange(selectedBlock.item)"
                                    >
                                        <option v-for="h in 9" :key="h" :value="h">
                                            {{ h }} {{ h === 1 ? 'row' : 'rows' }} ({{ (h * aspectH).toFixed(1) }}%)
                                        </option>
                                    </select>
                                </label>
                                <button
                                    type="button"
                                    class="mt-2 w-full rounded-xl border border-red-300 bg-red-50/50 hover:bg-red-50 px-2 py-1 text-xs font-semibold text-red-800 transition cursor-pointer"
                                    @click="removeSelected"
                                >
                                    Remove section
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </aside>

            <!-- RIGHT GRID: Visual Canvas (dynamically fills remaining space spared by left grid) -->
            <section class="h-full min-w-0 overflow-y-auto bg-[#f8f5ee] p-4 sm:p-6 lg:p-8 flex-1">
                <div class="mx-auto max-w-8xl space-y-4 pb-20">
                    <div class="rounded-xl border border-cream-400/80 bg-white p-5 shadow-sm sm:p-8">
                        <div class="mb-5 flex items-center justify-between gap-4">
                            <div class="flex items-center gap-3">
                                <button
                                    v-if="!sidebarOpen"
                                    type="button"
                                    class="p-1.5 rounded-lg border border-cream-400 bg-cream-50 hover:bg-cream-100 text-forest-900 transition cursor-pointer shadow-2xs flex items-center gap-1.5 text-xs font-bold"
                                    title="Open Block Library & Settings"
                                    @click="sidebarOpen = true"
                                >
                                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
                                    </svg>
                                    <span>Open Sidebar</span>
                                </button>
                                <div>
                                    <p class="text-xs font-bold uppercase tracking-widest text-emerald-700">Visual canvas</p>
                                    <p class="text-xs text-gray-500">Click any block to edit it. Drag blocks to reorder.</p>
                                </div>
                            </div>
                            <span class="rounded-full bg-emerald-100 px-3 py-1 text-xs font-bold text-emerald-800">WYSIWYG</span>
                        </div>
                        <FeedbackAnalyticsReport
                            :report="config"
                            :data="preview"
                            :event-title="form.event.name"
                            editable
                            :selected-key="selectedKey"
                            @select="handleBlockSelect"
                            @drag-block="dragBlock"
                            @drop-block="dropBlock"
                            @move="({ key, direction }) => { moveBlock(config, key, direction); normalizeLayout(); }"
                            @edit-text="updateCanvasText"
                        />
                    </div>
                </div>
            </section>
        </main>
        <div v-else class="flex flex-1 items-center justify-center p-10 text-sm text-gray-500">
            Preparing the report builder…
        </div>

        <!-- Sticky Bottom Actions Footer -->
        <footer v-if="config" class="shrink-0 border-t border-cream-400 bg-cream-50 px-4 sm:px-6 py-3 z-30 shadow-xs">
            <section v-if="publication.has_published_report" class="mb-2.5 flex flex-wrap items-center gap-x-4 gap-y-2 rounded-xl bg-emerald-50 border border-emerald-200 px-3 py-1.5 text-xs text-emerald-900">
                <span class="font-bold">Published</span>
                <code class="max-w-full truncate">{{ publication.public_url }}</code>
                <button type="button" class="underline hover:text-emerald-950 font-bold cursor-pointer" @click="run(copyLink)">Copy URL</button>
                <a :href="publication.public_url" target="_blank" rel="noopener" class="underline hover:text-emerald-950 font-bold">Open ↗</a>
            </section>
            <div class="flex flex-wrap items-center gap-2 sm:gap-3">
                <button
                    type="button"
                    :disabled="busy"
                    class="rounded-xl border border-forest-800 bg-white px-3.5 py-1.5 text-xs sm:text-sm font-bold text-forest-900 shadow-2xs hover:bg-cream-100 transition disabled:opacity-50 cursor-pointer"
                    @click="run(save)"
                >
                    Save Draft
                </button>
                <button
                    type="button"
                    :disabled="busy"
                    class="rounded-xl border border-cream-400 bg-white px-3.5 py-1.5 text-xs sm:text-sm font-semibold text-gray-700 shadow-2xs hover:bg-cream-100 transition disabled:opacity-50 cursor-pointer"
                    @click="run(refreshPreview)"
                >
                    Preview data
                </button>
                <button
                    type="button"
                    :disabled="busy || !config.charts.length"
                    class="rounded-xl bg-forest-900 px-4 py-1.5 text-xs sm:text-sm font-bold text-white shadow-xs hover:bg-forest-950 transition disabled:opacity-50 cursor-pointer"
                    @click="confirming = true; reviewed = false; approveSensitive = false"
                >
                    {{ publicReportId === selectedId && selectedId ? 'Update Published Report' : 'Publish Analytics Page' }}
                </button>
                <button
                    v-if="selectedId && publicReportId === selectedId"
                    type="button"
                    :disabled="busy"
                    class="rounded-xl border border-red-300 bg-white px-3.5 py-1.5 text-xs sm:text-sm font-semibold text-red-800 shadow-2xs hover:bg-red-50 transition disabled:opacity-50 cursor-pointer"
                    @click="run(unpublish)"
                >
                    Unpublish
                </button>
            </div>
        </footer>

        <!-- Confirmation Prompt Overlay for Publishing -->
        <section
            v-if="confirming"
            class="fixed inset-x-4 bottom-16 z-50 max-w-xl mx-auto rounded-2xl border border-amber-300 bg-amber-50 p-5 shadow-2xl sm:inset-x-auto sm:right-6 sm:bottom-20"
        >
            <h3 class="font-bold text-gray-900">Publish this analytics report?</h3>
            <p class="mt-1 text-sm text-gray-600">Anyone with the URL may view its approved aggregated data.</p>
            <label class="mt-4 flex items-start gap-2 text-sm text-gray-700">
                <input v-model="reviewed" type="checkbox" class="mt-1 rounded border-amber-400 text-forest-700 focus:ring-forest-600">
                <span>I reviewed labels, notes, and data tables for private information.</span>
            </label>
            <label v-if="isAdmin" class="mt-3 flex items-start gap-2 text-sm text-gray-700">
                <input v-model="approveSensitive" type="checkbox" class="mt-1 rounded border-amber-400 text-forest-700 focus:ring-forest-600">
                <span>I approve sensitive demographic breakdowns, if present.</span>
            </label>
            <div class="mt-4 flex gap-3">
                <button
                    type="button"
                    :disabled="busy || !reviewed"
                    class="rounded-xl bg-forest-900 px-4 py-2 text-sm font-bold text-white hover:bg-forest-950 transition disabled:opacity-50 cursor-pointer"
                    @click="run(publish)"
                >
                    Confirm publication
                </button>
                <button
                    type="button"
                    :disabled="busy"
                    class="rounded-xl border border-gray-300 bg-white px-3 py-2 text-sm text-gray-700 hover:bg-gray-100 transition cursor-pointer"
                    @click="confirming = false"
                >
                    Cancel
                </button>
            </div>
        </section>
    </div>
</template>

<style scoped>
.library-block {
    display: flex;
    width: 100%;
    gap: 0.5rem;
    align-items: flex-start;
    border: 1px solid #e7dcc3;
    border-radius: 0.625rem;
    padding: 0.5rem;
    background: white;
    text-align: left;
    font-size: 0.75rem;
    cursor: grab;
    line-height: 1.25;
}
.library-block:hover {
    border-color: #28704a;
    background: #f0fdf4;
}
.library-block:active {
    cursor: grabbing;
}
.library-block b {
    display: block;
    font-weight: 700;
    color: #1f2937;
}
.library-block small {
    display: block;
    margin-top: 0.125rem;
    color: #6b7280;
    font-size: 0.6875rem;
}
.field {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
    font-size: 0.75rem;
    font-weight: 700;
    color: #374151;
}
.field input, .field select, .field textarea {
    width: 100%;
    border-radius: 0.5rem;
    border-color: #e7dcc3;
    font-size: 0.8125rem;
    font-weight: 400;
    padding: 0.375rem 0.5rem;
}
.field input[type='color'] {
    width: 100%;
}
</style>


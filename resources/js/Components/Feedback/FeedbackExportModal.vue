<script setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';

const props = defineProps({
    show: {
        type: Boolean,
        default: false,
    },
    initialFormat: {
        type: String,
        default: 'csv',
    },
    exportableCount: {
        type: Number,
        default: 0,
    },
    exportableFields: {
        type: Object,
        default: () => ({ metadata: [], questions: [] }),
    },
    filters: {
        type: Object,
        default: () => ({}),
    },
    currentTab: {
        type: String,
        default: 'individual',
    },
});

const emit = defineEmits(['close']);

const selectedFormat = ref('csv');
const searchQuery = ref('');
const selectedTokens = ref([]);
const closeBtnRef = ref(null);

const metadataFields = computed(() => {
    return Array.isArray(props.exportableFields?.metadata)
        ? props.exportableFields.metadata
        : [];
});

const questionFields = computed(() => {
    return Array.isArray(props.exportableFields?.questions)
        ? props.exportableFields.questions
        : [];
});

const hasMultipleEvents = computed(() => {
    const names = new Set(
        questionFields.value
            .map((q) => q.event_name)
            .filter((name) => typeof name === 'string' && name.trim() !== '')
    );
    return names.size > 1;
});

const catalogMap = computed(() => {
    const map = new Map();
    for (const item of metadataFields.value) {
        map.set(item.token, {
            ...item,
            category: 'metadata',
            badge: 'META',
        });
    }
    for (const item of questionFields.value) {
        map.set(item.token, {
            ...item,
            category: 'question',
            badge: (item.type || 'text').toUpperCase(),
        });
    }
    return map;
});

const defaultTokenOrder = computed(() => {
    return [
        ...metadataFields.value.map((m) => m.token),
        ...questionFields.value.map((q) => q.token),
    ];
});

const resetSelectionToDefault = () => {
    selectedTokens.value = [...defaultTokenOrder.value];
};

// Keep selection synchronized whenever the underlying available field catalog changes
watch(
    () => defaultTokenOrder.value.join('|'),
    () => {
        resetSelectionToDefault();
    },
    { immediate: true }
);

watch(
    () => props.show,
    async (isOpen) => {
        if (isOpen) {
            selectedFormat.value = props.initialFormat === 'pdf' ? 'pdf' : 'csv';
            searchQuery.value = '';

            // Ensure at least valid catalog tokens exist on open
            const validExisting = selectedTokens.value.filter((t) => catalogMap.value.has(t));
            if (validExisting.length === 0 && defaultTokenOrder.value.length > 0) {
                resetSelectionToDefault();
            } else {
                selectedTokens.value = validExisting;
            }

            window.addEventListener('keydown', onWindowKeydown);
            await nextTick();
            closeBtnRef.value?.focus();
        } else {
            window.removeEventListener('keydown', onWindowKeydown);
        }
    }
);

onBeforeUnmount(() => {
    window.removeEventListener('keydown', onWindowKeydown);
});

const onWindowKeydown = (event) => {
    if (event.key === 'Escape' && props.show) {
        emit('close');
    }
};

const selectedSet = computed(() => new Set(selectedTokens.value));

const filteredMetadataFields = computed(() => {
    const term = searchQuery.value.trim().toLowerCase();
    if (!term) {
        return metadataFields.value;
    }
    return metadataFields.value.filter((item) =>
        item.label.toLowerCase().includes(term) || item.key.toLowerCase().includes(term)
    );
});

const filteredQuestionFields = computed(() => {
    const term = searchQuery.value.trim().toLowerCase();
    if (!term) {
        return questionFields.value;
    }
    return questionFields.value.filter((item) =>
        (item.label || '').toLowerCase().includes(term) ||
        (item.prompt || '').toLowerCase().includes(term) ||
        (item.type || '').toLowerCase().includes(term) ||
        (item.event_name || '').toLowerCase().includes(term)
    );
});

const selectedMetadataCount = computed(() =>
    metadataFields.value.filter((m) => selectedSet.value.has(m.token)).length
);

const selectedQuestionCount = computed(() =>
    questionFields.value.filter((q) => selectedSet.value.has(q.token)).length
);

const selectedOrderedItems = computed(() =>
    selectedTokens.value
        .map((token) => catalogMap.value.get(token))
        .filter(Boolean)
);

const isDefaultSelection = computed(() => {
    const def = defaultTokenOrder.value;
    if (selectedTokens.value.length !== def.length) {
        return false;
    }
    return selectedTokens.value.every((token, idx) => token === def[idx]);
});

const toggleToken = (token) => {
    if (selectedSet.value.has(token)) {
        selectedTokens.value = selectedTokens.value.filter((t) => t !== token);
    } else {
        selectedTokens.value = [...selectedTokens.value, token];
    }
};

const toggleAllMetadata = () => {
    const allMetaTokens = metadataFields.value.map((m) => m.token);
    const allSelected = allMetaTokens.every((t) => selectedSet.value.has(t));

    if (allSelected) {
        const metaSet = new Set(allMetaTokens);
        selectedTokens.value = selectedTokens.value.filter((t) => !metaSet.has(t));
    } else {
        const missing = allMetaTokens.filter((t) => !selectedSet.value.has(t));
        selectedTokens.value = [...selectedTokens.value, ...missing];
    }
};

const toggleAllQuestions = () => {
    const allQuestionTokens = questionFields.value.map((q) => q.token);
    const allSelected = allQuestionTokens.length > 0 && allQuestionTokens.every((t) => selectedSet.value.has(t));

    if (allSelected) {
        const qSet = new Set(allQuestionTokens);
        selectedTokens.value = selectedTokens.value.filter((t) => !qSet.has(t));
    } else {
        const missing = allQuestionTokens.filter((t) => !selectedSet.value.has(t));
        selectedTokens.value = [...selectedTokens.value, ...missing];
    }
};

const selectQuestionsOnly = () => {
    if (questionFields.value.length === 0) {
        return;
    }
    selectedTokens.value = questionFields.value.map((q) => q.token);
};

const selectMetadataOnly = () => {
    selectedTokens.value = metadataFields.value.map((m) => m.token);
};

const clearAllSelections = () => {
    selectedTokens.value = [];
};

const moveSelected = (index, direction) => {
    const targetIndex = index + direction;
    if (targetIndex < 0 || targetIndex >= selectedTokens.value.length) {
        return;
    }
    const updated = [...selectedTokens.value];
    const [moved] = updated.splice(index, 1);
    updated.splice(targetIndex, 0, moved);
    selectedTokens.value = updated;
};

const downloadUrl = computed(() => {
    if (selectedTokens.value.length === 0) {
        return '#';
    }

    const applied = props.filters || {};
    const query = {
        tab: props.currentTab,
    };

    if (applied.event_id) {
        query.event_id = applied.event_id;
    }
    if (applied.function_id) {
        query.function_id = applied.function_id;
    }
    if (applied.agency) {
        query.agency = applied.agency;
    }
    if (applied.designation) {
        query.designation = applied.designation;
    }
    if (props.currentTab === 'individual' && applied.search) {
        query.search = applied.search;
    }
    if (!isDefaultSelection.value) {
        query.fields = selectedTokens.value.join(',');
    }

    return route('admin.feedback.submissions.export', {
        format: selectedFormat.value,
        ...query,
    });
});

const onDownloadClick = () => {
    if (selectedTokens.value.length === 0) {
        return;
    }
    // Close modal shortly after initiating native browser download
    window.setTimeout(() => {
        emit('close');
    }, 120);
};
</script>

<template>
    <div
        v-if="show"
        class="fixed inset-0 z-50 overflow-y-auto bg-gray-900/60 backdrop-blur-xs flex items-center justify-center p-3 sm:p-5"
        @click.self="emit('close')"
    >
        <div
            role="dialog"
            aria-modal="true"
            aria-labelledby="export-field-builder-title"
            class="bg-cream-200 rounded-3xl max-w-5xl w-full shadow-2xl border border-cream-500/60 max-h-[92vh] flex flex-col overflow-hidden"
        >
            <!-- Modal Header -->
            <div class="px-6 py-4 bg-cream-300/70 border-b border-cream-500/50 flex flex-wrap items-center justify-between gap-4 shrink-0">
                <div>
                    <div class="flex items-center gap-2">
                        <span class="inline-flex items-center px-2 py-0.5 rounded-md text-[10px] font-bold uppercase tracking-wider bg-lime-200 text-forest-900 border border-forest-500/20">
                            Export Builder
                        </span>
                        <span class="text-[11px] font-semibold text-gray-600">
                            {{ exportableCount }} matching {{ exportableCount === 1 ? 'submission' : 'submissions' }}
                        </span>
                    </div>
                    <h3 id="export-field-builder-title" class="text-lg font-bold text-gray-900 mt-1">
                        Choose Fields to Include in Export
                    </h3>
                </div>

                <div class="flex items-center gap-3">
                    <!-- Format Switcher -->
                    <div
                        role="group"
                        aria-label="Export file format"
                        class="inline-flex p-1 bg-[#fffef9] rounded-xl border border-cream-500 shadow-2xs"
                    >
                        <button
                            type="button"
                            @click="selectedFormat = 'csv'"
                            class="px-3 py-1.5 rounded-lg text-xs font-bold transition"
                            :class="selectedFormat === 'csv'
                                ? 'bg-forest-900 text-white shadow-2xs'
                                : 'text-gray-700 hover:bg-cream-200'"
                        >
                            CSV (Excel)
                        </button>
                        <button
                            type="button"
                            @click="selectedFormat = 'pdf'"
                            class="px-3 py-1.5 rounded-lg text-xs font-bold transition"
                            :class="selectedFormat === 'pdf'
                                ? 'bg-forest-900 text-white shadow-2xs'
                                : 'text-gray-700 hover:bg-cream-200'"
                        >
                            PDF Report
                        </button>
                    </div>

                    <button
                        ref="closeBtnRef"
                        type="button"
                        @click="emit('close')"
                        class="p-1.5 rounded-xl text-gray-500 hover:text-gray-800 hover:bg-cream-300 transition"
                        aria-label="Close export field builder"
                    >
                        <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
            </div>

            <!-- Presets & Search Bar -->
            <div class="px-6 py-3 bg-[#fffef9] border-b border-cream-400/60 flex flex-wrap items-center justify-between gap-3 shrink-0">
                <div class="flex flex-wrap items-center gap-1.5">
                    <span class="text-[11px] font-bold uppercase tracking-wider text-gray-500 me-1">Presets:</span>
                    <button
                        type="button"
                        @click="resetSelectionToDefault"
                        class="px-2.5 py-1 rounded-lg text-xs font-semibold bg-cream-200 hover:bg-cream-300 text-forest-900 border border-cream-400 transition"
                    >
                        All Fields (Default)
                    </button>
                    <button
                        type="button"
                        :disabled="questionFields.length === 0"
                        @click="selectQuestionsOnly"
                        class="px-2.5 py-1 rounded-lg text-xs font-semibold bg-cream-200 hover:bg-cream-300 text-forest-900 border border-cream-400 transition disabled:opacity-40 disabled:cursor-not-allowed"
                    >
                        Questions Only
                    </button>
                    <button
                        type="button"
                        @click="selectMetadataOnly"
                        class="px-2.5 py-1 rounded-lg text-xs font-semibold bg-cream-200 hover:bg-cream-300 text-forest-900 border border-cream-400 transition"
                    >
                        Participant Info Only
                    </button>
                    <button
                        type="button"
                        @click="clearAllSelections"
                        class="px-2.5 py-1 rounded-lg text-xs font-semibold bg-cream-100 hover:bg-red-50 text-gray-600 hover:text-red-700 border border-cream-400 transition"
                    >
                        Clear All
                    </button>
                </div>

                <div class="w-full sm:w-60">
                    <input
                        v-model="searchQuery"
                        type="search"
                        placeholder="Filter available fields..."
                        class="w-full px-3 py-1.5 bg-cream-100/70 border border-cream-400 focus:border-forest-600 rounded-xl text-xs text-gray-800"
                    />
                </div>
            </div>

            <!-- Main Builder Grid -->
            <div class="p-6 overflow-y-auto flex-1 grid grid-cols-1 lg:grid-cols-5 gap-5">
                <!-- Left Column: Available Field Checklists -->
                <div class="lg:col-span-3 space-y-5">
                    <!-- Group 1: Metadata -->
                    <div class="bg-[#fffef9] rounded-2xl border border-cream-400/80 p-4 shadow-2xs">
                        <div class="flex items-center justify-between gap-2 pb-3 mb-3 border-b border-cream-300">
                            <div>
                                <h4 class="text-xs font-bold uppercase tracking-wider text-forest-900">
                                    Submission & Participant Metadata
                                </h4>
                                <p class="text-[11px] text-gray-500 mt-0.5">
                                    {{ selectedMetadataCount }} of {{ metadataFields.length }} metadata fields included
                                </p>
                            </div>
                            <button
                                type="button"
                                @click="toggleAllMetadata"
                                class="text-xs font-bold text-forest-800 hover:text-forest-950 underline"
                            >
                                {{ selectedMetadataCount === metadataFields.length ? 'Uncheck All' : 'Check All' }}
                            </button>
                        </div>

                        <div v-if="filteredMetadataFields.length === 0" class="text-xs text-gray-400 italic py-2">
                            No metadata fields match "{{ searchQuery }}".
                        </div>

                        <div v-else class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                            <label
                                v-for="meta in filteredMetadataFields"
                                :key="meta.token"
                                class="flex items-center gap-2.5 p-2.5 rounded-xl border cursor-pointer transition select-none"
                                :class="selectedSet.has(meta.token)
                                    ? 'bg-lime-100/60 border-forest-600/40 text-gray-900'
                                    : 'bg-cream-100/40 border-cream-300 text-gray-600 hover:bg-cream-100'"
                            >
                                <input
                                    type="checkbox"
                                    :checked="selectedSet.has(meta.token)"
                                    @change="toggleToken(meta.token)"
                                    class="rounded border-cream-500 text-forest-800 focus:ring-forest-600"
                                />
                                <span class="text-xs font-semibold truncate">{{ meta.label }}</span>
                            </label>
                        </div>
                    </div>

                    <!-- Group 2: Questionnaire Fields -->
                    <div class="bg-[#fffef9] rounded-2xl border border-cream-400/80 p-4 shadow-2xs">
                        <div class="flex items-center justify-between gap-2 pb-3 mb-3 border-b border-cream-300">
                            <div>
                                <h4 class="text-xs font-bold uppercase tracking-wider text-forest-900">
                                    Questionnaire Answer Fields
                                </h4>
                                <p class="text-[11px] text-gray-500 mt-0.5">
                                    {{ selectedQuestionCount }} of {{ questionFields.length }} questionnaire fields included
                                </p>
                            </div>
                            <button
                                v-if="questionFields.length > 0"
                                type="button"
                                @click="toggleAllQuestions"
                                class="text-xs font-bold text-forest-800 hover:text-forest-950 underline"
                            >
                                {{ selectedQuestionCount === questionFields.length ? 'Uncheck All' : 'Check All' }}
                            </button>
                        </div>

                        <div v-if="questionFields.length === 0" class="text-xs text-gray-400 italic py-3">
                            No answerable questionnaire fields found for the selected event scope.
                        </div>
                        <div v-else-if="filteredQuestionFields.length === 0" class="text-xs text-gray-400 italic py-3">
                            No questionnaire fields match "{{ searchQuery }}".
                        </div>
                        <div v-else class="space-y-2">
                            <label
                                v-for="q in filteredQuestionFields"
                                :key="q.token"
                                class="flex items-start justify-between gap-3 p-2.5 rounded-xl border cursor-pointer transition select-none"
                                :class="selectedSet.has(q.token)
                                    ? 'bg-lime-100/60 border-forest-600/40 text-gray-900'
                                    : 'bg-cream-100/40 border-cream-300 text-gray-600 hover:bg-cream-100'"
                            >
                                <div class="flex items-start gap-2.5 min-w-0">
                                    <input
                                        type="checkbox"
                                        :checked="selectedSet.has(q.token)"
                                        @change="toggleToken(q.token)"
                                        class="mt-0.5 rounded border-cream-500 text-forest-800 focus:ring-forest-600 shrink-0"
                                    />
                                    <div class="min-w-0">
                                        <span class="text-xs font-semibold block leading-snug break-words">
                                            {{ q.prompt || q.label }}
                                        </span>
                                        <span
                                            v-if="hasMultipleEvents && q.event_name"
                                            class="inline-block mt-1 px-2 py-0.5 rounded bg-cream-200 text-[10px] font-semibold text-forest-900"
                                        >
                                            {{ q.event_name }}
                                        </span>
                                    </div>
                                </div>
                                <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase bg-cream-200 text-forest-900 border border-cream-400/70 shrink-0">
                                    {{ q.type }}
                                </span>
                            </label>
                        </div>
                    </div>
                </div>

                <!-- Right Column: Included Fields & Export Order -->
                <div class="lg:col-span-2 bg-[#fffef9] rounded-2xl border border-cream-400/80 p-4 shadow-2xs flex flex-col max-h-[54vh] lg:max-h-none">
                    <div class="pb-3 mb-3 border-b border-cream-300 flex items-center justify-between">
                        <div>
                            <h4 class="text-xs font-bold uppercase tracking-wider text-forest-900">
                                Export Field Order ({{ selectedOrderedItems.length }})
                            </h4>
                            <p class="text-[11px] text-gray-500 mt-0.5">
                                {{ selectedFormat === 'csv' ? 'Columns appear left-to-right in this order' : 'Metadata & questions follow this sequence' }}
                            </p>
                        </div>
                    </div>

                    <div
                        v-if="selectedOrderedItems.length === 0"
                        class="flex-1 flex flex-col items-center justify-center text-center p-6 bg-amber-50/70 rounded-xl border border-amber-200"
                    >
                        <p class="text-xs font-bold text-amber-900">No Fields Selected</p>
                        <p class="text-[11px] text-amber-700 mt-1">
                            Check at least one metadata or questionnaire field on the left to enable export.
                        </p>
                    </div>

                    <div v-else class="space-y-1.5 overflow-y-auto pr-1 flex-1">
                        <div
                            v-for="(item, idx) in selectedOrderedItems"
                            :key="item.token"
                            class="flex items-center justify-between gap-2 px-2.5 py-2 rounded-xl bg-cream-100/80 border border-cream-300 text-xs"
                        >
                            <div class="flex items-center gap-2 min-w-0">
                                <span class="text-[10px] font-mono font-bold text-gray-500 w-5 shrink-0">
                                    {{ idx + 1 }}.
                                </span>
                                <span class="font-semibold text-gray-800 truncate" :title="item.label">
                                    {{ item.label }}
                                </span>
                            </div>

                            <div class="flex items-center gap-1 shrink-0">
                                <span
                                    class="px-1.5 py-0.5 rounded text-[9px] font-bold uppercase"
                                    :class="item.category === 'metadata'
                                        ? 'bg-cream-300 text-gray-700'
                                        : 'bg-lime-200 text-forest-900'"
                                >
                                    {{ item.badge }}
                                </span>
                                <button
                                    type="button"
                                    :disabled="idx === 0"
                                    @click="moveSelected(idx, -1)"
                                    class="p-1 rounded hover:bg-cream-300 text-gray-600 disabled:opacity-30 disabled:cursor-not-allowed"
                                    title="Move up"
                                    aria-label="Move field up"
                                >
                                    ↑
                                </button>
                                <button
                                    type="button"
                                    :disabled="idx === selectedOrderedItems.length - 1"
                                    @click="moveSelected(idx, 1)"
                                    class="p-1 rounded hover:bg-cream-300 text-gray-600 disabled:opacity-30 disabled:cursor-not-allowed"
                                    title="Move down"
                                    aria-label="Move field down"
                                >
                                    ↓
                                </button>
                                <button
                                    type="button"
                                    @click="toggleToken(item.token)"
                                    class="p-1 rounded hover:bg-red-100 text-gray-500 hover:text-red-700"
                                    title="Remove field"
                                    aria-label="Remove field from export"
                                >
                                    ✕
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Modal Footer -->
            <div class="px-6 py-4 bg-cream-300/60 border-t border-cream-500/50 flex flex-wrap items-center justify-between gap-3 shrink-0">
                <div class="text-xs text-gray-700">
                    <span v-if="selectedOrderedItems.length > 0">
                        Ready to export
                        <strong class="font-bold text-forest-900">{{ selectedOrderedItems.length }}</strong>
                        of {{ defaultTokenOrder.length }} fields across
                        <strong class="font-bold text-forest-900">{{ exportableCount }}</strong>
                        {{ exportableCount === 1 ? 'submission' : 'submissions' }}.
                    </span>
                    <span v-else class="font-semibold text-amber-800">
                        Please select at least 1 field before downloading.
                    </span>
                </div>

                <div class="flex items-center gap-2.5">
                    <button
                        type="button"
                        @click="emit('close')"
                        class="px-4 py-2 bg-[#fffef9] hover:bg-cream-100 text-gray-700 border border-cream-500 rounded-xl text-xs font-bold transition"
                    >
                        Cancel
                    </button>

                    <a
                        v-if="selectedOrderedItems.length > 0"
                        :href="downloadUrl"
                        @click="onDownloadClick"
                        class="inline-flex items-center gap-1.5 px-5 py-2 bg-forest-900 hover:bg-forest-950 text-white rounded-xl text-xs font-bold shadow-xs transition"
                    >
                        <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3" />
                        </svg>
                        <span>
                            {{ selectedFormat === 'pdf' ? 'Download PDF Report' : 'Download CSV (Excel)' }}
                        </span>
                    </a>
                    <button
                        v-else
                        type="button"
                        disabled
                        class="inline-flex items-center gap-1.5 px-5 py-2 bg-gray-300 text-gray-500 rounded-xl text-xs font-bold cursor-not-allowed"
                    >
                        <span>
                            {{ selectedFormat === 'pdf' ? 'Download PDF Report' : 'Download CSV (Excel)' }}
                        </span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>


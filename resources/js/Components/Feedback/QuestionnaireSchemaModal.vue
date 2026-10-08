<script setup>
import { ref, computed, watch, nextTick, onMounted, onUnmounted } from 'vue';
import { useForm, router } from '@inertiajs/vue3';
import axios from 'axios';

const props = defineProps({
    show: {
        type: Boolean,
        default: false,
    },
    initialEventId: {
        type: [Number, String],
        default: null,
    },
    initialFormRecord: {
        type: Object,
        default: null,
    },
    events: {
        type: Array,
        required: true,
    },
    functions: {
        type: Array,
        default: () => [],
    },
    feedbackForms: {
        type: Array,
        default: () => [],
    },
    agencies: {
        type: Array,
        default: () => [],
    },
    designations: {
        type: Array,
        default: () => [],
    },
});

const emit = defineEmits(['close', 'saved', 'preview']);

const activeTab = ref('questions'); // 'questions' | 'logic' | 'json' | 'import'
const activeFieldIdx = ref(0);
const jsonSchemaString = ref('');
const jsonParseError = ref('');
const highlightedSchemaFieldIdx = ref(null);
let schemaHighlightTimer = null;

// More options dropdown state per field
const openMenuFieldIdx = ref(null);

const toggleFieldMenu = (idx) => {
    openMenuFieldIdx.value = openMenuFieldIdx.value === idx ? null : idx;
};

const closeAllFieldMenus = () => {
    openMenuFieldIdx.value = null;
};

// Default Schema Template
const defaultSchemaTemplate = {
    title: 'Untitled feedback form',
    description: 'Please complete this evaluation questionnaire to help us improve future activities.',
    pagination: {
        enabled: false,
        progress_bar: true,
        show_section_numbers: true,
    },
    fields: [
        {
            id: 'overall_rating',
            particular: 'Overall exercise rating',
            type: 'radio',
            weight: 1,
            required: true,
            allow_other: false,
            option_source: 'static',
            function_ids: [],
            options: [
                { value: 'excellent', label: '⭐⭐⭐⭐⭐ Outstanding / Excellent', is_other: false, goto_section: null },
                { value: 'very_good', label: '⭐⭐⭐⭐ Very Good', is_other: false, goto_section: null },
                { value: 'satisfactory', label: '⭐⭐⭐ Satisfactory', is_other: false, goto_section: null },
                { value: 'needs_improvement', label: '⚠️ Needs Improvement', is_other: false, goto_section: null },
            ],
            manualId: false,
        },
    ],
};

const formConfig = useForm({
    event_id: '',
    schema: JSON.parse(JSON.stringify(defaultSchemaTemplate)),
});

// Helper to convert strings to clean snake_case
const toSnakeCase = (str) => {
    return String(str || '')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '_')
        .replace(/^_+|_+$/g, '');
};

const onParticularInput = (field) => {
    if (!field.manualId && field.particular) {
        field.id = toSnakeCase(field.particular) || (field.type === 'section' ? `section_${field.weight}` : `field_${field.weight}`);
    }
};

const reindexWeights = () => {
    if (!Array.isArray(formConfig.schema?.fields)) return;
    formConfig.schema.fields.forEach((field, index) => {
        field.weight = index + 1;
    });
    if (activeTab.value === 'json') {
        jsonSchemaString.value = JSON.stringify(formConfig.schema, null, 2);
    }
};

watch(
    () => formConfig.schema?.fields,
    (fields) => {
        if (Array.isArray(fields)) {
            let changed = false;
            fields.forEach((field, index) => {
                const targetWeight = index + 1;
                if (field.weight !== targetWeight) {
                    field.weight = targetWeight;
                    changed = true;
                }
            });
            if (changed && activeTab.value === 'questions') {
                jsonSchemaString.value = JSON.stringify(formConfig.schema, null, 2);
            }
        }
    },
    { deep: true }
);

// Sections metadata
const sectionList = computed(() => {
    if (!formConfig.schema?.fields) return [];
    return formConfig.schema.fields
        .map((f, idx) => ({ ...f, originalIndex: idx }))
        .filter((f) => f.type === 'section');
});

const getSectionIndex = (sectionField) => {
    const idx = sectionList.value.findIndex((s) => s.id === sectionField.id);
    return idx >= 0 ? idx + 1 : 1;
};

// Which section does a field belong to?
const getFieldSection = (fieldIdx) => {
    if (!formConfig.schema?.fields) return null;
    let currentSec = null;
    for (let i = 0; i <= fieldIdx; i++) {
        if (formConfig.schema.fields[i]?.type === 'section') {
            currentSec = formConfig.schema.fields[i];
        }
    }
    return currentSec;
};

// Available questions that precede a section for conditions
const getAvailableConditionQuestions = (sectionIdx) => {
    if (!formConfig.schema?.fields) return [];
    return formConfig.schema.fields
        .slice(0, sectionIdx)
        .filter((f) => f.type !== 'section' && ['radio', 'select', 'checkbox', 'text'].includes(f.type));
};

// Available destination options for "Go to section based on answer"
const availableDestinations = computed(() => {
    const list = [
        { value: 'next', label: 'Continue to next section' },
    ];
    sectionList.value.forEach((sec, idx) => {
        list.push({
            value: sec.id,
            label: `Go to Section ${idx + 1}: ${sec.particular || 'Untitled Section'}`,
        });
    });
    list.push({ value: 'submit', label: 'Submit form' });
    return list;
});

// Target Event Computations
const currentSelectedEvent = computed(() => {
    return props.events.find((e) => e.id === Number(formConfig.event_id)) || null;
});

const currentEventFunctions = computed(() => {
    return currentSelectedEvent.value?.functions || [];
});

const unconfiguredEvents = computed(() => {
    return props.events.filter((ev) => !props.feedbackForms.some((f) => f.event_id === ev.id));
});

// Initialize / Sync when modal opens
const initializeModal = () => {
    formConfig.clearErrors();
    jsonParseError.value = '';
    activeTab.value = 'questions';
    activeFieldIdx.value = 0;

    let targetEventId = props.initialEventId;
    let targetForm = props.initialFormRecord;

    if (targetForm) {
        formConfig.event_id = targetForm.event_id;
        formConfig.schema = JSON.parse(JSON.stringify(targetForm.schema));
    } else if (targetEventId) {
        formConfig.event_id = Number(targetEventId);
        const existing = props.feedbackForms.find((f) => f.event_id === Number(targetEventId));
        formConfig.schema = existing
            ? JSON.parse(JSON.stringify(existing.schema))
            : JSON.parse(JSON.stringify(defaultSchemaTemplate));
    } else {
        const firstUnconfigured = unconfiguredEvents.value[0];
        if (firstUnconfigured) {
            formConfig.event_id = firstUnconfigured.id;
            formConfig.schema = JSON.parse(JSON.stringify(defaultSchemaTemplate));
        } else if (props.events.length > 0) {
            formConfig.event_id = props.events[0].id;
            const existing = props.feedbackForms.find((f) => f.event_id === Number(formConfig.event_id));
            formConfig.schema = existing
                ? JSON.parse(JSON.stringify(existing.schema))
                : JSON.parse(JSON.stringify(defaultSchemaTemplate));
        } else {
            formConfig.event_id = '';
            formConfig.schema = JSON.parse(JSON.stringify(defaultSchemaTemplate));
        }
    }

    if (!formConfig.schema?.fields || formConfig.schema.fields.length === 0) {
        formConfig.schema = JSON.parse(JSON.stringify(defaultSchemaTemplate));
    }

    // Ensure pagination object exists
    if (!formConfig.schema.pagination) {
        formConfig.schema.pagination = {
            enabled: sectionList.value.length > 1,
            progress_bar: true,
            show_section_numbers: true,
        };
    }

    // Ensure title & description
    if (formConfig.schema.title === undefined) {
        formConfig.schema.title = currentSelectedEvent.value?.name
            ? `${currentSelectedEvent.value.name} Evaluation`
            : 'Untitled feedback form';
    }
    if (formConfig.schema.description === undefined) {
        formConfig.schema.description = 'Please complete this evaluation questionnaire to help us improve future activities.';
    }

    reindexWeights();
    jsonSchemaString.value = JSON.stringify(formConfig.schema, null, 2);
};

watch(
    () => props.show,
    (isOpen) => {
        if (isOpen) {
            initializeModal();
        }
    },
    { immediate: true }
);

const onEventSelectionChange = () => {
    const existing = props.feedbackForms.find((f) => f.event_id === Number(formConfig.event_id));
    if (existing && existing.schema?.fields) {
        formConfig.schema = JSON.parse(JSON.stringify(existing.schema));
    } else {
        formConfig.schema = JSON.parse(JSON.stringify(defaultSchemaTemplate));
        if (currentSelectedEvent.value?.name) {
            formConfig.schema.title = `${currentSelectedEvent.value.name} Evaluation`;
        }
    }
    if (!formConfig.schema.pagination) {
        formConfig.schema.pagination = {
            enabled: false,
            progress_bar: true,
            show_section_numbers: true,
        };
    }
    reindexWeights();
};

const resetToDefaultSchema = () => {
    if (confirm('Reset questionnaire to the standard default evaluation preset? Any unsaved edits will be lost.')) {
        formConfig.schema = JSON.parse(JSON.stringify(defaultSchemaTemplate));
        if (currentSelectedEvent.value?.name) {
            formConfig.schema.title = `${currentSelectedEvent.value.name} Evaluation`;
        }
        activeFieldIdx.value = 0;
        reindexWeights();
        jsonParseError.value = '';
    }
};

// Question Card Manipulations
const activateCard = (idx) => {
    activeFieldIdx.value = idx;
    closeAllFieldMenus();
};

const addQuestionAt = (afterIdx = -1) => {
    const insertIdx = afterIdx >= 0 ? afterIdx + 1 : (formConfig.schema.fields?.length || 0);
    const nextWeight = insertIdx + 1;

    const newField = {
        id: `question_${Date.now().toString().slice(-4)}`,
        particular: 'Untitled Question',
        type: 'radio',
        weight: nextWeight,
        required: false,
        allow_other: false,
        allow_custom_value: false,
        placeholder: '',
        description: '',
        option_source: 'static',
        function_ids: [],
        options: [
            { value: 'option_1', label: 'Option 1', is_other: false, goto_section: null },
        ],
        manualId: false,
        _show_description: false,
        _show_id: false,
        _show_branching: false,
    };

    formConfig.schema.fields.splice(insertIdx, 0, newField);
    reindexWeights();
    activeFieldIdx.value = insertIdx;

    nextTick(() => {
        const el = document.getElementById(`schema_field_${insertIdx}`);
        el?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    });
};

const addSectionAt = (afterIdx = -1) => {
    const insertIdx = afterIdx >= 0 ? afterIdx + 1 : (formConfig.schema.fields?.length || 0);
    const nextWeight = insertIdx + 1;
    const count = sectionList.value.length + 1;

    const newSection = {
        id: `section_${Date.now().toString().slice(-4)}`,
        particular: `Section ${count}`,
        type: 'section',
        weight: nextWeight,
        description: '',
        section_flow: 'next',
        conditions: [],
        manualId: false,
    };

    // Auto-enable pagination when section is added
    if (!formConfig.schema.pagination) {
        formConfig.schema.pagination = { enabled: true, progress_bar: true, show_section_numbers: true };
    } else {
        formConfig.schema.pagination.enabled = true;
    }

    formConfig.schema.fields.splice(insertIdx, 0, newSection);
    reindexWeights();
    activeFieldIdx.value = insertIdx;

    nextTick(() => {
        const el = document.getElementById(`schema_field_${insertIdx}`);
        el?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    });
};

const duplicateField = (idx) => {
    if (!formConfig.schema?.fields?.[idx]) return;
    const original = formConfig.schema.fields[idx];
    const copy = JSON.parse(JSON.stringify(original));
    copy.id = `${original.id}_copy_${Date.now().toString().slice(-4)}`;
    copy.particular = `${original.particular} (Copy)`;
    copy.manualId = true;

    formConfig.schema.fields.splice(idx + 1, 0, copy);
    reindexWeights();
    activeFieldIdx.value = idx + 1;
};

const removeField = (idx) => {
    if (!formConfig.schema?.fields?.[idx]) return;
    formConfig.schema.fields.splice(idx, 1);

    if (formConfig.schema.fields.length === 0) {
        addQuestionAt(-1);
    } else if (activeFieldIdx.value >= formConfig.schema.fields.length) {
        activeFieldIdx.value = formConfig.schema.fields.length - 1;
    }
    reindexWeights();
};

const moveField = (idx, direction) => {
    const targetIdx = idx + direction;
    if (targetIdx < 0 || targetIdx >= formConfig.schema.fields.length) return;

    const current = formConfig.schema.fields[idx];
    formConfig.schema.fields.splice(idx, 1);
    formConfig.schema.fields.splice(targetIdx, 0, current);
    reindexWeights();
    activeFieldIdx.value = targetIdx;
};

// Options inline editing
const addOptionToField = (field, currentOptIdx = -1) => {
    if (!field.options) field.options = [];
    const optNum = field.options.length + 1;
    const newOpt = {
        value: `option_${optNum}`,
        label: `Option ${optNum}`,
        is_other: false,
        goto_section: null,
    };

    if (currentOptIdx >= 0 && currentOptIdx < field.options.length) {
        field.options.splice(currentOptIdx + 1, 0, newOpt);
    } else {
        field.options.push(newOpt);
    }
    reindexWeights();

    nextTick(() => {
        const focusIdx = currentOptIdx >= 0 ? currentOptIdx + 1 : field.options.length - 1;
        const input = document.getElementById(`opt_input_${field.id}_${focusIdx}`);
        input?.focus();
    });
};

const addOtherOptionToField = (field) => {
    if (!field.options) field.options = [];
    field.allow_other = true;
    const hasOther = field.options.some((o) => o.is_other || o.value === 'other');
    if (!hasOther) {
        field.options.push({
            value: 'other',
            label: 'Other...',
            is_other: true,
            goto_section: null,
        });
    }
    reindexWeights();
};

const removeOptionFromField = (field, optIdx) => {
    if (!field.options) return;
    const removed = field.options.splice(optIdx, 1)[0];
    if (removed?.is_other) {
        field.allow_other = false;
    }
    reindexWeights();
};

const onFieldTypeChange = (field) => {
    if (['radio', 'select', 'checkbox'].includes(field.type)) {
        if (!field.option_source) field.option_source = 'static';
        if (field.option_source === 'static' && (!field.options || field.options.length === 0)) {
            field.options = [
                { value: 'option_1', label: 'Option 1', is_other: false, goto_section: null },
                { value: 'option_2', label: 'Option 2', is_other: false, goto_section: null },
            ];
        }
    }
    reindexWeights();
};

// Section Condition Management
const addSectionCondition = (sectionField) => {
    if (!sectionField.conditions) sectionField.conditions = [];
    const available = getAvailableConditionQuestions(sectionField.weight - 1);
    const triggerField = available[0] || null;

    sectionField.conditions.push({
        field_id: triggerField ? triggerField.id : '',
        operator: 'equals',
        value: triggerField?.options?.[0]?.value || '',
    });
    reindexWeights();
};

const removeSectionCondition = (sectionField, condIdx) => {
    if (!sectionField.conditions) return;
    sectionField.conditions.splice(condIdx, 1);
    reindexWeights();
};

const getTriggerFieldOptions = (fieldId) => {
    const found = formConfig.schema.fields.find((f) => f.id === fieldId);
    return found?.options || [];
};

// JSON Mode Sync
const syncJsonToInteractive = () => {
    try {
        const parsed = JSON.parse(jsonSchemaString.value);
        if (typeof parsed !== 'object' || parsed === null) {
            jsonParseError.value = 'Schema must be a valid JSON object.';
            return false;
        }
        if (!Array.isArray(parsed.fields)) {
            jsonParseError.value = 'Schema must contain a "fields" array.';
            return false;
        }
        formConfig.schema = parsed;
        if (!formConfig.schema.pagination) {
            formConfig.schema.pagination = { enabled: false, progress_bar: true, show_section_numbers: true };
        }
        reindexWeights();
        jsonParseError.value = '';
        return true;
    } catch (err) {
        jsonParseError.value = `Invalid JSON syntax: ${err.message}`;
        return false;
    }
};

const switchTab = (tab) => {
    if (tab === 'json') {
        jsonSchemaString.value = JSON.stringify(formConfig.schema, null, 2);
        jsonParseError.value = '';
    } else if (activeTab.value === 'json') {
        if (!syncJsonToInteractive()) return;
    }
    activeTab.value = tab;
};

// Error highlighting and jumping
const getSchemaFieldError = (idx) => {
    const errors = formConfig.errors;
    for (const key of Object.keys(errors)) {
        if (key.startsWith(`schema.fields.${idx}.`) || key === `schema.fields.${idx}`) {
            return errors[key];
        }
    }
    return null;
};

const schemaErrorsList = computed(() => {
    const list = [];
    const errors = formConfig.errors;
    Object.keys(errors).forEach((key) => {
        const match = key.match(/^schema\.fields\.(\d+)/);
        if (match) {
            const idx = parseInt(match[1], 10);
            const field = formConfig.schema.fields?.[idx];
            if (!list.some((item) => item.idx === idx)) {
                list.push({
                    idx,
                    particular: field?.particular || `Question #${idx + 1}`,
                    message: errors[key],
                });
            }
        }
    });
    return list;
});

const goToSchemaErrorField = (idx) => {
    switchTab('questions');
    activeFieldIdx.value = idx;
    highlightedSchemaFieldIdx.value = idx;

    if (schemaHighlightTimer) clearTimeout(schemaHighlightTimer);
    schemaHighlightTimer = setTimeout(() => {
        highlightedSchemaFieldIdx.value = null;
    }, 4000);

    setTimeout(() => {
        const el = document.getElementById(`schema_field_${idx}`);
        if (el) {
            el.scrollIntoView({ behavior: 'smooth', block: 'center' });
            const input = el.querySelector('input:not([type=hidden]), select, textarea');
            input?.focus();
        }
    }, 150);
};

// Document Import State & Methods
const importFile = ref(null);
const fileInput = ref(null);
const isDragging = ref(false);
const isAnalyzing = ref(false);
const analyzeStepText = ref('');
const importError = ref('');
const importResult = ref(null);
const showMergeModal = ref(false);

const selectedCandidatesCount = computed(() => {
    if (!importResult.value?.candidates) return 0;
    return importResult.value.candidates.filter((c) => c.include).length;
});

const formatFileSize = (bytes) => {
    if (!bytes || bytes === 0) return '0 B';
    const k = 1024;
    const sizes = ['B', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
};

const onDropFile = (e) => {
    isDragging.value = false;
    if (e.dataTransfer?.files?.length > 0) {
        importFile.value = e.dataTransfer.files[0];
        importError.value = '';
        importResult.value = null;
    }
};

const onFileSelected = (e) => {
    if (e.target?.files?.length > 0) {
        importFile.value = e.target.files[0];
        importError.value = '';
        importResult.value = null;
    }
};

const startDocumentAnalysis = async () => {
    if (!importFile.value) return;

    isAnalyzing.value = true;
    importError.value = '';
    analyzeStepText.value = 'Uploading document...';

    const formData = new FormData();
    formData.append('file', importFile.value);
    if (formConfig.event_id) {
        formData.append('event_id', formConfig.event_id);
    }

    const t1 = setTimeout(() => {
        if (isAnalyzing.value) analyzeStepText.value = 'Reading document structure & tables...';
    }, 1000);
    const t2 = setTimeout(() => {
        if (isAnalyzing.value) analyzeStepText.value = 'Detecting questions, rating scales, and answer choices...';
    }, 2500);

    try {
        const response = await axios.post(route('admin.feedback.forms.import'), formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });

        if (response.data?.status === 'success') {
            importResult.value = response.data;
        } else {
            importError.value = response.data?.message || 'Failed to extract questions from document.';
        }
    } catch (err) {
        importError.value = err.response?.data?.message || 'An error occurred while analyzing document.';
    } finally {
        clearTimeout(t1);
        clearTimeout(t2);
        isAnalyzing.value = false;
    }
};

const applyImportedFields = (strategy) => {
    if (!importResult.value?.candidates) return;
    const selected = importResult.value.candidates.filter((c) => c.include);
    if (selected.length === 0) return;

    const formatted = selected.map((cand) => ({
        id: cand.id,
        particular: cand.particular,
        type: cand.type,
        weight: 1,
        required: Boolean(cand.required),
        placeholder: cand.placeholder || '',
        description: cand.description || '',
        allow_other: Boolean(cand.allow_other),
        allow_custom_value: false,
        option_source: 'static',
        options: cand.options || [],
        manualId: true,
    }));

    if (strategy === 'replace') {
        formConfig.schema.fields = formatted;
    } else {
        formatted.forEach((f) => formConfig.schema.fields.push(f));
    }

    reindexWeights();
    showMergeModal.value = false;
    activeTab.value = 'questions';
    importFile.value = null;
    importResult.value = null;
};

// Save handler
const saveSchema = () => {
    if (activeTab.value === 'json') {
        if (!syncJsonToInteractive()) return;
    }

    formConfig.post(route('admin.feedback.forms.save'), {
        preserveScroll: true,
        onSuccess: () => {
            emit('saved');
            emit('close');
        },
        onError: () => {
            if (schemaErrorsList.value.length > 0) {
                goToSchemaErrorField(schemaErrorsList.value[0].idx);
            }
        },
    });
};

const openPreview = () => {
    emit('preview', currentSelectedEvent.value, { schema: formConfig.schema });
};

// Global click listener to close dropdowns
const handleWindowClick = () => {
    closeAllFieldMenus();
};

onMounted(() => {
    window.addEventListener('click', handleWindowClick);
});

onUnmounted(() => {
    window.removeEventListener('click', handleWindowClick);
});
</script>

<template>
    <div
        v-if="show"
        class="fixed inset-0 z-50 bg-cream-200 flex flex-col w-screen h-screen overflow-hidden font-sans text-gray-900 select-none animate-in fade-in duration-150"
    >
        <!-- Top Google Forms-Style Navigation Header -->
        <header class="bg-[#fffef9] border-b border-cream-400 px-4 sm:px-6 py-2.5 flex items-center justify-between shrink-0 shadow-xs z-30">
            <!-- Left: Document Branding & Title -->
            <div class="flex items-center space-x-3 truncate">
                <button
                    type="button"
                    @click="$emit('close')"
                    class="p-1.5 rounded-xl hover:bg-cream-200 text-gray-600 hover:text-gray-900 transition cursor-pointer"
                    title="Close editor"
                >
                    <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>

                <div class="size-8 rounded-lg bg-forest-900 text-lime-200 flex items-center justify-center font-black text-sm shrink-0 shadow-2xs">
                    📋
                </div>

                <div class="truncate">
                    <div class="flex items-center space-x-2">
                        <input
                            v-model="formConfig.schema.title"
                            type="text"
                            placeholder="Untitled form"
                            class="text-sm sm:text-base font-bold text-gray-900 hover:bg-cream-100/70 focus:bg-white rounded-lg px-2 py-0.5 border border-transparent focus:border-forest-600 focus:outline-none transition truncate max-w-xs sm:max-w-md"
                        />
                        <span v-if="currentSelectedEvent" class="text-[11px] font-semibold text-gray-500 bg-cream-200 px-2 py-0.5 rounded-md truncate hidden md:inline-block">
                            {{ currentSelectedEvent.name }}
                        </span>
                    </div>
                </div>
            </div>

            <!-- Center Tabs: Questions | Logic | JSON | Import -->
            <nav class="hidden md:flex items-center space-x-1 bg-cream-200/90 p-1 rounded-xl border border-cream-400/80">
                <button
                    type="button"
                    @click="switchTab('questions')"
                    class="px-3.5 py-1 text-xs font-bold rounded-lg transition cursor-pointer flex items-center space-x-1.5"
                    :class="activeTab === 'questions' ? 'bg-forest-900 text-white shadow-xs' : 'text-gray-700 hover:text-gray-900 hover:bg-cream-300'"
                >
                    <span>Questions</span>
                    <span class="px-1.5 py-0.2 rounded-full text-[10px]" :class="activeTab === 'questions' ? 'bg-forest-800 text-lime-200 font-bold' : 'bg-cream-300 text-gray-700'">
                        {{ formConfig.schema.fields.length }}
                    </span>
                </button>

                <button
                    type="button"
                    @click="switchTab('logic')"
                    class="px-3 py-1 text-xs font-bold rounded-lg transition cursor-pointer flex items-center space-x-1.5"
                    :class="activeTab === 'logic' ? 'bg-forest-900 text-white shadow-xs' : 'text-gray-700 hover:text-gray-900 hover:bg-cream-300'"
                >
                    <span>Pagination & Logic</span>
                    <span
                        v-if="formConfig.schema.pagination?.enabled"
                        class="px-1.5 py-0.2 rounded-full text-[10px] bg-lime-300 text-forest-950 font-black"
                    >
                        {{ sectionList.length }}P
                    </span>
                </button>

                <button
                    type="button"
                    @click="switchTab('json')"
                    class="px-3 py-1 text-xs font-bold rounded-lg transition cursor-pointer flex items-center space-x-1"
                    :class="activeTab === 'json' ? 'bg-forest-900 text-white shadow-xs' : 'text-gray-700 hover:text-gray-900 hover:bg-cream-300'"
                >
                    <span>JSON Editor</span>
                </button>

                <button
                    type="button"
                    @click="switchTab('import')"
                    class="px-3 py-1 text-xs font-bold rounded-lg transition cursor-pointer flex items-center space-x-1"
                    :class="activeTab === 'import' ? 'bg-forest-900 text-white shadow-xs' : 'text-gray-700 hover:text-gray-900 hover:bg-cream-300'"
                >
                    <span>Import Form</span>
                </button>
            </nav>

            <!-- Right Actions: Preview | Reset | Save Button -->
            <div class="flex items-center space-x-2 shrink-0">
                <button
                    type="button"
                    @click="openPreview"
                    class="px-3 py-1.5 bg-cream-100 hover:bg-cream-300 text-forest-900 border border-cream-400 text-xs font-bold rounded-xl shadow-2xs transition cursor-pointer flex items-center space-x-1.5"
                    title="Preview this questionnaire with sample answers"
                >
                    <svg class="size-4 text-forest-900" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                    </svg>
                    <span class="hidden sm:inline">Preview</span>
                </button>

                <button
                    type="button"
                    @click="resetToDefaultSchema"
                    class="px-2.5 py-1.5 bg-cream-100 hover:bg-cream-300 text-gray-700 border border-cream-400 text-xs font-bold rounded-xl transition cursor-pointer"
                    title="Reset to default evaluation preset"
                >
                    ↺
                </button>

                <button
                    type="button"
                    @click="saveSchema"
                    :disabled="formConfig.processing"
                    class="px-4.5 py-1.5 bg-forest-900 hover:bg-forest-950 text-white text-xs font-bold rounded-xl shadow-xs transition disabled:opacity-50 cursor-pointer flex items-center space-x-1.5"
                >
                    <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                    </svg>
                    <span>{{ formConfig.processing ? 'Saving...' : 'Save Schema' }}</span>
                </button>
            </div>
        </header>

        <!-- Main Body Workspace -->
        <main class="flex-1 overflow-y-auto bg-[#f8f5ee] p-3 sm:p-6 lg:p-8">
            <div class="max-w-4xl mx-auto space-y-4 relative pb-24">
                <!-- Validation Error Alert Banner -->
                <div
                    v-if="schemaErrorsList.length > 0"
                    class="p-4 bg-red-50/95 border-2 border-red-300 rounded-2xl shadow-xs space-y-2.5 transition-all"
                >
                    <div class="flex items-center justify-between flex-wrap gap-2">
                        <div class="flex items-center space-x-2 text-red-950 font-bold text-xs sm:text-sm">
                            <span class="size-6 rounded-lg bg-red-200 text-red-900 flex items-center justify-center text-xs font-black shrink-0">!</span>
                            <span>Validation Error: {{ schemaErrorsList.length }} field(s) require your attention.</span>
                        </div>
                        <button
                            type="button"
                            @click="goToSchemaErrorField(schemaErrorsList[0].idx)"
                            class="inline-flex items-center space-x-1 px-3 py-1 bg-red-600 hover:bg-red-700 text-white rounded-lg text-xs font-bold shadow-2xs transition cursor-pointer"
                        >
                            <span>Jump to First Error →</span>
                        </button>
                    </div>
                    <div class="pt-2 border-t border-red-200 flex flex-wrap items-center gap-1.5">
                        <span class="text-[11px] font-bold text-red-800 me-1">Jump to question:</span>
                        <button
                            v-for="err in schemaErrorsList"
                            :key="err.idx"
                            type="button"
                            @click="goToSchemaErrorField(err.idx)"
                            class="inline-flex items-center space-x-1 px-2.5 py-1 bg-white hover:bg-red-100/90 text-red-900 border border-red-300 rounded-lg text-xs font-semibold shadow-2xs transition cursor-pointer"
                        >
                            <span class="size-4 rounded-full bg-red-100 text-red-800 text-[10px] font-black flex items-center justify-center">
                                {{ err.idx + 1 }}
                            </span>
                            <span class="truncate max-w-[150px]">{{ err.particular }}</span>
                        </button>
                    </div>
                </div>

                <!-- TAB 1: VISUAL GOOGLE FORMS QUESTIONS BUILDER -->
                <div v-show="activeTab === 'questions'" class="space-y-4">
                    <!-- Form Header Card (Google Forms Top Card with Forest Accent Bar) -->
                    <div class="bg-white rounded-2xl shadow-xs border border-cream-400/80 overflow-hidden transition">
                        <!-- Top Accent Bar strictly retaining Forest-900 -->
                        <div class="h-2.5 bg-forest-900 w-full"></div>

                        <div class="p-6 space-y-4">
                            <!-- Event Selector & Status Row -->
                            <div class="flex items-center justify-between flex-wrap gap-2 pb-3 border-b border-cream-300/60">
                                <div class="flex items-center space-x-2">
                                    <label class="text-[11px] font-black text-forest-900 uppercase tracking-wider">
                                        Target Feedback Event:
                                    </label>
                                    <select
                                        v-model="formConfig.event_id"
                                        @change="onEventSelectionChange"
                                        class="px-3 py-1 bg-[#fffef9] border border-cream-500 focus:border-forest-600 rounded-xl text-gray-900 text-xs font-bold"
                                    >
                                        <option value="" disabled>-- Select Event --</option>
                                        <option v-for="ev in events" :key="ev.id" :value="ev.id">
                                            {{ ev.name }} (ID: {{ ev.id }}) {{ feedbackForms.some(f => f.event_id === ev.id) ? '— [Configured]' : '— [New]' }}
                                        </option>
                                    </select>
                                </div>

                                <span
                                    v-if="currentSelectedEvent"
                                    class="text-xs font-semibold px-2.5 py-0.5 rounded-full"
                                    :class="feedbackForms.some(f => f.event_id === currentSelectedEvent.id) ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800'"
                                >
                                    {{ feedbackForms.some(f => f.event_id === currentSelectedEvent.id) ? '✓ Active Schema' : '⚙️ Not Yet Configured' }}
                                </span>
                            </div>

                            <!-- Large Editable Form Title -->
                            <div>
                                <input
                                    v-model="formConfig.schema.title"
                                    type="text"
                                    placeholder="Form title"
                                    class="w-full text-2xl sm:text-3xl font-black text-gray-900 border-b border-cream-300 focus:border-forest-900 focus:outline-none pb-2 placeholder-gray-400 transition"
                                />
                            </div>

                            <!-- Editable Form Description -->
                            <div>
                                <textarea
                                    v-model="formConfig.schema.description"
                                    rows="2"
                                    placeholder="Form description / instructions for respondents..."
                                    class="w-full text-xs sm:text-sm text-gray-600 border-b border-transparent hover:border-cream-300 focus:border-forest-900 focus:outline-none pb-1 placeholder-gray-400 transition resize-none"
                                ></textarea>
                            </div>

                            <!-- Pagination Mode Toggle Box -->
                            <div class="mt-4 p-3.5 bg-cream-100/70 border border-cream-400/80 rounded-xl flex items-center justify-between flex-wrap gap-3">
                                <div>
                                    <div class="flex items-center space-x-2">
                                        <span class="text-sm">📖</span>
                                        <label class="text-xs font-black text-forest-900 uppercase tracking-wider">
                                            Paginate Form Into Multiple Pages
                                        </label>
                                        <span
                                            v-if="formConfig.schema.pagination?.enabled"
                                            class="px-2 py-0.5 bg-lime-200 text-forest-950 text-[10px] font-black rounded-md uppercase"
                                        >
                                            {{ sectionList.length }} Page(s) Active
                                        </span>
                                    </div>
                                    <p class="text-[11px] text-gray-500 mt-0.5">
                                        Divide questionnaire into sequential pages using <strong>Sections</strong>, with customizable next/previous flow and condition-based branching.
                                    </p>
                                </div>

                                <div class="flex items-center space-x-4">
                                    <label class="relative inline-flex items-center cursor-pointer">
                                        <input
                                            type="checkbox"
                                            v-model="formConfig.schema.pagination.enabled"
                                            class="sr-only peer"
                                        />
                                        <div class="w-11 h-6 bg-cream-400 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-forest-900"></div>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Questions & Sections Cards Canvas -->
                    <div class="space-y-4">
                        <div
                            v-for="(field, idx) in formConfig.schema.fields"
                            :key="field.id || idx"
                            :id="`schema_field_${idx}`"
                            class="transition-all duration-200"
                        >
                            <!-- SECTION CARD (PAGE BREAK) -->
                            <div
                                v-if="field.type === 'section'"
                                @click="activateCard(idx)"
                                class="rounded-2xl border transition-all duration-200 bg-white"
                                :class="[
                                    activeFieldIdx === idx
                                        ? 'border-y border-r border-cream-400 border-l-[6px] border-l-indigo-700 shadow-md ring-1 ring-black/5'
                                        : 'border-cream-300/80 hover:border-cream-400 shadow-xs'
                                ]"
                            >
                                <div class="p-5 space-y-4">
                                    <!-- Section Card Top Header -->
                                    <div class="flex items-center justify-between gap-3">
                                        <div class="flex items-center space-x-2">
                                            <span class="px-3 py-1 rounded-full bg-indigo-900 text-white font-black text-xs uppercase tracking-wider flex items-center space-x-1.5 shadow-2xs">
                                                <span>Section {{ getSectionIndex(field) }} of {{ sectionList.length }}</span>
                                            </span>
                                            <span v-if="field.conditions?.length" class="px-2 py-0.5 rounded-md bg-amber-100 text-amber-900 text-[10px] font-bold border border-amber-200">
                                                ⚡ Conditional
                                            </span>
                                        </div>

                                        <!-- Section Actions -->
                                        <div class="flex items-center space-x-1 text-gray-500">
                                            <button
                                                type="button"
                                                @click.stop="moveField(idx, -1)"
                                                :disabled="idx === 0"
                                                class="p-1 rounded hover:bg-cream-200 disabled:opacity-30 text-xs cursor-pointer"
                                                title="Move Up"
                                            >▲</button>
                                            <button
                                                type="button"
                                                @click.stop="moveField(idx, 1)"
                                                :disabled="idx === formConfig.schema.fields.length - 1"
                                                class="p-1 rounded hover:bg-cream-200 disabled:opacity-30 text-xs cursor-pointer"
                                                title="Move Down"
                                            >▼</button>
                                            <button
                                                type="button"
                                                @click.stop="duplicateField(idx)"
                                                class="p-1 rounded hover:bg-cream-200 text-xs cursor-pointer"
                                                title="Duplicate Section"
                                            >📋</button>
                                            <button
                                                type="button"
                                                @click.stop="removeField(idx)"
                                                class="p-1 rounded hover:bg-red-50 text-red-500 hover:text-red-700 text-xs cursor-pointer"
                                                title="Delete Section"
                                            >
                                                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" />
                                                </svg>
                                            </button>
                                        </div>
                                    </div>

                                    <!-- Section Title & Description -->
                                    <div class="space-y-2">
                                        <input
                                            v-model="field.particular"
                                            @input="onParticularInput(field)"
                                            type="text"
                                            placeholder="Section Title"
                                            class="w-full text-lg font-bold text-gray-900 border-b border-cream-300 focus:border-indigo-600 focus:outline-none pb-1 placeholder-gray-400"
                                        />
                                        <input
                                            v-model="field.description"
                                            type="text"
                                            placeholder="Description (optional)"
                                            class="w-full text-xs text-gray-600 border-b border-transparent hover:border-cream-300 focus:border-indigo-600 focus:outline-none pb-1 placeholder-gray-400"
                                        />
                                    </div>

                                    <!-- Section Navigation Rules & Conditions Box -->
                                    <div class="pt-3 border-t border-cream-200 space-y-3">
                                        <!-- After Section Navigation Flow -->
                                        <div class="flex items-center justify-between flex-wrap gap-2 text-xs">
                                            <div class="flex items-center space-x-2">
                                                <span class="font-bold text-gray-700">After section {{ getSectionIndex(field) }}:</span>
                                                <select
                                                    v-model="field.section_flow"
                                                    class="px-2.5 py-1 bg-[#fffef9] border border-cream-400 focus:border-forest-600 rounded-lg text-xs font-semibold text-gray-900"
                                                >
                                                    <option v-for="dest in availableDestinations" :key="dest.value" :value="dest.value">
                                                        {{ dest.label }}
                                                    </option>
                                                </select>
                                            </div>

                                            <button
                                                type="button"
                                                @click.stop="addSectionCondition(field)"
                                                class="px-2.5 py-1 bg-cream-100 hover:bg-cream-300 text-forest-900 border border-cream-400 rounded-lg text-[11px] font-bold cursor-pointer"
                                            >
                                                + Add Display Condition
                                            </button>
                                        </div>

                                        <!-- Conditions List if any -->
                                        <div v-if="field.conditions && field.conditions.length > 0" class="p-3 bg-amber-50/70 border border-amber-200/80 rounded-xl space-y-2">
                                            <p class="text-[11px] font-bold text-amber-900 uppercase tracking-wider">
                                                Conditional Display Rule: Show this section only if:
                                            </p>
                                            <div
                                                v-for="(cond, cIdx) in field.conditions"
                                                :key="cIdx"
                                                class="flex items-center space-x-2 flex-wrap gap-1 text-xs"
                                            >
                                                <select
                                                    v-model="cond.field_id"
                                                    class="px-2 py-1 bg-white border border-amber-300 rounded-lg text-xs"
                                                >
                                                    <option value="" disabled>-- Select Question --</option>
                                                    <option
                                                        v-for="q in getAvailableConditionQuestions(idx)"
                                                        :key="q.id"
                                                        :value="q.id"
                                                    >
                                                        {{ q.particular }}
                                                    </option>
                                                </select>

                                                <select
                                                    v-model="cond.operator"
                                                    class="px-2 py-1 bg-white border border-amber-300 rounded-lg text-xs font-medium"
                                                >
                                                    <option value="equals">is equal to</option>
                                                    <option value="not_equals">is not equal to</option>
                                                    <option value="is_answered">is answered</option>
                                                    <option value="is_not_answered">is not answered</option>
                                                </select>

                                                <template v-if="['equals', 'not_equals'].includes(cond.operator)">
                                                    <select
                                                        v-if="getTriggerFieldOptions(cond.field_id).length > 0"
                                                        v-model="cond.value"
                                                        class="px-2 py-1 bg-white border border-amber-300 rounded-lg text-xs"
                                                    >
                                                        <option
                                                            v-for="opt in getTriggerFieldOptions(cond.field_id)"
                                                            :key="opt.value"
                                                            :value="opt.value"
                                                        >
                                                            {{ opt.label }}
                                                        </option>
                                                    </select>
                                                    <input
                                                        v-else
                                                        v-model="cond.value"
                                                        type="text"
                                                        placeholder="Target Value"
                                                        class="px-2 py-1 bg-white border border-amber-300 rounded-lg text-xs"
                                                    />
                                                </template>

                                                <button
                                                    type="button"
                                                    @click="removeSectionCondition(field, cIdx)"
                                                    class="text-red-500 hover:text-red-700 text-xs px-1 cursor-pointer font-bold"
                                                    title="Remove condition"
                                                >✕</button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- REGULAR QUESTION CARD -->
                            <div
                                v-else
                                @click="activateCard(idx)"
                                class="rounded-2xl transition-all duration-200"
                                :class="[
                                    activeFieldIdx === idx
                                        ? 'bg-white border-y border-r border-cream-400 border-l-[6px] border-l-forest-900 shadow-md ring-1 ring-black/5'
                                        : 'bg-white/95 hover:bg-white border border-cream-300/80 hover:border-cream-400 shadow-xs cursor-pointer'
                                ]"
                            >
                                <!-- ACTIVE EDITING STATE -->
                                <div v-if="activeFieldIdx === idx" class="p-5 space-y-4">
                                    <!-- Card Drag Handle Icon at Top Center -->
                                    <div class="flex justify-center -mt-2 -mb-2">
                                        <span class="text-gray-300 hover:text-gray-500 cursor-grab text-xs tracking-widest font-black" title="Drag to reorder">
                                            :::
                                        </span>
                                    </div>

                                    <!-- Top Row: Question Title & Question Type Selector -->
                                    <div class="flex items-start justify-between gap-3 flex-wrap sm:flex-nowrap">
                                        <div class="flex-1">
                                            <input
                                                v-model="field.particular"
                                                @input="onParticularInput(field)"
                                                type="text"
                                                placeholder="Question"
                                                class="w-full text-base font-semibold text-gray-900 bg-cream-50/60 border-b-2 border-cream-400 focus:border-forest-900 focus:bg-white rounded-t-lg px-3 py-2 focus:outline-none transition"
                                            />
                                        </div>

                                        <div class="w-full sm:w-56 shrink-0">
                                            <select
                                                v-model="field.type"
                                                @change="onFieldTypeChange(field)"
                                                class="w-full px-3 py-2 bg-white border border-cream-400 focus:border-forest-600 rounded-xl text-xs font-bold text-gray-900 shadow-2xs cursor-pointer"
                                            >
                                                <option value="radio">🔘 Multiple choice</option>
                                                <option value="checkbox">☑️ Checkboxes</option>
                                                <option value="select">🔽 Dropdown</option>
                                                <option value="text">📝 Short answer</option>
                                                <option value="textarea">📄 Paragraph</option>
                                                <option value="number">🔢 Linear scale / Number</option>
                                                <option value="date">📅 Date</option>
                                                <option value="section">〓 Section header</option>
                                            </select>
                                        </div>
                                    </div>

                                    <!-- Question Description / Subtitle if toggled -->
                                    <div v-if="field._show_description || field.description">
                                        <input
                                            v-model="field.description"
                                            type="text"
                                            placeholder="Description / subtitle"
                                            class="w-full text-xs text-gray-600 border-b border-cream-300 focus:border-forest-900 focus:outline-none pb-1"
                                        />
                                    </div>

                                    <!-- Custom Field ID if toggled -->
                                    <div v-if="field._show_id || field.manualId" class="flex items-center space-x-2 text-xs">
                                        <label class="text-[10px] font-bold text-gray-500 uppercase">Field ID:</label>
                                        <input
                                            v-model="field.id"
                                            @input="field.manualId = true"
                                            type="text"
                                            placeholder="field_id"
                                            class="px-2 py-0.5 bg-cream-100 border border-cream-400 rounded-lg font-mono text-xs w-48"
                                        />
                                    </div>

                                    <!-- Dynamic Option Source for Select Dropdown -->
                                    <div v-if="field.type === 'select'" class="p-3 bg-cream-100 rounded-xl border border-cream-400/60 space-y-2">
                                        <div class="flex items-center justify-between text-xs">
                                            <span class="font-bold text-forest-900 uppercase">Option Source:</span>
                                            <div class="flex items-center space-x-3">
                                                <label class="flex items-center space-x-1.5 cursor-pointer">
                                                    <input
                                                        type="radio"
                                                        :name="`opt_source_${idx}`"
                                                        value="static"
                                                        v-model="field.option_source"
                                                        class="text-forest-900 focus:ring-forest-500 size-3.5"
                                                    />
                                                    <span class="font-medium text-gray-800">Custom Static Options</span>
                                                </label>
                                                <label class="flex items-center space-x-1.5 cursor-pointer">
                                                    <input
                                                        type="radio"
                                                        :name="`opt_source_${idx}`"
                                                        value="fb_functions"
                                                        v-model="field.option_source"
                                                        class="text-forest-900 focus:ring-forest-500 size-3.5"
                                                    />
                                                    <span class="font-bold text-forest-900">Event Functions</span>
                                                </label>
                                            </div>
                                        </div>

                                        <div v-if="field.option_source === 'fb_functions'" class="mt-2 p-2.5 bg-white rounded-lg border border-forest-400/30 text-xs">
                                            <p class="text-[11px] text-gray-500 mb-1.5">
                                                {{ (!field.function_ids || field.function_ids.length === 0) ? 'All functions assigned to this event will appear as choices:' : 'Only chosen functions will appear:' }}
                                            </p>
                                            <div class="flex flex-wrap gap-1 max-h-28 overflow-y-auto">
                                                <label
                                                    v-for="fn in currentEventFunctions"
                                                    :key="fn.id"
                                                    class="inline-flex items-center space-x-1 px-2 py-0.5 rounded-md border text-[11px] cursor-pointer"
                                                    :class="(!field.function_ids || field.function_ids.length === 0 || field.function_ids.includes(fn.id)) ? 'bg-forest-900 text-white border-forest-950 font-semibold' : 'bg-cream-100 text-gray-600 border-cream-300'"
                                                >
                                                    <input
                                                        type="checkbox"
                                                        :value="fn.id"
                                                        v-model="field.function_ids"
                                                        class="size-3 rounded border-cream-400 text-forest-900 focus:ring-forest-500"
                                                    />
                                                    <span>{{ fn.function }}</span>
                                                </label>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Choice Options List (Radio, Checkbox, Select static) -->
                                    <div
                                        v-if="['radio', 'checkbox'].includes(field.type) || (field.type === 'select' && field.option_source !== 'fb_functions')"
                                        class="space-y-2 pt-1"
                                    >
                                        <div
                                            v-for="(opt, optIdx) in field.options || []"
                                            :key="optIdx"
                                            class="flex items-center space-x-2 group"
                                        >
                                            <!-- Option Marker -->
                                            <span class="text-gray-400 text-sm shrink-0">
                                                <span v-if="field.type === 'radio'">⚪</span>
                                                <span v-else-if="field.type === 'checkbox'">⬜</span>
                                                <span v-else class="text-xs font-mono font-bold">{{ optIdx + 1 }}.</span>
                                            </span>

                                            <!-- Option Input -->
                                            <input
                                                :id="`opt_input_${field.id}_${optIdx}`"
                                                v-model="opt.label"
                                                @keydown.enter.prevent="addOptionToField(field, optIdx)"
                                                type="text"
                                                :placeholder="`Option ${optIdx + 1}`"
                                                class="flex-1 px-2.5 py-1 text-xs text-gray-900 border-b border-cream-300 hover:border-cream-400 focus:border-forest-900 focus:outline-none transition"
                                            />

                                            <!-- Conditional Branching Dropdown (Go to section based on answer) -->
                                            <div v-if="field._show_branching || opt.goto_section" class="shrink-0">
                                                <select
                                                    v-model="opt.goto_section"
                                                    class="px-2 py-0.5 bg-cream-100 border border-cream-400 focus:border-forest-600 rounded-lg text-[11px] font-semibold text-gray-800"
                                                >
                                                    <option v-for="dest in availableDestinations" :key="dest.value" :value="dest.value">
                                                        {{ dest.label }}
                                                    </option>
                                                </select>
                                            </div>

                                            <!-- Delete Option -->
                                            <button
                                                type="button"
                                                @click="removeOptionFromField(field, optIdx)"
                                                class="text-gray-400 hover:text-red-600 text-xs p-1 opacity-0 group-hover:opacity-100 transition cursor-pointer"
                                                title="Remove option"
                                            >✕</button>
                                        </div>

                                        <!-- Add Option Row (Google Forms style) -->
                                        <div class="flex items-center space-x-2 text-xs pt-1">
                                            <span class="text-gray-400 text-sm shrink-0">
                                                <span v-if="field.type === 'radio'">⚪</span>
                                                <span v-else-if="field.type === 'checkbox'">⬜</span>
                                                <span v-else class="text-xs font-mono">➕</span>
                                            </span>
                                            <button
                                                type="button"
                                                @click="addOptionToField(field)"
                                                class="text-gray-500 hover:text-forest-900 font-medium hover:underline cursor-pointer"
                                            >
                                                Add option
                                            </button>
                                            <span v-if="!field.allow_other" class="text-gray-400">or</span>
                                            <button
                                                v-if="!field.allow_other"
                                                type="button"
                                                @click="addOtherOptionToField(field)"
                                                class="text-forest-800 hover:text-forest-950 font-bold hover:underline cursor-pointer"
                                            >
                                                add "Other"
                                            </button>
                                        </div>
                                    </div>

                                    <!-- Text / Paragraph Preview Line -->
                                    <div v-else-if="['text', 'textarea'].includes(field.type)" class="py-2">
                                        <div class="text-xs text-gray-400 border-b border-cream-300 pb-1 italic">
                                            {{ field.type === 'text' ? 'Short answer text' : 'Long answer text / paragraph' }}
                                        </div>
                                    </div>

                                    <!-- Date Preview Mockup -->
                                    <div v-else-if="field.type === 'date'" class="py-2">
                                        <div class="inline-flex items-center justify-between text-xs text-gray-400 border-b border-cream-300 pb-1.5 w-60">
                                            <span>Month, day, year</span>
                                            <span class="text-gray-400 text-sm">📅</span>
                                        </div>
                                    </div>

                                    <!-- Linear Scale / Number Config -->
                                    <div v-else-if="field.type === 'number'" class="flex items-center space-x-3 text-xs pt-1">
                                        <div class="flex items-center space-x-1.5">
                                            <span class="text-gray-600 font-bold">Min:</span>
                                            <input
                                                v-model.number="field.min"
                                                type="number"
                                                class="w-16 px-2 py-1 bg-cream-100 border border-cream-400 rounded-lg text-xs"
                                                placeholder="1"
                                            />
                                        </div>
                                        <div class="flex items-center space-x-1.5">
                                            <span class="text-gray-600 font-bold">Max:</span>
                                            <input
                                                v-model.number="field.max"
                                                type="number"
                                                class="w-16 px-2 py-1 bg-cream-100 border border-cream-400 rounded-lg text-xs"
                                                placeholder="5"
                                            />
                                        </div>
                                    </div>

                                    <!-- Card Bottom Actions Toolbar -->
                                    <div class="pt-3 border-t border-cream-300/80 flex items-center justify-end space-x-3 text-gray-600">
                                        <button
                                            type="button"
                                            @click.stop="moveField(idx, -1)"
                                            :disabled="idx === 0"
                                            class="p-1 rounded hover:bg-cream-200 disabled:opacity-30 text-xs cursor-pointer"
                                            title="Move Up"
                                        >▲</button>
                                        <button
                                            type="button"
                                            @click.stop="moveField(idx, 1)"
                                            :disabled="idx === formConfig.schema.fields.length - 1"
                                            class="p-1 rounded hover:bg-cream-200 disabled:opacity-30 text-xs cursor-pointer"
                                            title="Move Down"
                                        >▼</button>

                                        <button
                                            type="button"
                                            @click.stop="duplicateField(idx)"
                                            class="p-1.5 rounded-lg hover:bg-cream-200 text-gray-700 hover:text-gray-900 transition cursor-pointer"
                                            title="Duplicate Question"
                                        >
                                            <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 17.25v3.375c0 .621-.504 1.125-1.125 1.125h-9.75a1.125 1.125 0 01-1.125-1.125V7.875c0-.621.504-1.125 1.125-1.125H6.75a9.06 9.06 0 011.5.124m7.5 10.376h3.375c.621 0 1.125-.504 1.125-1.125V11.25c0-4.46-3.243-8.161-7.5-8.876a9.06 9.06 0 00-1.5-.124H9.375c-.621 0-1.125.504-1.125 1.125v3.5m7.5 10.375H9.375a1.125 1.125 0 01-1.125-1.125v-9.25m12 6.625v-1.875a3.375 3.375 0 00-3.375-3.375h-1.5a1.125 1.125 0 01-1.125-1.125v-1.5a3.375 3.375 0 00-3.375-3.375H9.75" />
                                            </svg>
                                        </button>

                                        <button
                                            type="button"
                                            @click.stop="removeField(idx)"
                                            class="p-1.5 rounded-lg hover:bg-red-50 text-red-500 hover:text-red-700 transition cursor-pointer"
                                            title="Delete Question"
                                        >
                                            <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" />
                                            </svg>
                                        </button>

                                        <div class="h-5 w-px bg-cream-400"></div>

                                        <!-- Required Toggle -->
                                        <div class="flex items-center space-x-2">
                                            <span class="text-xs font-semibold text-gray-800">Required</span>
                                            <label class="relative inline-flex items-center cursor-pointer">
                                                <input
                                                    type="checkbox"
                                                    v-model="field.required"
                                                    class="sr-only peer"
                                                />
                                                <div class="w-8 h-4.5 bg-cream-400 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-3.5 after:w-3.5 after:transition-all peer-checked:bg-forest-900"></div>
                                            </label>
                                        </div>

                                        <!-- Three Dots Menu (⋮) -->
                                        <div class="relative inline-block text-left">
                                            <button
                                                type="button"
                                                @click.stop="toggleFieldMenu(idx)"
                                                class="p-1 rounded-lg hover:bg-cream-200 text-gray-700 cursor-pointer"
                                                title="More options"
                                            >
                                                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 6.75a.75.75 0 110-1.5.75.75 0 010 1.5zM12 12.75a.75.75 0 110-1.5.75.75 0 010 1.5zM12 18.75a.75.75 0 110-1.5.75.75 0 010 1.5z" />
                                                </svg>
                                            </button>

                                            <div
                                                v-if="openMenuFieldIdx === idx"
                                                @click.stop
                                                class="absolute right-0 bottom-full mb-1 w-52 rounded-xl bg-white shadow-xl ring-1 ring-black/10 p-1.5 z-40 border border-cream-400 text-xs font-medium"
                                            >
                                                <button
                                                    type="button"
                                                    @click="field._show_description = !field._show_description; closeAllFieldMenus()"
                                                    class="w-full flex items-center justify-between px-2.5 py-1.5 hover:bg-cream-100 rounded-lg text-left cursor-pointer"
                                                >
                                                    <span>Description</span>
                                                    <span v-if="field._show_description || field.description" class="text-forest-900 font-bold">✓</span>
                                                </button>

                                                <button
                                                    type="button"
                                                    @click="field._show_id = !field._show_id; closeAllFieldMenus()"
                                                    class="w-full flex items-center justify-between px-2.5 py-1.5 hover:bg-cream-100 rounded-lg text-left cursor-pointer"
                                                >
                                                    <span>Custom Field ID</span>
                                                    <span v-if="field._show_id || field.manualId" class="text-forest-900 font-bold">✓</span>
                                                </button>

                                                <button
                                                    v-if="['radio', 'select'].includes(field.type)"
                                                    type="button"
                                                    @click="field._show_branching = !field._show_branching; closeAllFieldMenus()"
                                                    class="w-full flex items-center justify-between px-2.5 py-1.5 hover:bg-cream-100 rounded-lg text-left cursor-pointer"
                                                >
                                                    <span>Go to section based on answer</span>
                                                    <span v-if="field._show_branching" class="text-forest-900 font-bold">✓</span>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- INACTIVE RESTING PREVIEW STATE -->
                                <div v-else class="p-5 space-y-2">
                                    <div class="flex items-center justify-between">
                                        <div class="flex items-center space-x-2">
                                            <span class="text-sm font-bold text-gray-900">
                                                {{ field.particular || 'Untitled Question' }}
                                                <span v-if="field.required" class="text-red-500 font-bold ml-0.5">*</span>
                                            </span>
                                        </div>
                                        <span class="text-[10px] uppercase font-mono px-2 py-0.5 rounded bg-cream-200 text-gray-600">
                                            {{ field.type }}
                                        </span>
                                    </div>

                                    <p v-if="field.description" class="text-xs text-gray-500">
                                        {{ field.description }}
                                    </p>

                                    <!-- Read-only preview of options -->
                                    <div
                                        v-if="['radio', 'checkbox'].includes(field.type) || (field.type === 'select' && field.option_source !== 'fb_functions')"
                                        class="space-y-1.5 pt-1"
                                    >
                                        <div
                                            v-for="(opt, oIdx) in (field.options || []).slice(0, 4)"
                                            :key="oIdx"
                                            class="flex items-center space-x-2 text-xs text-gray-700"
                                        >
                                            <span class="text-gray-400 text-xs">
                                                {{ field.type === 'radio' ? '⚪' : '⬜' }}
                                            </span>
                                            <span>{{ opt.label }}</span>
                                            <span v-if="opt.goto_section" class="text-[10px] font-bold text-forest-800 bg-forest-50 px-1.5 py-0.2 rounded">
                                                ➔ {{ opt.goto_section }}
                                            </span>
                                        </div>
                                        <div v-if="(field.options || []).length > 4" class="text-[11px] text-gray-400 italic">
                                            + {{ (field.options || []).length - 4 }} more option(s)
                                        </div>
                                    </div>

                                    <div v-else-if="['text', 'textarea'].includes(field.type)" class="text-xs text-gray-400 italic border-b border-cream-300 pb-1">
                                        {{ field.type === 'text' ? 'Short answer' : 'Paragraph' }}
                                    </div>

                                    <div v-else-if="field.type === 'date'" class="text-xs text-gray-400 italic border-b border-cream-300 pb-1 flex items-center justify-between w-48">
                                        <span>Month, day, year</span>
                                        <span>📅</span>
                                    </div>

                                    <div v-else-if="field.type === 'number'" class="text-xs text-gray-500 italic">
                                        Scale: {{ field.min ?? 1 }} to {{ field.max ?? 5 }}
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- TAB 2: PAGINATION & LOGIC VISUAL MAP -->
                <div v-show="activeTab === 'logic'" class="space-y-4">
                    <div class="bg-white rounded-2xl shadow-xs border border-cream-400/80 p-6 space-y-4">
                        <div class="flex items-center justify-between flex-wrap gap-2 pb-3 border-b border-cream-300">
                            <div>
                                <h3 class="text-lg font-black text-gray-900 tracking-tight">
                                    Multi-Page Pagination & Conditional Logic Map
                                </h3>
                                <p class="text-xs text-gray-500 mt-0.5">
                                    Review the page flow, branch destinations, and display conditions for this questionnaire.
                                </p>
                            </div>

                            <div class="flex items-center space-x-2">
                                <label class="text-xs font-bold text-gray-700">Pagination Status:</label>
                                <span
                                    class="px-2.5 py-1 rounded-full text-xs font-black uppercase tracking-wider"
                                    :class="formConfig.schema.pagination?.enabled ? 'bg-forest-900 text-lime-200' : 'bg-gray-200 text-gray-600'"
                                >
                                    {{ formConfig.schema.pagination?.enabled ? 'Active / Multi-Page' : 'Disabled / Single-Page' }}
                                </span>
                            </div>
                        </div>

                        <!-- If no sections defined -->
                        <div v-if="sectionList.length === 0" class="p-8 text-center bg-cream-100 rounded-2xl border border-cream-400/60 space-y-3">
                            <div class="size-12 rounded-full bg-forest-100 text-forest-900 flex items-center justify-center mx-auto text-xl font-bold">
                                〓
                            </div>
                            <h4 class="text-sm font-bold text-gray-900">No Section Page Breaks Defined</h4>
                            <p class="text-xs text-gray-600 max-w-md mx-auto">
                                To divide this questionnaire into pages, add one or more <strong>Section Header</strong> dividers. Each section starts a new page in the respondent's form.
                            </p>
                            <button
                                type="button"
                                @click="addSectionAt(-1); switchTab('questions')"
                                class="inline-flex items-center space-x-1.5 px-4 py-2 bg-forest-900 hover:bg-forest-950 text-white text-xs font-bold rounded-xl shadow-xs transition cursor-pointer"
                            >
                                <span>+ Add First Section</span>
                            </button>
                        </div>

                        <!-- Sections summary table -->
                        <div v-else class="space-y-4">
                            <div
                                v-for="(sec, sIdx) in sectionList"
                                :key="sec.id"
                                class="p-4 bg-cream-100/60 rounded-xl border border-cream-400/80 space-y-3"
                            >
                                <div class="flex items-center justify-between flex-wrap gap-2">
                                    <div class="flex items-center space-x-2">
                                        <span class="size-6 rounded-full bg-forest-900 text-lime-200 font-bold text-xs flex items-center justify-center">
                                            {{ sIdx + 1 }}
                                        </span>
                                        <h4 class="text-sm font-black text-gray-900">
                                            {{ sec.particular || `Section ${sIdx + 1}` }}
                                        </h4>
                                        <span class="text-[11px] font-mono text-gray-500">
                                            ({{ sec.id }})
                                        </span>
                                    </div>

                                    <div class="text-xs font-semibold text-gray-700 flex items-center space-x-2">
                                        <span>After section:</span>
                                        <span class="px-2 py-0.5 rounded bg-cream-200 text-forest-900 border border-cream-300 font-bold">
                                            {{ sec.section_flow || 'next' }}
                                        </span>
                                    </div>
                                </div>

                                <!-- Display Conditions summary -->
                                <div class="text-xs">
                                    <span class="font-bold text-gray-700">Display Condition:</span>
                                    <span v-if="!sec.conditions || sec.conditions.length === 0" class="text-gray-500 ml-1.5">
                                        Always shown (sequential)
                                    </span>
                                    <div v-else class="mt-1 space-y-1">
                                        <span
                                            v-for="(c, cIdx) in sec.conditions"
                                            :key="cIdx"
                                            class="inline-flex items-center space-x-1 px-2.5 py-0.5 rounded-lg bg-amber-100 text-amber-900 border border-amber-300 text-[11px] font-semibold mr-1.5"
                                        >
                                            <span>Only if <strong>{{ c.field_id }}</strong> {{ c.operator }} <strong>"{{ c.value }}"</strong></span>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- TAB 3: JSON SCHEMA EDITOR -->
                <div v-show="activeTab === 'json'" class="space-y-3">
                    <div class="flex items-center justify-between">
                        <label class="block text-xs font-bold text-forest-900 uppercase tracking-wider">Raw JSON Schema</label>
                        <span class="text-xs text-gray-500 font-mono">{ "title", "pagination", "fields": [ ... ] }</span>
                    </div>

                    <textarea
                        v-model="jsonSchemaString"
                        rows="18"
                        class="w-full px-4 py-3 bg-gray-900 text-emerald-300 font-mono text-xs rounded-2xl border border-gray-700 focus:border-emerald-400 focus:ring-1 focus:ring-emerald-400 shadow-inner"
                        placeholder="{ ... }"
                    ></textarea>

                    <div v-if="jsonParseError" class="p-3 bg-red-50 border border-red-200 text-red-700 rounded-xl text-xs font-medium">
                        {{ jsonParseError }}
                    </div>
                </div>

                <!-- TAB 4: DOCUMENT IMPORT MODE -->
                <div v-show="activeTab === 'import'" class="space-y-4">
                    <div v-if="!importResult" class="space-y-4">
                        <div class="text-center max-w-lg mx-auto py-2">
                            <h4 class="text-sm font-bold text-gray-900">Import Evaluation / Survey Document</h4>
                            <p class="text-xs text-gray-500 mt-1">
                                Upload a Word, Excel, or PDF questionnaire document. The assistant extracts questions and answer choices for your review.
                            </p>
                        </div>

                        <!-- Drag & Drop Zone -->
                        <div
                            @dragover.prevent="isDragging = true"
                            @dragleave.prevent="isDragging = false"
                            @drop.prevent="onDropFile"
                            class="border-2 border-dashed rounded-3xl p-8 text-center transition cursor-pointer"
                            :class="isDragging ? 'border-forest-600 bg-forest-50/50' : 'border-cream-500/80 bg-white hover:border-forest-500'"
                            @click="$refs.fileInput.click()"
                        >
                            <input
                                ref="fileInput"
                                type="file"
                                class="hidden"
                                accept=".docx,.xlsx,.xls,.csv,.pdf,.png,.jpg,.jpeg,.webp,.txt"
                                @change="onFileSelected"
                            />

                            <div class="size-14 rounded-2xl bg-cream-300 text-forest-900 flex items-center justify-center mx-auto mb-3 shadow-xs">
                                📄
                            </div>

                            <p class="text-sm font-bold text-gray-800">
                                Drag & drop your file here, or <span class="text-forest-900 underline">browse files</span>
                            </p>
                            <p class="text-xs text-gray-500 mt-1">
                                Supports Word (.docx), Excel (.xlsx, .csv), PDF (.pdf), and images (up to 10MB)
                            </p>
                        </div>

                        <!-- Selected File & Action -->
                        <div v-if="importFile" class="p-4 bg-white rounded-2xl border border-forest-500/40 shadow-xs flex items-center justify-between gap-4">
                            <div class="truncate">
                                <p class="text-xs font-bold text-gray-900 truncate">{{ importFile.name }}</p>
                                <p class="text-[11px] text-gray-500">{{ formatFileSize(importFile.size) }}</p>
                            </div>
                            <div class="flex items-center space-x-2 shrink-0">
                                <button
                                    type="button"
                                    @click="importFile = null"
                                    class="px-3 py-1.5 bg-cream-200 text-gray-700 text-xs font-semibold rounded-xl cursor-pointer"
                                >Clear</button>
                                <button
                                    type="button"
                                    @click="startDocumentAnalysis"
                                    :disabled="isAnalyzing"
                                    class="px-4 py-1.5 bg-forest-900 text-white text-xs font-bold rounded-xl shadow-xs transition cursor-pointer"
                                >
                                    {{ isAnalyzing ? 'Analyzing...' : 'Start Extraction' }}
                                </button>
                            </div>
                        </div>

                        <div v-if="isAnalyzing" class="p-6 bg-forest-50 border border-forest-200 rounded-2xl text-center space-y-2">
                            <div class="inline-block size-6 border-2 border-forest-900 border-t-transparent rounded-full animate-spin"></div>
                            <p class="text-xs font-bold text-forest-950">{{ analyzeStepText }}</p>
                        </div>

                        <div v-if="importError" class="p-4 bg-red-50 border border-red-200 text-red-800 rounded-2xl text-xs">
                            {{ importError }}
                        </div>
                    </div>

                    <!-- Step 2: Import Review & Candidate Mapping -->
                    <div v-else class="space-y-4">
                        <div class="p-4 bg-white rounded-2xl border border-cream-400 shadow-xs flex items-center justify-between flex-wrap gap-2">
                            <div>
                                <span class="text-xs font-bold text-forest-950">Detected {{ importResult.summary?.total_detected }} field(s) from {{ importResult.filename }}</span>
                            </div>
                            <div class="flex items-center space-x-2">
                                <button
                                    type="button"
                                    @click="showMergeModal = true"
                                    :disabled="selectedCandidatesCount === 0"
                                    class="px-4 py-1.5 bg-forest-900 text-white text-xs font-bold rounded-xl shadow-xs transition cursor-pointer"
                                >
                                    Add Selected ({{ selectedCandidatesCount }}) to Builder →
                                </button>
                            </div>
                        </div>

                        <div class="space-y-3 max-h-[55vh] overflow-y-auto pr-1">
                            <div
                                v-for="(cand, cIdx) in importResult.candidates"
                                :key="cIdx"
                                class="p-3.5 rounded-xl border bg-white space-y-2 text-xs"
                            >
                                <div class="flex items-center justify-between">
                                    <label class="flex items-center space-x-2 cursor-pointer">
                                        <input
                                            type="checkbox"
                                            v-model="cand.include"
                                            class="rounded border-cream-400 text-forest-900 focus:ring-forest-500 size-4"
                                        />
                                        <span class="font-bold text-gray-900">{{ cand.particular }}</span>
                                    </label>
                                    <span class="px-2 py-0.5 rounded text-[10px] font-mono bg-cream-200 text-gray-600">
                                        {{ cand.type }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- FLOATING RIGHT COMPANION TOOLBAR (GOOGLE FORMS ICONIC BAR) -->
                <div
                    v-if="activeTab === 'questions'"
                    class="fixed right-4 sm:right-8 lg:right-12 top-1/2 -translate-y-1/2 z-40 bg-white shadow-xl rounded-2xl border border-cream-400/90 p-1.5 flex flex-col space-y-1.5 transition-all"
                >
                    <button
                        type="button"
                        @click="addQuestionAt(activeFieldIdx)"
                        class="p-2.5 rounded-xl hover:bg-forest-100 text-gray-700 hover:text-forest-900 transition cursor-pointer group relative"
                        title="Add question"
                    >
                        <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                        </svg>
                        <span class="absolute right-full mr-2 top-1/2 -translate-y-1/2 px-2 py-1 bg-gray-900 text-white text-[10px] font-bold rounded-lg whitespace-nowrap opacity-0 group-hover:opacity-100 transition pointer-events-none shadow-md">
                            Add Question
                        </span>
                    </button>

                    <button
                        type="button"
                        @click="switchTab('import')"
                        class="p-2.5 rounded-xl hover:bg-forest-100 text-gray-700 hover:text-forest-900 transition cursor-pointer group relative"
                        title="Import questions"
                    >
                        <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5" />
                        </svg>
                        <span class="absolute right-full mr-2 top-1/2 -translate-y-1/2 px-2 py-1 bg-gray-900 text-white text-[10px] font-bold rounded-lg whitespace-nowrap opacity-0 group-hover:opacity-100 transition pointer-events-none shadow-md">
                            Import Form
                        </span>
                    </button>

                    <button
                        type="button"
                        @click="addSectionAt(activeFieldIdx)"
                        class="p-2.5 rounded-xl hover:bg-indigo-100 text-gray-700 hover:text-indigo-900 transition cursor-pointer group relative"
                        title="Add Section / Page Break"
                    >
                        <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25H12" />
                        </svg>
                        <span class="absolute right-full mr-2 top-1/2 -translate-y-1/2 px-2 py-1 bg-gray-900 text-white text-[10px] font-bold rounded-lg whitespace-nowrap opacity-0 group-hover:opacity-100 transition pointer-events-none shadow-md">
                            Add Section / Page Break
                        </span>
                    </button>
                </div>
            </div>
        </main>

        <!-- Merge Confirmation Modal for Document Import -->
        <div v-if="showMergeModal" class="fixed inset-0 z-50 overflow-y-auto bg-gray-900/60 backdrop-blur-xs flex items-center justify-center p-4">
            <div class="bg-cream-200 rounded-3xl max-w-md w-full shadow-2xl p-6 border border-cream-500/60 text-center space-y-4">
                <div class="size-12 rounded-full bg-forest-100 text-forest-900 flex items-center justify-center mx-auto text-xl font-bold">
                    📥
                </div>
                <div>
                    <h3 class="text-base font-bold text-gray-900">How should imported fields be added?</h3>
                    <p class="text-xs text-gray-600 mt-1">
                        You have selected <strong>{{ selectedCandidatesCount }}</strong> imported field(s).
                    </p>
                </div>

                <div class="space-y-2 text-left text-xs font-semibold">
                    <button
                        type="button"
                        @click="applyImportedFields('append')"
                        class="w-full p-3.5 bg-forest-900 hover:bg-forest-950 text-white rounded-xl shadow-xs transition flex items-center justify-between cursor-pointer"
                    >
                        <div>
                            <p class="font-bold">Append to existing questions</p>
                            <p class="text-[11px] text-white/80 font-normal">Keep current questions and add imported fields to end</p>
                        </div>
                        <span class="text-base">➕</span>
                    </button>

                    <button
                        type="button"
                        @click="applyImportedFields('replace')"
                        class="w-full p-3.5 bg-cream-100 hover:bg-amber-100 text-gray-900 hover:text-amber-900 rounded-xl border border-cream-400 hover:border-amber-300 transition flex items-center justify-between cursor-pointer"
                    >
                        <div>
                            <p class="font-bold">Replace existing questions</p>
                            <p class="text-[11px] text-gray-500 font-normal">Overwrite current questionnaire with imported fields</p>
                        </div>
                        <span class="text-base">🔄</span>
                    </button>
                </div>

                <div class="pt-2 border-t border-cream-500/30">
                    <button
                        type="button"
                        @click="showMergeModal = false"
                        class="text-xs text-gray-500 hover:text-gray-800 font-semibold cursor-pointer"
                    >
                        Cancel
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>

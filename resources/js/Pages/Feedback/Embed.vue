<script setup>
import { ref, computed, watch, onMounted } from 'vue';
import { Head } from '@inertiajs/vue3';
import axios from 'axios';

const props = defineProps({
    event: {
        type: Object,
        default: null,
    },
    form: {
        type: Object,
        default: null,
    },
    publicId: {
        type: String,
        required: true,
    },
    sessionToken: {
        type: String,
        required: true,
    },
    userDefaults: {
        type: Object,
        default: () => ({}),
    },
    allowedOrigins: {
        type: Array,
        default: () => [],
    },
});

// Sorted schema fields by weight ascending
const sortedFields = computed(() => {
    if (!props.form?.schema?.fields || !Array.isArray(props.form.schema.fields)) {
        return [];
    }
    return [...props.form.schema.fields].sort((a, b) => (Number(a.weight) || 0) - (Number(b.weight) || 0));
});

// Helper to determine target origin from document.referrer against allowed list
const getTargetOrigin = () => {
    try {
        if (!document.referrer) {
            return null;
        }
        const refUrl = new URL(document.referrer);
        const refOrigin = refUrl.origin;
        if (Array.isArray(props.allowedOrigins) && props.allowedOrigins.includes(refOrigin)) {
            return refOrigin;
        }
        if (typeof window !== 'undefined' && refOrigin === window.location.origin) {
            return refOrigin;
        }
    } catch (e) {
        // Invalid referrer URL
    }
    return null;
};

// Send postMessage strictly to verified parent origin
const sendParentMessage = (message) => {
    try {
        if (window.parent && window.parent !== window) {
            const targetOrigin = getTargetOrigin();
            if (targetOrigin) {
                window.parent.postMessage(message, targetOrigin);
            }
        }
    } catch (e) {
        // Suppress cross-origin frame access warnings
    }
};

// Dynamic form answers dictionary keyed by field.id
const answers = ref({});

// Initialize answers dictionary from dynamic schema fields
const initAnswers = () => {
    const initial = {};
    sortedFields.value.forEach((field) => {
        if (field.type === 'section') {
            return;
        }
        if (field.type === 'checkbox') {
            initial[field.id] = [];
        } else {
            initial[field.id] = props.userDefaults?.[field.id] ?? '';
        }
        if (field.allow_other) {
            initial[`${field.id}_other`] = '';
        }
    });
    answers.value = initial;
};

watch(
    () => props.form?.schema?.fields,
    () => {
        initAnswers();
    },
    { immediate: true }
);

// --- Pagination & Multi-Page Sections Engine ---
const currentPageIdx = ref(0);
const pageHistory = ref([0]);
const clientErrors = ref({});

const sectionPages = computed(() => {
    if (!sortedFields.value.length) return [];
    const pages = [];
    let currentSection = null;
    let currentFields = [];

    sortedFields.value.forEach((field) => {
        if (field.type === 'section') {
            if (currentSection !== null || currentFields.length > 0) {
                pages.push({
                    section: currentSection,
                    fields: currentFields,
                });
            }
            currentSection = field;
            currentFields = [];
        } else {
            currentFields.push(field);
        }
    });

    if (currentSection !== null || currentFields.length > 0) {
        pages.push({
            section: currentSection,
            fields: currentFields,
        });
    }

    return pages;
});

const isPaginated = computed(() => {
    return Boolean(props.form?.schema?.pagination?.enabled && sectionPages.value.length > 1);
});

const currentPage = computed(() => {
    return sectionPages.value[currentPageIdx.value] || null;
});

const isConditionsMet = (conditions) => {
    if (!conditions || conditions.length === 0) return true;
    for (const cond of conditions) {
        const triggerId = cond.field_id;
        const op = cond.operator || 'equals';
        const targetVal = String(cond.value ?? '');
        const actual = answers.value[triggerId];

        if (op === 'equals') {
            if (Array.isArray(actual)) {
                if (!actual.map(String).includes(targetVal)) return false;
            } else {
                if (String(actual ?? '') !== targetVal) return false;
            }
        } else if (op === 'not_equals') {
            if (Array.isArray(actual)) {
                if (actual.map(String).includes(targetVal)) return false;
            } else {
                if (String(actual ?? '') === targetVal) return false;
            }
        } else if (op === 'contains') {
            if (Array.isArray(actual)) {
                if (!actual.some((item) => String(item ?? '').toLowerCase().includes(targetVal.toLowerCase()))) return false;
            } else {
                if (!String(actual ?? '').toLowerCase().includes(targetVal.toLowerCase())) return false;
            }
        } else if (op === 'not_contains') {
            if (Array.isArray(actual)) {
                if (actual.some((item) => String(item ?? '').toLowerCase().includes(targetVal.toLowerCase()))) return false;
            } else {
                if (String(actual ?? '').toLowerCase().includes(targetVal.toLowerCase())) return false;
            }
        } else if (op === 'is_answered') {
            if (actual === null || actual === '' || (Array.isArray(actual) && actual.length === 0)) return false;
        } else if (op === 'is_not_answered') {
            if (actual !== null && actual !== '' && (!Array.isArray(actual) || actual.length > 0)) return false;
        }
    }
    return true;
};

const isSectionConditionMet = (sec) => isConditionsMet(sec?.conditions);
const isFieldConditionMet = (field) => isConditionsMet(field?.conditions);

const validateCurrentPage = () => {
    clientErrors.value = {};
    const page = currentPage.value;
    if (!page) return true;

    let hasError = false;
    for (const field of page.fields) {
        if (!isFieldConditionMet(field)) continue;
        if (field.required) {
            const val = answers.value[field.id];
            if (val === null || val === undefined || val === '' || (Array.isArray(val) && val.length === 0)) {
                clientErrors.value[field.id] = `The '${field.particular}' field is required.`;
                hasError = true;
            }
        }
    }
    return !hasError;
};

const isLastPage = computed(() => {
    if (!isPaginated.value) return true;
    return currentPageIdx.value >= sectionPages.value.length - 1;
});

const goToNextPage = () => {
    if (!validateCurrentPage()) {
        const firstErrId = Object.keys(clientErrors.value)[0];
        if (firstErrId) {
            highlightField(firstErrId);
            const el = document.getElementById(`field_container_${firstErrId}`);
            el?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
        return;
    }

    let target = null;
    const page = currentPage.value;
    if (page) {
        for (const f of page.fields) {
            const ans = answers.value[f.id];
            if (ans !== undefined && f.options) {
                for (const opt of f.options) {
                    const isMatch = Array.isArray(ans) ? ans.includes(opt.value) : String(ans) === String(opt.value);
                    if (isMatch && opt.goto_section) {
                        target = opt.goto_section;
                        break;
                    }
                }
            }
            if (target) break;
        }

        if (!target && page.section?.section_flow && page.section.section_flow !== 'next') {
            target = page.section.section_flow;
        }
    }

    if (target === 'submit') {
        submitForm();
        return;
    }

    let nextIdx = -1;
    if (target && target !== 'next') {
        nextIdx = sectionPages.value.findIndex((p) => p.section?.id === target);
    }

    if (nextIdx === -1) {
        nextIdx = currentPageIdx.value + 1;
    }

    while (nextIdx < sectionPages.value.length && !isSectionConditionMet(sectionPages.value[nextIdx].section)) {
        nextIdx++;
    }

    if (nextIdx >= sectionPages.value.length) {
        submitForm();
        return;
    }

    pageHistory.value.push(nextIdx);
    currentPageIdx.value = nextIdx;
    window.scrollTo({ top: 0, behavior: 'smooth' });
};

const goToPreviousPage = () => {
    if (pageHistory.value.length > 1) {
        pageHistory.value.pop();
        currentPageIdx.value = pageHistory.value[pageHistory.value.length - 1];
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }
};

// Submission states
const isSubmitting = ref(false);
const serverErrors = ref({});
const submissionSuccess = ref(false);
const submittedRecord = ref(null);

// Temporary quick go-to error navigation & highlighting state
const highlightedFieldId = ref(null);
let highlightTimer = null;
const showQuickErrorToast = ref(false);
let quickErrorToastTimer = null;
let currentErrorIndex = 0;

// Extract list of fields with validation errors
const errorFieldsList = computed(() => {
    const list = [];
    const seenFieldIds = new Set();
    const errorKeys = Object.keys(serverErrors.value).filter((k) => k !== 'general');

    errorKeys.forEach((key) => {
        let fieldId = key;
        if (fieldId.startsWith('answers.')) {
            fieldId = fieldId.substring(8);
        } else if (fieldId.startsWith('data.')) {
            fieldId = fieldId.substring(5);
        }
        const isOther = fieldId.endsWith('_other');
        if (isOther) {
            fieldId = fieldId.substring(0, fieldId.length - 6);
        }

        if (!seenFieldIds.has(fieldId)) {
            seenFieldIds.add(fieldId);
            const field = sortedFields.value.find((f) => String(f.id) === String(fieldId));
            if (field?.type === 'section') {
                return;
            }
            const errorMsg = serverErrors.value[key]?.[0]
                || getFieldError(fieldId)
                || getOtherFieldError(fieldId)
                || 'This field requires your attention.';

            list.push({
                id: fieldId,
                particular: field?.particular || `Question (${fieldId})`,
                weight: field?.weight || null,
                message: errorMsg,
                isOther,
            });
        }
    });

    return list;
});

// Temporarily highlight a field and clear after 3.5 seconds
const highlightField = (fieldId) => {
    highlightedFieldId.value = fieldId;
    if (highlightTimer) {
        clearTimeout(highlightTimer);
    }
    highlightTimer = setTimeout(() => {
        highlightedFieldId.value = null;
    }, 3500);
};

// Scroll to error field, focus input, and apply temporary highlight
const goToErrorField = (fieldId) => {
    if (!fieldId) return;

    if (isPaginated.value) {
        const targetPageIdx = sectionPages.value.findIndex((p) => p.fields.some((f) => String(f.id) === String(fieldId)));
        if (targetPageIdx !== -1 && targetPageIdx !== currentPageIdx.value) {
            currentPageIdx.value = targetPageIdx;
        }
    }

    highlightField(fieldId);

    setTimeout(() => {
        const containerEl = document.getElementById(`field_container_${fieldId}`);
        if (containerEl) {
            containerEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }

        const inputEl = document.getElementById(`input_${fieldId}`)
            || containerEl?.querySelector('input:not([type=hidden]), textarea, select, [tabindex="0"]');

        if (inputEl) {
            try {
                inputEl.focus();
            } catch (e) {
                // Ignore focus errors
            }
        }
    }, 100);
};

const goToFirstError = () => {
    if (errorFieldsList.value.length > 0) {
        currentErrorIndex = 0;
        goToErrorField(errorFieldsList.value[0].id);
    }
};

const goToNextError = () => {
    if (errorFieldsList.value.length === 0) return;
    const item = errorFieldsList.value[currentErrorIndex % errorFieldsList.value.length];
    currentErrorIndex++;
    goToErrorField(item.id);
};

onMounted(() => {
    // Notify parent frame that embedded form is ready
    sendParentMessage({
        type: 'feedback-embed-ready',
        publicId: props.publicId,
        height: document.body.scrollHeight,
    });
});

// Check if currently selected option represents "other"
const isOtherSelected = (field) => {
    if (!field.allow_other) return false;
    const val = answers.value[field.id];
    if (val === undefined || val === null || val === '') return false;

    if (field.type === 'checkbox') {
        if (!Array.isArray(val)) return false;
        return val.some((selectedVal) => {
            const matchedOpt = (field.options || []).find((opt) => String(opt.value) === String(selectedVal));
            return matchedOpt?.is_other === true || String(selectedVal).toLowerCase() === 'other';
        });
    }

    const matchedOpt = (field.options || []).find((opt) => String(opt.value) === String(val));
    return matchedOpt?.is_other === true || String(val).toLowerCase() === 'other';
};

// Checkbox selection toggle handler
const handleCheckboxToggle = (fieldId, optionValue) => {
    if (!Array.isArray(answers.value[fieldId])) {
        answers.value[fieldId] = [];
    }
    const idx = answers.value[fieldId].indexOf(optionValue);
    if (idx > -1) {
        answers.value[fieldId].splice(idx, 1);
    } else {
        answers.value[fieldId].push(optionValue);
    }
};

// Generic dynamic form submission
const submitForm = async () => {
    isSubmitting.value = true;
    serverErrors.value = {};
    showQuickErrorToast.value = false;

    const payload = {
        token: props.sessionToken,
        answers: { ...answers.value },
    };

    try {
        const response = await axios.post(route('feedback.embed.submit', props.publicId), payload);
        if (response.status === 201 || response.status === 200) {
            submissionSuccess.value = true;
            submittedRecord.value = response.data?.data || null;
            serverErrors.value = {};
            showQuickErrorToast.value = false;

            // Notify parent iframe container strictly to allowed origin
            sendParentMessage({
                type: 'feedback-submitted',
                publicId: props.publicId,
                submissionId: response.data?.data?.id || null,
            });
        }
    } catch (error) {
        if (error.response && error.response.status === 422) {
            serverErrors.value = error.response.data.errors || {};
            showQuickErrorToast.value = true;

            // Automatically jump to and temporarily highlight the first error field
            setTimeout(() => {
                goToFirstError();
            }, 150);

            // Keep toast visible for 15s or until dismissed
            if (quickErrorToastTimer) clearTimeout(quickErrorToastTimer);
            quickErrorToastTimer = setTimeout(() => {
                showQuickErrorToast.value = false;
            }, 15000);
        } else {
            serverErrors.value = {
                general: [error.response?.data?.message || 'An unexpected error occurred while submitting feedback. Please try again.'],
            };
        }
    } finally {
        isSubmitting.value = false;
    }
};

const getFieldError = (fieldId) => {
    return clientErrors.value[fieldId]
        || serverErrors.value[`answers.${fieldId}`]?.[0]
        || serverErrors.value[`data.${fieldId}`]?.[0]
        || serverErrors.value[fieldId]?.[0]
        || null;
};

const getOtherFieldError = (fieldId) => {
    return serverErrors.value[`answers.${fieldId}_other`]?.[0]
        || serverErrors.value[`data.${fieldId}_other`]?.[0]
        || serverErrors.value[`${fieldId}_other`]?.[0]
        || null;
};
</script>

<template>
    <div class="min-h-screen bg-[#faf8f5] text-gray-900 font-sans antialiased p-4 sm:p-6 flex flex-col justify-center">
        <Head :title="event?.name ? `Evaluation - ${event.name}` : 'Evaluation Feedback Form'" />

        <div class="max-w-3xl w-full mx-auto">
            <!-- Success State View -->
            <div v-if="submissionSuccess" class="bg-[#fffef9] rounded-3xl p-8 sm:p-12 text-center border border-cream-500/70 shadow-lg space-y-5">
                <div class="size-16 rounded-full bg-emerald-100 text-emerald-700 flex items-center justify-center mx-auto shadow-inner">
                    <svg class="size-8" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                    </svg>
                </div>

                <h2 class="text-2xl font-black text-gray-900">
                    Feedback Submitted!
                </h2>

                <p class="text-sm text-gray-600 max-w-md mx-auto leading-relaxed">
                    Thank you for your valuable evaluation. Your responses for <strong>{{ event?.name }}</strong> have been securely recorded.
                </p>

                <div v-if="submittedRecord?.id" class="inline-block p-3 bg-cream-100/60 rounded-xl border border-cream-400 text-xs font-mono text-gray-700">
                    Reference Number: #{{ submittedRecord.id }}
                </div>
            </div>

            <!-- Unavailable / Empty State -->
            <div v-else-if="!event || !form || !sortedFields.length" class="bg-cream-200 rounded-3xl p-10 text-center border border-cream-500/50 shadow-sm">
                <div class="size-14 rounded-full bg-cream-100 text-gray-400 flex items-center justify-center mx-auto mb-3">
                    <svg class="size-7" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" />
                    </svg>
                </div>
                <h3 class="text-base font-bold text-gray-900">Feedback Form Unavailable</h3>
                <p class="text-xs text-gray-600 max-w-sm mx-auto mt-1">
                    This evaluation form is currently unavailable or has already been submitted.
                </p>
            </div>

            <!-- Active Questionnaire Form -->
            <form v-else @submit.prevent="isPaginated && !isLastPage ? goToNextPage() : submitForm()" class="space-y-6">
                <!-- General Error Alert -->
                <div v-if="serverErrors.general" class="p-4 bg-red-50 border border-red-200 text-red-800 rounded-2xl text-xs font-medium flex items-center shadow-xs">
                    <svg class="size-5 me-2 shrink-0 text-red-600" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" />
                    </svg>
                    <span>{{ serverErrors.general[0] }}</span>
                </div>

                <!-- Event Header Card -->
                <div
                    class="bg-forest-900 rounded-3xl p-6 sm:p-8 text-white shadow-lg border border-forest-800 relative overflow-hidden"
                    style="background-color: #1b4332; color: #ffffff;"
                >
                    <span
                        class="inline-flex items-center px-3 py-1 rounded-lg text-[11px] font-black uppercase tracking-wider shadow-xs mb-3"
                        style="background-color: #fde047; color: #1b4332;"
                    >
                        Evaluation Form
                    </span>
                    <h1 class="text-2xl sm:text-3xl font-black tracking-tight" style="color: #ffffff;">
                        {{ event.name }}
                    </h1>
                    <p
                        v-if="event.details"
                        class="text-sm mt-2.5 leading-relaxed max-w-2xl font-medium"
                        style="color: #dcfce7;"
                    >
                        {{ event.details }}
                    </p>
                </div>

                <!-- Schema Fields Container -->
                <div class="bg-[#fffef9] rounded-3xl p-6 sm:p-8 border border-cream-500/70 shadow-sm space-y-6">
                    <!-- Multi-Page Progress Indicator -->
                    <div v-if="isPaginated" class="border-b border-cream-400/50 pb-4 space-y-2">
                        <div class="flex items-center justify-between text-xs font-bold text-forest-900">
                            <span>{{ currentPage?.section?.particular || `Section ${currentPageIdx + 1}` }}</span>
                            <span v-if="props.form?.schema?.pagination?.show_section_numbers !== false">
                                Section {{ currentPageIdx + 1 }} of {{ sectionPages.length }}
                            </span>
                        </div>
                        <p v-if="currentPage?.section?.description" class="text-xs text-gray-600">
                            {{ currentPage.section.description }}
                        </p>
                        <div v-if="props.form?.schema?.pagination?.progress_bar !== false" class="h-2 w-full bg-cream-200 rounded-full overflow-hidden">
                            <div
                                class="h-full bg-forest-900 transition-all duration-300 rounded-full"
                                :style="{ width: `${Math.round(((currentPageIdx + 1) / sectionPages.length) * 100)}%` }"
                            ></div>
                        </div>
                    </div>
                    <div v-else class="border-b border-cream-400/50 pb-3">
                        <h3 class="text-base font-bold text-gray-900">
                            Questionnaire
                        </h3>
                        <p class="text-xs text-gray-500 mt-0.5">Please provide your honest answers and ratings for each item below.</p>
                    </div>

                    <div class="space-y-6">
                        <!-- Dynamic Field Loop -->
                        <template v-for="(field, index) in (isPaginated ? (currentPage?.fields || []) : sortedFields)" :key="field.id">
                            <!-- Section Header / Divider -->
                            <div
                                v-if="field.type === 'section'"
                                :id="`field_container_${field.id}`"
                                class="pt-6 pb-2 border-b-2 border-forest-900/15 first:pt-0"
                            >
                                <div class="flex items-center gap-3">
                                    <span class="size-7 rounded-xl bg-forest-900 text-emerald-200 text-xs font-black flex items-center justify-center shrink-0 shadow-xs">
                                        #{{ field.weight || index + 1 }}
                                    </span>
                                    <div class="flex-1">
                                        <h3 class="text-base sm:text-lg font-black text-forest-950 tracking-tight">
                                            {{ field.particular }}
                                        </h3>
                                        <p v-if="field.description" class="text-xs text-gray-600 mt-1 leading-relaxed font-medium">
                                            {{ field.description }}
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <!-- Regular Question / Input Card -->
                            <div
                                v-else-if="isFieldConditionMet(field)"
                                :id="`field_container_${field.id}`"
                                class="p-5 rounded-2xl border space-y-3 transition-all duration-300"
                                :class="[
                                    highlightedFieldId === field.id
                                        ? 'ring-4 ring-red-500/80 ring-offset-2 border-red-500 bg-red-50/90 shadow-xl scale-[1.01] animate-pulse'
                                        : (getFieldError(field.id) || getOtherFieldError(field.id)
                                            ? 'border-red-400 bg-red-50/30'
                                            : 'bg-cream-100/50 border-cream-400/60 hover:border-forest-600/40')
                                ]"
                            >
                            <div class="flex items-start justify-between gap-3">
                                <div class="flex items-center space-x-2">
                                    <span class="size-6 rounded-full bg-forest-900 text-emerald-200 text-xs font-bold flex items-center justify-center shrink-0">
                                        {{ field.weight || index + 1 }}
                                    </span>
                                    <label :for="`input_${field.id}`" class="text-sm font-bold text-gray-900">
                                        {{ field.particular }}
                                        <span v-if="field.required" class="text-red-500 font-bold ml-0.5">*</span>
                                        <span v-else class="text-gray-400 font-normal text-xs ml-1">(Optional)</span>
                                    </label>
                                </div>
                                <span class="px-2 py-0.5 rounded text-[10px] font-mono uppercase bg-cream-200 text-gray-600 shrink-0">
                                    {{ field.type }}
                                </span>
                            </div>

                            <!-- Text Input -->
                            <div v-if="field.type === 'text'">
                                <input
                                    :id="`input_${field.id}`"
                                    v-model="answers[field.id]"
                                    type="text"
                                    :required="field.required"
                                    :placeholder="field.placeholder || `Enter ${field.particular}...`"
                                    class="w-full px-3.5 py-2.5 bg-[#fffef9] border border-cream-500 focus:border-forest-600 focus:ring-2 focus:ring-forest-200 rounded-xl text-gray-900 text-xs transition font-medium"
                                />
                            </div>

                            <!-- Textarea Input -->
                            <div v-else-if="field.type === 'textarea'">
                                <textarea
                                    :id="`input_${field.id}`"
                                    v-model="answers[field.id]"
                                    rows="3"
                                    :required="field.required"
                                    :placeholder="field.placeholder || `Enter ${field.particular}...`"
                                    class="w-full px-3.5 py-2.5 bg-[#fffef9] border border-cream-500 focus:border-forest-600 focus:ring-2 focus:ring-forest-200 rounded-xl text-gray-900 text-xs transition font-medium"
                                ></textarea>
                            </div>

                            <!-- Number Input -->
                            <div v-else-if="field.type === 'number'">
                                <input
                                    :id="`input_${field.id}`"
                                    v-model.number="answers[field.id]"
                                    type="number"
                                    :min="field.min !== null && field.min !== undefined ? field.min : undefined"
                                    :max="field.max !== null && field.max !== undefined ? field.max : undefined"
                                    :required="field.required"
                                    :placeholder="field.placeholder || '0'"
                                    class="w-full px-3.5 py-2.5 bg-[#fffef9] border border-cream-500 focus:border-forest-600 focus:ring-2 focus:ring-forest-200 rounded-xl text-gray-900 text-xs transition font-medium"
                                />
                            </div>

                            <!-- Radio Buttons -->
                            <div v-else-if="field.type === 'radio'" class="space-y-3">
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2.5">
                                    <label
                                        v-for="opt in field.options || []"
                                        :key="opt.value"
                                        class="flex items-center space-x-3 p-3 rounded-xl border transition cursor-pointer"
                                        :class="String(answers[field.id]) === String(opt.value) ? 'bg-forest-900 text-white border-forest-950 shadow-xs font-semibold' : 'bg-[#fffef9] text-gray-800 border-cream-400 hover:bg-cream-200/60'"
                                    >
                                        <input
                                            type="radio"
                                            :name="`field_${field.id}`"
                                            :value="opt.value"
                                            v-model="answers[field.id]"
                                            :required="field.required"
                                            class="text-forest-900 focus:ring-forest-500 size-4 shrink-0"
                                        />
                                        <span class="text-xs">{{ opt.label }}</span>
                                    </label>
                                </div>

                                <!-- Conditional "Other" input -->
                                <div v-if="isOtherSelected(field)" class="p-3 bg-amber-50/70 border border-amber-300 rounded-xl space-y-1.5">
                                    <label class="block text-xs font-bold text-amber-900">
                                        Please specify {{ field.particular }} <span v-if="field.required" class="text-red-500">*</span>
                                    </label>
                                    <input
                                        v-model="answers[`${field.id}_other`]"
                                        type="text"
                                        :required="field.required"
                                        placeholder="Please specify details..."
                                        class="w-full px-3.5 py-2 bg-[#fffef9] border border-amber-400 focus:border-forest-600 focus:ring-2 focus:ring-forest-200 rounded-lg text-gray-900 text-xs font-medium"
                                    />
                                    <div v-if="getOtherFieldError(field.id)" class="text-xs text-red-600 font-medium">
                                        {{ getOtherFieldError(field.id) }}
                                    </div>
                                </div>
                            </div>

                            <!-- Select Dropdown or Custom Value Input -->
                            <div v-else-if="field.type === 'select'" class="space-y-3">
                                <div v-if="field.allow_custom_value">
                                    <input
                                        :id="`input_${field.id}`"
                                        v-model="answers[field.id]"
                                        type="text"
                                        :list="`datalist_${field.id}`"
                                        :required="field.required"
                                        :placeholder="field.placeholder || `Select or type ${field.particular}...`"
                                        class="w-full px-3.5 py-2.5 bg-[#fffef9] border border-cream-500 focus:border-forest-600 focus:ring-2 focus:ring-forest-200 rounded-xl text-gray-900 text-xs transition font-medium"
                                    />
                                    <datalist :id="`datalist_${field.id}`">
                                        <option v-for="opt in field.options || []" :key="opt.value" :value="opt.value">
                                            {{ opt.label }}
                                        </option>
                                    </datalist>
                                </div>

                                <div v-else>
                                    <select
                                        :id="`input_${field.id}`"
                                        v-model="answers[field.id]"
                                        :required="field.required"
                                        class="w-full px-3.5 py-2.5 bg-[#fffef9] border border-cream-500 focus:border-forest-600 focus:ring-2 focus:ring-forest-200 rounded-xl text-gray-900 text-xs transition font-medium"
                                    >
                                        <option value="" disabled>-- Select {{ field.particular }} --</option>
                                        <option v-for="opt in field.options || []" :key="opt.value" :value="opt.value">
                                            {{ opt.label }}
                                        </option>
                                    </select>
                                </div>

                                <!-- Conditional "Other" input -->
                                <div v-if="isOtherSelected(field)" class="p-3 bg-amber-50/70 border border-amber-300 rounded-xl space-y-1.5">
                                    <label class="block text-xs font-bold text-amber-900">
                                        Please specify {{ field.particular }} <span v-if="field.required" class="text-red-500">*</span>
                                    </label>
                                    <input
                                        v-model="answers[`${field.id}_other`]"
                                        type="text"
                                        :required="field.required"
                                        placeholder="Please specify details..."
                                        class="w-full px-3.5 py-2 bg-[#fffef9] border border-amber-400 focus:border-forest-600 focus:ring-2 focus:ring-forest-200 rounded-lg text-gray-900 text-xs font-medium"
                                    />
                                    <div v-if="getOtherFieldError(field.id)" class="text-xs text-red-600 font-medium">
                                        {{ getOtherFieldError(field.id) }}
                                    </div>
                                </div>
                            </div>

                            <!-- Checkbox Multi-Choice Tiles -->
                            <div v-else-if="field.type === 'checkbox'" class="space-y-3">
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2.5">
                                    <label
                                        v-for="opt in field.options || []"
                                        :key="opt.value"
                                        class="flex items-center space-x-3 p-3 rounded-xl border transition cursor-pointer"
                                        :class="(answers[field.id] || []).includes(opt.value) ? 'bg-forest-900 text-white border-forest-950 shadow-xs font-semibold' : 'bg-[#fffef9] text-gray-800 border-cream-400 hover:bg-cream-200/60'"
                                    >
                                        <input
                                            type="checkbox"
                                            :value="opt.value"
                                            :checked="(answers[field.id] || []).includes(opt.value)"
                                            @change="handleCheckboxToggle(field.id, opt.value)"
                                            class="rounded text-forest-900 focus:ring-forest-500 size-4 shrink-0"
                                        />
                                        <span class="text-xs">{{ opt.label }}</span>
                                    </label>
                                </div>

                                <!-- Conditional "Other" input -->
                                <div v-if="isOtherSelected(field)" class="p-3 bg-amber-50/70 border border-amber-300 rounded-xl space-y-1.5">
                                    <label class="block text-xs font-bold text-amber-900">
                                        Please specify {{ field.particular }} <span v-if="field.required" class="text-red-500">*</span>
                                    </label>
                                    <input
                                        v-model="answers[`${field.id}_other`]"
                                        type="text"
                                        :required="field.required"
                                        placeholder="Please specify details..."
                                        class="w-full px-3.5 py-2 bg-[#fffef9] border border-amber-400 focus:border-forest-600 focus:ring-2 focus:ring-forest-200 rounded-lg text-gray-900 text-xs font-medium"
                                    />
                                    <div v-if="getOtherFieldError(field.id)" class="text-xs text-red-600 font-medium">
                                        {{ getOtherFieldError(field.id) }}
                                    </div>
                                </div>
                            </div>

                            <!-- Field Validation Error Display -->
                            <div v-if="getFieldError(field.id)" class="text-xs text-red-600 font-medium">
                                {{ getFieldError(field.id) }}
                            </div>
                        </div>
                        </template>
                    </div>
                </div>

                <!-- Submission Validation Errors Bar with Quick Go-To Links -->
                <div
                    v-if="errorFieldsList.length > 0"
                    id="submission_errors_banner"
                    class="p-5 bg-red-50/95 border-2 border-red-300 rounded-3xl shadow-sm space-y-3.5 transition-all"
                >
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                        <div class="flex items-center space-x-2.5 text-red-950 font-bold text-xs sm:text-sm">
                            <span class="size-7 rounded-xl bg-red-200 text-red-900 flex items-center justify-center text-xs shrink-0 font-black shadow-2xs">
                                !
                            </span>
                            <div>
                                <span class="block font-black text-red-900">
                                    Submission Incomplete
                                </span>
                                <span class="text-xs text-red-700 font-medium">
                                    Please review {{ errorFieldsList.length }} field{{ errorFieldsList.length > 1 ? 's' : '' }} with missing or invalid input.
                                </span>
                            </div>
                        </div>

                        <!-- Primary Quick Go-To Action Link -->
                        <button
                            type="button"
                            @click="goToFirstError"
                            class="inline-flex items-center justify-center space-x-1.5 px-4 py-2 bg-red-600 hover:bg-red-700 active:bg-red-800 text-white rounded-xl text-xs font-bold shadow-xs transition cursor-pointer shrink-0"
                        >
                            <span>⚡ Quick Go To First Error</span>
                            <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
                            </svg>
                        </button>
                    </div>

                    <!-- Individual Question Error Quick Jump Chips -->
                    <div class="pt-2.5 border-t border-red-200/80 flex flex-wrap items-center gap-1.5">
                        <span class="text-[11px] font-bold text-red-800 me-1">Jump to field:</span>
                        <button
                            v-for="item in errorFieldsList"
                            :key="item.id"
                            type="button"
                            @click="goToErrorField(item.id)"
                            class="inline-flex items-center space-x-1.5 px-2.5 py-1 bg-white hover:bg-red-100/90 active:bg-red-200 text-red-900 border border-red-300 hover:border-red-400 rounded-lg text-xs font-semibold shadow-2xs transition cursor-pointer"
                            :title="item.message"
                        >
                            <span class="size-4 rounded-full bg-red-100 text-red-800 text-[10px] font-black flex items-center justify-center shrink-0">
                                {{ item.weight || '!' }}
                            </span>
                            <span class="truncate max-w-[160px]">{{ item.particular }}</span>
                            <span class="text-red-500 text-[10px]">↗</span>
                        </button>
                    </div>
                </div>

                <!-- Submit / Navigation Action Bar -->
                <div class="bg-[#fffef9] rounded-3xl p-6 border border-cream-500/70 shadow-sm flex flex-col sm:flex-row items-center justify-between gap-4">
                    <div class="text-xs text-gray-500 text-center sm:text-left">
                        <span>Your responses will be securely recorded under <strong>{{ event?.name }}</strong>.</span>
                    </div>

                    <div class="flex items-center space-x-3 w-full sm:w-auto">
                        <button
                            v-if="isPaginated && pageHistory.length > 1"
                            type="button"
                            @click="goToPreviousPage"
                            class="flex-1 sm:flex-initial text-center px-5 py-2.5 bg-cream-100 hover:bg-cream-200 text-gray-700 text-xs font-bold rounded-xl border border-cream-400 transition cursor-pointer"
                        >
                            ← Back
                        </button>

                        <button
                            v-if="isPaginated && !isLastPage"
                            type="button"
                            @click="goToNextPage"
                            class="flex-1 sm:flex-initial inline-flex items-center justify-center px-7 py-2.5 bg-forest-900 hover:bg-forest-950 text-white text-xs font-bold rounded-xl shadow-md transition cursor-pointer"
                        >
                            <span>Next Page →</span>
                        </button>

                        <button
                            v-else
                            type="submit"
                            :disabled="isSubmitting"
                            class="flex-1 sm:flex-initial inline-flex items-center justify-center px-7 py-2.5 bg-forest-900 hover:bg-forest-950 disabled:opacity-50 text-white text-xs font-bold rounded-xl shadow-md transition cursor-pointer"
                        >
                            <svg v-if="isSubmitting" class="animate-spin -ms-1 me-2 size-4 text-white" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                            <span>{{ isSubmitting ? 'Submitting Responses...' : 'Submit Feedback' }}</span>
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <!-- Floating Quick Go-To Link Toast for Submission Errors -->
        <transition
            enter-active-class="transition duration-300 ease-out"
            enter-from-class="transform translate-y-6 opacity-0"
            enter-to-class="transform translate-y-0 opacity-100"
            leave-active-class="transition duration-200 ease-in"
            leave-from-class="transform translate-y-0 opacity-100"
            leave-to-class="transform translate-y-6 opacity-0"
        >
            <div
                v-if="showQuickErrorToast && errorFieldsList.length > 0"
                class="fixed bottom-6 right-6 z-50 max-w-sm sm:max-w-md bg-white border-2 border-red-400 rounded-2xl shadow-2xl p-3.5 flex items-center space-x-3 text-xs"
            >
                <div class="size-9 rounded-xl bg-red-100 text-red-700 flex items-center justify-center shrink-0 text-base font-bold shadow-2xs">
                    ⚠️
                </div>
                <div class="flex-1 min-w-0">
                    <p class="font-bold text-gray-900 leading-tight">
                        {{ errorFieldsList.length }} field{{ errorFieldsList.length > 1 ? 's have' : ' has' }} submission error{{ errorFieldsList.length > 1 ? 's' : '' }}
                    </p>
                    <p class="text-[11px] text-gray-500 truncate">
                        Click to jump and highlight error field
                    </p>
                </div>
                <button
                    type="button"
                    @click="goToNextError"
                    class="px-3 py-1.5 bg-red-600 hover:bg-red-700 text-white font-bold rounded-xl shadow-xs transition shrink-0 cursor-pointer flex items-center space-x-1"
                >
                    <span>Go to error</span>
                    <span>→</span>
                </button>
                <button
                    type="button"
                    @click="showQuickErrorToast = false"
                    class="text-gray-400 hover:text-gray-600 font-bold p-1 cursor-pointer shrink-0"
                    title="Dismiss"
                >
                    ✕
                </button>
            </div>
        </transition>
    </div>
</template>

<script setup>
import { ref, computed, watch, onBeforeUnmount } from 'vue';
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import InputError from '@/Components/InputError.vue';
import Turnstile from '@/Components/Turnstile.vue';
import { sanitizeHtml, stripHtml } from '@/Utils/htmlSanitizer';

const props = defineProps({
    event: {
        type: Object,
        default: null,
    },
    form: {
        type: Object,
        default: null,
    },
    functions: {
        type: Array,
        default: () => [],
    },
    lookups: {
        type: Object,
        default: () => ({
            agencies: [],
            designations: [],
        }),
    },
    apiKey: {
        type: String,
        required: true,
    },
    turnstileSiteKey: {
        type: String,
        default: '',
    },
    isAvailable: {
        type: Boolean,
        default: true,
    },
    unavailableReason: {
        type: String,
        default: '',
    },
    unavailableMessage: {
        type: String,
        default: '',
    },
});

// Dynamic fields sorted strictly by weight ascending
const sortedFields = computed(() => {
    if (!props.form?.schema?.fields || !Array.isArray(props.form.schema.fields)) {
        return [];
    }
    return [...props.form.schema.fields].sort((a, b) => (Number(a.weight) || 0) - (Number(b.weight) || 0));
});

const isFormAvailable = computed(() => {
    if (props.isAvailable === false) {
        return false;
    }
    if (!props.event || !props.form || !sortedFields.value.length) {
        return false;
    }
    return true;
});

// Dynamic form answers dictionary keyed by field.id
const answers = ref({});

// Cloudflare Turnstile verification state
const turnstileToken = ref('');
const turnstileRef = ref(null);

// Participant metadata fields (optional for respondent identification)
const participantData = ref({
    name: '',
    agency: '',
    designation: '',
    function_id: '',
    custom_function: '',
    years_in_designation: '',
    location: '',
    no_of_exercises: '',
});

// Initialize answers dictionary from dynamic schema fields
const initAnswers = () => {
    const initial = {};
    sortedFields.value.forEach((field) => {
        if (['section', 'text_only'].includes(field.type)) {
            return;
        }
        if (field.type === 'checkbox') {
            initial[field.id] = [];
        } else {
            initial[field.id] = '';
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
                clientErrors.value[field.id] = `The '${stripHtml(field.particular)}' field is required.`;
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

// Quick go-to error navigation & temporary highlight state
const highlightedFieldId = ref(null);
let highlightTimer = null;
const showQuickErrorToast = ref(false);
let quickErrorToastTimer = null;
let currentErrorIndex = 0;

const turnstileError = computed(() => {
    return clientErrors.value.turnstile_token
        || serverErrors.value.turnstile_token?.[0]
        || serverErrors.value['cf-turnstile-response']?.[0]
        || null;
});

watch(turnstileToken, (token) => {
    if (token) {
        delete clientErrors.value.turnstile_token;
        delete serverErrors.value.turnstile_token;
        delete serverErrors.value['cf-turnstile-response'];
    }
});

// Extract list of fields with validation errors
const errorFieldsList = computed(() => {
    const list = [];
    const seenFieldIds = new Set();
    const errorKeys = Object.keys(serverErrors.value).filter((k) => k !== 'general');

    errorKeys.forEach((key) => {
        let fieldId = key;
        if (fieldId === 'cf-turnstile-response') {
            fieldId = 'turnstile_token';
        } else if (fieldId.startsWith('answers.')) {
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
            if (fieldId === 'turnstile_token') {
                list.push({
                    id: 'turnstile_token',
                    particular: 'Security Verification',
                    weight: null,
                    message: turnstileError.value || 'Please complete the security verification.',
                    isOther: false,
                });
                return;
            }

            const field = sortedFields.value.find((f) => String(f.id) === String(fieldId));
            if (['section', 'text_only'].includes(field?.type)) {
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

    if (isPaginated.value && fieldId !== 'turnstile_token') {
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

// Determine if the currently selected value for a field is marked as an "other" option
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

// Live deployed form submission
const submitForm = async () => {
    isSubmitting.value = true;
    serverErrors.value = {};
    showQuickErrorToast.value = false;

    const payload = {
        answers: { ...answers.value },
        ...participantData.value,
        turnstile_token: turnstileToken.value,
    };

    try {
        const response = await axios.post(route('forms.live.submit', props.apiKey), payload);
        if (response.status === 201 || response.status === 200) {
            submissionSuccess.value = true;
            submittedRecord.value = response.data?.data || response.data || null;
            turnstileToken.value = '';
            serverErrors.value = {};
            showQuickErrorToast.value = false;
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }
    } catch (error) {
        // Clear spent/invalid single-use token and reset widget for a fresh challenge
        turnstileToken.value = '';
        turnstileRef.value?.reset();

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

const resetForAnotherSubmission = () => {
    submissionSuccess.value = false;
    submittedRecord.value = null;
    serverErrors.value = {};
    clientErrors.value = {};
    showQuickErrorToast.value = false;
    highlightedFieldId.value = null;
    currentPageIdx.value = 0;
    pageHistory.value = [0];
    turnstileToken.value = '';
    turnstileRef.value?.reset();
    initAnswers();
    participantData.value = {
        name: '',
        agency: '',
        designation: '',
        function_id: '',
        custom_function: '',
        years_in_designation: '',
        location: '',
        no_of_exercises: '',
    };
    window.scrollTo({ top: 0, behavior: 'smooth' });
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

onBeforeUnmount(() => {
    if (highlightTimer) {
        clearTimeout(highlightTimer);
    }
    if (quickErrorToastTimer) {
        clearTimeout(quickErrorToastTimer);
    }
});
</script>

<template>
    <div class="min-h-screen bg-cream-300 text-gray-900 font-sans antialiased selection:bg-forest-900 selection:text-white flex flex-col justify-between py-6 px-4 sm:px-6 lg:px-8">
        <Head :title="event?.name ? `${event.name} - Feedback Form` : 'Live Evaluation Feedback Form'" />

        <div class="w-full max-w-3xl mx-auto space-y-6">
            <!-- Portal Brand Header -->
            <header class="flex items-center justify-between border-b border-cream-500/60 pb-4">
                <div class="flex items-center space-x-3">
                    <img
                        v-if="$page.props.system_appearance?.logo_url"
                        :src="$page.props.system_appearance.logo_url"
                        :alt="$page.props.system_appearance?.name || 'System Logo'"
                        class="h-10 w-auto object-contain"
                    />
                    <div v-else class="size-10 rounded-xl bg-forest-900 text-emerald-200 flex items-center justify-center font-black text-lg shadow-sm">
                        <svg class="size-6 text-emerald-200" viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M11.395 44.428C4.557 40.198 0 32.632 0 24 0 10.745 10.745 0 24 0a23.891 23.891 0 0113.997 4.502c-.2 17.907-11.097 33.245-26.602 39.926z" fill="currentColor" />
                            <path d="M14.134 45.885A23.914 23.914 0 0024 48c13.255 0 24-10.745 24-24 0-3.516-.756-6.856-2.115-9.866-4.659 15.143-16.608 27.092-31.75 31.751z" fill="currentColor" />
                        </svg>
                    </div>
                    <div>
                        <h1 class="text-base font-black text-forest-950 leading-tight">
                            {{ $page.props.system_appearance?.name || 'Local Government Portal' }}
                        </h1>
                        <p class="text-xs text-gray-600 font-medium">Citizen & Participant Evaluation Service</p>
                    </div>
                </div>

                <div class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-emerald-100 text-emerald-800 border border-emerald-200 shadow-2xs">
                    <span class="size-2 rounded-full bg-emerald-500 mr-2 animate-pulse"></span>
                    Live Form
                </div>
            </header>

            <!-- Success State View -->
            <div v-if="submissionSuccess" class="bg-[#fffef9] rounded-3xl p-8 sm:p-12 text-center border border-cream-500/70 shadow-lg space-y-5 animate-fade-in">
                <div class="size-20 rounded-full bg-emerald-100 text-emerald-700 flex items-center justify-center mx-auto shadow-inner">
                    <svg class="size-10" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                    </svg>
                </div>

                <div class="space-y-2">
                    <h2 class="text-2xl sm:text-3xl font-black text-gray-900 tracking-tight">
                        Feedback Submitted!
                    </h2>
                    <p class="text-sm text-gray-600 max-w-md mx-auto leading-relaxed">
                        Thank you for taking the time to share your feedback for <strong>{{ event?.name }}</strong>. Your responses have been safely and anonymously recorded.
                    </p>
                </div>

                <div v-if="submittedRecord?.id" class="inline-block px-4 py-2 bg-cream-100 rounded-xl border border-cream-400 text-xs font-mono text-gray-800 shadow-2xs">
                    Submission Reference: #{{ submittedRecord.id }}
                </div>

                <div class="pt-4">
                    <button
                        type="button"
                        @click="resetForAnotherSubmission"
                        class="px-5 py-2.5 bg-forest-900 hover:bg-forest-950 text-white text-xs font-bold rounded-xl shadow-xs transition hover:shadow-md cursor-pointer"
                    >
                        Submit Another Response
                    </button>
                </div>
            </div>

            <!-- Not Available / Draft / Unavailable State -->
            <div v-else-if="!isFormAvailable" class="bg-[#fffef9] rounded-3xl p-8 sm:p-12 text-center border border-cream-400/80 shadow-sm space-y-4 animate-fade-in max-w-lg mx-auto">
                <div class="size-16 rounded-2xl bg-amber-100 text-amber-800 flex items-center justify-center mx-auto shadow-inner">
                    <svg class="size-8" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />
                    </svg>
                </div>
                <div class="space-y-2">
                    <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-[11px] font-black uppercase tracking-wider bg-amber-200/80 text-amber-900 border border-amber-300">
                        <span class="size-2 rounded-full bg-amber-500 animate-pulse"></span>
                        Not Available
                    </span>
                    <h2 class="text-xl sm:text-2xl font-black text-forest-950 tracking-tight">
                        {{ event?.name || 'Feedback Form Not Available' }}
                    </h2>
                    <p class="text-xs sm:text-sm text-gray-600 max-w-md mx-auto leading-relaxed">
                        {{ unavailableMessage || (unavailableReason === 'draft'
                            ? 'This feedback form is currently unpublished or still saved as draft and is not accepting responses at this time.'
                            : 'This evaluation feedback form is currently inactive, has reached its conclusion, or has not yet been configured by the system administrator.') }}
                    </p>
                </div>
                <div v-if="unavailableReason === 'draft'" class="pt-2 text-[11px] text-amber-800/80 font-mono bg-amber-50/60 py-1.5 px-3 rounded-lg border border-amber-200/60 inline-block">
                    Status: Unpublished / Draft Mode
                </div>
            </div>

            <!-- Active Questionnaire Form -->
            <form v-else @submit.prevent="isPaginated && !isLastPage ? goToNextPage() : submitForm()" class="space-y-6">
                <!-- Admin Draft Notice Banner -->
                <div v-if="form?.is_draft" class="p-4 bg-amber-50 border-2 border-amber-300 text-amber-950 rounded-2xl text-xs font-bold flex items-center justify-between shadow-xs">
                    <div class="flex items-center space-x-2">
                        <span class="size-6 rounded-lg bg-amber-200 text-amber-900 flex items-center justify-center text-xs font-black shrink-0">⚠️</span>
                        <span>Draft Preview Mode: This questionnaire is currently in Draft (Unpublished) mode. Public respondents cannot access or submit responses until it is published.</span>
                    </div>
                    <span class="px-2 py-0.5 rounded text-[10px] font-mono uppercase bg-amber-200 text-amber-900 font-extrabold shrink-0">Draft</span>
                </div>

                <!-- General Error Alert -->
                <div v-if="serverErrors.general" class="p-4 bg-red-50 border border-red-200 text-red-800 rounded-2xl text-xs font-medium flex items-center shadow-xs">
                    <svg class="size-5 me-2 shrink-0 text-red-600" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" />
                    </svg>
                    <span>{{ serverErrors.general[0] }}</span>
                </div>

                <!-- Form Card -->
                <div class="bg-[#fffef9] border border-cream-500/70 rounded-3xl p-6 sm:p-8 shadow-sm space-y-6">
                    <!-- Event / Form Header -->
                    <div class="border-b border-cream-400/60 pb-5 space-y-2">
                        <span class="px-2.5 py-1 rounded-full text-[10px] font-bold uppercase tracking-wider bg-cream-200 text-forest-900 border border-cream-400/60">
                            Evaluation Questionnaire
                        </span>
                        <h2 class="text-xl sm:text-2xl font-black text-forest-950 tracking-tight form-rich-content" v-html="sanitizeHtml(form.schema?.title || event.name)"></h2>
                        <div v-if="form.schema?.description || event.details" class="text-xs sm:text-sm text-gray-600 leading-relaxed font-medium form-rich-content" v-html="sanitizeHtml(form.schema?.description || event.details)"></div>
                    </div>

                    <!-- Pagination Step Indicator / Progress Bar -->
                    <div v-if="isPaginated" class="space-y-2.5 pt-1">
                        <div class="flex items-center justify-between text-xs font-bold text-gray-700">
                            <span class="flex items-center space-x-2">
                                <span class="size-5 rounded-full bg-forest-900 text-emerald-200 text-[10px] flex items-center justify-center font-bold shrink-0">
                                    {{ currentPageIdx + 1 }}
                                </span>
                                <span class="flex items-center space-x-1.5 flex-wrap">
                                    <span>Section {{ currentPageIdx + 1 }} of {{ sectionPages.length }}:</span>
                                    <span class="form-rich-content inline" v-html="sanitizeHtml(currentPage?.section?.particular || 'Evaluation Questions')"></span>
                                </span>
                            </span>
                            <span class="font-mono text-gray-500 text-[11px]">
                                {{ Math.round(((currentPageIdx + 1) / sectionPages.length) * 100) }}%
                            </span>
                        </div>
                        <div v-if="currentPage?.section?.description" class="text-xs text-gray-600 leading-relaxed font-medium form-rich-content pl-7" v-html="sanitizeHtml(currentPage.section.description)"></div>
                        <div class="w-full bg-cream-200 rounded-full h-2 overflow-hidden border border-cream-400">
                            <div
                                class="bg-forest-900 h-2 rounded-full transition-all duration-300"
                                :style="{ width: `${((currentPageIdx + 1) / sectionPages.length) * 100}%` }"
                            ></div>
                        </div>
                    </div>

                    <!-- Questions Container -->
                    <div class="space-y-6 pt-2">
                        <!-- Questions Loop -->
                        <template v-for="(field, index) in (isPaginated ? (currentPage?.fields || []) : sortedFields)" :key="field.id">
                            <!-- Section Divider Header -->
                            <div
                                v-if="field.type === 'section'"
                                :id="`field_container_${field.id}`"
                                class="pt-6 pb-2 border-b-2 border-forest-900/15 first:pt-0"
                            >
                                <div>
                                    <h3 class="text-base sm:text-lg font-black text-forest-950 tracking-tight form-rich-content" v-html="sanitizeHtml(field.particular)"></h3>
                                    <div v-if="field.description" class="text-xs text-gray-600 mt-1 leading-relaxed font-medium form-rich-content" v-html="sanitizeHtml(field.description)"></div>
                                </div>
                            </div>

                            <!-- Text Only Instructional Section -->
                            <div
                                v-else-if="field.type === 'text_only' && isFieldConditionMet(field)"
                                :id="`field_container_${field.id}`"
                                class="p-4 sm:p-5 rounded-2xl bg-cream-100/80 border border-cream-400/70 space-y-2"
                            >
                                <h4 class="text-sm sm:text-base font-bold text-forest-950 form-rich-content" v-html="sanitizeHtml(field.particular)"></h4>
                                <div v-if="field.description" class="text-xs text-gray-700 leading-relaxed form-rich-content overflow-x-auto" v-html="sanitizeHtml(field.description)"></div>
                            </div>

                            <!-- Regular Question Input Tile -->
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
                                    <div class="flex-1">
                                        <label :for="`input_${field.id}`" class="text-sm font-bold text-gray-900 block">
                                            <span class="form-rich-content inline" v-html="sanitizeHtml(field.particular)"></span>
                                            <span v-if="field.required" class="text-red-500 font-bold ml-0.5">*</span>
                                            <span v-else class="text-gray-400 font-normal text-xs ml-1">(Optional)</span>
                                        </label>
                                        <div v-if="field.description" class="text-xs text-gray-600 mt-1 form-rich-content" v-html="sanitizeHtml(field.description)"></div>
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
                                        :placeholder="field.placeholder || 'Type your answer here...'"
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
                                        :placeholder="field.placeholder || 'Type your response here...'"
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

                                <!-- Date Input -->
                                <div v-else-if="field.type === 'date'">
                                    <input
                                        :id="`input_${field.id}`"
                                        v-model="answers[field.id]"
                                        type="date"
                                        :required="field.required"
                                        class="w-full sm:w-64 px-3.5 py-2.5 bg-[#fffef9] border border-cream-500 focus:border-forest-600 focus:ring-2 focus:ring-forest-200 rounded-xl text-gray-900 text-xs transition font-medium"
                                    />
                                </div>

                                <!-- Radio Buttons (Choice Tiles) -->
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

                                    <!-- If allow_other is true and an "Other" option is selected -->
                                    <div v-if="isOtherSelected(field)" class="p-3 bg-amber-50/70 border border-amber-300 rounded-xl space-y-1.5">
                                        <label class="block text-xs font-bold text-amber-900">
                                            Please specify <span class="form-rich-content inline" v-html="sanitizeHtml(field.particular)"></span> <span v-if="field.required" class="text-red-500">*</span>
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

                                <!-- Select Dropdown or Datalist -->
                                <div v-else-if="field.type === 'select'" class="space-y-3">
                                    <div v-if="field.allow_custom_value">
                                        <input
                                            :id="`input_${field.id}`"
                                            v-model="answers[field.id]"
                                            type="text"
                                            :list="`datalist_${field.id}`"
                                            :required="field.required"
                                            :placeholder="field.placeholder || 'Select or type an option...'"
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
                                            <option value="" disabled>-- Select an option --</option>
                                            <option v-for="opt in field.options || []" :key="opt.value" :value="opt.value">
                                                {{ opt.label }}
                                            </option>
                                        </select>
                                    </div>

                                    <!-- If allow_other is true and an "Other" option is selected -->
                                    <div v-if="isOtherSelected(field)" class="p-3 bg-amber-50/70 border border-amber-300 rounded-xl space-y-1.5">
                                        <label class="block text-xs font-bold text-amber-900">
                                            Please specify <span class="form-rich-content inline" v-html="sanitizeHtml(field.particular)"></span> <span v-if="field.required" class="text-red-500">*</span>
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

                                    <!-- If allow_other is true and an "Other" option is selected -->
                                    <div v-if="isOtherSelected(field)" class="p-3 bg-amber-50/70 border border-amber-300 rounded-xl space-y-1.5">
                                        <label class="block text-xs font-bold text-amber-900">
                                            Please specify <span class="form-rich-content inline" v-html="sanitizeHtml(field.particular)"></span> <span v-if="field.required" class="text-red-500">*</span>
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

                                <!-- Field Error Alert -->
                                <div v-if="getFieldError(field.id)" class="text-xs text-red-600 font-medium flex items-center space-x-1">
                                    <svg class="size-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" />
                                    </svg>
                                    <span>{{ getFieldError(field.id) }}</span>
                                </div>
                            </div>
                        </template>
                    </div>

                    <!-- Cloudflare Turnstile Security Verification -->
                    <div
                        id="field_container_turnstile_token"
                        class="p-4 sm:p-5 rounded-2xl border space-y-2.5 transition-all duration-300"
                        :class="[
                            highlightedFieldId === 'turnstile_token'
                                ? 'ring-4 ring-red-500/80 ring-offset-2 border-red-500 bg-red-50/90 shadow-xl'
                                : (turnstileError
                                    ? 'border-red-400 bg-red-50/30'
                                    : 'bg-cream-100/50 border-cream-400/60')
                        ]"
                    >
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-xs font-bold text-gray-800">
                                Security Verification <span class="text-red-500 font-bold">*</span>
                            </span>
                            <span class="px-2 py-0.5 rounded text-[10px] font-mono uppercase bg-cream-200 text-gray-600">
                                Turnstile
                            </span>
                        </div>

                        <Turnstile
                            ref="turnstileRef"
                            v-model="turnstileToken"
                            :site-key="turnstileSiteKey"
                            action="feedback_submit"
                        />

                        <InputError :message="turnstileError" class="text-xs font-medium" />
                    </div>

                    <!-- Navigation & Action Buttons -->
                    <div class="border-t border-cream-400/60 pt-5 flex items-center justify-between gap-4">
                        <div>
                            <button
                                v-if="isPaginated && currentPageIdx > 0"
                                type="button"
                                @click="goToPreviousPage"
                                class="px-5 py-2.5 bg-cream-200 hover:bg-cream-300 text-gray-800 text-xs font-bold rounded-xl border border-cream-400 transition cursor-pointer"
                            >
                                ← Previous Section
                            </button>
                        </div>

                        <div>
                            <button
                                v-if="isPaginated && !isLastPage"
                                type="button"
                                @click="goToNextPage"
                                class="px-6 py-2.5 bg-forest-900 hover:bg-forest-950 text-white text-xs font-bold rounded-xl shadow-xs transition hover:shadow-md cursor-pointer"
                            >
                                Next Section →
                            </button>

                            <button
                                v-else
                                type="submit"
                                :disabled="isSubmitting"
                                class="px-7 py-2.5 bg-forest-900 hover:bg-forest-950 text-white text-xs font-black rounded-xl shadow-xs transition hover:shadow-md flex items-center space-x-2 disabled:opacity-50 cursor-pointer"
                            >
                                <svg v-if="isSubmitting" class="animate-spin -ml-1 mr-2 size-4 text-white" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                                <span>{{ isSubmitting ? 'Submitting Responses...' : 'Submit Evaluation' }}</span>
                            </button>
                        </div>
                    </div>
                </div>
            </form>
        </div>

        <!-- Floating Quick Error Jump Toast -->
        <transition
            enter-active-class="transform ease-out duration-300 transition"
            enter-from-class="translate-y-2 opacity-0 sm:translate-y-0 sm:translate-x-2"
            enter-to-class="translate-y-0 opacity-100 sm:translate-x-0"
            leave-active-class="transition ease-in duration-200"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div
                v-if="showQuickErrorToast && errorFieldsList.length > 0"
                class="fixed bottom-6 right-6 z-50 max-w-sm w-full bg-white rounded-2xl shadow-2xl border-2 border-red-500 p-4 space-y-2.5"
            >
                <div class="flex items-start justify-between gap-3">
                    <div class="flex items-center space-x-2 text-red-700 font-bold text-xs">
                        <span class="flex size-5 rounded-full bg-red-100 items-center justify-center text-red-600 font-bold text-[10px]">!</span>
                        <span>{{ errorFieldsList.length }} Question{{ errorFieldsList.length > 1 ? 's' : '' }} Require Attention</span>
                    </div>
                    <button
                        type="button"
                        @click="showQuickErrorToast = false"
                        class="text-gray-400 hover:text-gray-600 text-xs font-bold cursor-pointer"
                    >
                        ✕
                    </button>
                </div>
                <p class="text-[11px] text-gray-600 leading-normal">
                    Some mandatory fields were missed or contain invalid data. Click below to inspect.
                </p>
                <div class="flex items-center space-x-2 pt-1">
                    <button
                        type="button"
                        @click="goToNextError"
                        class="flex-1 py-1.5 px-3 bg-red-600 hover:bg-red-700 text-white text-[11px] font-bold rounded-lg transition cursor-pointer text-center"
                    >
                        Jump to Next Error →
                    </button>
                </div>
            </div>
        </transition>

        <!-- Footer -->
        <footer class="mt-8 text-center text-xs text-gray-500 font-medium">
            <p>Protected by Portal Security & Data Privacy Guidelines</p>
        </footer>
    </div>
</template>

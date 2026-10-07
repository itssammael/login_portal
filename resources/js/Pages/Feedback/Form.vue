<script setup>
import { ref, computed, watch } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    event: {
        type: Object,
        default: null,
    },
    form: {
        type: Object,
        default: null,
    },
    userDefaults: {
        type: Object,
        default: () => ({}),
    },
});

// Dynamic fields sorted strictly by weight ascending
const sortedFields = computed(() => {
    if (!props.form?.schema?.fields || !Array.isArray(props.form.schema.fields)) {
        return [];
    }
    return [...props.form.schema.fields].sort((a, b) => (Number(a.weight) || 0) - (Number(b.weight) || 0));
});

// Dynamic form answers dictionary keyed by field.id
const answers = ref({});

// Initialize answers dictionary from dynamic schema fields
const initAnswers = () => {
    const initial = {};
    sortedFields.value.forEach((field) => {
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

    highlightField(fieldId);

    const containerEl = document.getElementById(`field_container_${fieldId}`);
    if (containerEl) {
        containerEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    const inputEl = document.getElementById(`input_${fieldId}`)
        || containerEl?.querySelector('input:not([type=hidden]), textarea, select, [tabindex="0"]');

    if (inputEl) {
        setTimeout(() => {
            try {
                inputEl.focus();
            } catch (e) {
                // Ignore focus errors
            }
        }, 350);
    }
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

// Generic dynamic form submission
const submitForm = async () => {
    isSubmitting.value = true;
    serverErrors.value = {};
    showQuickErrorToast.value = false;

    const payload = {
        event_id: props.event?.id || null,
        feedback_id: props.form?.id || null,
        answers: { ...answers.value },
    };

    try {
        const response = await axios.post(route('feedback.submit'), payload);
        if (response.status === 201 || response.status === 200) {
            submissionSuccess.value = true;
            submittedRecord.value = response.data?.data || null;
            serverErrors.value = {};
            showQuickErrorToast.value = false;
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

const resetForAnotherSubmission = () => {
    submissionSuccess.value = false;
    submittedRecord.value = null;
    serverErrors.value = {};
    showQuickErrorToast.value = false;
    highlightedFieldId.value = null;
    initAnswers();
};

const getFieldError = (fieldId) => {
    return serverErrors.value[`answers.${fieldId}`]?.[0]
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
    <AppLayout title="Evaluation Feedback Form">
        <template #header>
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="text-xl font-bold text-gray-900 tracking-tight">
                        Evaluation Feedback Form
                    </h2>
                    <p class="text-xs text-gray-700 mt-0.5 font-medium">
                        {{ event?.name ? `Evaluation feedback for ${event.name}` : 'System Evaluation & Activity Feedback' }}
                    </p>
                </div>
            </div>
        </template>

        <div class="py-8">
            <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
                <!-- Unavailable / Empty State -->
                <div v-if="!event || !form || !sortedFields.length" class="bg-cream-200 rounded-3xl p-12 text-center border border-cream-500/50 shadow-sm">
                    <div class="size-16 rounded-full bg-cream-100 text-gray-400 flex items-center justify-center mx-auto mb-4">
                        <svg class="size-8" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" />
                        </svg>
                    </div>
                    <h3 class="text-base font-bold text-gray-900">No Active Feedback Form Available</h3>
                    <p class="text-xs text-gray-600 max-w-sm mx-auto mt-1">
                        There is currently no active feedback questionnaire configured for this system. Please check back later or contact your administrator.
                    </p>
                    <Link
                        :href="route('dashboard')"
                        class="mt-6 inline-flex items-center px-4 py-2 bg-forest-900 hover:bg-forest-950 text-white text-xs font-semibold rounded-xl shadow-xs transition"
                    >
                        Return to Dashboard
                    </Link>
                </div>

                <!-- Schema-Driven Form Container -->
                <form v-else @submit.prevent="submitForm" class="space-y-6">
                    <!-- General Error Alert -->
                    <div v-if="serverErrors.general" class="p-4 bg-red-50 border border-red-200 text-red-800 rounded-2xl text-xs font-medium flex items-center shadow-xs">
                        <svg class="size-5 me-2 shrink-0 text-red-600" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" />
                        </svg>
                        <span>{{ serverErrors.general[0] }}</span>
                    </div>

                    <!-- Event Banner Card -->
                    <div
                        class="bg-forest-900 rounded-3xl p-6 sm:p-8 text-white shadow-lg border border-forest-800 relative overflow-hidden"
                        style="background-color: #1b4332; color: #ffffff;"
                    >
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <span
                                    class="inline-flex items-center px-3 py-1 rounded-lg text-[11px] font-black uppercase tracking-wider shadow-xs mb-3"
                                    style="background-color: #fde047; color: #1b4332;"
                                >
                                    Active Evaluation
                                </span>
                                <h1
                                    class="text-2xl sm:text-3xl font-black tracking-tight"
                                    style="color: #ffffff;"
                                >
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
                        </div>
                    </div>

                    <!-- Schema Fields Card -->
                    <div class="bg-[#fffef9] rounded-3xl p-6 sm:p-8 border border-cream-500/70 shadow-sm space-y-6">
                        <div class="border-b border-cream-400/50 pb-3">
                            <h3 class="text-base font-bold text-gray-900">
                                Feedback Questionnaire
                            </h3>
                            <p class="text-xs text-gray-500 mt-0.5">Please provide your honest answers and ratings for each item below.</p>
                        </div>

                        <div class="space-y-6">
                            <!-- Iterating over schema.fields ordered by weight -->
                            <div
                                v-for="(field, index) in sortedFields"
                                :key="field.id"
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

                                    <!-- If allow_other is true and an "Other" option is selected -->
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

                                    <!-- If allow_other is true and an "Other" option is selected in checkboxes -->
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

                    <!-- Submission Action Bar -->
                    <div class="bg-[#fffef9] rounded-3xl p-6 border border-cream-500/70 shadow-sm flex flex-col sm:flex-row items-center justify-between gap-4">
                        <div class="text-xs text-gray-500 text-center sm:text-left">
                            <span>Your submission will be securely recorded under <strong>{{ event.name }}</strong>.</span>
                        </div>

                        <div class="flex items-center space-x-3 w-full sm:w-auto">
                            <Link
                                :href="route('dashboard')"
                                class="flex-1 sm:flex-initial text-center px-4 py-2.5 bg-cream-100 hover:bg-cream-200 text-gray-700 text-xs font-semibold rounded-xl border border-cream-400 transition"
                            >
                                Cancel
                            </Link>

                            <button
                                type="submit"
                                :disabled="isSubmitting"
                                class="flex-1 sm:flex-initial inline-flex items-center justify-center px-6 py-2.5 bg-forest-900 hover:bg-forest-950 disabled:opacity-50 text-white text-xs font-bold rounded-xl shadow-md transition cursor-pointer"
                            >
                                <svg v-if="isSubmitting" class="animate-spin -ms-1 me-2 size-4 text-white" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                                <span>{{ isSubmitting ? 'Submitting Feedback...' : 'Submit Evaluation Feedback' }}</span>
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Success Modal -->
        <div v-if="submissionSuccess" class="fixed inset-0 z-50 overflow-y-auto bg-gray-900/60 backdrop-blur-xs flex items-center justify-center p-4">
            <div class="bg-cream-100 rounded-3xl max-w-md w-full shadow-2xl p-8 border border-cream-500 text-center space-y-4">
                <div class="size-16 rounded-full bg-emerald-100 text-emerald-700 flex items-center justify-center mx-auto shadow-inner">
                    <svg class="size-8" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                    </svg>
                </div>

                <h3 class="text-xl font-black text-gray-900">
                    Feedback Submitted!
                </h3>

                <p class="text-xs text-gray-600 leading-relaxed">
                    Thank you for your valuable evaluation and feedback. Your responses have been successfully recorded.
                </p>

                <div v-if="submittedRecord?.id" class="p-3 bg-[#fffef9] rounded-xl border border-cream-400 text-xs font-mono text-gray-600">
                    Submission Reference: #{{ submittedRecord.id }}
                </div>

                <div class="pt-4 flex flex-col sm:flex-row gap-2.5">
                    <button
                        type="button"
                        @click="resetForAnotherSubmission"
                        class="flex-1 px-4 py-2.5 bg-cream-200 hover:bg-cream-300 text-gray-800 text-xs font-semibold rounded-xl transition cursor-pointer"
                    >
                        Submit Another Response
                    </button>
                    <Link
                        :href="route('dashboard')"
                        class="flex-1 px-4 py-2.5 bg-forest-900 hover:bg-forest-950 text-white text-xs font-semibold rounded-xl shadow-xs transition"
                    >
                        Return to Dashboard
                    </Link>
                </div>
            </div>
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
    </AppLayout>
</template>

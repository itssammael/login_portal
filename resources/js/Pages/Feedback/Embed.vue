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
});

// Sorted schema fields by weight ascending
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

onMounted(() => {
    // Notify parent frame that embedded form is ready
    try {
        if (window.parent && window.parent !== window) {
            window.parent.postMessage({
                type: 'feedback-embed-ready',
                publicId: props.publicId,
                height: document.body.scrollHeight,
            }, '*');
        }
    } catch (e) {
        // Suppress cross-origin frame access warnings
    }
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

    const payload = {
        token: props.sessionToken,
        answers: { ...answers.value },
    };

    try {
        const response = await axios.post(route('feedback.embed.submit', props.publicId), payload);
        if (response.status === 201 || response.status === 200) {
            submissionSuccess.value = true;
            submittedRecord.value = response.data?.data || null;

            // Notify parent iframe container
            try {
                if (window.parent && window.parent !== window) {
                    window.parent.postMessage({
                        type: 'feedback-submitted',
                        publicId: props.publicId,
                        submissionId: response.data?.data?.id || null,
                    }, '*');
                }
            } catch (e) {
                // Cross-origin safe
            }
        }
    } catch (error) {
        if (error.response && error.response.status === 422) {
            serverErrors.value = error.response.data.errors || {};
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
            <form v-else @submit.prevent="submitForm" class="space-y-6">
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
                    <div class="border-b border-cream-400/50 pb-3">
                        <h3 class="text-base font-bold text-gray-900">
                            Questionnaire
                        </h3>
                        <p class="text-xs text-gray-500 mt-0.5">Please provide your honest answers and ratings for each item below.</p>
                    </div>

                    <div class="space-y-6">
                        <!-- Dynamic Field Loop -->
                        <div
                            v-for="(field, index) in sortedFields"
                            :key="field.id"
                            :id="`field_container_${field.id}`"
                            class="p-5 rounded-2xl bg-cream-100/50 border border-cream-400/60 space-y-3 transition hover:border-forest-600/40"
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
                    </div>
                </div>

                <!-- Submit Button Bar -->
                <div class="bg-[#fffef9] rounded-3xl p-6 border border-cream-500/70 shadow-sm flex items-center justify-end">
                    <button
                        type="submit"
                        :disabled="isSubmitting"
                        class="w-full sm:w-auto inline-flex items-center justify-center px-8 py-3 bg-forest-900 hover:bg-forest-950 disabled:opacity-50 text-white text-xs font-bold rounded-xl shadow-md transition cursor-pointer"
                    >
                        <svg v-if="isSubmitting" class="animate-spin -ms-1 me-2 size-4 text-white" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        <span>{{ isSubmitting ? 'Submitting Responses...' : 'Submit Feedback' }}</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</template>

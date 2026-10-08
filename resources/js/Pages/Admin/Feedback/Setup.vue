<script setup>
import { ref, computed, watch, onMounted, onUnmounted } from 'vue';
import { Head, useForm, router, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import AdminNav from '@/Components/AdminNav.vue';
import QuestionnaireSchemaModal from '@/Components/Feedback/QuestionnaireSchemaModal.vue';

const page = usePage();

const props = defineProps({
    events: {
        type: Array,
        required: true,
    },
    functions: {
        type: Array,
        required: true,
    },
    feedbackForms: {
        type: Array,
        required: true,
    },
    agencies: {
        type: Array,
        required: true,
    },
    designations: {
        type: Array,
        required: true,
    },
    stats: {
        type: Object,
        required: true,
    },
});

const activeTab = ref('events'); // 'events' | 'functions' | 'lookups' | 'forms'
const copiedKeyId = ref(null);
const copiedRef = ref(null);
const visibleKeys = ref({});

const toggleKeyVisibility = (eventId) => {
    visibleKeys.value[eventId] = !visibleKeys.value[eventId];
};

const copyTextUniversal = async (text) => {
    if (text === undefined || text === null) return false;
    const str = String(text);

    // 1. Try modern navigator.clipboard API if available and in secure context
    if (typeof navigator !== 'undefined' && navigator?.clipboard?.writeText && window.isSecureContext) {
        try {
            await navigator.clipboard.writeText(str);
            return true;
        } catch (err) {
            console.warn('navigator.clipboard.writeText failed, using execCommand fallback:', err);
        }
    }

    // 2. Reliable fallback for non-secure HTTP / intranet / restricted clipboard contexts
    try {
        const textArea = document.createElement('textarea');
        textArea.value = str;
        textArea.style.position = 'fixed';
        textArea.style.top = '0';
        textArea.style.left = '-9999px';
        textArea.style.width = '2em';
        textArea.style.height = '2em';
        textArea.style.padding = '0';
        textArea.style.border = 'none';
        textArea.style.outline = 'none';
        textArea.style.boxShadow = 'none';
        textArea.style.background = 'transparent';
        textArea.setAttribute('readonly', '');
        document.body.appendChild(textArea);

        textArea.focus();
        textArea.select();
        textArea.setSelectionRange(0, textArea.value.length);

        const successful = document.execCommand('copy');
        document.body.removeChild(textArea);
        return successful;
    } catch (fallbackErr) {
        console.error('execCommand copy fallback failed: ', fallbackErr);
        return false;
    }
};

const copyApiKey = async (event) => {
    if (!event?.api_key) return;
    const success = await copyTextUniversal(event.api_key);
    if (success) {
        copiedKeyId.value = event.id;
        setTimeout(() => {
            if (copiedKeyId.value === event.id) copiedKeyId.value = null;
        }, 2500);
    }
};

const copyText = async (text, refKey) => {
    if (!text) return;
    const success = await copyTextUniversal(text);
    if (success) {
        copiedRef.value = refKey;
        setTimeout(() => {
            if (copiedRef.value === refKey) copiedRef.value = null;
        }, 2500);
    }
};

const getIframeSnippet = (event) => {
    if (!event?.embed?.public_id) return '';
    const url = route('feedback.embed.show', event.embed.public_id);
    return `<iframe src="${url}?token=SESSION_TOKEN" title="Feedback form" style="width: 100%; min-height: 700px; border: 0;"></iframe>`;
};

// --- Embed Modal State & Handlers ---
const showEmbedSettingsModal = ref(false);
const eventForEmbedSettings = ref(null);
const embedSettingsForm = useForm({
    allowed_origins_text: '',
    is_active: true,
});

const openEmbedOriginsModal = (event) => {
    eventForEmbedSettings.value = event;
    embedSettingsForm.clearErrors();
    embedSettingsForm.allowed_origins_text = event.embed?.allowed_origins ? event.embed.allowed_origins.join('\n') : '';
    embedSettingsForm.is_active = event.embed?.is_active ?? true;
    showEmbedSettingsModal.value = true;
};

const closeEmbedSettingsModal = () => {
    showEmbedSettingsModal.value = false;
    eventForEmbedSettings.value = null;
    embedSettingsForm.reset();
};

const saveEmbedSettings = () => {
    if (!eventForEmbedSettings.value) return;

    const origins = embedSettingsForm.allowed_origins_text
        .split('\n')
        .map(s => s.trim())
        .filter(s => s.length > 0);

    embedSettingsForm.transform(() => ({
        allowed_origins: origins,
        is_active: embedSettingsForm.is_active,
    })).put(route('admin.feedback.events.update-embed-origins', eventForEmbedSettings.value.id), {
        preserveScroll: true,
        onSuccess: () => closeEmbedSettingsModal(),
    });
};

// Modal for Regenerate Public ID
const showRegenerateEmbedIdModal = ref(false);
const eventForRegenerateId = ref(null);

const promptRegenerateEmbedId = (event) => {
    eventForRegenerateId.value = event;
    showRegenerateEmbedIdModal.value = true;
};

const confirmRegenerateEmbedId = () => {
    if (!eventForRegenerateId.value) return;
    router.post(route('admin.feedback.events.regenerate-embed-id', eventForRegenerateId.value.id), {}, {
        preserveScroll: true,
        onSuccess: () => {
            showRegenerateEmbedIdModal.value = false;
            eventForRegenerateId.value = null;
        },
    });
};

// Modal for Regenerate Client Secret
const showRegenerateSecretModal = ref(false);
const eventForRegenerateSecret = ref(null);

const promptRegenerateSecret = (event) => {
    eventForRegenerateSecret.value = event;
    showRegenerateSecretModal.value = true;
};

const confirmRegenerateSecret = () => {
    if (!eventForRegenerateSecret.value) return;
    router.post(route('admin.feedback.events.regenerate-embed-secret', eventForRegenerateSecret.value.id), {}, {
        preserveScroll: true,
        onSuccess: () => {
            showRegenerateSecretModal.value = false;
            eventForRegenerateSecret.value = null;
        },
    });
};

// Modal for newly revealed secret (from flash session)
const showRevealedSecretModal = ref(false);
const revealedSecret = computed(() => {
    return page.props.flash?.revealed_secret || null;
});

watch(() => page.props.flash?.revealed_secret, (val) => {
    if (val) {
        showRevealedSecretModal.value = true;
    }
}, { immediate: true });

// --- Event Modal State & Form ---
const showEventModal = ref(false);
const editingEvent = ref(null);
const eventForm = useForm({
    name: '',
    details: '',
    function_ids: [],
});

const openCreateEventModal = () => {
    editingEvent.value = null;
    eventForm.reset();
    eventForm.clearErrors();
    eventForm.function_ids = [];
    showEventModal.value = true;
};

const openEditEventModal = (event) => {
    editingEvent.value = event;
    eventForm.clearErrors();
    eventForm.name = event.name;
    eventForm.details = event.details || '';
    eventForm.function_ids = event.functions ? event.functions.map(fn => fn.id) : [];
    showEventModal.value = true;
};

const selectAllFunctionsForEvent = () => {
    eventForm.function_ids = props.functions.map(fn => fn.id);
};

const clearAllFunctionsForEvent = () => {
    eventForm.function_ids = [];
};

const closeEventModal = () => {
    showEventModal.value = false;
    editingEvent.value = null;
    eventForm.reset();
    eventForm.clearErrors();
};

const saveEvent = () => {
    if (editingEvent.value) {
        eventForm.put(route('admin.feedback.events.update', editingEvent.value.id), {
            preserveScroll: true,
            onSuccess: () => closeEventModal(),
        });
    } else {
        eventForm.post(route('admin.feedback.events.store'), {
            preserveScroll: true,
            onSuccess: () => closeEventModal(),
        });
    }
};

const regenerateKey = (event) => {
    if (confirm(`Regenerate API Key for "${event.name}"? External apps using the old key will need to be updated.`)) {
        router.post(route('admin.feedback.events.regenerate-key', event.id), {}, {
            preserveScroll: true,
        });
    }
};

// --- Function Modal State & Form (Reusable Functions) ---
const showFunctionModal = ref(false);
const editingFunction = ref(null);
const functionForm = useForm({
    function: '',
    details: '',
    event_ids: [],
});

const openCreateFunctionModal = (eventId = null) => {
    editingFunction.value = null;
    functionForm.reset();
    functionForm.clearErrors();
    functionForm.event_ids = eventId ? [Number(eventId)] : [];
    showFunctionModal.value = true;
};

const openEditFunctionModal = (fnItem) => {
    editingFunction.value = fnItem;
    functionForm.clearErrors();
    functionForm.function = fnItem.function;
    functionForm.details = fnItem.details || '';
    functionForm.event_ids = fnItem.events ? fnItem.events.map(ev => ev.id) : [];
    showFunctionModal.value = true;
};

const closeFunctionModal = () => {
    showFunctionModal.value = false;
    editingFunction.value = null;
    functionForm.reset();
    functionForm.clearErrors();
};

const saveFunction = () => {
    if (editingFunction.value) {
        functionForm.put(route('admin.feedback.functions.update', editingFunction.value.id), {
            preserveScroll: true,
            onSuccess: () => closeFunctionModal(),
        });
    } else {
        functionForm.post(route('admin.feedback.functions.store'), {
            preserveScroll: true,
            onSuccess: () => closeFunctionModal(),
        });
    }
};

// --- Agency & Designation Management ---
const agencyForm = useForm({ name: '' });
const designationForm = useForm({ name: '' });

const saveAgency = () => {
    agencyForm.post(route('admin.feedback.agencies.store'), {
        preserveScroll: true,
        onSuccess: () => agencyForm.reset(),
    });
};

const deleteAgency = (agency) => {
    if (confirm(`Remove agency "${agency.name}" from cached list?`)) {
        router.delete(route('admin.feedback.agencies.destroy', agency.id), {
            preserveScroll: true,
        });
    }
};

const saveDesignation = () => {
    designationForm.post(route('admin.feedback.designations.store'), {
        preserveScroll: true,
        onSuccess: () => designationForm.reset(),
    });
};

const deleteDesignation = (designation) => {
    if (confirm(`Remove designation "${designation.name}" from cached list?`)) {
        router.delete(route('admin.feedback.designations.destroy', designation.id), {
            preserveScroll: true,
        });
    }
};

// --- Form Configuration Modal State ---
const showConfigModal = ref(false);
const configTargetEventId = ref(null);
const configTargetFormRecord = ref(null);

const openFormConfigModal = (formRecord = null, eventId = null) => {
    configTargetFormRecord.value = formRecord;
    configTargetEventId.value = eventId ? Number(eventId) : null;
    showConfigModal.value = true;
};

const closeConfigModal = () => {
    showConfigModal.value = false;
    configTargetFormRecord.value = null;
    configTargetEventId.value = null;
};

// --- Generic Delete Confirmation State ---
const showDeleteConfirm = ref(false);
const deleteTargetType = ref(''); // 'event' | 'function'
const itemToDelete = ref(null);

const confirmDelete = (type, item) => {
    deleteTargetType.value = type;
    itemToDelete.value = item;
    showDeleteConfirm.value = true;
};

const performDelete = () => {
    if (!itemToDelete.value) return;

    let deleteUrl = '';
    if (deleteTargetType.value === 'event') {
        deleteUrl = route('admin.feedback.events.destroy', itemToDelete.value.id);
    } else if (deleteTargetType.value === 'function') {
        deleteUrl = route('admin.feedback.functions.destroy', itemToDelete.value.id);
    }

    router.delete(deleteUrl, {
        preserveScroll: true,
        onSuccess: () => {
            showDeleteConfirm.value = false;
            itemToDelete.value = null;
        },
    });
};

// --- Sample Preview Modal State & Handlers ---
const showSamplePreviewModal = ref(false);
const previewSelectedEventId = ref(null);
const previewCustomSchema = ref(null);
const previewMode = ref('deployed'); // 'deployed' | 'embedded'
const previewDevice = ref('desktop'); // 'desktop' | 'tablet' | 'mobile'
const previewFillState = ref('completed'); // 'completed' | 'blank'
const previewAnswers = ref({});

const previewEvent = computed(() => {
    if (previewSelectedEventId.value) {
        const found = props.events.find((e) => e.id === Number(previewSelectedEventId.value));
        if (found) return found;
    }
    return props.events[0] || null;
});

const previewForm = computed(() => {
    if (previewCustomSchema.value) {
        return {
            id: previewEvent.value?.feedback?.id || 1,
            event_id: previewEvent.value?.id,
            schema: previewCustomSchema.value,
        };
    }
    if (!previewEvent.value) {
        return {
            id: 0,
            event_id: null,
            schema: defaultSchemaTemplate,
        };
    }
    const found = props.feedbackForms.find((f) => f.event_id === previewEvent.value.id);
    if (found && found.schema?.fields?.length) {
        return found;
    }
    if (previewEvent.value.feedback?.schema?.fields?.length) {
        return previewEvent.value.feedback;
    }
    return {
        id: previewEvent.value.feedback?.id || 0,
        event_id: previewEvent.value.id,
        schema: defaultSchemaTemplate,
    };
});

const previewFields = computed(() => {
    if (!previewForm.value?.schema?.fields || !Array.isArray(previewForm.value.schema.fields)) {
        return [];
    }
    return [...previewForm.value.schema.fields].sort((a, b) => (Number(a.weight) || 0) - (Number(b.weight) || 0));
});

// --- Sample Preview Pagination Engine ---
const previewCurrentPageIdx = ref(0);
const previewPageHistory = ref([0]);

const previewSectionPages = computed(() => {
    if (!previewFields.value.length) return [];
    const pages = [];
    let currentSection = null;
    let currentFields = [];

    previewFields.value.forEach((field) => {
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

const isPreviewPaginated = computed(() => {
    return Boolean(previewForm.value?.schema?.pagination?.enabled && previewSectionPages.value.length > 1);
});

const previewCurrentPage = computed(() => {
    return previewSectionPages.value[previewCurrentPageIdx.value] || null;
});

const isPreviewLastPage = computed(() => {
    if (!isPreviewPaginated.value) return true;
    return previewCurrentPageIdx.value >= previewSectionPages.value.length - 1;
});

const isPreviewConditionsMet = (conditions) => {
    if (!conditions || conditions.length === 0) return true;
    for (const cond of conditions) {
        const triggerId = cond.field_id;
        const op = cond.operator || 'equals';
        const targetVal = String(cond.value ?? '');
        const actual = previewAnswers.value[triggerId];

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

const isPreviewSectionConditionMet = (sec) => isPreviewConditionsMet(sec?.conditions);
const isPreviewFieldConditionMet = (field) => isPreviewConditionsMet(field?.conditions);

const goToPreviewNextPage = () => {
    let target = null;
    const page = previewCurrentPage.value;
    if (page) {
        for (const f of page.fields) {
            const ans = previewAnswers.value[f.id];
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
        return;
    }

    let nextIdx = -1;
    if (target && target !== 'next') {
        nextIdx = previewSectionPages.value.findIndex((p) => p.section?.id === target);
    }

    if (nextIdx === -1) {
        nextIdx = previewCurrentPageIdx.value + 1;
    }

    while (nextIdx < previewSectionPages.value.length && !isPreviewSectionConditionMet(previewSectionPages.value[nextIdx].section)) {
        nextIdx++;
    }

    if (nextIdx >= previewSectionPages.value.length) {
        return;
    }

    previewPageHistory.value.push(nextIdx);
    previewCurrentPageIdx.value = nextIdx;
};

const goToPreviewPreviousPage = () => {
    if (previewPageHistory.value.length > 1) {
        previewPageHistory.value.pop();
        previewCurrentPageIdx.value = previewPageHistory.value[previewPageHistory.value.length - 1];
    }
};

const getPreviewFieldOptions = (field) => {
    if (!field) return [];
    if (field.option_source === 'fb_functions') {
        const eventFuncs = previewEvent.value?.functions || [];
        let sourceList = eventFuncs.length > 0 ? eventFuncs : props.functions;
        if (Array.isArray(field.function_ids) && field.function_ids.length > 0) {
            sourceList = sourceList.filter((f) => field.function_ids.includes(f.id));
        }
        if (sourceList.length === 0) {
            return [
                { value: 'Registration & Onboarding', label: 'Registration & Onboarding' },
                { value: 'Plenary Lecture & Presentation', label: 'Plenary Lecture & Presentation' },
                { value: 'Interactive Hands-on Workshop', label: 'Interactive Hands-on Workshop' },
                { value: 'Open Forum & Evaluation Synthesis', label: 'Open Forum & Evaluation Synthesis' },
            ];
        }
        return sourceList.map((f) => ({
            value: f.name,
            label: f.name + (f.code ? ` (${f.code})` : ''),
        }));
    }
    return Array.isArray(field.options) ? field.options : [];
};

const generateSampleAnswers = (fields = []) => {
    const sample = {};
    fields.forEach((field) => {
        const id = String(field.id || '');
        const p = String(field.particular || '').toLowerCase();

        if (field.type === 'text') {
            if (p.includes('name') || id.includes('name')) {
                sample[id] = 'Juan Dela Cruz';
            } else if (p.includes('email') || id.includes('email')) {
                sample[id] = 'juan.delacruz@agency.gov.ph';
            } else if (p.includes('agency') || p.includes('office') || id.includes('agency')) {
                sample[id] = 'Department of Budget and Management';
            } else if (p.includes('designation') || p.includes('position') || id.includes('designation')) {
                sample[id] = 'Senior Budget & Management Specialist';
            } else if (p.includes('contact') || p.includes('mobile') || p.includes('phone')) {
                sample[id] = '0917-123-4567';
            } else {
                sample[id] = 'All technical and operational procedures were thoroughly covered.';
            }
        } else if (field.type === 'textarea') {
            if (p.includes('comment') || p.includes('feedback') || p.includes('suggestion') || p.includes('recommendation')) {
                sample[id] = 'The orientation session was highly structured, clear, and engaging. The resource speakers provided comprehensive insights and practical examples that are directly applicable to our department\'s daily operational workflows. Recommend conducting similar refresher sessions quarterly.';
            } else {
                sample[id] = 'All scheduled activities and discussions were executed punctually with clear facilitation and responsive support throughout.';
            }
        } else if (field.type === 'number') {
            sample[id] = field.max !== null && field.max !== undefined ? Math.min(5, Number(field.max)) : 5;
        } else if (field.type === 'date') {
            sample[id] = new Date().toISOString().slice(0, 10);
        } else if (field.type === 'radio') {
            const opts = getPreviewFieldOptions(field);
            if (opts.length > 0) {
                const bestOpt = opts.find((o) => {
                    const str = (String(o.value) + ' ' + String(o.label)).toLowerCase();
                    return str.includes('excellent') || str.includes('outstanding') || str.includes('5') || str.includes('agree') || str.includes('yes');
                }) || opts[0];
                sample[id] = bestOpt.value;
            }
        } else if (field.type === 'select') {
            const opts = getPreviewFieldOptions(field);
            if (opts.length > 0) {
                sample[id] = opts[0].value;
            }
        } else if (field.type === 'checkbox') {
            const opts = getPreviewFieldOptions(field);
            if (opts.length > 1) {
                sample[id] = [opts[0].value, opts[1].value];
            } else if (opts.length === 1) {
                sample[id] = [opts[0].value];
            } else {
                sample[id] = [];
            }
        } else {
            sample[id] = 'Satisfactory';
        }

        if (field.allow_other) {
            sample[`${id}_other`] = 'Specific requirements discussed during plenary consultation.';
        }
    });
    return sample;
};

const resetPreviewAnswers = (state = 'completed') => {
    previewFillState.value = state;
    previewCurrentPageIdx.value = 0;
    previewPageHistory.value = [0];
    if (state === 'completed') {
        previewAnswers.value = generateSampleAnswers(previewFields.value);
    } else {
        const blank = {};
        previewFields.value.forEach((f) => {
            if (f.type === 'checkbox') {
                blank[f.id] = [];
            } else {
                blank[f.id] = '';
            }
            if (f.allow_other) {
                blank[`${f.id}_other`] = '';
            }
        });
        previewAnswers.value = blank;
    }
};

const handlePreviewCheckboxToggle = (fieldId, optionValue) => {
    if (!Array.isArray(previewAnswers.value[fieldId])) {
        previewAnswers.value[fieldId] = [];
    }
    const idx = previewAnswers.value[fieldId].indexOf(optionValue);
    if (idx > -1) {
        previewAnswers.value[fieldId].splice(idx, 1);
    } else {
        previewAnswers.value[fieldId].push(optionValue);
    }
};

const isPreviewOtherSelected = (field) => {
    if (!field?.allow_other) return false;
    const val = previewAnswers.value[field.id];
    if (val === undefined || val === null || val === '') return false;
    if (field.type === 'checkbox') {
        if (!Array.isArray(val)) return false;
        return val.some((selectedVal) => {
            const opts = getPreviewFieldOptions(field);
            const matchedOpt = opts.find((opt) => String(opt.value) === String(selectedVal));
            return matchedOpt?.is_other === true || String(selectedVal).toLowerCase() === 'other';
        });
    }
    const opts = getPreviewFieldOptions(field);
    const matchedOpt = opts.find((opt) => String(opt.value) === String(val));
    return matchedOpt?.is_other === true || String(val).toLowerCase() === 'other';
};

const openSamplePreviewModal = (targetEvent = null, targetForm = null) => {
    if (targetEvent?.id) {
        previewSelectedEventId.value = targetEvent.id;
    } else if (targetForm?.event_id) {
        previewSelectedEventId.value = targetForm.event_id;
    } else if (configTargetEventId.value) {
        previewSelectedEventId.value = Number(configTargetEventId.value);
    } else if (props.events.length > 0) {
        previewSelectedEventId.value = props.events[0].id;
    } else {
        previewSelectedEventId.value = null;
    }

    if (targetForm?.schema) {
        previewCustomSchema.value = JSON.parse(JSON.stringify(targetForm.schema));
    } else {
        previewCustomSchema.value = null;
    }

    previewMode.value = 'deployed';
    previewDevice.value = 'desktop';
    resetPreviewAnswers('completed');
    showSamplePreviewModal.value = true;
};

const onPreviewEventChange = () => {
    previewCustomSchema.value = null;
    resetPreviewAnswers(previewFillState.value);
};

const closeSamplePreviewModal = () => {
    showSamplePreviewModal.value = false;
    previewCustomSchema.value = null;
};
</script>

<template>
    <AppLayout title="Admin - Feedback Setup">
        <template #header>
            <AdminNav />
            <div class="flex items-center justify-between flex-wrap gap-4">
                <div>
                    <h2 class="font-bold text-xl text-gray-900 leading-tight">
                        Feedback Module Setup & Configuration
                    </h2>
                    <p class="text-xs text-gray-500 mt-0.5">
                        Manage feedback events, API authentication keys, reusable participant functions, cached lookups, and questionnaire schemas
                    </p>
                </div>

                <div class="flex items-center space-x-2.5">
                    <button
                        type="button"
                        @click="openSamplePreviewModal()"
                        class="inline-flex items-center px-4 py-2 bg-cream-100 hover:bg-cream-200 text-forest-900 border border-cream-400 text-sm font-semibold rounded-xl shadow-xs transition cursor-pointer"
                        title="View a sample completed feedback form when embedded or deployed"
                    >
                        <svg class="size-4 me-1.5 text-forest-900" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                        </svg>
                        <span>View Feedback Form</span>
                    </button>

                    <button
                        v-if="activeTab === 'events'"
                        @click="openCreateEventModal"
                        class="inline-flex items-center px-4 py-2 bg-forest-900 hover:bg-forest-950 text-white text-sm font-semibold rounded-xl shadow-xs transition"
                    >
                        <svg class="size-4 me-1.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                        </svg>
                        New Event
                    </button>

                    <button
                        v-if="activeTab === 'functions'"
                        @click="openCreateFunctionModal()"
                        class="inline-flex items-center px-4 py-2 bg-forest-900 hover:bg-forest-950 text-white text-sm font-semibold rounded-xl shadow-xs transition"
                    >
                        <svg class="size-4 me-1.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                        </svg>
                        New Function
                    </button>

                    <button
                        v-if="activeTab === 'forms'"
                        @click="openFormConfigModal()"
                        class="inline-flex items-center px-4 py-2 bg-forest-900 hover:bg-forest-950 text-white text-sm font-semibold rounded-xl shadow-xs transition cursor-pointer"
                    >
                        <svg class="size-4 me-1.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                        </svg>
                        Questionnaire
                    </button>
                </div>
            </div>
        </template>

        <div class="py-6">
            <div class="max-w-8xl mx-auto px-4 sm:px-6 lg:px-8">
                <!-- Flash Notifications -->
                <div v-if="$page.props.flash?.success" class="mb-6 p-4 bg-emerald-50 border border-emerald-200 text-emerald-800 rounded-xl text-sm font-medium flex items-center shadow-xs">
                    <svg class="size-5 me-2 text-emerald-600 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    {{ $page.props.flash.success }}
                </div>

                <div v-if="$page.props.flash?.error" class="mb-6 p-4 bg-red-50 border border-red-200 text-red-800 rounded-xl text-sm font-medium flex items-center shadow-xs">
                    <svg class="size-5 me-2 text-red-600 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" />
                    </svg>
                    {{ $page.props.flash.error }}
                </div>

                <!-- Metrics Overview Row -->
                <div class="grid grid-cols-2 lg:grid-cols-5 gap-4 mb-6">
                    <div class="bg-cream-200 p-4 rounded-2xl shadow-xs border border-cream-500/50 flex items-center space-x-3">
                        <div class="p-2.5 bg-lime-200 text-forest-900 rounded-xl shrink-0">
                            <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.253M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5" />
                            </svg>
                        </div>
                        <div>
                            <div class="text-[11px] text-gray-500 font-medium uppercase tracking-wider">Events</div>
                            <div class="text-xl font-bold text-gray-900 mt-0.5">{{ stats.total_events }}</div>
                        </div>
                    </div>

                    <div class="bg-cream-200 p-4 rounded-2xl shadow-xs border border-cream-500/50 flex items-center space-x-3">
                        <div class="p-2.5 bg-lime-200 text-forest-900 rounded-xl shrink-0">
                            <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" />
                            </svg>
                        </div>
                        <div>
                            <div class="text-[11px] text-gray-500 font-medium uppercase tracking-wider">Functions</div>
                            <div class="text-xl font-bold text-gray-900 mt-0.5">{{ stats.total_functions }}</div>
                        </div>
                    </div>

                    <div class="bg-cream-200 p-4 rounded-2xl shadow-xs border border-cream-500/50 flex items-center space-x-3">
                        <div class="p-2.5 bg-lime-200 text-forest-900 rounded-xl shrink-0">
                            <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3.75a.75.75 0 01.75-.75h1.5a.75.75 0 01.75.75V21m6 0v-3.75a.75.75 0 01.75-.75h1.5a.75.75 0 01.75.75V21" />
                            </svg>
                        </div>
                        <div>
                            <div class="text-[11px] text-gray-500 font-medium uppercase tracking-wider">Agencies</div>
                            <div class="text-xl font-bold text-gray-900 mt-0.5">{{ stats.total_agencies }}</div>
                        </div>
                    </div>

                    <div class="bg-cream-200 p-4 rounded-2xl shadow-xs border border-cream-500/50 flex items-center space-x-3">
                        <div class="p-2.5 bg-lime-200 text-forest-900 rounded-xl shrink-0">
                            <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 002.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 00-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 00.75-.75 2.25 2.25 0 00-.1-.664m-5.8 0A2.251 2.251 0 0113.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25zM6.75 12h.008v.008H6.75V12zm0 3h.008v.008H6.75V15zm0 3h.008v.008H6.75V18z" />
                            </svg>
                        </div>
                        <div>
                            <div class="text-[11px] text-gray-500 font-medium uppercase tracking-wider">Schemas</div>
                            <div class="text-xl font-bold text-gray-900 mt-0.5">{{ stats.total_forms }}</div>
                        </div>
                    </div>

                    <div class="bg-cream-200 p-4 rounded-2xl shadow-xs border border-cream-500/50 flex items-center space-x-3 col-span-2 lg:col-span-1">
                        <div class="p-2.5 bg-lime-200 text-forest-900 rounded-xl shrink-0">
                            <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 12h16.5m-16.5 3.75h16.5M3.75 19.5h16.5M5.625 4.5h12.75a1.875 1.875 0 010 3.75H5.625a1.875 1.875 0 010-3.75z" />
                            </svg>
                        </div>
                        <div>
                            <div class="text-[11px] text-gray-500 font-medium uppercase tracking-wider">Submissions</div>
                            <div class="text-xl font-bold text-gray-900 mt-0.5">{{ stats.total_submissions }}</div>
                        </div>
                    </div>
                </div>

                <!-- Navigation Tabs -->
                <div class="flex items-center space-x-2 border-b border-cream-500/60 pb-3 mb-6 overflow-x-auto">
                    <button
                        type="button"
                        @click="activeTab = 'events'"
                        class="px-4 py-2 rounded-xl text-sm font-semibold transition whitespace-nowrap flex items-center space-x-2 cursor-pointer"
                        :class="activeTab === 'events' ? 'bg-forest-900 text-white shadow-xs' : 'bg-cream-200 text-gray-700 hover:bg-cream-300'"
                    >
                        <span>Feedback Events</span>
                        <span class="px-2 py-0.5 rounded-full text-xs font-bold" :class="activeTab === 'events' ? 'bg-forest-800 text-emerald-200' : 'bg-cream-300 text-gray-700'">
                            {{ events.length }}
                        </span>
                    </button>

                    <button
                        type="button"
                        @click="activeTab = 'functions'"
                        class="px-4 py-2 rounded-xl text-sm font-semibold transition whitespace-nowrap flex items-center space-x-2 cursor-pointer"
                        :class="activeTab === 'functions' ? 'bg-forest-900 text-white shadow-xs' : 'bg-cream-200 text-gray-700 hover:bg-cream-300'"
                    >
                        <span>Functions / Roles</span>
                        <span class="px-2 py-0.5 rounded-full text-xs font-bold" :class="activeTab === 'functions' ? 'bg-forest-800 text-emerald-200' : 'bg-cream-300 text-gray-700'">
                            {{ functions.length }}
                        </span>
                    </button>

                    <button
                        type="button"
                        @click="activeTab = 'lookups'"
                        class="px-4 py-2 rounded-xl text-sm font-semibold transition whitespace-nowrap flex items-center space-x-2 cursor-pointer"
                        :class="activeTab === 'lookups' ? 'bg-forest-900 text-white shadow-xs' : 'bg-cream-200 text-gray-700 hover:bg-cream-300'"
                    >
                        <span>Agencies & Designations</span>
                        <span class="px-2 py-0.5 rounded-full text-xs font-bold" :class="activeTab === 'lookups' ? 'bg-forest-800 text-emerald-200' : 'bg-cream-300 text-gray-700'">
                            {{ agencies.length + designations.length }}
                        </span>
                    </button>

                    <button
                        type="button"
                        @click="activeTab = 'forms'"
                        class="px-4 py-2 rounded-xl text-sm font-semibold transition whitespace-nowrap flex items-center space-x-2 cursor-pointer"
                        :class="activeTab === 'forms' ? 'bg-forest-900 text-white shadow-xs' : 'bg-cream-200 text-gray-700 hover:bg-cream-300'"
                    >
                        <span>Form Schemas</span>
                        <span class="px-2 py-0.5 rounded-full text-xs font-bold" :class="activeTab === 'forms' ? 'bg-forest-800 text-emerald-200' : 'bg-cream-300 text-gray-700'">
                            {{ feedbackForms.length }}
                        </span>
                    </button>
                </div>

                <!-- Tab 1: Events Section -->
                <div v-if="activeTab === 'events'">
                    <div v-if="events.length === 0" class="bg-cream-200 rounded-3xl p-12 text-center border border-cream-500/50">
                        <div class="size-16 rounded-full bg-cream-100 text-gray-400 flex items-center justify-center mx-auto mb-4">
                            <svg class="size-8" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.253M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5" />
                            </svg>
                        </div>
                        <h3 class="text-base font-bold text-gray-900">No Feedback Events Defined</h3>
                        <p class="text-xs text-gray-500 max-w-sm mx-auto mt-1">Create an event to generate its secure API key, assign functions, and configure feedback questionnaires.</p>
                        <button
                            @click="openCreateEventModal"
                            class="mt-4 inline-flex items-center px-4 py-2 bg-forest-900 hover:bg-forest-950 text-white text-xs font-semibold rounded-xl shadow-xs transition"
                        >
                            Create Event
                        </button>
                    </div>

                    <div v-else class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
                        <div
                            v-for="event in events"
                            :key="event.id"
                            class="bg-cream-200 rounded-2xl border border-cream-500/50 shadow-xs hover:shadow-md transition-all p-5 flex flex-col justify-between"
                        >
                            <div>
                                <div class="flex items-start justify-between gap-2">
                                    <div>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-md text-[10px] font-bold uppercase tracking-wider bg-lime-200 text-forest-900 border border-forest-500/20">
                                            Event #{{ event.id }}
                                        </span>
                                        <h3 class="text-base font-bold text-gray-900 mt-1.5">{{ event.name }}</h3>
                                    </div>

                                    <span
                                        v-if="event.feedback"
                                        class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold bg-emerald-100 text-emerald-800 border border-emerald-200 shrink-0"
                                    >
                                        ✓ Form Ready
                                    </span>
                                    <span
                                        v-else
                                        class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold bg-amber-100 text-amber-800 border border-amber-200 shrink-0"
                                    >
                                        ⚠ No Form
                                    </span>
                                </div>

                                <p class="text-xs text-gray-600 mt-2 line-clamp-2">
                                    {{ event.details || 'No event description provided.' }}
                                </p>

                                <!-- Assigned Functions Preview -->
                                <div class="mt-3">
                                    <div class="text-[10px] font-bold text-forest-900 uppercase tracking-wider mb-1">
                                        Assigned Functions ({{ event.functions ? event.functions.length : 0 }})
                                    </div>
                                    <div v-if="event.functions && event.functions.length > 0" class="flex flex-wrap gap-1 max-h-16 overflow-y-auto">
                                        <span
                                            v-for="fn in event.functions"
                                            :key="fn.id"
                                            class="inline-flex items-center px-2 py-0.5 rounded-md text-[10px] font-semibold bg-forest-900 text-emerald-200 shadow-2xs"
                                        >
                                            {{ fn.function }}
                                        </span>
                                    </div>
                                    <div v-else class="text-[11px] text-gray-400 italic">
                                        No functions assigned yet.
                                    </div>
                                </div>

                                <!-- Secure API Key Display Card -->
                                <div class="mt-4 bg-[#fffef9] p-3 rounded-xl border border-cream-500/60 shadow-xs">
                                    <div class="flex items-center justify-between text-[11px] font-bold text-forest-900 mb-1.5">
                                        <span class="flex items-center space-x-1">
                                            <svg class="size-3.5 text-forest-700" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 5.25a3 3 0 013 3m3 0a6 6 0 01-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 1121.75 8.25z" />
                                            </svg>
                                            <span>Event API Key</span>
                                        </span>
                                        <button
                                            type="button"
                                            @click="toggleKeyVisibility(event.id)"
                                            class="text-forest-800 hover:text-forest-950 text-[10px] font-semibold underline cursor-pointer"
                                        >
                                            {{ visibleKeys[event.id] ? 'Hide' : 'Reveal' }}
                                        </button>
                                    </div>

                                    <div class="flex items-center space-x-1.5">
                                        <input
                                            :type="visibleKeys[event.id] ? 'text' : 'password'"
                                            readonly
                                            :value="event.api_key"
                                            class="flex-1 px-2 py-1 bg-cream-100/70 border border-cream-400 rounded-lg text-xs font-mono select-all text-gray-800"
                                        />
                                        <button
                                            type="button"
                                            @click="copyApiKey(event)"
                                            class="px-2.5 py-1 bg-forest-900 hover:bg-forest-950 text-white rounded-lg text-xs font-semibold shrink-0 transition cursor-pointer"
                                            title="Copy API Key to clipboard"
                                        >
                                            {{ copiedKeyId === event.id ? '✓ Copied' : 'Copy' }}
                                        </button>
                                    </div>

                                    <div class="mt-1.5 flex items-center justify-between text-[10px] text-gray-500">
                                        <span>Use in <code>X-API-KEY</code> header</span>
                                        <button
                                            type="button"
                                            @click="regenerateKey(event)"
                                            class="text-amber-700 hover:text-amber-900 hover:underline font-medium cursor-pointer"
                                        >
                                            Regenerate Key
                                        </button>
                                    </div>
                                </div>

                                <!-- Secure Public Iframe Embed Card -->
                                <div class="mt-3.5 bg-[#fffef9] p-3.5 rounded-xl border border-cream-500/60 shadow-xs space-y-3">
                                    <div class="flex items-center justify-between text-[11px] font-bold text-forest-900">
                                        <span class="flex items-center space-x-1.5">
                                            <svg class="size-3.5 text-forest-700" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" />
                                            </svg>
                                            <span>Public Iframe Integration</span>
                                        </span>
                                        <span
                                            class="px-2 py-0.5 rounded-full text-[10px] font-semibold"
                                            :class="event.embed?.is_active ? 'bg-emerald-100 text-emerald-800' : 'bg-red-100 text-red-800'"
                                        >
                                            {{ event.embed?.is_active ? 'Embed Active' : 'Embed Disabled' }}
                                        </span>
                                    </div>

                                    <!-- Public ID & Client ID Rows -->
                                    <div class="space-y-2 text-xs">
                                        <div>
                                            <div class="text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-0.5">Embed Public ID</div>
                                            <div class="flex items-center space-x-2 min-w-0">
                                                <input
                                                    type="text"
                                                    readonly
                                                    :value="event.embed?.public_id || 'Not generated'"
                                                    class="flex-1 min-w-0 px-2.5 py-1 bg-cream-100/70 border border-cream-400 rounded-lg text-[11px] font-mono select-all text-gray-800"
                                                />
                                                <button
                                                    type="button"
                                                    @click="copyText(event.embed?.public_id, `pub_${event.id}`)"
                                                    class="px-2.5 py-1 bg-forest-900 hover:bg-forest-950 text-white rounded-lg text-[11px] font-semibold shrink-0 transition cursor-pointer"
                                                >
                                                    {{ copiedRef === `pub_${event.id}` ? '✓ Copied' : 'Copy' }}
                                                </button>
                                            </div>
                                        </div>

                                        <div>
                                            <div class="text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-0.5">Client ID</div>
                                            <div class="flex items-center space-x-2 min-w-0">
                                                <input
                                                    type="text"
                                                    readonly
                                                    :value="event.embed?.client_id || 'Not generated'"
                                                    class="flex-1 min-w-0 px-2.5 py-1 bg-cream-100/70 border border-cream-400 rounded-lg text-[11px] font-mono select-all text-gray-800"
                                                />
                                                <button
                                                    type="button"
                                                    @click="copyText(event.embed?.client_id, `client_${event.id}`)"
                                                    class="px-2.5 py-1 bg-forest-900 hover:bg-forest-950 text-white rounded-lg text-[11px] font-semibold shrink-0 transition cursor-pointer"
                                                >
                                                    {{ copiedRef === `client_${event.id}` ? '✓ Copied' : 'Copy' }}
                                                </button>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Allowed Origins & Iframe Tag -->
                                    <div class="space-y-1.5 pt-1">
                                        <div class="flex items-center justify-between text-[10px]">
                                            <span class="font-bold text-gray-600 uppercase tracking-wider">
                                                Allowed Origins (CSP frame-ancestors)
                                            </span>
                                            <button
                                                type="button"
                                                @click="openEmbedOriginsModal(event)"
                                                class="text-forest-800 hover:text-forest-950 font-semibold underline cursor-pointer"
                                            >
                                                Configure Origins
                                            </button>
                                        </div>

                                        <div class="flex flex-wrap gap-1">
                                            <template v-if="event.embed?.allowed_origins && event.embed.allowed_origins.length > 0">
                                                <span
                                                    v-for="orig in event.embed.allowed_origins"
                                                    :key="orig"
                                                    class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-mono bg-cream-200 text-gray-800 border border-cream-400"
                                                >
                                                    {{ orig }}
                                                </span>
                                            </template>
                                            <span v-else class="text-[10px] text-gray-500 italic">
                                                Allowing 'self' only. Configure parent origins to allow external framing.
                                            </span>
                                        </div>

                                        <!-- Iframe snippet -->
                                        <div class="mt-2">
                                            <div class="flex items-center justify-between text-[10px] font-bold text-gray-600 mb-1">
                                                <span>Iframe Embed Tag</span>
                                                <button
                                                    type="button"
                                                    @click="copyText(getIframeSnippet(event), `snippet_${event.id}`)"
                                                    class="text-forest-800 hover:text-forest-950 font-semibold underline cursor-pointer"
                                                >
                                                    {{ copiedRef === `snippet_${event.id}` ? '✓ Copied Tag' : 'Copy Iframe Tag' }}
                                                </button>
                                            </div>
                                            <textarea
                                                readonly
                                                rows="2"
                                                :value="getIframeSnippet(event)"
                                                class="w-full px-2 py-1 bg-cream-100/70 border border-cream-400 rounded-lg text-[10px] font-mono select-all text-gray-700"
                                            ></textarea>
                                            <p class="text-[10px] text-gray-500 mt-1 leading-normal">
                                                Your backend requests a 5-minute single-use session token via <code class="px-1 py-0.5 bg-cream-200 text-gray-800 rounded font-mono text-[9px]">POST /api/feedback/embed/sessions</code> and injects the resulting <code class="px-1 py-0.5 bg-cream-200 text-gray-800 rounded font-mono text-[9px]">iframe_url</code> into your template.
                                            </p>
                                        </div>

                                        <!-- Action Links for Regeneration -->
                                        <div class="mt-2 pt-2 border-t border-cream-300 flex items-center justify-between text-[10px]">
                                            <button
                                                type="button"
                                                @click="promptRegenerateEmbedId(event)"
                                                class="text-amber-800 hover:text-amber-950 font-medium cursor-pointer"
                                            >
                                                Regenerate Public ID
                                            </button>
                                            <button
                                                type="button"
                                                @click="promptRegenerateSecret(event)"
                                                class="text-amber-800 hover:text-amber-950 font-medium cursor-pointer"
                                            >
                                                Regenerate Client Secret
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="mt-4 pt-3 border-t border-cream-500/40 flex items-center justify-between text-xs text-gray-500">
                                <div>
                                    <span class="font-medium text-gray-800">{{ event.functions ? event.functions.length : 0 }} functions assigned</span>
                                </div>

                                <div class="flex items-center space-x-1.5">
                                    <button
                                        type="button"
                                        @click="openSamplePreviewModal(event)"
                                        class="p-1.5 rounded-lg text-forest-900 hover:bg-forest-100 transition cursor-pointer"
                                        title="View Feedback Form Sample for this Event"
                                    >
                                        <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" />
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                        </svg>
                                    </button>

                                    <button
                                        @click="openFormConfigModal(event.feedback, event.id)"
                                        class="p-1.5 rounded-lg text-forest-900 hover:bg-lime-200 transition cursor-pointer"
                                        title="Configure Form Questionnaire"
                                    >
                                        <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M10.34 15.84c-.688-.06-1.386-.09-2.09-.09H7.5a4.5 4.5 0 110-9h.75c.704 0 1.402-.03 2.09-.09m0 9.18c.253.962.584 1.892.985 2.783.247.55.06 1.21-.463 1.511l-.657.38c-.551.318-1.26.117-1.527-.461a20.845 20.845 0 01-1.44-4.282m3.102.069a18.03 18.03 0 01-.59-4.59c0-1.586.205-3.124.59-4.59m0 9.18a23.848 23.848 0 018.835 2.535M10.34 6.66a23.847 23.847 0 008.835-2.535m0 0A23.74 23.74 0 0018.795 3m.38 1.125a23.91 23.91 0 011.014 5.395m-1.014 8.855c-.118.38-.245.754-.38 1.125m.38-1.125a23.91 23.91 0 001.014-5.395m0-3.46c.495.41.811 1.035.811 1.73 0 .695-.316 1.32-.811 1.73m0-3.46a24.347 24.347 0 010 3.46" />
                                        </svg>
                                    </button>

                                    <button
                                        @click="openEditEventModal(event)"
                                        class="p-1.5 rounded-lg text-blue-700 hover:bg-blue-100 transition cursor-pointer"
                                        title="Edit Event & Functions"
                                    >
                                        <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 013 18.75V8.25A2.25 2.25 0 015.25 6H10" />
                                        </svg>
                                    </button>

                                    <button
                                        @click="confirmDelete('event', event)"
                                        class="p-1.5 rounded-lg text-red-600 hover:bg-red-100 transition cursor-pointer"
                                        title="Delete Event"
                                    >
                                        <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" />
                                        </svg>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tab 2: Functions Section (Reusable Catalog) -->
                <div v-if="activeTab === 'functions'">
                    <div v-if="functions.length === 0" class="bg-cream-200 rounded-3xl p-12 text-center border border-cream-500/50">
                        <div class="size-16 rounded-full bg-cream-100 text-gray-400 flex items-center justify-center mx-auto mb-4">
                            <svg class="size-8" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" />
                            </svg>
                        </div>
                        <h3 class="text-base font-bold text-gray-900">No Functions Defined</h3>
                        <p class="text-xs text-gray-500 max-w-sm mx-auto mt-1">Create reusable participant functions (e.g. Incident Commander, Observer, Evaluator, Others) that can be shared across multiple events.</p>
                        <button
                            @click="openCreateFunctionModal()"
                            class="mt-4 inline-flex items-center px-4 py-2 bg-forest-900 hover:bg-forest-950 text-white text-xs font-semibold rounded-xl shadow-xs transition"
                        >
                            Create Reusable Function
                        </button>
                    </div>

                    <div v-else class="bg-cream-200 rounded-2xl border border-cream-500/50 shadow-xs overflow-hidden">
                        <div class="overflow-x-auto">
                            <table class="w-full text-left text-xs">
                                <thead class="bg-cream-300 text-forest-900 font-bold uppercase tracking-wider text-[11px] border-b border-cream-500/50">
                                    <tr>
                                        <th class="px-5 py-3.5">Function / Role Label</th>
                                        <th class="px-5 py-3.5">Details / Custom Notes</th>
                                        <th class="px-5 py-3.5">Assigned Events</th>
                                        <th class="px-5 py-3.5">Participants</th>
                                        <th class="px-5 py-3.5 text-right">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-cream-500/30">
                                    <tr v-for="func in functions" :key="func.id" class="hover:bg-cream-100 transition">
                                        <td class="px-5 py-3.5 font-semibold text-forest-900">
                                            <span class="flex items-center space-x-1.5">
                                                <span class="text-sm font-bold text-gray-900">{{ func.function }}</span>
                                                <span v-if="func.function.toLowerCase().includes('other')" class="px-1.5 py-0.5 rounded text-[10px] font-bold bg-amber-100 text-amber-800 border border-amber-300">
                                                    Custom Value
                                                </span>
                                            </span>
                                        </td>
                                        <td class="px-5 py-3.5 text-gray-600">
                                            {{ func.details || '—' }}
                                        </td>
                                        <td class="px-5 py-3.5">
                                            <div v-if="func.events && func.events.length > 0" class="flex flex-wrap gap-1">
                                                <span
                                                    v-for="ev in func.events"
                                                    :key="ev.id"
                                                    class="inline-flex items-center px-2 py-0.5 rounded-md text-[10px] font-semibold bg-lime-100 text-forest-900 border border-forest-300"
                                                >
                                                    {{ ev.name }}
                                                </span>
                                            </div>
                                            <span v-else class="text-[11px] text-gray-400 italic">
                                                Unassigned
                                            </span>
                                        </td>
                                        <td class="px-5 py-3.5">
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold bg-cream-100 text-gray-800 border border-cream-400">
                                                {{ func.participants_count ?? 0 }} responses
                                            </span>
                                        </td>
                                        <td class="px-5 py-3.5 text-right space-x-1">
                                            <button
                                                @click="openEditFunctionModal(func)"
                                                class="px-2.5 py-1 text-xs font-semibold rounded-lg bg-blue-50 text-blue-700 hover:bg-blue-100 transition border border-blue-200 cursor-pointer"
                                            >
                                                Edit
                                            </button>
                                            <button
                                                @click="confirmDelete('function', func)"
                                                class="px-2.5 py-1 text-xs font-semibold rounded-lg bg-red-50 text-red-700 hover:bg-red-100 transition border border-red-200 cursor-pointer"
                                            >
                                                Delete
                                            </button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Tab 3: Lookups Section (Agencies & Designations) -->
                <div v-if="activeTab === 'lookups'" class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <!-- Agencies Box -->
                    <div class="bg-cream-200 rounded-2xl border border-cream-500/50 shadow-xs p-5 flex flex-col justify-between">
                        <div>
                            <div class="flex items-center justify-between pb-3 border-b border-cream-500/40">
                                <div>
                                    <h3 class="text-base font-bold text-gray-900">Agencies Dropdown List</h3>
                                    <p class="text-xs text-gray-500">Cached choices automatically available to forms and API consumers</p>
                                </div>
                                <span class="px-2.5 py-0.5 bg-lime-200 text-forest-900 rounded-full text-xs font-bold">
                                    {{ agencies.length }} cached
                                </span>
                            </div>

                            <!-- Add Agency Form -->
                            <form @submit.prevent="saveAgency" class="mt-4 flex space-x-2">
                                <input
                                    v-model="agencyForm.name"
                                    type="text"
                                    required
                                    placeholder="Add new agency name..."
                                    class="flex-1 px-3 py-2 bg-[#fffef9] border border-cream-500 focus:border-forest-600 rounded-xl text-xs"
                                />
                                <button
                                    type="submit"
                                    :disabled="agencyForm.processing"
                                    class="px-4 py-2 bg-forest-900 hover:bg-forest-950 text-white rounded-xl text-xs font-semibold shadow-xs cursor-pointer"
                                >
                                    + Add
                                </button>
                            </form>

                            <!-- Agencies List -->
                            <div class="mt-4 max-h-72 overflow-y-auto space-y-1.5 pr-1">
                                <div v-if="agencies.length === 0" class="text-xs text-gray-400 p-4 text-center">
                                    No agencies registered yet. New agencies will also be auto-created during form submissions.
                                </div>
                                <div
                                    v-for="ag in agencies"
                                    :key="ag.id"
                                    class="flex items-center justify-between p-2.5 rounded-xl bg-[#fffef9] border border-cream-500/50 text-xs"
                                >
                                    <span class="font-medium text-gray-800">{{ ag.name }}</span>
                                    <button
                                        type="button"
                                        @click="deleteAgency(ag)"
                                        class="text-red-500 hover:text-red-700 text-xs font-semibold cursor-pointer"
                                    >
                                        Remove
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Designations Box -->
                    <div class="bg-cream-200 rounded-2xl border border-cream-500/50 shadow-xs p-5 flex flex-col justify-between">
                        <div>
                            <div class="flex items-center justify-between pb-3 border-b border-cream-500/40">
                                <div>
                                    <h3 class="text-base font-bold text-gray-900">Designations Dropdown List</h3>
                                    <p class="text-xs text-gray-500">Cached titles & ranks available to forms and API consumers</p>
                                </div>
                                <span class="px-2.5 py-0.5 bg-lime-200 text-forest-900 rounded-full text-xs font-bold">
                                    {{ designations.length }} cached
                                </span>
                            </div>

                            <!-- Add Designation Form -->
                            <form @submit.prevent="saveDesignation" class="mt-4 flex space-x-2">
                                <input
                                    v-model="designationForm.name"
                                    type="text"
                                    required
                                    placeholder="Add new designation / role title..."
                                    class="flex-1 px-3 py-2 bg-[#fffef9] border border-cream-500 focus:border-forest-600 rounded-xl text-xs"
                                />
                                <button
                                    type="submit"
                                    :disabled="designationForm.processing"
                                    class="px-4 py-2 bg-forest-900 hover:bg-forest-950 text-white rounded-xl text-xs font-semibold shadow-xs cursor-pointer"
                                >
                                    + Add
                                </button>
                            </form>

                            <!-- Designations List -->
                            <div class="mt-4 max-h-72 overflow-y-auto space-y-1.5 pr-1">
                                <div v-if="designations.length === 0" class="text-xs text-gray-400 p-4 text-center">
                                    No designations registered yet.
                                </div>
                                <div
                                    v-for="des in designations"
                                    :key="des.id"
                                    class="flex items-center justify-between p-2.5 rounded-xl bg-[#fffef9] border border-cream-500/50 text-xs"
                                >
                                    <span class="font-medium text-gray-800">{{ des.name }}</span>
                                    <button
                                        type="button"
                                        @click="deleteDesignation(des)"
                                        class="text-red-500 hover:text-red-700 text-xs font-semibold cursor-pointer"
                                    >
                                        Remove
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tab 4: Form Schemas Section -->
                <div v-if="activeTab === 'forms'">
                    <div v-if="feedbackForms.length === 0 && unconfiguredEvents.length === 0" class="bg-cream-200 rounded-3xl p-12 text-center border border-cream-500/50">
                        <div class="size-16 rounded-full bg-cream-100 text-gray-400 flex items-center justify-center mx-auto mb-4">
                            <svg class="size-8" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 002.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 00-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 00.75-.75 2.25 2.25 0 00-.1-.664m-5.8 0A2.251 2.251 0 0113.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25zM6.75 12h.008v.008H6.75V12zm0 3h.008v.008H6.75V15zm0 3h.008v.008H6.75V18z" />
                            </svg>
                        </div>
                        <h3 class="text-base font-bold text-gray-900">No Feedback Form Schemas Configured</h3>
                        <p class="text-xs text-gray-500 max-w-sm mx-auto mt-1">Configure questionnaire schemas for your events to allow mobile and external applications to fetch evaluation surveys.</p>
                        <button
                            @click="openFormConfigModal()"
                            class="mt-4 inline-flex items-center px-4 py-2 bg-forest-900 hover:bg-forest-950 text-white text-xs font-semibold rounded-xl shadow-xs transition cursor-pointer"
                        >
                            Questionnaire
                        </button>
                    </div>

                    <div v-else class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
                        <!-- Configured Form Schemas -->
                        <div
                            v-for="formItem in feedbackForms"
                            :key="formItem.id"
                            class="bg-cream-200 rounded-2xl border border-cream-500/50 shadow-xs hover:shadow-md transition-all p-5 flex flex-col justify-between"
                        >
                            <div>
                                <div class="flex items-start justify-between gap-2">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-md text-[10px] font-bold uppercase tracking-wider bg-lime-200 text-forest-900 border border-forest-500/20">
                                        Form #{{ formItem.id }}
                                    </span>
                                    <span class="text-xs font-bold text-gray-500">
                                        {{ formItem.schema?.fields ? formItem.schema.fields.length : 0 }} questions
                                    </span>
                                </div>

                                <h3 class="text-base font-bold text-gray-900 mt-2">
                                    {{ formItem.event?.name || 'Event #' + formItem.event_id }}
                                </h3>

                                <div class="mt-3 space-y-1.5 max-h-48 overflow-y-auto pr-1">
                                    <div
                                        v-for="(fld, fldIdx) in formItem.schema?.fields || []"
                                        :key="fldIdx"
                                        class="p-2 bg-[#fffef9] rounded-xl border border-cream-400 text-xs flex items-center justify-between"
                                    >
                                        <div class="flex items-center space-x-2 truncate">
                                            <span class="size-5 rounded-full bg-lime-100 text-forest-900 text-[10px] font-bold flex items-center justify-center shrink-0">
                                                {{ fld.weight || fldIdx + 1 }}
                                            </span>
                                            <span class="font-medium text-gray-800 truncate">{{ fld.particular }}</span>
                                        </div>
                                        <span class="px-1.5 py-0.5 rounded text-[10px] font-mono bg-cream-200 text-gray-600 shrink-0 uppercase">
                                            {{ fld.option_source === 'fb_functions' ? 'functions' : fld.type }}
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <div class="mt-4 pt-3 border-t border-cream-500/40 flex items-center justify-between text-xs text-gray-500">
                                <span>{{ formItem.submissions_count ?? 0 }} submissions</span>
                                <div class="flex items-center space-x-2">
                                    <button
                                        type="button"
                                        @click="openSamplePreviewModal(formItem.event || { id: formItem.event_id }, formItem)"
                                        class="px-2.5 py-1.5 bg-cream-100 hover:bg-cream-200 text-forest-900 border border-cream-400 rounded-xl font-semibold shadow-xs transition cursor-pointer flex items-center space-x-1"
                                        title="View sample completed form"
                                    >
                                        <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" />
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                        </svg>
                                        <span>Preview</span>
                                    </button>

                                    <button
                                        @click="openFormConfigModal(formItem, formItem.event_id)"
                                        class="px-3 py-1.5 bg-forest-900 hover:bg-forest-950 text-white rounded-xl font-semibold shadow-xs transition cursor-pointer"
                                    >
                                        Edit Schema
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Unconfigured Events Prompt Cards -->
                        <div
                            v-for="unEvent in unconfiguredEvents"
                            :key="'unconfigured-' + unEvent.id"
                            class="bg-[#fffef9] rounded-2xl border-2 border-dashed border-cream-500/80 p-5 flex flex-col justify-between hover:border-forest-800 transition"
                        >
                            <div>
                                <div class="flex items-start justify-between gap-2">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-md text-[10px] font-bold uppercase tracking-wider bg-amber-100 text-amber-900 border border-amber-300">
                                        No Schema Configured
                                    </span>
                                    <span class="text-xs font-bold text-gray-400">
                                        Event #{{ unEvent.id }}
                                    </span>
                                </div>

                                <h3 class="text-base font-bold text-gray-900 mt-2">
                                    {{ unEvent.name }}
                                </h3>
                                <p class="text-xs text-gray-500 mt-1">
                                    {{ unEvent.details || 'This event has no feedback questionnaire yet.' }}
                                </p>
                            </div>

                            <div class="mt-4 pt-3 border-t border-cream-400/40 flex items-center justify-between">
                                <span class="text-xs text-gray-400 italic">0 questions</span>
                                <button
                                    @click="openFormConfigModal(null, unEvent.id)"
                                    class="inline-flex items-center px-3 py-1.5 bg-forest-900 hover:bg-forest-950 text-white text-xs font-semibold rounded-xl shadow-xs transition cursor-pointer"
                                >
                                    <svg class="size-3.5 me-1" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                                    </svg>
                                    Questionnaire
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Event Modal (With Multi-Select Functions Assignment) -->
        <div v-if="showEventModal" class="fixed inset-0 z-50 overflow-y-auto bg-gray-900/60 backdrop-blur-xs flex items-center justify-center p-4">
            <div class="bg-cream-200 rounded-3xl max-w-lg w-full shadow-2xl p-6 border border-cream-500/60 max-h-[90vh] flex flex-col">
                <div class="flex items-center justify-between pb-4 border-b border-cream-500/40 shrink-0">
                    <h3 class="text-lg font-bold text-gray-900">
                        {{ editingEvent ? 'Edit Feedback Event' : 'Create Feedback Event' }}
                    </h3>
                    <button @click="closeEventModal" class="text-gray-400 hover:text-gray-600 cursor-pointer">
                        <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <form @submit.prevent="saveEvent" class="space-y-4 mt-4 overflow-y-auto pr-1 flex-1">
                    <div>
                        <label class="block text-xs font-bold text-forest-900 uppercase tracking-wider mb-1.5">Event Name *</label>
                        <input
                            v-model="eventForm.name"
                            type="text"
                            required
                            placeholder="e.g. Nationwide Disaster Preparedness Simulation 2026"
                            class="w-full px-3.5 py-2.5 bg-[#fffef9] border border-cream-500 focus:border-forest-600 focus:ring-2 focus:ring-forest-200 rounded-xl text-gray-900 text-sm transition"
                        />
                        <div v-if="eventForm.errors.name" class="text-xs text-red-600 mt-1 font-medium">{{ eventForm.errors.name }}</div>
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-forest-900 uppercase tracking-wider mb-1.5">Details & Scope</label>
                        <textarea
                            v-model="eventForm.details"
                            rows="2"
                            placeholder="Brief description of event, objectives, and participating units..."
                            class="w-full px-3.5 py-2.5 bg-[#fffef9] border border-cream-500 focus:border-forest-600 focus:ring-2 focus:ring-forest-200 rounded-xl text-gray-900 text-sm transition"
                        ></textarea>
                        <div v-if="eventForm.errors.details" class="text-xs text-red-600 mt-1 font-medium">{{ eventForm.errors.details }}</div>
                    </div>

                    <!-- Assign Functions to Event -->
                    <div>
                        <div class="flex items-center justify-between mb-1.5">
                            <label class="block text-xs font-bold text-forest-900 uppercase tracking-wider">
                                Assign Participant Functions ({{ eventForm.function_ids.length }} selected)
                            </label>
                            <div class="space-x-2 text-[11px]">
                                <button type="button" @click="selectAllFunctionsForEvent" class="text-forest-800 hover:underline font-semibold cursor-pointer">Select All</button>
                                <span class="text-gray-300">|</span>
                                <button type="button" @click="clearAllFunctionsForEvent" class="text-gray-500 hover:underline cursor-pointer">Clear</button>
                            </div>
                        </div>

                        <div v-if="functions.length === 0" class="p-4 bg-[#fffef9] border border-cream-400 rounded-xl text-xs text-gray-500 text-center">
                            No reusable functions created yet. You can add functions under the <strong>Reusable Functions</strong> tab anytime.
                        </div>

                        <div v-else class="max-h-48 overflow-y-auto p-2 bg-[#fffef9] border border-cream-500 rounded-xl space-y-1">
                            <label
                                v-for="fn in functions"
                                :key="fn.id"
                                class="flex items-start space-x-2.5 p-2 rounded-lg hover:bg-cream-100 transition cursor-pointer"
                                :class="eventForm.function_ids.includes(fn.id) ? 'bg-lime-100/50' : ''"
                            >
                                <input
                                    type="checkbox"
                                    :value="fn.id"
                                    v-model="eventForm.function_ids"
                                    class="rounded border-cream-500 text-forest-900 focus:ring-forest-500 mt-0.5"
                                />
                                <div class="flex-1 text-xs">
                                    <div class="flex items-center justify-between">
                                        <span class="font-bold text-gray-900">{{ fn.function }}</span>
                                        <span v-if="fn.function.toLowerCase().includes('other')" class="px-1 py-0.2 rounded text-[9px] font-bold bg-amber-100 text-amber-800 border border-amber-300">
                                            Others
                                        </span>
                                    </div>
                                    <p v-if="fn.details" class="text-[11px] text-gray-500 mt-0.5">{{ fn.details }}</p>
                                </div>
                            </label>
                        </div>
                        <div v-if="eventForm.errors.function_ids" class="text-xs text-red-600 mt-1 font-medium">{{ eventForm.errors.function_ids }}</div>
                    </div>

                    <div class="pt-4 border-t border-cream-500/40 flex items-center justify-end space-x-3 shrink-0">
                        <button
                            type="button"
                            @click="closeEventModal"
                            class="px-4 py-2 bg-cream-100 hover:bg-cream-300 text-gray-800 text-sm font-semibold rounded-xl transition cursor-pointer"
                        >
                            Cancel
                        </button>
                        <button
                            type="submit"
                            :disabled="eventForm.processing"
                            class="px-5 py-2 bg-forest-900 hover:bg-forest-950 text-white text-sm font-semibold rounded-xl shadow-xs transition disabled:opacity-50 cursor-pointer"
                        >
                            {{ eventForm.processing ? 'Saving...' : (editingEvent ? 'Update Event' : 'Create Event & API Key') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Function Modal (Reusable Across Events) -->
        <div v-if="showFunctionModal" class="fixed inset-0 z-50 overflow-y-auto bg-gray-900/60 backdrop-blur-xs flex items-center justify-center p-4">
            <div class="bg-cream-200 rounded-3xl max-w-lg w-full shadow-2xl p-6 border border-cream-500/60 max-h-[90vh] flex flex-col">
                <div class="flex items-center justify-between pb-4 border-b border-cream-500/40 shrink-0">
                    <h3 class="text-lg font-bold text-gray-900">
                        {{ editingFunction ? 'Edit Reusable Function' : 'Create Reusable Function' }}
                    </h3>
                    <button @click="closeFunctionModal" class="text-gray-400 hover:text-gray-600 cursor-pointer">
                        <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <form @submit.prevent="saveFunction" class="space-y-4 mt-4 overflow-y-auto pr-1 flex-1">
                    <div>
                        <label class="block text-xs font-bold text-forest-900 uppercase tracking-wider mb-1.5">Function / Role Label *</label>
                        <input
                            v-model="functionForm.function"
                            type="text"
                            required
                            placeholder="e.g. Incident Commander, Observer, Evaluator, Others"
                            class="w-full px-3.5 py-2.5 bg-[#fffef9] border border-cream-500 focus:border-forest-600 focus:ring-2 focus:ring-forest-200 rounded-xl text-gray-900 text-sm transition"
                        />
                        <div v-if="functionForm.errors.function" class="text-xs text-red-600 mt-1 font-medium">{{ functionForm.errors.function }}</div>
                        <p class="text-[11px] text-gray-500 mt-1">If set to "Others", participants can manually provide their custom role during submission.</p>
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-forest-900 uppercase tracking-wider mb-1.5">Role Details / Description</label>
                        <textarea
                            v-model="functionForm.details"
                            rows="2"
                            placeholder="Optional description or operational instructions..."
                            class="w-full px-3.5 py-2.5 bg-[#fffef9] border border-cream-500 focus:border-forest-600 focus:ring-2 focus:ring-forest-200 rounded-xl text-gray-900 text-sm transition"
                        ></textarea>
                    </div>

                    <!-- Assign Function to Events -->
                    <div>
                        <label class="block text-xs font-bold text-forest-900 uppercase tracking-wider mb-1.5">
                            Assign to Events (Optional)
                        </label>
                        <div v-if="events.length === 0" class="text-xs text-gray-400 p-2 text-center bg-[#fffef9] rounded-xl border border-cream-400">
                            No events created yet. You can assign this function to events later.
                        </div>
                        <div v-else class="max-h-40 overflow-y-auto p-2 bg-[#fffef9] border border-cream-500 rounded-xl space-y-1">
                            <label
                                v-for="ev in events"
                                :key="ev.id"
                                class="flex items-center space-x-2.5 p-1.5 rounded-lg hover:bg-cream-100 transition cursor-pointer"
                            >
                                <input
                                    type="checkbox"
                                    :value="ev.id"
                                    v-model="functionForm.event_ids"
                                    class="rounded border-cream-500 text-forest-900 focus:ring-forest-500"
                                />
                                <span class="text-xs font-medium text-gray-800">{{ ev.name }}</span>
                            </label>
                        </div>
                    </div>

                    <div class="pt-4 border-t border-cream-500/40 flex items-center justify-end space-x-3 shrink-0">
                        <button
                            type="button"
                            @click="closeFunctionModal"
                            class="px-4 py-2 bg-cream-100 hover:bg-cream-300 text-gray-800 text-sm font-semibold rounded-xl transition cursor-pointer"
                        >
                            Cancel
                        </button>
                        <button
                            type="submit"
                            :disabled="functionForm.processing"
                            class="px-5 py-2 bg-forest-900 hover:bg-forest-950 text-white text-sm font-semibold rounded-xl shadow-xs transition disabled:opacity-50 cursor-pointer"
                        >
                            {{ functionForm.processing ? 'Saving...' : (editingFunction ? 'Update Function' : 'Create Function') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Form Configuration Editor Modal (Google Forms-Style Visual Builder) -->
        <QuestionnaireSchemaModal
            :show="showConfigModal"
            :initial-event-id="configTargetEventId"
            :initial-form-record="configTargetFormRecord"
            :events="events"
            :functions="functions"
            :feedback-forms="feedbackForms"
            :agencies="agencies"
            :designations="designations"
            @close="closeConfigModal"
            @saved="closeConfigModal"
            @preview="openSamplePreviewModal"
        />

        <!-- Generic Delete Confirmation Modal -->
        <div v-if="showDeleteConfirm" class="fixed inset-0 z-50 overflow-y-auto bg-gray-900/60 backdrop-blur-xs flex items-center justify-center p-4">
            <div class="bg-cream-200 rounded-3xl max-w-md w-full shadow-2xl p-6 text-center border border-cream-500/60">
                <div class="size-12 rounded-full bg-red-100 text-red-600 flex items-center justify-center mx-auto mb-4">
                    <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.008v.008H12v-.008z" />
                    </svg>
                </div>

                <h3 class="text-lg font-bold text-gray-900">
                    Confirm Deletion
                </h3>
                <p class="text-xs text-gray-600 mt-2">
                    Are you sure you want to delete this {{ deleteTargetType }}?
                    <span v-if="deleteTargetType === 'event'" class="block mt-1 text-gray-500">
                        The event will be soft-deleted. Shared functions and existing submissions will remain intact.
                    </span>
                    <span v-else class="block mt-1 text-gray-500">
                        This action cannot be undone. Records with feedback submissions cannot be deleted.
                    </span>
                </p>

                <div class="mt-6 flex items-center justify-center space-x-3">
                    <button
                        type="button"
                        @click="showDeleteConfirm = false; itemToDelete = null;"
                        class="px-4 py-2 bg-cream-100 hover:bg-cream-300 text-gray-800 text-sm font-semibold rounded-xl transition cursor-pointer"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        @click="performDelete"
                        class="px-5 py-2 bg-red-600 hover:bg-red-700 text-white text-sm font-semibold rounded-xl shadow-xs transition cursor-pointer"
                    >
                        Delete
                    </button>
                </div>
            </div>
        </div>

        <!-- Embed Settings Modal -->
        <div v-if="showEmbedSettingsModal" class="fixed inset-0 z-50 overflow-y-auto bg-gray-900/60 backdrop-blur-xs flex items-center justify-center p-4">
            <div class="bg-cream-100 rounded-3xl max-w-lg w-full shadow-2xl p-6 border border-cream-500/70 space-y-4">
                <div class="flex items-start justify-between border-b border-cream-400 pb-3">
                    <div>
                        <h3 class="text-base font-bold text-gray-900">
                            Configure Allowed Origins
                        </h3>
                        <p class="text-xs text-gray-500 mt-0.5">
                            {{ eventForEmbedSettings?.name }}
                        </p>
                    </div>
                    <button
                        type="button"
                        @click="closeEmbedSettingsModal"
                        class="text-gray-400 hover:text-gray-700 p-1 rounded-lg"
                    >
                        ✕
                    </button>
                </div>

                <form @submit.prevent="saveEmbedSettings" class="space-y-4">
                    <div>
                        <label class="block text-xs font-bold text-forest-900 uppercase tracking-wider mb-1.5">
                            Trusted Parent Domains (Content Security Policy)
                        </label>
                        <p class="text-[11px] text-gray-500 mb-2">
                            Enter the exact origins of external sites permitted to embed this form in an iframe (e.g. <code>https://portal.lgu.gov.ph</code>), one per line.
                        </p>
                        <textarea
                            v-model="embedSettingsForm.allowed_origins_text"
                            rows="4"
                            placeholder="https://example.gov.ph&#10;https://dashboard.lgu.gov.ph"
                            class="w-full px-3.5 py-2.5 bg-[#fffef9] border border-cream-500 focus:border-forest-600 focus:ring-2 focus:ring-forest-200 rounded-xl text-gray-900 text-xs font-mono"
                        ></textarea>
                    </div>

                    <div class="p-3 bg-cream-200/70 rounded-xl border border-cream-400 flex items-center justify-between">
                        <div>
                            <span class="text-xs font-bold text-gray-800 block">Enable Iframe Embedding</span>
                            <span class="text-[11px] text-gray-500 block">Allow external sessions to load and submit feedback</span>
                        </div>
                        <input
                            type="checkbox"
                            v-model="embedSettingsForm.is_active"
                            class="rounded text-forest-900 focus:ring-forest-500 size-4.5"
                        />
                    </div>

                    <div class="pt-3 border-t border-cream-400 flex items-center justify-end space-x-2.5">
                        <button
                            type="button"
                            @click="closeEmbedSettingsModal"
                            class="px-4 py-2 bg-cream-200 hover:bg-cream-300 text-gray-800 text-xs font-semibold rounded-xl transition"
                        >
                            Cancel
                        </button>
                        <button
                            type="submit"
                            :disabled="embedSettingsForm.processing"
                            class="px-5 py-2 bg-forest-900 hover:bg-forest-950 text-white text-xs font-semibold rounded-xl shadow-xs transition disabled:opacity-50"
                        >
                            {{ embedSettingsForm.processing ? 'Saving...' : 'Save Configuration' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Regenerate Embed Public ID Modal -->
        <div v-if="showRegenerateEmbedIdModal" class="fixed inset-0 z-50 overflow-y-auto bg-gray-900/60 backdrop-blur-xs flex items-center justify-center p-4">
            <div class="bg-cream-200 rounded-3xl max-w-md w-full shadow-2xl p-6 text-center border border-cream-500/60 space-y-4">
                <div class="size-12 rounded-full bg-amber-100 text-amber-700 flex items-center justify-center mx-auto shadow-inner">
                    <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99" />
                    </svg>
                </div>

                <h3 class="text-base font-bold text-gray-900">
                    Regenerate Public Embed ID?
                </h3>

                <p class="text-xs text-gray-600 leading-relaxed">
                    Are you sure you want to regenerate the Public Embed ID for <strong>{{ eventForRegenerateId?.name }}</strong>?
                    <span class="block mt-1 text-red-600 font-semibold">
                        Existing websites embedding this form with the old ID will stop loading immediately, and all active iframe sessions will be invalidated.
                    </span>
                </p>

                <div class="pt-2 flex items-center justify-center space-x-3">
                    <button
                        type="button"
                        @click="showRegenerateEmbedIdModal = false; eventForRegenerateId = null;"
                        class="px-4 py-2 bg-cream-100 hover:bg-cream-300 text-gray-800 text-xs font-semibold rounded-xl transition"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        @click="confirmRegenerateEmbedId"
                        class="px-5 py-2 bg-amber-700 hover:bg-amber-800 text-white text-xs font-semibold rounded-xl shadow-xs transition"
                    >
                        Regenerate Public ID
                    </button>
                </div>
            </div>
        </div>

        <!-- Regenerate Client Secret Modal -->
        <div v-if="showRegenerateSecretModal" class="fixed inset-0 z-50 overflow-y-auto bg-gray-900/60 backdrop-blur-xs flex items-center justify-center p-4">
            <div class="bg-cream-200 rounded-3xl max-w-md w-full shadow-2xl p-6 text-center border border-cream-500/60 space-y-4">
                <div class="size-12 rounded-full bg-amber-100 text-amber-700 flex items-center justify-center mx-auto shadow-inner">
                    <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 5.25a3 3 0 013 3m3 0a6 6 0 01-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 1121.75 8.25z" />
                    </svg>
                </div>

                <h3 class="text-base font-bold text-gray-900">
                    Regenerate Client Secret?
                </h3>

                <p class="text-xs text-gray-600 leading-relaxed">
                    Regenerating the integration secret for <strong>{{ eventForRegenerateSecret?.name }}</strong> will invalidate external backend sessions.
                    The new secret will be displayed <strong>once</strong> for you to copy.
                </p>

                <div class="pt-2 flex items-center justify-center space-x-3">
                    <button
                        type="button"
                        @click="showRegenerateSecretModal = false; eventForRegenerateSecret = null;"
                        class="px-4 py-2 bg-cream-100 hover:bg-cream-300 text-gray-800 text-xs font-semibold rounded-xl transition"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        @click="confirmRegenerateSecret"
                        class="px-5 py-2 bg-amber-700 hover:bg-amber-800 text-white text-xs font-semibold rounded-xl shadow-xs transition"
                    >
                        Regenerate Secret
                    </button>
                </div>
            </div>
        </div>

        <!-- One-Time Revealed Client Secret Modal -->
        <div v-if="showRevealedSecretModal && revealedSecret" class="fixed inset-0 z-50 overflow-y-auto bg-gray-900/60 backdrop-blur-xs flex items-center justify-center p-4">
            <div class="bg-[#fffef9] rounded-3xl max-w-lg w-full shadow-2xl p-6 border border-amber-300 space-y-4">
                <div class="flex items-center space-x-3 text-amber-900">
                    <div class="size-10 rounded-full bg-amber-100 flex items-center justify-center shrink-0">
                        <svg class="size-5 text-amber-700" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.008v.008H12v-.008z" />
                        </svg>
                    </div>
                    <div>
                        <h3 class="text-base font-bold text-gray-900">New Client Secret Generated</h3>
                        <p class="text-xs text-amber-800">Copy and store this secret securely now. It will not be shown again.</p>
                    </div>
                </div>

                <div class="p-3 bg-amber-50 rounded-2xl border border-amber-200 space-y-3">
                    <div>
                        <div class="text-[10px] font-bold text-amber-900 uppercase tracking-wider mb-0.5">Client ID</div>
                        <div class="text-xs font-mono text-gray-800 bg-white p-2 rounded-lg border border-amber-300 select-all">
                            {{ revealedSecret.client_id }}
                        </div>
                    </div>

                    <div>
                        <div class="text-[10px] font-bold text-amber-900 uppercase tracking-wider mb-0.5">Client Secret (Store Securely)</div>
                        <div class="flex items-center space-x-1.5">
                            <input
                                type="text"
                                readonly
                                :value="revealedSecret.client_secret"
                                class="flex-1 px-3 py-2 bg-white border border-amber-400 rounded-lg text-xs font-mono font-bold select-all text-gray-900"
                            />
                            <button
                                type="button"
                                @click="copyText(revealedSecret.client_secret, 'revealed_secret')"
                                class="px-3 py-2 bg-forest-900 hover:bg-forest-950 text-white rounded-lg text-xs font-semibold shrink-0 transition"
                            >
                                {{ copiedRef === 'revealed_secret' ? '✓ Copied' : 'Copy Secret' }}
                            </button>
                        </div>
                    </div>
                </div>

                <div class="pt-2 flex items-center justify-end">
                    <button
                        type="button"
                        @click="showRevealedSecretModal = false;"
                        class="px-5 py-2 bg-forest-900 hover:bg-forest-950 text-white text-xs font-bold rounded-xl shadow-xs transition"
                    >
                        I Have Saved the Secret
                    </button>
                </div>
            </div>
        </div>

        <!-- Sample Completed Feedback Form Preview Modal (Deployed & Embedded Views) -->
        <div v-if="showSamplePreviewModal" class="fixed inset-0 z-50 overflow-y-auto bg-gray-950/80 backdrop-blur-sm flex items-center justify-center p-2 sm:p-4 md:p-6">
            <div class="bg-[#faf8f5] rounded-3xl max-w-6xl w-full max-h-[92vh] shadow-2xl border border-cream-500/70 flex flex-col overflow-hidden animate-in fade-in duration-200">
                <!-- Top Navigation & Controls Bar -->
                <div class="p-4 sm:p-5 bg-[#fffef9] border-b border-cream-500/60 shrink-0 space-y-3">
                    <div class="flex items-center justify-between flex-wrap gap-3">
                        <div class="flex items-center space-x-3">
                            <div class="size-10 rounded-2xl bg-forest-900 text-emerald-200 flex items-center justify-center font-bold text-lg shadow-sm">
                                👁️
                            </div>
                            <div>
                                <div class="flex items-center space-x-2">
                                    <h3 class="text-base sm:text-lg font-black text-gray-900">
                                        Feedback Form Sample View
                                    </h3>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold uppercase tracking-wider bg-lime-100 text-forest-900 border border-lime-300">
                                        Live Sample Preview
                                    </span>
                                </div>
                                <p class="text-xs text-gray-500 mt-0.5">
                                    Simulating the completed questionnaire experience as seen by participants in deployed or embedded environments.
                                </p>
                            </div>
                        </div>

                        <!-- Top Right Quick Links & Close -->
                        <div class="flex items-center space-x-2.5">
                            <a
                                :href="route('feedback.form')"
                                target="_blank"
                                class="inline-flex items-center space-x-1 px-3 py-1.5 bg-cream-100 hover:bg-cream-200 text-forest-900 border border-cream-400 rounded-xl text-xs font-semibold transition"
                                title="Open the live deployed feedback portal in a new browser tab"
                            >
                                <span>Open Live Deployed</span>
                                <svg class="size-3" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" />
                                </svg>
                            </a>

                            <a
                                v-if="previewEvent?.embed?.public_id"
                                :href="route('feedback.embed.show', previewEvent.embed.public_id)"
                                target="_blank"
                                class="inline-flex items-center space-x-1 px-3 py-1.5 bg-cream-100 hover:bg-cream-200 text-forest-900 border border-cream-400 rounded-xl text-xs font-semibold transition"
                                title="Open the live embed standalone URL in a new browser tab"
                            >
                                <span>Open Live Embed</span>
                                <svg class="size-3" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" />
                                </svg>
                            </a>

                            <button
                                type="button"
                                @click="closeSamplePreviewModal"
                                class="p-2 text-gray-400 hover:text-gray-700 hover:bg-cream-200 rounded-xl transition cursor-pointer"
                                title="Close Sample Preview"
                            >
                                <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>
                    </div>

                    <!-- Environment & Mode Switchers Toolbar -->
                    <div class="flex items-center justify-between flex-wrap gap-2.5 pt-2 border-t border-cream-500/30">
                        <div class="flex items-center flex-wrap gap-2">
                            <!-- Event Selector -->
                            <div class="flex items-center space-x-1.5 bg-cream-100 px-3 py-1.5 rounded-xl border border-cream-400 text-xs font-semibold text-gray-800">
                                <span class="text-gray-500">Event:</span>
                                <select
                                    v-model="previewSelectedEventId"
                                    @change="onPreviewEventChange"
                                    class="bg-transparent border-none text-xs font-bold text-forest-900 focus:ring-0 p-0 pr-6 cursor-pointer"
                                >
                                    <option v-for="ev in events" :key="ev.id" :value="ev.id">
                                        {{ ev.name }} (ID: {{ ev.id }})
                                    </option>
                                </select>
                            </div>

                            <!-- Mode Switcher: Deployed vs Embedded -->
                            <div class="bg-cream-200 p-1 rounded-xl flex items-center space-x-1 border border-cream-400">
                                <button
                                    type="button"
                                    @click="previewMode = 'deployed'"
                                    class="px-3 py-1.5 text-xs font-bold rounded-lg transition cursor-pointer flex items-center space-x-1.5"
                                    :class="previewMode === 'deployed' ? 'bg-forest-900 text-white shadow-xs' : 'text-gray-700 hover:text-gray-900'"
                                >
                                    <span>🌐</span>
                                    <span>Deployed View (Web Portal)</span>
                                </button>
                                <button
                                    type="button"
                                    @click="previewMode = 'embedded'"
                                    class="px-3 py-1.5 text-xs font-bold rounded-lg transition cursor-pointer flex items-center space-x-1.5"
                                    :class="previewMode === 'embedded' ? 'bg-forest-900 text-white shadow-xs' : 'text-gray-700 hover:text-gray-900'"
                                >
                                    <span>📦</span>
                                    <span>Embedded View (Iframe Mockup)</span>
                                </button>
                            </div>
                        </div>

                        <!-- Right Options: Sample Filled vs Blank & Device Switcher -->
                        <div class="flex items-center flex-wrap gap-2">
                            <!-- Device Viewport Switcher (Active in Embedded Mode) -->
                            <div v-if="previewMode === 'embedded'" class="bg-cream-200 p-1 rounded-xl flex items-center space-x-1 border border-cream-400">
                                <button
                                    type="button"
                                    @click="previewDevice = 'desktop'"
                                    class="px-2.5 py-1 text-xs font-semibold rounded-lg transition cursor-pointer flex items-center space-x-1"
                                    :class="previewDevice === 'desktop' ? 'bg-forest-900 text-white shadow-xs' : 'text-gray-700 hover:text-gray-900'"
                                    title="Desktop Viewport (100% full width)"
                                >
                                    <span>🖥️ Desktop</span>
                                </button>
                                <button
                                    type="button"
                                    @click="previewDevice = 'tablet'"
                                    class="px-2.5 py-1 text-xs font-semibold rounded-lg transition cursor-pointer flex items-center space-x-1"
                                    :class="previewDevice === 'tablet' ? 'bg-forest-900 text-white shadow-xs' : 'text-gray-700 hover:text-gray-900'"
                                    title="Tablet Viewport (768px width)"
                                >
                                    <span>📱 Tablet (768px)</span>
                                </button>
                                <button
                                    type="button"
                                    @click="previewDevice = 'mobile'"
                                    class="px-2.5 py-1 text-xs font-semibold rounded-lg transition cursor-pointer flex items-center space-x-1"
                                    :class="previewDevice === 'mobile' ? 'bg-forest-900 text-white shadow-xs' : 'text-gray-700 hover:text-gray-900'"
                                    title="Mobile Viewport (375px width)"
                                >
                                    <span>📱 Mobile (375px)</span>
                                </button>
                            </div>

                            <!-- Fill State Toggle (Sample Completed vs Blank) -->
                            <div class="bg-cream-200 p-1 rounded-xl flex items-center space-x-1 border border-cream-400">
                                <button
                                    type="button"
                                    @click="resetPreviewAnswers('completed')"
                                    class="px-3 py-1 text-xs font-bold rounded-lg transition cursor-pointer flex items-center space-x-1"
                                    :class="previewFillState === 'completed' ? 'bg-forest-900 text-white shadow-xs' : 'text-gray-700 hover:text-gray-900'"
                                >
                                    <span>✓</span>
                                    <span>Sample Completed</span>
                                </button>
                                <button
                                    type="button"
                                    @click="resetPreviewAnswers('blank')"
                                    class="px-3 py-1 text-xs font-bold rounded-lg transition cursor-pointer flex items-center space-x-1"
                                    :class="previewFillState === 'blank' ? 'bg-forest-900 text-white shadow-xs' : 'text-gray-700 hover:text-gray-900'"
                                >
                                    <span>⬜</span>
                                    <span>Blank Form</span>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Scrollable Preview Canvas Body -->
                <div class="flex-1 overflow-y-auto p-4 sm:p-6 lg:p-8 bg-[#faf8f5]/60">
                    <!-- Notice Banner -->
                    <div class="mb-6 p-3 bg-amber-50 border border-amber-300 rounded-2xl text-xs text-amber-900 flex items-center justify-between shadow-2xs">
                        <div class="flex items-center space-x-2">
                            <span class="text-base">💡</span>
                            <span>
                                <strong>Interactive Simulation:</strong> You are viewing a sample representation of this feedback questionnaire.
                                <span v-if="previewFillState === 'completed'"> All fields are pre-filled with representative participant answers.</span>
                                <span v-else> Fields are empty to let you test manual typing and option selections.</span>
                            </span>
                        </div>
                        <span class="text-[11px] font-mono text-amber-700 uppercase tracking-wider shrink-0 hidden sm:inline">
                            {{ previewMode === 'deployed' ? 'Deployed Portal Layout' : 'Embedded Iframe Layout' }}
                        </span>
                    </div>

                    <!-- Empty State If Schema Has No Fields -->
                    <div v-if="!previewFields.length" class="bg-cream-200 rounded-3xl p-12 text-center border border-cream-500/50 shadow-sm max-w-xl mx-auto">
                        <div class="size-16 rounded-full bg-cream-100 text-gray-400 flex items-center justify-center mx-auto mb-4 text-2xl font-bold">
                            📝
                        </div>
                        <h3 class="text-base font-bold text-gray-900">No Questionnaire Configured</h3>
                        <p class="text-xs text-gray-600 max-w-sm mx-auto mt-1">
                            This event currently has no active questionnaire fields configured. Configure questions in the Visual Builder to see a live preview.
                        </p>
                    </div>

                    <!-- MODE 1: DEPLOYED STANDALONE VIEW -->
                    <div v-else-if="previewMode === 'deployed'" class="max-w-4xl mx-auto space-y-6">
                        <!-- Simulated Top Web Header -->
                        <div class="bg-white/80 rounded-2xl px-5 py-3 border border-cream-400 shadow-2xs flex items-center justify-between text-xs text-gray-600">
                            <div class="flex items-center space-x-2">
                                <span class="size-2 rounded-full bg-emerald-500"></span>
                                <span class="font-bold text-gray-800">Login Portal — Participant Feedback System</span>
                            </div>
                            <span class="font-mono text-[11px] text-gray-500">Route: /feedback</span>
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
                                        {{ previewEvent?.name || 'Evaluation Feedback Form' }}
                                    </h1>
                                    <p
                                        v-if="previewEvent?.details"
                                        class="text-sm mt-2.5 leading-relaxed max-w-2xl font-medium"
                                        style="color: #dcfce7;"
                                    >
                                        {{ previewEvent.details }}
                                    </p>
                                </div>
                            </div>
                        </div>

                        <!-- Questionnaire Fields Card -->
                        <div class="bg-[#fffef9] rounded-3xl p-6 sm:p-8 border border-cream-500/70 shadow-sm space-y-6">
                            <!-- Multi-Page Progress Indicator -->
                            <div v-if="isPreviewPaginated" class="border-b border-cream-400/50 pb-4 space-y-2">
                                <div class="flex items-center justify-between text-xs font-bold text-forest-900">
                                    <span>{{ previewCurrentPage?.section?.particular || `Section ${previewCurrentPageIdx + 1}` }}</span>
                                    <span v-if="previewForm?.schema?.pagination?.show_section_numbers !== false">
                                        Section {{ previewCurrentPageIdx + 1 }} of {{ previewSectionPages.length }}
                                    </span>
                                </div>
                                <p v-if="previewCurrentPage?.section?.description" class="text-xs text-gray-600">
                                    {{ previewCurrentPage.section.description }}
                                </p>
                                <div v-if="previewForm?.schema?.pagination?.progress_bar !== false" class="h-2 w-full bg-cream-200 rounded-full overflow-hidden">
                                    <div
                                        class="h-full bg-forest-900 transition-all duration-300 rounded-full"
                                        :style="{ width: `${Math.round(((previewCurrentPageIdx + 1) / previewSectionPages.length) * 100)}%` }"
                                    ></div>
                                </div>
                            </div>
                            <div v-else class="border-b border-cream-400/50 pb-3 flex items-center justify-between">
                                <div>
                                    <h3 class="text-base font-bold text-gray-900">
                                        Feedback Questionnaire
                                    </h3>
                                    <p class="text-xs text-gray-500 mt-0.5">Please provide your honest answers and ratings for each item below.</p>
                                </div>
                                <span class="text-xs font-bold text-forest-900 bg-lime-100 px-2.5 py-1 rounded-lg border border-lime-300">
                                    {{ previewFields.length }} Item{{ previewFields.length > 1 ? 's' : '' }}
                                </span>
                            </div>

                            <div class="space-y-6">
                                <template
                                    v-for="(field, index) in (isPreviewPaginated ? (previewCurrentPage?.fields || []) : previewFields)"
                                    :key="'deployed-' + field.id"
                                >
                                    <!-- Section Header / Divider -->
                                    <div
                                        v-if="field.type === 'section'"
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

                                    <!-- Regular Question Card -->
                                    <div
                                        v-else-if="isPreviewFieldConditionMet(field)"
                                        class="p-5 rounded-2xl border bg-cream-100/50 border-cream-400/60 hover:border-forest-600/40 space-y-3 transition"
                                    >
                                    <div class="flex items-start justify-between gap-3">
                                        <div class="flex items-center space-x-2">
                                            <span class="size-6 rounded-full bg-forest-900 text-emerald-200 text-xs font-bold flex items-center justify-center shrink-0">
                                                {{ field.weight || index + 1 }}
                                            </span>
                                            <label class="text-sm font-bold text-gray-900">
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
                                            v-model="previewAnswers[field.id]"
                                            type="text"
                                            :placeholder="field.placeholder || `Enter ${field.particular}...`"
                                            class="w-full px-3.5 py-2.5 bg-[#fffef9] border border-cream-500 focus:border-forest-600 focus:ring-2 focus:ring-forest-200 rounded-xl text-gray-900 text-xs transition font-medium"
                                        />
                                    </div>

                                    <!-- Textarea Input -->
                                    <div v-else-if="field.type === 'textarea'">
                                        <textarea
                                            v-model="previewAnswers[field.id]"
                                            rows="3"
                                            :placeholder="field.placeholder || `Enter ${field.particular}...`"
                                            class="w-full px-3.5 py-2.5 bg-[#fffef9] border border-cream-500 focus:border-forest-600 focus:ring-2 focus:ring-forest-200 rounded-xl text-gray-900 text-xs transition font-medium"
                                        ></textarea>
                                    </div>

                                    <!-- Number Input -->
                                    <div v-else-if="field.type === 'number'">
                                        <input
                                            v-model.number="previewAnswers[field.id]"
                                            type="number"
                                            :min="field.min !== null && field.min !== undefined ? field.min : undefined"
                                            :max="field.max !== null && field.max !== undefined ? field.max : undefined"
                                            :placeholder="field.placeholder || '0'"
                                            class="w-full px-3.5 py-2.5 bg-[#fffef9] border border-cream-500 focus:border-forest-600 focus:ring-2 focus:ring-forest-200 rounded-xl text-gray-900 text-xs transition font-medium"
                                        />
                                    </div>

                                    <!-- Date Input -->
                                    <div v-else-if="field.type === 'date'">
                                        <input
                                            v-model="previewAnswers[field.id]"
                                            type="date"
                                            class="w-full sm:w-64 px-3.5 py-2.5 bg-[#fffef9] border border-cream-500 focus:border-forest-600 focus:ring-2 focus:ring-forest-200 rounded-xl text-gray-900 text-xs transition font-medium"
                                        />
                                    </div>

                                    <!-- Radio Option Tiles -->
                                    <div v-else-if="field.type === 'radio'" class="space-y-3">
                                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2.5">
                                            <label
                                                v-for="opt in getPreviewFieldOptions(field)"
                                                :key="opt.value"
                                                class="flex items-center space-x-3 p-3 rounded-xl border transition cursor-pointer"
                                                :class="String(previewAnswers[field.id]) === String(opt.value) ? 'bg-forest-900 text-white border-forest-950 shadow-xs font-semibold' : 'bg-[#fffef9] text-gray-800 border-cream-400 hover:bg-cream-200/60'"
                                            >
                                                <input
                                                    type="radio"
                                                    :name="`deployed_field_${field.id}`"
                                                    :value="opt.value"
                                                    v-model="previewAnswers[field.id]"
                                                    class="text-forest-900 focus:ring-forest-500 size-4 shrink-0"
                                                />
                                                <span class="text-xs">{{ opt.label }}</span>
                                            </label>
                                        </div>

                                        <!-- If allow_other is true and an "Other" option is selected -->
                                        <div v-if="isPreviewOtherSelected(field)" class="p-3 bg-amber-50/70 border border-amber-300 rounded-xl space-y-1.5">
                                            <label class="block text-xs font-bold text-amber-900">
                                                Please specify {{ field.particular }}
                                            </label>
                                            <input
                                                v-model="previewAnswers[`${field.id}_other`]"
                                                type="text"
                                                placeholder="Please specify details..."
                                                class="w-full px-3.5 py-2 bg-[#fffef9] border border-amber-400 focus:border-forest-600 focus:ring-2 focus:ring-forest-200 rounded-lg text-gray-900 text-xs font-medium"
                                            />
                                        </div>
                                    </div>

                                    <!-- Dropdown Select -->
                                    <div v-else-if="field.type === 'select'" class="space-y-3">
                                        <select
                                            v-model="previewAnswers[field.id]"
                                            class="w-full px-3.5 py-2.5 bg-[#fffef9] border border-cream-500 focus:border-forest-600 focus:ring-2 focus:ring-forest-200 rounded-xl text-gray-900 text-xs transition font-medium"
                                        >
                                            <option value="" disabled>-- Select {{ field.particular }} --</option>
                                            <option v-for="opt in getPreviewFieldOptions(field)" :key="opt.value" :value="opt.value">
                                                {{ opt.label }}
                                            </option>
                                        </select>

                                        <!-- If allow_other is true and an "Other" option is selected -->
                                        <div v-if="isPreviewOtherSelected(field)" class="p-3 bg-amber-50/70 border border-amber-300 rounded-xl space-y-1.5">
                                            <label class="block text-xs font-bold text-amber-900">
                                                Please specify {{ field.particular }}
                                            </label>
                                            <input
                                                v-model="previewAnswers[`${field.id}_other`]"
                                                type="text"
                                                placeholder="Please specify details..."
                                                class="w-full px-3.5 py-2 bg-[#fffef9] border border-amber-400 focus:border-forest-600 focus:ring-2 focus:ring-forest-200 rounded-lg text-gray-900 text-xs font-medium"
                                            />
                                        </div>
                                    </div>

                                    <!-- Checkbox Multi-Choice Tiles -->
                                    <div v-else-if="field.type === 'checkbox'" class="space-y-3">
                                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2.5">
                                            <label
                                                v-for="opt in getPreviewFieldOptions(field)"
                                                :key="opt.value"
                                                class="flex items-center space-x-3 p-3 rounded-xl border transition cursor-pointer"
                                                :class="(previewAnswers[field.id] || []).includes(opt.value) ? 'bg-forest-900 text-white border-forest-950 shadow-xs font-semibold' : 'bg-[#fffef9] text-gray-800 border-cream-400 hover:bg-cream-200/60'"
                                            >
                                                <input
                                                    type="checkbox"
                                                    :value="opt.value"
                                                    :checked="(previewAnswers[field.id] || []).includes(opt.value)"
                                                    @change="handlePreviewCheckboxToggle(field.id, opt.value)"
                                                    class="rounded text-forest-900 focus:ring-forest-500 size-4 shrink-0"
                                                />
                                                <span class="text-xs">{{ opt.label }}</span>
                                            </label>
                                        </div>

                                        <!-- If allow_other is true and an "Other" option is selected -->
                                        <div v-if="isPreviewOtherSelected(field)" class="p-3 bg-amber-50/70 border border-amber-300 rounded-xl space-y-1.5">
                                            <label class="block text-xs font-bold text-amber-900">
                                                Please specify {{ field.particular }}
                                            </label>
                                            <input
                                                v-model="previewAnswers[`${field.id}_other`]"
                                                type="text"
                                                placeholder="Please specify details..."
                                                class="w-full px-3.5 py-2 bg-[#fffef9] border border-amber-400 focus:border-forest-600 focus:ring-2 focus:ring-forest-200 rounded-lg text-gray-900 text-xs font-medium"
                                            />
                                        </div>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>

                        <!-- Simulated Action Bar -->
                        <div class="bg-[#fffef9] rounded-3xl p-6 border border-cream-500/70 shadow-sm flex flex-col sm:flex-row items-center justify-between gap-4">
                            <div class="text-xs text-gray-500 text-center sm:text-left">
                                <span>Your submission will be securely recorded under <strong>{{ previewEvent?.name }}</strong>.</span>
                            </div>

                            <div class="flex items-center space-x-3 w-full sm:w-auto">
                                <button
                                    v-if="isPreviewPaginated && previewPageHistory.length > 1"
                                    type="button"
                                    @click="goToPreviewPreviousPage"
                                    class="flex-1 sm:flex-initial text-center px-4 py-2 bg-cream-100 hover:bg-cream-200 text-gray-700 text-xs font-bold rounded-xl border border-cream-400 transition cursor-pointer"
                                >
                                    ← Back
                                </button>
                                <span v-else class="px-4 py-2 bg-cream-100 text-gray-500 text-xs font-semibold rounded-xl border border-cream-300 select-none">
                                    Cancel
                                </span>

                                <button
                                    v-if="isPreviewPaginated && !isPreviewLastPage"
                                    type="button"
                                    @click="goToPreviewNextPage"
                                    class="flex-1 sm:flex-initial inline-flex items-center justify-center px-6 py-2.5 bg-forest-900 hover:bg-forest-950 text-white text-xs font-bold rounded-xl shadow-md transition cursor-pointer"
                                >
                                    <span>Next Page →</span>
                                </button>
                                <span
                                    v-else
                                    class="inline-flex items-center justify-center px-6 py-2.5 bg-forest-900 text-white text-xs font-bold rounded-xl shadow-md select-none opacity-90 cursor-not-allowed"
                                >
                                    Submit Evaluation Feedback
                                </span>
                            </div>
                        </div>
                    </div>

                    <!-- MODE 2: EMBEDDED IFRAME MOCKUP VIEW -->
                    <div v-else-if="previewMode === 'embedded'" class="w-full transition-all duration-300">
                        <!-- Simulated Parent Website Frame / Browser Window Mockup -->
                        <div
                            class="bg-[#fffef9] rounded-3xl border border-gray-300 shadow-xl overflow-hidden transition-all duration-300 mx-auto"
                            :class="[
                                previewDevice === 'desktop' ? 'max-w-4xl' : (previewDevice === 'tablet' ? 'max-w-[768px]' : 'max-w-[375px]')
                            ]"
                        >
                            <!-- Mock Browser Window Title Bar -->
                            <div class="bg-gray-100 px-4 py-3 border-b border-gray-200 flex items-center justify-between">
                                <div class="flex items-center space-x-2">
                                    <span class="size-3 rounded-full bg-red-400"></span>
                                    <span class="size-3 rounded-full bg-amber-400"></span>
                                    <span class="size-3 rounded-full bg-emerald-400"></span>
                                    <span class="text-[11px] font-mono text-gray-400 ml-2">External Host Website (Portal Mockup)</span>
                                </div>
                                <div class="px-3 py-1 bg-white rounded-lg border border-gray-300 text-[11px] font-mono text-gray-600 truncate max-w-xs">
                                    https://agency.gov.ph/services/feedback-window
                                </div>
                                <div class="text-[10px] font-bold text-gray-500 uppercase">
                                    {{ previewDevice }}
                                </div>
                            </div>

                            <!-- Mock Host Website Content Container -->
                            <div class="p-4 sm:p-6 bg-gray-50 border-b border-gray-200 space-y-2">
                                <div class="flex items-center justify-between">
                                    <h4 class="text-sm font-bold text-gray-900 flex items-center space-x-1.5">
                                        <span>🏛️</span>
                                        <span>Agency Intranet & Public Portal</span>
                                    </h4>
                                    <span class="text-[10px] font-mono bg-blue-100 text-blue-800 px-2 py-0.5 rounded font-bold">
                                        Parent Iframe Container
                                    </span>
                                </div>
                                <p class="text-xs text-gray-600">
                                    The feedback questionnaire below is embedded seamlessly via secure iframe with dynamic postMessage cross-origin communication.
                                </p>
                            </div>

                            <!-- The Simulated Iframe Container Area -->
                            <div class="p-3 sm:p-5 bg-[#faf8f5]">
                                <div class="border-2 border-dashed border-forest-600/30 rounded-2xl p-3 sm:p-6 bg-[#faf8f5] space-y-5">
                                    <!-- Embedded Header Card (Matches Feedback/Embed.vue) -->
                                    <div class="bg-forest-900 rounded-2xl p-5 text-white shadow-md">
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold uppercase tracking-wider bg-yellow-300 text-forest-950 mb-2">
                                            Embedded Evaluation
                                        </span>
                                        <h2 class="text-lg sm:text-xl font-black">
                                            {{ previewEvent?.name }}
                                        </h2>
                                        <p v-if="previewEvent?.details" class="text-xs text-emerald-100 mt-1 line-clamp-2">
                                            {{ previewEvent.details }}
                                        </p>
                                    </div>

                                    <!-- Multi-Page Progress Indicator -->
                                    <div v-if="isPreviewPaginated" class="border-b border-cream-400/50 pb-3 space-y-1.5">
                                        <div class="flex items-center justify-between text-xs font-bold text-forest-900">
                                            <span>{{ previewCurrentPage?.section?.particular || `Section ${previewCurrentPageIdx + 1}` }}</span>
                                            <span v-if="previewForm?.schema?.pagination?.show_section_numbers !== false">
                                                Section {{ previewCurrentPageIdx + 1 }} of {{ previewSectionPages.length }}
                                            </span>
                                        </div>
                                        <div v-if="previewForm?.schema?.pagination?.progress_bar !== false" class="h-1.5 w-full bg-cream-200 rounded-full overflow-hidden">
                                            <div
                                                class="h-full bg-forest-900 transition-all duration-300 rounded-full"
                                                :style="{ width: `${Math.round(((previewCurrentPageIdx + 1) / previewSectionPages.length) * 100)}%` }"
                                            ></div>
                                        </div>
                                    </div>

                                    <!-- Embedded Questions -->
                                    <div class="space-y-4">
                                        <template
                                            v-for="(field, index) in (isPreviewPaginated ? (previewCurrentPage?.fields || []) : previewFields)"
                                            :key="'embed-' + field.id"
                                        >
                                            <!-- Section Header / Divider in Embed -->
                                            <div
                                                v-if="field.type === 'section'"
                                                class="pt-3 pb-1 border-b border-forest-900/15 first:pt-0"
                                            >
                                                <h3 class="text-sm font-bold text-forest-950">
                                                    {{ field.particular }}
                                                </h3>
                                                <p v-if="field.description" class="text-xs text-gray-500 mt-0.5">
                                                    {{ field.description }}
                                                </p>
                                            </div>

                                            <div
                                                v-else-if="isPreviewFieldConditionMet(field)"
                                                class="p-4 bg-white rounded-xl border border-cream-400 space-y-2.5 shadow-2xs"
                                            >
                                            <div class="flex items-start justify-between gap-2">
                                                <div class="flex items-center space-x-2">
                                                    <span class="size-5 rounded-full bg-forest-900 text-white text-[11px] font-bold flex items-center justify-center shrink-0">
                                                        {{ field.weight || index + 1 }}
                                                    </span>
                                                    <label class="text-xs font-bold text-gray-900">
                                                        {{ field.particular }}
                                                        <span v-if="field.required" class="text-red-500">*</span>
                                                    </label>
                                                </div>
                                                <span class="px-1.5 py-0.5 rounded text-[9px] font-mono uppercase bg-gray-100 text-gray-500">
                                                    {{ field.type }}
                                                </span>
                                            </div>

                                            <!-- Field Input for Embed Preview -->
                                            <div v-if="field.type === 'text'">
                                                <input
                                                    v-model="previewAnswers[field.id]"
                                                    type="text"
                                                    :placeholder="field.placeholder || `Enter ${field.particular}...`"
                                                    class="w-full px-3 py-2 bg-[#fffef9] border border-gray-300 rounded-lg text-gray-900 text-xs"
                                                />
                                            </div>

                                            <div v-else-if="field.type === 'textarea'">
                                                <textarea
                                                    v-model="previewAnswers[field.id]"
                                                    rows="2"
                                                    :placeholder="field.placeholder || `Enter ${field.particular}...`"
                                                    class="w-full px-3 py-2 bg-[#fffef9] border border-gray-300 rounded-lg text-gray-900 text-xs"
                                                ></textarea>
                                            </div>

                                            <div v-else-if="field.type === 'number'">
                                                <input
                                                    v-model.number="previewAnswers[field.id]"
                                                    type="number"
                                                    class="w-full px-3 py-2 bg-[#fffef9] border border-gray-300 rounded-lg text-gray-900 text-xs"
                                                />
                                            </div>

                                            <div v-else-if="field.type === 'date'">
                                                <input
                                                    v-model="previewAnswers[field.id]"
                                                    type="date"
                                                    class="w-full sm:w-64 px-3 py-2 bg-[#fffef9] border border-gray-300 rounded-lg text-gray-900 text-xs"
                                                />
                                            </div>

                                            <div v-else-if="field.type === 'radio'" class="space-y-2">
                                                <div class="grid grid-cols-1 gap-2">
                                                    <label
                                                        v-for="opt in getPreviewFieldOptions(field)"
                                                        :key="opt.value"
                                                        class="flex items-center space-x-2.5 p-2 rounded-lg border text-xs cursor-pointer transition"
                                                        :class="String(previewAnswers[field.id]) === String(opt.value) ? 'bg-forest-900 text-white font-semibold' : 'bg-[#fffef9] text-gray-800 border-gray-200'"
                                                    >
                                                        <input
                                                            type="radio"
                                                            :name="`embed_field_${field.id}`"
                                                            :value="opt.value"
                                                            v-model="previewAnswers[field.id]"
                                                            class="size-3.5 text-forest-900"
                                                        />
                                                        <span>{{ opt.label }}</span>
                                                    </label>
                                                </div>

                                                <div v-if="isPreviewOtherSelected(field)" class="p-2 bg-amber-50 rounded-lg border border-amber-300 space-y-1">
                                                    <label class="text-[11px] font-bold text-amber-900">Please specify details:</label>
                                                    <input
                                                        v-model="previewAnswers[`${field.id}_other`]"
                                                        type="text"
                                                        class="w-full px-2.5 py-1.5 bg-white border border-amber-400 rounded text-xs"
                                                    />
                                                </div>
                                            </div>

                                            <div v-else-if="field.type === 'select'" class="space-y-2">
                                                <select
                                                    v-model="previewAnswers[field.id]"
                                                    class="w-full px-3 py-2 bg-[#fffef9] border border-gray-300 rounded-lg text-gray-900 text-xs"
                                                >
                                                    <option value="" disabled>-- Select {{ field.particular }} --</option>
                                                    <option v-for="opt in getPreviewFieldOptions(field)" :key="opt.value" :value="opt.value">
                                                        {{ opt.label }}
                                                    </option>
                                                </select>

                                                <div v-if="isPreviewOtherSelected(field)" class="p-2 bg-amber-50 rounded-lg border border-amber-300 space-y-1">
                                                    <label class="text-[11px] font-bold text-amber-900">Please specify details:</label>
                                                    <input
                                                        v-model="previewAnswers[`${field.id}_other`]"
                                                        type="text"
                                                        class="w-full px-2.5 py-1.5 bg-white border border-amber-400 rounded text-xs"
                                                    />
                                                </div>
                                            </div>

                                            <div v-else-if="field.type === 'checkbox'" class="space-y-2">
                                                <div class="grid grid-cols-1 gap-2">
                                                    <label
                                                        v-for="opt in getPreviewFieldOptions(field)"
                                                        :key="opt.value"
                                                        class="flex items-center space-x-2.5 p-2 rounded-lg border text-xs cursor-pointer transition"
                                                        :class="(previewAnswers[field.id] || []).includes(opt.value) ? 'bg-forest-900 text-white font-semibold' : 'bg-[#fffef9] text-gray-800 border-gray-200'"
                                                    >
                                                        <input
                                                            type="checkbox"
                                                            :value="opt.value"
                                                            :checked="(previewAnswers[field.id] || []).includes(opt.value)"
                                                            @change="handlePreviewCheckboxToggle(field.id, opt.value)"
                                                            class="size-3.5 rounded text-forest-900"
                                                        />
                                                        <span>{{ opt.label }}</span>
                                                    </label>
                                                </div>

                                                <div v-if="isPreviewOtherSelected(field)" class="p-2 bg-amber-50 rounded-lg border border-amber-300 space-y-1">
                                                    <label class="text-[11px] font-bold text-amber-900">Please specify details:</label>
                                                    <input
                                                        v-model="previewAnswers[`${field.id}_other`]"
                                                        type="text"
                                                        class="w-full px-2.5 py-1.5 bg-white border border-amber-400 rounded text-xs"
                                                    />
                                                </div>
                                            </div>
                                        </div>
                                    </template>
                                </div>

                                    <!-- Embedded Submit / Navigation Buttons -->
                                    <div class="pt-2 flex items-center justify-between gap-3">
                                        <button
                                            v-if="isPreviewPaginated && previewPageHistory.length > 1"
                                            type="button"
                                            @click="goToPreviewPreviousPage"
                                            class="px-4 py-2 bg-cream-100 hover:bg-cream-200 text-gray-700 text-xs font-bold rounded-xl border border-cream-400 transition cursor-pointer"
                                        >
                                            ← Back
                                        </button>
                                        <div v-else></div>

                                        <button
                                            v-if="isPreviewPaginated && !isPreviewLastPage"
                                            type="button"
                                            @click="goToPreviewNextPage"
                                            class="px-6 py-2 bg-forest-900 hover:bg-forest-950 text-white text-xs font-bold rounded-xl shadow-sm transition cursor-pointer"
                                        >
                                            Next Page →
                                        </button>
                                        <span
                                            v-else
                                            class="w-full sm:w-auto text-center px-6 py-2.5 bg-forest-900 text-white rounded-xl text-xs font-bold shadow-sm select-none opacity-90 cursor-not-allowed"
                                        >
                                            Submit Evaluation Feedback
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Footer Summary Bar -->
                <div class="p-4 bg-[#fffef9] border-t border-cream-500/60 shrink-0 flex items-center justify-between text-xs text-gray-500">
                    <div class="flex items-center space-x-2">
                        <span class="font-bold text-gray-800">{{ previewEvent?.name }}</span>
                        <span>•</span>
                        <span>{{ previewFields.length }} Questionnaire Questions</span>
                        <span>•</span>
                        <span class="capitalize text-forest-900 font-semibold">{{ previewMode }} View</span>
                    </div>

                    <div class="flex items-center space-x-3">
                        <button
                            type="button"
                            @click="closeSamplePreviewModal"
                            class="px-4 py-2 bg-cream-100 hover:bg-cream-300 text-gray-800 text-xs font-semibold rounded-xl transition cursor-pointer"
                        >
                            Close Preview
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

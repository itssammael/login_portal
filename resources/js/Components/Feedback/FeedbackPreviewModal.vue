<script setup>
import { computed, ref, watch } from 'vue';
import { sanitizeHtml } from '@/Utils/htmlSanitizer';

const props = defineProps({
    form: { type: Object, default: null },
});

const emit = defineEmits(['close']);
const device = ref('desktop');
const mode = ref('deployed');
const state = ref('completed');
const pageIndex = ref(0);
const answers = ref({});

const fields = computed(() => [...(props.form?.schema?.fields || [])]
    .sort((left, right) => Number(left.weight || 0) - Number(right.weight || 0)));
const pages = computed(() => {
    const result = [];
    let section = null;
    let pageFields = [];

    fields.value.forEach((field) => {
        if (field.type === 'section') {
            if (section || pageFields.length) result.push({ section, fields: pageFields });
            section = field;
            pageFields = [];
            return;
        }
        pageFields.push(field);
    });

    if (section || pageFields.length) result.push({ section, fields: pageFields });
    return result;
});
const paginated = computed(() => Boolean(props.form?.schema?.pagination?.enabled && pages.value.length > 1));
const isVisible = (field) => {
    if (!field.conditions?.length) return true;

    return field.conditions.every((condition) => {
        const value = answers.value[condition.field_id];
        const expected = String(condition.value ?? '');
        const values = Array.isArray(value) ? value.map(String) : [String(value ?? '')];

        return condition.operator === 'not_equals' ? !values.includes(expected) : values.includes(expected);
    });
};
const displayedPages = computed(() => (paginated.value ? [pages.value[pageIndex.value]] : pages.value)
    .map((page) => ({ ...page, fields: page.fields.filter(isVisible) })));
const viewportClass = computed(() => ({ desktop: 'w-full', tablet: 'max-w-[768px]', mobile: 'max-w-[420px]' })[device.value]);

const sampleValue = (field) => {
    if (state.value === 'blank') return field.type === 'checkbox' ? [] : '';
    if (field.type === 'checkbox') return field.options?.slice(0, 2).map((option) => option.value) || [];
    if (['radio', 'select'].includes(field.type)) return field.options?.[0]?.value || '';
    if (field.type === 'number') return 4;
    if (field.type === 'date') return '2026-01-15';
    if (field.id?.includes('email')) return 'respondent@example.com';
    return `Sample ${field.particular || 'response'}`;
};

const resetAnswers = () => {
    answers.value = Object.fromEntries(fields.value
        .filter((field) => field.type !== 'section')
        .map((field) => [field.id, sampleValue(field)]));
    pageIndex.value = 0;
};

watch([fields, state], resetAnswers, { immediate: true });
</script>

<template>
    <div class="fixed inset-0 z-50 overflow-y-auto bg-gray-950/65 p-4 backdrop-blur-sm" role="dialog" aria-modal="true" aria-label="Feedback form preview">
        <div class="mx-auto flex min-h-full max-w-6xl items-center justify-center">
            <div class="w-full overflow-hidden rounded-3xl border border-cream-500 bg-cream-100 shadow-2xl">
                <header class="flex flex-wrap items-center justify-between gap-4 border-b border-cream-500 bg-white px-5 py-4 sm:px-7">
                    <div>
                        <p class="text-xs font-bold uppercase tracking-[0.16em] text-forest-700">Interactive preview</p>
                        <h2 class="text-lg font-black text-gray-950">{{ form?.event?.name || 'Feedback form' }}</h2>
                    </div>
                    <button type="button" class="rounded-xl p-2 text-gray-500 transition hover:bg-cream-200 hover:text-gray-950" aria-label="Close preview" @click="emit('close')">✕</button>
                </header>

                <div class="flex flex-wrap items-center gap-3 border-b border-cream-500 px-5 py-3 sm:px-7">
                    <div class="flex rounded-xl bg-cream-200 p-1 text-xs font-bold text-gray-600">
                        <button v-for="option in ['desktop', 'tablet', 'mobile']" :key="option" type="button" class="rounded-lg px-3 py-1.5 capitalize" :class="device === option ? 'bg-white text-forest-900 shadow-sm' : ''" @click="device = option">{{ option }}</button>
                    </div>
                    <div class="flex rounded-xl bg-cream-200 p-1 text-xs font-bold text-gray-600">
                        <button v-for="option in ['deployed', 'embedded']" :key="option" type="button" class="rounded-lg px-3 py-1.5 capitalize" :class="mode === option ? 'bg-white text-forest-900 shadow-sm' : ''" @click="mode = option">{{ option }}</button>
                    </div>
                    <label class="ml-auto flex items-center gap-2 text-xs font-semibold text-gray-700"><input v-model="state" type="checkbox" true-value="completed" false-value="blank" class="rounded border-gray-300 text-forest-700 focus:ring-forest-600"> Sample answers</label>
                </div>

                <div class="max-h-[68vh] overflow-y-auto bg-cream-200/60 p-4 sm:p-8">
                    <div class="mx-auto transition-all" :class="viewportClass">
                        <div class="overflow-hidden rounded-2xl border border-cream-500 bg-white shadow-lg">
                            <div v-if="mode === 'embedded'" class="flex items-center gap-2 border-b border-cream-500 bg-gray-900 px-4 py-2 text-[11px] font-medium text-gray-200"><span class="size-2 rounded-full bg-red-400"></span><span class="size-2 rounded-full bg-amber-300"></span><span class="size-2 rounded-full bg-emerald-400"></span><span class="ml-2 truncate">Embedded feedback iframe</span></div>
                            <div class="space-y-6 p-5 sm:p-8">
                                <div class="rounded-2xl bg-forest-900 p-5 text-white"><p class="text-xs font-bold uppercase tracking-[0.14em] text-emerald-200">{{ mode === 'embedded' ? 'Embedded questionnaire' : 'Deployed web portal' }}</p><h3 class="mt-1 text-xl font-black" v-html="sanitizeHtml(form?.schema?.title || form?.event?.name)"></h3><div v-if="form?.schema?.description || form?.event?.details" class="mt-2 text-sm text-emerald-50 form-rich-content" v-html="sanitizeHtml(form?.schema?.description || form?.event?.details)"></div></div>
                                <section v-for="(page, index) in displayedPages" :key="page?.section?.id || index" class="space-y-5">
                                    <div v-if="page.section">
                                        <h4 class="text-base font-black text-gray-950 form-rich-content" v-html="sanitizeHtml(page.section.particular)"></h4>
                                        <div v-if="page.section.description || page.section.details" class="text-sm text-gray-600 form-rich-content" v-html="sanitizeHtml(page.section.description || page.section.details)"></div>
                                    </div>
                                    <div v-for="field in page.fields" :key="field.id" class="space-y-2">
                                        <template v-if="field.type === 'text_only'">
                                            <div class="rounded-xl border border-cream-400 bg-cream-100/80 p-4 space-y-2">
                                                <h5 class="text-sm font-bold text-gray-900 form-rich-content" v-html="sanitizeHtml(field.particular)"></h5>
                                                <div v-if="field.description" class="text-xs text-gray-700 form-rich-content overflow-x-auto" v-html="sanitizeHtml(field.description)"></div>
                                            </div>
                                        </template>
                                        <template v-else>
                                            <div>
                                                <label class="block text-sm font-bold text-gray-800">
                                                    <span class="form-rich-content inline" v-html="sanitizeHtml(field.particular)"></span>
                                                    <span v-if="field.required" class="text-red-600 ml-0.5">*</span>
                                                </label>
                                                <div v-if="field.description" class="text-xs text-gray-600 mt-1 form-rich-content" v-html="sanitizeHtml(field.description)"></div>
                                            </div>
                                            <textarea v-if="field.type === 'textarea'" v-model="answers[field.id]" rows="3" class="w-full rounded-xl border-gray-300 text-sm focus:border-forest-600 focus:ring-forest-600" />
                                            <input v-else-if="['text', 'number', 'date'].includes(field.type)" v-model="answers[field.id]" :type="field.type" class="w-full rounded-xl border-gray-300 text-sm focus:border-forest-600 focus:ring-forest-600">
                                            <select v-else-if="field.type === 'select'" v-model="answers[field.id]" class="w-full rounded-xl border-gray-300 text-sm focus:border-forest-600 focus:ring-forest-600"><option value="">Select an option</option><option v-for="option in field.options || []" :key="option.value" :value="option.value">{{ option.label || option.value }}</option></select>
                                            <div v-else-if="field.type === 'radio'" class="space-y-2"><label v-for="option in field.options || []" :key="option.value" class="flex items-center gap-2 text-sm text-gray-700"><input v-model="answers[field.id]" type="radio" :name="field.id" :value="option.value" class="border-gray-300 text-forest-700 focus:ring-forest-600">{{ option.label || option.value }}</label></div>
                                            <div v-else-if="field.type === 'checkbox'" class="space-y-2"><label v-for="option in field.options || []" :key="option.value" class="flex items-center gap-2 text-sm text-gray-700"><input v-model="answers[field.id]" type="checkbox" :value="option.value" class="rounded border-gray-300 text-forest-700 focus:ring-forest-600">{{ option.label || option.value }}</label></div>
                                        </template>
                                    </div>
                                </section>
                                <div v-if="paginated" class="flex items-center justify-between border-t border-cream-500 pt-4"><button type="button" class="rounded-xl px-4 py-2 text-xs font-bold text-gray-700 hover:bg-cream-200 disabled:opacity-40" :disabled="pageIndex === 0" @click="pageIndex--">← Previous</button><span class="text-xs font-semibold text-gray-500">Page {{ pageIndex + 1 }} of {{ pages.length }}</span><button type="button" class="rounded-xl bg-forest-900 px-4 py-2 text-xs font-bold text-white disabled:opacity-40" :disabled="pageIndex === pages.length - 1" @click="pageIndex++">Next →</button></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

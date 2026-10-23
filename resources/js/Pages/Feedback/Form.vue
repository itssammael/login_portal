<script setup>
import { computed, ref } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import FeedbackIntegrationGuideModal from '@/Components/Feedback/FeedbackIntegrationGuideModal.vue';
import FeedbackPreviewModal from '@/Components/Feedback/FeedbackPreviewModal.vue';
import FeedbackAnalyticsModal from '@/Components/Feedback/FeedbackAnalyticsModal.vue';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    forms: { type: Array, default: () => [] }, event: { type: Object, default: null }, form: { type: Object, default: null },
    functions: { type: Array, default: () => [] }, lookups: { type: Object, default: () => ({}) }, userDefaults: { type: Object, default: () => ({}) },
});
const page = usePage();
const query = ref('');
const copiedFormId = ref(null);
const previewForm = ref(null);
const guideForm = ref(null);
const analyticsForm = ref(null);
const publicationOverrides = ref({});
const publicationFor = (item) => publicationOverrides.value[item.id] || item.analytics;
const filteredForms = computed(() => {
    const needle = query.value.trim().toLocaleLowerCase();
    return needle ? props.forms.filter((item) => [item.event?.name, item.event?.details].filter(Boolean).some((value) => value.toLocaleLowerCase().includes(needle))) : props.forms;
});
const totalSubmissions = computed(() => props.forms.reduce((total, item) => total + Number(item.submissions_count || 0), 0));
const canManageFeedback = computed(() => Boolean(page.props.auth?.user?.is_admin));
const questionCount = (item) => (item.schema?.fields || []).filter((field) => field.type !== 'section').length;
const liveUrl = (item) => route('forms.live', { api_key: item.event.api_key });
const copyUrl = async (item) => {
    const url = liveUrl(item);
    try { await navigator.clipboard.writeText(url); } catch {
        const input = document.createElement('textarea'); input.value = url; input.style.position = 'fixed'; input.style.opacity = '0'; document.body.appendChild(input); input.select(); document.execCommand('copy'); input.remove();
    }
    copiedFormId.value = item.id;
    window.setTimeout(() => { copiedFormId.value = null; }, 1800);
};
const shareUrl = async (item) => {
    if (navigator.share) {
        try {
            await navigator.share({ title: item.event.name, text: item.event.details || 'Feedback form', url: liveUrl(item) });
        } catch (error) {
            if (error.name !== 'AbortError') await copyUrl(item);
        }
        return;
    }
    await copyUrl(item);
};
</script>

<template>
    <AppLayout title="Feedback Forms">
        <Head title="Feedback Forms" />
        <template #header><div><h2 class="text-xl font-bold tracking-tight text-gray-900">Feedback Forms</h2><p class="mt-0.5 text-xs font-medium text-gray-700">Browse, preview, share, and open available evaluation questionnaires.</p></div></template>
        <main class="py-8"><div class="mx-auto max-w-6xl space-y-6 px-4 sm:px-6 lg:px-8">
            <section class="grid gap-4 md:grid-cols-[1fr_auto] md:items-end"><div class="relative"><label for="form-search" class="mb-1.5 block text-xs font-bold text-gray-700">Find a feedback form</label><input id="form-search" v-model="query" type="search" placeholder="Search by event title or details..." class="w-full rounded-2xl border-cream-500 bg-white py-3 pl-4 pr-4 text-sm shadow-sm focus:border-forest-600 focus:ring-forest-600"></div><div class="grid grid-cols-2 gap-3"><div class="rounded-2xl border border-cream-500 bg-white px-4 py-3 shadow-sm"><p class="text-[11px] font-bold uppercase tracking-wide text-gray-500">Forms</p><p class="text-2xl font-black text-forest-900">{{ forms.length }}</p></div><div class="rounded-2xl border border-cream-500 bg-white px-4 py-3 shadow-sm"><p class="text-[11px] font-bold uppercase tracking-wide text-gray-500">Submissions</p><p class="text-2xl font-black text-forest-900">{{ totalSubmissions }}</p></div></div></section>
            <section v-if="filteredForms.length" class="space-y-4"><article v-for="item in filteredForms" :key="item.id" class="overflow-hidden rounded-3xl border border-cream-500 bg-white shadow-sm transition hover:border-forest-400 hover:shadow-md"><div class="grid gap-5 p-5 sm:p-6 lg:grid-cols-[1fr_auto] lg:items-start"><div class="min-w-0"><div class="flex flex-wrap items-center gap-2"><span class="rounded-full bg-emerald-100 px-2.5 py-1 text-[11px] font-black uppercase tracking-wide text-emerald-800">Available</span><span class="text-xs font-semibold text-gray-500">{{ questionCount(item) }} questions · {{ item.submissions_count }} submissions</span></div><h3 class="mt-3 text-xl font-black text-gray-950">{{ item.event.name }}</h3><p v-if="item.event.details" class="mt-1 max-w-3xl text-sm leading-6 text-gray-600">{{ item.event.details }}</p><div v-if="item.event.functions?.length" class="mt-4 flex flex-wrap gap-2"><span v-for="functionItem in item.event.functions" :key="functionItem.id" class="rounded-lg bg-cream-200 px-2.5 py-1 text-xs font-semibold text-gray-700">{{ functionItem.function }}</span></div></div><div class="flex flex-wrap gap-2 lg:max-w-64 lg:justify-end"><button v-if="item.can_manage_analytics" type="button" class="rounded-xl border border-cream-500 px-3 py-2 text-xs font-bold text-gray-700 hover:bg-cream-200" @click="analyticsForm = item">Analytics</button><a v-if="publicationFor(item)?.has_published_report" :href="publicationFor(item).public_url" target="_blank" rel="noopener" class="rounded-xl bg-emerald-100 px-3 py-2 text-xs font-bold text-emerald-900">View Analytics ↗</a><button type="button" class="rounded-xl border border-cream-500 px-3 py-2 text-xs font-bold text-gray-700 hover:bg-cream-200" @click="previewForm = item">Preview</button><button type="button" class="rounded-xl border border-cream-500 px-3 py-2 text-xs font-bold text-gray-700 hover:bg-cream-200" @click="guideForm = item">Integration Guide</button><a :href="liveUrl(item)" class="rounded-xl bg-forest-900 px-4 py-2 text-xs font-black text-white shadow-sm hover:bg-forest-950">Open Form ↗</a></div></div><div class="flex flex-col gap-3 border-t border-cream-500 bg-cream-100/70 px-5 py-4 sm:flex-row sm:items-center sm:px-6"><div class="min-w-0 flex-1"><p class="text-[11px] font-bold uppercase tracking-wide text-gray-500">Live form URL</p><code class="block truncate text-xs text-forest-900">{{ liveUrl(item) }}</code></div><div class="flex gap-2"><button type="button" class="rounded-xl bg-white px-3 py-2 text-xs font-bold text-gray-700 shadow-sm ring-1 ring-cream-500 hover:bg-cream-200" @click="copyUrl(item)">{{ copiedFormId === item.id ? '✓ Copied' : 'Copy URL' }}</button><button type="button" class="rounded-xl bg-white px-3 py-2 text-xs font-bold text-gray-700 shadow-sm ring-1 ring-cream-500 hover:bg-cream-200" @click="shareUrl(item)">Share</button></div></div></article></section>
            <section v-else class="rounded-3xl border border-dashed border-cream-500 bg-cream-100 p-12 text-center shadow-sm"><div class="mx-auto flex size-16 items-center justify-center rounded-full bg-white text-forest-700 shadow-sm">✦</div><h3 class="mt-4 text-lg font-black text-gray-950">{{ forms.length ? 'No forms match your search' : 'No feedback forms are available' }}</h3><p class="mx-auto mt-2 max-w-md text-sm leading-6 text-gray-600">{{ forms.length ? 'Try a different event title or clear the search field.' : 'An administrator can create an event and configure its questionnaire to make it available here.' }}</p><Link v-if="!forms.length && canManageFeedback" :href="route('admin.feedback.setup')" class="mt-6 inline-flex rounded-xl bg-forest-900 px-4 py-2.5 text-xs font-black text-white shadow-sm hover:bg-forest-950">Configure feedback forms</Link></section>
        </div></main>
        <FeedbackAnalyticsModal v-if="analyticsForm" :form="analyticsForm" @close="analyticsForm = null" @publication="publicationOverrides[analyticsForm.id] = $event" />
        <FeedbackPreviewModal v-if="previewForm" :form="previewForm" @close="previewForm = null" />
        <FeedbackIntegrationGuideModal v-if="guideForm" :form="guideForm" @close="guideForm = null" />
    </AppLayout>
</template>

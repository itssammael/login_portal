<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import FeedbackIntegrationGuideModal from '@/Components/Feedback/FeedbackIntegrationGuideModal.vue';
import FeedbackPreviewModal from '@/Components/Feedback/FeedbackPreviewModal.vue';
import FeedbackAnalyticsModal from '@/Components/Feedback/FeedbackAnalyticsModal.vue';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    forms: { type: Array, default: () => [] },
    event: { type: Object, default: null },
    form: { type: Object, default: null },
    functions: { type: Array, default: () => [] },
    lookups: { type: Object, default: () => ({}) },
    userDefaults: { type: Object, default: () => ({}) },
});

const page = usePage();
const query = ref('');
const statusFilter = ref('all'); // 'all' | 'published' | 'draft'
const copiedFormId = ref(null);
const togglingStatusId = ref(null);
const previewForm = ref(null);
const guideForm = ref(null);
const analyticsForm = ref(null);
const publicationOverrides = ref({});

const publicationFor = (item) => publicationOverrides.value[item.id] || item.analytics;

const canManageFeedback = computed(() => Boolean(page.props.auth?.user?.is_admin));

const isItemPublished = (item) => item.status !== 'draft' && !item.is_draft;

const publishedFormsCount = computed(() => props.forms.filter(isItemPublished).length);
const draftFormsCount = computed(() => props.forms.filter((item) => !isItemPublished(item)).length);

const filteredForms = computed(() => {
    let list = props.forms;
    if (canManageFeedback.value && statusFilter.value !== 'all') {
        if (statusFilter.value === 'published') {
            list = list.filter(isItemPublished);
        } else if (statusFilter.value === 'draft') {
            list = list.filter((item) => !isItemPublished(item));
        }
    }
    const needle = query.value.trim().toLocaleLowerCase();
    return needle
        ? list.filter((item) => [item.event?.name, item.event?.details].filter(Boolean).some((value) => value.toLocaleLowerCase().includes(needle)))
        : list;
});

const totalSubmissions = computed(() => props.forms.reduce((total, item) => total + Number(item.submissions_count || 0), 0));
const questionCount = (item) => (item.schema?.fields || []).filter((field) => field.type !== 'section').length;
const liveUrl = (item) => route('forms.live', { api_key: item.event.api_key });

const togglePublishStatus = (item) => {
    const nextStatus = isItemPublished(item) ? 'draft' : 'published';
    togglingStatusId.value = item.id;
    router.post(
        route('admin.feedback.forms.status', item.id),
        { status: nextStatus },
        {
            preserveScroll: true,
            onSuccess: () => {
                item.status = nextStatus;
                item.is_draft = nextStatus === 'draft';
            },
            onFinish: () => {
                togglingStatusId.value = null;
            },
        }
    );
};

const copyUrl = async (item) => {
    const url = liveUrl(item);
    try {
        await navigator.clipboard.writeText(url);
    } catch {
        const input = document.createElement('textarea');
        input.value = url;
        input.style.position = 'fixed';
        input.style.opacity = '0';
        document.body.appendChild(input);
        input.select();
        document.execCommand('copy');
        input.remove();
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
        <template #header>
            <div>
                <h2 class="text-xl font-bold tracking-tight text-gray-900">Feedback Forms</h2>
                <p class="mt-0.5 text-xs font-medium text-gray-700">Browse, preview, share, and open available evaluation questionnaires.</p>
            </div>
        </template>

        <main class="py-8">
            <div class="mx-auto max-w-6xl space-y-6 px-4 sm:px-6 lg:px-8">
                <!-- Search & Overview Stats -->
                <section class="grid gap-4 md:grid-cols-[1fr_auto] md:items-end">
                    <div class="relative">
                        <label for="form-search" class="mb-1.5 block text-xs font-bold text-gray-700">Find a feedback form</label>
                        <input
                            id="form-search"
                            v-model="query"
                            type="search"
                            placeholder="Search by event title or details..."
                            class="w-full rounded-2xl border-cream-500 bg-white py-3 pl-4 pr-4 text-sm shadow-sm focus:border-forest-600 focus:ring-forest-600"
                        />

                        <!-- Status Filter Tabs for Admin -->
                        <div v-if="canManageFeedback && forms.length > 0" class="mt-3 flex items-center space-x-1.5">
                            <button
                                type="button"
                                @click="statusFilter = 'all'"
                                class="px-3 py-1 rounded-xl text-xs font-bold transition cursor-pointer flex items-center space-x-1.5"
                                :class="statusFilter === 'all'
                                    ? 'bg-forest-900 text-white shadow-xs'
                                    : 'bg-white text-gray-700 hover:bg-cream-200 border border-cream-400'"
                            >
                                <span>All Forms</span>
                                <span class="px-1.5 py-0.2 rounded-full text-[10px]" :class="statusFilter === 'all' ? 'bg-forest-800 text-emerald-200' : 'bg-cream-200 text-gray-700'">
                                    {{ forms.length }}
                                </span>
                            </button>

                            <button
                                type="button"
                                @click="statusFilter = 'published'"
                                class="px-3 py-1 rounded-xl text-xs font-bold transition cursor-pointer flex items-center space-x-1.5"
                                :class="statusFilter === 'published'
                                    ? 'bg-emerald-700 text-white shadow-xs'
                                    : 'bg-white text-gray-700 hover:bg-cream-200 border border-cream-400'"
                            >
                                <span class="size-2 rounded-full bg-emerald-400"></span>
                                <span>Published</span>
                                <span class="px-1.5 py-0.2 rounded-full text-[10px]" :class="statusFilter === 'published' ? 'bg-emerald-800 text-emerald-100' : 'bg-cream-200 text-gray-700'">
                                    {{ publishedFormsCount }}
                                </span>
                            </button>

                            <button
                                type="button"
                                @click="statusFilter = 'draft'"
                                class="px-3 py-1 rounded-xl text-xs font-bold transition cursor-pointer flex items-center space-x-1.5"
                                :class="statusFilter === 'draft'
                                    ? 'bg-amber-600 text-white shadow-xs'
                                    : 'bg-white text-gray-700 hover:bg-cream-200 border border-cream-400'"
                            >
                                <span class="size-2 rounded-full bg-amber-400"></span>
                                <span>Drafts</span>
                                <span class="px-1.5 py-0.2 rounded-full text-[10px]" :class="statusFilter === 'draft' ? 'bg-amber-700 text-amber-100' : 'bg-cream-200 text-gray-700'">
                                    {{ draftFormsCount }}
                                </span>
                            </button>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div class="rounded-2xl border border-cream-500 bg-white px-4 py-3 shadow-sm">
                            <p class="text-[11px] font-bold uppercase tracking-wide text-gray-500">Forms</p>
                            <p class="text-2xl font-black text-forest-900">{{ forms.length }}</p>
                        </div>
                        <div class="rounded-2xl border border-cream-500 bg-white px-4 py-3 shadow-sm">
                            <p class="text-[11px] font-bold uppercase tracking-wide text-gray-500">Submissions</p>
                            <p class="text-2xl font-black text-forest-900">{{ totalSubmissions }}</p>
                        </div>
                    </div>
                </section>

                <!-- Forms List -->
                <section v-if="filteredForms.length" class="space-y-4">
                    <article
                        v-for="item in filteredForms"
                        :key="item.id"
                        class="overflow-hidden rounded-3xl border shadow-sm transition hover:shadow-md"
                        :class="!isItemPublished(item)
                            ? 'border-amber-300 bg-amber-50/15 hover:border-amber-400'
                            : 'border-cream-500 bg-white hover:border-forest-400'"
                    >
                        <div class="grid gap-5 p-5 sm:p-6 lg:grid-cols-[1fr_auto] lg:items-start">
                            <div class="min-w-0">
                                <!-- Status Row with Publish/Unpublish Switcher -->
                                <div class="flex flex-wrap items-center gap-2.5">
                                    <!-- Interactive Switcher (for Admins) -->
                                    <div
                                        v-if="canManageFeedback"
                                        class="inline-flex items-center gap-2 px-2.5 py-1 rounded-full border shadow-2xs transition"
                                        :class="isItemPublished(item)
                                            ? 'bg-emerald-50/90 border-emerald-300 text-emerald-950'
                                            : 'bg-amber-50/90 border-amber-300 text-amber-950'"
                                    >
                                        <button
                                            type="button"
                                            role="switch"
                                            :aria-checked="isItemPublished(item)"
                                            @click="togglePublishStatus(item)"
                                            :disabled="togglingStatusId === item.id"
                                            class="relative inline-flex h-5 w-9 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-forest-600 focus:ring-offset-1 disabled:opacity-50"
                                            :class="isItemPublished(item) ? 'bg-emerald-600' : 'bg-gray-300'"
                                            :title="isItemPublished(item) ? 'Form is Published. Click to Unpublish (switch to Draft)' : 'Form is in Draft mode. Click to Publish'"
                                        >
                                            <span class="sr-only">Toggle publish status</span>
                                            <span
                                                aria-hidden="true"
                                                class="pointer-events-none inline-flex items-center justify-center size-4 transform rounded-full bg-white shadow-xs ring-0 transition duration-200 ease-in-out"
                                                :class="isItemPublished(item) ? 'translate-x-4' : 'translate-x-0'"
                                            >
                                                <svg v-if="togglingStatusId === item.id" class="animate-spin size-2.5 text-gray-500" fill="none" viewBox="0 0 24 24">
                                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                                                </svg>
                                            </span>
                                        </button>
                                        <span class="text-[11px] font-black uppercase tracking-wide select-none">
                                            {{ isItemPublished(item) ? 'Published' : 'Draft' }}
                                        </span>
                                    </div>

                                    <!-- Static Badge for Respondents -->
                                    <span
                                        v-else
                                        class="rounded-full bg-emerald-100 px-2.5 py-1 text-[11px] font-black uppercase tracking-wide text-emerald-800"
                                    >
                                        Available
                                    </span>

                                    <span class="text-xs font-semibold text-gray-500">
                                        {{ questionCount(item) }} questions · {{ item.submissions_count }} submissions
                                    </span>
                                </div>

                                <h3 class="mt-3 text-xl font-black text-gray-950">
                                    {{ item.event.name }}
                                </h3>
                                <p v-if="item.event.details" class="mt-1 max-w-3xl text-sm leading-6 text-gray-600">
                                    {{ item.event.details }}
                                </p>

                                <div v-if="item.event.functions?.length" class="mt-4 flex flex-wrap gap-2">
                                    <span
                                        v-for="functionItem in item.event.functions"
                                        :key="functionItem.id"
                                        class="rounded-lg bg-cream-200 px-2.5 py-1 text-xs font-semibold text-gray-700"
                                    >
                                        {{ functionItem.function }}
                                    </span>
                                </div>
                            </div>

                            <!-- Actions Row -->
                            <div class="flex flex-wrap gap-2 lg:max-w-72 lg:justify-end items-center">
                                <!-- Publish / Unpublish Action Button (for Admins) -->
                                <button
                                    v-if="canManageFeedback"
                                    type="button"
                                    @click="togglePublishStatus(item)"
                                    :disabled="togglingStatusId === item.id"
                                    class="rounded-xl border px-3 py-2 text-xs font-bold transition flex items-center space-x-1.5 shadow-2xs disabled:opacity-50 cursor-pointer"
                                    :class="isItemPublished(item)
                                        ? 'border-amber-300 bg-amber-50 text-amber-900 hover:bg-amber-100'
                                        : 'border-emerald-300 bg-emerald-50 text-emerald-900 hover:bg-emerald-100'"
                                    :title="isItemPublished(item) ? 'Unpublish form back to draft mode' : 'Publish form so public respondents can access it'"
                                >
                                    <svg v-if="togglingStatusId === item.id" class="animate-spin size-3.5 text-current" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                                    </svg>
                                    <template v-else>
                                        <span v-if="isItemPublished(item)">⏸ Unpublish</span>
                                        <span v-else>🚀 Publish</span>
                                    </template>
                                </button>

                                <button
                                    v-if="item.can_manage_analytics"
                                    type="button"
                                    class="rounded-xl border border-cream-500 px-3 py-2 text-xs font-bold text-gray-700 hover:bg-cream-200 cursor-pointer"
                                    @click="analyticsForm = item"
                                >
                                    Analytics
                                </button>
                                <a
                                    v-if="publicationFor(item)?.has_published_report"
                                    :href="publicationFor(item).public_url"
                                    target="_blank"
                                    rel="noopener"
                                    class="rounded-xl bg-emerald-100 px-3 py-2 text-xs font-bold text-emerald-900 hover:bg-emerald-200"
                                >
                                    View Analytics ↗
                                </a>
                                <button
                                    type="button"
                                    class="rounded-xl border border-cream-500 px-3 py-2 text-xs font-bold text-gray-700 hover:bg-cream-200 cursor-pointer"
                                    @click="previewForm = item"
                                >
                                    Preview
                                </button>
                                <button
                                    type="button"
                                    class="rounded-xl border border-cream-500 px-3 py-2 text-xs font-bold text-gray-700 hover:bg-cream-200 cursor-pointer"
                                    @click="guideForm = item"
                                >
                                    Integration Guide
                                </button>
                                <a
                                    :href="liveUrl(item)"
                                    class="rounded-xl bg-forest-900 px-4 py-2 text-xs font-black text-white shadow-sm hover:bg-forest-950"
                                >
                                    Open Form ↗
                                </a>
                            </div>
                        </div>

                        <!-- Bottom URL Row -->
                        <div class="flex flex-col gap-3 border-t border-cream-500 bg-cream-100/70 px-5 py-4 sm:flex-row sm:items-center sm:px-6">
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center space-x-2">
                                    <p class="text-[11px] font-bold uppercase tracking-wide text-gray-500">Live form URL</p>
                                    <span v-if="!isItemPublished(item)" class="text-[10px] font-bold text-amber-700 bg-amber-100 px-1.5 py-0.2 rounded">
                                        Draft (Only admins can view)
                                    </span>
                                </div>
                                <code class="block truncate text-xs text-forest-900">{{ liveUrl(item) }}</code>
                            </div>
                            <div class="flex gap-2">
                                <button
                                    type="button"
                                    class="rounded-xl bg-white px-3 py-2 text-xs font-bold text-gray-700 shadow-sm ring-1 ring-cream-500 hover:bg-cream-200 cursor-pointer"
                                    @click="copyUrl(item)"
                                >
                                    {{ copiedFormId === item.id ? '✓ Copied' : 'Copy URL' }}
                                </button>
                                <button
                                    type="button"
                                    class="rounded-xl bg-white px-3 py-2 text-xs font-bold text-gray-700 shadow-sm ring-1 ring-cream-500 hover:bg-cream-200 cursor-pointer"
                                    @click="shareUrl(item)"
                                >
                                    Share
                                </button>
                            </div>
                        </div>
                    </article>
                </section>

                <!-- Empty State -->
                <section v-else class="rounded-3xl border border-dashed border-cream-500 bg-cream-100 p-12 text-center shadow-sm">
                    <div class="mx-auto flex size-16 items-center justify-center rounded-full bg-white text-forest-700 shadow-sm">✦</div>
                    <h3 class="mt-4 text-lg font-black text-gray-950">
                        {{ forms.length ? 'No forms match your search or filter' : 'No feedback forms are available' }}
                    </h3>
                    <p class="mx-auto mt-2 max-w-md text-sm leading-6 text-gray-600">
                        {{ forms.length ? 'Try a different filter or clear the search field.' : 'An administrator can create an event and configure its questionnaire to make it available here.' }}
                    </p>
                    <div class="mt-4 flex justify-center gap-3">
                        <button
                            v-if="statusFilter !== 'all' || query"
                            type="button"
                            @click="statusFilter = 'all'; query = ''"
                            class="inline-flex rounded-xl bg-cream-200 hover:bg-cream-300 text-gray-800 px-4 py-2.5 text-xs font-bold shadow-xs cursor-pointer"
                        >
                            Reset filters
                        </button>
                        <Link
                            v-if="!forms.length && canManageFeedback"
                            :href="route('admin.feedback.setup')"
                            class="inline-flex rounded-xl bg-forest-900 px-4 py-2.5 text-xs font-black text-white shadow-sm hover:bg-forest-950"
                        >
                            Configure feedback forms
                        </Link>
                    </div>
                </section>
            </div>
        </main>

        <FeedbackAnalyticsModal
            v-if="analyticsForm"
            :form="analyticsForm"
            @close="analyticsForm = null"
            @publication="publicationOverrides[analyticsForm.id] = $event"
        />
        <FeedbackPreviewModal
            v-if="previewForm"
            :form="previewForm"
            @close="previewForm = null"
        />
        <FeedbackIntegrationGuideModal
            v-if="guideForm"
            :form="guideForm"
            @close="guideForm = null"
        />
    </AppLayout>
</template>

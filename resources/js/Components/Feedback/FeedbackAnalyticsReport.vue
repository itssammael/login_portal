<script setup>
import { computed, ref } from 'vue';
import FeedbackAnalyticsBlock from './FeedbackAnalyticsBlock.vue';
import FormRichEditor from './FormRichEditor.vue';
import { reportBlocks } from './feedbackAnalyticsLayout';
import { sanitizeHtml } from '@/Utils/htmlSanitizer';

const props = defineProps({
    report: Object,
    data: Object,
    eventTitle: String,
    editable: Boolean,
    selectedKey: String,
});

const emit = defineEmits(['select', 'drop-block', 'drag-block', 'move', 'edit-text']);
const blocks = computed(() => (props.report ? reportBlocks(props.report, null) : []));
const dropTarget = ref(null);

function drop(event, key, parentId = null) {
    if (!props.editable) return;
    event.preventDefault();
    event.stopPropagation();
    dropTarget.value = null;
    emit('drop-block', { event, beforeKey: key, parentId });
}

function edit(key, field, value) {
    emit('edit-text', { key, field, value });
}
</script>

<template>
    <article class="report-body flex flex-col gap-6">
        <header
            class="flex flex-col gap-3 border-b border-cream-500 pb-6"
            :class="editable ? 'rounded-lg p-2 hover:ring-1 hover:ring-emerald-300' : ''"
            @click="editable && emit('select', 'page')"
        >
            <p class="text-xs font-bold uppercase tracking-widest text-forest-700">{{ eventTitle }}</p>
            <FormRichEditor
                v-if="editable"
                :model-value="report.title"
                placeholder="Report title…"
                min-height="44px"
                :allow-tables="false"
                single-line
                input-class="text-3xl font-black tracking-tight text-gray-950"
                @update:model-value="edit('page', 'title', $event)"
            />
            <h1 v-else class="text-3xl font-black tracking-tight text-gray-950 sm:text-4xl form-rich-content" v-html="sanitizeHtml(report.title)" />
            <FormRichEditor
                v-if="editable"
                :model-value="report.description"
                placeholder="Add a report description…"
                min-height="60px"
                input-class="leading-7 text-gray-600"
                @update:model-value="edit('page', 'description', $event)"
            />
            <div v-else-if="report.description" class="max-w-3xl whitespace-pre-line leading-7 text-gray-600 form-rich-content" v-html="sanitizeHtml(report.description)" />
            <div class="flex flex-wrap gap-4 text-sm text-gray-500">
                <span>Period: {{ report.period_start || 'Beginning' }} — {{ report.period_end || 'Present' }}</span>
                <span v-if="report.show_submission_count && data?.submission_count != null">{{ data.submission_count }} submissions</span>
            </div>
        </header>

        <!-- TailwindCSS 12-Column Grid for Canvas Report Blocks (Each col = 8.333333333% container width) -->
        <div
            class="grid grid-cols-12 gap-5"
            @dragover.prevent
            @drop="drop($event, null, null)"
        >
            <FeedbackAnalyticsBlock
                v-for="(block, index) in blocks"
                :key="block.key"
                :block="block"
                :index="index"
                :sibling-count="blocks.length"
                :parent-columns="12"
                :parent-id="null"
                :depth="0"
                :report="report"
                :data="data"
                :editable="editable"
                :selected-key="selectedKey"
                :drop-target="dropTarget"
                @select="emit('select', $event)"
                @drop-block="emit('drop-block', $event)"
                @drag-block="emit('drag-block', $event)"
                @move="emit('move', $event)"
                @edit-text="emit('edit-text', $event)"
                @update:drop-target="dropTarget = $event"
            />

            <button
                v-if="editable"
                type="button"
                class="col-span-full report-drop flex min-h-28 flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed border-emerald-200 bg-emerald-50/50 p-6 text-sm text-emerald-800 hover:border-emerald-500 cursor-pointer"
                @dragover.prevent="dropTarget = 'end'"
                @drop.stop="drop($event, null, null)"
                @click="emit('select', 'library')"
            >
                <span class="text-2xl">＋</span>
                <span>{{ blocks.length ? 'Drop a block here to append it' : 'Drag a block here to start your report' }}</span>
                <span class="text-xs text-gray-500">Or select a block from the library</span>
            </button>
        </div>

        <footer class="flex flex-col gap-2 border-t border-cream-500 pt-5 text-xs leading-5 text-gray-500">
            <div v-if="editable" @click="emit('select', 'page')">
                <FormRichEditor
                    :model-value="report.footer"
                    placeholder="Add a footer…"
                    min-height="48px"
                    input-class="text-xs leading-5 text-gray-500"
                    @update:model-value="edit('page', 'footer', $event)"
                    @focus="emit('select', 'page')"
                />
            </div>
            <div v-else-if="report.footer" class="whitespace-pre-line form-rich-content" v-html="sanitizeHtml(report.footer)" />
            <p>Last data refresh: {{ data?.refreshed_at ? new Date(data.refreshed_at).toLocaleString() : 'Pending' }} · Refreshes every {{ report.refresh_seconds / 60 }} minutes.</p>
            <p>Small groups are withheld. Percentages use answered responses; multiple-choice totals may exceed 100%. Standard deviation uses the population formula.</p>
        </footer>
    </article>
</template>

<script setup>
import { computed, ref } from 'vue';
import FeedbackAnalyticsBlock from './FeedbackAnalyticsBlock.vue';
import { reportBlocks } from './feedbackAnalyticsLayout';

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

function edit(key, field, event) {
    emit('edit-text', { key, field, value: event.target.value });
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
            <input
                v-if="editable"
                :value="report.title"
                aria-label="Report title on canvas"
                maxlength="500"
                class="canvas-input text-3xl font-black tracking-tight"
                @input="edit('page', 'title', $event)"
            >
            <h1 v-else class="text-3xl font-black tracking-tight text-gray-950 sm:text-4xl">{{ report.title }}</h1>
            <textarea
                v-if="editable"
                :value="report.description"
                aria-label="Report description on canvas"
                placeholder="Add a report description…"
                maxlength="2000"
                rows="2"
                class="canvas-input resize-y leading-7 text-gray-600"
                @input="edit('page', 'description', $event)"
            />
            <p v-else-if="report.description" class="max-w-3xl whitespace-pre-line leading-7 text-gray-600">{{ report.description }}</p>
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
            <textarea
                v-if="editable"
                :value="report.footer"
                aria-label="Report footer on canvas"
                maxlength="1000"
                rows="2"
                placeholder="Add a footer…"
                class="canvas-input resize-y text-xs"
                @input="edit('page', 'footer', $event)"
                @focus="emit('select', 'page')"
            />
            <p v-else-if="report.footer" class="whitespace-pre-line">{{ report.footer }}</p>
            <p>Last data refresh: {{ data?.refreshed_at ? new Date(data.refreshed_at).toLocaleString() : 'Pending' }} · Refreshes every {{ report.refresh_seconds / 60 }} minutes.</p>
            <p>Small groups are withheld. Percentages use answered responses; multiple-choice totals may exceed 100%. Standard deviation uses the population formula.</p>
        </footer>
    </article>
</template>

<style scoped>
.canvas-input { display: block; width: 100%; padding: 0; border: 0; background: transparent; border-radius: 0.25rem; }
.canvas-input:focus { outline: 2px solid #a7f3d0; outline-offset: 4px; box-shadow: none; }
</style>

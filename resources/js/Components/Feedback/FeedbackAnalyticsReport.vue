<script setup>
import { computed, ref } from 'vue';
import FeedbackAnalyticsChart from './FeedbackAnalyticsChart.vue';
import { reportBlocks } from './feedbackAnalyticsLayout';

const props = defineProps({
    report: Object,
    data: Object,
    eventTitle: String,
    editable: Boolean,
    selectedKey: String,
});

const aspectW = 8.333333333;
const aspectH = 11.111111111;

const emit = defineEmits(['select', 'drop-block', 'drag-block', 'move', 'edit-text']);
const blocks = computed(() => (props.report ? reportBlocks(props.report) : []));
const dropTarget = ref(null);

function blockSpanClass(block) {
    let span = Number(block?.item?.width);
    if (!span || span < 1) {
        span = block?.kind === 'section' ? 12 : 6;
    }
    span = Math.min(Math.max(span, 1), 12);

    const spanClasses = {
        1: 'col-span-1',
        2: 'col-span-2',
        3: 'col-span-3',
        4: 'col-span-4',
        5: 'col-span-5',
        6: 'col-span-6',
        7: 'col-span-7',
        8: 'col-span-8',
        9: 'col-span-9',
        10: 'col-span-10',
        11: 'col-span-11',
        12: 'col-span-12',
    };

    return spanClasses[span] || 'col-span-6';
}

function blockRowSpanClass(block) {
    let rows = Number(block?.item?.height);
    if (!rows || rows < 1) {
        rows = block?.kind === 'section' ? 3 : 5;
    }
    rows = Math.min(Math.max(rows, 1), 9);

    const rowClasses = {
        1: 'row-span-1',
        2: 'row-span-2',
        3: 'row-span-3',
        4: 'row-span-4',
        5: 'row-span-5',
        6: 'row-span-6',
        7: 'row-span-7',
        8: 'row-span-8',
        9: 'row-span-9',
    };

    return rowClasses[rows] || 'row-span-4';
}

function blockHeightStyle(block) {
    let rows = Number(block?.item?.height);
    if (!rows || rows < 1) {
        rows = block?.kind === 'section' ? 3 : 5;
    }
    rows = Math.min(Math.max(rows, 1), 9);
    return {
        minHeight: `${rows * 64}px`,
    };
}

function drop(event, key) {
    if (!props.editable) return;
    event.preventDefault();
    event.stopPropagation();
    dropTarget.value = null;
    emit('drop-block', { event, beforeKey: key });
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
                maxlength="200"
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
            @drop="drop($event, null)"
        >
            <div
                v-for="(block, index) in blocks"
                :key="block.key"
                class="report-block relative min-w-0 rounded-2xl flex flex-col"
                :class="[
                    blockSpanClass(block),
                    blockRowSpanClass(block),
                    {
                        'ring-2 ring-emerald-600 ring-offset-4': editable && selectedKey === block.key,
                        'outline outline-2 outline-dashed outline-emerald-400': editable && dropTarget === block.key
                    }
                ]"
                :style="blockHeightStyle(block)"
                @click="editable && emit('select', block.key)"
                @dragover.prevent.stop="editable && (dropTarget = block.key)"
                @dragleave="dropTarget = null"
                @drop="drop($event, block.key)"
            >
                <div v-if="editable" class="flex items-center justify-between gap-2 rounded-t-xl border border-emerald-100 bg-emerald-50 px-3 py-2 text-xs text-emerald-900">
                    <button
                        type="button"
                        draggable="true"
                        class="cursor-grab rounded px-1 py-1 font-semibold active:cursor-grabbing"
                        :aria-label="`Drag ${block.item.title}`"
                        @dragstart="emit('drag-block', { event: $event, key: block.key })"
                        @dragend="dropTarget = null"
                        @click.stop="emit('select', block.key)"
                    >
                        ⠿ {{ block.kind === 'chart' ? block.item.type : 'Text section' }}
                    </button>
                    <div class="flex gap-1">
                        <button
                            type="button"
                            :disabled="index === 0"
                            class="rounded px-2 py-1 disabled:opacity-30"
                            :aria-label="`Move ${block.item.title} up`"
                            @click.stop="emit('move', { key: block.key, direction: -1 })"
                        >
                            ↑
                        </button>
                        <button
                            type="button"
                            :disabled="index === blocks.length - 1"
                            class="rounded px-2 py-1 disabled:opacity-30"
                            :aria-label="`Move ${block.item.title} down`"
                            @click.stop="emit('move', { key: block.key, direction: 1 })"
                        >
                            ↓
                        </button>
                        <button
                            type="button"
                            class="rounded px-2 py-1 underline font-semibold text-emerald-950 hover:bg-emerald-100 transition cursor-pointer"
                            @click.stop="emit('select', block.key)"
                        >
                            Edit
                        </button>
                    </div>
                </div>

                <FeedbackAnalyticsChart v-if="block.kind === 'chart'" :chart="block.item" :result="data?.charts?.[block.item.id]" />
                <section v-else class="rounded-2xl bg-white p-6 flex-1 flex flex-col h-full">
                    <template v-if="editable">
                        <input
                            :value="block.item.title"
                            aria-label="Section title on canvas"
                            maxlength="200"
                            class="canvas-input text-xl font-bold"
                            @input="edit(block.key, 'title', $event)"
                        >
                        <textarea
                            :value="block.item.body"
                            aria-label="Section text on canvas"
                            maxlength="3000"
                            rows="3"
                            placeholder="Write your report notes here…"
                            class="canvas-input mt-2 resize-y leading-7 text-gray-600"
                            @input="edit(block.key, 'body', $event)"
                        />
                    </template>
                    <template v-else>
                        <h2 class="text-xl font-bold">{{ block.item.title }}</h2>
                        <p class="mt-2 whitespace-pre-line leading-7 text-gray-600">{{ block.item.body }}</p>
                    </template>
                </section>
            </div>

            <button
                v-if="editable"
                type="button"
                class="col-span-full report-drop flex min-h-28 flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed border-emerald-200 bg-emerald-50/50 p-6 text-sm text-emerald-800 hover:border-emerald-500 cursor-pointer"
                @dragover.prevent="dropTarget = 'end'"
                @drop.stop="drop($event, null)"
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

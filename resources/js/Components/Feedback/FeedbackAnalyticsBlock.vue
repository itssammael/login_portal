<script setup>
import FeedbackAnalyticsChart from './FeedbackAnalyticsChart.vue';
import { reportBlocks } from './feedbackAnalyticsLayout';

defineOptions({
    name: 'FeedbackAnalyticsBlock',
});

const props = defineProps({
    block: { type: Object, required: true },
    index: { type: Number, required: true },
    siblingCount: { type: Number, required: true },
    parentColumns: { type: Number, default: 12 },
    parentId: { type: String, default: null },
    depth: { type: Number, default: 0 },
    report: { type: Object, required: true },
    data: { type: Object, default: null },
    editable: { type: Boolean, default: false },
    selectedKey: { type: String, default: null },
    dropTarget: { type: String, default: null },
});

const emit = defineEmits([
    'select',
    'drop-block',
    'drag-block',
    'move',
    'edit-text',
    'update:dropTarget',
]);

function childrenOf(containerId) {
    return props.report ? reportBlocks(props.report, containerId) : [];
}

function blockSpanClass(block, maxCols = 12) {
    let span = Number(block?.item?.width);
    if (!span || span < 1) {
        span = block?.kind === 'section' || block?.kind === 'blank' ? maxCols : Math.min(6, maxCols);
    }
    span = Math.min(Math.max(span, 1), maxCols);

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

function blankSectionBorderStyle(section) {
    const style = section?.border_style ?? 'solid';
    const width = style === 'none' ? 0 : Number(section?.border_width ?? 1);
    const radius = Number(section?.border_radius ?? 16);
    const color = section?.border_color || '#e7dcc3';
    return {
        borderWidth: `${Math.min(Math.max(width, 0), 16)}px`,
        borderStyle: style,
        borderRadius: `${Math.min(Math.max(radius, 0), 48)}px`,
        borderColor: color,
    };
}

function blankSectionGridStyle(section) {
    const cols = Math.min(Math.max(Number(section?.columns) || 12, 1), 12);
    const rows = Math.min(Math.max(Number(section?.rows) || 1, 1), 12);
    return {
        gridTemplateColumns: `repeat(${cols}, minmax(0, 1fr))`,
        gridTemplateRows: `repeat(${rows}, minmax(64px, auto))`,
    };
}

function onDrop(event, beforeKey, targetParentId = null) {
    if (!props.editable) return;
    event.preventDefault();
    event.stopPropagation();
    emit('update:dropTarget', null);
    emit('drop-block', { event, beforeKey, parentId: targetParentId });
}

function onBlockDrop(event) {
    if (props.block.kind === 'blank') {
        onDrop(event, null, props.block.item.id);
    } else {
        onDrop(event, props.block.key, props.parentId);
    }
}

function onBlockDragOver() {
    if (!props.editable) return;
    if (props.block.kind === 'blank') {
        emit('update:dropTarget', `container:${props.block.item.id}`);
    } else {
        emit('update:dropTarget', props.block.key);
    }
}

function onEdit(key, field, event) {
    emit('edit-text', { key, field, value: event.target.value });
}
</script>

<template>
    <div
        class="report-block relative min-w-0 rounded-2xl flex flex-col"
        :class="[
            blockSpanClass(block, parentColumns),
            blockRowSpanClass(block),
            {
                'ring-2 ring-emerald-600': editable && selectedKey === block.key,
                'ring-offset-4': editable && selectedKey === block.key && depth === 0,
                'ring-offset-2': editable && selectedKey === block.key && depth > 0,
                'outline outline-2 outline-dashed outline-emerald-400': editable && (dropTarget === block.key || (block.kind === 'blank' && dropTarget === `container:${block.item.id}`)),
            },
        ]"
        :style="blockHeightStyle(block)"
        @click.stop="editable && emit('select', block.key)"
        @dragover.prevent.stop="onBlockDragOver"
        @dragleave="emit('update:dropTarget', null)"
        @drop.stop="onBlockDrop"
    >
        <div
            v-if="editable"
            class="flex items-center justify-between gap-2 rounded-t-xl border border-emerald-100 bg-emerald-50 px-3 text-xs text-emerald-900"
            :class="depth > 0 ? 'py-1.5' : 'py-2'"
        >
            <button
                type="button"
                draggable="true"
                class="cursor-grab rounded px-1 py-0.5 font-semibold active:cursor-grabbing"
                :aria-label="`Drag ${block.item.title || 'Blank section'}`"
                @dragstart.stop="emit('drag-block', { event: $event, key: block.key })"
                @dragend="emit('update:dropTarget', null)"
                @click.stop="emit('select', block.key)"
            >
                ⠿ {{ block.kind === 'chart' ? (block.item.kind === 'custom' ? `Custom Graph (${block.item.type})` : block.item.type) : (block.kind === 'blank' ? `Blank section (${block.item.rows || 1}×${block.item.columns || 12})` : 'Text section') }}
            </button>
            <div class="flex gap-1">
                <button
                    type="button"
                    :disabled="index === 0"
                    class="rounded px-1.5 py-0.5 disabled:opacity-30"
                    :aria-label="`Move ${block.item.title || 'Blank section'} up`"
                    @click.stop="emit('move', { key: block.key, direction: -1 })"
                >
                    ↑
                </button>
                <button
                    type="button"
                    :disabled="index === siblingCount - 1"
                    class="rounded px-1.5 py-0.5 disabled:opacity-30"
                    :aria-label="`Move ${block.item.title || 'Blank section'} down`"
                    @click.stop="emit('move', { key: block.key, direction: 1 })"
                >
                    ↓
                </button>
                <button
                    type="button"
                    class="rounded px-1.5 py-0.5 underline font-semibold text-emerald-950 hover:bg-emerald-100 transition cursor-pointer"
                    @click.stop="emit('select', block.key)"
                >
                    Edit
                </button>
            </div>
        </div>

        <FeedbackAnalyticsChart
            v-if="block.kind === 'chart'"
            :chart="block.item"
            :result="data?.charts?.[block.item.id]"
        />

        <!-- Blank Section Container (supports arbitrary nesting depth) -->
        <section
            v-else-if="block.kind === 'blank'"
            class="bg-white/90 flex-1 flex flex-col gap-4 h-full transition-all"
            :class="depth > 0 ? 'p-4' : 'p-5'"
            :style="blankSectionBorderStyle(block.item)"
            @dragover.prevent.stop="editable && emit('update:dropTarget', `container:${block.item.id}`)"
            @dragleave="emit('update:dropTarget', null)"
            @drop.stop="onDrop($event, null, block.item.id)"
        >
            <div
                v-if="block.item.show_heading !== false && (editable || block.item.title)"
                class="flex items-center justify-between gap-2"
            >
                <input
                    v-if="editable"
                    :value="block.item.title"
                    aria-label="Blank section title on canvas"
                    maxlength="200"
                    placeholder="Optional section heading…"
                    class="canvas-input text-lg font-bold text-gray-900"
                    @click.stop="emit('select', block.key)"
                    @input="onEdit(block.key, 'title', $event)"
                >
                <h2 v-else-if="block.item.title" class="text-lg font-bold text-gray-900">{{ block.item.title }}</h2>
            </div>

            <div
                class="grid gap-4 flex-1"
                :style="blankSectionGridStyle(block.item)"
            >
                <FeedbackAnalyticsBlock
                    v-for="(child, childIndex) in childrenOf(block.item.id)"
                    :key="child.key"
                    :block="child"
                    :index="childIndex"
                    :sibling-count="childrenOf(block.item.id).length"
                    :parent-columns="block.item.columns || 12"
                    :parent-id="block.item.id"
                    :depth="depth + 1"
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
                    @update:drop-target="emit('update:dropTarget', $event)"
                />

                <button
                    v-if="editable"
                    type="button"
                    class="col-span-full flex min-h-24 flex-col items-center justify-center gap-1.5 rounded-xl border-2 border-dashed border-emerald-200 bg-emerald-50/40 p-4 text-xs text-emerald-800 hover:border-emerald-500 cursor-pointer transition"
                    :class="dropTarget === `container:${block.item.id}` ? 'border-emerald-500 bg-emerald-50/80 ring-2 ring-emerald-300' : ''"
                    @dragover.prevent.stop="emit('update:dropTarget', `container:${block.item.id}`)"
                    @drop.stop="onDrop($event, null, block.item.id)"
                    @click.stop="emit('select', block.key)"
                >
                    <span class="text-lg font-bold">＋</span>
                    <span class="font-semibold">{{ childrenOf(block.item.id).length ? 'Drop any block here to add inside this Blank Section' : 'Empty Blank Section — Drag or insert any block here' }}</span>
                    <span class="text-[11px] text-gray-500">Grid: {{ block.item.rows || 1 }} {{ (block.item.rows || 1) === 1 ? 'row' : 'rows' }} × {{ block.item.columns || 12 }} {{ (block.item.columns || 12) === 1 ? 'column' : 'columns' }}</span>
                </button>
            </div>
        </section>

        <!-- Text Section -->
        <section
            v-else
            class="rounded-2xl bg-white flex-1 flex flex-col h-full"
            :class="depth > 0 ? 'border border-cream-400 p-5' : 'p-6'"
        >
            <template v-if="editable">
                <input
                    :value="block.item.title"
                    aria-label="Section title on canvas"
                    maxlength="200"
                    class="canvas-input font-bold"
                    :class="depth > 0 ? 'text-lg' : 'text-xl'"
                    @click.stop="emit('select', block.key)"
                    @input="onEdit(block.key, 'title', $event)"
                >
                <textarea
                    :value="block.item.body"
                    aria-label="Section text on canvas"
                    maxlength="3000"
                    rows="3"
                    placeholder="Write your report notes here…"
                    class="canvas-input mt-2 resize-y leading-7 text-gray-600"
                    @click.stop="emit('select', block.key)"
                    @input="onEdit(block.key, 'body', $event)"
                />
            </template>
            <template v-else>
                <h2 :class="depth > 0 ? 'text-lg font-bold' : 'text-xl font-bold'">{{ block.item.title }}</h2>
                <p class="mt-2 whitespace-pre-line leading-7 text-gray-600">{{ block.item.body }}</p>
            </template>
        </section>
    </div>
</template>

<style scoped>
.canvas-input { display: block; width: 100%; padding: 0; border: 0; background: transparent; border-radius: 0.25rem; }
.canvas-input:focus { outline: 2px solid #a7f3d0; outline-offset: 4px; box-shadow: none; }
</style>

<script setup>
import { ref, watch, onMounted, computed, nextTick } from 'vue';
import { sanitizeHtml, stripHtml } from '@/Utils/htmlSanitizer';

const props = defineProps({
    modelValue: {
        type: String,
        default: '',
    },
    placeholder: {
        type: String,
        default: 'Type here...',
    },
    mode: {
        type: String,
        default: 'basic', // 'basic' | 'rich'
    },
    allowBullets: {
        type: Boolean,
        default: false,
    },
    allowTables: {
        type: Boolean,
        default: false,
    },
    singleLine: {
        type: Boolean,
        default: false,
    },
    minHeight: {
        type: String,
        default: 'auto',
    },
    inputClass: {
        type: String,
        default: '',
    },
});

const emit = defineEmits(['update:modelValue', 'blur', 'focus', 'change']);

const editorRef = ref(null);
const isFocused = ref(false);
const showTableMenu = ref(false);
const tableGridHover = ref({ rows: 3, cols: 3 });
const activeTable = ref(null);
const activeTableCell = ref(null);

const canBullets = computed(() => props.mode === 'rich' || props.allowBullets);
const canTables = computed(() => props.mode === 'rich' || props.allowTables);

const isEmpty = computed(() => {
    if (!props.modelValue) return true;
    const stripped = stripHtml(props.modelValue);
    return stripped.trim() === '' && !props.modelValue.includes('<table');
});

// Format execution helpers
const exec = (command, value = null) => {
    if (!editorRef.value) return;
    editorRef.value.focus();
    document.execCommand(command, false, value);
    onEditorInput();
    updateActiveTableState();
};

const setAlignment = (align) => {
    if (!editorRef.value) return;
    editorRef.value.focus();
    const map = {
        left: 'justifyLeft',
        center: 'justifyCenter',
        right: 'justifyRight',
        justify: 'justifyFull',
    };
    document.execCommand(map[align] || 'justifyLeft', false, null);
    onEditorInput();
};

const clearFormatting = () => {
    if (!editorRef.value) return;
    editorRef.value.focus();
    document.execCommand('removeFormat', false, null);
    onEditorInput();
};

// Table Insertion & Management
const insertTable = (rows = 3, cols = 3) => {
    if (!editorRef.value || !canTables.value) return;
    editorRef.value.focus();

    const r = Math.max(1, Math.min(rows, 10));
    const c = Math.max(1, Math.min(cols, 10));

    let html = '<table class="form-rich-table border-collapse w-full my-2 border border-cream-400 bg-white text-xs text-left">';
    html += '<thead><tr class="bg-cream-200/80 border-b border-cream-400 font-bold text-forest-950">';
    for (let j = 0; j < c; j++) {
        html += `<th class="border border-cream-400 p-2">Header ${j + 1}</th>`;
    }
    html += '</tr></thead><tbody>';
    for (let i = 0; i < r; i++) {
        html += '<tr class="border-b border-cream-300 hover:bg-cream-50/50">';
        for (let j = 0; j < c; j++) {
            html += '<td class="border border-cream-400 p-2">Cell</td>';
        }
        html += '</tr>';
    }
    html += '</tbody></table><p><br></p>';

    document.execCommand('insertHTML', false, html);
    showTableMenu.value = false;
    onEditorInput();
    nextTick(() => updateActiveTableState());
};

const findClosest = (el, selector) => {
    let current = el;
    while (current && current !== editorRef.value) {
        if (current.matches && current.matches(selector)) {
            return current;
        }
        current = current.parentElement;
    }
    return null;
};

const updateActiveTableState = () => {
    const sel = window.getSelection();
    if (!sel || !sel.rangeCount) {
        activeTable.value = null;
        activeTableCell.value = null;
        return;
    }
    const node = sel.anchorNode;
    const cell = findClosest(node, 'th, td');
    const table = findClosest(node, 'table');
    activeTableCell.value = cell;
    activeTable.value = table;
};

const addTableRow = (below = true) => {
    if (!activeTable.value || !activeTableCell.value) return;
    const row = activeTableCell.value.parentElement;
    const colCount = row.children.length;
    const newRow = document.createElement('tr');
    newRow.className = 'border-b border-cream-300 hover:bg-cream-50/50';

    for (let i = 0; i < colCount; i++) {
        const cell = document.createElement('td');
        cell.className = 'border border-cream-400 p-2';
        cell.innerHTML = 'Cell';
        newRow.appendChild(cell);
    }

    if (below) {
        row.insertAdjacentElement('afterend', newRow);
    } else {
        row.insertAdjacentElement('beforebegin', newRow);
    }
    onEditorInput();
};

const deleteTableRow = () => {
    if (!activeTable.value || !activeTableCell.value) return;
    const row = activeTableCell.value.parentElement;
    const tbody = row.parentElement;
    row.remove();
    if (tbody.children.length === 0) {
        activeTable.value.remove();
        activeTable.value = null;
        activeTableCell.value = null;
    }
    onEditorInput();
};

const addTableColumn = (after = true) => {
    if (!activeTable.value || !activeTableCell.value) return;
    const cellIdx = Array.from(activeTableCell.value.parentElement.children).indexOf(activeTableCell.value);
    const rows = activeTable.value.querySelectorAll('tr');

    rows.forEach(tr => {
        const isHead = tr.parentElement.tagName === 'THEAD';
        const newCell = document.createElement(isHead ? 'th' : 'td');
        newCell.className = isHead
            ? 'border border-cream-400 p-2 font-bold bg-cream-200/80 text-forest-950'
            : 'border border-cream-400 p-2';
        newCell.innerHTML = isHead ? 'Header' : 'Cell';

        const targetCell = tr.children[cellIdx];
        if (targetCell) {
            if (after) {
                targetCell.insertAdjacentElement('afterend', newCell);
            } else {
                targetCell.insertAdjacentElement('beforebegin', newCell);
            }
        } else {
            tr.appendChild(newCell);
        }
    });
    onEditorInput();
};

const deleteTableColumn = () => {
    if (!activeTable.value || !activeTableCell.value) return;
    const cellIdx = Array.from(activeTableCell.value.parentElement.children).indexOf(activeTableCell.value);
    const rows = activeTable.value.querySelectorAll('tr');

    rows.forEach(tr => {
        if (tr.children[cellIdx]) {
            tr.children[cellIdx].remove();
        }
    });

    const firstRow = activeTable.value.querySelector('tr');
    if (!firstRow || firstRow.children.length === 0) {
        activeTable.value.remove();
        activeTable.value = null;
        activeTableCell.value = null;
    }
    onEditorInput();
};

const deleteTable = () => {
    if (!activeTable.value) return;
    activeTable.value.remove();
    activeTable.value = null;
    activeTableCell.value = null;
    onEditorInput();
};

// Input and sync
const onEditorInput = () => {
    if (!editorRef.value) return;
    const raw = editorRef.value.innerHTML;
    // Normalize empty content
    const cleaned = (raw === '<br>' || raw === '<p><br></p>' || raw.trim() === '') ? '' : raw;
    const sanitized = sanitizeHtml(cleaned);
    emit('update:modelValue', sanitized);
    emit('change', sanitized);
};

const onKeydown = (e) => {
    if (props.singleLine && e.key === 'Enter') {
        e.preventDefault();
        return;
    }
};

const onPaste = (e) => {
    e.preventDefault();
    const text = e.clipboardData.getData('text/plain');
    const html = e.clipboardData.getData('text/html');

    if (html && canTables.value && html.includes('<table')) {
        const sanitized = sanitizeHtml(html);
        document.execCommand('insertHTML', false, sanitized);
    } else {
        document.execCommand('insertText', false, text);
    }
    onEditorInput();
};

const onEditorFocus = (e) => {
    isFocused.value = true;
    updateActiveTableState();
    emit('focus', e);
};

const onEditorBlur = (e) => {
    isFocused.value = false;
    emit('blur', e);
};

// Sync external modelValue changes
watch(
    () => props.modelValue,
    (newVal) => {
        if (!editorRef.value) return;
        const current = editorRef.value.innerHTML;
        const normalizedNew = newVal || '';
        if (normalizedNew !== current && sanitizeHtml(normalizedNew) !== sanitizeHtml(current)) {
            editorRef.value.innerHTML = normalizedNew;
            updateActiveTableState();
        }
    }
);

onMounted(() => {
    if (editorRef.value) {
        editorRef.value.innerHTML = props.modelValue || '';
    }
});
</script>

<template>
    <div
        class="form-rich-editor rounded-xl border transition-all duration-150 relative bg-white"
        :class="[
            isFocused
                ? 'border-forest-600 ring-2 ring-forest-100 shadow-xs'
                : 'border-cream-400 hover:border-cream-500'
        ]"
    >
        <!-- Formatting Toolbar -->
        <div
            class="flex items-center flex-wrap gap-1 px-2.5 py-1.5 bg-cream-100/80 border-b border-cream-300/80 rounded-t-xl text-gray-700 select-none"
            @mousedown.prevent
        >
            <!-- Font Style Buttons -->
            <div class="inline-flex items-center space-x-0.5 bg-white rounded-lg p-0.5 border border-cream-300 shadow-2xs">
                <button
                    type="button"
                    @click="exec('bold')"
                    class="size-6 rounded flex items-center justify-center font-black text-xs hover:bg-cream-100 hover:text-forest-900 transition cursor-pointer"
                    title="Bold (Ctrl+B)"
                >
                    <span class="font-extrabold text-[13px]">B</span>
                </button>
                <button
                    type="button"
                    @click="exec('italic')"
                    class="size-6 rounded flex items-center justify-center italic text-xs hover:bg-cream-100 hover:text-forest-900 transition cursor-pointer"
                    title="Italic (Ctrl+I)"
                >
                    <span class="font-serif italic font-bold text-[13px]">I</span>
                </button>
                <button
                    type="button"
                    @click="exec('underline')"
                    class="size-6 rounded flex items-center justify-center underline text-xs hover:bg-cream-100 hover:text-forest-900 transition cursor-pointer"
                    title="Underline (Ctrl+U)"
                >
                    <span class="underline font-bold text-[13px]">U</span>
                </button>
                <button
                    type="button"
                    @click="exec('strikeThrough')"
                    class="size-6 rounded flex items-center justify-center line-through text-xs hover:bg-cream-100 hover:text-forest-900 transition cursor-pointer"
                    title="Strikethrough"
                >
                    <span class="line-through text-[12px] font-bold">S</span>
                </button>
            </div>

            <!-- Alignment Buttons -->
            <div class="inline-flex items-center space-x-0.5 bg-white rounded-lg p-0.5 border border-cream-300 shadow-2xs">
                <button
                    type="button"
                    @click="setAlignment('left')"
                    class="size-6 rounded flex items-center justify-center text-xs hover:bg-cream-100 hover:text-forest-900 transition cursor-pointer"
                    title="Align Left"
                >
                    <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h10.5m-10.5 5.25h16.5" />
                    </svg>
                </button>
                <button
                    type="button"
                    @click="setAlignment('center')"
                    class="size-6 rounded flex items-center justify-center text-xs hover:bg-cream-100 hover:text-forest-900 transition cursor-pointer"
                    title="Align Center"
                >
                    <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M6.75 12h10.5m-13.5 5.25h16.5" />
                    </svg>
                </button>
                <button
                    type="button"
                    @click="setAlignment('right')"
                    class="size-6 rounded flex items-center justify-center text-xs hover:bg-cream-100 hover:text-forest-900 transition cursor-pointer"
                    title="Align Right"
                >
                    <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M9.75 12h10.5m-16.5 5.25h16.5" />
                    </svg>
                </button>
                <button
                    type="button"
                    @click="setAlignment('justify')"
                    class="size-6 rounded flex items-center justify-center text-xs hover:bg-cream-100 hover:text-forest-900 transition cursor-pointer"
                    title="Justify"
                >
                    <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
                    </svg>
                </button>
            </div>

            <!-- Bullet & Number Lists (Exclusive to Text Only) -->
            <div v-if="canBullets" class="inline-flex items-center space-x-0.5 bg-white rounded-lg p-0.5 border border-cream-300 shadow-2xs">
                <button
                    type="button"
                    @click="exec('insertUnorderedList')"
                    class="size-6 rounded flex items-center justify-center text-xs hover:bg-cream-100 hover:text-forest-900 transition cursor-pointer"
                    title="Bullet List"
                >
                    <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 6.75h12M8.25 12h12m-12 5.25h12M3.75 6.75h.007v.008H3.75V6.75zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zM3.75 12h.007v.008H3.75V12zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zM3.75 17.25h.007v.008H3.75v-.008zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z" />
                    </svg>
                </button>
                <button
                    type="button"
                    @click="exec('insertOrderedList')"
                    class="size-6 rounded flex items-center justify-center text-xs hover:bg-cream-100 hover:text-forest-900 transition cursor-pointer"
                    title="Numbered List"
                >
                    <span class="text-[10px] font-black font-mono">1.2</span>
                </button>
            </div>

            <!-- Draw Table (Exclusive to Text Only) -->
            <div v-if="canTables" class="relative inline-flex items-center">
                <button
                    type="button"
                    @click="showTableMenu = !showTableMenu"
                    class="h-7 px-2 bg-white rounded-lg border border-cream-300 shadow-2xs flex items-center space-x-1.5 text-xs font-bold text-gray-700 hover:bg-cream-50 hover:text-forest-900 transition cursor-pointer"
                    title="Draw / Insert Table"
                >
                    <svg class="size-3.5 text-forest-800" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.375 19.5h17.25m-17.25 0a1.125 1.125 0 01-1.125-1.125M3.375 19.5h7.5c.621 0 1.125-.504 1.125-1.125m-9.75 0V5.625m0 12.75v-1.5c0-.621.504-1.125 1.125-1.125m18.375 2.625V5.625m0 12.75c0 .621-.504 1.125-1.125 1.125m1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125m0 3.75h-7.5A1.125 1.125 0 0112 18.375m9.75-12.75c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125m19.5 0v1.5c0 .621-.504 1.125-1.125 1.125M2.25 5.625v1.5c0 .621.504 1.125 1.125 1.125m0 0h17.25m-17.25 0h7.5c.621 0 1.125.504 1.125 1.125M3.375 8.25c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125m17.25-3.75h-7.5c-.621 0-1.125.504-1.125 1.125m8.625-1.125c.621 0 1.125.504 1.125 1.125v1.5c0 .621-.504 1.125-1.125 1.125" />
                    </svg>
                    <span>Table</span>
                    <span class="text-[9px] text-gray-400">▾</span>
                </button>

                <!-- Table Grid Dropdown -->
                <div
                    v-if="showTableMenu"
                    class="absolute left-0 top-full mt-1 z-50 p-3 bg-white rounded-xl shadow-xl border border-cream-400 text-xs w-52 space-y-2.5 animate-in fade-in"
                >
                    <div class="flex items-center justify-between pb-1.5 border-b border-cream-200">
                        <span class="font-bold text-gray-800">Draw / Insert Table</span>
                        <span class="font-mono text-[10px] text-forest-800 font-bold bg-cream-100 px-1.5 py-0.5 rounded">
                            {{ tableGridHover.rows }} x {{ tableGridHover.cols }}
                        </span>
                    </div>

                    <!-- Visual Grid Matrix Picker -->
                    <div class="grid grid-cols-5 gap-1 p-1 bg-cream-50 rounded-lg border border-cream-300">
                        <template v-for="r in 5" :key="`r_${r}`">
                            <button
                                v-for="c in 5"
                                :key="`c_${r}_${c}`"
                                type="button"
                                @mouseenter="tableGridHover = { rows: r, cols: c }"
                                @click="insertTable(r, c)"
                                class="size-6 rounded border transition cursor-pointer"
                                :class="[
                                    r <= tableGridHover.rows && c <= tableGridHover.cols
                                        ? 'bg-forest-900 border-forest-950'
                                        : 'bg-white border-cream-300 hover:border-forest-600'
                                ]"
                            ></button>
                        </template>
                    </div>

                    <!-- Quick buttons -->
                    <div class="grid grid-cols-2 gap-1.5 pt-1">
                        <button
                            type="button"
                            @click="insertTable(2, 2)"
                            class="px-2 py-1 bg-cream-100 hover:bg-cream-200 rounded-lg text-[11px] font-semibold text-gray-700 text-center cursor-pointer"
                        >
                            2 × 2 Table
                        </button>
                        <button
                            type="button"
                            @click="insertTable(3, 3)"
                            class="px-2 py-1 bg-cream-100 hover:bg-cream-200 rounded-lg text-[11px] font-semibold text-gray-700 text-center cursor-pointer"
                        >
                            3 × 3 Table
                        </button>
                    </div>
                </div>
            </div>

            <!-- Clear formatting -->
            <button
                type="button"
                @click="clearFormatting"
                class="size-7 rounded-lg bg-white border border-cream-300 shadow-2xs flex items-center justify-center text-xs text-gray-500 hover:text-red-600 hover:border-red-300 transition cursor-pointer ml-auto"
                title="Clear Formatting"
            >
                <span class="text-xs font-bold">✕</span>
            </button>
        </div>

        <!-- Table Context Actions (shown when cursor is inside a table) -->
        <div
            v-if="canTables && activeTable"
            class="flex items-center flex-wrap gap-1 px-3 py-1 bg-amber-50/90 border-b border-amber-200 text-[11px] text-amber-900 font-semibold select-none"
            @mousedown.prevent
        >
            <span class="text-[10px] uppercase tracking-wider font-bold text-amber-800 mr-1 flex items-center space-x-1">
                <span>▦ Table:</span>
            </span>
            <button
                type="button"
                @click="addTableRow(true)"
                class="px-2 py-0.5 bg-white border border-amber-300 rounded hover:bg-amber-100 text-amber-950 cursor-pointer"
                title="Add Row Below"
            >
                + Row
            </button>
            <button
                type="button"
                @click="deleteTableRow"
                class="px-2 py-0.5 bg-white border border-amber-300 rounded hover:bg-red-50 text-red-700 cursor-pointer"
                title="Delete Row"
            >
                - Row
            </button>
            <span class="text-amber-300">|</span>
            <button
                type="button"
                @click="addTableColumn(true)"
                class="px-2 py-0.5 bg-white border border-amber-300 rounded hover:bg-amber-100 text-amber-950 cursor-pointer"
                title="Add Column Right"
            >
                + Col
            </button>
            <button
                type="button"
                @click="deleteTableColumn"
                class="px-2 py-0.5 bg-white border border-amber-300 rounded hover:bg-red-50 text-red-700 cursor-pointer"
                title="Delete Column"
            >
                - Col
            </button>
            <span class="text-amber-300">|</span>
            <button
                type="button"
                @click="deleteTable"
                class="px-2 py-0.5 bg-red-100 border border-red-300 text-red-800 rounded hover:bg-red-200 cursor-pointer"
                title="Delete Table"
            >
                🗑 Delete Table
            </button>
        </div>

        <!-- Contenteditable Canvas -->
        <div class="relative">
            <!-- Placeholder -->
            <div
                v-if="isEmpty && !isFocused"
                @click="editorRef?.focus()"
                class="absolute left-3 top-2.5 text-gray-400 pointer-events-none text-xs select-none italic"
            >
                {{ placeholder }}
            </div>

            <div
                ref="editorRef"
                contenteditable="true"
                @input="onEditorInput"
                @keydown="onKeydown"
                @paste="onPaste"
                @focus="onEditorFocus"
                @blur="onEditorBlur"
                @keyup="updateActiveTableState"
                @mouseup="updateActiveTableState"
                class="form-rich-canvas w-full px-3 py-2.5 text-xs text-gray-800 focus:outline-none transition leading-relaxed"
                :class="[
                    singleLine ? 'whitespace-nowrap overflow-x-auto min-h-[36px]' : 'min-h-[70px] overflow-y-auto',
                    inputClass
                ]"
                :style="{ minHeight: minHeight !== 'auto' ? minHeight : undefined }"
            ></div>
        </div>
    </div>
</template>

<style>
/* Scoped Table & Rich Text Styling inside the Editor Canvas */
.form-rich-canvas .form-rich-table {
    width: 100%;
    border-collapse: collapse;
    margin: 0.5rem 0;
    border: 1px solid #ddd0ab;
}

.form-rich-canvas .form-rich-table th,
.form-rich-canvas .form-rich-table td {
    border: 1px solid #ddd0ab;
    padding: 6px 8px;
    font-size: 0.75rem;
    min-width: 60px;
}

.form-rich-canvas .form-rich-table th {
    background-color: #faf4df;
    font-weight: 700;
    color: #1b4332;
}

.form-rich-canvas ul {
    list-style-type: disc !important;
    padding-left: 1.25rem !important;
    margin: 0.25rem 0 !important;
}

.form-rich-canvas ol {
    list-style-type: decimal !important;
    padding-left: 1.25rem !important;
    margin: 0.25rem 0 !important;
}

.form-rich-canvas p {
    margin: 0.2rem 0;
}
</style>

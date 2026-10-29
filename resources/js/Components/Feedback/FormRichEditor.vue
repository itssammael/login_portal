<script setup>
import { ref, shallowRef, watch, onMounted, onUnmounted, computed, nextTick, inject } from 'vue';
import { sanitizeHtml, stripHtml, hasHtmlTags } from '@/Utils/htmlSanitizer';

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
        default: 'rich',
    },
    allowBullets: {
        type: Boolean,
        default: true,
    },
    allowTables: {
        type: Boolean,
        default: true,
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
    formData: {
        type: Object,
        default: null,
    },
});

const emit = defineEmits(['update:modelValue', 'blur', 'focus', 'change']);

const injectedFormData = inject('analyticsFormData', null);

const resolvedFormData = computed(() => {
    if (props.formData) return props.formData;
    const injected = injectedFormData?.value ?? injectedFormData;
    return injected || null;
});

const hasFormData = computed(() => {
    return Array.isArray(resolvedFormData.value?.questions) && resolvedFormData.value.questions.length > 0;
});

const editorRef = ref(null);
const isFocused = ref(false);
const showTableMenu = ref(false);
const tableGridHover = ref({ rows: 3, cols: 3 });
const activeTable = shallowRef(null);
const activeTableCell = shallowRef(null);
const activeCellColspan = ref(1);
const activeCellRowspan = ref(1);
const canMergeRight = ref(false);
const canMergeDown = ref(false);
const canSplit = ref(false);
const selectedCellsCount = ref(0);

// Add Form Data state
const showFormDataMenu = ref(false);
const showQuestionDropdown = ref(false);
const formDataSearch = ref('');
const formDataQuestionId = ref('');
const formDataMode = ref('calculated'); // 'calculated' | 'grouped' | 'collected'
const formDataCalcMetric = ref('mean'); // 'mean' | 'sum' | 'median' | 'min' | 'max' | 'stddev' | 'count'
const formDataCalcFormat = ref('value'); // 'value' | 'metric_value' | 'question_value' | 'value_count'
const formDataGroupScope = ref('all'); // 'all' | 'top' | 'opt:<value>'
const formDataGroupMetric = ref('count_pct'); // 'count_pct' | 'count' | 'pct'
const formDataGroupLayout = ref('inline'); // 'inline' | 'multiline' | 'bullets'
const formDataIncludeLabel = ref(true);
const formDataCollectedScope = ref('all'); // 'all' | 'unique' | 'custom' | 'resp:<index>'
const formDataCollectedFormat = ref('quoted_csv'); // 'quoted_csv' | 'bracket_array' | 'plain_csv' | 'multiline' | 'bullets'
const formDataSelectedResponseIndices = ref([]);
const savedSelectionRange = shallowRef(null);
const savedTargetCell = shallowRef(null);

const canBullets = computed(() => props.allowBullets !== false);
const canTables = computed(() => props.allowTables !== false);

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

/**
 * Builds 2D grid matrix of the table accounting for colspans and rowspans.
 * Filters direct child rows of this table to avoid nested table contamination.
 * Returns { grid, numRows, numCols, rows, cellPositions }
 */
const buildTableGrid = (table) => {
    if (!table) return null;
    const rows = Array.from(table.querySelectorAll('tr')).filter((tr) => tr.closest('table') === table);
    const grid = [];
    const cellPositions = new Map();

    rows.forEach((tr, r) => {
        if (!grid[r]) grid[r] = [];
        let c = 0;
        Array.from(tr.children).forEach((cell) => {
            if (!['TH', 'TD'].includes(cell.tagName)) return;
            while (grid[r][c] !== undefined) {
                c++;
            }
            const rawRowspan = parseInt(cell.getAttribute('rowspan') || '1', 10);
            const rawColspan = parseInt(cell.getAttribute('colspan') || '1', 10);
            const rowspan = Math.max(1, isNaN(rawRowspan) ? 1 : rawRowspan);
            const colspan = Math.max(1, isNaN(rawColspan) ? 1 : rawColspan);

            cellPositions.set(cell, { row: r, col: c, rowspan, colspan });

            for (let dr = 0; dr < rowspan; dr++) {
                const targetR = r + dr;
                if (!grid[targetR]) grid[targetR] = [];
                for (let dc = 0; dc < colspan; dc++) {
                    grid[targetR][c + dc] = cell;
                }
            }
            c += colspan;
        });
    });

    const numRows = grid.length;
    let numCols = 0;
    for (let r = 0; r < numRows; r++) {
        if (grid[r] && grid[r].length > numCols) {
            numCols = grid[r].length;
        }
    }

    return { grid, numRows, numCols, rows, cellPositions };
};

const getSelectedCellsInTable = () => {
    if (!activeTable.value) return [];
    const sel = window.getSelection();
    if (!sel || !sel.rangeCount) return [];
    const range = sel.getRangeAt(0);

    const allCells = Array.from(activeTable.value.querySelectorAll('th, td')).filter(
        (cell) => cell.closest('table') === activeTable.value
    );
    return allCells.filter((cell) => {
        try {
            return sel.containsNode(cell, true) || range.intersectsNode(cell);
        } catch (e) {
            return false;
        }
    });
};

const updateActiveTableState = () => {
    const sel = window.getSelection();
    if (!sel || !sel.rangeCount) {
        if (activeTableCell.value) {
            activeTableCell.value.classList.remove('form-rich-active-cell');
        }
        activeTable.value = null;
        activeTableCell.value = null;
        canMergeRight.value = false;
        canMergeDown.value = false;
        canSplit.value = false;
        selectedCellsCount.value = 0;
        return;
    }
    const node = sel.anchorNode;
    const cell = findClosest(node, 'th, td');
    const table = findClosest(node, 'table');

    if (activeTableCell.value && activeTableCell.value !== cell) {
        activeTableCell.value.classList.remove('form-rich-active-cell');
    }

    activeTableCell.value = cell;
    activeTable.value = table;

    if (!table || !cell) {
        canMergeRight.value = false;
        canMergeDown.value = false;
        canSplit.value = false;
        selectedCellsCount.value = 0;
        return;
    }

    cell.classList.add('form-rich-active-cell');

    const cSpan = parseInt(cell.getAttribute('colspan') || '1', 10);
    const rSpan = parseInt(cell.getAttribute('rowspan') || '1', 10);
    activeCellColspan.value = Math.max(1, isNaN(cSpan) ? 1 : cSpan);
    activeCellRowspan.value = Math.max(1, isNaN(rSpan) ? 1 : rSpan);
    canSplit.value = activeCellColspan.value > 1 || activeCellRowspan.value > 1;

    const tableInfo = buildTableGrid(table);
    if (tableInfo) {
        const pos = tableInfo.cellPositions.get(cell);
        if (pos) {
            // Check merge right (adjacent column in same row)
            const rightCol = pos.col + pos.colspan;
            const rightCell = tableInfo.grid[pos.row]?.[rightCol];
            const rightPos = rightCell ? tableInfo.cellPositions.get(rightCell) : null;
            canMergeRight.value = Boolean(
                rightCell &&
                rightPos &&
                rightPos.row === pos.row &&
                rightPos.rowspan === pos.rowspan
            );

            // Check merge down (adjacent row) - must be in the same rowgroup (e.g. tbody)
            const belowRow = pos.row + pos.rowspan;
            const belowCell = tableInfo.grid[belowRow]?.[pos.col];
            const belowPos = belowCell ? tableInfo.cellPositions.get(belowCell) : null;
            const isSameRowgroup = cell.parentElement?.parentElement === belowCell?.parentElement?.parentElement;

            canMergeDown.value = Boolean(
                belowCell &&
                belowPos &&
                isSameRowgroup &&
                belowPos.col === pos.col &&
                belowPos.colspan === pos.colspan
            );
        }
    }

    const selCells = getSelectedCellsInTable();
    selectedCellsCount.value = selCells.length;
};

// Merge current cell with the cell to the right (Colspan)
const mergeCellRight = () => {
    if (!activeTable.value || !activeTableCell.value) return;
    const tableInfo = buildTableGrid(activeTable.value);
    if (!tableInfo) return;
    const pos = tableInfo.cellPositions.get(activeTableCell.value);
    if (!pos) return;

    const { row, col, rowspan, colspan } = pos;
    const targetCol = col + colspan;
    if (targetCol >= tableInfo.numCols) return;

    const rightCell = tableInfo.grid[row]?.[targetCol];
    if (!rightCell || rightCell === activeTableCell.value) return;

    const rightPos = tableInfo.cellPositions.get(rightCell);
    if (!rightPos || rightPos.row !== row || rightPos.rowspan !== rowspan) return;

    // Merge contents
    const rightContent = rightCell.innerHTML.trim();
    if (rightContent && rightContent !== '<br>' && rightContent !== 'Cell' && rightContent !== 'Header') {
        const currentContent = activeTableCell.value.innerHTML.trim();
        if (!currentContent || currentContent === '<br>' || currentContent === 'Cell' || currentContent === 'Header') {
            activeTableCell.value.innerHTML = rightContent;
        } else {
            activeTableCell.value.innerHTML = currentContent + ' ' + rightContent;
        }
    }

    const newColspan = colspan + rightPos.colspan;
    activeTableCell.value.setAttribute('colspan', String(newColspan));
    rightCell.remove();

    onEditorInput();
    updateActiveTableState();
};

// Merge current cell with the cell below (Rowspan)
const mergeCellDown = () => {
    if (!activeTable.value || !activeTableCell.value) return;
    const tableInfo = buildTableGrid(activeTable.value);
    if (!tableInfo) return;
    const pos = tableInfo.cellPositions.get(activeTableCell.value);
    if (!pos) return;

    const { row, col, rowspan, colspan } = pos;
    const targetRow = row + rowspan;
    if (targetRow >= tableInfo.numRows) return;

    const belowCell = tableInfo.grid[targetRow]?.[col];
    if (!belowCell || belowCell === activeTableCell.value) return;

    // Verify same rowgroup (HTML forbids cells spanning across thead and tbody)
    if (activeTableCell.value.parentElement?.parentElement !== belowCell.parentElement?.parentElement) {
        return;
    }

    const belowPos = tableInfo.cellPositions.get(belowCell);
    if (!belowPos || belowPos.col !== col || belowPos.colspan !== colspan) return;

    // Merge contents
    const belowContent = belowCell.innerHTML.trim();
    if (belowContent && belowContent !== '<br>' && belowContent !== 'Cell' && belowContent !== 'Header') {
        const currentContent = activeTableCell.value.innerHTML.trim();
        if (!currentContent || currentContent === '<br>' || currentContent === 'Cell' || currentContent === 'Header') {
            activeTableCell.value.innerHTML = belowContent;
        } else {
            activeTableCell.value.innerHTML = currentContent + '<br>' + belowContent;
        }
    }

    const newRowspan = rowspan + belowPos.rowspan;
    activeTableCell.value.setAttribute('rowspan', String(newRowspan));
    belowCell.remove();

    onEditorInput();
    updateActiveTableState();
};

// Merge multiple highlighted/selected cells into one rectangular merged cell
const mergeSelectedCells = () => {
    if (!activeTable.value) return;
    const selected = getSelectedCellsInTable();
    if (selected.length < 2) return;

    const tableInfo = buildTableGrid(activeTable.value);
    if (!tableInfo) return;

    // Verify all selected cells belong to the same rowgroup
    const firstRowgroup = selected[0].parentElement?.parentElement;
    const allSameRowgroup = selected.every((c) => c.parentElement?.parentElement === firstRowgroup);
    if (!allSameRowgroup) return;

    let minR = Infinity;
    let maxR = -Infinity;
    let minC = Infinity;
    let maxC = -Infinity;

    for (const cell of selected) {
        const pos = tableInfo.cellPositions.get(cell);
        if (!pos) return;
        minR = Math.min(minR, pos.row);
        maxR = Math.max(maxR, pos.row + pos.rowspan - 1);
        minC = Math.min(minC, pos.col);
        maxC = Math.max(maxC, pos.col + pos.colspan - 1);
    }

    // Verify rectangular integrity: every grid cell in [minR..maxR] x [minC..maxC] must belong to selected
    const expectedCellsInBox = new Set();
    for (let r = minR; r <= maxR; r++) {
        for (let c = minC; c <= maxC; c++) {
            const cell = tableInfo.grid[r]?.[c];
            if (!cell) return;
            const pos = tableInfo.cellPositions.get(cell);
            if (!pos) return;
            if (
                pos.row < minR ||
                pos.row + pos.rowspan - 1 > maxR ||
                pos.col < minC ||
                pos.col + pos.colspan - 1 > maxC
            ) {
                return;
            }
            expectedCellsInBox.add(cell);
        }
    }

    if (expectedCellsInBox.size !== selected.length) return;

    const targetColspan = maxC - minC + 1;
    const targetRowspan = maxR - minR + 1;
    const primaryCell = tableInfo.grid[minR]?.[minC];
    if (!primaryCell) return;

    let mergedHtml = primaryCell.innerHTML.trim();
    if (mergedHtml === 'Cell' || mergedHtml === 'Header' || mergedHtml === '<br>') mergedHtml = '';

    const cellsToRemove = new Set();
    for (let r = minR; r <= maxR; r++) {
        for (let c = minC; c <= maxC; c++) {
            const cell = tableInfo.grid[r]?.[c];
            if (cell && cell !== primaryCell) {
                cellsToRemove.add(cell);
            }
        }
    }

    cellsToRemove.forEach((cell) => {
        const text = cell.innerHTML.trim();
        if (text && text !== 'Cell' && text !== 'Header' && text !== '<br>') {
            mergedHtml = mergedHtml ? mergedHtml + '<br>' + text : text;
        }
        cell.remove();
    });

    if (mergedHtml) {
        primaryCell.innerHTML = mergedHtml;
    }

    if (targetColspan > 1) {
        primaryCell.setAttribute('colspan', String(targetColspan));
    } else {
        primaryCell.removeAttribute('colspan');
    }

    if (targetRowspan > 1) {
        primaryCell.setAttribute('rowspan', String(targetRowspan));
    } else {
        primaryCell.removeAttribute('rowspan');
    }

    onEditorInput();
    updateActiveTableState();
};

// Split / Unmerge cell back into individual grid cells
const unmergeCell = () => {
    if (!activeTable.value || !activeTableCell.value) return;
    const tableInfo = buildTableGrid(activeTable.value);
    if (!tableInfo) return;
    const pos = tableInfo.cellPositions.get(activeTableCell.value);
    if (!pos) return;

    const { row, col, rowspan, colspan } = pos;
    if (rowspan <= 1 && colspan <= 1) return;

    activeTableCell.value.removeAttribute('colspan');
    activeTableCell.value.removeAttribute('rowspan');

    for (let dr = 0; dr < rowspan; dr++) {
        const targetRowIdx = row + dr;
        const targetRow = tableInfo.rows[targetRowIdx];
        if (!targetRow) continue;

        let refCell = null;
        for (let c = col + colspan; c < tableInfo.numCols; c++) {
            const candidate = tableInfo.grid[targetRowIdx]?.[c];
            if (candidate && candidate.parentElement === targetRow) {
                refCell = candidate;
                break;
            }
        }

        const isHead = targetRow.parentElement?.tagName === 'THEAD';
        const startDc = dr === 0 ? 1 : 0;

        for (let dc = startDc; dc < colspan; dc++) {
            const newCell = document.createElement(isHead ? 'th' : 'td');
            newCell.className = isHead
                ? 'border border-cream-400 p-2 font-bold bg-cream-200/80 text-forest-950'
                : 'border border-cream-400 p-2';
            newCell.innerHTML = '<br>';

            if (refCell) {
                targetRow.insertBefore(newCell, refCell);
            } else {
                targetRow.appendChild(newCell);
            }
        }
    }

    onEditorInput();
    updateActiveTableState();
};

const addTableRow = (below = true) => {
    if (!activeTable.value || !activeTableCell.value) return;
    const tableInfo = buildTableGrid(activeTable.value);
    if (!tableInfo) return;
    const pos = tableInfo.cellPositions.get(activeTableCell.value);
    if (!pos) return;

    const baseRowIdx = below ? pos.row + pos.rowspan - 1 : pos.row;
    const insertAtRowIdx = below ? baseRowIdx + 1 : baseRowIdx;
    const refTr = tableInfo.rows[baseRowIdx];
    if (!refTr) return;

    const newRow = document.createElement('tr');
    newRow.className = 'border-b border-cream-300 hover:bg-cream-50/50';

    const handledSpanCells = new Set();
    let col = 0;
    while (col < tableInfo.numCols) {
        const cellAbove = insertAtRowIdx > 0 ? tableInfo.grid[insertAtRowIdx - 1]?.[col] : null;
        const cellPos = cellAbove ? tableInfo.cellPositions.get(cellAbove) : null;

        if (
            below &&
            cellAbove &&
            cellPos &&
            cellPos.row + cellPos.rowspan > insertAtRowIdx &&
            !handledSpanCells.has(cellAbove)
        ) {
            handledSpanCells.add(cellAbove);
            cellAbove.setAttribute('rowspan', String(cellPos.rowspan + 1));
            col += cellPos.colspan;
        } else {
            const cell = document.createElement('td');
            cell.className = 'border border-cream-400 p-2';
            cell.innerHTML = 'Cell';
            newRow.appendChild(cell);
            col++;
        }
    }

    if (below) {
        refTr.insertAdjacentElement('afterend', newRow);
    } else {
        refTr.insertAdjacentElement('beforebegin', newRow);
    }
    onEditorInput();
    updateActiveTableState();
};

const deleteTableRow = () => {
    if (!activeTable.value || !activeTableCell.value) return;
    const tableInfo = buildTableGrid(activeTable.value);
    if (!tableInfo) return;
    const pos = tableInfo.cellPositions.get(activeTableCell.value);
    if (!pos) return;

    const delRowIdx = pos.row;
    const targetTr = tableInfo.rows[delRowIdx];
    if (!targetTr) return;

    const nextTr = tableInfo.rows[delRowIdx + 1];
    const handledCells = new Set();

    for (let c = 0; c < tableInfo.numCols; c++) {
        const cell = tableInfo.grid[delRowIdx]?.[c];
        if (!cell || handledCells.has(cell)) continue;
        handledCells.add(cell);

        const cellPos = tableInfo.cellPositions.get(cell);
        if (!cellPos) continue;

        if (cellPos.row < delRowIdx) {
            const newRowspan = cellPos.rowspan - 1;
            if (newRowspan > 1) {
                cell.setAttribute('rowspan', String(newRowspan));
            } else {
                cell.removeAttribute('rowspan');
            }
        } else if (cellPos.row === delRowIdx && cellPos.rowspan > 1 && nextTr) {
            const newRowspan = cellPos.rowspan - 1;
            if (newRowspan > 1) {
                cell.setAttribute('rowspan', String(newRowspan));
            } else {
                cell.removeAttribute('rowspan');
            }
            let inserted = false;
            for (let nextC = c + 1; nextC < tableInfo.numCols; nextC++) {
                const neighbor = tableInfo.grid[delRowIdx + 1]?.[nextC];
                if (neighbor && neighbor.parentElement === nextTr && neighbor !== cell) {
                    nextTr.insertBefore(cell, neighbor);
                    inserted = true;
                    break;
                }
            }
            if (!inserted) {
                nextTr.appendChild(cell);
            }
        }
    }

    targetTr.remove();

    if (activeTable.value.querySelectorAll('tr').length === 0) {
        activeTable.value.remove();
        activeTable.value = null;
        activeTableCell.value = null;
    }
    onEditorInput();
    updateActiveTableState();
};

const addTableColumn = (after = true) => {
    if (!activeTable.value || !activeTableCell.value) return;
    const tableInfo = buildTableGrid(activeTable.value);
    if (!tableInfo) return;
    const pos = tableInfo.cellPositions.get(activeTableCell.value);
    if (!pos) return;

    const baseCol = after ? pos.col + pos.colspan - 1 : pos.col;
    const insertAtCol = after ? baseCol + 1 : baseCol;
    const handledSpanCells = new Set();

    tableInfo.rows.forEach((tr, rIdx) => {
        const isHead = tr.parentElement?.tagName === 'THEAD';

        const cellLeft = insertAtCol > 0 ? tableInfo.grid[rIdx]?.[insertAtCol - 1] : null;
        const cellLeftPos = cellLeft ? tableInfo.cellPositions.get(cellLeft) : null;

        if (
            after &&
            cellLeft &&
            cellLeftPos &&
            cellLeftPos.col + cellLeftPos.colspan > insertAtCol &&
            !handledSpanCells.has(cellLeft)
        ) {
            handledSpanCells.add(cellLeft);
            cellLeft.setAttribute('colspan', String(cellLeftPos.colspan + 1));
            return;
        }

        const newCell = document.createElement(isHead ? 'th' : 'td');
        newCell.className = isHead
            ? 'border border-cream-400 p-2 font-bold bg-cream-200/80 text-forest-950'
            : 'border border-cream-400 p-2';
        newCell.innerHTML = isHead ? 'Header' : 'Cell';

        let refCell = null;
        for (let c = insertAtCol; c < tableInfo.numCols; c++) {
            const candidate = tableInfo.grid[rIdx]?.[c];
            if (candidate && candidate.parentElement === tr) {
                refCell = candidate;
                break;
            }
        }

        if (refCell) {
            tr.insertBefore(newCell, refCell);
        } else {
            tr.appendChild(newCell);
        }
    });

    onEditorInput();
    updateActiveTableState();
};

const deleteTableColumn = () => {
    if (!activeTable.value || !activeTableCell.value) return;
    const tableInfo = buildTableGrid(activeTable.value);
    if (!tableInfo) return;
    const pos = tableInfo.cellPositions.get(activeTableCell.value);
    if (!pos) return;

    const delCol = pos.col;
    const handledCells = new Set();

    for (let r = 0; r < tableInfo.numRows; r++) {
        const cell = tableInfo.grid[r]?.[delCol];
        if (!cell || handledCells.has(cell)) continue;
        handledCells.add(cell);

        const cellPos = tableInfo.cellPositions.get(cell);
        if (!cellPos) continue;

        if (cellPos.colspan > 1) {
            const newColspan = cellPos.colspan - 1;
            if (newColspan > 1) {
                cell.setAttribute('colspan', String(newColspan));
            } else {
                cell.removeAttribute('colspan');
            }
        } else {
            cell.remove();
        }
    }

    const firstRow = activeTable.value.querySelector('tr');
    if (!firstRow || firstRow.children.length === 0 || activeTable.value.querySelectorAll('th, td').length === 0) {
        activeTable.value.remove();
        activeTable.value = null;
        activeTableCell.value = null;
    }
    onEditorInput();
    updateActiveTableState();
};

const deleteTable = () => {
    if (!activeTable.value) return;
    if (typeof window !== 'undefined' && !window.confirm('Are you sure you want to delete this table?')) {
        return;
    }
    activeTable.value.remove();
    activeTable.value = null;
    activeTableCell.value = null;
    onEditorInput();
};

// Input and sync
const onEditorInput = () => {
    if (!editorRef.value) return;
    let raw = editorRef.value.innerHTML;
    if (raw.includes('form-rich-active-cell')) {
        raw = raw.replace(/\s*form-rich-active-cell\s*/g, ' ').replace(/class="\s*"/g, '');
    }
    // Check if effectively empty (whitespace or empty tags only, unless it has a table or hr)
    const stripped = stripHtml(raw).replace(/&nbsp;/g, ' ').trim();
    const hasStructure = raw.includes('<table') || raw.includes('<hr');
    if (stripped === '' && !hasStructure) {
        emit('update:modelValue', '');
        emit('change', '');
        return;
    }
    const sanitized = sanitizeHtml(raw);
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

    if (props.singleLine) {
        const cleanText = text.replace(/[\r\n]+/g, ' ');
        document.execCommand('insertText', false, cleanText);
        onEditorInput();
        return;
    }

    if (props.mode === 'rich' && html) {
        const sanitized = sanitizeHtml(html);
        if (sanitized && hasHtmlTags(sanitized)) {
            document.execCommand('insertHTML', false, sanitized);
        } else {
            document.execCommand('insertText', false, text);
        }
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
    if (editorRef.value && !showFormDataMenu.value) {
        editorRef.value.querySelectorAll('.form-rich-active-cell').forEach((el) => {
            el.classList.remove('form-rich-active-cell');
        });
    }
    emit('blur', e);
};

// Add Form Data Computed & Helpers
const escapeHtml = (str) => {
    return String(str ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
};

const formatStatNumber = (val) => {
    if (val === null || val === undefined || val === '' || Number.isNaN(Number(val))) {
        return '0';
    }
    const num = Number(val);
    if (Number.isInteger(num)) {
        return String(num);
    }
    return String(Number(num.toFixed(2)));
};

const formQuestionsList = computed(() => {
    return resolvedFormData.value?.questions || [];
});

const formHasSections = computed(() => {
    return formQuestionsList.value.some((q) => Boolean(q.section_title));
});

const groupedQuestionsBySection = computed(() => {
    const query = formDataSearch.value.trim().toLowerCase();
    const groups = [];
    const groupMap = new Map();

    formQuestionsList.value.forEach((q) => {
        const plainTitle = stripHtml(q.particular || q.id || '').trim();
        const plainSection = stripHtml(q.section_title || '').trim();
        if (
            query &&
            !plainTitle.toLowerCase().includes(query) &&
            !plainSection.toLowerCase().includes(query)
        ) {
            return;
        }

        const sectionKey = q.section_id || (plainSection ? `sec:${plainSection}` : '__general__');
        const sectionLabel = plainSection || (formHasSections.value ? 'General / Unsectioned' : 'Form Questions');

        if (!groupMap.has(sectionKey)) {
            const grp = {
                key: sectionKey,
                sectionTitle: sectionLabel,
                hasExplicitSection: Boolean(plainSection),
                questions: [],
            };
            groupMap.set(sectionKey, grp);
            groups.push(grp);
        }

        groupMap.get(sectionKey).questions.push({
            ...q,
            plainTitle: plainTitle || q.id,
            plainSection,
        });
    });

    return groups;
});

const selectedFormQuestion = computed(() => {
    const list = formQuestionsList.value;
    if (!list.length) return null;
    const found = list.find((q) => q.id === formDataQuestionId.value);
    return found || list[0];
});

const selectedQuestionSummary = computed(() => {
    const q = selectedFormQuestion.value;
    if (!q) return null;
    return resolvedFormData.value?.summaries?.[q.id] || null;
});

const questionHasCalculated = computed(() => {
    const q = selectedFormQuestion.value;
    if (!q) return false;
    if (q.type === 'number') return true;
    return Boolean(selectedQuestionSummary.value?.has_numeric_values);
});

const questionHasGrouped = computed(() => {
    const q = selectedFormQuestion.value;
    if (!q) return false;
    if (['radio', 'select', 'checkbox'].includes(q.type)) return true;
    return Array.isArray(selectedQuestionSummary.value?.grouped) && selectedQuestionSummary.value.grouped.length > 0;
});

const selectedQuestionResponses = computed(() => {
    const responses = selectedQuestionSummary.value?.responses;
    return Array.isArray(responses) ? responses : [];
});

const selectedQuestionOptions = computed(() => {
    const summaryGroups = selectedQuestionSummary.value?.grouped;
    if (Array.isArray(summaryGroups) && summaryGroups.length > 0) {
        return summaryGroups;
    }
    const q = selectedFormQuestion.value;
    if (Array.isArray(q?.options)) {
        return q.options.map((opt) => {
            const val = typeof opt === 'object' ? String(opt.value ?? opt.label ?? '') : String(opt);
            const lbl = typeof opt === 'object' ? String(opt.label ?? opt.value ?? '') : String(opt);
            return { value: val, label: stripHtml(lbl), count: 0, percentage: 0 };
        });
    }
    return [];
});

const toggleCustomResponseIndex = (idx) => {
    const current = [...formDataSelectedResponseIndices.value];
    const pos = current.indexOf(idx);
    if (pos >= 0) {
        current.splice(pos, 1);
    } else {
        current.push(idx);
    }
    formDataSelectedResponseIndices.value = current;
};

const selectFormQuestion = (q) => {
    if (!q) return;
    formDataQuestionId.value = q.id;
    showQuestionDropdown.value = false;

    const summary = resolvedFormData.value?.summaries?.[q.id];
    const hasCalc = q.type === 'number' || Boolean(summary?.has_numeric_values);
    const hasGrp = ['radio', 'select', 'checkbox'].includes(q.type) || (Array.isArray(summary?.grouped) && summary.grouped.length > 0);

    if (['text', 'textarea', 'date'].includes(q.type)) {
        formDataMode.value = 'collected';
    } else if (q.type === 'number') {
        formDataMode.value = 'calculated';
    } else if (hasGrp) {
        formDataMode.value = 'grouped';
    } else if (hasCalc) {
        formDataMode.value = 'calculated';
    } else {
        formDataMode.value = 'collected';
    }

    formDataGroupScope.value = 'all';
    formDataCollectedScope.value = 'all';
    formDataSelectedResponseIndices.value = (summary?.responses || []).map((r) => r.index);
};

const openFormDataMenu = () => {
    if (showFormDataMenu.value) {
        showFormDataMenu.value = false;
        showQuestionDropdown.value = false;
        return;
    }

    showTableMenu.value = false;

    // Capture current target cell and selection range inside editorRef before focus moves to the menu
    savedTargetCell.value = activeTableCell.value && editorRef.value?.contains(activeTableCell.value)
        ? activeTableCell.value
        : null;

    const sel = window.getSelection();
    if (sel && sel.rangeCount > 0 && editorRef.value?.contains(sel.anchorNode)) {
        savedSelectionRange.value = sel.getRangeAt(0).cloneRange();
        if (!savedTargetCell.value) {
            const cell = findClosest(sel.anchorNode, 'th, td');
            if (cell && editorRef.value.contains(cell)) {
                savedTargetCell.value = cell;
            }
        }
    } else {
        savedSelectionRange.value = null;
    }

    if (savedTargetCell.value) {
        savedTargetCell.value.classList.add('form-rich-active-cell');
    }

    if (!formDataQuestionId.value && formQuestionsList.value.length > 0) {
        selectFormQuestion(formQuestionsList.value[0]);
    } else if (selectedFormQuestion.value) {
        if (formDataMode.value === 'calculated' && !questionHasCalculated.value) {
            formDataMode.value = questionHasGrouped.value ? 'grouped' : 'collected';
        } else if (formDataMode.value === 'grouped' && !questionHasGrouped.value) {
            formDataMode.value = questionHasCalculated.value ? 'calculated' : 'collected';
        }
        if (
            formDataSelectedResponseIndices.value.length === 0 &&
            selectedQuestionResponses.value.length > 0
        ) {
            formDataSelectedResponseIndices.value = selectedQuestionResponses.value.map((r) => r.index);
        }
    }

    formDataSearch.value = '';
    showQuestionDropdown.value = false;
    showFormDataMenu.value = true;
};

const calcMetricLabels = {
    mean: 'Average',
    sum: 'Total',
    median: 'Median',
    min: 'Minimum',
    max: 'Maximum',
    stddev: 'Std. Dev',
    count: 'Responses',
};

const formatGroupedItemMetric = (g) => {
    const count = g?.count ?? 0;
    const pct = formatStatNumber(g?.percentage ?? 0);
    if (formDataGroupMetric.value === 'count') {
        return `${count}`;
    }
    if (formDataGroupMetric.value === 'pct') {
        return `${pct}%`;
    }
    return `${count} (${pct}%)`;
};

const quoteResponseValue = (val) => {
    const clean = String(val ?? '').replace(/"/g, '\\"');
    return `"${clean}"`;
};

const formDataGenerated = computed(() => {
    const q = selectedFormQuestion.value;
    if (!q) return { text: '', html: '' };

    const qTitle = stripHtml(q.particular || q.id || '').trim();
    const summary = selectedQuestionSummary.value;

    // Collected / Single Response mode
    if (formDataMode.value === 'collected') {
        const allResps = selectedQuestionResponses.value;
        let items = [];

        if (formDataCollectedScope.value === 'all') {
            items = allResps.map((r) => String(r.value ?? '').trim()).filter(Boolean);
        } else if (formDataCollectedScope.value === 'unique') {
            items = Array.from(new Set(allResps.map((r) => String(r.value ?? '').trim()).filter(Boolean)));
        } else if (formDataCollectedScope.value === 'custom') {
            const selectedSet = new Set(formDataSelectedResponseIndices.value);
            items = allResps
                .filter((r) => selectedSet.has(r.index))
                .map((r) => String(r.value ?? '').trim())
                .filter(Boolean);
        } else if (formDataCollectedScope.value.startsWith('resp:')) {
            const targetIdx = Number(formDataCollectedScope.value.slice(5));
            const found = allResps.find((r) => Number(r.index) === targetIdx);
            if (found && String(found.value ?? '').trim() !== '') {
                items = [String(found.value).trim()];
            }
        }

        if (!items.length) {
            const emptyText = formDataCollectedFormat.value === 'bracket_array' ? '[]' : '""';
            return { text: emptyText, html: escapeHtml(emptyText) };
        }

        const fmt = props.singleLine && ['multiline', 'bullets'].includes(formDataCollectedFormat.value)
            ? 'quoted_csv'
            : formDataCollectedFormat.value;

        if (fmt === 'bracket_array') {
            const text = `[${items.map(quoteResponseValue).join(', ')}]`;
            return { text, html: escapeHtml(text) };
        }

        if (fmt === 'plain_csv') {
            const text = items.join(', ');
            return { text, html: escapeHtml(text) };
        }

        if (fmt === 'multiline') {
            const text = items.map(quoteResponseValue).join('\n');
            const html = items.map((v) => escapeHtml(quoteResponseValue(v))).join('<br>');
            return { text, html };
        }

        if (fmt === 'bullets' && canBullets.value) {
            const text = items.map((v) => `• ${quoteResponseValue(v)}`).join('\n');
            const html = `<ul>${items.map((v) => `<li>${escapeHtml(quoteResponseValue(v))}</li>`).join('')}</ul>`;
            return { text, html };
        }

        // Default: quoted_csv -> "Value 1", "Value 2", "Value 3"
        const text = items.map(quoteResponseValue).join(', ');
        return { text, html: escapeHtml(text) };
    }

    if (formDataMode.value === 'calculated') {
        const calc = summary?.calculated || {
            count: summary?.answered ?? 0,
            sum: 0,
            mean: 0,
            median: 0,
            min: 0,
            max: 0,
            stddev: 0,
        };
        const metricKey = formDataCalcMetric.value;
        const metricLabel = calcMetricLabels[metricKey] || 'Value';
        const rawVal = metricKey === 'count' ? (calc.count ?? summary?.answered ?? 0) : (calc[metricKey] ?? 0);
        const formattedVal = metricKey === 'count' ? String(rawVal) : formatStatNumber(rawVal);

        let text = formattedVal;
        if (formDataCalcFormat.value === 'metric_value') {
            text = `${metricLabel}: ${formattedVal}`;
        } else if (formDataCalcFormat.value === 'question_value') {
            text = `${qTitle}: ${formattedVal}`;
        } else if (formDataCalcFormat.value === 'value_count') {
            text = `${formattedVal} (n = ${calc.count ?? summary?.answered ?? 0})`;
        }

        return {
            text,
            html: escapeHtml(text),
        };
    }

    // Grouped mode
    const groups = selectedQuestionOptions.value;
    if (!groups.length) {
        return { text: '0 (0%)', html: '0 (0%)' };
    }

    if (formDataGroupScope.value === 'top') {
        const top = [...groups].sort((a, b) => (b.count ?? 0) - (a.count ?? 0))[0] || groups[0];
        const metricStr = formatGroupedItemMetric(top);
        const text = formDataIncludeLabel.value ? `${top.label}: ${metricStr}` : metricStr;
        const html = formDataIncludeLabel.value
            ? `<strong>${escapeHtml(top.label)}:</strong> ${escapeHtml(metricStr)}`
            : escapeHtml(metricStr);
        return { text, html };
    }

    if (formDataGroupScope.value.startsWith('opt:')) {
        const targetVal = formDataGroupScope.value.slice(4);
        const target = groups.find((g) => String(g.value) === targetVal) || groups[0];
        const metricStr = formatGroupedItemMetric(target);
        const text = formDataIncludeLabel.value ? `${target.label}: ${metricStr}` : metricStr;
        const html = formDataIncludeLabel.value
            ? `<strong>${escapeHtml(target.label)}:</strong> ${escapeHtml(metricStr)}`
            : escapeHtml(metricStr);
        return { text, html };
    }

    // All options breakdown
    const layout = props.singleLine ? 'inline' : formDataGroupLayout.value;
    if (layout === 'bullets' && canBullets.value) {
        const text = groups.map((g) => `• ${g.label}: ${formatGroupedItemMetric(g)}`).join('\n');
        const html = `<ul>${groups
            .map((g) => `<li><strong>${escapeHtml(g.label)}:</strong> ${escapeHtml(formatGroupedItemMetric(g))}</li>`)
            .join('')}</ul>`;
        return { text, html };
    }

    if (layout === 'multiline') {
        const text = groups.map((g) => `${g.label}: ${formatGroupedItemMetric(g)}`).join('\n');
        const html = groups
            .map((g) => `<strong>${escapeHtml(g.label)}:</strong> ${escapeHtml(formatGroupedItemMetric(g))}`)
            .join('<br>');
        return { text, html };
    }

    // Inline
    const text = groups.map((g) => `${g.label}: ${formatGroupedItemMetric(g)}`).join(', ');
    const html = groups
        .map((g) => `<strong>${escapeHtml(g.label)}:</strong> ${escapeHtml(formatGroupedItemMetric(g))}`)
        .join(', ');
    return { text, html };
});

const insertFormData = () => {
    if (!editorRef.value) return;
    const { text, html } = formDataGenerated.value;
    if (!text && !html) return;

    const targetCell = savedTargetCell.value && editorRef.value.contains(savedTargetCell.value)
        ? savedTargetCell.value
        : null;

    if (targetCell) {
        const cellPlain = targetCell.textContent.trim();
        const isPlaceholder = cellPlain === '' || cellPlain === 'Cell' || /^Header(\s+\d+)?$/i.test(cellPlain);

        if (isPlaceholder) {
            targetCell.innerHTML = props.singleLine ? escapeHtml(text) : html;
            editorRef.value.focus();
            const range = document.createRange();
            range.selectNodeContents(targetCell);
            range.collapse(false);
            const sel = window.getSelection();
            if (sel) {
                sel.removeAllRanges();
                sel.addRange(range);
            }
        } else if (
            savedSelectionRange.value &&
            targetCell.contains(savedSelectionRange.value.commonAncestorContainer)
        ) {
            editorRef.value.focus();
            const sel = window.getSelection();
            if (sel) {
                sel.removeAllRanges();
                sel.addRange(savedSelectionRange.value);
            }
            document.execCommand(props.singleLine ? 'insertText' : 'insertHTML', false, props.singleLine ? text : html);
        } else {
            targetCell.innerHTML = `${targetCell.innerHTML} ${props.singleLine ? escapeHtml(text) : html}`;
            editorRef.value.focus();
        }
    } else if (
        savedSelectionRange.value &&
        editorRef.value.contains(savedSelectionRange.value.commonAncestorContainer)
    ) {
        editorRef.value.focus();
        const sel = window.getSelection();
        if (sel) {
            sel.removeAllRanges();
            sel.addRange(savedSelectionRange.value);
        }
        document.execCommand(props.singleLine ? 'insertText' : 'insertHTML', false, props.singleLine ? text : html);
    } else {
        editorRef.value.focus();
        const range = document.createRange();
        range.selectNodeContents(editorRef.value);
        range.collapse(false);
        const sel = window.getSelection();
        if (sel) {
            sel.removeAllRanges();
            sel.addRange(range);
        }
        document.execCommand(props.singleLine ? 'insertText' : 'insertHTML', false, props.singleLine ? text : html);
    }

    showFormDataMenu.value = false;
    showQuestionDropdown.value = false;
    onEditorInput();
    nextTick(() => updateActiveTableState());
};

// Sync external modelValue changes
watch(
    () => props.modelValue,
    (newVal) => {
        if (!editorRef.value) return;
        const current = editorRef.value.innerHTML;
        const normalizedNew = newVal || '';
        if (normalizedNew !== current) {
            if (!isFocused.value || sanitizeHtml(normalizedNew) !== sanitizeHtml(current)) {
                editorRef.value.innerHTML = normalizedNew;
                updateActiveTableState();
            }
        }
    }
);

const closeTableMenuOnOutside = (e) => {
    if (showTableMenu.value && !e.target.closest('.table-menu-container')) {
        showTableMenu.value = false;
    }
    if (showFormDataMenu.value && !e.target.closest('.form-data-menu-container') && !e.target.closest('.form-data-trigger-btn')) {
        showFormDataMenu.value = false;
        showQuestionDropdown.value = false;
    }
};

const onSelectionChange = () => {
    if (isFocused.value) {
        updateActiveTableState();
    }
};

onMounted(() => {
    if (editorRef.value) {
        editorRef.value.innerHTML = props.modelValue || '';
    }
    document.addEventListener('mousedown', closeTableMenuOnOutside);
    document.addEventListener('selectionchange', onSelectionChange);
});

onUnmounted(() => {
    document.removeEventListener('mousedown', closeTableMenuOnOutside);
    document.removeEventListener('selectionchange', onSelectionChange);
});
</script>

<template>
    <div
        class="form-rich-editor rounded-xl border transition-all duration-150 relative bg-white w-full min-w-0 max-w-full"
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

            <!-- Create Table Tool -->
            <div v-if="canTables" class="relative inline-flex items-center table-menu-container">
                <button
                    type="button"
                    @click="showTableMenu = !showTableMenu"
                    class="h-7 px-2 bg-white rounded-lg border border-cream-300 shadow-2xs flex items-center space-x-1.5 text-xs font-bold text-gray-700 hover:bg-cream-50 hover:text-forest-900 transition cursor-pointer"
                    title="Create / Insert Table"
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
                    class="absolute left-0 top-full mt-1 z-50 p-3 bg-white rounded-xl shadow-xl border border-cream-400 text-xs w-56 space-y-2.5 animate-in fade-in"
                >
                    <div class="flex items-center justify-between pb-1.5 border-b border-cream-200">
                        <span class="font-bold text-gray-800">Create / Insert Table</span>
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

                    <!-- Active Table Actions inside Dropdown -->
                    <div v-if="activeTable" class="pt-2 border-t border-cream-200 space-y-1.5">
                        <div class="text-[10px] font-bold text-gray-500 uppercase tracking-wider">Active Cell Tools</div>
                        <div class="grid grid-cols-2 gap-1">
                            <button
                                type="button"
                                @click="mergeCellRight(); showTableMenu = false"
                                :disabled="!canMergeRight"
                                class="px-2 py-1 bg-cream-50 hover:bg-cream-100 disabled:opacity-40 rounded text-[11px] text-gray-800 font-medium text-left cursor-pointer"
                                title="Merge with column to the right"
                            >
                                Merge Col →
                            </button>
                            <button
                                type="button"
                                @click="mergeCellDown(); showTableMenu = false"
                                :disabled="!canMergeDown"
                                class="px-2 py-1 bg-cream-50 hover:bg-cream-100 disabled:opacity-40 rounded text-[11px] text-gray-800 font-medium text-left cursor-pointer"
                                title="Merge with row below"
                            >
                                Merge Row ↓
                            </button>
                        </div>
                        <button
                            v-if="canSplit"
                            type="button"
                            @click="unmergeCell(); showTableMenu = false"
                            class="w-full px-2 py-1 bg-purple-50 hover:bg-purple-100 rounded text-[11px] text-purple-900 font-medium text-center cursor-pointer border border-purple-200"
                        >
                            ◫ Split / Unmerge Cell
                        </button>
                    </div>
                </div>
            </div>

            <!-- Add Form Data Tool -->
            <div v-if="hasFormData" class="relative inline-flex items-center">
                <button
                    type="button"
                    @click="openFormDataMenu"
                    class="form-data-trigger-btn h-7 px-2.5 rounded-lg border shadow-2xs flex items-center space-x-1.5 text-xs font-bold transition cursor-pointer"
                    :class="[
                        showFormDataMenu
                            ? 'bg-forest-900 text-white border-forest-950'
                            : 'bg-white text-forest-900 border-cream-300 hover:bg-cream-50 hover:border-forest-600'
                    ]"
                    title="Insert Calculated or Grouped Form Data from Submissions"
                >
                    <svg class="size-3.5 shrink-0" :class="showFormDataMenu ? 'text-amber-300' : 'text-forest-700'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z" />
                    </svg>
                    <span>Add Form Data</span>
                    <span class="text-[9px] opacity-75">▾</span>
                </button>
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

        <!-- Add Form Data Configuration Panel -->
        <div
            v-if="hasFormData && showFormDataMenu"
            class="form-data-menu-container border-b border-cream-300 bg-cream-50/95 p-3 text-xs space-y-2.5 select-none animate-in fade-in"
            @mousedown.stop
        >
            <div class="flex items-center justify-between gap-2 pb-1.5 border-b border-cream-200">
                <div class="flex items-center gap-1.5 flex-wrap">
                    <span class="font-extrabold text-forest-950 uppercase tracking-wider text-[10px]">
                        Insert Form Submission Data
                    </span>
                    <span
                        class="px-1.5 py-0.5 rounded text-[10px] font-bold"
                        :class="savedTargetCell ? 'bg-amber-100 text-amber-900 border border-amber-300' : 'bg-forest-100 text-forest-900'"
                    >
                        {{ savedTargetCell ? '▦ Target: Table Cell' : '✎ Target: Selected Text / Cursor' }}
                    </span>
                </div>
                <div class="flex items-center gap-1.5">
                    <span class="text-[10px] font-mono font-bold text-gray-600 bg-white px-1.5 py-0.5 rounded border border-cream-300">
                        n = {{ resolvedFormData?.submissionCount ?? 0 }}
                    </span>
                    <button
                        type="button"
                        @click="showFormDataMenu = false; showQuestionDropdown = false"
                        class="size-5 rounded flex items-center justify-center text-gray-400 hover:text-gray-700 hover:bg-cream-200 cursor-pointer"
                        title="Close"
                    >
                        ✕
                    </button>
                </div>
            </div>

            <!-- Searchable Question Dropdown (Arranged by Section) -->
            <div class="space-y-1 relative">
                <label class="block text-[10px] font-bold uppercase tracking-wider text-gray-600">
                    Question <span v-if="formHasSections" class="text-forest-800">(Arranged by Section)</span>
                </label>

                <button
                    type="button"
                    @click="showQuestionDropdown = !showQuestionDropdown"
                    class="w-full px-2.5 py-1.5 bg-white rounded-lg border border-cream-400 hover:border-forest-600 text-left flex items-center justify-between gap-2 shadow-2xs cursor-pointer"
                >
                    <div class="min-w-0 flex-1">
                        <div
                            v-if="selectedFormQuestion?.section_title"
                            class="text-[9px] font-extrabold uppercase tracking-wider text-forest-800 truncate"
                        >
                            {{ stripHtml(selectedFormQuestion.section_title) }}
                        </div>
                        <div class="text-xs font-semibold text-gray-900 truncate">
                            {{ selectedFormQuestion ? stripHtml(selectedFormQuestion.particular || selectedFormQuestion.id) : 'Select a question...' }}
                        </div>
                    </div>
                    <div class="flex items-center gap-1.5 shrink-0">
                        <span
                            v-if="selectedFormQuestion"
                            class="px-1.5 py-0.5 rounded text-[9px] font-bold uppercase bg-cream-100 text-gray-700 border border-cream-300"
                        >
                            {{ selectedFormQuestion.type }}
                        </span>
                        <span class="text-[10px] text-gray-500">{{ showQuestionDropdown ? '▴' : '▾' }}</span>
                    </div>
                </button>

                <!-- Searchable Section-Grouped Dropdown List -->
                <div
                    v-if="showQuestionDropdown"
                    class="mt-1 bg-white rounded-xl border border-cream-400 shadow-lg overflow-hidden z-40"
                >
                    <div class="p-2 border-b border-cream-200 bg-cream-50/70">
                        <input
                            v-model="formDataSearch"
                            type="text"
                            placeholder="Search question or section..."
                            class="w-full rounded-lg border border-cream-300 bg-white px-2.5 py-1 text-xs text-gray-800 focus:border-forest-600 focus:outline-none"
                        />
                    </div>

                    <div class="max-h-48 overflow-y-auto divide-y divide-cream-100">
                        <template v-if="groupedQuestionsBySection.length">
                            <div v-for="group in groupedQuestionsBySection" :key="group.key" class="py-1">
                                <div
                                    v-if="formHasSections || group.hasExplicitSection"
                                    class="px-2.5 py-1 bg-cream-100/90 text-[10px] font-extrabold uppercase tracking-wider text-forest-900 sticky top-0"
                                >
                                    § {{ group.sectionTitle }}
                                </div>
                                <button
                                    v-for="q in group.questions"
                                    :key="q.id"
                                    type="button"
                                    @click="selectFormQuestion(q)"
                                    class="w-full px-3 py-1.5 text-left flex items-center justify-between gap-2 hover:bg-cream-100/80 transition cursor-pointer"
                                    :class="selectedFormQuestion?.id === q.id ? 'bg-forest-50/80 font-bold text-forest-950' : 'text-gray-800'"
                                >
                                    <span class="truncate text-xs">{{ q.plainTitle }}</span>
                                    <span class="shrink-0 px-1.5 py-0.5 rounded text-[9px] font-bold uppercase bg-cream-100 text-gray-600 border border-cream-300">
                                        {{ q.type }}
                                    </span>
                                </button>
                            </div>
                        </template>
                        <div v-else class="px-3 py-3 text-center text-gray-400 italic text-xs">
                            No matching questions found.
                        </div>
                    </div>
                </div>
            </div>

            <!-- Mode Switcher: Calculated vs Grouped vs Collected / Single Response -->
            <div v-if="selectedFormQuestion" class="space-y-2">
                <div class="grid grid-cols-3 gap-1 bg-white p-1 rounded-lg border border-cream-300">
                    <button
                        type="button"
                        :disabled="!questionHasCalculated"
                        @click="formDataMode = 'calculated'"
                        class="py-1 px-1.5 rounded-md text-[11px] font-bold transition cursor-pointer disabled:opacity-40 disabled:cursor-not-allowed truncate"
                        :class="formDataMode === 'calculated' ? 'bg-forest-900 text-white shadow-2xs' : 'text-gray-700 hover:bg-cream-100'"
                        title="Calculated (Numerical)"
                    >
                        ∑ Calculated (Numerical)
                    </button>
                    <button
                        type="button"
                        :disabled="!questionHasGrouped"
                        @click="formDataMode = 'grouped'"
                        class="py-1 px-1.5 rounded-md text-[11px] font-bold transition cursor-pointer disabled:opacity-40 disabled:cursor-not-allowed truncate"
                        :class="formDataMode === 'grouped' ? 'bg-forest-900 text-white shadow-2xs' : 'text-gray-700 hover:bg-cream-100'"
                        title="Grouped (Breakdown)"
                    >
                        ▤ Grouped (Breakdown)
                    </button>
                    <button
                        type="button"
                        @click="formDataMode = 'collected'"
                        class="py-1 px-1.5 rounded-md text-[11px] font-bold transition cursor-pointer truncate"
                        :class="formDataMode === 'collected' ? 'bg-forest-900 text-white shadow-2xs' : 'text-gray-700 hover:bg-cream-100'"
                        title="Collected / Single Response"
                    >
                        ❝❞ Collected / Single Response
                    </button>
                </div>

                <!-- Calculated Mode Controls -->
                <div v-if="formDataMode === 'calculated'" class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                    <div>
                        <label class="block text-[10px] font-bold uppercase tracking-wider text-gray-600 mb-0.5">
                            Calculation
                        </label>
                        <select
                            v-model="formDataCalcMetric"
                            class="w-full rounded-lg border border-cream-300 bg-white px-2 py-1 text-xs font-semibold text-gray-800 focus:border-forest-600 focus:outline-none"
                        >
                            <option value="mean">Average (Mean)</option>
                            <option value="sum">Total (Sum)</option>
                            <option value="median">Median</option>
                            <option value="min">Minimum</option>
                            <option value="max">Maximum</option>
                            <option value="stddev">Std. Deviation</option>
                            <option value="count">Response Count (n)</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-[10px] font-bold uppercase tracking-wider text-gray-600 mb-0.5">
                            Display Format
                        </label>
                        <select
                            v-model="formDataCalcFormat"
                            class="w-full rounded-lg border border-cream-300 bg-white px-2 py-1 text-xs font-semibold text-gray-800 focus:border-forest-600 focus:outline-none"
                        >
                            <option value="value">Value Only (e.g. 4.5)</option>
                            <option value="metric_value">Metric + Value (e.g. Average: 4.5)</option>
                            <option value="question_value">Question + Value</option>
                            <option value="value_count">Value + Count (e.g. 4.5 (n = 10))</option>
                        </select>
                    </div>
                </div>

                <!-- Grouped Mode Controls -->
                <div v-else-if="formDataMode === 'grouped'" class="space-y-2">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                        <div>
                            <label class="block text-[10px] font-bold uppercase tracking-wider text-gray-600 mb-0.5">
                                Response Group
                            </label>
                            <select
                                v-model="formDataGroupScope"
                                class="w-full rounded-lg border border-cream-300 bg-white px-2 py-1 text-xs font-semibold text-gray-800 focus:border-forest-600 focus:outline-none"
                            >
                                <option value="all">All Options Breakdown</option>
                                <option value="top">Top / Highest Response Only</option>
                                <option
                                    v-for="opt in selectedQuestionOptions"
                                    :key="`opt_${opt.value}`"
                                    :value="`opt:${opt.value}`"
                                >
                                    Option: {{ opt.label }} ({{ opt.count }} — {{ formatStatNumber(opt.percentage) }}%)
                                </option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-[10px] font-bold uppercase tracking-wider text-gray-600 mb-0.5">
                                Value Format
                            </label>
                            <select
                                v-model="formDataGroupMetric"
                                class="w-full rounded-lg border border-cream-300 bg-white px-2 py-1 text-xs font-semibold text-gray-800 focus:border-forest-600 focus:outline-none"
                            >
                                <option value="count_pct">Count &amp; % — e.g. 15 (60%)</option>
                                <option value="count">Count Only — e.g. 15</option>
                                <option value="pct">Percentage Only — e.g. 60%</option>
                            </select>
                        </div>
                    </div>

                    <div v-if="formDataGroupScope === 'all' && !singleLine" class="flex items-center justify-between gap-2">
                        <span class="text-[10px] font-bold uppercase tracking-wider text-gray-600">Layout:</span>
                        <div class="inline-flex items-center gap-1 bg-white p-0.5 rounded-lg border border-cream-300">
                            <button
                                type="button"
                                @click="formDataGroupLayout = 'inline'"
                                class="px-2 py-0.5 rounded text-[11px] font-semibold cursor-pointer"
                                :class="formDataGroupLayout === 'inline' ? 'bg-forest-900 text-white' : 'text-gray-700 hover:bg-cream-100'"
                            >
                                Inline
                            </button>
                            <button
                                type="button"
                                @click="formDataGroupLayout = 'multiline'"
                                class="px-2 py-0.5 rounded text-[11px] font-semibold cursor-pointer"
                                :class="formDataGroupLayout === 'multiline' ? 'bg-forest-900 text-white' : 'text-gray-700 hover:bg-cream-100'"
                            >
                                Lines
                            </button>
                            <button
                                v-if="canBullets"
                                type="button"
                                @click="formDataGroupLayout = 'bullets'"
                                class="px-2 py-0.5 rounded text-[11px] font-semibold cursor-pointer"
                                :class="formDataGroupLayout === 'bullets' ? 'bg-forest-900 text-white' : 'text-gray-700 hover:bg-cream-100'"
                            >
                                Bullets
                            </button>
                        </div>
                    </div>

                    <label
                        v-else-if="formDataGroupScope !== 'all'"
                        class="inline-flex items-center gap-1.5 text-[11px] font-semibold text-gray-700 cursor-pointer"
                    >
                        <input
                            v-model="formDataIncludeLabel"
                            type="checkbox"
                            class="rounded border-cream-400 text-forest-900 focus:ring-forest-600"
                        />
                        <span>Include option label (uncheck to insert numbers only into table cell)</span>
                    </label>
                </div>

                <!-- Collected / Single Response Mode Controls -->
                <div v-else class="space-y-2">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                        <div>
                            <label class="block text-[10px] font-bold uppercase tracking-wider text-gray-600 mb-0.5">
                                Response Data
                            </label>
                            <select
                                v-model="formDataCollectedScope"
                                class="w-full rounded-lg border border-cream-300 bg-white px-2 py-1 text-xs font-semibold text-gray-800 focus:border-forest-600 focus:outline-none"
                            >
                                <option value="all">
                                    Collected (All Responses — {{ selectedQuestionResponses.length }})
                                </option>
                                <option value="unique">
                                    Collected (Unique Responses Only)
                                </option>
                                <option v-if="selectedQuestionResponses.length > 0" value="custom">
                                    Select Specific Responses (Multi-Select)...
                                </option>
                                <optgroup v-if="selectedQuestionResponses.length > 0" label="Single Form Response">
                                    <option
                                        v-for="resp in selectedQuestionResponses"
                                        :key="`resp_${resp.index}`"
                                        :value="`resp:${resp.index}`"
                                    >
                                        Response #{{ resp.index }}: "{{ resp.value.length > 48 ? resp.value.slice(0, 48) + '...' : resp.value }}"
                                    </option>
                                </optgroup>
                            </select>
                        </div>
                        <div>
                            <label class="block text-[10px] font-bold uppercase tracking-wider text-gray-600 mb-0.5">
                                Value Format
                            </label>
                            <select
                                v-model="formDataCollectedFormat"
                                class="w-full rounded-lg border border-cream-300 bg-white px-2 py-1 text-xs font-semibold text-gray-800 focus:border-forest-600 focus:outline-none"
                            >
                                <option value="quoted_csv">"Value 1", "Value 2" (Quoted, Comma)</option>
                                <option value="bracket_array">["Value 1", "Value 2"] (Array)</option>
                                <option value="plain_csv">Value 1, Value 2 (Plain Text)</option>
                                <option v-if="!singleLine" value="multiline">Line by Line ("Value")</option>
                                <option v-if="!singleLine && canBullets" value="bullets">Bulleted List ("Value")</option>
                            </select>
                        </div>
                    </div>

                    <!-- Multi-select response picker when 'custom' is chosen -->
                    <div
                        v-if="formDataCollectedScope === 'custom' && selectedQuestionResponses.length > 0"
                        class="bg-white rounded-lg border border-cream-300 p-2 space-y-1 max-h-32 overflow-y-auto"
                    >
                        <div class="flex items-center justify-between pb-1 border-b border-cream-200 text-[10px] font-bold text-gray-500">
                            <span>Select which responses to include ({{ formDataSelectedResponseIndices.length }}/{{ selectedQuestionResponses.length }})</span>
                            <div class="space-x-2">
                                <button
                                    type="button"
                                    @click="formDataSelectedResponseIndices = selectedQuestionResponses.map((r) => r.index)"
                                    class="text-forest-800 hover:underline cursor-pointer"
                                >
                                    All
                                </button>
                                <button
                                    type="button"
                                    @click="formDataSelectedResponseIndices = []"
                                    class="text-gray-500 hover:underline cursor-pointer"
                                >
                                    None
                                </button>
                            </div>
                        </div>
                        <label
                            v-for="resp in selectedQuestionResponses"
                            :key="`chk_resp_${resp.index}`"
                            class="flex items-start gap-2 py-0.5 text-xs text-gray-800 cursor-pointer hover:bg-cream-50 rounded px-1"
                        >
                            <input
                                type="checkbox"
                                :checked="formDataSelectedResponseIndices.includes(resp.index)"
                                @change="toggleCustomResponseIndex(resp.index)"
                                class="mt-0.5 rounded border-cream-400 text-forest-900 focus:ring-forest-600"
                            />
                            <span class="min-w-0 flex-1 break-words">
                                <span class="font-mono text-[10px] font-bold text-gray-500 mr-1">#{{ resp.index }}:</span>
                                "{{ resp.value }}"
                            </span>
                        </label>
                    </div>
                </div>

                <!-- Live Preview & Insert Button -->
                <div class="pt-1.5 border-t border-cream-200 flex items-center justify-between gap-2">
                    <div class="min-w-0 flex-1 bg-white px-2.5 py-1.5 rounded-lg border border-cream-300 text-[11px] text-gray-800 truncate" :title="formDataGenerated.text">
                        <span class="text-[9px] font-extrabold uppercase tracking-wider text-gray-400 mr-1.5">Preview:</span>
                        <span class="font-medium">{{ formDataGenerated.text || '—' }}</span>
                    </div>
                    <button
                        type="button"
                        @click="insertFormData"
                        class="shrink-0 px-3 py-1.5 bg-forest-900 hover:bg-forest-800 text-white rounded-lg text-xs font-bold shadow-xs transition cursor-pointer"
                    >
                        + Insert Data
                    </button>
                </div>
            </div>
        </div>

        <!-- Table Context Actions (shown when cursor is inside a table and editor has focus) -->
        <div
            v-if="canTables && (isFocused || showFormDataMenu) && activeTable"
            class="flex items-center flex-wrap gap-1 px-3 py-1.5 bg-amber-50/95 border-b border-amber-200 text-[11px] text-amber-900 font-semibold select-none shadow-2xs"
            @mousedown.prevent
        >
            <span class="text-[10px] uppercase tracking-wider font-extrabold text-amber-800 mr-1 flex items-center space-x-1">
                <span>▦ Table:</span>
            </span>

            <!-- Row controls -->
            <div class="inline-flex items-center space-x-0.5 bg-white rounded-lg p-0.5 border border-amber-300">
                <button
                    type="button"
                    @click="addTableRow(true)"
                    class="px-1.5 py-0.5 rounded hover:bg-amber-100 text-amber-950 cursor-pointer"
                    title="Add Row Below"
                >
                    + Row
                </button>
                <button
                    type="button"
                    @click="deleteTableRow"
                    class="px-1.5 py-0.5 rounded hover:bg-red-50 text-red-700 cursor-pointer"
                    title="Delete Current Row"
                >
                    - Row
                </button>
            </div>

            <!-- Col controls -->
            <div class="inline-flex items-center space-x-0.5 bg-white rounded-lg p-0.5 border border-amber-300">
                <button
                    type="button"
                    @click="addTableColumn(true)"
                    class="px-1.5 py-0.5 rounded hover:bg-amber-100 text-amber-950 cursor-pointer"
                    title="Add Column Right"
                >
                    + Col
                </button>
                <button
                    type="button"
                    @click="deleteTableColumn"
                    class="px-1.5 py-0.5 rounded hover:bg-red-50 text-red-700 cursor-pointer"
                    title="Delete Current Column"
                >
                    - Col
                </button>
            </div>

            <span class="text-amber-300">|</span>

            <!-- Cell Merge & Split controls -->
            <div class="inline-flex items-center space-x-0.5 bg-white rounded-lg p-0.5 border border-amber-300">
                <button
                    v-if="selectedCellsCount > 1"
                    type="button"
                    @click="mergeSelectedCells"
                    class="px-2 py-0.5 bg-forest-800 text-white rounded hover:bg-forest-900 font-bold cursor-pointer text-xs flex items-center space-x-1"
                    :title="`Merge ${selectedCellsCount} selected cells`"
                >
                    <span>⧉ Merge Selected ({{ selectedCellsCount }})</span>
                </button>

                <button
                    type="button"
                    @click="mergeCellRight"
                    :disabled="!canMergeRight"
                    class="px-2 py-0.5 rounded text-amber-950 cursor-pointer disabled:opacity-40 disabled:cursor-not-allowed hover:bg-amber-100 flex items-center space-x-1"
                    title="Merge with Column to the Right (Colspan)"
                >
                    <span>Merge Right (Col) →</span>
                </button>

                <button
                    type="button"
                    @click="mergeCellDown"
                    :disabled="!canMergeDown"
                    class="px-2 py-0.5 rounded text-amber-950 cursor-pointer disabled:opacity-40 disabled:cursor-not-allowed hover:bg-amber-100 flex items-center space-x-1"
                    title="Merge with Row Below (Rowspan)"
                >
                    <span>Merge Down (Row) ↓</span>
                </button>

                <button
                    v-if="canSplit"
                    type="button"
                    @click="unmergeCell"
                    class="px-2 py-0.5 bg-purple-50 text-purple-900 border border-purple-300 rounded hover:bg-purple-100 cursor-pointer font-bold"
                    title="Split / Unmerge this cell into individual cells"
                >
                    ◫ Split Cell
                </button>
            </div>

            <template v-if="hasFormData">
                <span class="text-amber-300">|</span>
                <button
                    type="button"
                    @click="openFormDataMenu"
                    class="form-data-trigger-btn px-2 py-0.5 bg-forest-900 text-white rounded hover:bg-forest-800 cursor-pointer font-bold flex items-center space-x-1"
                    title="Insert Calculated or Grouped Form Data into this Table Cell"
                >
                    <span>📊 + Form Data</span>
                </button>
            </template>

            <span class="text-amber-300">|</span>

            <button
                type="button"
                @click="deleteTable"
                class="px-2 py-0.5 bg-red-100 border border-red-300 text-red-800 rounded hover:bg-red-200 cursor-pointer ml-auto"
                title="Delete Table"
            >
                🗑 Delete Table
            </button>
        </div>

        <!-- Contenteditable Canvas -->
        <div class="relative w-full min-w-0 max-w-full">
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
                class="form-rich-canvas w-full min-w-0 max-w-full px-3 py-2.5 text-xs text-gray-800 focus:outline-none transition leading-relaxed break-words"
                :class="[
                    singleLine ? 'whitespace-normal min-h-[36px]' : 'min-h-[70px] overflow-y-auto',
                    inputClass
                ]"
                :style="{ minHeight: minHeight !== 'auto' ? minHeight : undefined }"
            ></div>
        </div>
    </div>
</template>

<style>
.form-rich-canvas {
    overflow-wrap: anywhere !important;
    word-break: break-word !important;
}

/* Scoped Table & Rich Text Styling inside the Editor Canvas */
.form-rich-canvas table,
.form-rich-canvas .form-rich-table {
    width: 100% !important;
    max-width: 100% !important;
    min-width: 0 !important;
    table-layout: auto !important;
    border-collapse: collapse !important;
    margin: 0.5rem 0 !important;
    border: 1px solid #ddd0ab;
    box-sizing: border-box !important;
}

.form-rich-canvas table *,
.form-rich-canvas .form-rich-table * {
    box-sizing: border-box !important;
}

.form-rich-canvas col,
.form-rich-canvas colgroup,
.form-rich-canvas .form-rich-table col,
.form-rich-canvas .form-rich-table colgroup {
    max-width: 100% !important;
    box-sizing: border-box !important;
}

.form-rich-canvas table th,
.form-rich-canvas table td,
.form-rich-canvas .form-rich-table th,
.form-rich-canvas .form-rich-table td {
    border: 1px solid #ddd0ab;
    padding: 6px 8px;
    font-size: 0.75rem;
    min-width: 28px;
    vertical-align: top;
    box-sizing: border-box !important;
    word-break: break-word !important;
    overflow-wrap: anywhere !important;
    white-space: normal !important;
    max-width: 100% !important;
}

.form-rich-canvas table th:focus,
.form-rich-canvas table td:focus,
.form-rich-canvas .form-rich-table th:focus,
.form-rich-canvas .form-rich-table td:focus,
.form-rich-canvas .form-rich-table th.form-rich-active-cell,
.form-rich-canvas .form-rich-table td.form-rich-active-cell {
    outline: 2px solid #2d6a4f !important;
    outline-offset: -2px;
}

.form-rich-canvas table th,
.form-rich-canvas .form-rich-table th {
    background-color: #faf4df;
    font-weight: 700;
    color: #1b4332;
}

.form-rich-canvas ul {
    list-style-type: disc;
    padding-left: 1.25rem !important;
    margin: 0.25rem 0 !important;
}

.form-rich-canvas ol {
    list-style-type: decimal;
    padding-left: 1.25rem !important;
    margin: 0.25rem 0 !important;
}

.form-rich-canvas p {
    margin: 0.2rem 0;
}

.form-rich-canvas [align="left"],
.form-rich-canvas .text-left {
    text-align: left !important;
}

.form-rich-canvas [align="center"],
.form-rich-canvas .text-center {
    text-align: center !important;
}

.form-rich-canvas [align="right"],
.form-rich-canvas .text-right {
    text-align: right !important;
}

.form-rich-canvas [align="justify"],
.form-rich-canvas .text-justify {
    text-align: justify !important;
}

.form-rich-canvas s,
.form-rich-canvas strike,
.form-rich-canvas del,
.form-rich-canvas .line-through {
    text-decoration: line-through !important;
}

.form-rich-canvas u,
.form-rich-canvas ins,
.form-rich-canvas .underline {
    text-decoration: underline !important;
}

.form-rich-canvas b,
.form-rich-canvas strong,
.form-rich-canvas .font-bold {
    font-weight: 700 !important;
}

.form-rich-canvas i,
.form-rich-canvas em,
.form-rich-canvas .italic {
    font-style: italic !important;
}

.form-rich-canvas blockquote {
    border-left: 3px solid #ddd0ab;
    padding-left: 0.75rem;
    margin: 0.5rem 0;
    color: #4b5563;
    font-style: italic;
}
</style>

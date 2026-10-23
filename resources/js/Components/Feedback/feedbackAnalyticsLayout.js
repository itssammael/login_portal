export const aspectW = 8.333333333;
export const aspectH = 11.111111111;

export function reportBlocks(report, parentId = undefined) {
    const all = [
        ...(report.sections || []).map((item, index) => ({
            key: `section:${item.id || index}`,
            kind: item.kind === 'blank' ? 'blank' : 'section',
            item,
            index,
            fallback: index,
        })),
        ...(report.charts || []).map((item, index) => ({
            key: `chart:${item.id}`,
            kind: 'chart',
            item,
            index,
            fallback: (report.sections?.length || 0) + index,
        })),
    ].sort((a, b) => (a.item.position ?? a.fallback) - (b.item.position ?? b.fallback));

    if (parentId === undefined) {
        return all;
    }

    const normalizedParent = parentId || null;
    return all.filter((block) => (block.item.parent_id || null) === normalizedParent);
}

export function isDescendantOf(report, candidateParentId, sourceBlockId) {
    if (!candidateParentId || !sourceBlockId) return false;
    if (candidateParentId === sourceBlockId) return true;
    const sections = report.sections || [];
    let current = sections.find((s) => s.id === candidateParentId);
    const visited = new Set();
    while (current && current.parent_id && !visited.has(current.id)) {
        if (current.parent_id === sourceBlockId) return true;
        visited.add(current.id);
        current = sections.find((s) => s.id === current.parent_id);
    }
    return false;
}

export function reorderBlock(report, sourceKey, beforeKey = null, targetParentId = undefined) {
    const all = reportBlocks(report);
    const sourceBlock = all.find((block) => block.key === sourceKey);
    if (!sourceBlock || sourceKey === beforeKey) return false;

    let resolvedParentId = targetParentId;
    if (resolvedParentId === undefined) {
        if (beforeKey) {
            const beforeBlock = all.find((block) => block.key === beforeKey);
            if (!beforeBlock) return false;
            resolvedParentId = beforeBlock.item.parent_id || null;
        } else {
            resolvedParentId = sourceBlock.item.parent_id || null;
        }
    } else {
        resolvedParentId = resolvedParentId || null;
    }

    if (sourceBlock.item.id && isDescendantOf(report, resolvedParentId, sourceBlock.item.id)) {
        return false;
    }

    const previousParentId = sourceBlock.item.parent_id || null;
    sourceBlock.item.parent_id = resolvedParentId;

    const siblings = reportBlocks(report, resolvedParentId);
    const sourceIndex = siblings.findIndex((block) => block.key === sourceKey);
    if (sourceIndex < 0) return false;
    if (beforeKey && !siblings.some((block) => block.key === beforeKey)) return false;

    const [moved] = siblings.splice(sourceIndex, 1);
    const targetIndex = beforeKey ? siblings.findIndex((item) => item.key === beforeKey) : siblings.length;
    siblings.splice(targetIndex, 0, moved);
    siblings.forEach((item, index) => {
        item.item.position = index;
    });

    if (previousParentId !== resolvedParentId) {
        reportBlocks(report, previousParentId).forEach((item, index) => {
            item.item.position = index;
        });
    }

    return true;
}

export function moveBlock(report, key, direction) {
    const all = reportBlocks(report);
    const sourceBlock = all.find((block) => block.key === key);
    if (!sourceBlock) return false;
    const parentId = sourceBlock.item.parent_id || null;
    const blocks = reportBlocks(report, parentId);
    const index = blocks.findIndex((block) => block.key === key);
    const target = index + direction;
    if (index < 0 || target < 0 || target >= blocks.length) return false;
    return reorderBlock(report, key, direction < 0 ? blocks[target].key : blocks[target + 1]?.key, parentId);
}


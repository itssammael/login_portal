export const aspectW = 8.333333333;
export const aspectH = 11.111111111;

export function reportBlocks(report) {
    return [
        ...(report.sections || []).map((item, index) => ({ key: `section:${item.id || index}`, kind: 'section', item, index, fallback: index })),
        ...(report.charts || []).map((item, index) => ({ key: `chart:${item.id}`, kind: 'chart', item, index, fallback: (report.sections?.length || 0) + index })),
    ].sort((a, b) => (a.item.position ?? a.fallback) - (b.item.position ?? b.fallback));
}

export function reorderBlock(report, sourceKey, beforeKey = null) {
    const blocks = reportBlocks(report);
    const source = blocks.findIndex((block) => block.key === sourceKey);
    if (source < 0 || sourceKey === beforeKey || (beforeKey && !blocks.some((block) => block.key === beforeKey))) return false;
    const [block] = blocks.splice(source, 1);
    const target = beforeKey ? blocks.findIndex((item) => item.key === beforeKey) : blocks.length;
    blocks.splice(target, 0, block);
    blocks.forEach((item, index) => { item.item.position = index; });
    return true;
}

export function moveBlock(report, key, direction) {
    const blocks = reportBlocks(report);
    const index = blocks.findIndex((block) => block.key === key);
    const target = index + direction;
    if (index < 0 || target < 0 || target >= blocks.length) return false;
    return reorderBlock(report, key, direction < 0 ? blocks[target].key : blocks[target + 1]?.key);
}

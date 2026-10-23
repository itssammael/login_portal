<script setup>
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import * as am5 from '@amcharts/amcharts5';
import * as xy from '@amcharts/amcharts5/xy';
import * as percent from '@amcharts/amcharts5/percent';
import * as radar from '@amcharts/amcharts5/radar';

const props = defineProps({ chart: Object, result: Object });
const container = ref(null);
let root;
function render() {
    root?.dispose();
    root = null;
    if (!container.value || !props.result?.rows?.length || ['table', 'kpi'].includes(props.chart.type)) return;
    root = am5.Root.new(container.value);
    const options = props.chart;
    const rows = props.result.rows;
    let color;
    try {
        color = am5.color(options.color || '#24543c');
    } catch {
        color = am5.color('#24543c');
    }
    let chart;
    let series;
    if (['pie', 'donut'].includes(options.type)) {
        chart = root.container.children.push(percent.PieChart.new(root, { layout: root.verticalLayout, innerRadius: am5.percent(options.type === 'donut' ? 55 : 0) }));
        series = chart.series.push(percent.PieSeries.new(root, { valueField: 'value', categoryField: 'label' }));
        series.get('colors').set('colors', [color, am5.Color.lighten(color, 0.25), am5.Color.lighten(color, 0.5), am5.Color.brighten(color, -0.25)]);
        series.labels.template.setAll({ visible: options.labels, text: '{category}: {value}' });
        series.ticks.template.set('visible', options.labels);
    } else if (options.type === 'radar') {
        chart = root.container.children.push(radar.RadarChart.new(root, { layout: root.verticalLayout }));
        const xAxis = chart.xAxes.push(xy.CategoryAxis.new(root, { categoryField: 'label', renderer: radar.AxisRendererCircular.new(root, {}) }));
        const yAxis = chart.yAxes.push(xy.ValueAxis.new(root, { min: 0, renderer: radar.AxisRendererRadial.new(root, {}) }));
        xAxis.data.setAll(rows);
        series = chart.series.push(radar.RadarLineSeries.new(root, { name: options.title, xAxis, yAxis, categoryXField: 'label', valueYField: 'value', stroke: color, fill: color, tooltip: am5.Tooltip.new(root, { labelText: '{categoryX}: {valueY}' }) }));
        series.fills.template.setAll({ visible: true, fillOpacity: 0.15 });
        if (options.labels) series.bullets.push(() => am5.Bullet.new(root, { sprite: am5.Label.new(root, { text: '{valueY}', populateText: true, centerX: am5.p50, centerY: am5.p100 }) }));
    } else {
        const horizontal = options.type === 'bar';
        chart = root.container.children.push(xy.XYChart.new(root, { layout: root.verticalLayout, wheelX: 'panX', wheelY: 'zoomX', panX: true }));
        const category = xy.CategoryAxis.new(root, { categoryField: 'label', renderer: horizontal ? xy.AxisRendererY.new(root, { inversed: true, minGridDistance: 20 }) : xy.AxisRendererX.new(root, { minGridDistance: 35 }) });
        const value = xy.ValueAxis.new(root, { min: 0, renderer: horizontal ? xy.AxisRendererX.new(root, {}) : xy.AxisRendererY.new(root, {}) });
        const xAxis = chart.xAxes.push(horizontal ? value : category);
        const yAxis = chart.yAxes.push(horizontal ? category : value);
        category.data.setAll(rows);
        const Series = ['line', 'area'].includes(options.type) ? xy.LineSeries : xy.ColumnSeries;
        series = chart.series.push(Series.new(root, {
            name: options.title, xAxis, yAxis, stroke: color, fill: color,
            ...(horizontal ? { valueXField: 'value', categoryYField: 'label' } : { valueYField: 'value', categoryXField: 'label' }),
            tooltip: am5.Tooltip.new(root, { labelText: horizontal ? '{categoryY}: {valueX}' : '{categoryX}: {valueY}' }),
        }));
        if (options.type === 'area') series.fills.template.setAll({ visible: true, fillOpacity: 0.25 });
        if (options.labels) series.bullets.push(() => am5.Bullet.new(root, { sprite: am5.Label.new(root, { text: horizontal ? '{valueX}' : '{valueY}', populateText: true, centerX: am5.p50, centerY: am5.p100 }) }));
        chart.set('cursor', xy.XYCursor.new(root, { behavior: 'zoomX' }));
    }
    series.data.setAll(rows);
    if (options.legend) {
        const legend = chart.children.push(am5.Legend.new(root, { centerX: am5.p50, x: am5.p50 }));
        legend.data.setAll(['pie', 'donut'].includes(options.type) ? series.dataItems : chart.series.values);
    }
}
onMounted(render);
watch(() => [props.chart, props.result], render, { deep: true, flush: 'post' });
onBeforeUnmount(() => root?.dispose());
</script>

<template>
    <section class="flex min-w-0 flex-1 flex-col gap-3 rounded-2xl border border-cream-500 bg-white p-5 shadow-sm h-full">
        <div><h3 class="font-bold text-gray-900">{{ chart.title }}</h3><p class="text-xs capitalize text-gray-500">{{ chart.aggregation }} · {{ result?.answered ?? '—' }} responses</p></div>
        <p v-if="result?.suppressed" class="rounded-xl bg-cream-100 p-6 text-sm text-gray-600">Not enough responses to display this chart safely. Every populated group needs at least five responses.</p>
        <p v-else-if="!result?.rows?.length" class="p-6 text-sm text-gray-500">No data for this reporting period.</p>
        <dl v-else-if="chart.type === 'kpi'" class="flex flex-wrap gap-6"><div v-for="row in result.rows" :key="row.label"><dt class="text-xs text-gray-500">{{ row.label }}</dt><dd class="text-3xl font-black text-forest-900">{{ row.value }}{{ chart.aggregation === 'percentage' ? '%' : '' }}</dd></div></dl>
        <div v-if="!['table', 'kpi'].includes(chart.type)" ref="container" class="w-full flex-1 min-h-[160px]" role="img" :aria-label="chart.title" />
        <details v-if="result?.rows?.length && chart.type !== 'kpi'" :open="chart.type === 'table'" class="text-sm"><summary v-if="chart.type !== 'table'" class="cursor-pointer text-forest-800">View data table</summary><table class="mt-2 w-full text-left"><caption class="sr-only">{{ chart.title }}</caption><thead><tr class="border-b"><th class="py-2">Label</th><th class="py-2 text-right">{{ chart.aggregation }}</th></tr></thead><tbody><tr v-for="row in result.rows" :key="row.label" class="border-b border-gray-100"><td class="py-2">{{ row.label }}</td><td class="py-2 text-right tabular-nums">{{ row.value }}{{ chart.aggregation === 'percentage' ? '%' : '' }}</td></tr></tbody></table></details>
        <p v-if="chart.annotation" class="whitespace-pre-line text-sm leading-6 text-gray-600">{{ chart.annotation }}</p>
    </section>
</template>

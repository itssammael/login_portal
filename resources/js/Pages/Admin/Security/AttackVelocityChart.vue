<script setup>
import { ref, onMounted, onBeforeUnmount, watch } from 'vue';
import * as am5 from '@amcharts/amcharts5';
import * as am5xy from '@amcharts/amcharts5/xy';
import am5themes_Animated from '@amcharts/amcharts5/themes/Animated';

const props = defineProps({
    timeline: {
        type: Array,
        default: () => [],
    },
});

const chartContainer = ref(null);
const activeSeries = ref({
    probes: true,
    ddos: true,
    exploits: true,
});

// Non-reactive references to avoid Vue proxy conflicts with AmCharts internal state
let root = null;
let chartInstance = null;
let seriesMap = {};

const formatBucketLabel = (item) => {
    if (!item) return '';
    if (item.timestamp) {
        try {
            const d = new Date(item.timestamp);
            return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', hour12: false });
        } catch (e) {
            return item.label || '';
        }
    }
    return item.label || '';
};

const toggleSeries = (key) => {
    activeSeries.value[key] = !activeSeries.value[key];
    const series = seriesMap[key];
    if (series) {
        if (activeSeries.value[key]) {
            series.show();
        } else {
            series.hide();
        }
    }
};

const renderChart = () => {
    // Dispose previous chart instance cleanly before re-rendering
    if (root) {
        root.dispose();
        root = null;
        chartInstance = null;
        seriesMap = {};
    }

    if (!chartContainer.value) {
        return;
    }

    const rawData = Array.isArray(props.timeline) ? props.timeline : [];

    // Transform timeline into normalized numerical data
    const chartData = rawData.map((item) => {
        const label = formatBucketLabel(item);
        const probes = Number(item.probes || 0);
        const ddos = Number(item.ddos || 0);
        const exploits = Number(item.exploits || 0);
        const total = Number(item.total ?? (probes + ddos + exploits));

        return {
            label,
            probes,
            ddos,
            exploits,
            total,
        };
    });

    root = am5.Root.new(chartContainer.value);

    // Suppress default watermark logo if present
    if (root._logo) {
        root._logo.dispose();
    }

    root.setThemes([am5themes_Animated.new(root)]);

    const chart = root.container.children.push(
        am5xy.XYChart.new(root, {
            panX: false,
            panY: false,
            wheelX: 'none',
            wheelY: 'none',
            layout: root.verticalLayout,
            paddingLeft: 0,
            paddingRight: 12,
            paddingTop: 8,
            paddingBottom: 0,
        })
    );
    chartInstance = chart;

    // X Axis (Category: 2-hour timeline buckets)
    const xRenderer = am5xy.AxisRendererX.new(root, {
        minGridDistance: 32,
        minorGridEnabled: false,
        cellStartLocation: 0.15,
        cellEndLocation: 0.85,
    });

    xRenderer.grid.template.setAll({
        location: 0.5,
        stroke: am5.color('#ebe1c3'),
        strokeOpacity: 0.35,
        strokeDasharray: [2, 2],
    });

    xRenderer.labels.template.setAll({
        fontSize: 10,
        fill: am5.color('#6b7280'),
        fontWeight: '600',
        paddingTop: 8,
    });

    const xAxis = chart.xAxes.push(
        am5xy.CategoryAxis.new(root, {
            categoryField: 'label',
            renderer: xRenderer,
            tooltip: am5.Tooltip.new(root, {
                themeTags: ['axis'],
            }),
        })
    );

    const axisTooltip = xAxis.get('tooltip');
    if (axisTooltip) {
        axisTooltip.get('background').setAll({
            fill: am5.color('#0f2e1e'),
            fillOpacity: 0.92,
            cornerRadius: 4,
        });
        axisTooltip.label.setAll({
            fill: am5.color('#ffffff'),
            fontSize: 10,
            fontWeight: '600',
        });
    }

    xAxis.data.setAll(chartData);

    // Y Axis (Value: threat incident velocity count)
    const yRenderer = am5xy.AxisRendererY.new(root, {
        strokeOpacity: 0,
        minGridDistance: 24,
    });

    yRenderer.grid.template.setAll({
        stroke: am5.color('#ebe1c3'),
        strokeOpacity: 0.6,
        strokeDasharray: [3, 3],
    });

    yRenderer.labels.template.setAll({
        fontSize: 10,
        fill: am5.color('#9ca3af'),
        fontWeight: '600',
    });

    const yAxis = chart.yAxes.push(
        am5xy.ValueAxis.new(root, {
            min: 0,
            maxPrecision: 0,
            renderer: yRenderer,
            numberFormat: '#',
            extraMax: 0.15,
        })
    );

    // Threat classification stacked series configs:
    // Stacking order: 404 Probes (bottom) -> DDoS (middle) -> Exploits (top)
    const seriesConfigs = [
        {
            key: 'probes',
            name: '404 Probes',
            field: 'probes',
            color: '#f59e0b', // Amber 500
        },
        {
            key: 'ddos',
            name: 'DDoS',
            field: 'ddos',
            color: '#f97316', // Orange 500
        },
        {
            key: 'exploits',
            name: 'Exploits',
            field: 'exploits',
            color: '#ef4444', // Red 500
        },
    ];

    seriesConfigs.forEach((cfg) => {
        const series = chart.series.push(
            am5xy.ColumnSeries.new(root, {
                name: cfg.name,
                xAxis: xAxis,
                yAxis: yAxis,
                baseAxis: xAxis,
                valueYField: cfg.field,
                categoryXField: 'label',
                stacked: true,
                tooltip: am5.Tooltip.new(root, {
                    labelText: `[bold]{name}[/]: {valueY} incident(s)\n[#9ca3af fontSize: 10px]Window: {label} · Total: {total}[/]`,
                    pointerOrientation: 'vertical',
                    getFillFromSprite: false,
                }),
            })
        );

        series.columns.template.setAll({
            width: am5.percent(65),
            maxWidth: 28,
            fill: am5.color(cfg.color),
            stroke: am5.color(cfg.color),
            strokeOpacity: 0,
            cornerRadiusTL: 3,
            cornerRadiusTR: 3,
            tooltipY: 0,
        });

        // Hover state on column
        series.columns.template.states.create('hover', {
            fillOpacity: 0.85,
        });

        const tooltip = series.get('tooltip');
        if (tooltip) {
            tooltip.get('background').setAll({
                fill: am5.color('#0f2e1e'),
                fillOpacity: 0.95,
                stroke: am5.color('#22c55e'),
                strokeWidth: 1,
                strokeOpacity: 0.25,
                cornerRadius: 8,
                shadowColor: am5.color('#000000'),
                shadowBlur: 8,
                shadowOffsetX: 0,
                shadowOffsetY: 4,
                shadowOpacity: 0.25,
            });
            tooltip.label.setAll({
                fill: am5.color('#ffffff'),
                fontSize: 11,
                lineHeight: 1.35,
            });
        }

        series.data.setAll(chartData);

        if (!activeSeries.value[cfg.key]) {
            series.hide();
        } else {
            series.appear(700);
        }

        seriesMap[cfg.key] = series;
    });

    // Interactive cursor snapping to category intervals
    const cursor = chart.set(
        'cursor',
        am5xy.XYCursor.new(root, {
            behavior: 'none',
            xAxis: xAxis,
        })
    );
    cursor.lineY.set('visible', false);
    cursor.lineX.setAll({
        stroke: am5.color('#0f2e1e'),
        strokeOpacity: 0.2,
        strokeDasharray: [3, 3],
    });

    chart.appear(900, 100);
};

watch(
    () => props.timeline,
    () => {
        renderChart();
    },
    { deep: true, flush: 'post' }
);

onMounted(() => {
    renderChart();
});

onBeforeUnmount(() => {
    if (root) {
        root.dispose();
        root = null;
        chartInstance = null;
        seriesMap = {};
    }
});
</script>

<template>
    <div class="flex flex-col">
        <!-- Chart Header & Interactive Series Legend Filter -->
        <div class="flex flex-wrap items-center justify-between gap-3 mb-3">
            <div>
                <h3 class="text-sm font-bold text-gray-950 uppercase tracking-wider">Attack Velocity & Threat Timeline</h3>
                <p class="text-xs text-gray-500 mt-0.5">24-hour volume distribution by threat classification</p>
            </div>

            <!-- Clickable legend items allowing quick filtering / toggling of each threat layer -->
            <div class="flex items-center space-x-3 text-[11px] font-semibold text-gray-600">
                <button
                    type="button"
                    class="flex items-center space-x-1.5 transition-all duration-200 hover:opacity-80 focus:outline-none"
                    :class="{ 'opacity-35 line-through': !activeSeries.probes }"
                    title="Click to toggle 404 Probes"
                    @click="toggleSeries('probes')"
                >
                    <span class="size-2 rounded-full bg-amber-500 shrink-0"></span>
                    <span>404 Probes</span>
                </button>
                <button
                    type="button"
                    class="flex items-center space-x-1.5 transition-all duration-200 hover:opacity-80 focus:outline-none"
                    :class="{ 'opacity-35 line-through': !activeSeries.ddos }"
                    title="Click to toggle DDoS bursts"
                    @click="toggleSeries('ddos')"
                >
                    <span class="size-2 rounded-full bg-orange-500 shrink-0"></span>
                    <span>DDoS</span>
                </button>
                <button
                    type="button"
                    class="flex items-center space-x-1.5 transition-all duration-200 hover:opacity-80 focus:outline-none"
                    :class="{ 'opacity-35 line-through': !activeSeries.exploits }"
                    title="Click to toggle Exploits"
                    @click="toggleSeries('exploits')"
                >
                    <span class="size-2 rounded-full bg-red-500 shrink-0"></span>
                    <span>Exploits</span>
                </button>
            </div>
        </div>

        <!-- AmCharts 5 Container -->
        <div class="relative w-full">
            <div
                ref="chartContainer"
                class="w-full h-48 sm:h-52"
                role="img"
                aria-label="Attack Velocity and Threat Timeline Chart"
            ></div>
        </div>
    </div>
</template>


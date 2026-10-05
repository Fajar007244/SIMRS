<script setup>
/**
 * TrendPanel.vue - tab tren hasil penunjang.
 *
 * Port dari renderTrend() phase1/penunjang.html. phase1 menggambar lima
 * sparkline kecil (W 600 x H 80) sendiri dengan polyline; di sini digantikan
 * TrendChart.vue - SVG murni tanpa pustaka chart, sesuai keputusan proyek
 * bahwa grafik tidak boleh memakai library. Data titik tetap sama:
 * SupportService::getTrend() (LAMA ke BARU), dan min/max dilebarkan sampai
 * memuat batas rentang referensi supaya garis rentang normal ikut tergambar.
 *
 * Baris target (threshold) memakai batas bawah / atas rentang referensi dari
 * prop `ref`.
 *
 * PEMILIH ITEM: grup (lab / blood) dan item tren dibawa lewat query string
 * (trendGroup, trendKey) supaya reload tetap membuka tab dan item yang sama.
 */
import { computed } from 'vue';
import TrendChart from '@/Components/TrendChart.vue';
import EmptyState from '@/Components/EmptyState.vue';
import { formatNumber, isBlank } from '@/composables/useFormatting';

const props = defineProps({
    trend: { type: Object, default: () => ({ key: '', label: '', unit: null, points: [], min: null, max: null }) },
    ref: { type: Object, default: () => ({}) },
    targets: { type: Array, default: () => [] },
    groupOptions: { type: Array, default: () => [] },
    activeGroup: { type: String, default: 'lab' },
    activeKey: { type: String, default: '' },
});

const emit = defineEmits(['select']);

const series = computed(() => [
    {
        name: isBlank(props.trend.unit) ? props.trend.label || '-' : `${props.trend.label} (${props.trend.unit})`,
        color: '#dc2626',
        points: (props.trend.points || []).map((point, index) => ({
            x: index,
            y: Number(point.value),
            label: point.label,
            meta: `${formatNumber(Number(point.value), props.ref.decimals ?? 0)} ${props.trend.unit ?? ''}`.trim(),
        })),
    },
]);

const thresholds = computed(() => {
    const lines = [];

    if (typeof props.ref.low === 'number' && props.ref.low !== 0) {
        lines.push({ y: props.ref.low, label: `Batas bawah ${props.ref.low}`, color: '#f59e0b' });
    }

    if (typeof props.ref.high === 'number' && props.ref.high !== 0) {
        lines.push({ y: props.ref.high, label: `Batas atas ${props.ref.high}`, color: '#f59e0b' });
    }

    return lines;
});

const xLabels = computed(() => (props.trend.points || []).map((point) => point.label));

const yMin = computed(() => (typeof props.trend.min === 'number' ? props.trend.min : 0));
const yMax = computed(() => (typeof props.trend.max === 'number' ? props.trend.max : 100));

const lastValue = computed(() => {
    const points = props.trend.points || [];

    return points.length ? Number(points[points.length - 1].value) : null;
});

const pointCount = computed(() => (props.trend.points || []).length);

function groupChanged(event) {
    emit('select', { group: event.target.value, key: '' });
}

function keyChanged(event) {
    emit('select', { group: props.activeGroup, key: event.target.value });
}
</script>

<template>
    <div data-purpose="support-trend">
        <div class="mb-4 grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div>
                <label class="label" for="trend-group">Kelompok</label>
                <select id="trend-group" class="select" :value="activeGroup" @change="groupChanged">
                    <option v-for="option in groupOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
                </select>
            </div>
            <div>
                <label class="label" for="trend-key">Item</label>
                <select id="trend-key" class="select" :value="activeKey" @change="keyChanged">
                    <option value="">Item bawaan</option>
                    <option v-for="target in targets" :key="target.key" :value="target.key">
                        {{ target.name }}{{ target.unit ? ' (' + target.unit + ')' : '' }}
                    </option>
                </select>
            </div>
        </div>

        <EmptyState
            v-if="pointCount === 0"
            icon="fa-chart-line"
            title="Belum ada data yang cukup untuk menampilkan tren"
            message="Tren digambar dari hasil bertipe angka pada item yang dipilih. Hasil kultur berupa teks tidak bisa diplot."
        />

        <template v-else>
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <p class="text-xs font-bold text-slate-700">
                    {{ trend.label }}
                    <span class="font-normal text-slate-400">({{ trend.unit || 'tanpa satuan' }})</span>
                </p>
                <p class="font-mono text-[10px] text-slate-500">
                    {{ pointCount }} titik &bull; terakhir {{ formatNumber(lastValue, ref.decimals ?? 0) }}
                    <template v-if="!isBlank(ref.reference)"> &bull; rentang {{ ref.reference }}</template>
                </p>
            </div>

            <TrendChart
                :series="series"
                :y-min="yMin"
                :y-max="yMax"
                :thresholds="thresholds"
                :x-labels="xLabels"
                :y-unit="trend.unit || ''"
                :show-legend="false"
                area
                empty-message="Belum ada data tren."
            />

            <div class="mt-3 grid grid-cols-3 gap-2 text-center">
                <div class="rounded-lg border border-slate-200 px-2 py-2">
                    <p class="text-[10px] font-bold uppercase text-slate-400">Minimum</p>
                    <p class="font-mono text-sm font-black text-slate-900">{{ formatNumber(trend.min, ref.decimals ?? 0) }}</p>
                </div>
                <div class="rounded-lg border border-slate-200 px-2 py-2">
                    <p class="text-[10px] font-bold uppercase text-slate-400">Maksimum</p>
                    <p class="font-mono text-sm font-black text-slate-900">{{ formatNumber(trend.max, ref.decimals ?? 0) }}</p>
                </div>
                <div class="rounded-lg border border-slate-200 px-2 py-2">
                    <p class="text-[10px] font-bold uppercase text-slate-400">Terakhir</p>
                    <p class="font-mono text-sm font-black text-slate-900">{{ formatNumber(lastValue, ref.decimals ?? 0) }}</p>
                </div>
            </div>
        </template>
    </div>
</template>
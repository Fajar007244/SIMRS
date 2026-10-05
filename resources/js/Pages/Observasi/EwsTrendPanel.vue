<script setup>
/**
 * EwsTrendPanel.vue - region data-purpose="trend-chart-panel" pada
 * phase1/observasi.html.
 *
 * Prototype memakai satu SVG viewBox "0 0 760 190" berisi tiga polyline
 * (Sistolik / MAP / Nadi) dengan garis target "MAP > 65". SVG mentah itu sudah
 * dipindahkan ke Components/TrendChart.vue, jadi panel ini hanya menyiapkan
 * seri + ambang dan merangkai judul, legenda, dan garis waktu.
 *
 * DUA GRAFIK, karena keduanya ada di panel prototype:
 *
 *  1. Tren SKOR EWS (grafik utama). Serinya dari `ewsTrend.points` yang
 *     sudah LAMA ke BARU, sumbu Y 0 sampai `ewsTrend.max` (18, plafon skala
 *     British EWS 4 tingkat - bukan maksimum data). Garis panduan putus-putus
 *     diambil dari config('ews.escalation') melalui escalationGuides(),
 *     satu per tingkat: low, medium, high, emergency. Skor 0 tetap terbaca
 *     jujur karena sumbu tidak dipotong ke nilai tertinggi data.
 *
 *  2. Tren DINAMIKA HEMODINAMIK (grafik kecil di bawahnya). Systolik, MAP,
 *     dan Nadi diambil dari `flowsheet` (TERBARU DI ATAS) lalu dibalik
 *     dengan slice().reverse() supaya urutannya LAMA ke BARU seperti sumbu
 *     waktu. Sumbu Y memakai rentang data, dan target MAP > 65 digambar
 *     sebagai garis panduan - persis polyline mapLine + "Target MAP >65" di
 *     prototype.
 *
 * PROPS
 *   trend    Object  WAJIB  { points: [...], max, avg }
 *   rows     Array            baris flowsheet (TERBARU DI ATAS)
 *   scale    Object           config('ews'), untuk garis panduan eskalasi
 *
 * EMITS: (tidak ada)
 */
import { computed } from 'vue';
import TrendChart from '@/Components/TrendChart.vue';
import { escalationGuides, maxTotal } from './ewsPreview';
import { EWS_RISK_LABELS } from '@/tone';

const props = defineProps({
    trend: { type: Object, required: true },
    rows: { type: Array, default: () => [] },
    scale: { type: Object, default: () => ({}) },
});

const points = computed(() => (Array.isArray(props.trend?.points) ? props.trend.points : []));

const hasPoints = computed(() => points.value.length > 0);

const ceiling = computed(() => {
    const fromServer = Number(props.trend?.max);
    return Number.isFinite(fromServer) && fromServer > 0 ? fromServer : maxTotal();
});

/** Satu seri: skor EWS per titik, meta = label risiko untuk tooltip. */
const ewsSeries = computed(() => [
    {
        name: 'Skor EWS',
        color: '#dc2626',
        points: points.value.map((point, index) => ({
            x: index,
            y: Number(point.total) || 0,
            label: point.label || '',
            meta: point.riskLabel || EWS_RISK_LABELS[point.risk] || '-',
        })),
    },
]);

const ewsThresholds = computed(() => escalationGuides(props.scale?.escalation));

/**
 * Deret hemodinamik dari yang LAMA ke BARU. `flowsheet` yang dikirim
 * ObservationService::getFlowsheet() berurutan TERBARU DI ATAS, jadi dibalik.
 * Titik yang salah satu vitalnya kosong dibuang supaya polyline tidak
 * melompat ke y=0.
 */
const hemodynamic = computed(() => {
    const source = Array.isArray(props.rows) ? props.rows.slice().reverse() : [];

    return {
        sys: source.map((row, index) => ({ x: index, y: Number(row.sys) })),
        map: source.map((row, index) => ({ x: index, y: Number(row.map) })),
        hr: source.map((row, index) => ({ x: index, y: Number(row.hr) })),
        labels: source.map((row) => row.timeLabel || row.time || ''),
    };
});

const hemoSeries = computed(() => {
    const h = hemodynamic.value;

    return [
        { name: 'Sistolik', color: '#0284c7', points: h.sys.filter((point) => Number.isFinite(point.y)) },
        { name: 'MAP', color: '#10b981', points: h.map.filter((point) => Number.isFinite(point.y)) },
        { name: 'Nadi', color: '#f59e0b', points: h.hr.filter((point) => Number.isFinite(point.y)) },
    ];
});

const hemoHasData = computed(() => hemoSeries.value.some((entry) => entry.points.length > 0));

const hemoDomain = computed(() => {
    const values = hemoSeries.value.flatMap((entry) => entry.points.map((point) => point.y));

    if (values.length === 0) return { min: 0, max: 160 };

    const min = Math.min(...values);
    const max = Math.max(...values);
    const pad = Math.max(10, Math.round((max - min) * 0.2));

    return {
        min: Math.max(0, min - pad),
        max: max + pad,
    };
});

/** Target MAP > 65, sama dengan garis merah "Target MAP >65" di prototype. */
const hemoThresholds = computed(() => {
    if (hemoDomain.value.max <= 65) return [];

    return [{ y: 65, label: 'Target MAP >65', color: '#f87171' }];
});

const averageLabel = computed(() => {
    const avg = Number(props.trend?.avg);
    return Number.isFinite(avg) ? avg.toFixed(1).replace('.', ',') : '0,0';
});
</script>

<template>
    <section
        class="flex flex-col justify-between rounded-xl border border-slate-200 bg-white p-4 shadow-sm lg:col-span-8"
        data-purpose="trend-chart-panel"
    >
        <div class="mb-3 flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 pb-3">
            <div class="min-w-0">
                <h2 class="flex items-center gap-2 text-sm font-bold text-slate-900">
                    <i class="fa-solid fa-chart-area text-sky-600" aria-hidden="true"></i>
                    Tren Skor EWS &amp; Dinamika Hemodinamik
                </h2>
                <p class="text-xs text-slate-500">
                    Skor EWS British 4 tingkat (0-18) per jam observasi, dengan empat garis
                    panduan ambang eskalasi.
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-3 text-xs" data-purpose="ews-trend-legend">
                <span class="inline-flex items-center gap-1.5">
                    <span class="h-1 w-3 rounded bg-red-600"></span>Skor EWS
                </span>
                <span class="inline-flex items-center gap-1.5">
                    <span class="h-1 w-3 rounded bg-sky-600"></span> Sistolik
                </span>
                <span class="inline-flex items-center gap-1.5">
                    <span class="h-1 w-3 rounded bg-emerald-500"></span> MAP
                </span>
                <span class="inline-flex items-center gap-1.5">
                    <span class="h-1 w-3 rounded bg-amber-500"></span> Nadi
                </span>
                <span
                    v-for="guide in ewsThresholds"
                    :key="`leg-${guide.y}`"
                    class="inline-flex items-center gap-1.5"
                >
                    <span class="h-0 w-3 border-t-2 border-dashed" :style="{ borderColor: guide.color }"></span>
                    {{ guide.label }}
                </span>
            </div>
        </div>

        <TrendChart
            :series="ewsSeries"
            :y-max="ceiling"
            :y-min="0"
            :thresholds="ewsThresholds"
            y-unit="poin"
            :height="230"
            :empty-message="hasPoints ? '' : 'Belum ada observasi untuk digambar.'"
        />

        <div class="mt-3 flex flex-wrap items-center gap-3 border-t border-slate-100 pt-2 text-[11px] text-slate-500">
            <span>
                Titik: <strong class="font-mono text-slate-800">{{ points.length }}</strong>
            </span>
            <span>
                Rata-rata:
                <strong class="font-mono text-slate-800">{{ averageLabel }}</strong>
            </span>
            <span>
                Plafon skala:
                <strong class="font-mono text-slate-800">/ {{ ceiling }}</strong>
            </span>
            <span class="ml-auto" data-purpose="ews-trend-note">
                Sumbu Y memakai plafon skor British EWS 4 tingkat, bukan nilai tertinggi data.
            </span>
        </div>

        <!-- Grafik kedua: dinamika hemodinamik (polyline prototype yang kedua) -->
        <div v-if="hemoHasData" class="mt-3 border-t border-slate-100 pt-3" data-purpose="hemodynamic-trend">
            <p class="mb-1.5 flex items-center gap-1.5 text-[11px] font-bold uppercase tracking-wider text-slate-500">
                <i class="fa-solid fa-heart-pulse text-sky-600" aria-hidden="true"></i>
                Pergerakan Sistolik, MAP, dan Nadi
            </p>
            <TrendChart
                :series="hemoSeries"
                :y-max="hemoDomain.max"
                :y-min="hemoDomain.min"
                :thresholds="hemoThresholds"
                :x-labels="hemodynamic.labels"
                y-unit="mmHg"
                :height="150"
                :width="760"
            />
        </div>
    </section>
</template>
<script setup>
/**
 * TrendChart.vue - grafik tren SVG MURNI. Tidak memakai pustaka chart apa pun,
 * supaya tidak ada ketergantungan npm tambahan.
 *
 * Port dari dua grafik phase1 yang keduanya memakai viewBox "0 0 760 190":
 *   observasi.html "trend-chart-panel"  (Sistolik + MAP + Nadi, area + garis
 *                                        target MAP 65, sumbu Y 40/80/120/160)
 *   bundles.html  "bundle-trend-chart"  (VAP/CLABSI/CAUTI 0-100%, garis
 *                                        target 100%)
 *
 * PROPS
 *   series       Array    WAJIB
 *                [{ name, color, points: [{ x, y, label?, meta? }] }]
 *                  x = posisi numerik. Dua cara yang didukung:
 *                    (a) index   0..n-1  (paling umum)
 *                    (b) nilai nyata (epoch ms / angka apa pun) - sumbu X
 *                        dihitung dari min..max semua titik.
 *                  color default = tone untuk seri pertama (lihat tone.js).
 *   yMax         Number   WAJIB  batas atas sumbu Y
 *   yMin         Number   default 0    batas bawah sumbu Y
 *   thresholds   Array    default []   [{ y, label?, color? }] garis target
 *   xLabels      Array    default []   label sumbu X. Bila kosong, diambil
 *                                      dari `label` titik seri pertama.
 *   yUnit        String   default ''    satuan, tampilkan di tooltip
 *   height       Number   default 220   tinggi viewBox
 *   width        Number   default 760   lebar viewBox
 *   area         Boolean  default false isi di bawah garis tiap seri
 *   dashedGuide  Boolean  default true  garis target putus-putus
 *   showLegend   Boolean  default true  legenda di bawah grafik
 *   showGrid     Boolean  default true  garis kisi + label sumbu Y
 *   emptyMessage String   default 'Belum ada data tren.' tampil bila tidak ada
 *                                      satu pun titik
 *
 * SLOTS: (tidak ada)
 * EMITS : (tidak ada)
 *
 * INTERAKSI: hover menampilkan tooltip berisi SEMUA seri pada x terdekat.
 * Aman untuk 0 titik dan 1 titik (satu titik diletakkan di tengah).
 */
import { computed, ref } from 'vue';
import { TONE } from '../tone';
import { formatNumber } from '../composables/useFormatting';

const props = defineProps({
    series: { type: Array, default: () => [] },
    yMax: { type: Number, default: 100 },
    yMin: { type: Number, default: 0 },
    thresholds: { type: Array, default: () => [] },
    xLabels: { type: Array, default: () => [] },
    yUnit: { type: String, default: '' },
    height: { type: Number, default: 220 },
    width: { type: Number, default: 760 },
    area: { type: Boolean, default: false },
    dashedGuide: { type: Boolean, default: true },
    showLegend: { type: Boolean, default: true },
    showGrid: { type: Boolean, default: true },
    emptyMessage: { type: String, default: 'Belum ada data tren.' },
});

const PALETTE = [
    TONE.sky.hex,
    TONE.emerald.hex,
    TONE.amber.hex,
    TONE.purple.hex,
    TONE.red.hex,
    TONE.teal.hex,
    TONE.orange.hex,
    TONE.indigo.hex,
];

/**
 * Metrik font sumbu X. Lebar label harus diukur dari font yang benar-benar
 * dipakai <text> di template, bukan dari jumlah karakter per label hasil
 * tebakan. JetBrains Mono monospace dengan advance ~ 0.6em, jadi pada
 * font-size 9 satu karakter ~ 5.4px. Konstanta font-size yang sama dipakai
 * template supaya ukuran font dan perhitungan lebar tidak bisa melenceng.
 *
 * X_AXIS_DESCENT + 1 menambah ruang di PAD.bottom supaya bagian bawah glif
 * (termasuk `/` yang sedikit turun di bawah garis dasar) tidak keluar dari
 * viewBox. Format label `d/m H:i` memuat `/`, jadi tanpa ruang ini jam pada
 * sumbu X benar-benar terpotong di tepi bawah SVG.
 */
const X_AXIS_FONT_SIZE = 9;
const X_AXIS_ADVANCE = 0.6 * X_AXIS_FONT_SIZE;
const X_AXIS_MIN_GAP = 8;
const X_AXIS_LABEL_DROP = 11;
const X_AXIS_DESCENT = Math.ceil(X_AXIS_FONT_SIZE * 0.3);

const PAD = {
    top: 16,
    right: 12,
    bottom: X_AXIS_LABEL_DROP + X_AXIS_DESCENT + 1,
    left: 44,
};

const svg = ref(null);
const hoverIndex = ref(-1);

/* ----------------------------- normalisasi ----------------------------- */

const prepared = computed(() => {
    const list = Array.isArray(props.series) ? props.series : [];

    return list
        .filter((entry) => entry && Array.isArray(entry.points))
        .map((entry, index) => {
            const points = entry.points
                .filter((point) => point && Number.isFinite(Number(point.x)) && Number.isFinite(Number(point.y)))
                .map((point, pointIndex) => ({
                    x: Number(point.x),
                    y: Number(point.y),
                    label: point.label === null || point.label === undefined ? '' : String(point.label),
                    meta: point.meta === null || point.meta === undefined ? '' : String(point.meta),
                    order: pointIndex,
                }))
                .sort((a, b) => a.x - b.x);

            return {
                name: entry.name === null || entry.name === undefined ? '' : String(entry.name),
                color: entry.color || PALETTE[index % PALETTE.length],
                points,
            };
        });
});

const totalPoints = computed(() => prepared.value.reduce((sum, entry) => sum + entry.points.length, 0));
const isEmpty = computed(() => totalPoints.value === 0);

/* ------------------------------- geometri ------------------------------- */

const vb = computed(() => ({
    w: Math.max(200, Number(props.width) || 760),
    h: Math.max(80, Number(props.height) || 220),
}));

const plot = computed(() => ({
    x: PAD.left,
    y: PAD.top,
    w: Math.max(10, vb.value.w - PAD.left - PAD.right),
    h: Math.max(10, vb.value.h - PAD.top - PAD.bottom),
}));

const yDomain = computed(() => {
    const min = Number.isFinite(Number(props.yMin)) ? Number(props.yMin) : 0;
    const maxRaw = Number(props.yMax);
    const max = Number.isFinite(maxRaw) ? maxRaw : min + 1;
    return max > min ? { min, max } : { min, max: min + 1 };
});

/** Domain X: index 0..n-1, atau min..max bila titik punya nilai nyata. */
const xDomain = computed(() => {
    const all = prepared.value.flatMap((entry) => entry.points.map((point) => point.x));

    if (all.length === 0) return { min: 0, max: 0, single: true };

    const min = Math.min(...all);
    const max = Math.max(...all);

    if (min === max) return { min, max: min + 1, single: true };

    return { min, max, single: false };
});

/** Jumlah titik unik; dipakai untuk mengipiskan tick sumbu X. */
const uniqueX = computed(() => {
    const seen = new Set();
    prepared.value.forEach((entry) => entry.points.forEach((point) => seen.add(point.x)));
    return Array.from(seen).sort((a, b) => a - b);
});

function xAt(value) {
    const { x, w } = plot.value;
    if (xDomain.value.single) return x + w / 2;
    return x + ((value - xDomain.value.min) / (xDomain.value.max - xDomain.value.min)) * w;
}

function yAt(value) {
    const { y, h } = plot.value;
    const { min, max } = yDomain.value;
    const clamped = Math.min(max, Math.max(min, Number(value) || 0));
    return y + h - ((clamped - min) / (max - min)) * h;
}

/* ------------------------------ grid & ticks ---------------------------- */

const TICKS = [1, 2, 5, 10, 20, 25, 50, 100, 200, 250, 500, 1000, 2000, 5000];

const yTicks = computed(() => {
    if (!props.showGrid) return [];

    const { min, max } = yDomain.value;
    const span = max - min;
    const targetIntervals = 4;
    const rawStep = span / targetIntervals;
    const magnitude = Math.pow(10, Math.floor(Math.log10(rawStep)));
    let step = TICKS.find((candidate) => candidate * magnitude >= rawStep) * magnitude;

    if (!Number.isFinite(step) || step <= 0) step = span / targetIntervals;

    const ticks = [];
    for (let value = min; value <= max + step / 2; value += step) {
        ticks.push({ value: Math.round(value * 1000) / 1000, y: yAt(value) });
    }

    return ticks;
});

/** Garis dasar teks sumbu X, diukur dari garis sumbu bukan dari viewBox. */
const xAxisBaseline = computed(() => plot.value.y + plot.value.h + X_AXIS_LABEL_DROP);

/** Lebar piksel satu label sumbu X, dari jumlah karakternya x advance font. */
function labelPixelWidth(label) {
    return String(label).length * X_AXIS_ADVANCE;
}

/**
 * Satu slot label sumbu X: posisi, teks, dan lebar pikselnya, disusun sejajar
 * dengan titik data. Bila `xLabels` diberikan, teks diambil dari situ; kalau
 * tidak, dari `label` titik seri pertama pada indeks itu.
 */
const xTickSlots = computed(() => {
    const explicit = Array.isArray(props.xLabels) ? props.xLabels.map((label) => String(label)) : [];
    const xs = uniqueX.value;
    const points = prepared.value.flatMap((entry) => entry.points);
    const count = explicit.length ? explicit.length : xs.length;

    return Array.from({ length: count }, (_, index) => {
        const value = xs[index];
        const point = value === undefined ? null : points.find((candidate) => candidate.x === value);
        const label = explicit.length
            ? explicit[index]
            : (point && point.label ? point.label : String(index + 1));

        return {
            index,
            label,
            x: xAt(value === undefined ? index : value),
            width: labelPixelWidth(label),
        };
    });
});

/**
 * Label sumbu X, ditipiskan dengan MENGUKUR jarak antar label, bukan dengan
 * menebak jumlah karakter per label. Format label aplikasi ini `d/m H:i`
 * (11 karakter, bukan 7), jadi anggaran karakter hasil tebakan membuat
 * maxLabels terlalu besar, stepIndex terlalu kecil, dan label bersebelahan
 * saling menimpa sampai teks jamnya tidak terbaca.
 *
 * Penipisan berjalan dari kiri: sebuah label ditahan hanya bila jarak TAMPA
 * jaraknya ke label yang sudah ditahan minimal X_AXIS_MIN_GAP. Lebar yang
 * dipakai adalah lebar label itu sendiri, jadi label pendek (`08:00`) tidak
 * diperlakukan sama seperti label panjang (`06/09 08:00`).
 *
 * Label terakhir tetap selalu ditampilkan. Kalau jaraknya jadi terlalu dekat
 * ke label sebelumnya, label sebelumnya justru yang dilepas, sehingga label
 * terakhir tidak pernah bertabrakan.
 */
const xTicks = computed(() => {
    const slots = xTickSlots.value;

    if (!slots.length) return [];

    const last = slots.length - 1;

    /** Satu label sendirian diletakkan di tengah plot, jadi cukup di tengah. */
    const anchorOf = (index) => (slots.length === 1 ? 'middle' : (index === 0 ? 'start' : (index === last ? 'end' : 'middle')));
    const inkLeft = (slot) => slot.x - (anchorOf(slot.index) === 'end' ? slot.width : (anchorOf(slot.index) === 'start' ? 0 : slot.width / 2));
    const inkRight = (slot) => slot.x + (anchorOf(slot.index) === 'start' ? slot.width : (anchorOf(slot.index) === 'end' ? 0 : slot.width / 2));
    const apart = (a, b) => inkLeft(b) - inkRight(a);

    const kept = [];

    slots.forEach((slot) => {
        if (!kept.length || apart(kept[kept.length - 1], slot) >= X_AXIS_MIN_GAP) kept.push(slot);
    });

    if (kept[kept.length - 1] !== slots[last]) {
        while (kept.length > 1 && apart(kept[kept.length - 1], slots[last]) < X_AXIS_MIN_GAP) kept.pop();

        kept.push(slots[last]);
    }

    return kept.map((slot) => ({
        label: slot.label,
        x: slot.x,
        anchor: anchorOf(slot.index),
        width: slot.width,
    }));
});

/* -------------------------------- seri --------------------------------- */

const lines = computed(() =>
    prepared.value.map((entry, seriesIndex) => ({
        ...entry,
        seriesIndex,
        path: entry.points.map((point) => `${xAt(point.x).toFixed(1)},${yAt(point.y).toFixed(1)}`).join(' '),
        areaPath: entry.points.length
            ? `${xAt(entry.points[0].x).toFixed(1)},${yAt(yDomain.value.min).toFixed(1)} `
                + entry.points.map((point) => `${xAt(point.x).toFixed(1)},${yAt(point.y).toFixed(1)}`).join(' ')
                + ` ${xAt(entry.points[entry.points.length - 1].x).toFixed(1)},${yAt(yDomain.value.min).toFixed(1)}`
            : '',
        last: entry.points.length ? entry.points[entry.points.length - 1] : null,
    })),
);

const guides = computed(() =>
    (Array.isArray(props.thresholds) ? props.thresholds : [])
        .filter((guide) => guide && Number.isFinite(Number(guide.y)))
        .map((guide) => ({
            y: yAt(Number(guide.y)),
            raw: Number(guide.y),
            label: guide.label ? String(guide.label) : '',
            color: guide.color || '#f87171',
        }))
        .filter((guide) => guide.y >= plot.value.y - 1 && guide.y <= plot.value.y + plot.value.h + 1),
);

/* -------------------------------- tooltip ------------------------------- */

const hovered = computed(() => {
    if (hoverIndex.value < 0 || !uniqueX.value.length) return null;

    const xValue = uniqueX.value[hoverIndex.value];
    if (xValue === undefined) return null;

    const values = prepared.value.map((entry) => {
        const point = entry.points.find((candidate) => candidate.x === xValue) || null;
        return {
            name: entry.name,
            color: entry.color,
            y: point ? point.y : null,
            label: point ? point.label : '',
            meta: point ? point.meta : '',
        };
    });

    return {
        xValue,
        x: xAt(xValue),
        title: values.find((value) => value.label)?.label || String(xValue),
        values,
    };
});

/** Tooltip digeser agar tidak keluar panel. */
const tooltipStyle = computed(() => {
    if (!hovered.value) return { display: 'none' };

    const ratio = (hovered.value.x - plot.value.x) / plot.value.w;
    const shift = ratio > 0.62 ? '-100%' : (ratio < 0.28 ? '0%' : '-50%');
    const margin = ratio > 0.62 ? -10 : (ratio < 0.28 ? 10 : 0);

    return {
        left: `${((hovered.value.x / vb.value.w) * 100).toFixed(2)}%`,
        transform: `translateX(${shift})`,
        marginLeft: `${margin}px`,
    };
});

function displayValue(value) {
    if (value === null || value === undefined) return '-';
    const formatted = formatNumber(value, Number.isInteger(Number(value)) ? 0 : 1);
    return props.yUnit && formatted !== '-' ? `${formatted} ${props.yUnit}` : formatted;
}

/* ------------------------------- events --------------------------------- */

/** Ubah koordinat mouse (clientX) ke koordinat viewBox. */
function clientToViewX(clientX) {
    const node = svg.value;
    if (!node) return null;

    const rect = node.getBoundingClientRect();
    if (!rect.width || !rect.height) return null;

    const scale = Math.min(rect.width / vb.value.w, rect.height / vb.value.h);
    const offsetX = (rect.width - vb.value.w * scale) / 2;
    const offsetY = (rect.height - vb.value.h * scale) / 2;
    const viewX = (clientX - rect.left - offsetX) / scale;

    return Number.isFinite(viewX) ? viewX : null;
}

function onMove(event) {
    const viewX = clientToViewX(event.clientX);
    if (viewX === null) return;

    let nearest = -1;
    let bestDistance = Infinity;

    uniqueX.value.forEach((value, index) => {
        const distance = Math.abs(xAt(value) - viewX);
        if (distance < bestDistance) {
            bestDistance = distance;
            nearest = index;
        }
    });

    hoverIndex.value = nearest;
}

function onLeave() {
    hoverIndex.value = -1;
}

defineExpose({ hoverIndex, uniqueX });
</script>

<template>
    <div class="relative w-full" data-purpose="trend-chart">
        <p
            v-if="isEmpty"
            class="flex min-h-[8rem] items-center justify-center px-6 text-center text-sm text-slate-500"
        >
            {{ props.emptyMessage }}
        </p>

        <svg
            v-else
            ref="svg"
            class="block w-full"
            :viewBox="`0 0 ${vb.w} ${vb.h}`"
            preserveAspectRatio="xMidYMid meet"
            :style="{ height: 'auto' }"
            role="img"
            aria-label="Grafik tren"
            @mousemove="onMove"
            @mouseleave="onLeave"
        >
            <!-- Kisi horizontal + label sumbu Y -->
            <g v-if="props.showGrid">
                <g v-for="tick in yTicks" :key="`y-${tick.value}`">
                    <line
                        :x1="plot.x"
                        :x2="plot.x + plot.w"
                        :y1="tick.y"
                        :y2="tick.y"
                        stroke="#f1f5f9"
                        stroke-width="1"
                    />
                    <text
                        :x="plot.x - 8"
                        :y="tick.y + 3"
                        text-anchor="end"
                        fill="#94a3b8"
                        font-family="JetBrains Mono, monospace"
                        font-size="9"
                    >{{ tick.value }}</text>
                </g>
            </g>

            <!-- Garis panduan target -->
            <g v-if="props.dashedGuide">
                <g v-for="(guide, index) in guides" :key="`g-${index}`">
                    <line
                        :x1="plot.x"
                        :x2="plot.x + plot.w"
                        :y1="guide.y"
                        :y2="guide.y"
                        :stroke="guide.color"
                        :stroke-dasharray="props.dashedGuide ? '4,4' : 'none'"
                        stroke-width="1.2"
                    />
                    <text
                        v-if="guide.label"
                        :x="plot.x + plot.w"
                        :y="guide.y - 4"
                        text-anchor="end"
                        :fill="guide.color"
                        font-size="9"
                        font-weight="bold"
                    >{{ guide.label }}</text>
                </g>
            </g>

            <!-- Area + garis tiap seri -->
            <g v-for="line in lines" :key="`s-${line.seriesIndex}`">
                <polygon
                    v-if="props.area && line.areaPath"
                    :points="line.areaPath"
                    :fill="line.color"
                    fill-opacity="0.14"
                />
                <polyline
                    v-if="line.points.length"
                    :points="line.path"
                    fill="none"
                    :stroke="line.color"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="2"
                />
                <circle
                    v-if="line.last"
                    :cx="xAt(line.last.x)"
                    :cy="yAt(line.last.y)"
                    :fill="line.color"
                    r="4"
                />
            </g>

            <!-- Sumbu X -->
            <line
                :x1="plot.x"
                :x2="plot.x + plot.w"
                :y1="plot.y + plot.h"
                :y2="plot.y + plot.h"
                stroke="#e2e8f0"
                stroke-width="1"
            />
            <g v-for="(tick, index) in xTicks" :key="`x-${index}`">
                <text
                    :x="tick.x"
                    :y="xAxisBaseline"
                    :text-anchor="tick.anchor"
                    fill="#94a3b8"
                    font-family="JetBrains Mono, monospace"
                    :font-size="X_AXIS_FONT_SIZE"
                >{{ tick.label }}</text>
            </g>

            <!-- Penanda hover -->
            <line
                v-if="hovered"
                :x1="hovered.x"
                :x2="hovered.x"
                :y1="plot.y"
                :y2="plot.y + plot.h"
                stroke="#94a3b8"
                stroke-width="1"
                stroke-dasharray="3,3"
            />
            <circle
                v-for="(value, index) in (hovered ? hovered.values : [])"
                v-show="value.y !== null"
                :key="`hv-${index}`"
                :cx="hovered ? hovered.x : 0"
                :cy="value.y === null ? 0 : yAt(value.y)"
                :fill="value.color"
                r="3.5"
                stroke="#ffffff"
                stroke-width="1"
            />
        </svg>

        <!-- Tooltip -->
        <div
            v-if="hovered && !isEmpty"
            class="pointer-events-none absolute top-2 z-10 min-w-[9rem] rounded-lg border border-slate-200 bg-white/95 px-2.5 py-2 text-[11px] shadow-lg backdrop-blur"
            :style="tooltipStyle"
            data-purpose="trend-tooltip"
        >
            <p class="mb-1 font-mono text-[10px] font-bold text-slate-500">{{ hovered.title }}</p>
            <p v-for="(value, index) in hovered.values" :key="`tt-${index}`" class="flex items-center gap-1.5">
                <span class="h-2 w-2 shrink-0 rounded-full" :style="{ backgroundColor: value.color }"></span>
                <span class="min-w-0 flex-1 truncate text-slate-600">{{ value.name || '-' }}</span>
                <span class="shrink-0 font-mono font-bold text-slate-900">{{ displayValue(value.y) }}</span>
            </p>
            <p v-if="hovered.values.some((value) => value.meta)" class="mt-1 border-t border-slate-100 pt-1 text-[10px] text-slate-500">
                {{ hovered.values.find((value) => value.meta).meta }}
            </p>
        </div>

        <!-- Legenda -->
        <div
            v-if="props.showLegend && lines.length > 1"
            class="mt-2 flex flex-wrap items-center gap-3 border-t border-slate-100 pt-2 text-xs text-slate-600"
        >
            <span v-for="line in lines" :key="`lg-${line.seriesIndex}`" class="inline-flex items-center gap-1.5">
                <span class="h-1 w-3 rounded" :style="{ backgroundColor: line.color }"></span>
                {{ line.name }}
            </span>
            <span
                v-for="(guide, index) in guides"
                :key="`lgg-${index}`"
                class="inline-flex items-center gap-1.5"
            >
                <span class="h-0 w-3 border-t-2 border-dashed" :style="{ borderColor: guide.color }"></span>
                {{ guide.label }}
            </span>
        </div>
    </div>
</template>

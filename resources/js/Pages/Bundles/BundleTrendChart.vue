<script setup>
/**
 * BundleTrendChart.vue - tren kepatuhan bundle per observasi.
 *
 * Port 1:1 dari blok data-purpose="bundle-trend-chart" dan renderTrend() di
 * phase1/bundles.html: tiga garis (VAP, CLABSI, CAUTI) pada sumbu 0-100%
 * dengan garis target putus-putus di 100%, legenda warna di kanan header.
 * Grafik digambar TrendChart.vue (SVG murni, tanpa pustaka chart).
 *
 * URUTAN SUMBU X: BundleService::getHistory() mengembalikan baris TERBARU DI
 * ATAS (lihat docblock service), jadi indeks 0 adalah observasi terbaru.
 * Array `history` sendiri TIDAK dibalik di sini - urutannya dipakai apa
 * adanya. Yang dipetakan hanyalah koordinat X: indeks 0 dapat x terbesar
 * sehingga observasi terbaru berada di KANAN dan garis dibaca dari lama ke
 * baru, sama seperti renderTrend() prototype yang memakai
 * records.slice(0, 7).slice().reverse(). Label sumbu X disusun dengan
 * urutan yang sama. Tabel riwayat (BundleHistoryTable) tetap memakai urutan
 * asli: terbaru di atas.
 *
 * PROPS
 *   history  Array  default []  getHistory($enc, 7)
 *
 * SLOTS: (tidak ada)
 * EMITS : (tidak ada)
 */
import { computed } from 'vue';
import SectionCard from '@/Components/SectionCard.vue';
import TrendChart from '@/Components/TrendChart.vue';
import { BUNDLE_TONE } from '@/tone';

const props = defineProps({
    history: { type: Array, default: () => [] },
});

/**
 * Baris dengan koordinat sumbu X. Indeks 0 (terbaru) dipetakan ke x
 * terbesar, lalu diurutkan naik supaya kiri ke kanan = lama ke baru.
 */
const plotted = computed(() => {
    const rows = Array.isArray(props.history) ? props.history : [];
    const last = rows.length - 1;

    return rows
        .map((row, index) => ({ row, x: last - index }))
        .sort((a, b) => a.x - b.x);
});

function pointsFor(key) {
    return plotted.value.map((entry) => ({
        x: entry.x,
        y: Number(entry.row?.[key]) || 0,
        label: entry.row?.label ?? '',
    }));
}

const series = computed(() => [
    { name: 'VAP', color: BUNDLE_TONE.vap.line, points: pointsFor('vap') },
    { name: 'CLABSI', color: BUNDLE_TONE.clabsi.line, points: pointsFor('clabsi') },
    { name: 'CAUTI', color: BUNDLE_TONE.cauti.line, points: pointsFor('cauti') },
]);

const xLabels = computed(() => plotted.value.map((entry) => entry.row?.label ?? ''));

const thresholds = [{ y: 100, label: 'Target 100%', color: '#f87171' }];
</script>

<template>
    <SectionCard
        title="Tren Kepatuhan Bundle HAIs per Pengisian Observasi"
        subtitle="Perbandingan persentase kepatuhan VAP, CLABSI, dan CAUTI pada 7 observasi terakhir"
        tone="teal"
    >
        <template #actions>
            <div class="flex flex-wrap items-center gap-3 text-xs" data-purpose="bundle-trend-legend">
                <span v-for="group in ['vap', 'clabsi', 'cauti']" :key="group" class="inline-flex items-center gap-1.5">
                    <span class="h-1 w-3 rounded" :style="{ backgroundColor: BUNDLE_TONE[group].line }"></span>
                    {{ BUNDLE_TONE[group].short }}
                </span>
                <span class="inline-flex items-center gap-1.5">
                    <span class="h-0 w-3 border-t-2 border-dashed border-red-400"></span>
                    Target 100%
                </span>
            </div>
        </template>

        <TrendChart
            :series="series"
            :x-labels="xLabels"
            :thresholds="thresholds"
            :y-max="100"
            :y-min="0"
            :height="190"
            y-unit="%"
            :show-legend="false"
            :dashed-guide="true"
            empty-message="Belum ada tren kepatuhan bundle. Data muncul setelah observasi pertama disimpan."
        />
    </SectionCard>
</template>

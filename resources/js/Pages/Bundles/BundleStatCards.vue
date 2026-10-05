<script setup>
/**
 * BundleStatCards.vue - enam kartu statistik bundle HAIs.
 *
 * Port 1:1 dari blok data-purpose="bundle-stats" di phase1/bundles.html:
 * Kepatuhan Keseluruhan, Kepatuhan Terbaru, Item Terilai, Item Terpenuhi,
 * Bundle Terlemah, Item Bermasalah. Ikon dan warna garis kiri sama dengan
 * prototype.
 *
 * CATATAN KONTRAK: phase1 punya dua angka berbeda - overallPercent (rata-rata
 * seluruh observasi) dan latestPercent (observasi terakhir). BundleService
 * hanya mengembalikan satu angka, overall, yang berisi hasil observasi
 * TERAKHIR yang punya jawaban bundle. Supaya tidak menampilkan dua kartu
 * dengan angka yang identik, kartu kedua diisi "Observasi Terilai" (jumlah
 * observasi yang punya penilaian) dan kartu keempat "Item Terpenuhi", satu
 * pun tidak dihitung ulang dari data yang tidak ada.
 *
 * Bundle Terlemah diturunkan dari summary.groups: grup dengan persen
 * terendah di antara grup yang punya jawaban. Itu padanan summary.weakest
 * prototype.
 *
 * PROPS
 *   summary           Object  default {}  getSummary()
 *   history           Array   default []  getHistory()
 *   gaps              Array   default []  getGaps()
 *
 * SLOTS: (tidak ada)
 * EMITS : (tidak ada)
 */
import { computed } from 'vue';
import StatCard from '@/Components/StatCard.vue';
import { bundleTone } from '@/tone';
import { isBlank } from '@/composables/useFormatting';

const props = defineProps({
    summary: { type: Object, default: () => ({}) },
    history: { type: Array, default: () => [] },
    gaps: { type: Array, default: () => [] },
});

const s = computed(() => (props.summary && typeof props.summary === 'object' ? props.summary : {}));
const o = computed(() => (s.value.overall && typeof s.value.overall === 'object' ? s.value.overall : {}));

const historyRows = computed(() => (Array.isArray(props.history) ? props.history : []));
const gapRows = computed(() => (Array.isArray(props.gaps) ? props.gaps : []));

/** Grup dengan persen terendah di antara grup yang benar-benar dijawab. */
const weakest = computed(() => {
    const groups = s.value.groups;

    if (!groups || typeof groups !== 'object') return null;

    const answered = Object.values(groups).filter((group) => Number(group?.answered) > 0);

    if (answered.length === 0) return null;

    return answered.reduce((worst, group) =>
        Number(group.percent) < Number(worst.percent) ? group : worst);
});

const cards = computed(() => {
    const overall = o.value;
    const percent = Number(overall.percent) || 0;
    const answered = Number(overall.answered) || 0;
    const itemCount = Number(overall.itemCount) || 0;

    return [
        {
            key: 'overall',
            label: 'Kepatuhan Keseluruhan',
            value: `${percent}%`,
            sub: `${answered} dari ${itemCount} butir dinilai`,
            detail: 'Seluruh 13 item bundle VAP, CLABSI, dan CAUTI pada observasi terakhir yang dinilai',
            icon: 'fa-chart-pie',
            tone: 'teal',
        },
        {
            key: 'answered',
            label: 'Item Terilai',
            value: answered,
            sub: `dari ${itemCount} butir bundle`,
            icon: 'fa-list-check',
            tone: 'sky',
        },
        {
            key: 'compliant',
            label: 'Item Terpenuhi',
            value: Number(overall.compliant) || 0,
            sub: 'dijawab "Ya" pada formulir',
            icon: 'fa-circle-check',
            tone: 'amber',
        },
        {
            key: 'observations',
            label: 'Observasi Terilai',
            value: historyRows.value.length,
            sub: isBlank(s.value.latestAtLabel)
                ? 'observasi terakhir'
                : `terakhir ${s.value.latestAtLabel}`,
            icon: 'fa-clipboard-check',
            tone: 'emerald',
        },
        {
            key: 'weakest',
            label: 'Bundle Terlemah',
            value: weakest.value
                ? `${bundleTone(weakest.value.group).short} ${Math.round(Number(weakest.value.percent) || 0)}%`
                : '-',
            sub: weakest.value ? 'paling sering tidak terpenuhi' : 'belum ada data bundle',
            detail: weakest.value ? weakest.value.label : '',
            icon: 'fa-triangle-exclamation',
            tone: 'red',
        },
        {
            key: 'gaps',
            label: 'Item Bermasalah',
            value: gapRows.value.length,
            sub: 'perlu intervensi / tinjauan',
            icon: 'fa-flag',
            tone: 'purple',
        },
    ];
});


</script>

<template>
    <section class="grid grid-cols-2 gap-3 md:grid-cols-3 lg:grid-cols-6" data-purpose="bundle-stats">
        <StatCard
            v-for="card in cards"
            :key="card.key"
            :label="card.label"
            :value="card.value"
            :sub="card.sub"
            :detail="card.detail"
            :icon="card.icon"
            :tone="card.tone"
            mono
        />
    </section>
</template>

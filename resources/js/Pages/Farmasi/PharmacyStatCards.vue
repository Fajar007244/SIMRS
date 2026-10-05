<script setup>
/**
 * PharmacyStatCards.vue - enam kartu statistik farmasi.
 *
 * Port 1:1 dari blok data-purpose="pharmacy-stats" di phase1/farmasi.html:
 *   Total Entri / Obat Unik / Total Volume / Drip Aktif / Kategori /
 *   Pemberian Terakhir. Urutan, ikon, warna garis kiri, dan teks kecilnya
 *   sama persis dengan prototype.
 *
 * Sumber angka: MedicationRecapService::getSummary() (dipakai juga oleh
 * renderStats() prototype). Tidak ada nilai yang dihitung ulang di sini.
 *
 * PROPS
 *   summary  Object  default {}   getSummary()
 *
 * SLOTS: (tidak ada)
 * EMITS : (tidak ada)
 */
import { computed } from 'vue';
import StatCard from '@/Components/StatCard.vue';
import { formatNumber, isBlank } from '@/composables/useFormatting';

const props = defineProps({
    summary: { type: Object, default: () => ({}) },
});

const s = computed(() => (props.summary && typeof props.summary === 'object' ? props.summary : {}));

const cards = computed(() => {
    const summary = s.value;

    return [
        {
            key: 'total',
            label: 'Total Entri',
            value: formatNumber(summary.total ?? 0),
            sub: 'baris pemberian obat/cairan',
            icon: 'fa-list-check',
            tone: 'sky',
        },
        {
            key: 'uniqueDrugs',
            label: 'Obat Unik',
            value: formatNumber(summary.uniqueDrugs ?? 0),
            sub: 'jenis obat/cairan tercatat',
            icon: 'fa-capsules',
            tone: 'amber',
        },
        {
            key: 'totalVolume',
            label: 'Total Volume',
            value: formatNumber(summary.totalVolume ?? 0),
            sub: 'mL (total)',
            icon: 'fa-droplet',
            tone: 'emerald',
        },
        {
            key: 'activeDrips',
            label: 'Drip Aktif',
            value: formatNumber(summary.activeDrips ?? 0),
            sub: 'drip / obat berjalan',
            icon: 'fa-syringe',
            tone: 'purple',
        },
        {
            key: 'categoryCount',
            label: 'Kategori',
            value: formatNumber(summary.categoryCount ?? 0),
            sub: 'kategori obat terealisasi',
            icon: 'fa-layer-group',
            tone: 'orange',
        },
        {
            key: 'lastGiven',
            label: 'Pemberian Terakhir',
            value: isBlank(summary.lastGivenAtLabel) ? '-' : summary.lastGivenAtLabel,
            sub: 'waktu registrasi terakhir',
            detail: isBlank(summary.lastGivenAt) ? '' : String(summary.lastGivenAt),
            icon: 'fa-clock',
            tone: 'teal',
            by: isBlank(summary.lastGivenBy) ? '' : `oleh ${summary.lastGivenBy}`,
        },
    ];
});
</script>

<template>
    <section class="grid grid-cols-2 gap-3 md:grid-cols-3 lg:grid-cols-6" data-purpose="pharmacy-stats">
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
        >
            <template v-if="card.by" #footer>
                <span class="ml-1">{{ card.by }}</span>
            </template>
        </StatCard>
    </section>
</template>

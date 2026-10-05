<script setup>
/**
 * SupportStatCards.vue - enam kartu statistik area penunjang.
 *
 * Port 1:1 dari blok data-purpose="support-stats" di phase1/penunjang.html.
 * Nilai diambil dari SupportService::getSummary() ( SupportStatCards ini juga
 * sumber angka yang sama dengan yang dipakai renderStats() prototype).
 */
import { computed } from 'vue';
import StatCard from '@/Components/StatCard.vue';
import { isBlank } from '@/composables/useFormatting';

const props = defineProps({
    summary: { type: Object, default: () => ({}) },
    data: { type: Object, default: () => ({}) },
});

const cards = computed(() => {
    const s = props.summary && typeof props.summary === 'object' ? props.summary : {};
    const d = props.data && typeof props.data === 'object' ? props.data : {};

    const abgPh = isBlank(s.abgPh) ? '-' : s.abgPh;
    const abgSub = isBlank(s.abgAtLabel) ? 'belum ada AGD' : 'pH terakhir ' + s.abgAtLabel;
    const microCount = Array.isArray(d.micro) ? d.micro.length : 0;
    const radCount = isBlank(s.radiologyCount) ? microCount : s.radiologyCount;

    return [
        { key: 'labCount', label: 'Hasil Lab', value: s.labCount ?? 0, sub: 'item hasil tercatat', icon: 'fa-flask', tone: 'sky' },
        { key: 'abnormalCount', label: 'Di Luar Rentang', value: s.abnormalCount ?? 0, sub: 'perlu ditinjau', icon: 'fa-arrow-trend-up', tone: 'red' },
        { key: 'culturePending', label: 'Kultur Pending', value: s.culturePending ?? 0, sub: 'menunggu hasil', icon: 'fa-hourglass-half', tone: 'amber' },
        { key: 'abgPh', label: 'AGD Terakhir', value: abgPh, sub: abgSub, icon: 'fa-lungs', tone: 'teal' },
        { key: 'radiologyCount', label: 'Radiologi', value: radCount, sub: 'pemeriksaan gambar', icon: 'fa-x-ray', tone: 'purple' },
        { key: 'los', label: 'Hari Perawatan', value: s.los ?? 0, sub: 'sejak tanggal masuk', icon: 'fa-calendar-day', tone: 'emerald' },
    ];
});
</script>

<template>
    <section class="grid grid-cols-2 gap-3 md:grid-cols-3 lg:grid-cols-6" data-purpose="support-stats">
        <StatCard
            v-for="card in cards"
            :key="card.key"
            :label="card.label"
            :value="card.value"
            :sub="card.sub"
            :icon="card.icon"
            :tone="card.tone"
            mono
        />
    </section>
</template>
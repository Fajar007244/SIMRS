<script setup>
/**
 * QuickWidgets.vue - kolom kanan grid utama farmasi.
 *
 * Port 1:1 dari blok data-purpose="quick-widgets" di phase1/farmasi.html:
 * dua kartu bertumpuk - "Drip Obat & Cairan Aktif" lalu "Rekapitulasi Cairan
 * 24 Jam". Dipisah jadi dua komponen (ActiveDripList + FluidBalanceCard)
 * supaya tiap kartu punya satu sumber data dan bisa diuji terpisah.
 *
 * PROPS
 *   drips               Array   default []  getActiveDrips()
 *   fluidBalance        Object  default {}  getFluidBalance($enc, 24)
 *   latestObservationAt String  default ''  label observasi terakhir (banner)
 *
 * SLOTS: (tidak ada)
 * EMITS : (tidak ada)
 */
import ActiveDripList from './ActiveDripList.vue';
import FluidBalanceCard from './FluidBalanceCard.vue';

defineProps({
    drips: { type: Array, default: () => [] },
    fluidBalance: { type: Object, default: () => ({}) },
    latestObservationAt: { type: String, default: '' },
});
</script>

<template>
    <div class="space-y-4" data-purpose="quick-widgets">
        <ActiveDripList :drips="drips" />
        <FluidBalanceCard :fluid-balance="fluidBalance" :latest-observation-at="latestObservationAt" />
    </div>
</template>

<script setup>
/**
 * PendingPanel.vue - kolom kanan: hasil yang masih menunggu.
 *
 * Port 1:1 dari data-purpose="pending-panel" + renderPending() phase1
 * (kultur mikrobiologi berstatus pending, beserta lama tunggunya dalam hari).
 * Sumber data: SupportService::getPending().
 */
import { DASH } from './abg';

defineProps({
    rows: { type: Array, default: () => [] },
});
</script>

<template>
    <section class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm" data-purpose="pending-panel">
        <div class="flex items-center border-b border-amber-200 bg-amber-50 p-3">
            <h2 class="text-xs font-black uppercase tracking-wide text-amber-800">
                <i class="fa-solid fa-hourglass-half mr-1.5" aria-hidden="true"></i>Menunggu Hasil
            </h2>
        </div>
        <div class="space-y-2 p-3">
            <p v-if="rows.length === 0" class="text-xs text-slate-500">Tidak ada pemeriksaan yang menunggu hasil.</p>

            <div
                v-for="row in rows"
                :key="row.id"
                class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2"
                data-purpose="pending-row"
            >
                <div class="flex items-center justify-between gap-2">
                    <p class="text-xs font-bold text-amber-900">{{ row.label || DASH }}</p>
                    <span class="text-[10px] font-bold text-amber-700">
                        {{ row.daysWaiting === null ? DASH : row.daysWaiting + ' hari' }}
                    </span>
                </div>
                <p class="mt-0.5 text-[10px] text-amber-700">
                    Diminta {{ row.atLabel || DASH }}<template v-if="row.by && row.by !== DASH"> &bull; {{ row.by }}</template>
                </p>
            </div>
        </div>
    </section>
</template>
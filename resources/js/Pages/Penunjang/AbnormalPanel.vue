<script setup>
/**
 * AbnormalPanel.vue - kolom kanan: hasil di luar rentang referensi.
 *
 * Port 1:1 dari data-purpose="abnormal-panel" + renderAbnormal() phase1:
 * hasil terbaru per nama item (hasil baru menimpa yang lama), kritis
 * didahulukan, maksimal 8 baris. Dedup dan pengurutan kritis sudah dikerjakan
 * SupportService::getAbnormal(), halaman ini hanya merender.
 */
import { FLAG_STYLES } from './abg';
import { formatNumber, isBlank } from '@/composables/useFormatting';

const props = defineProps({
    rows: { type: Array, default: () => [] },
    limit: { type: Number, default: 8 },
});

const visible = () => props.rows.slice(0, props.limit);
</script>

<template>
    <section class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm" data-purpose="abnormal-panel">
        <div class="flex items-center border-b border-red-200 bg-red-50 p-3">
            <h2 class="text-xs font-black uppercase tracking-wide text-red-800">
                <i class="fa-solid fa-triangle-exclamation mr-1.5" aria-hidden="true"></i>Hasil Perlu Tinjauan
            </h2>
        </div>
        <div class="space-y-2 p-3">
            <p v-if="rows.length === 0" class="text-xs text-slate-500">Semua hasil dalam rentang referensi.</p>

            <div
                v-for="row in visible()"
                :key="row.key"
                class="rounded-lg border px-3 py-2"
                :class="row.flag === 'critical' ? 'border-red-300 bg-red-50' : 'border-amber-200 bg-amber-50'"
                data-purpose="abnormal-row"
            >
                <div class="flex items-center justify-between gap-2">
                    <p class="text-xs font-bold" :class="row.flag === 'critical' ? 'text-red-900' : 'text-amber-900'">
                        {{ row.label }}
                    </p>
                    <span class="inline-block rounded border px-1.5 py-0.5 text-[10px] font-bold" :class="FLAG_STYLES[row.flag] || FLAG_STYLES.none">
                        {{ row.flagLabel }}
                    </span>
                </div>
                <p class="mt-0.5 font-mono text-[11px]" :class="row.flag === 'critical' ? 'text-red-800' : 'text-amber-800'">
                    {{ row.numericValue === null ? row.value : formatNumber(Number(row.numericValue), 2) }}
                    <template v-if="!isBlank(row.unit)">{{ row.unit }}</template>
                    <span v-if="!isBlank(row.reference)" class="font-normal text-slate-400">(rentang {{ row.reference }})</span>
                </p>
            </div>

            <p v-if="rows.length > limit" class="text-[10px] text-slate-500">
                {{ rows.length - limit }} hasil lain tidak ditampilkan.
            </p>
        </div>
    </section>
</template>
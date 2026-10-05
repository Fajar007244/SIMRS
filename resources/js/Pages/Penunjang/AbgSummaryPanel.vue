<script setup>
/**
 * AbgSummaryPanel.vue - kolom kanan: ringkasan AGD terakhir.
 *
 * Port 1:1 dari data-purpose="abg-summary" + renderAbgSummary()
 * phase1/penunjang.html: judul + waktu di header teal, grid 3 kolom berisi
 * pH / PaCO2 / PaO2 / HCO3 / BE / SaO2 dengan warna sesuai level, kotak
 * interpretasi, lalu baris metode - FiO2 - oleh.
 *
 * `level` tiap sel diambil dari `flags` baris AGD (SupportService sudah
 * menghitungnya lewat ReferenceRange::abgFlags()), jadi tidak ada klasifikasi
 * ulang di sisi klien.
 */
import { computed } from 'vue';
import { DASH, analyzeAbg, cellToneClass } from './abg';
import { isBlank } from '@/composables/useFormatting';

const props = defineProps({
    latest: { type: Object, default: null },
});

const CELLS = [
    { key: 'ph', label: 'pH', unit: '' },
    { key: 'pco2', label: 'PaCO2', unit: 'mmHg' },
    { key: 'po2', label: 'PaO2', unit: 'mmHg' },
    { key: 'hco3', label: 'HCO3', unit: 'mEq/L' },
    { key: 'be', label: 'BE', unit: '' },
    { key: 'sao2', label: 'SaO2', unit: '%' },
];

const interpretation = computed(() => analyzeAbg(props.latest));

// Label FiO2 dihitung di script, bukan lewat <template v-if>/v-else di dalam <p>:
// v-else di dalam sebuah <template> v-if yang juga punya simpul teks tidak bisa di-codegen
// oleh @vue/compiler-sfc ("Codegen node is missing for element/if/for node").
const fio2Label = computed(() => (isBlank(props.latest?.fio2) ? DASH : Number(props.latest.fio2) + '%'));
const cells = computed(() =>
    CELLS.map((cell) => {
        const flagged = (props.latest?.flags || []).find((flag) => flag.key === cell.key);
        const raw = props.latest?.[cell.key];

        return {
            ...cell,
            value: isBlank(raw) ? DASH : Number(raw),
            tone: cellToneClass(flagged?.flag || 'none'),
        };
    }),
);
</script>

<template>
    <section class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm" data-purpose="abg-summary">
        <div class="flex items-center justify-between border-b border-teal-200 bg-teal-50 p-3">
            <h2 class="text-xs font-black uppercase tracking-wide text-teal-800">
                <i class="fa-solid fa-lungs mr-1.5" aria-hidden="true"></i>Ringkasan AGD Terakhir
            </h2>
            <span class="font-mono text-[10px] text-teal-700">{{ latest ? latest.measuredAtLabel : DASH }}</span>
        </div>
        <div class="p-4">
            <p v-if="!latest" class="text-sm text-slate-500">Belum ada hasil AGD.</p>

            <template v-else>
                <div class="grid grid-cols-3 gap-2 text-center">
                    <div v-for="cell in cells" :key="cell.key" class="rounded-lg border border-slate-200 px-2 py-2">
                        <p class="text-[10px] font-bold uppercase text-slate-400">{{ cell.label }}</p>
                        <p class="font-mono text-lg font-black" :class="cell.tone">{{ cell.value }}</p>
                        <p class="text-[9px] text-slate-400">{{ cell.unit }}</p>
                    </div>
                </div>

                <div class="mt-3 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2">
                    <p class="text-[10px] font-bold uppercase text-slate-400">Interpretasi</p>
                    <p class="mt-0.5 text-sm font-black" :class="interpretation.tone">{{ interpretation.label }}</p>
                    <p class="mt-1 text-[11px] text-slate-600">{{ interpretation.detail }}</p>
                </div>

                <p class="mt-2 text-[10px] text-slate-500">
                    Metode: {{ latest.method || DASH }} &bull;
                    FiO2: {{ fio2Label }}
                    &bull; Oleh: {{ latest.by || DASH }}
                </p>
            </template>
        </div>
    </section>
</template>
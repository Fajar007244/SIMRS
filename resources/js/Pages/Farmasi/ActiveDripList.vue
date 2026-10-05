<script setup>
/**
 * ActiveDripList.vue - daftar drip / obat aktif (Inotropik & Sedasi).
 *
 * Port 1:1 dari kartu "Drip Obat & Cairan Aktif" + renderActiveMedications()
 * di phase1/farmasi.html: judul berkapital kecil, badge "Syringe Pump", lalu
 * satu kartu per obat dengan garis kiri berwarna according ke kategorinya.
 *
 * CATATAN KONTRAK: MedicationRecapService::getActiveDrips() mengembalikan
 * MedicationRow yang sama dengan getAdministrations() - fieldsnya id,
 * observationId, observationDate, observationTime, recordedAt,
 * recordedAtLabel, name, dose, category, categoryLabel, volume, status,
 * route, recordedBy. Store phase1 punya `administrationCount`, `lastGiven`,
 * dan `lastGivenBy` yang TIDAK ada di kontrak, jadi angka "N x diberikan"
 * sengaja tidak ditampilkan di sini (angka itu ada di kolom Realisasi tabel
 * formularium). Rute yang ditampilkan memakai field `route` yang memang ada.
 *
 * PROPS
 *   drips  Array  default []  getActiveDrips()
 *
 * SLOTS: (tidak ada)
 * EMITS : (tidak ada)
 */
import { computed } from 'vue';
import EmptyState from '@/Components/EmptyState.vue';
import { tone, medicationTone } from '@/tone';
import { formatNumber, isBlank } from '@/composables/useFormatting';

const props = defineProps({
    drips: { type: Array, default: () => [] },
});

const rows = computed(() => (Array.isArray(props.drips) ? props.drips : []));

function cardStyle(category) {
    const t = tone(medicationTone(category).tone);

    return { accent: t.accent, soft: t.soft, chip: medicationTone(category).chip, text: t.value };
}
</script>

<template>
    <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm print-border" data-purpose="active-drips">
        <div class="mb-3 flex items-center justify-between gap-2 border-b border-slate-100 pb-2">
            <h3 class="flex items-center gap-1.5 text-xs font-bold uppercase tracking-wider text-slate-900">
                <i class="fa-solid fa-syringe text-purple-600" aria-hidden="true"></i>
                Drip Obat &amp; Cairan Aktif
            </h3>
            <span class="rounded bg-purple-100 px-1.5 py-0.5 text-[10px] font-bold text-purple-700">Syringe Pump</span>
        </div>

        <div v-if="rows.length === 0" class="space-y-2.5">
            <p class="text-xs text-slate-500">Belum ada obat aktif pada observasi episode ini.</p>
        </div>

        <div v-else class="space-y-2.5">
            <div
                v-for="row in rows"
                :key="row.id"
                class="rounded-lg border border-l-4 border-slate-200 p-2.5"
                :class="[cardStyle(row.category).accent, cardStyle(row.category).soft]"
                data-purpose="active-drip-row"
            >
                <div class="flex items-start justify-between gap-2">
                    <span class="text-xs font-bold leading-tight" :class="cardStyle(row.category).text">
                        {{ row.name }}
                    </span>
                    <span
                        class="shrink-0 rounded px-1.5 py-0.5 text-[10px] font-bold"
                        :class="cardStyle(row.category).chip"
                    >{{ row.categoryLabel || row.category }}</span>
                </div>

                <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-0.5 font-mono text-[11px] text-slate-600">
                    <span><span class="text-slate-400">Dosis:</span> {{ row.dose }}</span>
                    <span><span class="text-slate-400">Volume:</span> {{ formatNumber(row.volume ?? 0) }} mL</span>
                    <span><span class="text-slate-400">Rute:</span> {{ isBlank(row.route) ? '-' : row.route }}</span>
                </div>

                <div class="mt-1 font-sans text-[10px] text-slate-500">
                    Terakhir:
                    <span class="font-semibold text-slate-700">{{ row.recordedAtLabel }}</span>
                    oleh
                    <span class="font-semibold text-slate-700">{{ row.recordedBy }}</span>
                </div>
            </div>
        </div>
    </div>
</template>

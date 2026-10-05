<script setup>
/**
 * AsmedTherapyPlan.vue - pemeriksaan rencana terapi ASMED.
 *
 * Port 1:1 dari blok data-purpose="asmed-plan" dan renderPlanPanel() di
 * phase1/farmasi.html: empat kartu (Inotropik, Sedasi, Antibiotik, Cairan)
 * dengan titik berwarna sesuai kategorinya, lalu kotak ringkasan di bawahnya.
 *
 * Sumber data MedicationRecapService::getTherapyPlanChecks() mengembalikan
 * key, label, inPlan, dan evidence. Field evidence berisi potongan kalimat
 * pertama pada rencana terapi yang memuat pola kategori tersebut, hasil port
 * dari findPlanFragment() prototype, sehingga kartu bisa mengutip sumbernya.
 * Potongan dipotong 120 karakter seperti phase1.
 *
 * Keterbatasan kontrak: teks rencana terapi mentah tidak ada di payload,
 * jadi paragraf penuh tidak bisa ditampilkan apa adanya. Yang ditampilkan
 * adalah kutipan evidence per kartu, ringkasan berapa kelompok yang disebut,
 * serta diagnosa medis yang memang tersedia di banner.
 *
 * PROPS
 *   checks     Array   default []  getTherapyPlanChecks() (4 baris)
 *   diagnoses  String  default ''  banner.diagnoses
 *
 * SLOTS: (tidak ada)
 * EMITS : (tidak ada)
 */
import { computed } from 'vue';
import { tone, medicationTone } from '@/tone';
import { isBlank } from '@/composables/useFormatting';

const props = defineProps({
    checks: { type: Array, default: () => [] },
    diagnoses: { type: String, default: '' },
});

const rows = computed(() => (Array.isArray(props.checks) ? props.checks : []));

const inPlanCount = computed(() => rows.value.filter((row) => row.inPlan).length);

const planSummary = computed(() => {
    if (rows.value.length === 0) {
        return 'Belum ada pemeriksaan rencana terapi.';
    }

    return `${inPlanCount.value} dari ${rows.value.length} kelompok obat disebut pada rencana terapi ASMED.`;
});

const firstEvidence = computed(() => {
    const found = rows.value.find((row) => row.inPlan && !isBlank(row.evidence));

    return found ? found.evidence : null;
});

/** Potongan kalimat dipotong 120 karakter, persis seperti phase1. */
function quote(evidence) {
    const value = String(evidence || '');

    return value.length > 120 ? `${value.slice(0, 120)}...` : value;
}

function dotClass(label) {
    return tone(medicationTone(label).tone).fill;
}
</script>

<template>
    <section
        class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm print-border"
        data-purpose="asmed-plan"
    >
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 bg-slate-50 p-4">
            <div class="flex items-center gap-2">
                <i class="fa-solid fa-clipboard-list text-lg text-emerald-600" aria-hidden="true"></i>
                <div>
                    <h2 class="text-sm font-bold text-slate-900">
                        Rencana Terapi (Ditetapkan DPJP pada ASMED)
                    </h2>
                    <p class="text-xs text-slate-500">
                        Rencana terapi yang ditetapkan dokter penanggung jawab pasien dan dicocokkan dengan realisasi pemberian obat
                    </p>
                </div>
            </div>
        </div>

        <div class="space-y-3 p-4">
            <div class="grid grid-cols-1 gap-3 md:grid-cols-4">
                <div
                    v-for="row in rows"
                    :key="row.key"
                    class="rounded-lg border border-slate-200 bg-slate-50 p-3"
                    data-purpose="plan-check"
                    :data-in-plan="row.inPlan ? 'true' : 'false'"
                >
                    <div class="mb-1.5 flex items-center gap-1.5">
                        <span class="h-2 w-2 rounded-full" :class="dotClass(row.label)"></span>
                        <span class="text-[11px] font-bold uppercase tracking-wider text-slate-700">
                            {{ row.label }}
                        </span>
                    </div>

                    <div v-if="row.inPlan" class="text-[11px] text-emerald-700">
                        <span class="flex items-center gap-1 font-bold">
                            <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
                            Tersebut dalam rencana
                        </span>
                        <span class="mt-0.5 block italic text-slate-600">&quot;{{ quote(row.evidence) }}&quot;</span>
                    </div>
                    <div v-else class="text-[11px] text-amber-700">
                        <span class="flex items-center gap-1 font-bold">
                            <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
                            Tidak disebut dalam rencana
                        </span>
                    </div>
                </div>
            </div>

            <div class="rounded border border-slate-200 bg-slate-50 p-3 text-[11px] text-slate-600">
                <p class="mb-1 font-bold text-slate-700">Ringkasan Rencana Terapi (ASMED):</p>
                <p class="leading-relaxed" data-purpose="plan-summary">{{ planSummary }}</p>
                <p v-if="firstEvidence" class="mt-2 italic leading-relaxed text-slate-600">
                    &quot;{{ quote(firstEvidence) }}&quot;
                </p>
                <p class="mb-1 mt-2 font-bold text-slate-700">Diagnosa Medis:</p>
                <p class="leading-relaxed">{{ isBlank(diagnoses) ? '-' : diagnoses }}</p>
            </div>
        </div>
    </section>
</template>

<script setup>
/**
 * AllergyDrugSafetyAlert.vue - skrining keamanan obat terhadap alergi.
 *
 * Port 1:1 dari blok data-purpose="allergy-safety" + renderAllergyPanel() di
 * phase1/farmasi.html. Prototype punya tiga cabang; ketiganya dipertahankan:
 *
 *   1. `allergies` kosong            -> kartu netral "Tidak ada alergi obat
 *      yang tercatat".
 *   2. ada alergi, `hasConflict` false-> kartu hijau "Tidak ada konflik obat
 *      terdeteksi" yang menyebut daftar alerinya.
 *   3. `hasConflict` true            -> panel MERAH yang menonjol: jumlah baris
 *      yang cocok, lalu tiap obat beserta setiap baris pemberiannya.
 *
 * Perbedaan yang disengaja dari prototype: konflik dikelompokkan per nama
 * obat karena MedicationRecapService::getAllergyConflicts() mengembalikan
 * `{ id, name, matchedAllergy, rows[] }` dan setiap baris sudah punya
 * `recordedAtLabel` siap tampil. Jadi pencocokan string manual seperti di
 * phase1 tidak diperlukan lagi. Panel merah memakai border tebal, latar
 * merah, dan ikon segitiga agar langsung terlihat.
 *
 * PROPS
 *   conflicts  Object  default {}  getAllergyConflicts()
 *
 * SLOTS: (tidak ada)
 * EMITS : (tidak ada)
 */
import { computed } from 'vue';
import { formatNumber, isBlank } from '@/composables/useFormatting';

const props = defineProps({
    conflicts: { type: Object, default: () => ({}) },
});

const data = computed(() => (props.conflicts && typeof props.conflicts === 'object' ? props.conflicts : {}));

const allergies = computed(() => (Array.isArray(data.value.allergies) ? data.value.allergies : []));

const list = computed(() => (Array.isArray(data.value.conflicts) ? data.value.conflicts : []));

const hasConflict = computed(() => Boolean(data.value.hasConflict) && list.value.length > 0);

/** Jumlah baris pemberian, bukan jumlah jenis obat - seperti prototype. */
const rowCount = computed(() =>
    list.value.reduce((sum, entry) => sum + (Array.isArray(entry?.rows) ? entry.rows.length : 0), 0),
);

const allergyText = computed(() => allergies.value.join(', '));
</script>

<template>
    <section
        class="overflow-hidden rounded-xl border shadow-sm print-border"
        :class="hasConflict ? 'border-red-300 bg-white' : 'border-slate-200 bg-white'"
        data-purpose="allergy-safety"
        :data-has-conflict="hasConflict ? 'true' : 'false'"
    >
        <div
            class="flex flex-wrap items-center justify-between gap-3 border-b px-4 py-3"
            :class="hasConflict ? 'border-red-200 bg-red-50' : 'border-amber-200 bg-amber-50'"
        >
            <div class="flex items-center gap-2">
                <i
                    class="fa-shield-halved text-lg"
                    :class="hasConflict ? 'text-red-600' : 'text-amber-600'"
                    aria-hidden="true"
                ></i>
                <div>
                    <h2 class="text-sm font-bold text-slate-900">Peringatan Alergi &amp; Keamanan Obat</h2>
                    <p class="text-xs text-slate-500">
                        Silang cocokkan daftar alergi pasien dengan setiap nama obat yang direalisasikan pada observasi
                    </p>
                </div>
            </div>
            <span
                class="rounded-full border px-2.5 py-1 text-[10px] font-bold"
                :class="hasConflict
                    ? 'border-red-300 bg-white text-red-800'
                    : 'border-amber-300 bg-white text-amber-800'"
            >Drug Safety Check</span>
        </div>

        <div class="p-4">
            <div
                v-if="allergies.length === 0"
                class="flex items-start gap-2.5 rounded-lg border border-slate-200 bg-slate-50 p-3"
                data-purpose="allergy-state"
            >
                <i class="fa-solid fa-circle-info mt-0.5 text-slate-500" aria-hidden="true"></i>
                <div>
                    <p class="text-xs font-bold text-slate-800">Tidak ada alergi obat yang tercatat</p>
                    <p class="text-[11px] text-slate-500">
                        Riwayat alergi pasien kosong atau tidak diisi, sehingga tidak ada skrining konflik obat yang dilakukan.
                    </p>
                </div>
            </div>

            <div
                v-else-if="!hasConflict"
                class="flex items-start gap-2.5 rounded-lg border border-emerald-200 bg-emerald-50 p-3"
                data-purpose="allergy-state"
            >
                <i class="fa-solid fa-circle-check mt-0.5 text-emerald-600" aria-hidden="true"></i>
                <div>
                    <p class="text-xs font-bold text-emerald-800">Tidak ada konflik obat terdeteksi</p>
                    <p class="text-[11px] text-emerald-700">
                        Tidak ada obat yang direalisasikan pada observasi yang cocok dengan alergi tercatat: {{ allergyText }}.
                    </p>
                </div>
            </div>

            <div v-else class="rounded-lg border border-red-300 bg-red-50 p-3" data-purpose="allergy-state">
                <div class="flex items-start gap-2.5">
                    <i class="fa-solid fa-triangle-exclamation mt-0.5 text-red-600" aria-hidden="true"></i>
                    <div>
                        <p class="text-xs font-bold text-red-800">Konflik obat terdeteksi: {{ allergyText }}</p>
                        <p class="text-[11px] text-red-700">
                            Sebanyak {{ formatNumber(rowCount) }} baris pemberian obat dari
                            {{ formatNumber(list.length) }} jenis obat cocok dengan daftar alergi pasien.
                            Segera konfirmasi ke DPJP.
                        </p>
                    </div>
                </div>

                <ul class="mt-2.5 space-y-1.5">
                    <li
                        v-for="entry in list"
                        :key="entry.id"
                        class="rounded-lg border border-red-200 bg-white px-3 py-2"
                        data-purpose="allergy-conflict"
                    >
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <span class="text-xs font-bold text-red-800">{{ entry.name }}</span>
                            <span class="rounded border border-red-200 bg-red-50 px-1.5 py-0.5 text-[10px] font-bold text-red-700">
                                cocok: {{ entry.matchedAllergy }}
                            </span>
                        </div>
                        <ul class="mt-1.5 space-y-1">
                            <li
                                v-for="row in entry.rows"
                                :key="row.id"
                                class="flex flex-wrap items-center justify-between gap-2 border-t border-red-100 pt-1"
                            >
                                <span class="font-mono text-[11px] font-bold text-red-700">
                                    {{ isBlank(row.observationTime) ? '-' : row.observationTime }}
                                    <span class="font-sans font-normal text-slate-500">{{ row.recordedAtLabel }}</span>
                                </span>
                                <span class="font-mono text-[10px] text-slate-500">oleh {{ row.recordedBy }}</span>
                            </li>
                        </ul>
                    </li>
                </ul>
            </div>
        </div>
    </section>
</template>

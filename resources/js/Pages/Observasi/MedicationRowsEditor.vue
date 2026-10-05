<script setup>
/**
 * MedicationRowsEditor.vue - daftar koreksi pemberian obat / cairan pada
 * fieldset section-fluid-management phase1/observasi.html
 * (#fluidCorrectionRows + #addFluidCorrectionBtn).
 *
 * prototype memakai cloneNode() dari satu baris template; di sini baris
 * adalah objek dalam array reaktif yang diikat v-model, jadi menambah dan
 * menghapus baris tidak menyentuh DOM secara manual.
 *
 * TIDAK ADA KOLOM KATEGORI - sama seperti prototype. Kategori di-infer dari
 * nama obat memakai regex inferMedicationCategory() (phase1/observasi.html,
 * salinannya ada di medicationCategory.js dan config('formularium.category_patterns')),
 * dengan urutan: yang pertama cocok menang, tidak ada yang cocok berarti
 * "Lainnya". Nilai hasil inferensi tetap dikirim pada payload supaya
 * MedicationRecapService mengelompokkan baris ke kategori yang sama, dan
 * ObservationService::syncMedications() meng-infer ulang di server bila
 * `category` kosong - jadi kolom tebakan tidak pernah jadi sumber kebenaran.
 *
 * GRID dan sel persis mengikuti prototype:
 *   grid-cols-1 md:grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)_110px_34px]
 *   nama obat / cairan | dosis / keterangan | volume (mL) | tombol hapus
 *
 * Aturan jumlah baris yang dipakai payload (port getFluidCorrections() +
 * buildMedicationEntries()): baris yang benar-benar kosong (nama, dosis, dan
 * volume 0 semua kosong) TIDAK dikirim.
 *
 * PROPS
 *   modelValue Array  baris [{ name, dose, category, volume }]
 *   categories Object label => value, dari MedicationCategory::options()
 *   disabled  Boolean
 *
 * EMITS
 *   'update:modelValue' Array
 */
import { computed } from 'vue';
import { inferCategory } from './medicationCategory';

const props = defineProps({
    modelValue: { type: Array, default: () => [] },
    categories: { type: Object, default: () => ({}) },
    disabled: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue']);

const rows = computed(() => (Array.isArray(props.modelValue) ? props.modelValue : []));

function blankRow() {
    return { name: '', dose: '', category: '', volume: '' };
}

function replace(next) {
    emit('update:modelValue', next);
}

function addRow() {
    replace([...rows.value, blankRow()]);
}

function removeRow(index) {
    const next = rows.value.slice();

    next.splice(index, 1);

    // Baris terakhir tidak dihapus, hanya dikosongkan - sama dengan phase1 yang
    // membersihkan input baris terakhir alih-alih menutupnya, supaya formulir
    // tidak pernah tampil tanpa kolom nama.
    replace(next.length > 0 ? next : [blankRow()]);
}

function clearRow(index) {
    const next = rows.value.slice();

    next[index] = blankRow();
    replace(next);
}

/**
 * Kategori mengikuti nama obat selama pengguna belum mengubahnya sendiri.
 * Prototipe tidak punya kolom kategori sama sekali, jadi ini murni aides.
 */
function onNameInput(row) {
    const inferred = inferCategory(row.name);

    row.category = inferred || row.category;
}

/**
 * Kategori hasil inferensi baris PERTAMA, hanya sebagai keterangan di bawah
 * daftar. Mengembalikan string kosong saat daftar kosong - pemanggil tidak
 * boleh pernah menerima undefined di sini karena template memanggilnya tanpa
 * cek lebih dulu.
 */
const firstRowCategory = computed(() => {
    const first = rows.value[0];

    return first ? inferCategory(first.name) || '' : '';
});
</script>

<template>
    <div data-purpose="medication-correction-list">
        <div class="mb-1 flex items-center justify-between gap-2">
            <label class="block font-semibold text-slate-700" data-purpose="medication-editor-label">
                Koreksi Pemberian Cairan Dan Elektrolit :
            </label>
            <button
                id="addFluidCorrectionBtn"
                type="button"
                class="inline-flex items-center gap-1 rounded-md border border-sky-300 bg-sky-50 px-2 py-1 text-[11px] font-bold text-sky-700 transition hover:bg-sky-100"
                :disabled="props.disabled"
                data-purpose="medication-add-row"
                @click="addRow"
            >
                <i class="fa-solid fa-plus" aria-hidden="true"></i> Tambah
            </button>
        </div>

        <div id="fluidCorrectionRows" class="space-y-2" data-purpose="medication-correction-rows">
            <div
                v-for="(row, index) in rows"
                :key="`med-${index}`"
                class="grid grid-cols-1 items-end gap-2 md:grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)_110px_34px]"
                data-fluid-correction-row
                data-purpose="medication-row"
            >
                <div>
                    <label class="mb-1 block text-[11px] font-semibold text-slate-500" :for="`med-name-${index}`">
                        Nama obat / cairan
                    </label>
                    <input
                        :id="`med-name-${index}`"
                        v-model="row.name"
                        type="text"
                        class="input"
                        :disabled="props.disabled"
                        placeholder="Contoh: KCl dalam NaCl 0,9%"
                        data-fluid-name
                        data-purpose="medication-name"
                        @input="onNameInput(row)"
                    >
                </div>

                <div>
                    <label class="mb-1 block text-[11px] font-semibold text-slate-500" :for="`med-dose-${index}`">
                        Dosis / keterangan
                    </label>
                    <input
                        :id="`med-dose-${index}`"
                        v-model="row.dose"
                        type="text"
                        class="input"
                        :disabled="props.disabled"
                        placeholder="Contoh: 25 mEq / 500 mL"
                        data-fluid-dose
                        data-purpose="medication-dose"
                    >
                </div>

                <div>
                    <label class="mb-1 block text-[11px] font-semibold text-slate-500" :for="`med-volume-${index}`">
                        Volume (mL)
                    </label>
                    <input
                        :id="`med-volume-${index}`"
                        v-model="row.volume"
                        type="number"
                        min="0"
                        class="input font-mono"
                        :disabled="props.disabled"
                        placeholder="0"
                        data-fluid-volume
                        data-purpose="medication-volume"
                    >
                </div>

                <button
                    type="button"
                    class="h-9 w-9 rounded-lg border border-red-200 text-red-600 transition hover:bg-red-50"
                    :disabled="props.disabled"
                    aria-label="Hapus obat atau cairan"
                    title="Hapus baris"
                    data-remove-fluid-correction
                    data-purpose="medication-remove-row"
                    @click="removeRow(index)"
                >
                    <i class="fa-solid fa-trash-can" aria-hidden="true"></i>
                </button>
            </div>
        </div>

        <p class="mt-1.5 text-[10px] text-slate-500">
            Baris yang kosong semua tidak dikirim ke server. Kategori obat mengikuti nama yang
            diketik (regex tetap sama dengan prototype), jadi tabel
            <span class="font-mono">observation_medications</span> yang dibaca modul Farmasi
            tetap terisi kategori yang benar.
            <template v-if="firstRowCategory">
                Contoh kategori baris ini: <strong>{{ firstRowCategory }}</strong>.
            </template>
        </p>
    </div>
</template>
<script setup>
/**
 * Cppt/DiagnosisPanel.vue - tab "Diagnosa" pada halaman CPPT.
 *
 * Port 1:1 dari phase1/cppt.html:
 *   renderDiagnosis()  -> deretan chip diagnosis aktif
 *   diagnosisChips()   -> satu chip per diagnosis, `utama` diberi ikon bintang
 *   diagForm           -> grid sm:grid-cols-[140px_1fr_140px_auto]
 *
 * Gate JavaScript prototype (submit diagForm) berbunyi:
 *   if (!text) { toast('Nama diagnosa wajib diisi.', true); return; }
 * Kalimat yang sama dipakai sebagai pesan validasi server, jadi tidak ada
 * alert() di frontend dan tidak ada 500 dari backend: pemeriksa kosong
 * ditolak controller dengan ValidationException dan errornya tampil inline.
 *
 * Setelah berhasil, AdmissionService::storeDiagnosis() menyegarkan
 * encounters.diagnosis_summary, jadi kartu census dan banner ikut benar
 * setelah reload.
 *
 * PROPS
 *   diagnoses  Array   baris diagnosis dari AdmissionService::getCpptPanels()
 *   canWrite   Boolean hanya dokter
 *   storeUrl   String  URL POST
 *
 * EMITS
 *   saved  void
 */
import { computed } from 'vue';
import { useForm } from '@inertiajs/vue3';

const props = defineProps({
    diagnoses: { type: Array, default: () => [] },
    canWrite: { type: Boolean, default: false },
    storeUrl: { type: String, default: '' },
});

const emit = defineEmits(['saved']);

/* prototype: <option value="utama">Utama</option> / penyerta */
const TYPES = [
    { value: 'utama', label: 'Utama' },
    { value: 'penyerta', label: 'Penyerta' },
];

const form = useForm({
    type: 'utama',
    text: '',
    code: '',
});

const errorFor = (field) => (form.errors && form.errors[field] ? String(form.errors[field]) : '');

function submit() {
    form.post(props.storeUrl, {
        preserveState: true,
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            form.clearErrors();
            emit('saved');
        },
    });
}

const chipCount = computed(() => props.diagnoses.length);
</script>

<template>
    <div class="space-y-3">
        <p class="text-[11px] font-bold uppercase text-slate-400">Daftar Diagnosa (ICD-10)</p>

        <div class="flex flex-wrap gap-1.5">
            <span
                v-for="item in props.diagnoses"
                :key="item.id"
                class="inline-flex items-center gap-1.5 rounded-md border px-2.5 py-1 text-xs font-semibold"
                :class="item.isPrimary
                    ? 'border-purple-300 bg-purple-100 text-purple-900'
                    : 'border-slate-200 bg-slate-50 text-slate-700'"
            >
                <i v-if="item.isPrimary" class="fa-solid fa-star text-[10px] text-purple-500" aria-hidden="true"></i>
                {{ item.text }}
                <span v-if="item.code" class="font-mono text-[10px] opacity-70">({{ item.code }})</span>
            </span>

            <span v-if="chipCount === 0" class="text-sm text-slate-500">
                Belum ada diagnosa yang dicatat.
            </span>
        </div>

        <!-- ================= FORMULIR (diagForm) ================= -->
        <form
            v-if="props.canWrite"
            id="diagForm"
            class="grid items-start gap-2 border-t border-slate-100 pt-3 no-print sm:grid-cols-[140px_1fr_140px_auto]"
            data-purpose="cppt-diagnosis-form"
            novalidate
            @submit.prevent="submit"
        >
            <div>
                <label class="mb-1 block text-[11px] font-bold uppercase text-slate-500" for="dType">Jenis</label>
                <select id="dType" v-model="form.type" class="select">
                    <option v-for="option in TYPES" :key="option.value" :value="option.value">{{ option.label }}</option>
                </select>
                <p v-if="errorFor('type')" class="mt-1 text-[11px] text-red-600">{{ errorFor('type') }}</p>
            </div>

            <div>
                <label class="mb-1 block text-[11px] font-bold uppercase text-slate-500" for="dText">Nama Diagnosa</label>
                <input id="dText" v-model="form.text" type="text" class="input" :class="{ 'input-error': errorFor('text') }">
                <p v-if="errorFor('text')" class="mt-1 text-[11px] text-red-600">{{ errorFor('text') }}</p>
            </div>

            <div>
                <label class="mb-1 block text-[11px] font-bold uppercase text-slate-500" for="dCode">Kode ICD-10</label>
                <input id="dCode" v-model="form.code" type="text" class="input font-mono" :class="{ 'input-error': errorFor('code') }">
                <p v-if="errorFor('code')" class="mt-1 text-[11px] text-red-600">{{ errorFor('code') }}</p>
            </div>

            <button
                type="submit"
                class="mt-[26px] inline-flex items-center gap-2 rounded-lg bg-purple-600 px-4 py-2 text-xs font-semibold text-white shadow transition hover:bg-purple-700 disabled:opacity-60"
                data-purpose="cppt-diagnosis-submit"
                :disabled="form.processing"
            >
                <i class="fa-solid fa-plus" aria-hidden="true"></i>
                Tambah
            </button>
        </form>

        <p v-else class="border-t border-slate-100 pt-3 text-[11px] text-slate-500">
            Diagnosis diisi oleh dokter. Perawat dan bidan dapat membacanya sebagai konteks asuhan.
        </p>
    </div>
</template>

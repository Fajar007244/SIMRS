<script setup>
/**
 * Cppt/ProcedurePanel.vue - tab "Prosedur" pada halaman CPPT.
 *
 * Port 1:1 dari phase1/cppt.html:
 *   renderProcedure() -> tabel Tanggal / Nama Tindakan / Pelaksana / Kode
 *   procForm          -> grid sm:grid-cols-[150px_1fr_180px_120px_auto]
 *
 * Gate JavaScript prototype (submit procForm) berbunyi:
 *   if (!name) { toast('Nama tindakan wajib diisi.', true); return; }
 * Kalimat yang sama dipakai sebagai pesan validasi server, jadi tidak ada
 * alert() di frontend dan tidak ada 500 dari backend.
 *
 * CATATAN MENGENAI TOMBOL HAPUS
 * Prototype punya tombol tong sampah per baris (data-action del-procedure)
 * yang menghapus baris dari store lokal. Modul ini belum punya endpoint hapus,
 * jadi sel tersebut ditampilkan dalam keadaan nonaktif dan diberi penjelasan,
 * bukan tombol palsu yang diam-diam tidak melakukan apa pun. Begitu endpoint
 * hapus tersedia, cukup kirim prop `destroyUrl` dan tombolnya menghidupkan
 * sendiri.
 *
 * PROSEDUR TIDAK IKUT MEMBARUI encounters.diagnosis_summary. Kolom denormalisasi
 * itu disusun seeder hanya dari baris `diagnoses`, dan tidak ada pembacanya
 * yang menambahkan nama tindakan; alasannya dijelaskan di AdmissionService.
 *
 * PROPS
 *   procedures  Array   baris prosedur dari AdmissionService::getCpptPanels()
 *   canWrite    Boolean hanya dokter
 *   storeUrl    String  URL POST
 *   destroyUrl  String  URL hapus; kosong = tombol hapus nonaktif
 *
 * EMITS
 *   saved  void
 */
import { computed, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';

const props = defineProps({
    procedures: { type: Array, default: () => [] },
    canWrite: { type: Boolean, default: false },
    storeUrl: { type: String, default: '' },
    destroyUrl: { type: String, default: '' },
});

const emit = defineEmits(['saved']);

const DASH = '-';

function pad2(value) {
    return String(value).padStart(2, '0');
}

/** prototype: val('pDate') || new Date().toISOString().slice(0, 10) */
function today() {
    const now = new Date();

    return `${now.getFullYear()}-${pad2(now.getMonth() + 1)}-${pad2(now.getDate())}`;
}

const form = useForm({
    performed_at: today(),
    name: '',
    operator: '',
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

watch(
    () => props.procedures,
    () => {
        form.clearErrors();
    },
);

const rows = computed(() => props.procedures);
const canDelete = computed(() => Boolean(props.destroyUrl));
const deleteHint = 'Penghapusan prosedur belum tersedia pada modul ini.';
</script>

<template>
    <div class="space-y-3">
        <p class="text-[11px] font-bold uppercase text-slate-400">Prosedur &amp; Tindakan</p>

        <div class="overflow-x-auto">
            <table class="w-full text-left" data-purpose="cppt-procedure-table">
                <thead class="bg-slate-50 text-[10px] font-bold uppercase text-slate-500">
                    <tr>
                        <th class="px-3 py-2">Tanggal</th>
                        <th class="px-3 py-2">Nama Tindakan</th>
                        <th class="px-3 py-2">Pelaksana</th>
                        <th class="px-3 py-2 text-center">Kode</th>
                        <th class="px-3 py-2 no-print"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="row in rows"
                        :key="row.id"
                        class="border-t border-slate-100 hover:bg-amber-50/40"
                    >
                        <td class="px-3 py-2.5 font-mono text-sm">{{ row.performedAtLabel || DASH }}</td>
                        <td class="px-3 py-2.5 text-sm font-semibold text-slate-800">{{ row.name || DASH }}</td>
                        <td class="px-3 py-2.5 text-sm text-slate-600">{{ row.operator || DASH }}</td>
                        <td class="px-3 py-2.5 text-center font-mono text-sm text-slate-500">{{ row.code || DASH }}</td>
                        <td class="px-3 py-2.5 text-right no-print">
                            <button
                                type="button"
                                class="text-xs font-bold"
                                :class="canDelete ? 'text-red-600 hover:text-red-800' : 'cursor-not-allowed text-slate-300'"
                                :disabled="!canDelete"
                                :title="canDelete ? 'Hapus prosedur' : deleteHint"
                                data-action="del-procedure"
                            >
                                <i class="fa-solid fa-trash" aria-hidden="true"></i>
                            </button>
                        </td>
                    </tr>

                    <tr v-if="rows.length === 0">
                        <td colspan="5" class="px-3 py-8 text-center text-sm text-slate-500">
                            Belum ada prosedur / tindakan tercatat.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- ================= FORMULIR (procForm) ================= -->
        <form
            v-if="props.canWrite"
            id="procForm"
            class="grid items-start gap-2 border-t border-slate-100 pt-3 no-print sm:grid-cols-[150px_1fr_180px_120px_auto]"
            data-purpose="cppt-procedure-form"
            novalidate
            @submit.prevent="submit"
        >
            <div>
                <label class="mb-1 block text-[11px] font-bold uppercase text-slate-500" for="pDate">Tanggal</label>
                <input id="pDate" v-model="form.performed_at" type="date" class="input" :class="{ 'input-error': errorFor('performed_at') }">
                <p v-if="errorFor('performed_at')" class="mt-1 text-[11px] text-red-600">{{ errorFor('performed_at') }}</p>
            </div>

            <div>
                <label class="mb-1 block text-[11px] font-bold uppercase text-slate-500" for="pName">Nama Tindakan</label>
                <input id="pName" v-model="form.name" type="text" class="input" :class="{ 'input-error': errorFor('name') }">
                <p v-if="errorFor('name')" class="mt-1 text-[11px] text-red-600">{{ errorFor('name') }}</p>
            </div>

            <div>
                <label class="mb-1 block text-[11px] font-bold uppercase text-slate-500" for="pOperator">Pelaksana</label>
                <input id="pOperator" v-model="form.operator" type="text" class="input" :class="{ 'input-error': errorFor('operator') }">
                <p v-if="errorFor('operator')" class="mt-1 text-[11px] text-red-600">{{ errorFor('operator') }}</p>
            </div>

            <div>
                <label class="mb-1 block text-[11px] font-bold uppercase text-slate-500" for="pCode">Kode</label>
                <input id="pCode" v-model="form.code" type="text" class="input font-mono" :class="{ 'input-error': errorFor('code') }">
                <p v-if="errorFor('code')" class="mt-1 text-[11px] text-red-600">{{ errorFor('code') }}</p>
            </div>

            <button
                type="submit"
                class="mt-[26px] inline-flex items-center gap-2 rounded-lg bg-amber-600 px-4 py-2 text-xs font-semibold text-white shadow transition hover:bg-amber-700 disabled:opacity-60"
                data-purpose="cppt-procedure-submit"
                :disabled="form.processing"
            >
                <i class="fa-solid fa-plus" aria-hidden="true"></i>
                Tambah
            </button>
        </form>

        <p v-else class="border-t border-slate-100 pt-3 text-[11px] text-slate-500">
            Prosedur dicatat oleh dokter. Perawat dan bidan dapat membacanya sebagai konteks asuhan.
        </p>
    </div>
</template>

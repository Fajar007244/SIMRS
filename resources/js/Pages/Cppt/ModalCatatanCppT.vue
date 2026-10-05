<script setup>
/**
 * Cppt/ModalCatatanCppT.vue - modal "Tambah CPPT" untuk tab Timeline.
 *
 * BELUM ADA PROTOTYPE untuk formulir ini. phase1/cppt.html hanya menampilkan
 * timeline yang READ-ONLY (renderTimeline() merangkai ASMED, asuhan, prosedur,
 * dan observasi, tidak punya tombol tulis). Jadi bentuk modal ini sengaja
 * mengikuti bahasa visual halaman, bukan port baris demi baris:
 *   - jadwal memakai bagian atas sebagai strip identitas, seperti modal
 *     observasi pada modul Observasi;
 *   - empat isian S/O/A/P memakai label huruf besar 11px dan kolom yang sama
 *     dengan blok timeline (`block()` pada prototype), lalu disimpan sebagai
 *     satu baris medical_notes;
 *   - tombol memakai Modal.vue yang sudah ada di komponen bersama.
 *
 * Tanggal dan Waktu mengirim SATU kolom `noted_at` ("Y-m-d H:i:s"), bukan dua
 * kolom terpisah, karena medical_notes.noted_at memang satu timestamp.
 *
 * PENULIS TIDAK BISA DIEDIT. Nama, peran, dan spesialisasi diturunkan server
 * dari user yang sedang login, jadi kotak di bawah hanya MEMBACANYA dari
 * usePage().props.auth.user - nilai itu tidak pernah ikut terkirim.
 *
 * PROPS
 *   open      Boolean
 *   storeUrl  String   URL POST
 *   patient   Object   banner pasien untuk strip identitas (opsional)
 *   user      Object   props.auth.user, untuk kotak penulis
 *
 * EMITS
 *   close   void
 *   saved   void  storage sukses; halaman induk yang memutuskan reload
 */
import { computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Modal from '@/Components/Modal.vue';

const props = defineProps({
    open: { type: Boolean, default: false },
    storeUrl: { type: String, default: '' },
    patient: { type: Object, default: null },
    user: { type: Object, default: null },
});

const emit = defineEmits(['close', 'saved']);

const DASH = '-';

/* Kolom medical_notes.shift nullable; hanya tiga shift yang dipakai modul ini. */
const SHIFTS = ['Pagi', 'Siang', 'Malam'];

function pad2(value) {
    return String(value).padStart(2, '0');
}

function today() {
    const now = new Date();

    return `${now.getFullYear()}-${pad2(now.getMonth() + 1)}-${pad2(now.getDate())}`;
}

function nowTime() {
    const now = new Date();

    return `${pad2(now.getHours())}:${pad2(now.getMinutes())}`;
}

function blankForm() {
    return {
        noted_at_date: today(),
        noted_at_time: nowTime(),
        shift: '',
        subjective: '',
        objective: '',
        assessment: '',
        plan: '',
    };
}

const form = useForm(blankForm());

/* Setiap kali modal dibuka, isian kembali ke tanggal/jam saat itu. */
watch(
    () => props.open,
    (isOpen) => {
        if (!isOpen) return;

        form.defaults(blankForm());
        form.reset();
        form.clearErrors();
    },
    { immediate: true },
);

const errorFor = (field) => (form.errors && form.errors[field] ? String(form.errors[field]) : '');

const authorName = computed(() => (props.user && props.user.name ? props.user.name : DASH));
const authorRole = computed(() => (props.user && props.user.roleLabel ? props.user.roleLabel : DASH));
const authorSpecialty = computed(() => (props.user && props.user.specialty ? props.user.specialty : DASH));

const initials = computed(() => (props.user && props.user.initials ? props.user.initials : '?'));

function submit() {
    form
        .transform((data) => ({
            noted_at: `${data.noted_at_date} ${data.noted_at_time}:00`,
            shift: data.shift,
            subjective: data.subjective,
            objective: data.objective,
            assessment: data.assessment,
            plan: data.plan,
        }))
        .post(props.storeUrl, {
            /*
             * preserveState menjaga modal tetap terbuka ketika server membalas
             * dengan error validasi, jadi pesan inline bisa dibaca tanpa
             * mengetik ulang S/O/A/P.
             */
            preserveState: true,
            preserveScroll: true,
            onSuccess: () => {
                emit('saved');
                emit('close');
            },
        });
}

function requestClose() {
    if (form.processing) return;

    emit('close');
}
</script>

<template>
    <Modal
        :open="props.open"
        title="Tambah Catatan CPPT"
        subtitle="Catatan progres S / O / A / P yang ditandatangani petugas"
        icon="fa-solid fa-user-doctor"
        size="lg"
        body-class="p-0"
        :close-on-escape="!form.processing"
        :close-on-backdrop="!form.processing"
        data-purpose="cppt-note-modal"
        @close="requestClose"
    >
        <!-- ============ STRIP IDENTITAS PASIEN ============ -->
        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 bg-slate-50 px-5 py-2.5 text-xs text-slate-700">
            <div class="flex items-center gap-2">
                <span class="flex h-6 w-6 items-center justify-center rounded-full bg-sky-600 text-[10px] font-bold text-white">
                    {{ props.patient && props.patient.initials ? props.patient.initials : '-' }}
                </span>
                <span class="font-bold text-slate-900">{{ props.patient && props.patient.name ? props.patient.name : '-' }}</span>
                <span class="text-slate-400">|</span>
                <span>{{ props.patient && props.patient.demographics ? props.patient.demographics : '-' }}</span>
                <span class="text-slate-400">|</span>
                <span class="font-mono">No. RM: {{ props.patient && props.patient.mrn ? props.patient.mrn : '-' }}</span>
            </div>
            <div class="flex items-center gap-3 text-[11px]">
                <span>
                    Unit:
                    <strong class="rounded bg-sky-100 px-1.5 py-0.5 font-bold text-sky-700">
                        {{ props.patient && props.patient.unitBed ? props.patient.unitBed : '-' }}
                    </strong>
                </span>
                <span
                    class="rounded border border-emerald-200 bg-emerald-50 px-2 py-0.5 font-bold text-emerald-700"
                >
                    {{ props.patient && props.patient.payment ? props.patient.payment : '-' }}
                </span>
            </div>
        </div>

        <form
            id="cpptNoteForm"
            class="max-h-[70vh] space-y-4 overflow-y-auto p-5 text-xs text-slate-800"
            data-purpose="cppt-note-form"
            novalidate
            @submit.prevent="submit"
        >
            <div class="grid gap-3 sm:grid-cols-4">
                <div>
                    <label class="mb-1 block text-[11px] font-bold uppercase text-slate-500" for="cpptDate">Tanggal</label>
                    <input id="cpptDate" v-model="form.noted_at_date" type="date" class="input" :class="{ 'input-error': errorFor('noted_at') }">
                </div>

                <div>
                    <label class="mb-1 block text-[11px] font-bold uppercase text-slate-500" for="cpptTime">Waktu</label>
                    <input id="cpptTime" v-model="form.noted_at_time" type="time" class="input" :class="{ 'input-error': errorFor('noted_at') }">
                </div>

                <div>
                    <label class="mb-1 block text-[11px] font-bold uppercase text-slate-500" for="cpptShift">Shift</label>
                    <select id="cpptShift" v-model="form.shift" class="select" :class="{ 'input-error': errorFor('shift') }">
                        <option value="">Tidak diisi</option>
                        <option v-for="shift in SHIFTS" :key="shift" :value="shift">{{ shift }}</option>
                    </select>
                </div>

                <div class="flex items-end">
                    <div class="flex w-full items-center gap-2 rounded-md border border-slate-200 bg-slate-50 px-2.5 py-1.5">
                        <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-emerald-600 text-[10px] font-bold text-white">
                            {{ initials }}
                        </span>
                        <span class="min-w-0">
                            <span class="block truncate font-bold text-slate-800">{{ authorName }}</span>
                            <span class="block truncate text-[10px] text-slate-500">{{ authorRole }} &bull; {{ authorSpecialty }}</span>
                        </span>
                    </div>
                </div>
            </div>

            <p v-if="errorFor('noted_at')" class="text-[11px] text-red-600">{{ errorFor('noted_at') }}</p>
            <p v-if="errorFor('shift')" class="text-[11px] text-red-600">{{ errorFor('shift') }}</p>
            <p class="field-hint">
                Nama, peran, dan spesialisasi penulis diambil otomatis dari sesi login, jadi tidak bisa diubah dari formulir ini.
            </p>

            <div class="grid gap-3 md:grid-cols-2">
                <div>
                    <label class="mb-1 block text-[11px] font-bold uppercase text-slate-500" for="cpptSubjective">Subjektif / Keluhan</label>
                    <textarea id="cpptSubjective" v-model="form.subjective" rows="4" class="textarea" :class="{ 'input-error': errorFor('subjective') }"></textarea>
                    <p v-if="errorFor('subjective')" class="mt-1 text-[11px] text-red-600">{{ errorFor('subjective') }}</p>
                </div>

                <div>
                    <label class="mb-1 block text-[11px] font-bold uppercase text-slate-500" for="cpptObjective">Objektif / Temuan objektif</label>
                    <textarea id="cpptObjective" v-model="form.objective" rows="4" class="textarea" :class="{ 'input-error': errorFor('objective') }"></textarea>
                    <p v-if="errorFor('objective')" class="mt-1 text-[11px] text-red-600">{{ errorFor('objective') }}</p>
                </div>

                <div>
                    <label class="mb-1 block text-[11px] font-bold uppercase text-slate-500" for="cpptAssessment">Assessment / Diagnosis kerja</label>
                    <textarea id="cpptAssessment" v-model="form.assessment" rows="4" class="textarea" :class="{ 'input-error': errorFor('assessment') }"></textarea>
                    <p v-if="errorFor('assessment')" class="mt-1 text-[11px] text-red-600">{{ errorFor('assessment') }}</p>
                </div>

                <div>
                    <label class="mb-1 block text-[11px] font-bold uppercase text-slate-500" for="cpptPlan">Plan / Rencana terapi</label>
                    <textarea id="cpptPlan" v-model="form.plan" rows="4" class="textarea" :class="{ 'input-error': errorFor('plan') }"></textarea>
                    <p v-if="errorFor('plan')" class="mt-1 text-[11px] text-red-600">{{ errorFor('plan') }}</p>
                </div>
            </div>

            <div class="flex justify-end gap-2 border-t border-slate-100 pt-3">
                <button
                    type="button"
                    class="rounded-lg border border-slate-300 px-4 py-2 text-xs font-semibold transition hover:bg-slate-50"
                    data-action="cancel"
                    :disabled="form.processing"
                    @click="requestClose"
                >
                    Batal
                </button>
                <button
                    type="submit"
                    class="inline-flex items-center gap-2 rounded-lg bg-emerald-600 px-4 py-2 text-xs font-semibold text-white shadow transition hover:bg-emerald-500 disabled:opacity-60"
                    data-purpose="cppt-note-submit"
                    :disabled="form.processing"
                >
                    <i class="fa-solid fa-floppy-disk" aria-hidden="true"></i>
                    {{ form.processing ? 'Menyimpan...' : 'Simpan CPPT' }}
                </button>
            </div>
        </form>
    </Modal>
</template>

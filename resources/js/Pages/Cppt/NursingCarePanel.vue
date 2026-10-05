<script setup>
/**
 * Cppt/NursingCarePanel.vue - tab "Asuhan Keperawatan" pada halaman CPPT.
 *
 * Port 1:1 dari phase1/cppt.html:
 *   renderNursing() -> mode tampilan (blok A / P / I tiga kolom)
 *   nursingForm()   -> mode formulir (empat field + shift)
 *   emptyState()    -> kondisi belum ada asuhan
 *
 * Berbeda dengan ASMED, asuhan TIDAK unik per episode:
 * AdmissionService::storeNursingCare() menambah satu baris baru setiap kali
 * disimpan dan memindahkan flag `is_latest` ke baris itu. Karena itu tombol di
 * mode tampilan bernama "Catatan Baru", bukan "Edit" - mengisi formulir ini
 * berarti menambah catatan shift berikutnya, bukan menyunting baris lama.
 * Baris yang sudah tersimpan tetap dapat dibaca lewat daftar asuhan di
 * halaman profil.
 *
 * PROPS
 *   nursingCare  Object|null  baris asuhan TERAKHIR (is_latest)
 *   canWrite     Boolean      perawat + bidan + dokter boleh menulis
 *   storeUrl     String       URL POST
 *   authorName   String       nilai awal kolom "Nama Perawat / Bidan"
 *
 * EMITS
 *   saved  void
 */
import { ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import EmptyState from '@/Components/EmptyState.vue';

const props = defineProps({
    nursingCare: { type: Object, default: null },
    canWrite: { type: Boolean, default: false },
    storeUrl: { type: String, default: '' },
    authorName: { type: String, default: '' },
});

const emit = defineEmits(['saved']);

const DASH = '-';

/* prototype: ['Pagi', 'Siang', 'Sore', 'Malam'] */
const SHIFTS = ['Pagi', 'Siang', 'Sore', 'Malam'];

const editing = ref(false);

function blankForm() {
    return {
        assessment: '',
        problems: '',
        interventions: '',
        nurse: '',
        shift: 'Pagi',
    };
}

const form = useForm(blankForm());

function seed() {
    const row = props.nursingCare || {};

    form.defaults({
        assessment: row.assessment || '',
        problems: row.problems || '',
        interventions: row.interventions || '',
        nurse: row.nurse && row.nurse !== DASH ? row.nurse : props.authorName,
        shift: row.shift && row.shift !== DASH ? row.shift : 'Pagi',
    });

    form.reset();
    form.clearErrors();
}

watch(
    () => [props.nursingCare, props.authorName],
    () => {
        editing.value = false;
        seed();
    },
    { immediate: true },
);

function startEdit() {
    if (!props.canWrite) return;

    seed();
    editing.value = true;
}

function cancel() {
    editing.value = false;
    form.clearErrors();
}

function errorFor(field) {
    return form.errors && form.errors[field] ? String(form.errors[field]) : '';
}

function submit() {
    form.post(props.storeUrl, {
        preserveState: true,
        preserveScroll: true,
        onSuccess: () => {
            editing.value = false;
            emit('saved');
        },
    });
}

function blocks(row) {
    const items = [
        { tag: 'A', label: 'Assessment', value: row.assessment },
        { tag: 'P', label: 'Diagnosa Keperawatan', value: row.problems },
        { tag: 'I', label: 'Intervensi', value: row.interventions },
    ];

    return items.filter((item) => {
        const value = String(item.value || '').trim();

        return value !== '' && value !== DASH;
    });
}
</script>

<template>
    <!-- ================= FORMULIR ASUHAN (nursingForm) ================= -->
    <form v-if="editing" id="nursingForm" class="space-y-3" data-purpose="cppt-nursing-form" novalidate @submit.prevent="submit">
        <p class="text-[11px] font-bold uppercase text-slate-400">Formulir Asuhan Keperawatan / Bidan</p>

        <div class="grid gap-3 md:grid-cols-2">
            <div class="sm:col-span-2">
                <label class="mb-1 block text-[11px] font-bold uppercase text-slate-500" for="nAssessment">Assessment</label>
                <textarea id="nAssessment" v-model="form.assessment" rows="3" class="textarea" :class="{ 'input-error': errorFor('assessment') }"></textarea>
                <p v-if="errorFor('assessment')" class="mt-1 text-[11px] text-red-600">{{ errorFor('assessment') }}</p>
            </div>

            <div>
                <label class="mb-1 block text-[11px] font-bold uppercase text-slate-500" for="nProblems">Diagnosa Keperawatan</label>
                <textarea id="nProblems" v-model="form.problems" rows="3" class="textarea" :class="{ 'input-error': errorFor('problems') }"></textarea>
                <p v-if="errorFor('problems')" class="mt-1 text-[11px] text-red-600">{{ errorFor('problems') }}</p>
            </div>

            <div>
                <label class="mb-1 block text-[11px] font-bold uppercase text-slate-500" for="nInterventions">Intervensi</label>
                <textarea id="nInterventions" v-model="form.interventions" rows="3" class="textarea" :class="{ 'input-error': errorFor('interventions') }"></textarea>
                <p v-if="errorFor('interventions')" class="mt-1 text-[11px] text-red-600">{{ errorFor('interventions') }}</p>
            </div>

            <div>
                <label class="mb-1 block text-[11px] font-bold uppercase text-slate-500" for="nNurse">Nama Perawat / Bidan</label>
                <input id="nNurse" v-model="form.nurse" type="text" class="input" :class="{ 'input-error': errorFor('nurse') }">
                <p v-if="errorFor('nurse')" class="mt-1 text-[11px] text-red-600">{{ errorFor('nurse') }}</p>
            </div>

            <div>
                <label class="mb-1 block text-[11px] font-bold uppercase text-slate-500" for="nShift">Shift</label>
                <select id="nShift" v-model="form.shift" class="select" :class="{ 'input-error': errorFor('shift') }">
                    <option v-for="shift in SHIFTS" :key="shift" :value="shift">{{ shift }}</option>
                </select>
                <p v-if="errorFor('shift')" class="mt-1 text-[11px] text-red-600">{{ errorFor('shift') }}</p>
            </div>
        </div>

        <div class="flex justify-end gap-2 border-t border-slate-100 pt-2">
            <button
                type="button"
                class="rounded-lg border border-slate-300 px-4 py-2 text-xs font-semibold transition hover:bg-slate-50"
                data-action="cancel"
                @click="cancel"
            >
                Batal
            </button>
            <button
                type="submit"
                class="inline-flex items-center gap-2 rounded-lg bg-sky-600 px-4 py-2 text-xs font-semibold text-white shadow transition hover:bg-sky-700 disabled:opacity-60"
                data-purpose="cppt-nursing-submit"
                :disabled="form.processing"
            >
                <i class="fa-solid fa-floppy-disk" aria-hidden="true"></i>
                {{ form.processing ? 'Menyimpan...' : 'Simpan Asuhan' }}
            </button>
        </div>
    </form>

    <!-- ================= KONDISI KOSONG (emptyState) ================= -->
    <EmptyState
        v-else-if="!props.nursingCare"
        icon="fa-solid fa-user-nurse"
        title="Belum ada asuhan keperawatan / kebidanan."
        message="Asuhan diinput ulang setiap shift; yang tampil di sini adalah asuhan terakhir."
    >
        <button
            v-if="props.canWrite"
            type="button"
            class="no-print mt-4 inline-flex items-center gap-2 rounded-lg bg-sky-600 px-4 py-2 text-xs font-semibold text-white shadow transition hover:bg-sky-700"
            data-action="new-nursing"
            @click="startEdit"
        >
            <i class="fa-solid fa-plus" aria-hidden="true"></i>
            Tulis Asuhan
        </button>
    </EmptyState>

    <!-- ================= TAMPILAN (renderNursing) ================= -->
    <div v-else class="space-y-3" data-purpose="cppt-nursing-view">
        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 pb-3">
            <div>
                <p class="font-black text-sky-800">Asuhan Keperawatan / Bidan</p>
                <p class="text-[11px] text-slate-500">
                    {{ props.nursingCare.nurse || DASH }} &bull; Shift {{ props.nursingCare.shift || DASH }} &bull; {{ props.nursingCare.recordedAtLabel || DASH }}
                </p>
            </div>
            <button
                v-if="props.canWrite"
                type="button"
                class="no-print inline-flex items-center gap-2 rounded-lg border border-sky-600 px-3 py-1.5 text-xs font-semibold text-sky-700 transition hover:bg-sky-50"
                data-action="edit-nursing"
                @click="startEdit"
            >
                <i class="fa-solid fa-pen" aria-hidden="true"></i>
                Catatan Baru
            </button>
        </div>

        <div class="grid gap-3 text-sm md:grid-cols-3">
            <div
                v-for="block in blocks(props.nursingCare)"
                :key="block.tag"
                class="rounded-lg border-l-4 border-sky-500 bg-sky-50/60 px-3.5 py-2.5"
                :data-soap="block.tag"
            >
                <p class="text-xs font-black text-sky-800">{{ block.tag }} &mdash; {{ block.label }}</p>
                <p class="mt-1 whitespace-pre-line text-sm text-slate-700">{{ block.value }}</p>
            </div>
        </div>
    </div>
</template>

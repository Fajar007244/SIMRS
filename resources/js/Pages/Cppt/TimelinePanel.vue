<script setup>
/**
 * Cppt/TimelinePanel.vue - tab "Timeline" pada halaman CPPT.
 *
 * Markup daftar catatan progres dipindah apa adanya dari Cppt.vue lama ke sini
 * supaya halaman dapat memuat lima tab tanpa membengkak. SectionCard, FilterBar,
 * EmptyState, PrintButton, saringan jenis catatan, pencarian, dan tombol
 * Refresh semuanya tetap sama; yang baru hanyalah tombol "+ Tambah CPPT" dan
 * modalnya (Cppt/ModalCatatanCppT.vue).
 *
 * `notes` datang dari AdmissionService::getCppt() dan sudah terurut dari yang
 * paling lama (noted_at lalu order_number), jadi tidak ada pengurutan ulang di
 * sini. Belum ada tombol hapus catatan: modul ini hanya menambah.
 *
 * PROPS
 *   notes      Array   baris catatan dari AdmissionService::getCppt()
 *   canWrite   Boolean hanya dokter boleh menulis CPPT
 *   storeUrl   String  URL POST catatan
 *   patient    Object  banner pasien untuk strip identitas modal
 *   user       Object  props.auth.user untuk kotak penulis pada modal
 *
 * EMITS
 *   saved  void  satu modul lain di halaman ini berhasil menyimpan
 */
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import SectionCard from '@/Components/SectionCard.vue';
import FilterBar from '@/Components/FilterBar.vue';
import PrintButton from '@/Components/PrintButton.vue';
import EmptyState from '@/Components/EmptyState.vue';
import ModalCatatanCppT from './ModalCatatanCppT.vue';
import { isBlank } from '@/composables/useFormatting';

const props = defineProps({
    notes: { type: Array, default: () => [] },
    canWrite: { type: Boolean, default: false },
    storeUrl: { type: String, default: '' },
    patient: { type: Object, default: null },
    user: { type: Object, default: null },
});

const emit = defineEmits(['saved']);

const DASH = '-';
const SOAP = [
    { key: 'subjective', tag: 'S', label: 'Keluhan & Riwayat' },
    { key: 'objective', tag: 'O', label: 'Temuan Objektif' },
    { key: 'assessment', tag: 'A', label: 'Penilaian' },
    { key: 'plan', tag: 'P', label: 'Rencana Tatalaksana' },
];

const search = ref('');
const noteType = ref('');
const refreshing = ref(false);
const formOpen = ref(false);

const noteTypes = computed(() => {
    const types = [];

    for (const note of props.notes) {
        const label = isBlank(note.noteType) ? '' : String(note.noteType);
        if (label !== '' && !types.includes(label)) types.push(label);
    }

    return types.sort((a, b) => a.localeCompare(b));
});

const filteredNotes = computed(() => {
    const term = search.value.trim().toLowerCase();
    const type = noteType.value;

    return props.notes.filter((note) => {
        if (type !== '' && String(note.noteType ?? '') !== type) return false;
        if (term === '') return true;

        return [note.authorName, note.authorRoleLabel, note.authorSpecialty, note.noteType, note.subjective, note.objective, note.assessment, note.plan]
            .some((field) => String(field ?? '').toLowerCase().includes(term));
    });
});

function soapBlocks(note) {
    return SOAP
        .map((field) => ({ tag: field.tag, label: field.label, value: note?.[field.key] }))
        .filter((block) => !isBlank(block.value));
}

function noteTitle(note) {
    return isBlank(note.noteType) ? 'CPPT' : note.noteType;
}

function noteMeta(note) {
    const parts = [note.notedAtLabel || DASH, note.authorName || DASH];

    if (!isBlank(note.authorRoleLabel)) parts.push(note.authorRoleLabel);
    if (!isBlank(note.authorSpecialty)) parts.push(note.authorSpecialty);

    return parts.join(' \u2022 ');
}

function refresh() {
    if (refreshing.value) return;
    refreshing.value = true;

    router.reload({
        onFinish: () => {
            refreshing.value = false;
        },
    });
}

function openCreate() {
    formOpen.value = true;
}

/**
 * Setelah simpan, muat ulang halaman supaya timeline, keempat panel tab, dan
 * enam kartu statistik ikut segar. Muat penuh (bukan `only`) dipilih karena
 * enam kartu statistik bergantung pada hitungan diagnosa / prosedur /
 * asuhan yang semuanya ikut berubah.
 */
function onSaved() {
    emit('saved');

    router.reload();
}
</script>

<template>
    <SectionCard
        title="Timeline Catatan Progress"
        subtitle="Catatan CPPT bertanda tangan, urutan kronologis dari yang paling lama."
        icon="fa-user-doctor"
        tone="emerald"
        :padded="false"
    >
        <FilterBar
            v-model:search="search"
            :result-count="filteredNotes.length"
            search-placeholder="Cari penulis, jenis, atau isi catatan..."
            icon="fa-filter"
            :refreshing="refreshing"
            refresh-label="Refresh"
            @refresh="refresh"
        >
            <select v-model="noteType" class="select w-44 py-1.5 text-xs" aria-label="Saring jenis catatan">
                <option value="">Semua jenis catatan</option>
                <option v-for="type in noteTypes" :key="type" :value="type">{{ type }}</option>
            </select>
            <template #actions>
                <button
                    v-if="props.canWrite"
                    type="button"
                    class="no-print inline-flex items-center gap-1.5 rounded-md bg-emerald-600 px-3.5 py-1.5 text-xs font-bold text-white shadow-sm transition hover:bg-emerald-500"
                    data-purpose="cppt-note-new"
                    @click="openCreate"
                >
                    <i class="fa-solid fa-plus" aria-hidden="true"></i>
                    Tambah CPPT
                </button>
                <PrintButton label="Cetak" size="sm" tone="secondary" />
            </template>
        </FilterBar>

        <div class="p-4">
            <EmptyState
                v-if="props.notes.length === 0"
                icon="fa-file-lines"
                title="Belum ada catatan CPPT"
                message="Belum ada catatan progres yang ditandatangani untuk episode perawatan ini."
            />

            <EmptyState
                v-else-if="filteredNotes.length === 0"
                icon="fa-magnifying-glass"
                title="Tidak ada catatan yang cocok"
                message="Ubah kata kunci pencarian atau saringan jenis catatan."
            />

            <div
                v-else
                class="relative ml-3 space-y-4 border-l-2 border-slate-200"
                data-purpose="cppt-timeline"
            >
                <div
                    v-for="note in filteredNotes"
                    :key="note.id"
                    class="relative pl-6"
                    data-purpose="cppt-timeline-item"
                >
                    <span
                        class="absolute -left-[11px] top-1 flex h-5 w-5 items-center justify-center rounded-full bg-emerald-500 text-[9px] text-white ring-4 ring-white"
                    >
                        <i class="fa-solid fa-user-doctor" aria-hidden="true"></i>
                    </span>
                    <div class="rounded-lg border border-slate-200 bg-slate-50/60 px-3.5 py-2.5">
                        <p class="text-xs font-black text-emerald-800">
                            {{ noteTitle(note) }}
                            <span class="ml-1 font-mono text-[10px] font-bold text-slate-400">#{{ note.order }}</span>
                        </p>
                        <p class="mb-1 text-[10px] text-slate-500">{{ noteMeta(note) }}</p>

                        <div v-if="soapBlocks(note).length" class="grid gap-3 md:grid-cols-2">
                            <div
                                v-for="block in soapBlocks(note)"
                                :key="block.tag"
                                class="rounded-lg border-l-4 border-emerald-500 bg-emerald-50/60 px-3.5 py-2.5"
                                data-purpose="cppt-soap-block"
                                :data-soap="block.tag"
                            >
                                <p class="text-xs font-black text-emerald-800">
                                    {{ block.tag }} &mdash; {{ block.label }}
                                </p>
                                <p class="mt-1 whitespace-pre-line text-sm text-slate-700">{{ block.value }}</p>
                            </div>
                        </div>

                        <p v-else class="text-sm text-slate-500">Catatan ini tidak memuat isian S/O/A/P.</p>
                    </div>
                </div>
            </div>
        </div>

        <template #footer>
            <span>{{ props.notes.length }} catatan</span>
            <span v-if="filteredNotes.length !== props.notes.length">menampilkan {{ filteredNotes.length }}</span>
        </template>
    </SectionCard>

    <!-- ============ FORMULIR TAMBAH CATATAN CPPT ============ -->
    <ModalCatatanCppT
        :open="formOpen"
        :store-url="props.storeUrl"
        :patient="props.patient"
        :user="props.user"
        @close="formOpen = false"
        @saved="onSaved"
    />
</template>

<script setup>
/**
 * FlowsheetDeleteConfirmModal.vue - konfirmasi hapus SATU baris flowsheet.
 *
 * phase1 memakai dialog konfirmasi browser bawaan (script
 * data-purpose="modal-and-calc-handlers", blok submit: "Sudah ada observasi
 * tersimpan untuk ... Tekan OK untuk MENIMPA"). Aturan proyek melarang dialog
 * browser, jadi kedua tempat itu dipindahkan ke dalam aplikasi:
 *
 *   - slot jam yang sudah terisi  -> TIDAK perlu dialog. ObservationService
 *     bersifat UPSERT, jadi server mengembalikan flag `duplicated` dan
 *     controller membalas flash "Slot jam ini sudah ada, data diperbarui."
 *     Dialog hapus-lalu-simpan-ulang di prototype tidak diperlukan, dan
 *     menghilangkannya justru menghilangkan kemungkinan baris dobel.
 *   - hapus satu baris           -> modal ini. Tombolnya jadi dua langkah:
 *     baris yang akan dihapus ditampilkan lengkap (tanggal, jam, EWS, petugas)
 *     supaya tidak ada klik buta, dan server membalas flash error berbahasa
 *     Indonesia bila slot ternyata sudah kosong (mis. dihapus dari tab lain).
 *
 * PROPS
 *   open       Boolean
 *   row        Object|null  baris flowsheet yang akan dihapus
 *   processing Boolean     request sedang berjalan
 *
 * EMITS
 *   close    void
 *   confirm  void
 */
import Modal from '@/Components/Modal.vue';
import EwsBadge from '@/Components/EwsBadge.vue';
import { isBlank } from '@/composables/useFormatting';

const props = defineProps({
    open: { type: Boolean, default: false },
    row: { type: Object, default: null },
    processing: { type: Boolean, default: false },
});

const emit = defineEmits(['close', 'confirm']);

function value(source, fallback = '-') {
    if (!props.row) return fallback;
    if (isBlank(source)) return fallback;

    return String(source);
}
</script>

<template>
    <Modal
        :open="props.open"
        title="Hapus observasi EWS ini?"
        subtitle="Tindakan ini tidak dapat dibatalkan."
        icon="fa-solid fa-trash-can"
        size="sm"
        :close-on-escape="!props.processing"
        :close-on-backdrop="!props.processing"
        data-purpose="flowsheet-delete-modal"
        @close="emit('close')"
    >
        <div class="space-y-3 text-xs text-slate-700">
            <p>
                Observasi pada slot jam berikut akan dihapus permanen dari lembar observasi
                episode ini, beserta koreksi pemberian obat dan jawaban bundle yang menempel
                pada baris tersebut.
            </p>

            <div
                v-if="props.row"
                class="space-y-2 rounded-lg border border-red-200 bg-red-50 px-3 py-2 font-semibold text-red-800"
                data-purpose="flowsheet-delete-detail"
            >
                <p class="flex items-center justify-between gap-2">
                    <span class="text-red-700">Waktu</span>
                    <span class="font-mono font-bold">
                        {{ value(props.row.recordedAtLabel) }}
                    </span>
                </p>
                <p class="flex items-center justify-between gap-2">
                    <span class="text-red-700">Tekanan darah / Nadi</span>
                    <span class="font-mono font-bold">
                        {{ value(props.row.bpLabel) }} / {{ value(props.row.hr) }}
                    </span>
                </p>
                <p class="flex items-center justify-between gap-2">
                    <span class="text-red-700">Skor EWS</span>
                    <EwsBadge :total="props.row.ewsTotal" :risk="props.row.ewsRisk" size="sm" />
                </p>
                <p class="flex items-center justify-between gap-2">
                    <span class="text-red-700">Dicatat oleh</span>
                    <span class="truncate font-bold">{{ value(props.row.recordedBy) }}</span>
                </p>
            </div>

            <p class="text-[11px] text-slate-500">
                Slot jam lain pada tanggal yang sama tidak terpengaruh. Bila baris ini sudah
                dihapus dari tab lain, halaman akan menampilkan pemberitahuan bahwa slot tidak
                ditemukan.
            </p>
        </div>

        <template #footer>
            <div class="flex flex-wrap items-center justify-end gap-2">
                <button
                    type="button"
                    class="btn btn-secondary btn-sm"
                    :disabled="props.processing"
                    data-purpose="flowsheet-delete-cancel"
                    @click="emit('close')"
                >
                    Batal
                </button>
                <button
                    type="button"
                    class="btn btn-danger btn-sm"
                    :disabled="props.processing"
                    data-purpose="flowsheet-delete-confirm"
                    @click="emit('confirm')"
                >
                    <i class="fa-solid fa-trash-can" aria-hidden="true"></i>
                    {{ props.processing ? 'Menghapus...' : 'Ya, hapus observasi' }}
                </button>
            </div>
        </template>
    </Modal>
</template>
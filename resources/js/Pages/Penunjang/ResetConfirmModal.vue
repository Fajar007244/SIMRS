<script setup>
/**
 * ResetConfirmModal.vue - konfirmasi dalam aplikasi untuk aksi destruktif
 * "Kosongkan data penunjang".
 *
 * phase1 memakai window.confirm(); di sini diganti Modal.vue (bukan dialog
 * browser) sesuai aturan proyek: tidak boleh ada alert/prompt/confirm.
 * Konfirmasi BERLAPIS DUA:
 *   1. klien  - Modal ini, pengguna harus menekan tombol konfirmasi
 *   2. server - field `confirm=1` dikirim pada POST, tanpa itu PenunjangController
 *               membalas 403
 */
import Modal from '@/Components/Modal.vue';

defineProps({
    open: { type: Boolean, default: false },
    group: { type: String, default: '' },
    label: { type: String, default: '' },
    count: { type: Number, default: 0 },
    processing: { type: Boolean, default: false },
});

const emit = defineEmits(['close', 'confirm']);
</script>

<template>
    <Modal
        :open="open"
        :title="`Kosongkan data ${label || 'penunjang'}`"
        subtitle="Tindakan ini tidak dapat dibatalkan."
        icon="fa-solid fa-triangle-exclamation"
        size="sm"
        @close="emit('close')"
    >
        <div class="space-y-3 text-xs text-slate-700">
            <p>
                Seluruh hasil <strong>{{ label || 'penunjang' }}</strong> pada episode perawatan ini
                akan dih permanen.
            </p>
            <p class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 font-semibold text-red-800">
                <i class="fa-solid fa-circle-exclamation mr-1.5" aria-hidden="true"></i>
                {{ count }} baris saat ini akan dihapus. Cakupan aksi ini hanya satu kelompok, bukan seluruh data penunjang.
            </p>
            <p class="text-[11px] text-slate-500">
                Gunakan tombol hapus pada baris tabel bila hanya ingin membatalkan satu hasil.
            </p>
        </div>

        <template #footer>
            <div class="flex flex-wrap items-center justify-end gap-2">
                <button type="button" class="btn btn-secondary btn-sm" @click="emit('close')">Batal</button>
                <button type="button" class="btn btn-danger btn-sm" :disabled="processing" @click="emit('confirm')">
                    <i class="fa-solid fa-rotate-left" aria-hidden="true"></i>
                    {{ processing ? 'Menghapus...' : 'Ya, kosongkan' }}
                </button>
            </div>
        </template>
    </Modal>
</template>
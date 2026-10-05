<script setup>
/**
 * LoadingOverlay.vue - overlay penuh untuk mutasi lama (simpan observasi,
 * hapus baris, kirim CPPT).
 *
 * PROPS
 *   show    Boolean default false   controla visibilitas
 *   label   String  default 'Memproses...'
 *   message String  default ''       baris kedua di bawah spinner
 *   inline  Boolean default false    true = hanya menutupi elemen terluar
 *                                      (absolute), false = fixed full layar
 *
 * SLOTS: `default` (konten di dalam panel, opsional)
 * EMITS : (tidak ada)
 */
const props = defineProps({
    show: { type: Boolean, default: false },
    label: { type: String, default: 'Memproses...' },
    message: { type: String, default: '' },
    inline: { type: Boolean, default: false },
});
</script>

<template>
    <Transition name="ew-fade">
        <div
            v-if="props.show"
            class="z-[60] flex items-center justify-center bg-slate-900/50 backdrop-blur-[1px]"
            :class="props.inline ? 'absolute inset-0' : 'fixed inset-0'"
            role="status"
            aria-live="polite"
            aria-busy="true"
            data-purpose="loading-overlay"
        >
            <div class="anim-pop flex max-w-xs flex-col items-center gap-3 rounded-xl border border-slate-200 bg-white px-6 py-5 text-center shadow-2xl">
                <i class="fa-solid fa-circle-notch text-2xl text-sky-600 anim-spin" aria-hidden="true"></i>
                <p class="text-xs font-bold text-slate-800">{{ props.label }}</p>
                <p v-if="props.message" class="text-[11px] leading-relaxed text-slate-500">{{ props.message }}</p>
                <slot></slot>
            </div>
        </div>
    </Transition>
</template>

<style scoped>
.ew-fade-enter-active,
.ew-fade-leave-active {
    transition: opacity 0.15s ease-out;
}
.ew-fade-enter-from,
.ew-fade-leave-to {
    opacity: 0;
}
</style>
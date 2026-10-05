<script setup>
/**
 * EmptyState.vue - blok "belum ada data" seperti phase1.
 * Port literal dari:
 *   bundles.html  : <div class="rounded-lg border border-slate-200 bg-slate-50 p-6 text-center text-slate-500 text-xs">
 *   observasi.html: <div class="rounded-lg border border-dashed border-slate-300 bg-slate-50 px-3 py-4 text-center text-xs text-slate-500">
 *   farmasi.html  : <p class="text-xs text-slate-500">Belum ada ...</p>
 *
 * PROPS
 *   icon    String  default 'fa-inbox'   kelas Font Awesome solids
 *   title   String  WAJIB                judul status
 *   message String  default ''           keterangan satu-dua baris
 *
 * SLOTS: (tidak ada)
 * EMITS : (tidak ada)
 */
defineProps({
    icon: { type: String, default: 'fa-inbox' },
    title: { type: String, required: true },
    message: { type: String, default: '' },
});
</script>

<template>
    <div class="rounded-lg border border-dashed border-slate-300 bg-slate-50 px-3 py-6 text-center" data-purpose="empty-state">
        <i class="fa-solid text-slate-400 text-lg" :class="icon" aria-hidden="true"></i>
        <p class="mt-2 text-xs font-semibold text-slate-700">{{ title }}</p>
        <p v-if="message" class="mx-auto mt-1 max-w-md text-[11px] leading-relaxed text-slate-500">{{ message }}</p>
        <slot></slot>
    </div>
</template>
<script setup>
/*
 * CATATAN ICON: nilai prop icon dipakai apa adanya. Prefix a-solid HANYA
 * ditambahkan bila nilainya tidak mengandung substring a- sama sekali, jadi
 * a-pills TIDAK menjadi a-solid fa-pills (panggil dengan bentuk lengkap
 * bila butuh gaya fontawesome tertentu).
 */
/**
 * SectionCard.vue - pembungkus kerja utama untuk semua panel klinis.
 * Port dari markup phase1:
 *   <section class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
 *   <div class="flex flex-wrap items-center justify-between mb-3 border-b border-slate-100 pb-2 gap-2">
 *     <div class="flex items-center gap-2">
 *       <div class="w-7 h-7 rounded-md bg-teal-100 text-teal-700 ..."><i class="fa-solid ..."></i></div>
 *       <div><h2 class="font-bold text-slate-900 text-sm">...</h2>
 *            <p class="text-[11px] text-slate-500">...</p></div>
 *     </div>
 *     <div class="flex items-center gap-2"> ...tombol... </div>
 *   </div>
 *
 * PROPS
 *   title    String  WAJIB
 *   subtitle String  default ''
 *   icon     String  default ''       kelas ikon FontAwesome utuh, mis. 'fa-solid fa-pills'.
 *   tone     String  default 'slate'  slate|sky|emerald|teal|amber|orange|red|
 *                                    rose|purple|indigo|yellow|hospital
 *   noPrint  Boolean default false     tambahkan .no-print pada kartu
 *   padded   Boolean default true      false = header/body tanpa padding
 *
 * SLOTS
 *   actions  Tombol/filter di kanan header
 *   default  Isi body
 *   footer   Baris bawah (jumlah baris, total, dsb)
 *
 * EMITS: (tidak ada)
 */
import { computed } from 'vue';
import { tone } from '../tone';

const props = defineProps({
    title: { type: String, required: true },
    subtitle: { type: String, default: '' },
    icon: { type: String, default: '' },
    tone: {
        type: String,
        default: 'slate',
        validator: (value) => [
            'slate', 'sky', 'emerald', 'teal', 'amber', 'orange', 'red',
            'rose', 'purple', 'indigo', 'yellow', 'hospital',
        ].includes(value),
    },
    noPrint: { type: Boolean, default: false },
    padded: { type: Boolean, default: true },
});

const t = computed(() => tone(props.tone));

const iconClass = computed(() => {
    const raw = (props.icon || '').trim();
    if (!raw) return '';
    return raw.includes('fa-') ? raw : `fa-solid ${raw}`;
});

const hasHeader = computed(() => Boolean(props.title || props.icon || props.$slots.actions));
</script>

<template>
    <section
        class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm print-border"
        :class="[props.noPrint ? 'no-print' : '']"
        data-purpose="section-card"
    >
        <div
            v-if="hasHeader"
            class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 pb-2"
            :class="props.padded ? 'p-4' : ''"
        >
            <div class="flex min-w-0 items-center gap-2">
                <div
                    v-if="iconClass"
                    class="flex h-7 w-7 shrink-0 items-center justify-center rounded-md text-xs font-bold"
                    :class="[t.soft, t.softText]"
                >
                    <i :class="iconClass" aria-hidden="true"></i>
                </div>
                <div class="min-w-0">
                    <h2 class="truncate text-sm font-bold text-slate-900">{{ props.title }}</h2>
                    <p v-if="props.subtitle" class="text-[11px] text-slate-500">{{ props.subtitle }}</p>
                </div>
            </div>
            <div v-if="$slots.actions" class="flex flex-wrap items-center gap-2 no-print">
                <slot name="actions"></slot>
            </div>
        </div>

        <div :class="props.padded ? 'p-4' : ''">
            <slot></slot>
        </div>

        <div
            v-if="$slots.footer"
            class="flex flex-wrap items-center justify-between gap-2 border-t border-slate-200 px-4 py-2.5 text-xs text-slate-500"
        >
            <slot name="footer"></slot>
        </div>
    </section>
</template>
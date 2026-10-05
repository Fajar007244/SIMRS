<script setup>
/*
 * CATATAN ICON: nilai prop icon dipakai apa adanya. Prefix a-solid HANYA
 * ditambahkan bila nilainya tidak mengandung substring a- sama sekali, jadi
 * a-pills TIDAK menjadi a-solid fa-pills (panggil dengan bentuk lengkap
 * bila butuh gaya fontawesome tertentu).
 */
/**
 * StatCard.vue - kartu statistik dengan garis aksen kiri + ikon berwarna.
 * Port dari kartu statistik phase1 (bundles/farmasi/cppt/penunjang/observasi):
 *   <div class="bg-white border-l-4 border-l-teal-500 rounded-xl p-3.5 shadow-sm border border-slate-200">
 *     <div class="flex justify-between items-center text-slate-500 mb-1">
 *       <span class="text-[10px] uppercase tracking-wider font-bold">Label</span>
 *       <i class="fa-solid fa-chart-pie text-teal-500"></i>
 *     </div>
 *     <div class="font-mono font-black text-2xl text-slate-900">0</div>
 *     <div class="text-[10px] text-slate-400 mt-0.5">keterangan</div>
 *   </div>
 *
 * PROPS
 *   label   [String, Number] WAJIB   teks label (uppercase otomatis)
 *   value   [String, Number] WAJIB   nilai utama
 *   sub     String  default ''       baris kecil di bawah nilai
 *   detail  String  default ''       teks tooltips / keterangan tambahan
 *   icon    String  default ''       kelas ikon FontAwesome utuh, mis. 'fa-solid fa-pills'.
 *   tone    String  default 'sky'    tone yang valid (lihat tone.js)
 *   mono    Boolean default false    pakai font-mono untuk nilai
 *   pulse   Boolean default false    animasi denyut pada nilai (kritis)
 *
 * SLOTS: `footer` (baris tambahan di bawah `sub`)
 * EMITS : (tidak ada)
 *
 * SEMUA PROPS OPSIONAL besides label & value nullable: null dirender '-'
 * oleh Vue secara natural.
 */
import { computed } from 'vue';
import { tone } from '../tone';

const props = defineProps({
    label: { type: [String, Number], required: true },
    value: { type: [String, Number], required: true },
    sub: { type: String, default: '' },
    detail: { type: String, default: '' },
    icon: { type: String, default: '' },
    tone: {
        type: String,
        default: 'sky',
        validator: (value) => [
            'slate', 'sky', 'emerald', 'teal', 'amber', 'orange', 'red',
            'rose', 'purple', 'indigo', 'yellow', 'hospital',
        ].includes(value),
    },
    mono: { type: Boolean, default: false },
    pulse: { type: Boolean, default: false },
});

const t = computed(() => tone(props.tone));

const iconClass = computed(() => {
    const raw = (props.icon || '').trim();
    if (!raw) return '';
    return raw.includes('fa-') ? raw : `fa-solid ${raw}`;
});
</script>

<template>
    <div
        class="rounded-xl border border-l-4 border-slate-200 bg-white p-3.5 shadow-sm"
        :class="t.accent"
        :title="props.detail || undefined"
        data-purpose="stat-card"
    >
        <div class="mb-1 flex items-center justify-between gap-2 text-slate-500">
            <span class="truncate text-[10px] font-bold uppercase tracking-wider">{{ props.label }}</span>
            <i v-if="iconClass" class="shrink-0" :class="[iconClass, t.icon]" aria-hidden="true"></i>
        </div>
        <div
            class="truncate text-2xl font-black"
            :class="[props.mono ? 'font-mono' : '', t.value, props.pulse ? 'pulse-live' : '']"
        >
            {{ props.value }}
        </div>
        <div v-if="props.sub || $slots.footer" class="mt-0.5 text-[10px] text-slate-400">
            <span v-if="props.sub">{{ props.sub }}</span>
            <slot name="footer"></slot>
        </div>
    </div>
</template>
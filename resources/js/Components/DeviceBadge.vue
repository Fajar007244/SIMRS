<script setup>
/**
 * DeviceBadge.vue - badge lama pemakaian perangkat invasif.
 * Port dari phase1/bundles.html renderDayBadges() + renderDevices():
 *   <span class="text-[10px] bg-sky-100 text-sky-700 px-1.5 py-0.5 rounded font-mono font-bold">ETT Hari #-</span>
 *   <span class="text-[10px] bg-amber-100 text-amber-700 border border-amber-200 ...">Hari #3 &bull; perlu review</span>
 *   <span class="text-[10px] bg-emerald-100 text-emerald-700 ...">Hari #3</span>
 *
 * PROPS
 *   label       String  WAJIB            'ETT' | 'CVC' | 'DC' | teks bebas
 *   days        Number  default null     null -> tampilkan '#-'
 *   needsReview Boolean default false    gaya peringatan amber + "perlu review"
 *
 * SLOTS: (tidak ada)
 * EMITS : (tidak ada)
 *
 * `label` boleh 'ETT', 'ETT Hari', atau 'ETT Hari #'. Komponen menambah
 * "Hari #N" sendiri bila label belum memuatnya.
 */
import { computed } from 'vue';

const props = defineProps({
    label: { type: String, required: true },
    days: { type: Number, default: null },
    needsReview: { type: Boolean, default: false },
});

const dayText = computed(() => {
    const n = Number(props.days);
    return Number.isFinite(n) ? String(n) : '-';
});

/** Buang frasa "Hari" / "#N" yang mungkin sudah ada di label. */
const baseLabel = computed(() =>
    String(props.label || '')
        .replace(/\s*Hari\s*#?\s*\d*\s*$/i, '')
        .replace(/\s*#\s*\d+\s*$/, '')
        .trim() || String(props.label || ''),
);
</script>

<template>
    <span
        class="inline-flex items-center gap-1 whitespace-nowrap rounded px-1.5 py-0.5 font-mono text-[10px] font-bold"
        :class="props.needsReview
            ? 'bg-amber-100 text-amber-700 border border-amber-200'
            : (props.days === null || props.days === undefined
                ? 'bg-slate-100 text-slate-500'
                : 'bg-emerald-100 text-emerald-700')"
        data-purpose="device-badge"
    >
        <span>{{ baseLabel }} Hari #{{ dayText }}</span>
        <span v-if="props.needsReview">&bull; perlu review</span>
    </span>
</template>
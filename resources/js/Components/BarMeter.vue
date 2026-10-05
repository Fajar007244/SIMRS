<script setup>
/**
 * BarMeter.vue - bar komposisi volume per kategori obat / item lain.
 * Port dari renderCategoryBars() phase1/farmasi.html:
 *   <div class="grid grid-cols-12 items-center gap-2">
 *     <div class="col-span-12 sm:col-span-3 text-xs font-semibold text-slate-700 truncate">Kategori</div>
 *     <div class="col-span-8 sm:col-span-6 h-3 rounded-full bg-slate-100 border border-slate-200 overflow-hidden">
 *       <div class="h-full rounded-full bg-purple-500" style="width:42%"></div>
 *     </div>
 *     <div class="col-span-4 sm:col-span-3 text-right font-mono text-xs text-slate-700">
 *       <span class="font-bold">1.234 mL</span> <span class="text-slate-400">(42%)</span>
 *     </div>
 *   </div>
 *
 * PROPS
 *   label   String  WAJIB        nama kategori / item
 *   value   Number  default 0    nilai absolut (mis. mL)
 *   max     Number  default null Denominator opsional. Bila null, `percent`
 *                          yang dipakai. Bila keduanya null, percent = 0.
 *   percent Number  default null Persentase 0-100. Bila null DAN `max`
 *                          tersedia, percent = value / max * 100.
 *   tone    String  default 'sky'  tone untuk bar (lihat tone.js), atau
 *                             'auto' -> ambang percentTone()
 *   format  Function default null  fn(value, percent) -> string untuk kolom
 *                             kanan. Default: "1.234 mL (42%)"
 *
 * SLOTS: (tidak ada)
 * EMITS : (tidak ada)
 */
import { computed } from 'vue';
import { percentTone, tone as toneOf } from '../tone';
import { formatNumber } from '../composables/useFormatting';

const props = defineProps({
    label: { type: String, required: true },
    value: { type: Number, default: 0 },
    max: { type: Number, default: null },
    percent: { type: Number, default: null },
    tone: { type: String, default: 'sky' },
    format: { type: Function, default: null },
});

const safeValue = computed(() => (Number.isFinite(Number(props.value)) ? Number(props.value) : 0));

const safePercent = computed(() => {
    if (Number.isFinite(Number(props.percent))) return Number(props.percent);
    const max = Number(props.max);
    if (Number.isFinite(max) && max > 0) return (safeValue.value / max) * 100;
    return 0;
});

const barClass = computed(() => {
    if (props.tone === 'auto') return percentTone(safePercent.value).fill;
    return toneOf(props.tone).fill;
});

/** Bar dapat sisa 2% supaya nilai kecil tetap terlihat, seperti phase1. */
const width = computed(() => {
    const p = safePercent.value;
    if (p <= 0) return '0%';
    return `${Math.min(100, Math.max(2, p))}%`;
});

const rightText = computed(() => {
    if (typeof props.format === 'function') {
        try {
            return props.format(safeValue.value, safePercent.value);
        } catch (error) {
            return '-';
        }
    }
    const amount = formatNumber(safeValue.value);
    return `${amount === '-' ? '-' : `${amount} mL`} (${Math.round(safePercent.value)}%)`;
});
</script>

<template>
    <div class="grid grid-cols-12 items-center gap-2" data-purpose="bar-meter">
        <div class="col-span-12 truncate text-xs font-semibold text-slate-700 sm:col-span-3" :title="props.label">
            {{ props.label }}
        </div>
        <div class="col-span-8 h-3 overflow-hidden rounded-full border border-slate-200 bg-slate-100 sm:col-span-6">
            <div class="anim-grow-x h-full rounded-full" :class="barClass" :style="{ width }"></div>
        </div>
        <div class="col-span-4 text-right font-mono text-xs text-slate-700 sm:col-span-3">
            {{ rightText }}
        </div>
    </div>
</template>
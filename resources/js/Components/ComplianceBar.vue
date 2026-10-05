<script setup>
/**
 * ComplianceBar.vue - batang persentase kepatuhan bundle HAIs.
 * Ambang tone disalin dari percentTone() phase1/bundles.html:
 *   >= 100 emerald | >= 80 sky | >= 50 amber | selain itu red
 *
 * PROPS
 *   percent    Number  default 0    0-100. Nilai di luar 100 tetap bisa,
 *                                       bar penuh, teks menampilkan aslinya.
 *   tone       String  default 'auto'
 *               'auto'  -> pakai ambang di atas (perilaku yang diharapkan)
 *               atau salah satu: slate|sky|emerald|teal|amber|orange|red|
 *                                 rose|purple|indigo|yellow|hospital
 *   height     Number  default 8     tinggi track dalam px
 *   showLabel  Boolean default true   tampilkan angka persen di kanan
 *   labelText  String  default ''     teks sebelum persen, mis. "VAP"
 *   track      Boolean default true    false = track transparan
 *
 * SLOTS: (tidak ada)
 * EMITS : (tidak ada)
 *
 * CATATAN KONTRAK: nilai default `tone` sengaja 'auto' (bukan 'sky') supaya
 * ambang 100/80/50 benar-benar berlaku. Kalau butuh warna tetap, tulis
 * tone="sky" secara eksplisit - tidak ada halaman yang bisa rusak.
 */
import { computed } from 'vue';
import { percentTone, tone as toneOf } from '../tone';

const props = defineProps({
    percent: { type: Number, default: 0 },
    tone: {
        type: String,
        default: 'auto',
        validator: (value) =>
            value === 'auto' || [
                'slate', 'sky', 'emerald', 'teal', 'amber', 'orange', 'red',
                'rose', 'purple', 'indigo', 'yellow', 'hospital',
            ].includes(value),
    },
    height: { type: Number, default: 8 },
    showLabel: { type: Boolean, default: true },
    labelText: { type: String, default: '' },
    track: { type: Boolean, default: true },
});

const resolved = computed(() => {
    if (props.tone && props.tone !== 'auto') {
        const t = toneOf(props.tone);
        return { fill: t.fill, text: t.value, soft: t.soft, softText: t.softText, softBorder: t.softBorder };
    }
    const p = percentTone(props.percent);
    return { fill: p.fill, text: p.text, soft: p.chip, softText: '', softBorder: '' };
});

const safePercent = computed(() => {
    const n = Number(props.percent);
    return Number.isFinite(n) ? n : 0;
});

/** Bar selalu punya sisa kecil saat ada nilai, seperti phase1 (min 2%). */
const width = computed(() => {
    const n = safePercent.value;
    if (n <= 0) return '0%';
    return `${Math.min(100, Math.max(2, n))}%`;
});
</script>

<template>
    <div class="flex items-center gap-2" data-purpose="compliance-bar">
        <span v-if="props.labelText" class="shrink-0 text-[10px] font-bold uppercase tracking-wider text-slate-400">
            {{ props.labelText }}
        </span>
        <div
            class="w-full overflow-hidden rounded-full border border-slate-200"
            :class="props.track ? 'bg-slate-100' : 'bg-transparent'"
            :style="{ height: `${Math.max(2, Number(props.height) || 8)}px` }"
            role="progressbar"
            :aria-valuenow="safePercent"
            aria-valuemin="0"
            aria-valuemax="100"
        >
            <div class="anim-grow-x h-full rounded-full" :class="resolved.fill" :style="{ width }"></div>
        </div>
        <span
            v-if="props.showLabel"
            class="shrink-0 rounded px-1.5 py-0.5 font-mono text-[10px] font-bold"
            :class="[resolved.soft, resolved.text]"
        >
            {{ Number.isFinite(Number(props.percent)) ? `${Number(props.percent)}%` : '-' }}
        </span>
    </div>
</template>
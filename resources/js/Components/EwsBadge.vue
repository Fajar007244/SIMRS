<script setup>
/**
 * EwsBadge.vue - badge skor EWS 4 tingkat.
 * Port PERSIS dari ewsBadgeClasses() di phase1/app-context.js dan
 * EwsScoringService::badgeClasses() di backend:
 *   emergency -> bg-red-600   text-white    border-red-700
 *   high      -> bg-red-100   text-red-800  border-red-200
 *   medium    -> bg-amber-100 text-amber-800 border-amber-200
 *   low       -> bg-emerald-100 text-emerald-800 border-emerald-200
 *   lainnya   -> bg-slate-100 text-slate-700  border-slate-200
 *
 * PROPS
 *   total Number            WAJIB  skor EWS total
 *   risk  String            WAJIB  'low'|'medium'|'high'|'emergency'|'none'|''
 *   label String            default ''  label pendek. Bila kosong dipakai
 *                                         EWS_RISK_LABELS[risk], dan bila
 *                                         risk tak dikenal dipakai
 *                                         riskFrom(total).
 *   size  String            default 'md'  'sm' | 'md' | 'lg'
 *
 * SLOTS: `suffix` (teks kecil setelah label, mis. "/18" atau "· Emergency")
 * EMITS : (tidak ada)
 *
 * Toleransi: `risk` null / '' / tak dikenal -> level diturunkan dari `total`
 * memakai config('ews.escalation') (0-2 low, 3-4 medium, 5-6 high, >=7
 * emergency). `total` null/undefined -> ditampilkan '-'.
 */
import { computed } from 'vue';
import { ewsBadgeClasses, riskBadgeLabel, riskFrom } from '../tone';

const props = defineProps({
    total: { type: Number, default: null },
    risk: { type: String, default: '' },
    label: { type: String, default: '' },
    size: { type: String, default: 'md', validator: (value) => ['sm', 'md', 'lg'].includes(value) },
});

const SIZE_CLASS = {
    sm: 'text-[10px] px-1.5 py-0.5 gap-1',
    md: 'text-[11px] px-2 py-0.5 gap-1',
    lg: 'text-sm px-2.5 py-1 gap-1.5',
};

const SCORE_CLASS = {
    sm: 'text-[11px]',
    md: 'text-xs',
    lg: 'text-base',
};

const level = computed(() => {
    const raw = (props.risk || '').trim().toLowerCase();
    if (raw === 'low' || raw === 'medium' || raw === 'high' || raw === 'emergency') return raw;
    if (raw === 'none') return 'none';
    return riskFrom(props.total);
});

const classes = computed(() => ewsBadgeClasses(level.value === 'none' ? '' : level.value));

const displayTotal = computed(() =>
    props.total === null || props.total === undefined || Number.isNaN(Number(props.total)) ? '-' : String(props.total),
);

const displayLabel = computed(() => {
    const explicit = (props.label || '').trim();
    if (explicit) return explicit;
    if (level.value === 'none') return '-';
    return riskBadgeLabel(level.value);
});
</script>

<template>
    <span
        class="inline-flex items-center rounded border font-bold"
        :class="[classes.class, SIZE_CLASS[props.size] || SIZE_CLASS.md]"
        :title="`EWS ${displayTotal} (${displayLabel})`"
        data-purpose="ews-badge"
    >
        <span class="font-mono" :class="SCORE_CLASS[props.size] || SCORE_CLASS.md">{{ displayTotal }}</span>
        <span v-if="displayLabel !== '-'">{{ displayLabel }}</span>
        <slot name="suffix"></slot>
    </span>
</template>
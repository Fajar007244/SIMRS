<script setup>
/**
 * BundleComplianceCards.vue - tiga checklist bundle VAP / CLABSI / CAUTI.
 * Port 1:1 dari blok data-purpose="hais-compliance" dan renderBundleCards()
 * di phase1/bundles.html: kartu dengan ikon, judul bundle, badge lama pakai
 * perangkat, daftar item dengan tanda Ya / Tidak, lalu baris "Kepatuhan XX"
 * dengan chip persen.
 * Badge perangkat memakai DeviceBadge ("ETT Hari #N", "CVC Hari #N",
 * "DC Hari #N") - persis seperti renderDayBadges() prototype. Jumlah hari
 * diambil dari summary.groups[...].deviceDays; flag "perlu review" diambil
 * dari devices dengan mencocokkan kode perangkat, karena grup tidak
 * membawa flag itu sendiri.
 *
 *
 * Chip jawaban memakai tone.js bundleAnswerChip(), hasil port dari
 * statusChip() prototype.
 *
 * PROPS
 *   summary           Object  default {}  getSummary()
 *   devices           Array   default []  getDeviceSummary()
 *   observationCount  Number  default 0    jumlah observasi yang dinilai
 * SLOTS: (tidak ada)
 * EMITS : (tidak ada)
 */
import { computed } from 'vue';
import SectionCard from '@/Components/SectionCard.vue';
import DeviceBadge from '@/Components/DeviceBadge.vue';
import EmptyState from '@/Components/EmptyState.vue';
import { bundleAnswerChip, bundleTone, percentTone } from '@/tone';
import { formatNumber, isBlank } from '@/composables/useFormatting';

const props = defineProps({
    summary: { type: Object, default: () => ({}) },
    devices: { type: Array, default: () => [] },
    observationCount: { type: Number, default: 0 },
});

/** Awalan badge perangkat, sama seperti renderDayBadges() phase1. */
const DEVICE_LABEL = {
    vap: 'ETT',
    clabsi: 'CVC',
    cauti: 'DC',
};

const ICONS = {
    vap: 'fa-lungs',
    clabsi: 'fa-stethoscope',
    cauti: 'fa-droplet',
};

const s = computed(() => (props.summary && typeof props.summary === 'object' ? props.summary : {}));

const overall = computed(() => (s.value.overall && typeof s.value.overall === 'object' ? s.value.overall : {}));

const deviceRows = computed(() => (Array.isArray(props.devices) ? props.devices : []));

function deviceByCode(code) {
    return deviceRows.value.find((device) => device?.code === code) || null;
}

const cards = computed(() => {
    const groups = s.value.groups;

    if (!groups || typeof groups !== 'object') return [];

    return Object.values(groups)
        .filter((group) => group && typeof group === 'object')
        .map((group) => {
            const tone = bundleTone(group.group);
            const device = deviceByCode(group.deviceCode);
            const percent = Number(group.percent) || 0;
            const items = group.items && typeof group.items === 'object' ? Object.values(group.items) : [];

            return {
                group: group.group,
                label: group.label,
                short: tone.short,
                icon: ICONS[group.group] || 'fa-shield-halved',
                deviceLabel: DEVICE_LABEL[group.group] || (group.deviceCode || '').toUpperCase(),
                deviceDays: group.deviceDays ?? null,
                needsReview: Boolean(device?.needsReview),
                items: items.map((item) => ({ ...item, chip: bundleAnswerChip(item.answer) })),
                percent,
                chip: percentTone(percent).chip,
                answered: Number(group.answered) || 0,
                compliant: Number(group.compliant) || 0,
                itemCount: Number(group.itemCount) || 0,
            };
        });
});

const hasData = computed(() => (Number(overall.value.answered) || 0) > 0);

const haisStatus = computed(() => {
    if (!hasData.value) {
        return { class: 'bg-slate-100 text-slate-600', icon: 'fa-circle-info', text: 'Belum ada data bundle' };
    }

    return {
        class: percentTone(Number(overall.value.percent) || 0).chip,
        icon: 'fa-shield-halved',
        text: `${formatNumber(props.observationCount)} observasi dinilai - ${Math.round(Number(overall.value.percent) || 0)}%`,
    };
});
</script>

<template>
    <SectionCard
        title="Bundles Pencegahan Infeksi Rumah Sakit (HAIs PPI ICU)"
        subtitle="Evaluasi kepatuhan bundle pencegahan infeksi kateter dan ventilator setiap shift jaga"
        icon="fa-shield-halved"
        tone="teal"
    >
        <template #actions>
            <span
                class="flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-bold"
                :class="haisStatus.class"
                data-purpose="hais-compliance-status"
            >
                <i class="fa-solid" :class="haisStatus.icon" aria-hidden="true"></i>
                {{ haisStatus.text }}
            </span>
        </template>

        <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
            <div
                v-for="card in cards"
                :key="card.group"
                class="rounded-lg border border-slate-200 bg-slate-50/70 p-3"
                data-purpose="bundle-group-card"
                :data-group="card.group"
            >
                <div class="mb-2 flex items-center justify-between gap-2 border-b border-slate-200 pb-1">
                    <span class="flex items-center gap-1.5 text-xs font-bold text-slate-800">
                        <i class="fa-solid text-sky-600" :class="card.icon" aria-hidden="true"></i>
                        {{ card.label }}
                    </span>
                    <DeviceBadge
                        :label="card.deviceLabel"
                        :days="card.deviceDays"
                        :needs-review="card.needsReview"
                    />
                </div>

                <ul v-if="card.items.length" class="space-y-1.5 text-xs text-slate-700">
                    <li
                        v-for="item in card.items"
                        :key="item.key"
                        class="flex items-start justify-between gap-2"
                        data-purpose="bundle-item"
                    >
                        <span class="leading-snug text-slate-700">{{ item.label }}</span>
                        <span class="whitespace-nowrap text-[11px] font-bold" :class="item.chip.class">
                            <i class="fa-solid" :class="item.chip.icon" aria-hidden="true"></i>
                            {{ item.chip.text }}
                        </span>
                    </li>
                </ul>
                <EmptyState
                    v-else
                    icon="fa-list-check"
                    title="Belum ada item bundle"
                    message="Konfigurasi bundle untuk grup ini belum memuat item."
                />

                <div class="mt-2 flex items-center justify-between gap-2 border-t border-slate-200 pt-1">
                    <span class="text-[10px] font-bold uppercase tracking-wider text-slate-400">
                        Kepatuhan {{ card.short }}
                    </span>
                    <span
                        v-if="card.answered > 0"
                        class="rounded px-1.5 py-0.5 font-mono text-[10px] font-bold"
                        :class="card.chip"
                        data-purpose="bundle-group-percent"
                    >{{ card.percent }}% ({{ card.compliant }}/{{ card.answered }})</span>
                    <span
                        v-else
                        class="rounded bg-slate-100 px-1.5 py-0.5 font-mono text-[10px] font-bold text-slate-500"
                    >-</span>
                </div>
            </div>
        </div>

        <p v-if="!hasData" class="mt-3 text-[11px] text-slate-500">
            Belum ada penilaian bundle pada observasi episode ini. Checklist di atas akan terisi otomatis
            setelah penilaian bundle disimpan pada formulir observasi EWS.
            {{ isBlank(s.latestAtLabel) ? '' : `Penilaian terakhir: ${s.latestAtLabel}.` }}
        </p>
    </SectionCard>
</template>

<script setup>
/**
 * BundleBottomGrid.vue - dua panel bawah halaman bundle.
 *
 * Port 1:1 dari blok data-purpose="bundle-bottom-grid" di
 * phase1/bundles.html, yang berisi dua region:
 *
 *   bundle-gaps     (kolom 7 dari 12) "Peringatan: Item Bundle Belum
 *                   Terpenuhi" - renderGaps(). Satu baris per item_key,
 *                   berisi observasi TERBARU yang menjawab "Tidak" untuk
 *                   item tersebut, persis seperti criticalGaps() prototype.
 *                   Badge kanan: merah "N item bermasalah", hijau
 *                   "0 item bermasalah", atau abu-abu "Menunggu data".
 *
 *   device-monitor  (kolom 5 dari 12) "Perangkat Invasif & lines Terpantau"
 *                   - renderDevices(). Selalu 8 baris karena
 *                   BundleService::getDeviceSummary() mengembalikan seluruh
 *                   katalog config('hai.device_catalog'), termasuk yang
 *                   tidak terpasang.
 *
 * FLAG PERLU REVIEW: needsReview hanya true bila perangkat masih aktif dan
 * lama pakainya sudah mencapai config('hai.device_review_after_days') = 7,
 * dengan acuan tanggal observasi terakhir episode. Data seed hanya mencapai
 * "Hari #3", sehingga tidak ada satu pun perangkat yang perlu review. Panel
 * ini merender flag itu apa adanya lewat DeviceBadge dan tidak pernah
 * menyimpulkan bahwa semua perangkat sudah lewat batas; saat tidak ada yang
 * perlu review, panel menuliskan batasPemantauannya secara eksplisit.
 *
 * PROPS
 *   gaps     Array  default []  getGaps()
 *   devices  Array  default []  getDeviceSummary()
 *
 * SLOTS: (tidak ada)
 * EMITS : (tidak ada)
 */
import { computed } from 'vue';
import SectionCard from '@/Components/SectionCard.vue';
import DeviceBadge from '@/Components/DeviceBadge.vue';
import EmptyState from '@/Components/EmptyState.vue';
import { bundleTone } from '@/tone';
import { formatNumber, isBlank } from '@/composables/useFormatting';

const props = defineProps({
    gaps: { type: Array, default: () => [] },
    devices: { type: Array, default: () => [] },
});

const gapRows = computed(() => (Array.isArray(props.gaps) ? props.gaps : []));
const deviceRows = computed(() => (Array.isArray(props.devices) ? props.devices : []));

/** Awalan ringkas untuk DeviceBadge, mengikuti kode katalog perangkat. */
const DEVICE_SHORT = {
    ett: 'ETT',
    cvc: 'CVC',
    ventTubing: 'Vent Tubing',
    ngt: 'NGT',
    arterial: 'Art Line',
    dc: 'DC',
    infus: 'Infus',
    lain: 'Lain',
};

const reviewAfterDays = computed(() => {
    const first = deviceRows.value.find((device) => Number(device?.reviewAfterDays) > 0);

    return first ? Number(first.reviewAfterDays) : 7;
});

const reviewList = computed(() => deviceRows.value.filter((device) => device?.needsReview));

const gapBadge = computed(() => {
    if (gapRows.value.length === 0) {
        return { class: 'border border-emerald-200 bg-emerald-100 text-emerald-700', icon: 'fa-shield-halved', text: '0 item bermasalah' };
    }

    return {
        class: 'border border-red-200 bg-red-100 text-red-700',
        icon: 'fa-triangle-exclamation',
        text: `${formatNumber(gapRows.value.length)} item bermasalah`,
    };
});

const deviceMeta = computed(() => ({
    active: deviceRows.value.filter((device) => device?.isActive).length,
    review: reviewList.value.length,
}));
</script>

<template>
    <div class="grid grid-cols-1 gap-4 lg:grid-cols-12">
        <!-- ================= bundle-gaps ================= -->
        <SectionCard
            class="lg:col-span-7"
            title="Peringatan: Item Bundle Belum Terpenuhi"
            subtitle="Butir yang pernah dijawab Tidak pada observasi episode ini - memerlukan intervensi / tinjauan ulang"
            icon="fa-triangle-exclamation"
            tone="red"
        >
            <template #actions>
                <span
                    class="flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-bold"
                    :class="gapBadge.class"
                    data-purpose="gap-status-badge"
                >
                    <i class="fa-solid" :class="gapBadge.icon" aria-hidden="true"></i>
                    {{ gapBadge.text }}
                </span>
            </template>

            <div data-purpose="bundle-gaps">
                <EmptyState
                    v-if="gapRows.length === 0"
                    icon="fa-circle-check"
                    title="Semua item bundle terpenuhi pada observasi yang tercatat"
                    message="Tidak ada butir bundle yang dijawab Tidak pada observasi episode ini."
                />

                <ul v-else class="space-y-2">
                    <li
                        v-for="gap in gapRows"
                        :key="gap.itemKey"
                        class="flex flex-wrap items-start justify-between gap-2 rounded-lg border border-red-200 border-l-4 bg-red-50/40 p-2.5"
                        :class="bundleTone(gap.group).accent"
                        data-purpose="bundle-gap-row"
                    >
                        <div class="min-w-0">
                            <p class="text-xs font-bold leading-snug text-slate-800">{{ gap.itemLabel }}</p>
                            <p class="mt-0.5 text-[10px] text-slate-500">
                                Terakhir:
                                <span class="font-mono">{{ gap.observationAtLabel }}</span>
                                <span v-if="!isBlank(gap.deviceLabel)">
                                    - {{ gap.deviceLabel }}
                                    <span v-if="gap.deviceDays !== null && gap.deviceDays !== undefined">
                                        (Hari #{{ gap.deviceDays }})
                                    </span>
                                </span>
                            </p>
                        </div>
                        <span
                            class="whitespace-nowrap rounded px-1.5 py-0.5 font-mono text-[10px] font-bold"
                            :class="bundleTone(gap.group).chip"
                        >{{ bundleTone(gap.group).short }}</span>
                    </li>
                </ul>
            </div>
        </SectionCard>

        <!-- ================= device-monitor ================= -->
        <SectionCard
            class="lg:col-span-5"
            title="Perangkat Invasif &amp; lines Terpantau"
            subtitle="Status penggunaan perangkat dan lama pemakaian dalam hari"
            icon="fa-microphone-lines"
            tone="teal"
        >
            <div class="space-y-1.5" data-purpose="device-monitor">
                <EmptyState
                    v-if="deviceRows.length === 0"
                    icon="fa-plug-circle-xmark"
                    title="Belum ada data perangkat invasif pada observasi episode ini"
                    message="Katalog perangkat unit belum memuat entri apa pun."
                />

                <template v-else>
                    <div
                        v-for="device in deviceRows"
                        :key="device.code"
                        class="flex items-start justify-between gap-2 rounded-md border border-slate-200 bg-slate-50/60 px-2.5 py-2"
                        :class="device.bundleGroup ? `border-l-4 ${bundleTone(device.bundleGroup).accent}` : 'border-l-4 border-l-slate-200'"
                        data-purpose="device-row"
                        :data-needs-review="device.needsReview ? 'true' : 'false'"
                    >
                        <div class="flex min-w-0 items-start gap-2">
                            <span
                                class="mt-1.5 h-2 w-2 shrink-0 rounded-full"
                                :class="device.isActive ? 'bg-emerald-500' : 'bg-slate-300'"
                            ></span>
                            <div class="min-w-0">
                                <p class="flex flex-wrap items-center gap-1.5 text-xs font-bold leading-snug text-slate-800">
                                    {{ device.label }}
                                    <span
                                        v-if="device.bundleGroup"
                                        class="rounded px-1.5 py-0.5 font-mono text-[10px] font-bold"
                                        :class="bundleTone(device.bundleGroup).chip"
                                    >{{ bundleTone(device.bundleGroup).short }}</span>
                                </p>
                                <p class="mt-0.5 font-mono text-[10px] text-slate-500">
                                    Mulai: {{ device.startDateLabel }}
                                </p>
                            </div>
                        </div>

                        <div class="shrink-0">
                            <DeviceBadge
                                :label="DEVICE_SHORT[device.code] || (device.code || '').toUpperCase()"
                                :days="device.days"
                                :needs-review="device.needsReview"
                            />
                        </div>
                    </div>

                    <p class="field-hint" data-purpose="device-review-note">
                        <template v-if="deviceMeta.review > 0">
                            {{ formatNumber(deviceMeta.review) }} perangkat aktif sudah melewati batas pemantauan
                            {{ reviewAfterDays }} hari dan perlu review.
                        </template>
                        <template v-else>
                            Belum ada perangkat aktif yang melewati batas pemantauan {{ reviewAfterDays }} hari.
                            {{ formatNumber(deviceMeta.active) }} dari {{ formatNumber(deviceRows.length) }} perangkat
                            katalog sedang terpasang.
                        </template>
                    </p>
                </template>
            </div>
        </SectionCard>
    </div>
</template>

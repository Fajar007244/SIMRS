<script setup>
/**
 * HAIsBundleCompliancePanel.vue - region data-purpose="hais-compliance" pada
 * phase1/observasi.html.
 *
 * Prototype merender tiga kartu (VAP / CLABSI / CAUTI) dengan daftar jawaban
 * Ya/Tidak per item dan badge lama pemakaian perangkat (#ettDayBadge,
 * #cvcDayBadge, #dcDayBadge), plus chip status kepatuhan di header.
 *
 * Data come from BundleService::getSummary() (`summary`) yang sudah memuat:
 *   - groups.vap / groups.clabsi / groups.cauti, masing-masing dengan
 *     { itemCount, answered, compliant, percent, tone, items{item_key -> {...}} }
 *   - overall (13 item) untuk chip "Kepatuhan: N%"
 *   - latestAtLabel untuk keterangan waktu penilaian
 *
 * Badge hari perangkat diambil dari `devices` (BundleService::getDeviceSummary)
 * lalu dicocokkan lewat deviceCode tiap grup, lalu dirender dengan
 * Components/DeviceBadge.vue.
 *
 * CATATAN PERSENTASE: `percent` di server memakai pembagi JUMLAH ITEM YANG
 * DIJAWAB, bukan 13. Jadi "0%" di sini berarti "tidak ada jawaban sama
 * sekali", bukan "semua tidak patuh". Karena itu chip header memakai
 * `overall.answered` untuk membedakan keduanya.
 *
 * PROPS
 *   summary Object  WAJIB  hasil BundleService::getSummary()
 *   devices Array            8 baris getDeviceSummary()
 *
 * EMITS: (tidak ada)
 */
import { computed } from 'vue';
import DeviceBadge from '@/Components/DeviceBadge.vue';
import { bundleTone, bundleAnswerChip, tone } from '@/tone';
import { formatNumber } from '@/composables/useFormatting';

const props = defineProps({
    summary: { type: Object, required: true },
    devices: { type: Array, default: () => [] },
});

/** Urutan tetap sama dengan konfigurasi: vap, clabsi, cauti. */
const GROUP_ORDER = ['vap', 'clabsi', 'cauti'];

const GROUP_ICON = {
    vap: 'fa-lungs',
    clabsi: 'fa-stethoscope',
    cauti: 'fa-droplet',
};

/** Label device ringkas untuk badge, sama seperti phase1. */
const GROUP_BADGE_LABEL = {
    vap: 'ETT',
    clabsi: 'CVC',
    cauti: 'DC',
};

const groups = computed(() => {
    const source = props.summary && typeof props.summary === 'object' && props.summary.groups
        ? props.summary.groups
        : {};

    const deviceByCode = {};

    for (const device of Array.isArray(props.devices) ? props.devices : []) {
        deviceByCode[device.code] = device;
    }

    return GROUP_ORDER
        .filter((key) => source[key])
        .map((key) => {
            const group = source[key];
            const device = deviceByCode[group.deviceCode] || null;
            const palette = tone(group.tone);
            const items = group.items && typeof group.items === 'object' ? group.items : {};

            return {
                key,
                icon: GROUP_ICON[key] || 'fa-shield-halved',
                badgeLabel: GROUP_BADGE_LABEL[key] || group.deviceCode,
                label: group.label || key,
                answered: Number(group.answered) || 0,
                itemCount: Number(group.itemCount) || 0,
                compliant: Number(group.compliant) || 0,
                percent: Number(group.percent) || 0,
                deviceDays: device ? device.days : null,
                deviceNeedsReview: device ? device.needsReview : false,
                deviceLabel: group.deviceLabel || (device ? device.label : ''),
                palette,
                items: Object.keys(items).map((itemKey) => items[itemKey]),
            };
        });
});

const overall = computed(() => (props.summary && props.summary.overall) || {});

const overallPercent = computed(() => Number(overall.value.percent) || 0);
const overallAnswered = computed(() => Number(overall.value.answered) || 0);
const overallItemCount = computed(() => Number(overall.value.itemCount) || 0);

const hasBundleData = computed(() => overallAnswered.value > 0);

const latestLabel = computed(() => {
    const value = props.summary ? props.summary.latestAtLabel : null;

    return value ? String(value) : '-';
});

/**
 * Chip status header, port dari status.innerHTML di updateBundlePanel():
 * 100% hijau dengan circle-check, selain itu amber dengan triangle-exclamation.
 */
const statusChip = computed(() => {
    if (!hasBundleData.value) {
        return {
            class: 'bg-slate-100 text-slate-600',
            icon: 'fa-circle-info',
            text: 'Belum ada data bundle',
        };
    }

    if (overallPercent.value >= 100) {
        return {
            class: 'bg-emerald-100 text-emerald-800',
            icon: 'fa-circle-check',
            text: `Kepatuhan: ${formatNumber(overallPercent.value)}%`,
        };
    }

    return {
        class: 'bg-amber-100 text-amber-800',
        icon: 'fa-triangle-exclamation',
        text: `Kepatuhan: ${formatNumber(overallPercent.value)}%`,
    };
});

/**
 * bundleAnswerChip() menerima 'Ya' / 'Tidak' / null, sedangkan
 * `getSummary()` mengirim `answer` sebagai nilai enum mentah ('ya'/'tidak') dan
 * `answerLabel` sebagai teks siap tampil. Yang dipakai answerLabel supaya
 * jawaban kosong tetap tampil sebagai '-' dan bukan dianggap "Tidak".
 */
function chipFor(item) {
    return bundleAnswerChip(item ? item.answerLabel : null);
}

function groupToneClasses(group) {
    const palette = bundleTone(group.key);

    return `${palette.chip} border ${palette.border}`;
}
</script>

<template>
    <section
        class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm"
        data-purpose="hais-compliance"
    >
        <div class="mb-3 flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 pb-2">
            <div class="flex items-center gap-2">
                <div class="flex h-7 w-7 items-center justify-center rounded-md bg-teal-100 text-xs font-bold text-teal-700">
                    <i class="fa-solid fa-shield-halved" aria-hidden="true"></i>
                </div>
                <div class="min-w-0">
                    <h2 class="text-sm font-bold text-slate-900">
                        Bundles Pencegahan Infeksi Rumah Sakit (HAIs PPI ICU)
                    </h2>
                    <p class="text-[11px] text-slate-500">
                        Evaluasi kepatuhan bundle pencegahan infeksi kateter dan ventilator setiap shift jaga
                    </p>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <span
                    class="flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-bold"
                    :class="statusChip.class"
                    data-purpose="hais-compliance-status"
                >
                    <i class="fa-solid" :class="statusChip.icon" aria-hidden="true"></i>
                    {{ statusChip.text }}
                </span>
                <span class="rounded border border-slate-200 bg-slate-50 px-2 py-0.5 text-[10px] text-slate-500">
                    Dinilai {{ latestLabel }}
                </span>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
            <article
                v-for="group in groups"
                :key="group.key"
                class="rounded-lg border border-slate-200 bg-slate-50/70 p-3"
                :data-purpose="`hais-group-${group.key}`"
            >
                <div class="mb-2 flex items-center justify-between gap-2 border-b border-slate-200 pb-1">
                    <span class="flex min-w-0 items-center gap-1.5 text-xs font-bold text-slate-800">
                        <i class="fa-solid shrink-0" :class="group.icon" :title="group.icon" aria-hidden="true"></i>
                        <span class="truncate" :title="group.label">{{ group.label }}</span>
                    </span>
                    <DeviceBadge
                        :label="group.badgeLabel"
                        :days="group.deviceDays"
                        :needs-review="group.deviceNeedsReview"
                    />
                </div>

                <ul class="space-y-1.5 text-xs text-slate-700">
                    <li
                        v-for="item in group.items"
                        :key="item.key"
                        class="flex items-start justify-between gap-2"
                        :data-purpose="`hais-item-${item.key}`"
                    >
                        <span class="min-w-0 flex-1 pr-1 text-[11px]">{{ item.label }}</span>
                        <span class="shrink-0 text-[11px] font-bold" :class="chipFor(item).class">
                            <i class="fa-solid" :class="chipFor(item).icon" aria-hidden="true"></i>
                            {{ chipFor(item).text }}
                        </span>
                    </li>
                </ul>

                <div class="mt-2.5 flex items-center justify-between gap-2 border-t border-slate-200 pt-2 text-[11px]">
                    <span class="text-slate-500">
                        {{ formatNumber(group.compliant) }}/{{ formatNumber(group.itemCount) }} item
                        <template v-if="group.answered < group.itemCount">
                            &bull; {{ formatNumber(group.answered) }} dijawab
                        </template>
                    </span>
                    <span
                        class="rounded px-1.5 py-0.5 font-mono font-bold"
                        :class="groupToneClasses(group)"
                    >{{ formatNumber(group.percent) }}%</span>
                </div>

                <p v-if="group.deviceLabel" class="mt-1 truncate text-[10px] text-slate-400" :title="group.deviceLabel">
                    {{ group.deviceLabel }}
                </p>
            </article>
        </div>

        <p class="mt-3 text-[10px] text-slate-500">
            Persentase dihitung terhadap jumlah item yang dijawab
            ({{ formatNumber(overallAnswered) }} dari {{ formatNumber(overallItemCount) }} item konfigurasi),
            bukan terhadap seluruh konfigurasi.
        </p>
    </section>
</template>
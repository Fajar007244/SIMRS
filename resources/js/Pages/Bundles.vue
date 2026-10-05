<script setup>
/**
 * Pages/Bundles.vue - modul "Bundle HAIs (VAP/CLABSI/CAUTI)" (READ-ONLY).
 *
 * Port 1:1 dari phase1/bundles.html. Pemetaan region `data-purpose`:
 *
 *   <header> + tab bar modul  -> ClinicalLayout active-tab="bundles"
 *   patient-banner            -> PatientHeaderBanner di slot `status`
 *                                ClinicalLayout. Kotak "Kepatuhan Bundle HAIs"
 *                                (teal) memakai slot `status` milik banner,
 *                                blok "Evaluasi Bundle" memakai slot
 *                                `default`, tombol Cetak memakai `actions`.
 *   bundle-stats              -> BundleStatCards
 *   hais-compliance           -> BundleComplianceCards
 *   bundle-trend-chart        -> BundleTrendChart
 *   bundle-history            -> BundleHistoryTable
 *   bundle-gaps               -> BundleBottomGrid (kolom 7 dari 12)
 *   device-monitor            -> BundleBottomGrid (kolom 5 dari 12)
 *   topbar "Cetak" (onclick)  -> PrintButton
 *
 * Halaman ini tidak punya form create apa pun; penilaian bundle dan
 * pencatatan perangkat invasif dilakukan pada formulir observasi EWS.
 *
 * FILTER: dropdown kelompok bundle dan kotak pencarian menulis ke query
 * string (group / search) lewat url() dari router.js lalu visiting ulang
 * halaman, sehingga URL bisa disalin dan tombol Refresh tidak menghapus
 * pilihan pengguna. Penyaringan barisnya tetap di sisi klien karena
 * BundleService::getHistory() tidak menerima filter.
 *
 * CETAK: ClinicalLayout diberi printable sehingga konten dibungkus
 * id="print-area" sesuai aturan @media print di app.css.
 */
import { computed, ref, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import ClinicalLayout from '@/Layouts/ClinicalLayout.vue';
import PatientHeaderBanner from '@/Components/PatientHeaderBanner.vue';
import PrintButton from '@/Components/PrintButton.vue';
import BundleStatCards from '@/Pages/Bundles/BundleStatCards.vue';
import BundleComplianceCards from '@/Pages/Bundles/BundleComplianceCards.vue';
import BundleTrendChart from '@/Pages/Bundles/BundleTrendChart.vue';
import BundleHistoryTable from '@/Pages/Bundles/BundleHistoryTable.vue';
import BundleBottomGrid from '@/Pages/Bundles/BundleBottomGrid.vue';
import { url } from '@/router';
import { percentTone } from '@/tone';
import { formatNumber, isBlank } from '@/composables/useFormatting';

const props = defineProps({
    encounterId: { type: String, required: true },
    banner: { type: Object, default: null },
    summary: { type: Object, default: () => ({}) },
    history: { type: Array, default: () => [] },
    gaps: { type: Array, default: () => [] },
    devices: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
    activeTab: { type: String, default: 'bundles' },
});

const s = computed(() => (props.summary && typeof props.summary === 'object' ? props.summary : {}));
const overall = computed(() => (s.value.overall && typeof s.value.overall === 'object' ? s.value.overall : {}));
const historyRows = computed(() => (Array.isArray(props.history) ? props.history : []));
const gapRows = computed(() => (Array.isArray(props.gaps) ? props.gaps : []));

/* --------------------------------- banner --------------------------------- */

const hasData = computed(() => (Number(overall.value.answered) || 0) > 0);

/** Kotak kanan banner: "100% - 13/13 butir", atau "Belum ada data bundle". */
const moduleStatus = computed(() => {
    if (!hasData.value) {
        return 'Belum ada data bundle';
    }

    const percent = Math.round(Number(overall.value.percent) || 0);
    const compliant = Number(overall.value.compliant) || 0;
    const answered = Number(overall.value.answered) || 0;

    return `${percent}% - ${formatNumber(compliant)}/${formatNumber(answered)} butir`;
});

/** Blok "Evaluasi Bundle" di bar abu-abu banner (bundleLastUpdate). */
const evaluationText = computed(() => {
    if (!hasData.value) {
        return 'Belum ada data';
    }

    const percent = Math.round(Number(overall.value.percent) || 0);
    const at = isBlank(s.value.latestAtLabel) ? '-' : s.value.latestAtLabel;
    const by = isBlank(props.banner?.latestObsRecordedBy) ? '-' : props.banner.latestObsRecordedBy;

    return `${at} oleh ${by} (${percent}%)`;
});

const statusTone = computed(() => (hasData.value ? percentTone(Number(overall.value.percent) || 0).tone : 'teal'));

/* --------------------------------- filter --------------------------------- */

const filters = ref({ ...props.filters });
const refreshing = ref(false);

watch(
    () => props.filters,
    (next) => {
        filters.value = { ...next };
    },
);

function visit(next) {
    router.visit(
        url('bundles', {
            encounter: props.encounterId,
            group: next.group || null,
            search: next.search || null,
        }),
        { replace: true, preserveState: true, preserveScroll: true },
    );
}

function onUpdateFilters(next) {
    filters.value = { ...next };
    visit(next);
}

function refresh() {
    if (refreshing.value) return;

    refreshing.value = true;

    router.reload({
        preserveScroll: true,
        onFinish: () => {
            refreshing.value = false;
        },
    });
}
</script>

<template>
    <Head title="Evaluasi Bundle HAIs (VAP/CLABSI/CAUTI)" />

    <ClinicalLayout
        title="Evaluasi Bundle HAIs (VAP/CLABSI/CAUTI)"
        subtitle="SIMRS EMR v4.8 - Modul Pencegahan Infeksi Nosokomial"
        active-tab="bundles"
        :encounter-id="encounterId"
        :printable="true"
    >
        <template #status>
            <PatientHeaderBanner
                :patient="banner"
                status-label="Kepatuhan Bundle HAIs"
                :status-tone="statusTone"
                :module-status="moduleStatus"
            >
                <template #default>
                    <div class="flex flex-wrap items-center gap-1.5 text-[10px] uppercase tracking-wider text-slate-500">
                        <span class="font-bold">Evaluasi Bundle</span>
                        <span class="font-semibold normal-case tracking-normal text-slate-900">
                            {{ evaluationText }}
                        </span>
                    </div>
                </template>
                <template #actions>
                    <span class="rounded border border-teal-200 bg-teal-50 px-2 py-0.5 text-[10px] font-bold text-teal-700">
                        <i class="fa-solid fa-shield-virus" aria-hidden="true"></i> PPI ICU
                    </span>
                    <PrintButton label="Cetak" size="sm" tone="secondary" />
                </template>
            </PatientHeaderBanner>
        </template>

        <BundleStatCards :summary="summary" :history="history" :gaps="gaps" />

        <div data-purpose="hais-compliance">
            <BundleComplianceCards
                :summary="summary"
                :devices="devices"
                :observation-count="historyRows.length"
            />
        </div>

        <div data-purpose="bundle-trend-chart">
            <BundleTrendChart :history="history" />
        </div>

        <BundleHistoryTable
            :history="history"
            :filters="filters"
            :refreshing="refreshing"
            @update:filters="onUpdateFilters"
            @refresh="refresh"
        />

        <BundleBottomGrid :gaps="gaps" :devices="devices" />
    </ClinicalLayout>
</template>

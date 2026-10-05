<script setup>
/**
 * Pages/Farmasi.vue - modul "Farmasi & Drip Inotropik" (READ-ONLY).
 *
 * Port 1:1 dari phase1/farmasi.html. Pemetaan region `data-purpose`:
 *
 *   <header> + tab bar modul  -> ClinicalLayout active-tab="farmasi"
 *   patient-banner            -> PatientHeaderBanner di slot `status`
 *                                ClinicalLayout. Kotak "Status Rekap Obat"
 *                                (amber) memakai slot `status` milik banner,
 *                                baris "Update Terakhir" memakai slot
 *                                `default`, tombol Cetak memakai `actions`.
 *   pharmacy-stats            -> PharmacyStatCards
 *   medication-timeline       -> MedicationTimeline   (kolom 8 dari 12)
 *   quick-widgets             -> QuickWidgets         (kolom 4 dari 12)
 *   category-volume           -> CategoryVolumeDistribution
 *   allergy-safety            -> AllergyDrugSafetyAlert
 *   asmed-plan                -> AsmedTherapyPlan
 *   scheduled-formularium     -> ScheduledFormularium
 *   topbar "Cetak" (onclick)  -> PrintButton (dua kali: banner + timeline)
 *
 * Halaman ini tidak punya form create apa pun; sumber datanya adalah koreksi
 * pemberian obat/cairan yang dicatat pada formulir observasi EWS.
 *
 * FILTER: dropdown kategori dan kotak pencarian tidak menyaring ulang di
 * sisi klien. Keduanya menulis ke query string lewat url() dari router.js
 * lalu visiting ulang halaman, sehingga hasil penyaringan datang dari
 * MedicationRecapService::getAdministrations() di server dan URL-nya bisa
 * disalin atau dibagikan. Pencarian didebounce 350 ms supaya tidak ada
 * satu request per ketikan.
 *
 * CETAK: ClinicalLayout diberi printable (nilai bawaan juga true) sehingga
 * konten dibungkus id="print-area" sesuai aturan @media print di app.css.
 */
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import ClinicalLayout from '@/Layouts/ClinicalLayout.vue';
import PatientHeaderBanner from '@/Components/PatientHeaderBanner.vue';
import PrintButton from '@/Components/PrintButton.vue';
import PharmacyStatCards from '@/Pages/Farmasi/PharmacyStatCards.vue';
import MedicationTimeline from '@/Pages/Farmasi/MedicationTimeline.vue';
import QuickWidgets from '@/Pages/Farmasi/QuickWidgets.vue';
import CategoryVolumeDistribution from '@/Pages/Farmasi/CategoryVolumeDistribution.vue';
import AllergyDrugSafetyAlert from '@/Pages/Farmasi/AllergyDrugSafetyAlert.vue';
import AsmedTherapyPlan from '@/Pages/Farmasi/AsmedTherapyPlan.vue';
import ScheduledFormularium from '@/Pages/Farmasi/ScheduledFormularium.vue';
import { url } from '@/router';
import { MEDICATION_CATEGORY_ORDER } from '@/tone';
import { formatNumber, isBlank } from '@/composables/useFormatting';

const props = defineProps({
    encounterId: { type: String, required: true },
    banner: { type: Object, default: null },
    summary: { type: Object, default: () => ({}) },
    medications: { type: Array, default: () => [] },
    activeDrips: { type: Array, default: () => [] },
    fluidBalance: { type: Object, default: () => ({}) },
    categoryDistribution: { type: Array, default: () => [] },
    allergyConflicts: { type: Object, default: () => ({}) },
    therapyPlanChecks: { type: Array, default: () => [] },
    formularium: { type: Array, default: () => [] },
    formulariumMeta: { type: Object, default: () => ({}) },
    filters: { type: Object, default: () => ({}) },
    activeTab: { type: String, default: 'farmasi' },
});

const summary = computed(() => (props.summary && typeof props.summary === 'object' ? props.summary : {}));

/* --------------------------------- banner --------------------------------- */

/** "43 entri / 7 obat", atau "Belum ada data" - sama seperti phase1. */
const moduleStatus = computed(() => {
    const total = Number(summary.value.total) || 0;

    if (total <= 0) {
        return 'Belum ada data';
    }

    return `${formatNumber(total)} entri / ${formatNumber(Number(summary.value.uniqueDrugs) || 0)} obat`;
});

/** Baris "Update Terakhir" di bar abu-abu banner (pharmLastUpdate). */
const lastUpdateText = computed(() => {
    if (isBlank(summary.value.lastGivenAtLabel)) {
        return 'Belum ada catatan pemberian obat pada observasi episode ini.';
    }

    return `${summary.value.lastGivenAtLabel} oleh ${isBlank(summary.value.lastGivenBy) ? '-' : summary.value.lastGivenBy}`;
});

/* --------------------------------- filter --------------------------------- */

const filters = ref({ ...props.filters });
const refreshing = ref(false);

watch(
    () => props.filters,
    (next) => {
        filters.value = { ...next };
    },
);

/**
 * Opsi dropdown kategori: kategori yang punya baris pemberian, sesuai
 * urutan MedicationCategory, ditambah nilai filter yang sedang aktif supaya
 * pilihan pengguna tidak pernah hilang saat hasil penyaringan kosong.
 */
const categoryOptions = computed(() => {
    const withRows = new Set();

    for (const row of Array.isArray(props.categoryDistribution) ? props.categoryDistribution : []) {
        if (Number(row?.count) > 0) withRows.add(row.category);
    }

    if (filters.value?.category) withRows.add(filters.value.category);

    return MEDICATION_CATEGORY_ORDER.filter((category) => withRows.has(category));
});

const SEARCH_DEBOUNCE_MS = 350;
let searchTimer = null;

function targetUrl(next) {
    return url('farmasi', {
        encounter: props.encounterId,
        category: next.category || null,
        search: next.search || null,
        dateFrom: next.dateFrom || null,
        dateTo: next.dateTo || null,
    });
}

function visit(next) {
    router.visit(targetUrl(next), {
        replace: true,
        preserveState: true,
        preserveScroll: true,
    });
}

function onUpdateFilters(next) {
    filters.value = { ...next };

    if (searchTimer) {
        clearTimeout(searchTimer);
        searchTimer = null;
    }

    // Dropdown langsung, kotak pencarian didebounce.
    if (next.search === (props.filters?.search ?? null)) {
        visit(next);
        return;
    }

    searchTimer = setTimeout(() => {
        searchTimer = null;
        visit(next);
    }, SEARCH_DEBOUNCE_MS);
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

onBeforeUnmount(() => {
    if (searchTimer) clearTimeout(searchTimer);
});
</script>

<template>
    <Head title="Farmasi &amp; Rekap Pemberian Obat ICU" />

    <ClinicalLayout
        title="Farmasi &amp; Rekap Pemberian Obat ICU"
        subtitle="SIMRS EMR v4.8 - Modul Farmasi &amp; Drip Inotropik"
        active-tab="farmasi"
        :encounter-id="encounterId"
        :printable="true"
    >
        <template #status>
            <PatientHeaderBanner
                :patient="banner"
                status-label="Status Rekap Obat"
                status-tone="amber"
                :module-status="moduleStatus"
            >
                <template #default>
                    <div
                        class="flex flex-wrap items-center gap-1.5 text-[10px] uppercase tracking-wider text-slate-500"
                    >
                        <span class="font-bold">Pemberian Terakhir</span>
                        <span class="font-semibold normal-case tracking-normal text-slate-900">
                            {{ lastUpdateText }}
                        </span>
                    </div>
                </template>
                <template #actions>
                    <PrintButton label="Cetak" size="sm" tone="secondary" />
                </template>
            </PatientHeaderBanner>
        </template>

        <PharmacyStatCards :summary="summary" />

        <div class="grid grid-cols-1 gap-4 lg:grid-cols-12">
            <MedicationTimeline
                class="lg:col-span-8"
                :medications="medications"
                :filters="filters"
                :category-options="categoryOptions"
                :refreshing="refreshing"
                @update:filters="onUpdateFilters"
                @refresh="refresh"
            />

            <QuickWidgets
                class="lg:col-span-4"
                :drips="activeDrips"
                :fluid-balance="fluidBalance"
                :latest-observation-at="banner?.latestObsTimestamp ?? ''"
            />
        </div>

        <CategoryVolumeDistribution :distribution="categoryDistribution" />

        <AllergyDrugSafetyAlert :conflicts="allergyConflicts" />

        <AsmedTherapyPlan :checks="therapyPlanChecks" :diagnoses="banner?.diagnoses ?? ''" />

        <ScheduledFormularium :formularium="formularium" :meta="formulariumMeta" />
    </ClinicalLayout>
</template>

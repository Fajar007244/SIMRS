<script setup>
/**
 * Pages/Observasi.vue - modul "Observasi EWS & Hemodinamik", jalur tulis penuh.
 *
 * Port 1:1 dari phase1/observasi.html. Pemetaan region `data-purpose`:
 *
 *   BEGIN/END: TopNavigation          -> ClinicalLayout (active-tab="observasi")
 *   data-purpose="patient-banner"     -> PatientHeaderBanner di slot `status`
 *   data-purpose="telemetry-vitals"   -> Observasi/TelemetryVitalsCards.vue
 *   data-purpose="trend-chart-panel"  -> Observasi/EwsTrendPanel.vue (TrendChart.vue)
 *   data-purpose="quick-widgets"      -> Observasi/QuickWidgets.vue
 *   data-purpose="hais-compliance"     -> Observasi/HAIsBundleCompliancePanel.vue
 *   data-purpose="observation-flowsheet" -> Observasi/FlowsheetObservationTable.vue
 *   BEGIN: ModalFormulirObservasiEWS -> Observasi/ModalFormulirObservasiEWS.vue
 *   script data-purpose="ews-config"  -> Observasi/ewsPreview.js
 *   #observationModal footer         -> slot `footer` Modal.vue
 *   tombol hapus per baris flowsheet  -> Observasi/FlowsheetDeleteConfirmModal.vue
 *
 * STATE: slot jam yang sedang disunting (`editingRow`) dan openness modal
 * (`formOpen`) sengaja TIDAK disimpan di query string, jadi refresh tidak
 * pernah membuka formulir untuk slot yang sudah lewat.
 *
 * CATATAN URL JALUR TULIS: resources/js/router.js (file beku) hanya memuat
 * route baca `observasi`; `observasi.store` dan `observasi.destroy` tidak ada
 * di sana dan tidak boleh ditambah tanpa persetujuan. Karena itu `urls` di
 * bawah membentuk URL dari url('observasi', ...) + suffix.
 *
 * KETERBATASAN TABEL BUNDLE: ObservationService::getFlowsheet() tidak
 * mengirim jawaban bundle per item, hanya `hasBundle` dan `bundlePercent`.
 * Jadi ketika formulir dibuka untuk menyunting slot lama, isian Ya/Tidak
 * diambil dari observasi TERAKHIL yang punya jawaban bundle
 * (BundleService::getSummary().groups.*.items) - bukan dari slot itu sendiri.
 * Menutup celah ini butuh method baru di layer service, yang beku.
 */
import { computed, onMounted, ref, watch } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import ClinicalLayout from '@/Layouts/ClinicalLayout.vue';
import PatientHeaderBanner from '@/Components/PatientHeaderBanner.vue';
import TelemetryVitalsCards from './Observasi/TelemetryVitalsCards.vue';
import EwsTrendPanel from './Observasi/EwsTrendPanel.vue';
import QuickWidgets from './Observasi/QuickWidgets.vue';
import HAIsBundleCompliancePanel from './Observasi/HAIsBundleCompliancePanel.vue';
import FlowsheetObservationTable from './Observasi/FlowsheetObservationTable.vue';
import FlowsheetDeleteConfirmModal from './Observasi/FlowsheetDeleteConfirmModal.vue';
import LoadingOverlay from '@/Components/LoadingOverlay.vue';
import ModalFormulirObservasiEWS from './Observasi/ModalFormulirObservasiEWS.vue';
import { url } from '@/router';
import { formatNumber, isBlank } from '@/composables/useFormatting';

const props = defineProps({
    encounterId: { type: String, required: true },
    banner: { type: Object, default: null },
    telemetry: { type: Object, default: () => ({}) },
    ewsTrend: { type: Object, default: () => ({}) },
    flowsheet: { type: Array, default: () => [] },
    bundleCompliance: { type: Object, default: () => ({}) },
    devices: { type: Array, default: () => [] },
    reference: { type: Object, default: () => ({}) },
    filters: { type: Object, default: () => ({}) },
    activeTab: { type: String, default: 'observasi' },
    // Empat boolean dari Encounter::getCompletionAttribute(). Strip tab admisi
    // di dalam formulir observasi membacanya; opsional supaya halaman lama
    // yang tidak mengirim prop ini tetap berjalan.
    admissionCompletion: { type: Object, default: () => ({}) },
});

/* ------------------------------------------------------------------- URL */

const moduleBase = computed(() => url('observasi', { encounter: props.encounterId }));

const urls = computed(() => ({
    store: moduleBase.value,
    destroy: `${moduleBase.value}/delete`,
}));

const farmasiUrl = computed(() => url('farmasi', { encounter: props.encounterId }));
const bundlesUrl = computed(() => url('bundles', { encounter: props.encounterId }));

/* ------------------------------------------------------------------ data */

const latest = computed(() => props.telemetry?.latest ?? null);
const deltas = computed(() => props.telemetry?.deltas ?? {});
const rows = computed(() => (Array.isArray(props.flowsheet) ? props.flowsheet : []));

/** Keterangan untuk kotak status pada banner pasien. */
const moduleStatus = computed(() => {
    const overall = props.bundleCompliance?.overall;

    if (!overall || Number(overall.answered) === 0) {
        return 'Belum ada penilaian bundle';
    }

    return `${formatNumber(overall.percent)}% - ${formatNumber(overall.compliant)}/${formatNumber(overall.answered)} butir`;
});

/** Banner juga menampilkan status klinis EWS terakhir, jadi ringkasannya. */
const clinicalNote = computed(() => {
    if (!latest.value) return 'Belum ada observasi EWS pada episode ini.';

    const total = latest.value.ewsTotal ?? '-';
    const risk = isBlank(latest.value.ewsRisk) ? '' : String(latest.value.ewsRisk);
    const atRisk = Number(props.telemetry?.atRiskCount) || 0;

    return `EWS ${total} (${latest.value.riskLabel || '-'}), ${atRisk} observasi berisiko tinggi/emergensi.`;
});

/**
 * Jawaban bundle per item dari observasi terakhir yang dinilai. Dipakai
 * formulir sebagai titik awal ketika menyunting slot lama; lihat catatan
 * KETERBATASAN TABEL BUNDLE di atas.
 */
const bundleAnswers = computed(() => {
    const groups = props.bundleCompliance?.groups;

    if (!groups || typeof groups !== 'object') return {};

    const answers = {};

    for (const group of Object.values(groups)) {
        const items = group?.items;

        if (!items || typeof items !== 'object') continue;

        for (const item of Object.values(items)) {
            if (item && item.key && item.answerLabel && item.answerLabel !== '-') {
                answers[item.key] = item.answerLabel;
            }
        }
    }

    return answers;
});

/* --------------------------------------------------------------- kontrol */

const refreshing = ref(false);
const formOpen = ref(false);
const editingRow = ref(null);
const deleteTarget = ref(null);

const deleteForm = useForm({ date: '', time: '' });

const deleting = computed(() => deleteForm.processing);

function refresh() {
    if (refreshing.value) return;

    refreshing.value = true;

    router.reload({
        only: ['flowsheet', 'telemetry', 'ewsTrend', 'bundleCompliance', 'devices', 'banner', 'filters'],
        onFinish: () => {
            refreshing.value = false;
        },
    });
}

/**
 * Filter diterapkan di server lewat query string supaya URL bisa dibagikan
 * dan reload membuka tampilan yang sama. Nilai kosong dibuang oleh url().
 */
function applyFilters(next) {
    router.get(
        url('observasi', {
            encounter: props.encounterId,
            dateFrom: next.dateFrom || null,
            dateTo: next.dateTo || null,
            risk: next.risk || null,
            hasBundle: next.hasBundle || null,
            medication: next.medication || null,
            search: next.search || null,
        }),
        {},
        { preserveScroll: true, preserveState: true },
    );
}

/**
 * Handler emit `header-score` dari pratinjau EWS di formulir. Badge
 * #ewsHeaderScore pada banner sudah dihapus, jadi nilai ini tidak lagi
 * dirender; handlernya tetap ada karena emit `header-score` masih dikirim
 * ModalFormulirObservasiEWS dan kontraknya tidak boleh diubah.
 */
const headerScore = ref(' -');

function applyHeaderScore(value) {
    headerScore.value = value === null || value === undefined || value === '' ? ' -' : String(value);
}

watch(
    () => (latest.value ? `${latest.value.ewsTotal}|${latest.value.riskLabel}` : '-'),
    () => {
        if (!formOpen.value) applyHeaderScore(initialHeaderScore());
    },
    { immediate: true },
);

function initialHeaderScore() {
    if (!latest.value || isBlank(latest.value.riskLabel)) return ' -';

    return ` ${String(latest.value.riskLabel).toUpperCase()}`;
}

function openCreate() {
    editingRow.value = null;
    formOpen.value = true;
}

/**
 * Deep-link #observationModal seperti phase1: membuka formulir langsung dari
 * URL. Hash dibersihkan setelah dipakai supaya Reload tidak memaksa formulir
 * terbuka lagi tanpa sengaja.
 */
onMounted(() => {
    if (window.location.hash !== '#observationModal') return;

    openCreate();

    if (window.history && typeof window.history.replaceState === 'function') {
        window.history.replaceState(null, '', window.location.pathname + window.location.search);
    }
});

function openEdit(row) {
    editingRow.value = row || null;
    formOpen.value = true;
}

function closeForm() {
    formOpen.value = false;
    editingRow.value = null;
}

function onSaved() {
    editingRow.value = null;
}

/** Tanggal default slot baru: hari observasi terakhir, atau hari ini. */
const defaultDate = computed(() => {
    const first = rows.value[0];

    if (first && !isBlank(first.date)) return String(first.date);

    const now = new Date();
    const pad = (value) => String(value).padStart(2, '0');

    return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
});

/* ---------------------------------------------------------------- hapus */

function askDelete(row) {
    deleteTarget.value = row || null;
    deleteForm.clearErrors();
}

function closeDelete() {
    if (deleteForm.processing) return;

    deleteTarget.value = null;
}

function confirmDelete() {
    const row = deleteTarget.value;

    if (!row) return;

    deleteForm.date = row.date || '';
    deleteForm.time = row.time || '';

    deleteForm.post(urls.value.destroy, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            deleteTarget.value = null;
        },
    });
}
</script>
<template>
    <Head title="Observasi EWS &amp; Hemodinamik" />

    <ClinicalLayout
        title="Observasi EWS &amp; Hemodinamik"
        subtitle="SIMRS EMR v4.8 - Modul Monitoring Kritis Terpadu"
        active-tab="observasi"
        :encounter-id="encounterId"
    >
        <template #status>
            <PatientHeaderBanner
                :patient="banner"
                status-label="Status Modul"
                status-tone="teal"
                :module-status="moduleStatus"
                :alert-note="clinicalNote"
            >
            </PatientHeaderBanner>
        </template>

        <!-- ============ 1. TELEMETRI VITAL ============ -->
        <TelemetryVitalsCards
            :latest="latest"
            :deltas="deltas"
            :updated-at="telemetry.lastUpdatedAt || null"
        />

        <!-- ============ 2 & 3. TREN + WIDGET CEPAT ============ -->
        <div class="grid grid-cols-1 gap-4 lg:grid-cols-12">
            <EwsTrendPanel
                :trend="ewsTrend"
                :rows="rows"
                :scale="reference.ews || {}"
            />

            <QuickWidgets
                :latest="latest"
                :rows="rows"
                :farmasi-url="farmasiUrl"
                :bundles-url="bundlesUrl"
                @new-observation="openCreate"
            />
        </div>

        <!-- ============ 4. KEPATUHAN BUNDLE HAIs ============ -->
        <HAIsBundleCompliancePanel
            :summary="bundleCompliance"
            :devices="devices"
        />

        <!-- ============ 5. LEMBAR OBSERVASI JAM-KE-JAM ============ -->
        <FlowsheetObservationTable
            :rows="rows"
            :filters="filters"
            :refreshing="refreshing"
            :destroy-url="urls.destroy"
            @filter="applyFilters"
            @refresh="refresh"
            @new-observation="openCreate"
            @destroy="askDelete"
        />

        <!-- ============ 6. FORMULIR TAMBAH / SUNTING ============ -->
        <ModalFormulirObservasiEWS
            :open="formOpen"
            :row="editingRow"
            :bundle-answers="bundleAnswers"
            :devices="devices"
            :patient="banner"
            :reference="reference"
            :store-url="urls.store"
            :default-date="defaultDate"
            :encounter-id="encounterId"
            :admission-completion="admissionCompletion"
            @close="closeForm"
            @saved="onSaved"
            @header-score="applyHeaderScore"
        />

        <!-- ============ KONFIRMASI HAPUS SATU BARIS ============ -->
        <FlowsheetDeleteConfirmModal
            :open="deleteTarget !== null"
            :row="deleteTarget"
            :processing="deleting"
            @close="closeDelete"
            @confirm="confirmDelete"
        />

        <!-- ============ 7. OVERLAY MUTASI LAMA ============ -->
        <LoadingOverlay
            :show="deleting || refreshing"
            label="Memproses..."
            message="Menyimpan atau menghapus baris observasi. Jangan menutup halaman ini."
        />
    </ClinicalLayout>
</template>
/**
 * scripts/check-render-entry.mjs
 *
 * Entry point untuk build SSR yang dipakai scripts/check-render.mjs. Isinya
 * HANYA import statis semua komponen yang harus bisa di-mount, lalu mengexpor
 * lewat `components`.
 *
 * Kenapa file ini perlu ada: `vite build --ssr` mengeksternalisasi `vue`, jadi
 * bundle hasil build mengimpor Vue lewat conditional export "node" milik Node
 * dan berjalan di CommonJS tanpa masalah. Sebaliknya `ssrLoadModule()` (dev
 * SSR Vite) memaksa `vue` ikut diproses pipeline-nya, dan `vue/index.mjs`
 * merely-re-export file CJS (`module.exports`), yang gagal di ESM. Karena itu
 * jalur yang dipakai adalah build, bukan loader.
 *
 * File ini tidak pernah dibundel ke aset aplikasi.
 */
import Observasi from '@/Pages/Observasi.vue';

import TrendChart from '@/Components/TrendChart.vue';
import BundleTrendChart from '@/Pages/Bundles/BundleTrendChart.vue';
import ModalFormulirObservasiEWS from '@/Pages/Observasi/ModalFormulirObservasiEWS.vue';
import MedicationRowsEditor from '@/Pages/Observasi/MedicationRowsEditor.vue';
import EwsLivePreview from '@/Pages/Observasi/EwsLivePreview.vue';
import EwsTrendPanel from '@/Pages/Observasi/EwsTrendPanel.vue';
import FlowsheetObservationTable from '@/Pages/Observasi/FlowsheetObservationTable.vue';
import FlowsheetDeleteConfirmModal from '@/Pages/Observasi/FlowsheetDeleteConfirmModal.vue';
import HAIsBundleCompliancePanel from '@/Pages/Observasi/HAIsBundleCompliancePanel.vue';
import QuickWidgets from '@/Pages/Observasi/QuickWidgets.vue';
import TelemetryVitalsCards from '@/Pages/Observasi/TelemetryVitalsCards.vue';

export const components = {
    Observasi,
    TrendChart,
    BundleTrendChart,
    ModalFormulirObservasiEWS,
    MedicationRowsEditor,
    EwsLivePreview,
    EwsTrendPanel,
    FlowsheetObservationTable,
    FlowsheetDeleteConfirmModal,
    HAIsBundleCompliancePanel,
    QuickWidgets,
    TelemetryVitalsCards,
};
<script setup>
/**
 * Pages/Cppt.vue - modul "Medis & CPPT", jalur tulis penuh.
 *
 * Port 1:1 dari phase1/cppt.html. Pemetaan region:
 *
 *   blok <header> + tab modul   -> ClinicalLayout (active-tab="cppt")
 *   data-purpose="patient-banner"   -> PatientHeaderBanner di slot `status`
 *                                      ClinicalLayout; kelengkapan dokumentasi
 *                                      -> slot `default` banner; tombol Cetak
 *                                      -> slot `actions`
 *   data-purpose="cppt-stats"       -> enam StatCard (CpptStatCards)
 *   #tabBar + .tabBtn (cppt.html:191) -> tab bar di bawah ini, lima tombol
 *   #panel-asmed                    -> Cppt/AsmedPanel.vue
 *   #panel-nursing                  -> Cppt/NursingCarePanel.vue
 *   #panel-diagnosis                -> Cppt/DiagnosisPanel.vue
 *   #panel-procedure                -> Cppt/ProcedurePanel.vue
 *   #panel-timeline                 -> Cppt/TimelinePanel.vue
 *                                      (termasuk SectionCard, FilterBar,
 *                                       EmptyState, PrintButton, dan modal
 *                                       Tambah CPPT)
 *
 * Tab bar memakai gaya aktif / nonaktif persis seperti setTab() di prototype:
 * aktif `bg-slate-900 text-white shadow`, lainnya
 * `text-slate-600 hover:bg-slate-100`.
 *
 * CATATAN URL JALUR TULIS: resources/js/router.js (file beku) hanya memuat
 * route baca `cppt`, jadi kelimanya membentuk URL dari
 * url('cppt', { encounter }) + suffix - idiom yang sama dipakai
 * Observasi.vue untuk observasi.store / observasi.destroy.
 *
 * OTORISASI DI UI: aksi tulis disembunyikan untuk peran yang tidak boleh
 * menulis (docs/AUTHORIZATION.md) supaya tidak ada jalan buntu. Penegakannya
 * yang sebenarnya ada di routes/pages.cppt.php lewat middleware `role`.
 */
import { computed, ref } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import ClinicalLayout from '@/Layouts/ClinicalLayout.vue';
import PatientHeaderBanner from '@/Components/PatientHeaderBanner.vue';
import StatCard from '@/Components/StatCard.vue';
import PrintButton from '@/Components/PrintButton.vue';
import AsmedPanel from './Cppt/AsmedPanel.vue';
import NursingCarePanel from './Cppt/NursingCarePanel.vue';
import DiagnosisPanel from './Cppt/DiagnosisPanel.vue';
import ProcedurePanel from './Cppt/ProcedurePanel.vue';
import TimelinePanel from './Cppt/TimelinePanel.vue';
import { url } from '@/router';
import { isBlank } from '@/composables/useFormatting';

const props = defineProps({
    encounterId: { type: String, required: true },
    banner: { type: Object, default: null },
    data: { type: Object, default: () => ({ stats: {}, notes: [] }) },
    completion: { type: Object, default: () => ({}) },
    activeTab: { type: String, default: 'cppt' },
    // Isi keempat panel tab (ASMED / asuhan / diagnosa / prosedur). OPSIONAL
    // supaya halaman versi lama yang tidak mengirim prop ini tetap bisa
    // dirender - tabnya hanya tampil kosong.
    panels: {
        type: Object,
        default: () => ({ asmed: null, nursingCare: null, diagnoses: [], procedures: [] }),
    },
});

const page = usePage();

const DASH = '-';

/* --------------------------------- tab ----------------------------------- */

/** Urutan dan label sama persis dengan #tabBar pada prototype. */
const TABS = [
    { key: 'asmed', label: 'ASMED' },
    { key: 'nursing', label: 'Asuhan Keperawatan' },
    { key: 'diagnosis', label: 'Diagnosa' },
    { key: 'procedure', label: 'Prosedur' },
    { key: 'timeline', label: 'Timeline' },
];

/**
 * prototype: var activeTab = 'asmed';
 *
 * Nama variabelnya ctivePanel (bukan ctiveTab) karena ctiveTab sudah
 * dipakai sebagai prop halaman - yang nilanya tetap 'cppt' dan tidak boleh tertimpa.
 */
const activePanel = ref('asmed');

function isActive(key) {
    return activePanel.value === key;
}

function tabClass(key) {
    return 'tabBtn px-3.5 py-2 rounded-lg text-xs font-bold transition '
        + (isActive(key) ? 'bg-slate-900 text-white shadow' : 'text-slate-600 hover:bg-slate-100');
}

/* --------------------------------- URL ----------------------------------- */

const moduleBase = computed(() => url('cppt', { encounter: props.encounterId }));

const urls = computed(() => ({
    note: `${moduleBase.value}/note`,
    asmed: `${moduleBase.value}/asmed`,
    nursing: `${moduleBase.value}/nursing`,
    diagnosis: `${moduleBase.value}/diagnosis`,
    procedure: `${moduleBase.value}/procedure`,
}));

/* --------------------------------- peran --------------------------------- */

const user = computed(() => {
    const auth = page.props.auth || {};

    return auth.user && typeof auth.user === 'object' ? auth.user : null;
});
const role = computed(() => (user.value?.role ? String(user.value.role) : ''));

/** docs/AUTHORIZATION.md: ASMED / diagnosa / prosedur / CPPT hanya dokter. */
const isDoctor = computed(() => role.value === 'dokter');
/** Asuhan keperawatan terbuka untuk perawat, bidan, dan dokter. */
const canWriteNursing = computed(() => ['perawat', 'bidan', 'dokter'].includes(role.value));

/* ------------------------------- isi panel -------------------------------- */

const asmed = computed(() => props.panels?.asmed ?? null);
const nursingCare = computed(() => props.panels?.nursingCare ?? null);
const diagnoses = computed(() => (Array.isArray(props.panels?.diagnoses) ? props.panels.diagnoses : []));
const procedures = computed(() => (Array.isArray(props.panels?.procedures) ? props.panels.procedures : []));

const notes = computed(() => (Array.isArray(props.data?.notes) ? props.data.notes : []));

/* --------------------------- kelengkapan dokumen -------------------------- */

const COMPLETION_LABELS = [
    { key: 'asmed', label: 'ASMED' },
    { key: 'nursingCare', label: 'Asuhan' },
    { key: 'diagnosis', label: 'Diagnosa' },
    { key: 'procedure', label: 'Prosedur' },
];

const completionChips = computed(() =>
    COMPLETION_LABELS.map((item) => {
        const done = Boolean(props.completion?.[item.key]);

        return {
            key: item.key,
            label: item.label,
            class: done
                ? 'border-emerald-300 bg-emerald-50 text-emerald-700'
                : 'border-amber-300 bg-amber-50 text-amber-700',
        };
    }),
);

const completionCount = computed(() => completionChips.value.filter((chip) => chip.class.startsWith('border-emerald')).length);

const completionStatus = computed(() =>
    completionCount.value === 4
        ? 'Lengkap (4/4)'
        : 'Belum Lengkap (' + completionCount.value + '/4)',
);

/* --------------------------------- kartu ---------------------------------- */

function statValue(value) {
    return isBlank(value) ? DASH : value;
}

const statCards = computed(() => {
    const s = (props.data && typeof props.data === 'object' && props.data.stats) || {};

    return [
        { key: 'asmed', label: 'ASMED', value: statValue(s.asmed), sub: s.asmedDetail || 'belum diisi', icon: 'fa-user-doctor', tone: 'emerald' },
        { key: 'nursingCare', label: 'Asuhan Keperawatan', value: statValue(s.nursingCare), sub: s.nursingCareDetail || 'belum diisi', icon: 'fa-user-nurse', tone: 'sky' },
        { key: 'diagnosis', label: 'Diagnosa', value: s.diagnosis ?? 0, sub: 'item tercatat', icon: 'fa-stethoscope', tone: 'purple' },
        { key: 'procedure', label: 'Prosedur', value: s.procedure ?? 0, sub: 'tindakan tercatat', icon: 'fa-syringe', tone: 'amber' },
        { key: 'ews', label: 'EWS Terakhir', value: statValue(s.ews), sub: s.ewsDetail || 'belum ada observasi', icon: 'fa-triangle-exclamation', tone: 'red' },
        { key: 'observation', label: 'Observasi', value: s.observation ?? 0, sub: 'baris flowsheet', icon: 'fa-chart-line', tone: 'teal' },
    ];
});

/* --------------------------------- simpan --------------------------------- */

/**
 * Muat ulang penuh setelah salah satu panel tab menyimpan. Enam kartu statistik
 * membaca jumlah diagnosa, prosedur, dan asuhan, jadi `only` yang sempit akan
 * meninggalkan angka yang basi.
 */
function reload() {
    router.reload();
}
</script>

<template>
    <Head title="Medis &amp; CPPT" />

    <ClinicalLayout
        title="Medis &amp; CPPT"
        subtitle="SIMRS EMR v4.8 \u2022 Modul Dokumentasi Medis &amp; CPPT"
        active-tab="cppt"
        :encounter-id="encounterId"
    >
        <template #status>
            <PatientHeaderBanner
                :patient="banner"
                status-label="Kelengkapan Dokumentasi"
                status-tone="teal"
                :module-status="completionStatus"
            >
                <template #default>
                    <div class="flex flex-wrap items-center gap-1.5" data-purpose="cppt-completion-bar">
                        <span
                            v-for="chip in completionChips"
                            :key="chip.key"
                            class="rounded border px-2 py-0.5 font-bold"
                            :class="chip.class"
                        >{{ chip.label }}</span>
                        <span class="rounded border border-sky-300 bg-sky-50 px-2 py-0.5 font-bold text-sky-700">
                            {{ completionCount }}/4 terisi
                        </span>
                    </div>
                </template>
                <template #actions>
                    <PrintButton label="Cetak" size="sm" tone="secondary" />
                </template>
            </PatientHeaderBanner>
        </template>

        <!-- ============ CpptStatCards ============ -->
        <section
            class="grid grid-cols-2 gap-3 md:grid-cols-3 lg:grid-cols-6"
            data-purpose="cppt-stats"
        >
            <StatCard
                v-for="card in statCards"
                :key="card.key"
                :label="card.label"
                :value="card.value"
                :sub="card.sub"
                :icon="card.icon"
                :tone="card.tone"
                mono
            />
        </section>

        <!-- ============ #tabBar (cppt.html:191) ============ -->
        <div
            id="tabBar"
            class="no-print flex flex-wrap gap-1 rounded-xl border border-slate-200 bg-white p-1.5"
            role="tablist"
            aria-label="Modul dokumentasi medis"
        >
            <button
                v-for="tab in TABS"
                :key="tab.key"
                type="button"
                :data-tab="tab.key"
                :class="tabClass(tab.key)"
                :aria-selected="isActive(tab.key) ? 'true' : 'false'"
                @click="activePanel = tab.key"
            >{{ tab.label }}</button>
        </div>

        <!-- ============ #panel-* ============ -->
        <section class="rounded-xl border border-slate-200 bg-white p-4">
            <div
                v-show="activePanel === 'asmed'"
                id="panel-asmed"
                class="tabPanel"
                data-purpose="cppt-panel-asmed"
            >
                <AsmedPanel
                    :asmed="asmed"
                    :diagnoses="diagnoses"
                    :can-write="isDoctor"
                    :store-url="urls.asmed"
                    :author-name="user?.name || ''"
                    @saved="reload"
                />
            </div>

            <div
                v-show="activePanel === 'nursing'"
                id="panel-nursing"
                class="tabPanel"
                data-purpose="cppt-panel-nursing"
            >
                <NursingCarePanel
                    :nursing-care="nursingCare"
                    :can-write="canWriteNursing"
                    :store-url="urls.nursing"
                    :author-name="user?.name || ''"
                    @saved="reload"
                />
            </div>

            <div
                v-show="activePanel === 'diagnosis'"
                id="panel-diagnosis"
                class="tabPanel"
                data-purpose="cppt-panel-diagnosis"
            >
                <DiagnosisPanel
                    :diagnoses="diagnoses"
                    :can-write="isDoctor"
                    :store-url="urls.diagnosis"
                    @saved="reload"
                />
            </div>

            <div
                v-show="activePanel === 'procedure'"
                id="panel-procedure"
                class="tabPanel"
                data-purpose="cppt-panel-procedure"
            >
                <ProcedurePanel
                    :procedures="procedures"
                    :can-write="isDoctor"
                    :store-url="urls.procedure"
                    @saved="reload"
                />
            </div>

            <div
                v-show="activePanel === 'timeline'"
                id="panel-timeline"
                class="tabPanel"
                data-purpose="cppt-panel-timeline"
            >
                <TimelinePanel
                    :notes="notes"
                    :can-write="isDoctor"
                    :store-url="urls.note"
                    :patient="banner"
                    :user="user"
                    @saved="reload"
                />
            </div>
        </section>
    </ClinicalLayout>
</template>

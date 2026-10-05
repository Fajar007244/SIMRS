<script setup>
/**
 * Pages/Penjunjang.vue - modul "Penunjang & AGD", enam tab, jalur tulis penuh.
 *
 * Port 1:1 dari phase1/penunjang.html, pemetaan region `data-purpose`:
 *
 *   <header> + tab bar modul   -> ClinicalLayout (active-tab="penunjang")
 *   data-purpose="patient-banner"    -> PatientHeaderBanner di slot `status`
 *   data-purpose="support-stats"    -> SupportStatCards (6 StatCard)
 *   #tabBar (6 tombol)              -> Link ke ?group=lab|blood|abg|micro|rad|trend
 *   panel-lab / panel-blood         -> ResultGroupPanel (tabel + formulir data-labform)
 *   panel-abg                       -> AbgPanel (tabel + #abgForm)
 *   panel-micro                     -> ResultGroupPanel (tabel, badge Pending)
 *   panel-rad                       -> ResultGroupPanel varian kartu (renderRad)
 *   panel-trend                     -> TrendPanel (TrendChart.vue)
 *   data-purpose="abg-summary"      -> AbgSummaryPanel
 *   data-purpose="pending-panel"    -> PendingPanel
 *   data-purpose="abnormal-panel"   -> AbnormalPanel
 *   data-purpose="cppt-handoff"     -> blok tautan ke modul CPPT (url('cppt'))
 *   printBtn (top bar)              -> PrintButton di slot `actions` banner +
 *                                      slot `actions` FilterBar
 *
 * STATE TAB lewat query string (?group=abg) supaya reload membuka tab yang
 * sama. Tab tren memakai ?trendGroup=&trendKey=.
 *
 * CATATAN URL JALUR TULIS: resources/js/router.js (file beku) hanya memuat
 * route baca `penunjang`; lima route POST tidak ada di sana dan tidak boleh
 * ditambah tanpa persetujuan. Karena itu `urls` di bawah membentuk URL dari
 * url('penunjang', ...) + suffix, bukan url('penunjang.result.store', ...).
 */
import { computed, ref } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import ClinicalLayout from '@/Layouts/ClinicalLayout.vue';
import PatientHeaderBanner from '@/Components/PatientHeaderBanner.vue';
import FilterBar from '@/Components/FilterBar.vue';
import PrintButton from '@/Components/PrintButton.vue';
import SupportStatCards from './Penunjang/SupportStatCards.vue';
import ResultGroupPanel from './Penunjang/ResultGroupPanel.vue';
import AbgPanel from './Penunjang/AbgPanel.vue';
import TrendPanel from './Penunjang/TrendPanel.vue';
import AbgSummaryPanel from './Penunjang/AbgSummaryPanel.vue';
import PendingPanel from './Penunjang/PendingPanel.vue';
import AbnormalPanel from './Penunjang/AbnormalPanel.vue';
import ResetConfirmModal from './Penunjang/ResetConfirmModal.vue';
import { url } from '@/router';
import { isBlank } from '@/composables/useFormatting';

const props = defineProps({
    encounterId: { type: String, required: true },
    banner: { type: Object, default: null },
    data: { type: Object, default: () => ({ lab: [], blood: [], micro: [], rad: [] }) },
    abg: { type: Array, default: () => [] },
    latestAbg: { type: Object, default: null },
    summary: { type: Object, default: () => ({}) },
    pending: { type: Array, default: () => [] },
    abnormal: { type: Array, default: () => [] },
    trend: { type: Object, default: () => ({}) },
    trendGroup: { type: String, default: 'lab' },
    trendKey: { type: String, default: '' },
    trendRef: { type: Object, default: () => ({}) },
    trendTargets: { type: Array, default: () => [] },
    catalog: { type: Object, default: () => ({}) },
    activeGroup: { type: String, default: 'lab' },
    activeTab: { type: String, default: 'penunjang' },
});

const page = usePage();

const RESULT_GROUPS = ['lab', 'blood', 'micro', 'rad'];

const TABS = [
    { key: 'lab', label: 'Lab' },
    { key: 'blood', label: 'Darah Lengkap' },
    { key: 'abg', label: 'AGD' },
    { key: 'micro', label: 'Mikroba' },
    { key: 'rad', label: 'Radiologi' },
    { key: 'trend', label: 'Tren' },
];

const GROUP_LABELS = {
    lab: 'Laboratorium',
    blood: 'Darah Lengkap',
    abg: 'AGD',
    micro: 'Mikrobiologi',
    rad: 'Radiologi',
};

const GROUP_LABELS_FORM = {
    lab: 'Laboratorium',
    blood: 'Darah Lengkap',
    micro: 'Mikrobiologi',
    rad: 'Radiologi',
};

/* ---------------------------------- URL ----------------------------------- */

const moduleBase = computed(() => url('penunjang', { encounter: props.encounterId }));

const urls = computed(() => {
    const base = moduleBase.value;

    return {
        resultStore: `${base}/results`,
        resultDestroy: `${base}/results/delete`,
        abgStore: `${base}/abg`,
        abgDestroy: `${base}/abg/delete`,
        reset: `${base}/reset`,
    };
});

const cpptUrl = computed(() => url('cppt', { encounter: props.encounterId }));

function tabHref(key) {
    if (key === 'trend') {
        return url('penunjang', {
            encounter: props.encounterId,
            group: 'trend',
            trendGroup: props.trendGroup,
            trendKey: props.trendKey || null,
        });
    }

    return url('penunjang', { encounter: props.encounterId, group: key });
}

/* --------------------------------- data ---------------------------------- */

const isResultGroup = computed(() => RESULT_GROUPS.includes(props.activeGroup));
const isAbg = computed(() => props.activeGroup === 'abg');
const isTrend = computed(() => props.activeGroup === 'trend');

const activeLabel = computed(() => GROUP_LABELS[props.activeGroup] || 'Penunjang');

function rowsFor(group) {
    return Array.isArray(props.data?.[group]) ? props.data[group] : [];
}

const activeRows = computed(() => (isAbg.value ? props.abg : rowsFor(props.activeGroup)));

/**
 * Opsi item form tulis: katalog ReferenceRange lebih dulu (itu urutan panel
 * prototype), lalu key yang sudah ada di data tetapi tidak ada di katalog
 * (mis. rad, yang memang tidak punya katalog).
 */
function itemsFor(group) {
    const catalog = Array.isArray(props.catalog?.[group]) ? props.catalog[group] : [];
    const extra = [];

    for (const row of rowsFor(group)) {
        if (catalog.some((item) => item.key === row.key)) continue;

        extra.push({ key: row.key, name: row.label || row.key, panel: '-' });
    }

    return [...catalog, ...extra];
}

const resultCount = computed(() => activeRows.value.length);

const supportStatus = computed(() => {
    const total = rowsFor('lab').length + rowsFor('blood').length + rowsFor('micro').length + rowsFor('rad').length;

    return total > 0 ? `Tersimpan (${total} hasil)` : 'Belum ada hasil';
});

/* ------------------------------ kontrol UI -------------------------------- */

const search = ref('');
const refreshing = ref(false);

function refresh() {
    if (refreshing.value) return;
    refreshing.value = true;

    router.reload({
        only: ['data', 'abg', 'summary', 'pending', 'abnormal', 'trend'],
        onFinish: () => {
            refreshing.value = false;
        },
    });
}

function selectTrend(selection) {
    router.get(url('penunjang', {
        encounter: props.encounterId,
        group: 'trend',
        trendGroup: selection.group,
        trendKey: selection.key || null,
    }), {}, { preserveScroll: true });
}

const trendGroupOptions = computed(() => [
    { value: 'lab', label: GROUP_LABELS_FORM.lab },
    { value: 'blood', label: GROUP_LABELS_FORM.blood },
]);

/* --------------------------------- reset ---------------------------------- */

const resetOpen = ref(false);
const resetGroup = ref('');

const resetForm = useForm({ group: '', confirm: '' });

const resetCount = computed(() => {
    if (resetGroup.value === 'abg') return props.abg.length;
    if (RESULT_GROUPS.includes(resetGroup.value)) return rowsFor(resetGroup.value).length;

    return 0;
});

function openReset(group) {
    resetGroup.value = group;
    resetForm.group = group;
    resetForm.confirm = '';
    resetForm.clearErrors();
    resetOpen.value = true;
}

function closeReset() {
    if (resetForm.processing) return;
    resetOpen.value = false;
}

function submitReset() {
    resetForm.transform((data) => ({ ...data, confirm: '1' })).post(urls.value.reset, {
        preserveScroll: true,
        onSuccess: () => {
            resetOpen.value = false;
        },
        onFinish: () => {
            resetForm.confirm = '';
        },
    });
}

/* -------------------------------- notifikasi ------------------------------ */

const resultErrors = computed(() => Object.keys(page.props.errors ?? {}).length);
</script>

<template>
    <Head title="Penunjang &amp; AGD" />

    <ClinicalLayout
        title="Penunjang &amp; AGD"
        subtitle="SIMRS EMR v4.8 \u2022 Modul Penunjang Laboratorium &amp; Analisa Gas Darah"
        active-tab="penunjang"
        :encounter-id="encounterId"
    >
        <template #status>
            <PatientHeaderBanner
                :patient="banner"
                status-label="Hasil Penunjang"
                status-tone="purple"
                :module-status="supportStatus"
                alert-note="Perhatikan sebelum antifibotik"
            >
                <template #actions>
                    <PrintButton label="Cetak Hasil" tone="secondary" />
                </template>
            </PatientHeaderBanner>
        </template>

        <!-- ============ SupportStatCards ============ -->
        <SupportStatCards :summary="summary" :data="data" />

        <div class="grid grid-cols-1 gap-4 lg:grid-cols-12">
            <!-- ============ TAB + PANEL ============ -->
            <section
                class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm lg:col-span-8"
                data-purpose="support-tabs"
            >
                <div id="tabBar" class="scroll-x flex flex-wrap gap-1 border-b border-slate-200 bg-slate-50 p-3 no-print">
                    <Link
                        v-for="tab in TABS"
                        :key="tab.key"
                        :href="tabHref(tab.key)"
                        class="tab-link whitespace-nowrap"
                        :class="tab.key === activeGroup ? 'tab-link-active' : ''"
                        :aria-current="tab.key === activeGroup ? 'page' : undefined"
                        :data-tab="tab.key"
                        :data-active="tab.key === activeGroup ? 'true' : 'false'"
                    >{{ tab.label }}</Link>
                </div>

                <FilterBar
                    v-if="!isTrend"
                    v-model:search="search"
                    :result-count="resultCount"
                    :title="activeLabel"
                    :icon="isAbg ? 'fa-lungs' : 'fa-flask-vial'"
                    :refreshing="refreshing"
                    search-placeholder="Cari item, nilai, atau petugas..."
                    @refresh="refresh"
                >
                    <template #actions>
                        <PrintButton label="Cetak" size="sm" tone="secondary" />
                        <button
                            v-if="!isTrend"
                            type="button"
                            class="btn btn-secondary btn-sm"
                            data-purpose="support-reset-open"
                            @click="openReset(activeGroup)"
                        >
                            <i class="fa-solid fa-rotate-left" aria-hidden="true"></i>Reset
                        </button>
                    </template>
                </FilterBar>

                <div class="p-4">
                    <ResultGroupPanel
                        v-if="isResultGroup"
                        :group="activeGroup"
                        :rows="rowsFor(activeGroup)"
                        :items="itemsFor(activeGroup)"
                        :search="search"
                        :store-url="urls.resultStore"
                        :delete-url="urls.resultDestroy"
                    />

                    <AbgPanel
                        v-else-if="isAbg"
                        :rows="abg"
                        :search="search"
                        :store-url="urls.abgStore"
                        :delete-url="urls.abgDestroy"
                        @reset="openReset('abg')"
                    />

                    <TrendPanel
                        v-else-if="isTrend"
                        :trend="trend"
                        :ref="trendRef"
                        :targets="trendTargets"
                        :group-options="trendGroupOptions"
                        :active-group="trendGroup"
                        :active-key="trendKey"
                        @select="selectTrend"
                    />
                </div>
            </section>

            <!-- ============ KOLOM KANAN ============ -->
            <div class="space-y-4 lg:col-span-4">
                <AbgSummaryPanel :latest="latestAbg" />

                <PendingPanel :rows="pending" />

                <AbnormalPanel :rows="abnormal" />

                <section
                    class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm"
                    data-purpose="cppt-handoff"
                >
                    <div class="flex items-center border-b border-emerald-200 bg-emerald-50 p-3">
                        <h2 class="text-xs font-black uppercase tracking-wide text-emerald-800">
                            <i class="fa-solid fa-user-doctor mr-1.5" aria-hidden="true"></i>Teruskan ke CPPT
                        </h2>
                    </div>
                    <div class="p-3">
                        <p class="mb-2 text-[11px] text-slate-600">
                            Catatan plan ASMED dapat merujuk hasil abnormal di atas.
                        </p>
                        <Link
                            :href="cpptUrl"
                            class="btn btn-success btn-sm no-print"
                            data-purpose="cppt-handoff-link"
                        >
                            <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>Buka Medis &amp; CPPT
                        </Link>
                    </div>
                </section>
            </div>
        </div>

        <ResetConfirmModal
            :open="resetOpen"
            :group="resetGroup"
            :label="GROUP_LABELS[resetGroup] || ''"
            :count="resetCount"
            :processing="resetForm.processing"
            @close="closeReset"
            @confirm="submitReset"
        />
    </ClinicalLayout>
</template>
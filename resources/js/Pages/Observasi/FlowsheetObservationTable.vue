<script setup>
/**
 * FlowsheetObservationTable.vue - region data-purpose="observation-flowsheet"
 * pada phase1/observasi.html: "Rekam Lembar Observasi Klinis EWS ICU
 * (Flowsheet Jam-ke-Jam)".
 *
 * Kolom tabel disalin dari header phase1 (13 kolom + kolom hapus):
 *   Waktu | Kesadaran / RASS | TD (mmHg) | MAP | HR (x/m) | RR (x/m) |
 *   SpO2 (%) | Suhu (C) | Ventilasi / O2 | Intake (mL) | Output (mL) |
 *   EWS Score | Perawat | (hapus)
 *
 * Kolom "Kesadaran / RASS" menampilkan hasil baris flowsheet apa adanya.
 * Perlu diingat: kolom database hanya menyimpan KUNCI enum (`DPO`), sedangkan
 * ObservationService::rowFrom() menambahkan kembali RASS dari
 * ventilator_settings.rass untuk tampilan ("DPO (RASS -2)"). Jadi nilai di
 * tabel sudah berbentuk display, dan yang dikirim formulir tetap kunci enum.
 *
 * FILTER memakai Components/FilterBar.vue. Slot `default` FilterBar diletakkan
 * SEBELUM kotak pencarian, jadi urutan toolbar tetap: filter tanggal/risk,
 * pencarian, Refresh, Cetak, Tambah. Setiap perubahan filter memanggil router.get
 * sehingga filtering terjadi di server (ObservationService::getFlowsheet()) dan
 * URL ikut membawa filter - reload membuka tampilan yang sama.
 *
 * Slot jam: baris yang sedang disorot (dipilih) adalah slot yang dibuka lagi
 * di formulir. Tidak ada confirm dialog untuk kasus ini; ObservationService
 * bersifat UPSERT dan `duplicated` ditampilkan sebagai flash.
 *
 * PROPS
 *   rows        Array   WAJIB  baris flowsheet (TERBARU DI ATAS)
 *   filters     Object  filter aktif { dateFrom, dateTo, risk, hasBundle, medication, search }
 *   refreshing  Boolean tombol refresh sedang berputar
 *   destroyUrl  String  URL endpoint hapus
 *   newObservationLabel Boolean  tampilkan tombol tambah observasi
 *
 * EMITS
 *   filter        Object  filter baru (dikirim lewat router.get oleh halaman)
 *   refresh       void
 *   new-observation void
 */
import { computed, ref, watch } from 'vue';
import DataTableWrap from '@/Components/DataTableWrap.vue';
import EmptyState from '@/Components/EmptyState.vue';
import FilterBar from '@/Components/FilterBar.vue';
import PrintButton from '@/Components/PrintButton.vue';
import EwsBadge from '@/Components/EwsBadge.vue';
import { formatNumber, isBlank } from '@/composables/useFormatting';

const props = defineProps({
    rows: { type: Array, required: true },
    filters: { type: Object, default: () => ({}) },
    refreshing: { type: Boolean, default: false },
    destroyUrl: { type: String, required: true },
    showNewButton: { type: Boolean, default: true },
});

const emit = defineEmits(['filter', 'refresh', 'new-observation', 'destroy']);

const RISK_OPTIONS = [
    { value: '', label: 'Semua risiko' },
    { value: 'low', label: 'Low (0-2)' },
    { value: 'medium', label: 'Medium (3-4)' },
    { value: 'high', label: 'High (5-6)' },
    { value: 'emergency', label: 'Emergency (>=7)' },
];

const BUNDLE_OPTIONS = [
    { value: '', label: 'Bundle: semua' },
    { value: '1', label: 'Bundle: sudah diisi' },
    { value: '0', label: 'Bundle: belum diisi' },
];

/** Filter aktif diletakkan di ref agar bisa diedit tanpa langsung memicu navigasi. */
const draft = ref({
    dateFrom: props.filters?.dateFrom || '',
    dateTo: props.filters?.dateTo || '',
    risk: props.filters?.risk || '',
    hasBundle: props.filters?.hasBundle || '',
    medication: props.filters?.medication || '',
    search: props.filters?.search || '',
});

watch(
    () => props.filters,
    (next) => {
        draft.value = {
            dateFrom: next?.dateFrom || '',
            dateTo: next?.dateTo || '',
            risk: next?.risk || '',
            hasBundle: next?.hasBundle || '',
            medication: next?.medication || '',
            search: next?.search || '',
        };
    },
    { deep: true },
);

const hasActiveFilter = computed(() => {
    const value = draft.value;

    return Boolean(value.dateFrom || value.dateTo || value.risk || value.hasBundle || value.medication);
});

function applyFilters() {
    emit('filter', { ...draft.value });
}

function resetFilters() {
    draft.value = { dateFrom: '', dateTo: '', risk: '', hasBundle: '', medication: '', search: '' };
    emit('filter', { ...draft.value });
}

function text(source, fallback = '-') {
    return isBlank(source) ? fallback : String(source);
}

function numberOrDash(source) {
    if (source === null || source === undefined || isBlank(source)) return '-';

    const value = Number(source);

    return Number.isFinite(value) ? String(value) : '-';
}

/**
 * Tooltip kolom "Ventilasi / O2": sel itu hanya menampilkan breathType +
 * o2Support, jadi pengaturan ventilasi yang disimpan formulir tidak terlihat
 * di sini. row.ventilator datang apa adanya dari ObservationService::rowFrom().
 */
function ventilatorTitle(row) {
    if (isBlank(row?.ventilator)) return null;

    return String(row.ventilator);
}
</script>

<template>
    <section
        class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm"
        data-purpose="observation-flowsheet"
    >
        <FilterBar
            :search="draft.search"
            search-placeholder="Cari jam / perawat..."
            :result-count="rows.length"
            :refreshing="props.refreshing"
            title="Rekam Lembar Observasi Klinis EWS ICU (Flowsheet Jam-ke-Jam)"
            icon="fa-table-list"
            @update:search="draft.search = $event"
            @refresh="emit('refresh')"
        >
            <!-- Slot default: filter lain, diletakkan sebelum kotak pencarian -->
            <div class="flex flex-wrap items-center gap-2" data-purpose="flowsheet-filters">
                <label class="sr-only" for="flowsheet-date-from">Tanggal mulai</label>
                <input
                    id="flowsheet-date-from"
                    v-model="draft.dateFrom"
                    type="date"
                    class="input w-auto"
                    title="Batas bawah tanggal observasi"
                >

                <label class="sr-only" for="flowsheet-date-to">Tanggal akhir</label>
                <input
                    id="flowsheet-date-to"
                    v-model="draft.dateTo"
                    type="date"
                    class="input w-auto"
                    title="Batas atas tanggal observasi"
                >

                <label class="sr-only" for="flowsheet-risk">Tingkat risiko EWS</label>
                <select id="flowsheet-risk" v-model="draft.risk" class="select w-auto" title="Filter tingkat risiko EWS">
                    <option v-for="option in RISK_OPTIONS" :key="option.value" :value="option.value">
                        {{ option.label }}
                    </option>
                </select>

                <label class="sr-only" for="flowsheet-bundle">Status bundle</label>
                <select id="flowsheet-bundle" v-model="draft.hasBundle" class="select w-auto" title="Filter kelengkapan jawaban bundle">
                    <option v-for="option in BUNDLE_OPTIONS" :key="option.value" :value="option.value">
                        {{ option.label }}
                    </option>
                </select>

                <label class="sr-only" for="flowsheet-medication">Nama obat</label>
                <input
                    id="flowsheet-medication"
                    v-model="draft.medication"
                    type="text"
                    class="input w-40"
                    placeholder="Nama obat..."
                    title="Filter berdasarkan nama obat pada koreksi pemberian"
                >

                <button
                    type="button"
                    class="btn btn-primary btn-sm"
                    data-purpose="flowsheet-filter-apply"
                    @click="applyFilters"
                >
                    <i class="fa-solid fa-filter" aria-hidden="true"></i>
                    Terapkan
                </button>

                <button
                    v-if="hasActiveFilter"
                    type="button"
                    class="btn btn-ghost btn-sm"
                    data-purpose="flowsheet-filter-reset"
                    @click="resetFilters"
                >
                    <i class="fa-solid fa-rotate-left" aria-hidden="true"></i>
                    Reset filter
                </button>
            </div>

            <template #subtitle>
                <p class="text-[11px] text-slate-500">
                    Data terhubung langsung dengan bedside monitor dan input perawat pelaksana
                </p>
            </template>

            <!-- Slot actions: Cetak, tombol pemicu formulir, lalu divider.
                 Kelas dan teks tombol pemicu disalin apa adanya dari
                 #openModalBtn pada phase1/observasi.html; divider mengikuti
                 <span class="w-px h-6 bg-slate-300"> di markup yang sama.
                 Slot `actions` dirender SEBELUM tombol Refresh milik
                 FilterBar, jadi urutannya: cetak, tambah, divider, refresh. -->
            <template #actions>
                <PrintButton label="Cetak Dokumen EWS" size="sm" tone="dark" />
                <button
                    v-if="props.showNewButton"
                    id="openModalBtn"
                    type="button"
                    class="inline-flex items-center gap-1.5 rounded-md bg-emerald-600 px-3.5 py-1.5 text-xs font-bold text-white shadow-sm transition hover:bg-emerald-500"
                    data-purpose="flowsheet-new-observation"
                    @click="emit('new-observation')"
                >
                    <i class="fa-solid fa-circle-plus" aria-hidden="true"></i>
                    <span>+ Formulir Tambah Observasi EWS</span>
                </button>
                <span class="h-6 w-px bg-slate-300" aria-hidden="true"></span>
            </template>
        </FilterBar>

        <EmptyState
            v-if="rows.length === 0"
            icon="fa-table-list"
            title="Belum ada observasi"
            message="Belum ada data observasi yang cocok dengan filter. Ubah filter, atau gunakan tombol Tambah Observasi EWS untuk mencatat observasi pada slot jam berikutnya."
        />

        <DataTableWrap v-else :sticky="true" :max-height="'62vh'" table-class="table table-compact row-hover">
            <thead>
                <tr>
                    <th class="th" scope="col">Waktu</th>
                    <th class="th" scope="col">Kesadaran / RASS</th>
                    <th class="th text-center" scope="col">TD (mmHg)</th>
                    <th class="th text-center" scope="col">MAP</th>
                    <th class="th text-center" scope="col">HR (x/m)</th>
                    <th class="th text-center" scope="col">RR (x/m)</th>
                    <th class="th text-center" scope="col">SpO2 (%)</th>
                    <th class="th text-center" scope="col">Suhu (C)</th>
                    <th class="th" scope="col">Ventilasi / O2</th>
                    <th class="th text-right" scope="col">Intake (mL)</th>
                    <th class="th text-right" scope="col">Output (mL)</th>
                    <th class="th text-center" scope="col">EWS Score</th>
                    <th class="th" scope="col">Perawat</th>
                    <th class="th no-print"></th>
                </tr>
            </thead>
            <tbody class="font-mono">
                <tr
                    v-for="row in rows"
                    :key="row.id"
                    class="align-top"
                    data-purpose="flowsheet-row"
                    :data-slot="`${row.date || '-'} ${row.time || '-'}`"
                >
                    <td class="td whitespace-nowrap">
                        <span class="block text-[11px] font-bold text-slate-800">{{ text(row.timeLabel) }}</span>
                        <span class="block text-[10px] text-slate-500">{{ text(row.dateLabel) }}</span>
                    </td>

                    <td class="td text-[11px] text-purple-700">{{ text(row.kesadaran) }}</td>

                    <td class="td text-center font-bold text-slate-800">{{ text(row.bpLabel) }}</td>
                    <td class="td text-center text-slate-600">{{ numberOrDash(row.map) }}</td>
                    <td class="td text-center text-amber-700">{{ numberOrDash(row.hr) }}</td>
                    <td class="td text-center text-slate-700">{{ numberOrDash(row.rr) }}</td>
                    <td class="td text-center text-emerald-700">{{ numberOrDash(row.spo2) }}</td>
                    <td class="td text-center text-slate-700">{{ numberOrDash(row.suhu) }}</td>

                    <td class="td text-[11px]" :title="ventilatorTitle(row)">
                        <span class="block text-slate-700">{{ text(row.breathType) }}</span>
                        <span class="block text-slate-500">{{ text(row.o2Support) }}</span>
                    </td>

                    <td class="td text-right text-sky-700">{{ row.intake === null || row.intake === undefined ? '-' : formatNumber(row.intake) }}</td>
                    <td class="td text-right text-amber-700">{{ row.output === null || row.output === undefined ? '-' : formatNumber(row.output) }}</td>

                    <td class="td text-center">
                        <EwsBadge :total="row.ewsTotal" :risk="row.ewsRisk" size="sm" />
                    </td>

                    <td class="td max-w-[10rem] text-[11px] text-slate-600">
                        <span class="block truncate font-sans" :title="text(row.recordedBy)">
                            {{ text(row.recordedBy) }}
                        </span>
                        <span v-if="row.notes" class="mt-0.5 block truncate text-[10px] text-slate-400" :title="row.notes">
                            {{ row.notes }}
                        </span>
                    </td>

                    <td class="td text-right no-print">
                        <button
                            type="button"
                            class="btn btn-ghost btn-sm text-red-600"
                            :aria-label="`Hapus observasi ${text(row.recordedAtLabel)}`"
                            data-purpose="flowsheet-row-delete"
                            @click="emit('destroy', row)"
                        >
                            <i class="fa-solid fa-trash-can" aria-hidden="true"></i>
                        </button>
                    </td>
                </tr>
            </tbody>
        </DataTableWrap>

        <div class="flex flex-wrap items-center justify-between gap-2 border-t border-slate-200 bg-white px-4 py-2.5 text-xs text-slate-500">
            <span>
                Menampilkan <strong class="font-mono font-bold text-slate-800">{{ formatNumber(rows.length) }}</strong>
                jam observasi episode rawat ini
            </span>
            <span v-if="hasActiveFilter" class="text-amber-700">
                <i class="fa-solid fa-filter mr-1" aria-hidden="true"></i>Filter aktif - jumlah di atas adalah hasil yang sudah difilter
            </span>
        </div>
    </section>
</template>
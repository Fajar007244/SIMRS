<script setup>
/**
 * MedicationTimeline.vue - tabel rekap realisasi pemberian obat & cairan.
 *
 * Port 1:1 dari blok data-purpose="medication-timeline" di
 * phase1/farmasi.html: kepala kartu, dropdown kategori, kotak pencarian,
 * tombol Refresh, tombol Cetak, tabel 7 kolom, dan baris kaki jumlah baris
 * + total volume. Baris kosong memakai kalimat yang sama dengan prototype.
 *
 * CORS KARTU: markup <section> ditulis langsung (bukan SectionCard) karena
 * phase1 memakai kepala kartu BERLATAR (bg-slate-50 / bg-amber-50) sementara
 * SectionCard selalu memakai kepala putih. Baris alatnya FilterBar, yang
 * markup-nya sudah persis sama dengan toolbar phase1
 * (`border-b border-slate-200 bg-slate-50 p-4 no-print` + ikon/judul kiri,
 * kontrol kanan).
 *
 * SUMBER DATA: prop `medications` SUDAH tersaring di server oleh
 * FarmasiController (MedicationRecapService::getAdministrations dengan
 * filter category/search/dateFrom/dateTo dari query string). Komponen ini
 * tidak menyaring ulang - ia hanya MEMANCULKAN filter melalui query string
 * supaya URL bisa disalin dan tombol Refresh tidak menghilangkan pilihan
 * pengguna.
 *
 * PROPS
 *   medications     Array   default []  baris yang sudah tersaring
 *   filters         Object  default {}  { category, search, dateFrom, dateTo }
 *   categoryOptions Array   default []  kategori yang punya baris (urutan
 *                                      MedicationCategory), sudah termasuk
 *                                      nilai filter yang sedang aktif
 *   refreshing      Boolean default false
 *
 * EMITS
 *   'update:filters'  Object  objek filter baru (seluruh kunci)
 *   'refresh'         void    tombol Refresh diklik
 *
 * SLOTS: (tidak ada)
 */
import { computed, ref, watch } from 'vue';
import FilterBar from '@/Components/FilterBar.vue';
import DataTableWrap from '@/Components/DataTableWrap.vue';
import PrintButton from '@/Components/PrintButton.vue';
import EmptyState from '@/Components/EmptyState.vue';
import { medicationTone } from '@/tone';
import { formatNumber, isBlank, dateLabel } from '@/composables/useFormatting';

const props = defineProps({
    medications: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
    categoryOptions: { type: Array, default: () => [] },
    refreshing: { type: Boolean, default: false },
});

const emit = defineEmits(['update:filters', 'refresh']);

const rows = computed(() => (Array.isArray(props.medications) ? props.medications : []));

/** Nilai lokal supaya kontrol tidak berkedip selagi server memuat ulang. */
const localCategory = ref(props.filters?.category ?? '');
const localSearch = ref(props.filters?.search ?? '');

watch(
    () => props.filters,
    (next) => {
        localCategory.value = next?.category ?? '';
        localSearch.value = next?.search ?? '';
    },
);

function onCategory() {
    pushFilters();
}

function onSearch(value) {
    localSearch.value = value;
    pushFilters();
}

function pushFilters() {
    emit('update:filters', {
        category: localCategory.value || null,
        search: localSearch.value || null,
        dateFrom: props.filters?.dateFrom ?? null,
        dateTo: props.filters?.dateTo ?? null,
    });
}

const totalVolume = computed(() =>
    rows.value.reduce((sum, row) => sum + (Number(row?.volume) || 0), 0),
);
</script>

<template>
    <section
        class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm print-border"
        data-purpose="medication-timeline"
    >
        <FilterBar
            title="Rekam Realisasi Pemberian Obat &amp; Cairan (dari Formulir Observasi EWS)"
            icon="fa-table-list"
            :search="localSearch"
            search-placeholder="Cari obat / dosis / perawat..."
            :refreshing="props.refreshing"
            @update:search="onSearch"
            @refresh="emit('refresh')"
        >
            <template #subtitle>
                <span class="block text-[11px] text-slate-500">
                    Data diambil otomatis dari koreksi pemberian obat/cairan yang diisi pada formulir observasi
                </span>
            </template>

            <select
                v-model="localCategory"
                class="select w-48 py-1.5 text-xs"
                aria-label="Saring kategori obat"
                data-purpose="medication-category-filter"
                @change="onCategory"
            >
                <option value="">Semua Kategori</option>
                <option v-for="option in props.categoryOptions" :key="option" :value="option">
                    {{ option }}
                </option>
            </select>

            <template #actions>
                <PrintButton label="Cetak" size="sm" tone="secondary" />
            </template>
        </FilterBar>

        <DataTableWrap :sticky="true" :max-height="520">
            <thead>
                <tr>
                    <th class="th w-[132px]" scope="col">Waktu</th>
                    <th class="th" scope="col">Obat / Cairan</th>
                    <th class="th" scope="col">Kategori</th>
                    <th class="th" scope="col">Dosis</th>
                    <th class="th text-right" scope="col">Volume (mL)</th>
                    <th class="th text-center" scope="col">Status</th>
                    <th class="th" scope="col">Perawat</th>
                </tr>
            </thead>
            <tbody class="font-mono">
                <tr
                    v-for="row in rows"
                    :key="row.id"
                    class="row-hover transition hover:bg-sky-50/60"
                    data-purpose="medication-row"
                >
                    <td class="td font-bold text-slate-800">
                        {{ isBlank(row.observationTime) ? '-' : row.observationTime }}
                        <span class="block font-sans text-[10px] font-normal text-slate-400">
                            {{ dateLabel(row.observationDate) }}
                        </span>
                    </td>
                    <td class="td font-sans font-semibold text-slate-900">{{ row.name }}</td>
                    <td class="td font-sans">
                        <span
                            class="rounded px-1.5 py-0.5 text-[10px] font-bold"
                            :class="medicationTone(row.category).chip"
                        >{{ row.categoryLabel || row.category }}</span>
                    </td>
                    <td class="td font-sans text-slate-600">{{ row.dose }}</td>
                    <td class="td text-right font-bold text-sky-700">{{ formatNumber(row.volume ?? 0) }}</td>
                    <td class="td text-center">
                        <span class="rounded border border-emerald-200 bg-emerald-100 px-2 py-0.5 text-[11px] font-bold text-emerald-800">
                            {{ isBlank(row.status) ? 'Diberikan' : row.status }}
                        </span>
                    </td>
                    <td class="td font-sans text-slate-600">{{ row.recordedBy }}</td>
                </tr>

                <tr v-if="rows.length === 0">
                    <td class="td" colspan="7">
                        <EmptyState
                            icon="fa-file-lines"
                            title="Belum ada data pemberian obat pada observasi episode ini"
                            message="Tambahkan koreksi pemberian obat/cairan pada formulir observasi EWS."
                        />
                    </td>
                </tr>
            </tbody>
        </DataTableWrap>

        <div
            class="flex flex-wrap items-center justify-between gap-2 border-t border-slate-200 bg-white px-4 py-2.5 text-xs text-slate-500"
        >
            <span>
                Menampilkan
                <strong class="font-bold text-slate-800">{{ formatNumber(rows.length) }}</strong>
                baris pemberian obat episode ini
            </span>
            <span>
                Total volume:
                <strong class="font-bold text-sky-700">{{ formatNumber(totalVolume) }}</strong> mL
            </span>
        </div>
    </section>
</template>

<script setup>
/**
 * BundleHistoryTable.vue - riwayat kepatuhan bundle per observasi.
 *
 * Port 1:1 dari blok data-purpose="bundle-history" dan renderHistory() di
 * phase1/bundles.html: baris alat (dropdown grup, pencarian, Refresh, Cetak),
 * tabel per observasi, dan baris kaki jumlah baris.
 *
 * FILTER diterapkan di sisi klien karena BundleService::getHistory() tidak
 * menerima filter apa pun. Nilainya tetap dibawa lewat query string
 * (group / search) supaya pilihan pengguna bertahan saat halaman dimuat
 * ulang, dan setiap perubahan menulis ulang query string lewat url() dari
 * router.js.
 *
 * BATAS KONTRAK: baris history hanya berisi observationId, at, label, vap,
 * clabsi, cauti, dan overall. Kolom "Perawat" dan "Item Terilai" phase1
 * tidak punya sumber di payload, jadi keduanya tidak ditampilkan. Pencarian
 * karena itu mencocokkan label waktu (d/m H:i) yang sudah tersedia.
 *
 * FILTER GRUP: service menyimpan 0.0 untuk grup yang tidak dijawab pada
 * sebuah observasi, dan tidak ada penanda "dijawab / tidak" per baris. Grup
 * karena itu disaring dengan "persen grup lebih besar dari nol", dan
 * keterangan itu dituliskan di bawah tabel supaya tidak menyesatkan.
 *
 * PROPS
 *   history     Array   default []  getHistory($enc, 7)
 *   filters     Object  default {}  { group, search }
 *   refreshing  Boolean default false
 *
 * EMITS
 *   'update:filters'  Object  objek filter baru
 *   'refresh'         void
 *
 * SLOTS: (tidak ada)
 */
import { computed, ref, watch } from 'vue';
import FilterBar from '@/Components/FilterBar.vue';
import DataTableWrap from '@/Components/DataTableWrap.vue';
import PrintButton from '@/Components/PrintButton.vue';
import EmptyState from '@/Components/EmptyState.vue';
import ComplianceBar from '@/Components/ComplianceBar.vue';
import { percentTone } from '@/tone';
import { formatNumber } from '@/composables/useFormatting';

const props = defineProps({
    history: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
    refreshing: { type: Boolean, default: false },
});

const emit = defineEmits(['update:filters', 'refresh']);

const GROUPS = [
    { value: 'vap', short: 'VAP' },
    { value: 'clabsi', short: 'CLABSI' },
    { value: 'cauti', short: 'CAUTI' },
];

const rows = computed(() => (Array.isArray(props.history) ? props.history : []));

const localGroup = ref(props.filters?.group ?? '');
const localSearch = ref(props.filters?.search ?? '');

watch(
    () => props.filters,
    (next) => {
        localGroup.value = next?.group ?? '';
        localSearch.value = next?.search ?? '';
    },
);

function onSearch(value) {
    localSearch.value = value;
    pushFilters();
}

function pushFilters() {
    emit('update:filters', {
        group: localGroup.value || null,
        search: localSearch.value || null,
    });
}

const activeGroup = computed(() => localGroup.value || '');

/** Baris setelah filter grup dan pencarian; urutan asli: terbaru di atas. */
const filtered = computed(() => {
    const group = activeGroup.value;
    const term = String(localSearch.value || '').trim().toLowerCase();

    return rows.value.filter((row) => {
        if (group && !(Number(row?.[group]) > 0)) return false;
        if (term === '') return true;

        return String(row?.label ?? '').toLowerCase().includes(term);
    });
});

/** Kepatuhan utama: persen grup yang dipilih, atau keseluruhan. */
function mainPercent(row) {
    const group = activeGroup.value;
    const value = group ? Number(row?.[group]) : Number(row?.overall);

    return Number.isFinite(value) ? value : 0;
}

function chip(percent) {
    return percentTone(percent).chip;
}
</script>

<template>
    <section
        class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm print-border"
        data-purpose="bundle-history"
    >
        <FilterBar
            title="Riwayat Evaluasi Kepatuhan Bundle per Observasi"
            icon="fa-table-list"
            :search="localSearch"
            search-placeholder="Cari waktu observasi..."
            :refreshing="props.refreshing"
            @update:search="onSearch"
            @refresh="emit('refresh')"
        >
            <template #subtitle>
                <span class="block text-[11px] text-slate-500">
                    Rekapitulasi pengisian bundle HAIs pada setiap formulir observasi EWS episode ini
                </span>
            </template>

            <select
                v-model="localGroup"
                class="select w-40 py-1.5 text-xs"
                aria-label="Saring kelompok bundle"
                data-purpose="bundle-group-filter"
                @change="pushFilters"
            >
                <option value="">Semua Bundle</option>
                <option v-for="group in GROUPS" :key="group.value" :value="group.value">
                    {{ group.short }}
                </option>
            </select>

            <template #actions>
                <PrintButton label="Cetak" size="sm" tone="secondary" />
            </template>
        </FilterBar>

        <DataTableWrap :sticky="true" :max-height="520">
            <thead>
                <tr>
                    <th class="th w-[140px]" scope="col">Waktu</th>
                    <th class="th text-center" scope="col">VAP</th>
                    <th class="th text-center" scope="col">CLABSI</th>
                    <th class="th text-center" scope="col">CAUTI</th>
                    <th class="th" scope="col">Kepatuhan</th>
                </tr>
            </thead>
            <tbody class="font-mono">
                <tr
                    v-for="(row, index) in filtered"
                    :key="row.observationId ?? index"
                    class="row-hover transition"
                    :class="index === 0 && !activeGroup ? 'bg-teal-50/40' : ''"
                    data-purpose="bundle-history-row"
                >
                    <td class="td font-bold text-slate-800">
                        {{ row.label || '-' }}
                        <span class="block font-sans text-[10px] font-normal text-slate-400">
                            observasi #{{ row.observationId }}
                        </span>
                    </td>
                    <td
                        v-for="group in ['vap', 'clabsi', 'cauti']"
                        :key="group"
                        class="td text-center"
                    >
                        <span
                            v-if="Number(row[group]) > 0"
                            class="rounded px-1.5 py-0.5 text-[10px] font-bold"
                            :class="chip(Number(row[group]))"
                        >{{ Math.round(Number(row[group])) }}%</span>
                        <span v-else class="text-slate-300">-</span>
                    </td>
                    <td class="td">
                        <ComplianceBar
                            :percent="mainPercent(row)"
                            :height="8"
                            :label-text="''"
                        />
                    </td>
                </tr>

                <tr v-if="rows.length === 0">
                    <td class="td" colspan="5">
                        <EmptyState
                            icon="fa-file-lines"
                            title="Belum ada data evaluasi bundle pada observasi episode ini"
                            message="Tambahkan penilaian bundle pada formulir observasi EWS."
                        />
                    </td>
                </tr>

                <tr v-else-if="filtered.length === 0">
                    <td class="td" colspan="5">
                        <EmptyState
                            icon="fa-magnifying-glass"
                            title="Tidak ada baris yang cocok dengan filter yang dipilih"
                            message="Ubah kelompok bundle atau kata kunci pencarian."
                        />
                    </td>
                </tr>
            </tbody>
        </DataTableWrap>

        <div
            class="flex flex-wrap items-center justify-between gap-2 border-t border-slate-200 bg-white px-4 py-2.5 text-xs text-slate-500"
        >
            <span>
                Menampilkan <strong class="font-bold text-slate-800">{{ formatNumber(filtered.length) }}</strong>
                baris dari <strong class="font-bold text-slate-800">{{ formatNumber(rows.length) }}</strong>
                observasi
            </span>
            <span v-if="activeGroup" class="field-hint mt-0">
                Filter {{ activeGroup.toUpperCase() }}: hanya observasi dengan kepatuhan grup di atas 0% yang ditampilkan.
            </span>
        </div>
    </section>
</template>

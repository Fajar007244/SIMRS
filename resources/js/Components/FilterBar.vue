<script setup>
/*
 * CATATAN ICON: nilai prop icon dipakai apa adanya. Prefix a-solid HANYA
 * ditambahkan bila nilainya tidak mengandung substring a- sama sekali, jadi
 * a-pills TIDAK menjadi a-solid fa-pills (panggil dengan bentuk lengkap
 * bila butuh gaya fontawesome tertentu).
 */
/**
 * FilterBar.vue - bar alat di atas tabel (pencarian + dropdown + tombol).
 * Port dari toolbar phase1 (bundles.html / farmasi.html / observasi.html):
 *   <div class="p-4 bg-slate-50 border-b border-slate-200 flex flex-wrap items-center justify-between gap-3 no-print">
 *     <div class="flex items-center gap-2">
 *       <i class="fa-solid fa-table-list text-sky-600 text-lg"></i> ...judul...
 *     </div>
 *     <div class="flex items-center gap-2">
 *       <select>...</select>
 *       <div class="relative">
 *         <input class="... pl-7 pr-3 ..." placeholder="Cari ..." type="text"/>
 *         <i class="fa-solid fa-magnifying-glass text-slate-400 text-xs absolute left-2.5 top-2.5"></i>
 *       </div>
 *       <button class="bg-sky-600 hover:bg-sky-700 text-white ...">Refresh</button>
 *       <button class="bg-white ... border border-slate-300 ...">Cetak</button>
 *     </div>
 *   </div>
 *
 * PROPS
 *   search           String  default ''     dicerna v-model:search
 *   searchPlaceholder String default 'Cari...' teks placeholder kotak pencarian
 *   resultCount      Number  default null   jumlah baris hasil filter
 *   title            String  default ''     judul di kiri (opsional)
 *   icon             String  default ''     kelas ikon FontAwesome utuh di kiri title
 *   showRefresh      Boolean default true   tampilkan tombol Refresh
 *   refreshLabel     String  default 'Refresh'
 *   refreshing       Boolean default false  memutar ikon saat memuat
 *   countLabel       String  default 'hasil' teks sebelum resultCount
 *
 * EMITS
 *   'update:search'  String  nilai baru kotak pencarian (untuk v-model:search)
 *   'refresh'        void    tombol Refresh diklik
 *
 * SLOTS
 *   default   Dropdown / filter lain, diletakkan sebelum kotak pencarian
 *   actions   Tombol tambahan, diletakkan setelah kotak pencarian
 *   subtitle  Keterangan kecil di bawah `title` (slot tambahan, opsional)
 *
 * CATATAN: komponen ini TIDAK merender tombol Cetak. Taruh <PrintButton/>
 * di slot `actions` bila halaman punya tombol cetak (phase1 punya).
 */
import { computed, ref, watch } from 'vue';

const props = defineProps({
    search: { type: String, default: '' },
    searchPlaceholder: { type: String, default: 'Cari...' },
    resultCount: { type: Number, default: null },
    title: { type: String, default: '' },
    icon: { type: String, default: '' },
    showRefresh: { type: Boolean, default: true },
    refreshLabel: { type: String, default: 'Refresh' },
    refreshing: { type: Boolean, default: false },
    countLabel: { type: String, default: 'hasil' },
});

const emit = defineEmits(['update:search', 'refresh']);

// Nilai lokal supaya parent yang tidak memakai v-model tetap bisa mengetik.
const local = ref(props.search ?? '');

watch(
    () => props.search,
    (next) => {
        local.value = next ?? '';
    },
);

function onInput(event) {
    local.value = event.target.value;
    emit('update:search', local.value);
}

function onRefresh() {
    emit('refresh');
}

const iconClass = computed(() => {
    const raw = (props.icon || '').trim();
    if (!raw) return '';
    return raw.includes('fa-') ? raw : `fa-solid ${raw}`;
});

const countText = computed(() => (props.resultCount === null || props.resultCount === undefined ? '' : String(props.resultCount)));
</script>

<template>
    <div
        class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 bg-slate-50 p-4 no-print"
        data-purpose="filter-bar"
    >
        <div v-if="props.title || iconClass || countText" class="flex items-center gap-2">
            <i v-if="iconClass" class="text-lg text-sky-600" :class="iconClass" aria-hidden="true"></i>
            <div v-if="props.title">
                <h2 class="text-sm font-bold text-slate-900">{{ props.title }}</h2>
                <slot name="subtitle"></slot>
            </div>
            <span v-if="countText" class="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-bold text-slate-600">
                {{ countText }} {{ props.countLabel }}
            </span>
        </div>
        <div v-else-if="$slots.subtitle" class="flex items-center gap-2">
            <slot name="subtitle"></slot>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <slot></slot>

            <div class="relative">
                <input
                    class="input w-44 py-1.5 pl-7 text-xs"
                    type="search"
                    :value="local"
                    :placeholder="props.searchPlaceholder"
                    :aria-label="props.searchPlaceholder"
                    data-purpose="filter-search"
                    @input="onInput"
                />
                <i class="fa-solid fa-magnifying-glass pointer-events-none absolute left-2.5 top-2.5 text-xs text-slate-400" aria-hidden="true"></i>
            </div>

            <slot name="actions"></slot>

            <button
                v-if="props.showRefresh"
                type="button"
                class="btn btn-primary btn-sm"
                data-purpose="filter-refresh"
                @click="onRefresh"
            >
                <i class="fa-solid fa-arrows-rotate" :class="props.refreshing ? 'anim-spin' : ''" aria-hidden="true"></i>
                {{ props.refreshLabel }}
            </button>
        </div>
    </div>
</template>
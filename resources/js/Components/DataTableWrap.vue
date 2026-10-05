<script setup>
/**
 * DataTableWrap.vue - pembungkus tabel klinis yang lebar.
 * Port dari phase1: <div class="overflow-x-auto"> + header tabel sticky.
 *
 * KONTRAK SLOT (penting): slot HANYA berisi <thead> / <tbody> / <tfoot> /
 * <caption>. Komponen ini yang membuat elemen <table> nya, supaya kelas
 * `.table` dan `.table-sticky` selalu terpasang. JANGAN menaruh <table>
 * di dalam slot - itu akan menghasilkan tabel bersarang.
 *
 * Contoh:
 *   <DataTableWrap :max-height="520">
 *     <thead><tr><th>Waktu</th><th>EWS</th></tr></thead>
 *     <tbody><tr v-for="row in rows" :key="row.id"><td>{{ row.at }}</td><td>{{ row.ews }}</td></tr></tbody>
 *   </DataTableWrap>
 *
 * PROPS
 *   sticky     Boolean        default true   header menempel saat di-scroll
 *   maxHeight  [Number,String] default null  batas tinggi + scroll vertikal.
 *                                         Number = px. String = nilai CSS apa
 *                                         adanya, mis. '60vh' atau 'calc(100vh-220px)'.
 *   tableClass String         default 'table' kelas pada <table> (digabung dengan
 *                                         'table-sticky' bila sticky)
 *   class      String         default ''     kelas tambahan pada wrapper
 *
 * SLOTS
 *   default  isi <table>: <caption>?, <thead>?, <tbody>, <tfoot>?
 *
 * EMITS : (tidak ada)
 */
const props = defineProps({
    sticky: { type: Boolean, default: true },
    maxHeight: { type: [Number, String], default: null },
    tableClass: { type: String, default: 'table' },
    class: { type: String, default: '' },
});
</script>

<template>
    <div
        class="w-full overflow-x-auto"
        :class="[props.sticky ? 'overflow-y-auto' : '', props.class]"
        :style="props.maxHeight === null || props.maxHeight === ''
            ? undefined
            : (typeof props.maxHeight === 'number' ? { maxHeight: `${props.maxHeight}px` } : { maxHeight: String(props.maxHeight) })"
        data-purpose="data-table-wrap"
    >
        <table :class="[props.tableClass || 'table', props.sticky ? 'table-sticky' : '']">
            <slot></slot>
        </table>
    </div>
</template>
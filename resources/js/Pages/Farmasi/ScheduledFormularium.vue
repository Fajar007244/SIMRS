<script setup>
/**
 * ScheduledFormularium.vue - daftar obat terjadwal unit ICU.
 *
 * Port 1:1 dari blok data-purpose="scheduled-formularium" dan
 * renderFormularium() di phase1/farmasi.html: kepala kartu dengan chip jumlah
 * item, tabel enam kolom, dan baris kaki yang menjelaskan kolom Realisasi.
 *
 * Kolom Realisasi memakai `realizationLabel` hasil
 * MedicationRecapService::getFormularium(), yaitu "N x diberikan" atau
 * "Belum tercatat" - persis hitungan countRealizations() prototype. Badge
 * hijau dipakai saat ada realisasi, abu-abu saat belum.
 *
 * Kolom Rute, Target, dan Status diambil dari prop `meta`, yaitu helper UI
 * yang FarmasiController isi dari config('formularium'). Field-field itu ada
 * di konfigurasi unit tetapi tidak dikembalikan service, jadi tanpa `meta`
 * ketiga kolom akan kosong padahal datanya sebenarnya tersedia.
 *
 * PROPS
 *   formularium  Array  default []  getFormularium()
 *   meta         Object default {}  peta nama obat kecil ke
 *                                       { route, target, status }
 *
 * SLOTS: (tidak ada)
 * EMITS : (tidak ada)
 */
import { computed } from 'vue';
import DataTableWrap from '@/Components/DataTableWrap.vue';
import EmptyState from '@/Components/EmptyState.vue';
import { medicationTone } from '@/tone';
import { formatNumber, isBlank } from '@/composables/useFormatting';

const props = defineProps({
    formularium: { type: Array, default: () => [] },
    meta: { type: Object, default: () => ({}) },
});

const rows = computed(() => {
    const list = Array.isArray(props.formularium) ? props.formularium : [];
    const meta = props.meta && typeof props.meta === 'object' ? props.meta : {};

    return list.map((row) => {
        const key = String(row?.name ?? '').trim().toLowerCase();
        const extra = meta[key] || {};

        return {
            ...row,
            route: extra.route ?? null,
            target: extra.target ?? null,
            status: extra.status ?? null,
        };
    });
});

const dash = (value) => (isBlank(value) ? '-' : value);
</script>

<template>
    <section
        class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm print-border"
        data-purpose="scheduled-formularium"
    >
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 bg-slate-50 p-4">
            <div class="flex items-center gap-2">
                <i class="fa-solid fa-book-medical text-lg text-amber-600" aria-hidden="true"></i>
                <div>
                    <h2 class="text-sm font-bold text-slate-900">Daftar Obat Terjadwal &amp; Formularium Unit</h2>
                    <p class="text-xs text-slate-500">
                        Formularium obat terjadwal unit ICU beserta jumlah realisasi pemberian pada observasi EWS
                    </p>
                </div>
            </div>
            <span
                class="rounded-full bg-amber-100 px-2.5 py-1 text-[10px] font-bold text-amber-800"
                data-purpose="formularium-count"
            >{{ formatNumber(rows.length) }} item obat</span>
        </div>

        <DataTableWrap :sticky="true">
            <thead>
                <tr>
                    <th class="th" scope="col">Obat / Konsentrasi</th>
                    <th class="th" scope="col">Rute</th>
                    <th class="th" scope="col">Dosis</th>
                    <th class="th" scope="col">Target</th>
                    <th class="th" scope="col">Status</th>
                    <th class="th text-center" scope="col">Realisasi</th>
                </tr>
            </thead>
            <tbody>
                <tr
                    v-for="row in rows"
                    :key="row.name"
                    class="row-hover transition hover:bg-slate-50"
                    data-purpose="formularium-row"
                >
                    <td class="td font-bold text-slate-900">
                        {{ row.name }}
                        <span
                            class="ml-1.5 rounded px-1.5 py-0.5 text-[10px] font-bold"
                            :class="medicationTone(row.category).chip"
                        >{{ row.categoryLabel || row.category }}</span>
                    </td>
                    <td class="td font-sans text-slate-600">{{ dash(row.route) }}</td>
                    <td class="td font-mono text-slate-700">{{ row.dose }}</td>
                    <td class="td font-sans text-slate-600">{{ dash(row.target) }}</td>
                    <td class="td font-sans font-bold text-emerald-700">{{ dash(row.status) }}</td>
                    <td class="td text-center">
                        <span
                            v-if="row.givenCount > 0"
                            class="inline-flex items-center gap-1 rounded border border-emerald-200 bg-emerald-100 px-2 py-0.5 text-[10px] font-bold text-emerald-700"
                        >
                            <i class="fa-solid fa-check" aria-hidden="true"></i>
                            {{ row.realizationLabel }}
                        </span>
                        <span
                            v-else
                            class="inline-flex items-center gap-1 rounded border border-slate-200 bg-slate-100 px-2 py-0.5 text-[10px] font-bold text-slate-500"
                        >{{ row.realizationLabel }}</span>
                    </td>
                </tr>

                <tr v-if="rows.length === 0">
                    <td class="td" colspan="6">
                        <EmptyState
                            icon="fa-book-medical"
                            title="Belum ada formularium obat terjadwal pada unit ini"
                            message="Formularium unit ICU belum memuat item untuk episode perawatan ini."
                        />
                    </td>
                </tr>
            </tbody>
        </DataTableWrap>

        <div
            class="flex flex-wrap items-center justify-between gap-2 border-t border-slate-200 bg-white px-4 py-2.5 text-xs text-slate-500"
        >
            <span>
                Kolom <strong class="font-bold text-slate-800">Realisasi</strong> dihitung dari koreksi pemberian obat
                pada formulir observasi EWS.
            </span>
        </div>
    </section>
</template>

<script setup>
/**
 * QuickWidgets.vue - region data-purpose="quick-widgets" pada
 * phase1/observasi.html (kolom kanan, 4 dari 12 grid).
 *
 * Prototype memuat dua kartu di sini: "Drip Obat Titrasi & Sedasi Aktif" dan
 * "Rekapitulasi Cairan 24 Jam". Modul ini menambah dua hal yang diminta
 * halaman observasi:
 *
 *  1. KARTU SKOR EWS - skor + badge risiko dari observasi terakhir
 *     (telemetry.latest). Memakai Components/EwsBadge.vue supaya warna badge
 *     identik dengan kolom EWS Score di flowsheet.
 *  2. (dihapus) kartu "Aksi Cepat" - tombol tambah observasi, cetak, dan
 *     tautan modul Farmasi/Bundles. Kartu ini dihapus dari UI, tapi prop
 *     farmasiUrl/bundlesUrl dan emit new-observation TETAP dideklarasikan
 *     karena FRONTEND_CONTRACT.md menjanjikannya.
 *
 * Seluruh angka turunan dihitung di sisi klien dari `rows` (flowsheet lengkap)
 * memakai aturan yang sama dengan phase1: intake/output dijumlahkan untuk
 * seluruh baris episode, dan balance = intake - output. Tidak ada
 * MedicationRecapService::getFluidBalance() di prop halaman ini, jadi angka
 * 24 jam di bawah adalah rekap SELURUH episode, dan judulnya menyebutkan itu
 * supaya tidak disalahartikan sebagai jendela 24 jam bergeser.
 *
 * PROPS
 *   latest   Object|null  observasi terakhir
 *   rows     Array        baris flowsheet (TERBARU DI ATAS)
 *   farmasiUrl  String    tautan modul Farmasi   (TIDAK dipakai di template lagi)
 *   bundlesUrl  String    tautan modul Bundles   (TIDAK dipakai di template lagi)
 *
 * EMITS
 *   new-observation  void  tombol "Formulir Tambah Observasi EWS" diklik
 *                              (tidak ada pemanggil di template lagi)
 */
import { computed } from 'vue';
import EwsBadge from '@/Components/EwsBadge.vue';
import { EWS_MAX_TOTAL, riskLongLabel, EWS_RISK_LONG_LABELS } from '@/tone';
import { formatVolume, formatNumber, isBlank, EMPTY } from '@/composables/useFormatting';

const props = defineProps({
    latest: { type: Object, default: null },
    rows: { type: Array, default: () => [] },
    farmasiUrl: { type: String, default: '#' },
    bundlesUrl: { type: String, default: '#' },
});

const emit = defineEmits(['new-observation']);

const hasData = computed(() => props.latest !== null && props.latest !== undefined);

const total = computed(() => (hasData.value ? Number(props.latest.ewsTotal) : null));
const risk = computed(() => (hasData.value ? String(props.latest.ewsRisk || '') : ''));

const riskSentence = computed(() => {
    if (!hasData.value) return 'Belum ada observasi tercatat pada episode ini.';
    if (risk.value === 'none') return EWS_RISK_LONG_LABELS.none;

    return riskLongLabel(risk.value);
});

const recordedAt = computed(() => {
    if (!hasData.value) return '-';
    if (!isBlank(props.latest.recordedAtLabel)) return String(props.latest.recordedAtLabel);

    return '-';
});

/**
 * Rekap cairan seluruh episode, dijumlahkan dari baris flowsheet.
 * Baris dengan intake/output kosong diabaikan, sama seperti
 * parseFloat(x) || 0 di phase1.
 */
const fluid = computed(() => {
    const list = Array.isArray(props.rows) ? props.rows : [];

    let intake = 0;
    let output = 0;

    for (const row of list) {
        intake += Number(row.intake) || 0;
        output += Number(row.output) || 0;
    }

    return {
        intake,
        output,
        balance: intake - output,
        hours: list.length,
    };
});

/**
 * Drip obat aktif: nama obat unik dari observasi terbaru yang punya daftar
 * obat. phase1 memakai store.getActiveMedications(); di sini sumbernya baris
 * flowsheet yang sudah memuat `medicationNames` dan `medicationCount`.
 */
const drips = computed(() => {
    const list = Array.isArray(props.rows) ? props.rows : [];
    const seen = new Set();
    const result = [];

    for (const row of list) {
        const names = Array.isArray(row.medicationNames) ? row.medicationNames : [];

        for (const name of names) {
            const key = String(name || '').trim().toLowerCase();

            if (!key || seen.has(key)) continue;

            seen.add(key);
            result.push({
                name: String(name),
                at: row.recordedAtLabel || row.timeLabel || '-',
                count: Number(row.medicationCount) || 1,
            });
        }
    }

    return result.slice(0, 6);
});

/**
 * Ventilasi dan tindakan keperawatan dari observasi TERAKHIL. Keduanya
 * sebenarnya sudah tersimpan: ObservationService::rowFrom() mengembalikan
 * `ventilator` dan `nursingAction`, dan karena getTelemetry() memakai
 * rowFrom() yang sama, keduanya ikut pada `telemetry.latest`. Yang belum ada
 * hanyalah tempat menampilkannya - kartu ini menutup celah itu tanpa
 * menyentuh data.
 */
const ventilator = computed(() => (isBlank(props.latest?.ventilator) ? '' : String(props.latest.ventilator)));

/** Petunjuk saat kosong: bedakan "belum ada observasi" dari "observasi ini
 * tidak punya isian ventilasi" supaya petugas tahu mana yang terjadi. */
const ventilatorHint = computed(() => (hasData.value
    ? 'Belum ada pengaturan ventilasi pada observasi terakhir.'
    : 'Belum ada observasi tercatat pada episode ini.'));

const nursing = computed(() => {
    if (!hasData.value) return 'Belum ada observasi tercatat pada episode ini.';
    if (isBlank(props.latest.nursingAction)) return EMPTY;

    return String(props.latest.nursingAction);
});
</script>

<template>
    <div class="space-y-4 lg:col-span-4" data-purpose="quick-widgets">
        <!-- ============ KARTU SKOR EWS ============ -->
        <section class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm" data-purpose="ews-score-card">
            <div class="mb-3 flex items-center justify-between gap-2 border-b border-slate-100 pb-2">
                <h3 class="flex items-center gap-1.5 text-xs font-bold uppercase tracking-wider text-slate-900">
                    <i class="fa-solid fa-heart-pulse text-red-600" aria-hidden="true"></i>
                    Skor EWS Terkini
                </h3>
                <span class="rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-bold text-slate-500">
                    Tiap 1 Jam
                </span>
            </div>

            <div class="flex items-center justify-between gap-3">
                <div class="min-w-0">
                    <p class="text-[10px] font-bold uppercase text-slate-500">Total skor</p>
                    <p class="font-mono text-4xl font-black leading-tight text-slate-900" data-purpose="latest-ews-total">
                        {{ hasData ? (total ?? '-') : '-' }}
                    </p>
                    <p class="text-[11px] text-slate-500">
                        Plafon skala <span class="font-mono font-bold">/ {{ EWS_MAX_TOTAL }}</span>
                    </p>
                </div>
                <EwsBadge
                    :total="hasData ? total : null"
                    :risk="risk"
                    size="lg"
                >
                    <template #suffix>
                        <span class="opacity-75">/ {{ EWS_MAX_TOTAL }}</span>
                    </template>
                </EwsBadge>
            </div>

            <p class="mt-2 rounded-lg border border-slate-200 bg-slate-50 px-2.5 py-1.5 text-[11px] text-slate-600" data-purpose="latest-risk-sentence">
                {{ riskSentence }}
            </p>

            <dl class="mt-2 grid grid-cols-2 gap-2 text-[11px]">
                <div class="rounded-lg border border-slate-200 px-2 py-1.5">
                    <dt class="text-slate-500">Waktu</dt>
                    <dd class="font-mono font-bold text-slate-800">{{ recordedAt }}</dd>
                </div>
                <div class="rounded-lg border border-slate-200 px-2 py-1.5">
                    <dt class="text-slate-500">Petugas</dt>
                    <dd class="truncate font-bold text-slate-800">
                        {{ hasData && !isBlank(latest.recordedBy) ? latest.recordedBy : '-' }}
                    </dd>
                </div>
            </dl>
        </section>

        <!-- ============ DRIP OBAT ============ -->
        <section class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm" data-purpose="active-drip-list">
            <div class="mb-3 flex items-center justify-between gap-2 border-b border-slate-100 pb-2">
                <h3 class="flex items-center gap-1.5 text-xs font-bold uppercase tracking-wider text-slate-900">
                    <i class="fa-solid fa-syringe text-purple-600" aria-hidden="true"></i>
                    Drip Obat Titrasi &amp; Sedasi Aktif
                </h3>
                <span class="rounded bg-purple-100 px-1.5 py-0.5 text-[10px] font-bold text-purple-700">
                    Syringe Pump
                </span>
            </div>

            <p v-if="drips.length === 0" class="text-xs text-slate-500">Belum ada drip aktif.</p>

            <ul v-else class="space-y-2.5">
                <li
                    v-for="drip in drips"
                    :key="drip.name"
                    class="flex items-center justify-between gap-2 rounded-lg border border-slate-200/80 bg-slate-50 p-2.5"
                >
                    <div class="min-w-0">
                        <p class="truncate text-xs font-bold text-slate-800">{{ drip.name }}</p>
                        <p class="text-[11px] text-slate-500">
                            Tercatat {{ formatNumber(drip.count) }}x &bull; terakhir {{ drip.at }}
                        </p>
                    </div>
                    <span class="shrink-0 font-mono text-sm font-black text-purple-700">Aktif</span>
                </li>
            </ul>
        </section>

        <!-- ============ REKAP CAIRAN ============ -->
        <section class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm" data-purpose="fluid-summary">
            <div class="mb-2 flex items-center justify-between gap-2">
                <h3 class="flex items-center gap-1.5 text-xs font-bold uppercase tracking-wider text-slate-900">
                    <i class="fa-solid fa-scale-balanced text-sky-600" aria-hidden="true"></i>
                    Rekapitulasi Cairan
                </h3>
                <span class="font-mono text-[10px] text-slate-400">
                    {{ formatNumber(fluid.hours) }} slot jam
                </span>
            </div>

            <div class="grid grid-cols-3 gap-2 rounded-lg border border-slate-100 bg-slate-50 py-2 text-center">
                <div>
                    <span class="block text-[10px] font-bold uppercase text-slate-500">Intake</span>
                    <span class="font-mono text-sm font-bold text-sky-700">{{ formatVolume(fluid.intake) }}</span>
                </div>
                <div class="border-x border-slate-200">
                    <span class="block text-[10px] font-bold uppercase text-slate-500">Output</span>
                    <span class="font-mono text-sm font-bold text-amber-700">{{ formatVolume(fluid.output) }}</span>
                </div>
                <div>
                    <span class="block text-[10px] font-bold uppercase text-slate-500">Balance</span>
                    <span
                        class="font-mono text-sm font-bold"
                        :class="fluid.balance < 0 ? 'text-red-600' : 'text-emerald-600'"
                    >{{ formatVolume(fluid.balance) }}</span>
                </div>
            </div>

            <div class="mt-2.5 flex items-center justify-between px-1 text-xs text-slate-600">
                <span>Seluruh episode perawatan ini:</span>
                <span class="rounded border border-slate-200 bg-slate-100 px-2 py-0.5 font-mono font-bold text-slate-900">
                    {{ formatVolume(fluid.balance) }}
                </span>
            </div>
        </section>

        <!-- ============ VENTILASI & TINDAKAN KEPERAWATAN ============ -->
        <section class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm" data-purpose="latest-ventilator-nursing">
            <div class="mb-2 flex items-center justify-between gap-2 border-b border-slate-100 pb-2">
                <h3 class="flex items-center gap-1.5 text-xs font-bold uppercase tracking-wider text-slate-900">
                    <i class="fa-solid fa-wind text-cyan-600" aria-hidden="true"></i>
                    Ventilasi &amp; Tindakan Keperawatan
                </h3>
                <span class="font-mono text-[10px] text-slate-400">{{ recordedAt }}</span>
            </div>

            <p class="text-[10px] font-bold uppercase text-slate-500">Ventilasi</p>
            <p
                v-if="ventilator"
                class="mt-1 whitespace-pre-line rounded-lg border border-slate-200 bg-slate-50 px-2.5 py-1.5 font-mono text-[11px] leading-relaxed text-slate-800"
                data-purpose="latest-ventilator"
            >{{ ventilator }}</p>
            <p
                v-else
                class="mt-1 rounded-lg border border-dashed border-slate-200 px-2.5 py-1.5 text-[11px] italic text-slate-500"
                data-purpose="latest-ventilator-empty"
            >{{ ventilatorHint }}</p>

            <p class="mt-2.5 text-[10px] font-bold uppercase text-slate-500">Tindakan Keperawatan</p>
            <p class="mt-1 text-[11px] text-slate-600" data-purpose="latest-nursing-action">{{ nursing }}</p>
        </section>
    </div>
</template>
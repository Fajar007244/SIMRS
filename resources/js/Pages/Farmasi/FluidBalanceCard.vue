<script setup>
/**
 * FluidBalanceCard.vue - rekapitulasi cairan.
 *
 * Port 1:1 dari kartu "Rekapitulasi Cairan 24 Jam" + renderFluids() di
 * phase1/farmasi.html: judul + jam jendela mono, tiga kotak Intake / Output /
 * Balance 24J, lalu baris balance kumulatif.
 *
 * PERILAKU JENDELA 24 JAM (dokumentasi, bukan bug):
 * MedicationRecapService::getFluidBalance($enc, 24) mengunci jendela 24 jam
 * pada `now()`. Untuk episode yang observasinya sudah lewat - seluruh data
 * seed - jendela itu tidak memuat apa pun, jadi intake / output / balance
 * bernilai 0 dan `rows` kosong, SEDANGKAN `cumulativeBalance` (total SELURUH
 * episode) tetap terisi. Kartu ini karena itu:
 *   1. menampilkan kedua angka, dengan label berbeda: "24 Jam" untuk jendela
 *      bergerak dan "Kumulatif Selama Perawatan" untuk total episode;
 *   2. saat jendela kosong, menandai angka 24 Jam dengan tanda hubung (0
 *      berarti "tidak ada observasi", bukan nol mL) dan menampilkan catatan
 *      berbahasa Indonesia yang menjelaskan penyebabnya.
 * Service tidak diubah; halaman tidak mengarang nilai pengganti.
 *
 * PROPS
 *   fluidBalance       Object  default {}  getFluidBalance($enc, 24)
 *   latestObservationAt String default ''  label observasi terakhir dari
 *                                              banner, untuk kalimat catatan
 *
 * SLOTS: (tidak ada)
 * EMITS : (tidak ada)
 */
import { computed } from 'vue';
import { formatNumber, isBlank, signed } from '@/composables/useFormatting';

const props = defineProps({
    fluidBalance: { type: Object, default: () => ({}) },
    latestObservationAt: { type: String, default: '' },
});

const fb = computed(() => (props.fluidBalance && typeof props.fluidBalance === 'object' ? props.fluidBalance : {}));

const hours = computed(() => {
    const n = Number(fb.value.hours);
    return Number.isFinite(n) && n > 0 ? Math.round(n) : 24;
});

const rows = computed(() => (Array.isArray(fb.value.rows) ? fb.value.rows : []));

/**
 * Jendela kosong berarti tidak ada observasi yang jatuh di dalam rentang
 * `now() - hours .. now()`, bukan "nol mL masuk / nol mL keluar".
 */
const windowEmpty = computed(() => rows.value.length === 0);

const cumulative = computed(() => Number(fb.value.cumulativeBalance) || 0);

function pad(value) {
    return String(value).padStart(2, '0');
}

/** Rentang jam jendela 24 jam yang berjalan, mis. "07:00 - 07:00". */
const windowLabel = computed(() => {
    const now = new Date();
    const current = now.getHours();

    return `${pad(current)}:00 - ${pad(current)}:00`;
});
</script>

<template>
    <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm print-border" data-purpose="fluid-balance">
        <div class="mb-2 flex items-center justify-between gap-2">
            <h3 class="flex items-center gap-1.5 text-xs font-bold uppercase tracking-wider text-slate-900">
                <i class="fa-solid fa-scale-balanced text-sky-600" aria-hidden="true"></i>
                Rekapitulasi Cairan {{ hours }} Jam
            </h3>
            <span class="font-mono text-[10px] text-slate-400">
                {{ hours }} Jam &middot; {{ windowLabel }}
            </span>
        </div>

        <div class="grid grid-cols-3 gap-2 rounded-lg border border-slate-100 bg-slate-50 py-2 text-center">
            <div>
                <span class="block text-[10px] font-bold uppercase text-slate-500">Intake</span>
                <span class="font-mono text-sm font-bold text-sky-700" data-purpose="fluid-intake">
                    {{ windowEmpty ? '-' : formatNumber(fb.intake ?? 0) }}
                </span>
            </div>
            <div class="border-x border-slate-200">
                <span class="block text-[10px] font-bold uppercase text-slate-500">Output</span>
                <span class="font-mono text-sm font-bold text-amber-700" data-purpose="fluid-output">
                    {{ windowEmpty ? '-' : formatNumber(fb.output ?? 0) }}
                </span>
            </div>
            <div>
                <span class="block text-[10px] font-bold uppercase text-slate-500">Balance {{ hours }}J</span>
                <span class="font-mono text-sm font-bold text-emerald-600" data-purpose="fluid-balance-window">
                    {{ windowEmpty ? '-' : signed(fb.balance ?? 0) }}
                </span>
            </div>
        </div>

        <div
            v-if="windowEmpty"
            class="mt-2.5 rounded-lg border border-amber-200 bg-amber-50 px-2.5 py-2 text-[11px] leading-relaxed text-amber-800"
            data-purpose="fluid-window-empty"
        >
            <p class="flex items-start gap-1.5">
                <i class="fa-solid fa-circle-info mt-0.5 shrink-0" aria-hidden="true"></i>
                <span>
                    <template v-if="isBlank(latestObservationAt)">
                        Belum ada observasi pada episode ini, jadi rekap {{ hours }} jam terakhir masih kosong.
                    </template>
                    <template v-else>
                        Jendela {{ hours }} jam terakhir dihitung dari waktu sekarang
                        (<span class="font-mono">{{ windowLabel }}</span>) dan tidak memuat observasi episode ini;
                        observasi terakhir tercatat pada
                        <strong class="font-semibold">{{ latestObservationAt }}</strong>. Tanda hubung di atas berarti
                        "tidak ada pencatatan", bukan nol mL.
                    </template>
                </span>
            </p>
        </div>

        <div class="mt-2.5 flex items-center justify-between gap-2 px-1 text-xs text-slate-600">
            <span>Kumulatif Selama Perawatan:</span>
            <span
                class="rounded border border-slate-200 bg-slate-100 px-2 py-0.5 font-mono font-bold text-slate-900"
                data-purpose="fluid-cumulative"
            >{{ signed(cumulative) }}</span>
        </div>
    </div>
</template>

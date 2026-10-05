<script setup>
/**
 * CategoryVolumeDistribution.vue - komposisi volume per kategori obat.
 *
 * Port 1:1 dari blok data-purpose="category-volume" + renderCategoryBars() di
 * phase1/farmasi.html: kepala kartu dengan chip "Total 100%", lalu satu
 * BarMeter per kategori.
 *
 * URUTAN: App\Enums\MedicationCategory, sama dengan CATEGORY_ORDER phase1.
 * Sumber data MedicationRecapService::getCategoryDistribution() sudah
 * mengembalikan keenam kategori dalam urutan itu (termasuk yang volumenya
 * nol), tetapi baris tetap diurutkan ulang di sini lewat
 * MEDICATION_CATEGORY_ORDER supaya aman kalau payload datang dalam urutan
 * lain.
 *
 * BarMeter memakai prop `percent` (0-100) dan `format` supaya kolom kanan
 * menampilkan "1.234 mL (42%)" seperti prototype.
 *
 * CATATAN DESIMAL: service membulatkan persen ke satu desimal lalu
 * mengoreksi baris terakhir supaya jumlah tepat 100, sehingga satu kategori
 * bisa bernilai -0.1. Persentase negatif tidak pernah ditampilkan: bar
 * lebarnya 0% dan teksnya diklem ke 0.
 *
 * PROPS
 *   distribution  Array  default []  getCategoryDistribution()
 *
 * SLOTS: (tidak ada)
 * EMITS : (tidak ada)
 */
import { computed } from 'vue';
import BarMeter from '@/Components/BarMeter.vue';
import EmptyState from '@/Components/EmptyState.vue';
import { MEDICATION_CATEGORY_ORDER, medicationTone } from '@/tone';
import { formatNumber } from '@/composables/useFormatting';

const props = defineProps({
    distribution: { type: Array, default: () => [] },
});

const rows = computed(() => {
    const list = Array.isArray(props.distribution) ? props.distribution : [];
    const byCategory = new Map(list.map((row) => [row.category, row]));

    return MEDICATION_CATEGORY_ORDER
        .map((category) => byCategory.get(category))
        .filter(Boolean)
        .map((row) => ({ ...row, tone: medicationTone(row.category).tone }));
});

const hasVolume = computed(() => rows.value.some((row) => (Number(row.volume) || 0) > 0));

/** Kolom kanan: "1.234 mL (42%)", persis seperti BarMeter bawaan. */
function formatRow(value, percent) {
    const amount = formatNumber(value);
    const safe = Math.max(0, Math.round(Number(percent) || 0));

    return `${amount === '-' ? '-' : `${amount} mL`} (${safe}%)`;
}
</script>

<template>
    <section
        class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm print-border"
        data-purpose="category-volume"
    >
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 bg-slate-50 p-4">
            <div class="flex items-center gap-2">
                <i class="fa-solid fa-chart-simple text-lg text-sky-600" aria-hidden="true"></i>
                <div>
                    <h2 class="text-sm font-bold text-slate-900">
                        Komposisi Cairan &amp; Obat Berdasarkan Kategori
                    </h2>
                    <p class="text-xs text-slate-500">
                        Proporsi volume pemberian per kategori obat dari rekap observasi EWS
                    </p>
                </div>
            </div>
            <span class="rounded-full bg-slate-100 px-2.5 py-1 text-[10px] font-bold text-slate-600">
                Total 100%
            </span>
        </div>

        <div class="space-y-3 p-4">
            <EmptyState
                v-if="!hasVolume"
                icon="fa-chart-simple"
                title="Belum ada komposisi volume untuk ditampilkan"
                message="Tambahkan koreksi pemberian obat/cairan pada formulir observasi EWS."
            />

            <template v-else>
                <BarMeter
                    v-for="row in rows"
                    :key="row.category"
                    :label="row.categoryLabel || row.category"
                    :value="Number(row.volume) || 0"
                    :percent="Number(row.percent) || 0"
                    :tone="row.tone"
                    :format="formatRow"
                />
            </template>
        </div>
    </section>
</template>

<script setup>
/**
 * TelemetryVitalsCards.vue - enam kartu vital langsung (region
 * data-purpose="telemetry-vitals" pada phase1/observasi.html).
 *
 * Port 1:1 enam tile prototype:
 *   1. Tekanan Darah   -> border-l-sky-500, nilai "sys/dia", baris "MAP Target >65"
 *   2. Laju Nadi       -> border-l-amber-500, nilai hr, baris "Irama"
 *   3. SpO2 / Saturasi -> border-l-emerald-500, nilai spo2, baris "O2 Support"
 *   4. Respirasi (RR)  -> border-l-orange-500, nilai rr, baris "Tipe Nafas"
 *   5. Suhu Tubuh      -> border-l-teal-500, nilai suhu, baris "Status"
 *   6. Kesadaran       -> border-l-purple-500, nilai kesadaran, baris "GCS (ETT)"
 *
 * `delta` berasal dari ObservationService::getTelemetry().deltas dan hanya
 * tidak nol bila observasi TERBARU dan observasi SEBELUMNYA sama-sama ada
 * (server mengembalikan semua 0 kalau salah satu null). Karena itu delta
 * 0 ditampilkan sebagai "tetap" dan bukan "0", supaya petugas tidak salah
 * mengira ada perubahan.
 *
 * PROPS
 *   latest   Object|null  baris flowsheet terakhir (telemetry.latest)
 *   deltas   Object        { hr, sbp, spo2, suhu, rr }
 *   updatedAt string|null  ISO waktu observasi terakhir
 *
 * EMITS: (tidak ada)
 */
import { computed } from 'vue';
import StatCard from '@/Components/StatCard.vue';
import { EMPTY, isBlank } from '@/composables/useFormatting';

const props = defineProps({
    latest: { type: Object, default: null },
    deltas: { type: Object, default: () => ({}) },
    updatedAt: { type: String, default: null },
});

const row = computed(() => (props.latest && typeof props.latest === 'object' ? props.latest : {}));

const hasData = computed(() => props.latest !== null && props.latest !== undefined);

/** Tekanan darah: "114/68" sudah disiapkan server sebagai bpLabel. */
const bp = computed(() => {
    if (!hasData.value) return EMPTY;
    if (!isBlank(row.value.bpLabel)) return String(row.value.bpLabel);

    return `${row.value.sys ?? EMPTY}/${row.value.dia ?? EMPTY}`;
});

const mapValue = computed(() => {
    const value = row.value.map;
    return value === null || value === undefined || isBlank(value) ? EMPTY : `${value} mmHg`;
});

const mapLow = computed(() => {
    const value = Number(row.value.map);
    return Number.isFinite(value) && value < 65;
});

/**
 * Status suhu, port dari updateDashboard() phase1. Ambang prototype:
 * < 35 hipotermia, <= 37,5 normal, <= 38 demam ringan, di atasnya demam tinggi.
 */
const temperatureStatus = computed(() => {
    const value = Number(row.value.suhu);

    if (!Number.isFinite(value)) {
        return { label: EMPTY, class: 'text-slate-500' };
    }

    if (value < 35) return { label: 'Hipotermia', class: 'text-blue-700' };
    if (value <= 37.5) return { label: 'Normal', class: 'text-teal-700' };
    if (value <= 38) return { label: 'Demam ringan', class: 'text-amber-700' };

    return { label: 'Demam tinggi', class: 'text-red-700' };
});

const temperatureValue = computed(() => {
    const value = row.value.suhu;

    return value === null || value === undefined || isBlank(value) ? EMPTY : `${value} \u00b0C`;
});

/**
 * Teks delta seragam untuk semua kartu: tanda, angka, dan arah. `unit` hanya
 * hanya untuk supervisor, jadi satuan tidak ikut di dalam teks delta.
 */
function deltaText(key, unit) {
    const raw = props.deltas ? props.deltas[key] : 0;
    const value = Number(raw);

    if (!Number.isFinite(value) || value === 0) {
        return hasData.value ? `Tetap sejak observasi sebelumnya` : 'Belum ada observasi';
    }

    const sign = value > 0 ? '+' : '';
    const magnitude = unit === 'suhu' ? Math.abs(value).toFixed(1) : String(Math.abs(value));

    return `${sign}${magnitude} ${unit} dari observasi sebelumnya`;
}


</script>

<template>
    <section
        class="grid grid-cols-2 gap-3 md:grid-cols-3 lg:grid-cols-6"
        data-purpose="telemetry-vitals"
    >
        <!-- 1. Tekanan Darah -->
        <StatCard
            label="Tekanan Darah"
            :value="bp"
            :sub="deltaText('sbp', 'mmHg')"
            :icon="'fa-solid fa-heart-pulse'"
            tone="sky"
            mono
        >
            <template #footer>
                <span class="mt-1 flex w-full items-center justify-between border-t border-slate-100 pt-1.5 text-[11px]">
                    <span class="text-slate-500">MAP Target &gt;65:</span>
                    <span
                        class="rounded border px-1.5 py-0.5 font-mono font-bold"
                        :class="mapLow ? 'border-red-200 bg-red-50 text-red-700' : 'border-sky-200 bg-sky-50 text-sky-700'"
                    >{{ mapValue }}</span>
                </span>
            </template>
        </StatCard>

        <!-- 2. Laju Nadi -->
        <StatCard
            label="Laju Nadi"
            :value="hasData ? (row.hr ?? EMPTY) : EMPTY"
            :sub="deltaText('hr', 'x/mnt')"
            :icon="'fa-solid fa-heart-pulse'"
            tone="amber"
            mono
            pulse
        >
            <template #footer>
                <span class="mt-1 flex w-full items-center justify-between border-t border-slate-100 pt-1.5 text-[11px]">
                    <span class="text-slate-500">Irama:</span>
                    <span class="truncate text-[11px] text-amber-800">
                        {{ isBlank(row.rhythm) ? EMPTY : row.rhythm }}
                    </span>
                </span>
            </template>
        </StatCard>

        <!-- 3. SpO2 / Saturasi -->
        <StatCard
            label="SpO2 / Saturasi"
            :value="hasData ? (row.spo2 ?? EMPTY) : EMPTY"
            :sub="deltaText('spo2', '%')"
            :icon="'fa-solid fa-lungs'"
            tone="emerald"
            mono
        >
            <template #footer>
                <span class="mt-1 flex w-full items-center justify-between border-t border-slate-100 pt-1.5 text-[11px]">
                    <span class="text-slate-500">O2 Support:</span>
                    <span class="truncate font-bold text-slate-700">
                        {{ isBlank(row.o2Support) ? EMPTY : row.o2Support }}
                    </span>
                </span>
            </template>
        </StatCard>

        <!-- 4. Respirasi (RR) -->
        <StatCard
            label="Respirasi (RR)"
            :value="hasData ? (row.rr ?? EMPTY) : EMPTY"
            :sub="deltaText('rr', 'x/mnt')"
            :icon="'fa-solid fa-wave-square'"
            tone="orange"
            mono
        >
            <template #footer>
                <span class="mt-1 flex w-full items-center justify-between border-t border-slate-100 pt-1.5 text-[11px]">
                    <span class="text-slate-500">Tipe Nafas:</span>
                    <span class="truncate text-slate-700">
                        {{ isBlank(row.breathType) ? EMPTY : row.breathType }}
                    </span>
                </span>
            </template>
        </StatCard>

        <!-- 5. Suhu Tubuh -->
        <StatCard
            label="Suhu Tubuh"
            :value="temperatureValue"
            :sub="deltaText('suhu', '\u00b0C')"
            :icon="'fa-solid fa-temperature-three-quarters'"
            tone="teal"
            mono
        >
            <template #footer>
                <span class="mt-1 flex w-full items-center justify-between border-t border-slate-100 pt-1.5 text-[11px]">
                    <span class="text-slate-500">Status:</span>
                    <span class="font-mono font-medium" :class="temperatureStatus.class">
                        {{ temperatureStatus.label }}
                    </span>
                </span>
            </template>
        </StatCard>

        <!-- 6. Kesadaran / GCS -->
        <StatCard
            label="Kesadaran"
            :value="isBlank(row.kesadaran) ? EMPTY : row.kesadaran"
            :sub="isBlank(row.kesadaran) ? 'Belum ada observasi' : 'AVPU / DPO'"
            :icon="'fa-solid fa-brain'"
            tone="purple"
        >
            <template #footer>
                <span class="mt-1 flex w-full items-center justify-between border-t border-slate-100 pt-1.5 text-[11px]">
                    <span class="text-slate-500">GCS (ETT):</span>
                    <span class="font-mono font-bold text-slate-800">
                        {{ row.gcs === null || row.gcs === undefined || isBlank(row.gcs) ? EMPTY : row.gcs }}
                    </span>
                </span>
            </template>
        </StatCard>
    </section>
</template>
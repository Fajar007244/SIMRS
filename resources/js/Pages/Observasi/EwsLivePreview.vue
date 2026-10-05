<script setup>
/**
 * EwsLivePreview.vue - pratinjau skor EWS langsung di footer formulir
 * (blok aria-live="polite" berisi #ewsActionPreview + #ewsScorePreview pada
 * phase1/observasi.html).
 *
 * PRATINJAU INI BUKAN SUMBER KEBENARAN. Skor yang tersimpan SELALU dihitung
 * ulang oleh EwsScoringService di server. Yang ditampilkan di sini adalah
 * hasil port yang sama persis, dihitung dari band `config('ews')` yang
 * diteruskan lewat prop `reference` (lihat ewsPreview.js). Kalau karena
 * alasan apa pun angka pratinjau berbeda dengan yang tersimpan, yang benar
 * adalah angka server - karena itulah yang ditulis ke observations.ews_total
 * dan yang dipakai seluruh modul lain.
 *
 * Bentuk visualnya mengikuti ewsKategori() + updateEWSScore() phase1:
 *   belum ada vital  -> badge abu-abu berisi "-", aksi "Lypi tahu tanda
 *                       vital untuk menghitung skor."
 *   sudah ada vital  -> badge berisi "<total> (<Label>)" dengan kelas
 *                       bg-/text-/border- per tingkat, dan aksi dari
 *                       EWS_ACTION.
 *
 * Rincian per parameter sengaja ditampilkan, termasuk penanda `inGap`:
 * nilai suhu seperti 35,05 atau 38,05 jatuh pada CELAH band `Temp` yang
 * disengaja di config/ews.php. Celah itu menghasilkan skor 0, sama dengan
 * "belum diisi", jadi tanpa penanda eksplisit petugas akan salah mengira
 * temperaturenya tidak terbaca.
 *
 * PROPS
 *   state Object  hasil previewState(): { total, risk, riskLabel, action,
 *                                       hasVitalData, components, gaps }
 *   max    Number  plafon skala (18)
 *
 * EMITS
 *   headerScore  String  label kategori uppercase untuk #ewsHeaderScore di
 *                        luar modal, sama seperti updateEWSScore() phase1
 */
import { computed, watch } from 'vue';
import { EMPTY, EWS_CATEGORY_CLASSES, EWS_EMPTY_CLASSES } from './ewsPreview';

const props = defineProps({
    state: { type: Object, required: true },
    max: { type: Number, default: 18 },
});

const emit = defineEmits(['headerScore']);

const ready = computed(() => Boolean(props.state && props.state.hasVitalData));

/** Badge #ewsScorePreview: kelas literal dari ewsKategori() phase1. */
const badgeClass = computed(() => (
    ready.value
        ? `rounded-md border px-3 py-1 text-sm font-black ${EWS_CATEGORY_CLASSES[props.state.risk] || EWS_CATEGORY_CLASSES.low}`
        : `rounded-md border px-3 py-1 text-sm font-black ${EWS_EMPTY_CLASSES}`
));

/** Isi badge: "<total> (<Label>)", atau "-" saat belum ada vital. */
const scoreLabel = computed(() => {
    if (!ready.value) return EMPTY;

    const total = Number(props.state.total);

    return `${Number.isFinite(total) ? total : 0} (${props.state.riskLabel || EMPTY})`;
});

/** #ewsActionPreview. */
const actionLabel = computed(() => (ready.value
    ? props.state.action
    : 'Lengkapi tanda vital untuk menghitung skor.'));

/** Isi #ewsHeaderScore di luar modal (phase1: ` ${label.toUpperCase()}`). */
const headerScore = computed(() => (ready.value
    ? ` ${String(props.state.riskLabel || EMPTY).toUpperCase()}`
    : EMPTY));

watch(
    headerScore,
    (value) => emit('headerScore', value),
    { immediate: true },
);

function scoreClassFor(component) {
    if (component.score >= 3) return 'bg-red-600 text-white border-red-700';
    if (component.score === 2) return 'bg-red-100 text-red-800 border-red-200';
    if (component.score === 1) return 'bg-amber-100 text-amber-800 border-amber-200';

    return 'bg-emerald-100 text-emerald-800 border-emerald-200';
}

function componentValue(component) {
    if (component.key === 'Kesadaran') {
        return component.band && component.band !== EMPTY ? component.band : EMPTY;
    }

    if (component.value === null || component.value === undefined) return EMPTY;

    const formatted = Number.isInteger(component.value)
        ? String(component.value)
        : String(component.value).replace('.', ',');

    return component.unit ? `${formatted} ${component.unit}` : formatted;
}
</script>

<template>
    <div
        class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-sky-200 bg-sky-50 px-3 py-2"
        aria-live="polite"
        data-purpose="ews-live-preview"
    >
        <div class="min-w-0">
            <p class="text-xs font-bold text-sky-800">Skor EWS saat ini</p>
            <p id="ewsActionPreview" class="text-[11px] text-slate-600" data-purpose="ews-preview-action">{{ actionLabel }}</p>
            <p class="mt-0.5 text-[10px] italic text-slate-500" data-purpose="ews-preview-authoritative">
                Pratinjau saja. Nilai resmi tetap dihitung ulang di server saat disimpan.
            </p>
        </div>

        <span id="ewsScorePreview" class="font-black" :class="badgeClass" data-purpose="ews-score-preview">
            {{ scoreLabel }}
        </span>
    </div>

    <details
        v-if="ready"
        class="mt-2 rounded-lg border border-slate-200 bg-white px-3 py-2 text-[11px] text-slate-600"
        data-purpose="ews-preview-breakdown"
    >
        <summary class="cursor-pointer font-bold text-slate-700">
            Rincian skor per parameter (British EWS 4 tingkat, plafon {{ max }})
        </summary>

        <ul class="mt-2 grid grid-cols-2 gap-1.5 sm:grid-cols-3 lg:grid-cols-6">
            <li
                v-for="component in state.components"
                :key="component.key"
                class="rounded border border-slate-200 px-1.5 py-1"
                :class="component.inGap ? 'border-amber-300 bg-amber-50' : ''"
                :data-purpose="`ews-preview-${component.key}`"
            >
                <p class="truncate font-semibold text-slate-600" :title="component.label">{{ component.label }}</p>
                <p class="font-mono font-bold text-slate-900">{{ componentValue(component) }}</p>
                <p
                    class="mt-0.5 inline-block rounded border px-1 font-mono text-[10px] font-bold"
                    :class="component.inGap ? 'border-amber-300 bg-amber-100 text-amber-800' : scoreClassFor(component)"
                >
                    {{ component.inGap ? 'celah (0)' : component.score }}
                </p>
                <p class="mt-0.5 truncate font-mono text-[10px] text-slate-400" :title="component.band">
                    {{ component.inGap ? 'di luar band' : component.band }}
                </p>
            </li>
        </ul>

        <p class="mt-2 text-[10px] text-slate-500">
            Celah band pada suhu (35,0-35,1 / 36,0-36,1 / 38,0-38,1 / 41,0-41,1) sengaja bernilai
            0 dan ditandai "celah" - bukan "belum diisi". Nilai Celsius memakai koma desimal
            pada tampilan ini.
        </p>
    </details>
</template>
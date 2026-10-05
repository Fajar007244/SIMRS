/**
 * ewsPreview.js -hitung skor EWS di sisi klien untuk pratinjau langsung pada
 * formulir observasi.
 *
 * PENTING - ini BUKAN sumber kebenaran. Skor yang tersimpan SELALU dihitung
 * ulang oleh EwsScoringService di server (ObservationService::save() meneruskan
 * hasil calculate() ke kolom observations.ews_total). Fungsi di sini hanya
 * replika port yang sama supaya angka pada pratinjau bisa dibandingkan langsung
 * dengan hasil server tanpa menunggu round-trip.
 *
 * SEMUA BAND DIAMBIL DARI prop `reference` (config('ews') yang dikirim
 * ObservasiController::reference()), bukan ditulis ulang di sini. Dengan begitu
 * tidak ada salinan tabel EWS yang bisa ikut berubah-ubah di satu sisi saja:
 * Band `Temp` yang punya celah pun ikut terbawa apa adanya.
 *
 * ===== PERILAKU CELAH BAND (JANGAN DIRAPIKAN) =====
 * config('ews').parameters.Temp.bands:
 *   0 - 35.0  (2) | 35.1 - 36.0 (1) | 36.1 - 38.0 (0)
 *   38.1 - 38.5 (1) | 38.6 - 41 (2) | 41.1 - 99 (3)
 * Ada celah pada 35.0-35.1, 36.0-36.1, 38.0-38.1, dan 41.0-41.1. Nilai yang
 * jatuh di sana tidak cocok dengan band mana pun sehingga skornya 0 dan
 * ditandai `inGap: true` (bukan "belum diisi"). Port ini memakai loop
 * inklusif atas daftar band yang sama, jadi celahnya ikut terbawa
 * tanpa kasus khusus apa pun.
 * ===================================================
 */

import { EWS_ESCALATION, ewsBadgeClasses, EWS_MAX_TOTAL } from '@/tone';

export const EMPTY = '-';

/**
 * Kunci input yang diterima tiap parameter. Salinan dari
 * EwsScoringService::INPUT_ALIASES; dipakai sebagai jaring pengaman bila
 * formulir mengirim nama field berbeda.
 */
const INPUT_ALIASES = {
    RR: ['rr', 'respirasi', 'freq'],
    HR: ['hr', 'nadi', 'pulse'],
    SBP: ['sys', 'sbp', 'sistolik', 'map_sistolik'],
    SpO2: ['spo2', 'sp_o2', 'saturasi'],
    Temp: ['suhu', 'temp', 'temperatur', 'temperature'],
    Kesadaran: ['kesadaran', 'kesadarankey', 'awareness', 'consciousness'],
};

/**
 * Tindak lanjut yang disarankan per tingkat risiko.
 * Port `aksi` dari ewsKategori() phase1/observasi.html.
 */
export const EWS_ACTION = {
    emergency: 'Resuscitation Team / Code Blue, observasi kontinu',
    high: 'Evaluasi DPJP ≤ 1 jam, observasi tiap 1 jam',
    medium: 'Evaluasi DPJP ≤ 4 jam, observasi tiap 4 jam',
    // prototype menulis "tiap 8<E2> jam" di mana <E2> adalah U+2001 EM QUAD
    // yang ter-serialisasi ganda jadi huruf "E2" (lihat baris ewsKategori()
    // phase1/observasi.html). Koreksi itu di sini menjadi spasi biasa.
    low: 'Observasi rutin tiap 8 jam',
};

/**
 * Kelas badge sesuai ewsKategori() phase1, dipakai untuk kotak pratinjau
 * (#ewsScorePreview) supaya warna di footer formulir sama persis dengan
 * prototype - bukan kelas dari EwsBadge.vue.
 */
export const EWS_CATEGORY_CLASSES = {
    emergency: 'bg-red-600 text-white border-red-700',
    high: 'bg-red-100 text-red-800 border-red-200',
    medium: 'bg-amber-100 text-amber-800 border-amber-200',
    low: 'bg-emerald-100 text-emerald-800 border-emerald-200',
};

/** Kelas kotak skor saat belum ada vital yang bisa dinilai. */
export const EWS_EMPTY_CLASSES = 'text-slate-500';

/** Warna garis panduan per tingkat, mengikuti badge di tone.js. */
const GUIDE_COLOR = {
    low: '#10b981',
    medium: '#f59e0b',
    high: '#ea580c',
    emergency: '#dc2626',
};

/**
 * Cermin ClinicalFormat::numeric(): string kosong dan whitespace TIDAK
 * dianggap 0 (beda dari Number('')), dan teks yang bukan angka juga null.
 * Kata "35,5" (koma desimal) tidak diterima, sama seperti is_numeric() PHP.
 */
const NUMERIC_PATTERN = /^[+-]?(\d+(\.\d+)?|\.\d+)([eE][+-]?\d+)?$/;

export function numeric(value) {
    if (value === null || value === undefined) return null;
    if (typeof value === 'boolean') return null;

    if (typeof value === 'number') return Number.isFinite(value) ? value : null;

    const text = String(value).trim();

    if (text === '' || !NUMERIC_PATTERN.test(text)) return null;

    const parsed = Number(text);

    return Number.isFinite(parsed) ? parsed : null;
}

/** Rounded integer atau null, cermin ClinicalFormat::integer(). */
export function integer(value) {
    const parsed = numeric(value);

    return parsed === null ? null : Math.round(parsed);
}

/**
 * Skor satu parameter bertipe range.
 * Mengembalikan { score, band, inGap, value } - bentuknya sama dengan
 * EwsScoringService::calculate()['components'].
 */
export function scoreRange(value, bands) {
    const number = numeric(value);

    if (number === null) {
        return { score: 0, band: EMPTY, inGap: false, value: null };
    }

    for (const entry of Array.isArray(bands) ? bands : []) {
        const min = Number(entry.min);
        const max = Number(entry.max);

        if (number >= min && number <= max) {
            return {
                score: Number(entry.score),
                band: `${entry.min} - ${entry.max}`,
                inGap: false,
                value: number,
            };
        }
    }

    // Tidak masuk band mana pun: skor 0, tetapi ditandai celah.
    return { score: 0, band: EMPTY, inGap: true, value: number };
}

/**
 * Parameter bertipe map (Kesadaran). Prefix dicocokkan supaya bentuk
 * "DPO (RASS -2)" dari prototype tetap dikenali sebagai DPO, persis seperti
 * EwsScoringService::scoreMap().
 */
export function scoreMap(value, values) {
    const table = values && typeof values === 'object' ? values : {};

    if (typeof value === 'string') {
        const text = value.trim();

        for (const key of Object.keys(table)) {
            if (text.startsWith(key)) {
                return { score: Number(table[key]), band: key, inGap: false, value: null };
            }
        }

        if (text === '') {
            return { score: 0, band: EMPTY, inGap: false, value: null };
        }

        return { score: 0, band: text, inGap: false, value: null };
    }

    if (value === null || value === undefined) {
        return { score: 0, band: EMPTY, inGap: false, value: null };
    }

    return { score: 0, band: EMPTY, inGap: false, value: null };
}

function extract(vitals, parameter) {
    const source = vitals && typeof vitals === 'object' ? vitals : {};

    for (const alias of INPUT_ALIASES[parameter] || []) {
        if (Object.prototype.hasOwnProperty.call(source, alias) && source[alias] !== '') {
            return source[alias];
        }
    }

    const lowered = {};

    for (const [key, value] of Object.entries(source)) {
        lowered[key.toLowerCase()] = value;
    }

    for (const raw of INPUT_ALIASES[parameter] || []) {
        const alias = raw.toLowerCase();

        if (Object.prototype.hasOwnProperty.call(lowered, alias) && lowered[alias] !== '') {
            return lowered[alias];
        }
    }

    return null;
}

/**
 * Hitung skor lengkap, sama seperti EwsScoringService::calculate().
 *
 * @param {object} vitals     { rr, hr, sys, spo2, suhu, kesadaran }
 * @param {object} parameters config('ews.parameters')
 * @returns {{ scores: object, total: number, components: Array }}
 */
export function calculateEws(vitals, parameters) {
    const definition = parameters && typeof parameters === 'object' ? parameters : {};
    const scores = {};
    const components = [];

    let total = 0;

    for (const name of Object.keys(definition)) {
        const spec = definition[name] || {};
        const raw = extract(vitals, name);

        const meta = spec.type === 'map'
            ? scoreMap(raw, spec.values)
            : scoreRange(raw, spec.bands);

        scores[name] = meta.score;
        total += meta.score;

        components.push({
            key: name,
            label: spec.label || name,
            unit: spec.unit || '',
            field: spec.field || '',
            score: meta.score,
            band: meta.band,
            inGap: meta.inGap,
            value: meta.value,
            hasValue: meta.value !== null,
        });
    }

    return { scores, total, components };
}

/** Tingkat risiko dari total, memakai config('ews.escalation'). */
export function riskFrom(total, escalation) {
    const tiers = Array.isArray(escalation) && escalation.length
        ? escalation
        : EWS_ESCALATION;

    const value = Number(total);

    for (const tier of tiers) {
        const min = Number(tier.min);
        const max = tier.max === null || tier.max === undefined ? null : Number(tier.max);

        if (value >= min && (max === null || value <= max)) {
            return String(tier.level);
        }
    }

    return 'low';
}

/** Label badge per tingkat, memakai tier.label dari config('ews'). */
export function riskLabelFrom(risk, escalation) {
    const tiers = Array.isArray(escalation) ? escalation : [];

    const tier = tiers.find((entry) => String(entry.level) === String(risk));

    return tier && tier.label ? String(tier.label) : EMPTY;
}

/**
 * Empat garis panduan putus-putus, satu per tingkat eskalasi, pada y = min
 * tingkat tersebut. Diteruskan apa adanya ke prop `thresholds` TrendChart.vue.
 */
export function escalationGuides(escalation) {
    const tiers = Array.isArray(escalation) && escalation.length
        ? escalation
        : EWS_ESCALATION;

    return tiers
        .filter((tier) => Number.isFinite(Number(tier.min)))
        .map((tier) => {
            const min = Number(tier.min);
            const max = tier.max === null || tier.max === undefined ? null : Number(tier.max);
            const level = String(tier.level);

            return {
                y: min,
                label: max === null
                    ? `${tier.label} >= ${trimNumber(min)}`
                    : `${tier.label} ${trimNumber(min)}-${trimNumber(max)}`,
                color: GUIDE_COLOR[level] || '#f87171',
            };
        });
}

function trimNumber(value) {
    return String(value);
}

/** Plafon sumbu Y: skor maksimum skala, bukan maksimum data. */
export function maxTotal() {
    return EWS_MAX_TOTAL;
}

/** Kelas badge, untuk pratinjau agar warnanya sama dengan badge di tabel. */
export function badgeClasses(risk) {
    return ewsBadgeClasses(risk);
}

/**
 * Ringkasan siap tampil untuk pratinjau.
 *
 * `hasVitalData` meniru syarat phase1: kalau tidak ada satu pun vital
 * numerik yang terisi, pratinjau menampilkan "-" dan bukan "0 (Low)", supaya
 * petugas tidak salah mengira observasi sudah dinilai.
 */
export function previewState(vitals, parameters, escalation) {
    const result = calculateEws(vitals, parameters);
    const risk = riskFrom(result.total, escalation);

    const hasVitalData = result.components.some(
        (component) => component.field !== 'kesadaran' && component.value !== null,
    );

    return {
        scores: result.scores,
        total: result.total,
        components: result.components,
        risk,
        riskLabel: riskLabelFrom(risk, escalation),
        action: EWS_ACTION[risk] || EWS_ACTION.low,
        hasVitalData,
        gaps: result.components.filter((component) => component.inGap).map((component) => component.key),
    };
}
/**
 * tone.js - satu-satunya tempat pemetaan warna "tone" ke kelas Tailwind.
 *
 * Semua kelas ditulis LITERAL di file ini supaya JIT Tailwind memindainya
 * (Tailwind tidak bisa mendeteksi kelas yang dibangun secara dinamis).
 * Kalau halaman butuh warna yang tidak ada di sini, pakai kelas utilitas
 * Tailwind langsung di dalam template - jangan menambah tone baru di file ini
 * tanpa Izin, karena 4 developer halaman sudah mengunci nama-nama di bawah.
 *
 * Tone yang didukung (dipakai StatCard, SectionCard, BarMeter, ComplianceBar,
 * DeviceBadge, TrendChart):
 *   slate | sky | emerald | teal | amber | orange | red | rose | purple |
 *   indigo | yellow | hospital
 *
 * Struktur tiap tone:
 *   accent      -> border kiri 4px kartu statistik (phase1 `border-l-*`)
 *   icon        -> warna ikon di header kartu
 *   value       -> warna angka besar
 *   fill        -> warna batang solid (meter / bar)
 *   track       -> warna track batang
 *   soft        -> background chip/badge
 *   softText    -> teks chip/badge
 *   softBorder  -> border chip/badge
 *   text        -> teks aksen ringan
 *   deep        -> background kotak status gelap di banner (mis. bg-teal-950/60)
 *   deepBorder  -> border kotak status gelap
 *   deepLabel   -> warna teks label kotak status gelap
 *   deepValue   -> warna teks nilai kotak status gelap
 *   hex         -> warna untuk SVG stroke/line (TrendChart)
 */
export const TONE = {
    slate: {
        accent: 'border-l-slate-400',
        deep: 'bg-slate-800/90',
        deepBorder: 'border-slate-600',
        deepLabel: 'text-slate-400',
        deepValue: 'text-slate-100',
        icon: 'text-slate-500',
        value: 'text-slate-900',
        fill: 'bg-slate-500',
        track: 'bg-slate-100',
        soft: 'bg-slate-100',
        softText: 'text-slate-700',
        softBorder: 'border-slate-200',
        text: 'text-slate-600',
        hex: '#64748b',
    },
    sky: {
        accent: 'border-l-sky-500',
        deep: 'bg-sky-950/60',
        deepBorder: 'border-sky-500/50',
        deepLabel: 'text-sky-300',
        deepValue: 'text-sky-100',
        icon: 'text-sky-500',
        value: 'text-sky-700',
        fill: 'bg-sky-500',
        track: 'bg-slate-100',
        soft: 'bg-sky-100',
        softText: 'text-sky-700',
        softBorder: 'border-sky-200',
        text: 'text-sky-600',
        hex: '#0284c7',
    },
    emerald: {
        accent: 'border-l-emerald-500',
        deep: 'bg-emerald-950/60',
        deepBorder: 'border-emerald-500/50',
        deepLabel: 'text-emerald-300',
        deepValue: 'text-emerald-100',
        icon: 'text-emerald-500',
        value: 'text-emerald-600',
        fill: 'bg-emerald-500',
        track: 'bg-slate-100',
        soft: 'bg-emerald-100',
        softText: 'text-emerald-700',
        softBorder: 'border-emerald-200',
        text: 'text-emerald-600',
        hex: '#10b981',
    },
    teal: {
        accent: 'border-l-teal-500',
        deep: 'bg-teal-950/60',
        deepBorder: 'border-teal-500/50',
        deepLabel: 'text-teal-300',
        deepValue: 'text-teal-100',
        icon: 'text-teal-500',
        value: 'text-teal-700',
        fill: 'bg-teal-500',
        track: 'bg-slate-100',
        soft: 'bg-teal-100',
        softText: 'text-teal-700',
        softBorder: 'border-teal-200',
        text: 'text-teal-600',
        hex: '#14b8a6',
    },
    amber: {
        accent: 'border-l-amber-500',
        deep: 'bg-amber-950/50',
        deepBorder: 'border-amber-500/50',
        deepLabel: 'text-amber-300',
        deepValue: 'text-amber-100',
        icon: 'text-amber-500',
        value: 'text-amber-800',
        fill: 'bg-amber-500',
        track: 'bg-slate-100',
        soft: 'bg-amber-100',
        softText: 'text-amber-800',
        softBorder: 'border-amber-200',
        text: 'text-amber-600',
        hex: '#f59e0b',
    },
    orange: {
        accent: 'border-l-orange-500',
        deep: 'bg-orange-950/50',
        deepBorder: 'border-orange-500/50',
        deepLabel: 'text-orange-300',
        deepValue: 'text-orange-100',
        icon: 'text-orange-500',
        value: 'text-orange-600',
        fill: 'bg-orange-500',
        track: 'bg-slate-100',
        soft: 'bg-orange-100',
        softText: 'text-orange-800',
        softBorder: 'border-orange-200',
        text: 'text-orange-600',
        hex: '#f97316',
    },
    red: {
        accent: 'border-l-red-500',
        deep: 'bg-red-950/50',
        deepBorder: 'border-red-400/40',
        deepLabel: 'text-red-300',
        deepValue: 'text-red-100',
        icon: 'text-red-500',
        value: 'text-red-600',
        fill: 'bg-red-500',
        track: 'bg-slate-100',
        soft: 'bg-red-100',
        softText: 'text-red-700',
        softBorder: 'border-red-200',
        text: 'text-red-600',
        hex: '#ef4444',
    },
    rose: {
        accent: 'border-l-rose-500',
        deep: 'bg-rose-950/50',
        deepBorder: 'border-rose-500/50',
        deepLabel: 'text-rose-300',
        deepValue: 'text-rose-100',
        icon: 'text-rose-500',
        value: 'text-rose-600',
        fill: 'bg-rose-500',
        track: 'bg-slate-100',
        soft: 'bg-rose-100',
        softText: 'text-rose-700',
        softBorder: 'border-rose-200',
        text: 'text-rose-600',
        hex: '#e11d48',
    },
    purple: {
        accent: 'border-l-purple-500',
        deep: 'bg-purple-950/60',
        deepBorder: 'border-purple-500/50',
        deepLabel: 'text-purple-300',
        deepValue: 'text-purple-100',
        icon: 'text-purple-500',
        value: 'text-purple-700',
        fill: 'bg-purple-500',
        track: 'bg-slate-100',
        soft: 'bg-purple-100',
        softText: 'text-purple-700',
        softBorder: 'border-purple-200',
        text: 'text-purple-600',
        hex: '#7c3aed',
    },
    indigo: {
        accent: 'border-l-indigo-500',
        deep: 'bg-indigo-950/60',
        deepBorder: 'border-indigo-500/50',
        deepLabel: 'text-indigo-300',
        deepValue: 'text-indigo-100',
        icon: 'text-indigo-500',
        value: 'text-indigo-700',
        fill: 'bg-indigo-500',
        track: 'bg-slate-100',
        soft: 'bg-indigo-100',
        softText: 'text-indigo-700',
        softBorder: 'border-indigo-200',
        text: 'text-indigo-600',
        hex: '#6366f1',
    },
    yellow: {
        accent: 'border-l-yellow-500',
        deep: 'bg-yellow-950/50',
        deepBorder: 'border-yellow-500/50',
        deepLabel: 'text-yellow-300',
        deepValue: 'text-yellow-100',
        icon: 'text-yellow-500',
        value: 'text-yellow-600',
        fill: 'bg-yellow-500',
        track: 'bg-slate-100',
        soft: 'bg-yellow-100',
        softText: 'text-yellow-800',
        softBorder: 'border-yellow-200',
        text: 'text-yellow-600',
        hex: '#eab308',
    },
    hospital: {
        accent: 'border-l-hospital-500',
        deep: 'bg-hospital-800',
        deepBorder: 'border-hospital-500/40',
        deepLabel: 'text-hospital-100',
        deepValue: 'text-white',
        icon: 'text-hospital-500',
        value: 'text-hospital-700',
        fill: 'bg-hospital-500',
        track: 'bg-slate-100',
        soft: 'bg-hospital-100',
        softText: 'text-hospital-700',
        softBorder: 'border-hospital-100',
        text: 'text-hospital-600',
        hex: '#0284c7',
    },
};

/** Default ketika nama tone tidak dikenal. */
export const DEFAULT_TONE = 'slate';

/**
 * Ambil objek tone dengan fallback aman. Nama tone case-insensitive.
 * @param {string} name
 */
export function tone(name) {
    if (typeof name !== 'string') return TONE[DEFAULT_TONE];
    return TONE[name.trim().toLowerCase()] || TONE[DEFAULT_TONE];
}

/** Nama tone yang valid, untuk dokumentasi / validasi form. */
export const TONE_NAMES = Object.keys(TONE);

/* ===========================================================================
   EWS - port persis ewsBadgeClasses() dari phase1/app-context.js dan
   BADGE_CLASSES / escalation dari app/Services/EwsScoringService.php
   =========================================================================== */

export const EWS_BADGE_CLASSES = {
    emergency: ['bg-red-600', 'text-white', 'border-red-700'],
    high: ['bg-red-100', 'text-red-800', 'border-red-200'],
    medium: ['bg-amber-100', 'text-amber-800', 'border-amber-200'],
    low: ['bg-emerald-100', 'text-emerald-800', 'border-emerald-200'],
};

export const EWS_BADGE_FALLBACK = ['bg-slate-100', 'text-slate-700', 'border-slate-200'];

/** config('ews.escalation') - 4 tingkat British EWS. */
export const EWS_ESCALATION = [
    { min: 0, max: 2, level: 'low', label: 'Low' },
    { min: 3, max: 4, level: 'medium', label: 'Medium' },
    { min: 5, max: 6, level: 'high', label: 'High' },
    { min: 7, max: null, level: 'emergency', label: 'Emergency' },
];

/** EwsRiskLevel::badgeLabel(). */
export const EWS_RISK_LABELS = {
    low: 'Low',
    medium: 'Medium',
    high: 'High',
    emergency: 'Emergency',
    none: '-',
};

/** EwsRiskLevel::label() - label panjang untuk banner profil. */
export const EWS_RISK_LONG_LABELS = {
    low: 'Low · stabil',
    medium: 'Sedang · observasi rutin',
    high: 'Tinggi · perlu-awasi',
    emergency: 'Emergensi · intervensi segera',
    none: '-',
};

/**
 * Total skor EWS -> level risiko. Cermin EwsScoringService::riskFrom().
 * @param {number} total
 * @returns {'low'|'medium'|'high'|'emergency'}
 */
export function riskFrom(total) {
    const value = Number(total);

    if (!Number.isFinite(value)) return 'low';

    for (const tier of EWS_ESCALATION) {
        if (value >= tier.min && (tier.max === null || value <= tier.max)) return tier.level;
    }

    return 'low';
}

/**
 * Port ewsBadgeClasses(). Kembalikan objek yang bisa dipakai sebagai
 * :class="{ bg: tone.bg, ... }" atau `.class` untuk string.
 * @param {string} level  'low' | 'medium' | 'high' | 'emergency'
 * @returns {{ class: string, bg: string, text: string, border: string }}
 */
export function ewsBadgeClasses(level) {
    const parts = EWS_BADGE_CLASSES[level] || EWS_BADGE_FALLBACK;

    return {
        class: parts.join(' '),
        bg: parts[0],
        text: parts[1],
        border: parts[2],
    };
}

/** Label pendek badge EWS; level tidak dikenal -> '-'. */
export function riskBadgeLabel(level) {
    return EWS_RISK_LABELS[level] || EWS_RISK_LABELS.none;
}

/** Label panjang untuk banner; level tidak dikenal -> '-'. */
export function riskLongLabel(level) {
    return EWS_RISK_LONG_LABELS[level] || EWS_RISK_LONG_LABELS.none;
}

/** Plafon sumbu Y tren EWS (6 parameter x skor 3). */
export const EWS_MAX_TOTAL = 18;

/* ===========================================================================
   Persentase kepatuhan - port percentTone() dari phase1/bundles.html
   =========================================================================== */

/**
 * >=100 emerald, >=80 sky, >=50 amber, selain itu red.
 * @param {number} percent
 * @returns {{ tone: string, text: string, chip: string, fill: string }}
 */
export function percentTone(percent) {
    const value = Number(percent);
    const n = Number.isFinite(value) ? value : 0;

    if (n >= 100) {
        return {
            tone: 'emerald',
            text: 'text-emerald-600',
            chip: 'bg-emerald-100 text-emerald-700 border border-emerald-200',
            fill: 'bg-emerald-500',
        };
    }
    if (n >= 80) {
        return {
            tone: 'sky',
            text: 'text-sky-600',
            chip: 'bg-sky-100 text-sky-700 border border-sky-200',
            fill: 'bg-sky-500',
        };
    }
    if (n >= 50) {
        return {
            tone: 'amber',
            text: 'text-amber-600',
            chip: 'bg-amber-100 text-amber-700 border border-amber-200',
            fill: 'bg-amber-500',
        };
    }
    return {
        tone: 'red',
        text: 'text-red-600',
        chip: 'bg-red-100 text-red-700 border border-red-200',
        fill: 'bg-red-500',
    };
}

/* ===========================================================================
   Bundle HAIs - port GROUP_STYLES dari phase1/bundles.html
   =========================================================================== */
export const BUNDLE_TONE = {
    vap: { key: 'vap', short: 'VAP', accent: 'border-sky-500', chip: 'bg-sky-100 text-sky-700', border: 'border-sky-200', text: 'text-sky-700', line: '#0284c7' },
    clabsi: { key: 'clabsi', short: 'CLABSI', accent: 'border-purple-500', chip: 'bg-purple-100 text-purple-700', border: 'border-purple-200', text: 'text-purple-700', line: '#7c3aed' },
    cauti: { key: 'cauti', short: 'CAUTI', accent: 'border-amber-500', chip: 'bg-amber-100 text-amber-700', border: 'border-amber-200', text: 'text-amber-700', line: '#f59e0b' },
};

/** Ambil gaya bundle dengan fallback ke vap. */
export function bundleTone(groupId) {
    return BUNDLE_TONE[groupId] || BUNDLE_TONE.vap;
}

/* ===========================================================================
   Kategori obat - port CATEGORY_STYLES dari phase1/farmasi.html
   =========================================================================== */
export const MEDICATION_TONE = {
    'Inotropik / Vasopressor': { tone: 'purple', chip: 'bg-purple-100 text-purple-700 border border-purple-200', fill: 'bg-purple-500' },
    'Sedasi & Analgesia': { tone: 'indigo', chip: 'bg-indigo-100 text-indigo-700 border border-indigo-200', fill: 'bg-indigo-500' },
    'Antibiotik': { tone: 'emerald', chip: 'bg-emerald-100 text-emerald-700 border border-emerald-200', fill: 'bg-emerald-500' },
    'Cairan & Elektrolit': { tone: 'sky', chip: 'bg-sky-100 text-sky-700 border border-sky-200', fill: 'bg-sky-500' },
    'Obat Systemic': { tone: 'amber', chip: 'bg-amber-100 text-amber-800 border border-amber-200', fill: 'bg-amber-500' },
    Lainnya: { tone: 'slate', chip: 'bg-slate-100 text-slate-700 border border-slate-200', fill: 'bg-slate-500' },
};

/** Urutan kategori sesuai phase1 (farmasi.html CATEGORY_ORDER). */
export const MEDICATION_CATEGORY_ORDER = [
    'Inotropik / Vasopressor',
    'Sedasi & Analgesia',
    'Antibiotik',
    'Cairan & Elektrolit',
    'Obat Systemic',
    'Lainnya',
];

/** Gaya kategori obat, fallback ke Lainnya. */
export function medicationTone(category) {
    return MEDICATION_TONE[category] || MEDICATION_TONE.Lainnya;
}

/**
 * Nilai ready-state Ya / Tidak / belum dijawab untuk checklist bundle.
 * Port statusChip() dari phase1/bundles.html (hanya kelas + ikon).
 * @param {string|null} answer  'Ya' | 'Tidak' | null
 * @returns {{ text: string, icon: string, class: string }}
 */
export function bundleAnswerChip(answer) {
    if (answer === 'Ya') {
        return { text: 'Ya', icon: 'fa-solid fa-check', class: 'text-emerald-600' };
    }
    if (answer === 'Tidak') {
        return { text: 'Tidak', icon: 'fa-solid fa-xmark', class: 'text-red-600' };
    }
    return { text: EMPTY_DASH, icon: 'fa-solid fa-minus', class: 'text-slate-400' };
}

export const EMPTY_DASH = '-';

export default {
    TONE,
    DEFAULT_TONE,
    TONE_NAMES,
    tone,
    EWS_BADGE_CLASSES,
    EWS_BADGE_FALLBACK,
    EWS_ESCALATION,
    EWS_RISK_LABELS,
    EWS_RISK_LONG_LABELS,
    EWS_MAX_TOTAL,
    riskFrom,
    ewsBadgeClasses,
    riskBadgeLabel,
    riskLongLabel,
    percentTone,
    BUNDLE_TONE,
    bundleTone,
    MEDICATION_TONE,
    MEDICATION_CATEGORY_ORDER,
    medicationTone,
    bundleAnswerChip,
};
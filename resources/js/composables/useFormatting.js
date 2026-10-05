/**
 * useFormatting.js - cermin JavaScript dari
 * app/Services/Support/ClinicalFormat.php.
 *
 * KAPAN DIGUNAKAN:
 *  - Semua field yang berakhiran `*Label` dari server SUDAH siap tampil dan
 *    memakai '-' untuk data kosong. Pakai itu apa adanya; jangan format ulang.
 *  - Fungsi di bawah untuk nilai yang DIKOMPUTASI di sisi client:
 *    hasil hitung ulang, baris yang baru dibuat sebelum reload, isi dropdown
 *    filter, tooltip hover, label sumbu grafik.
 *
 * KONVENSI:
 *  - Nilai date/time server bisa berupa ISO 8601 ber-offset, 'Y-m-d',
 *    'Y-m-d H:i:s', atau 'H:i'. Semua bentuk itu diterima.
 *  - Tanggal TANPA offset ('2026-09-10') dibaca sebagai tanggal LOKAL, bukan
 *    UTC, supaya hari tidak bergeser di timezone negatif.
 *  - Setiap formatter mengembalikan `null` bila tidak bisa diparse. Pakai
 *    `dash()` atau `dateLabel()` kalau butuh string dengan fallback '-'.
 *  - EMPTY = '-' sama persis dengan ClinicalFormat::EMPTY.
 */

export const EMPTY = '-';

const HONORIFICS = [
    'dr', 'dra', 'drs', 'ir', 'irs', 'prof', 's', 'sa', 'st', 'se',
    'ns', 'nst', 'bd', 'bdr', 'tn', 'ny', 'h', 'hj', 'hij', 'ibu', 'bpk',
];

/** Sama dengan ClinicalFormat::dash(). */
export function dash(value) {
    if (value === null || value === undefined) return EMPTY;
    const text = String(value).trim();
    return text === '' || text === EMPTY ? EMPTY : text;
}

/** True bila null, kosong, whitespace, atau sudah '-'. */
export function isBlank(value) {
    if (value === null || value === undefined) return true;
    const text = String(value).trim();
    return text === '' || text === EMPTY;
}

/** String aman untuk dirender: null/'' -> '-', selain itu String(value). */
export function text(value, fallback = EMPTY) {
    return isBlank(value) ? fallback : String(value).trim();
}

const pad = (n) => String(n).padStart(2, '0');

/**
 * Parse bebas menjadi Date lokal, atau null bila tidak bisa.
 * Menerima: Date, number (epoch ms), ISO 8601 (dengan/d tanpa offset),
 * 'Y-m-d', 'Y-m-d H:i(:s)', 'Y-m-dTH:i(:s)', 'H:i(:s)'.
 */
export function parse(value) {
    if (value === null || value === undefined || value === '') return null;

    if (value instanceof Date) return Number.isNaN(value.getTime()) ? null : value;

    if (typeof value === 'number') {
        const fromNumber = new Date(value);
        return Number.isNaN(fromNumber.getTime()) ? null : fromNumber;
    }

    if (typeof value !== 'string') return null;

    const raw = value.trim();

    if (raw === '' || raw === EMPTY) return null;

    // Offset eksplisit (Z / +07:00 / -0500) -> biarkan Date yang mengurai.
    if (/(Z|[+-]\d{2}:?\d{2})$/i.test(raw)) {
        const parsed = new Date(raw);
        return Number.isNaN(parsed.getTime()) ? null : parsed;
    }

    // 'H:i' atau 'H:i:s' -> hari ini, jam tersebut.
    const timeOnly = /^(\d{1,2}):(\d{2})(?::(\d{2}))?$/.exec(raw);
    if (timeOnly) {
        const today = new Date();
        return new Date(today.getFullYear(), today.getMonth(), today.getDate(), Number(timeOnly[1]), Number(timeOnly[2]), Number(timeOnly[3] || 0));
    }

    // 'Y-m-d' -> tanggal lokal (JANGAN new Date('Y-m-d') yang di-parse sebagai UTC).
    const dateOnly = /^(\d{4})-(\d{1,2})-(\d{1,2})$/.exec(raw);
    if (dateOnly) {
        return new Date(Number(dateOnly[1]), Number(dateOnly[2]) - 1, Number(dateOnly[3]));
    }

    // 'Y-m-d H:i[:s]' atau 'Y-m-dTH:i[:s]' -> tanggal + jam lokal.
    const dateTime = /^(\d{4})-(\d{1,2})-(\d{1,2})[T ](\d{1,2}):(\d{2})(?::(\d{2}))?/.exec(raw);
    if (dateTime) {
        return new Date(
            Number(dateTime[1]),
            Number(dateTime[2]) - 1,
            Number(dateTime[3]),
            Number(dateTime[4]),
            Number(dateTime[5]),
            Number(dateTime[6] || 0),
        );
    }

    const fallback = new Date(raw);

    return Number.isNaN(fallback.getTime()) ? null : fallback;
}

/** d/m/Y - identik dengan ClinicalFormat::date(). */
export function formatDate(value) {
    const d = parse(value);
    if (!d) return null;
    return `${pad(d.getDate())}/${pad(d.getMonth() + 1)}/${d.getFullYear()}`;
}

/** H:i - identik dengan ClinicalFormat::time(). */
export function formatTime(value) {
    const d = parse(value);
    if (!d) return null;
    return `${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

/** d/m/Y H:i - identik dengan ClinicalFormat::dateTime(). */
export function formatDateTime(value) {
    const d = parse(value);
    if (!d) return null;
    return `${pad(d.getDate())}/${pad(d.getMonth() + 1)}/${d.getFullYear()} ${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

/**
 * dd/MM/yyyy - format yang diminta tim UI.
 * Bedakan dari formatDate(): ini memakai separator '-' pada tanggal.
 */
export function formatDateID(value) {
    const d = parse(value);
    if (!d) return null;
    return `${pad(d.getDate())}-${pad(d.getMonth() + 1)}-${d.getFullYear()}`;
}

/** d/m H:i - label sumbu X / tooltip tren. WAJIB sama dengan server. */
export function chartLabel(value) {
    const d = parse(value);
    if (!d) return EMPTY;
    return `${d.getDate()}/${d.getMonth() + 1} ${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

/** Nilai untuk <input type="date">: 'Y-m-d' atau ''. */
export function dateInputValue(value) {
    const d = parse(value);
    if (!d) return '';
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
}

/** Nilai untuk <input type="time">: 'H:i' atau ''. */
export function timeInputValue(value) {
    const d = parse(value);
    if (!d) return '';
    return `${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

/** d/m/Y H:i atau '-'. */
export function dateTimeLabel(value) {
    return formatDateTime(value) ?? EMPTY;
}

/** d/m/Y atau '-'. */
export function dateLabel(value) {
    return formatDate(value) ?? EMPTY;
}

/** H:i atau '-'. */
export function timeLabel(value) {
    return formatTime(value) ?? EMPTY;
}

const numberFormatter = new Map();

function idNumber(value, decimals) {
    const key = `${decimals}`;
    if (!numberFormatter.has(key)) {
        numberFormatter.set(key, new Intl.NumberFormat('id-ID', {
            minimumFractionDigits: decimals,
            maximumFractionDigits: decimals,
        }));
    }
    return numberFormatter.get(key).format(value);
}

/**
 * Angka gaya Indonesia: 1.234,56. Null/'' -> '-'.
 * Identik dengan ClinicalFormat::number() modulo perbedaan pemisah desimal
 * (JS Intl memakai koma untuk desimal, sama seperti number_format PHP).
 */
export function formatNumber(value, decimals = 0) {
    if (value === null || value === undefined || value === '') return EMPTY;
    if (typeof value === 'string' && value.trim() !== '' && !Number.isFinite(Number(value))) return dash(value);
    const n = Number(value);
    if (!Number.isFinite(n)) return EMPTY;
    return idNumber(n, Math.max(0, Number(decimals) || 0));
}

/** Angka bertanda untuk balance cairan: +180 / -120 / 0. Null -> '-'. */
export function signed(value, decimals = 0) {
    if (value === null || value === undefined || value === '') return EMPTY;
    const n = Number(value);
    if (!Number.isFinite(n)) return EMPTY;
    const formatted = idNumber(Math.abs(n), Math.max(0, Number(decimals) || 0));
    return n > 0 ? `+${formatted}` : formatted;
}

/** Volume dengan satuan: 1.234 mL. Null -> '-'. */
export function formatVolume(value, unit = 'mL', decimals = 0) {
    const formatted = formatNumber(value, decimals);
    return formatted === EMPTY ? EMPTY : `${formatted} ${unit}`;
}

/** Persentase bulat: 85%. Nilai mentah dipakai apa adanya. */
export function formatPercent(value, decimals = 0) {
    const formatted = formatNumber(value, decimals);
    return formatted === EMPTY ? EMPTY : `${formatted}%`;
}

/**
 * Ubah input bebas menjadi number, atau null bila bukan angka.
 * String kosong / whitespace TIDAK dianggap 0 (sama seperti
 * ClinicalFormat::numeric()).
 */
export function numeric(value) {
    if (value === null || value === undefined || value === '' || typeof value === 'boolean' || Array.isArray(value)) return null;
    if (typeof value === 'string' && value.trim() === '') return null;
    const n = Number(value);
    return Number.isFinite(n) ? n : null;
}

/** Bulat atau null. */
export function integer(value) {
    const n = numeric(value);
    return n === null ? null : Math.round(n);
}

/**
 * Waktu relatif bahasa Indonesia.
 * < 60 detik  -> "baru saja"
 * < 60 menit  -> "5 menit lalu"
 * < 24 jam    -> "2 jam lalu"
 * < 30 hari   -> "3 hari lalu"
 * lainnya     -> formatDateID() (fallback "-")
 */
export function relativeTime(value, now = new Date()) {
    const d = parse(value);
    if (!d) return EMPTY;

    const diffMs = now.getTime() - d.getTime();
    const seconds = Math.floor(diffMs / 1000);
    const absSeconds = Math.abs(seconds);

    if (absSeconds < 60) return 'baru saja';

    const minutes = Math.floor(absSeconds / 60);
    if (minutes < 60) return `${minutes} menit lalu`;

    const hours = Math.floor(minutes / 60);
    if (hours < 24) return `${hours} jam lalu`;

    const days = Math.floor(hours / 24);
    if (days < 30) return `${days} hari lalu`;

    return formatDateID(d) ?? EMPTY;
}

/**
 * Inisial untuk avatar: "OO JAENAL" -> "OJ", "dr. Rangga Saputra" -> "RS".
 * Gelar (dr./Ns./Bd./dst) dilewati. Maksimal 2 huruf.
 */
export function initials(name) {
    if (isBlank(name)) return EMPTY;

    const words = String(name)
        .replace(/[^A-Za-z0-9\s.'-]/g, ' ')
        .split(/[\s.'-]+/)
        .map((word) => word.trim())
        .filter((word) => word !== '' && !HONORIFICS.includes(word.toLowerCase()));

    if (words.length === 0) {
        const fallback = String(name).replace(/[^A-Za-z]/g, '');
        return fallback ? fallback.slice(0, 2).toUpperCase() : EMPTY;
    }

    return words.slice(0, 2).map((word) => word.charAt(0)).join('').toUpperCase();
}

/** Usia dalam tahun (bulat). Tidak bisa dihitung -> null. */
export function ageYears(birthDate, now = new Date()) {
    const birth = parse(birthDate);
    if (!birth) return null;

    let years = now.getFullYear() - birth.getFullYear();
    const monthDiff = now.getMonth() - birth.getMonth();

    if (monthDiff < 0 || (monthDiff === 0 && now.getDate() < birth.getDate())) years -= 1;

    return years < 0 ? null : years;
}

/** Usia dalam bulan (bulat). Tidak bisa dihitung -> null. */
export function ageMonths(birthDate, now = new Date()) {
    const birth = parse(birthDate);
    if (!birth) return null;

    let months = (now.getFullYear() - birth.getFullYear()) * 12 + (now.getMonth() - birth.getMonth());
    if (now.getDate() < birth.getDate()) months -= 1;

    return months < 0 ? null : months;
}

/**
 * Label usia siap tampil: "70 tahun" / "8 bulan" / "-".
 * Dipakai untuk nilai yang dihitung client; kalau server sudah mengirim
 * `demographics`, pakai itu.
 */
export function ageFrom(birthDate, now = new Date()) {
    const months = ageMonths(birthDate, now);
    if (months === null) return EMPTY;
    if (months < 12) return `${months} bulan`;
    return `${Math.floor(months / 12)} tahun`;
}

/** '70 tahun (L)' style: gabungan usia + jenis kelamin. */
export function demographicsFrom(birthDate, sexLabel, now = new Date()) {
    const age = ageFrom(birthDate, now);
    const sex = dash(sexLabel);
    if (age === EMPTY && sex === EMPTY) return EMPTY;
    if (sex === EMPTY) return age;
    if (age === EMPTY) return sex;
    return `${sex} · ${age}`;
}

/**
 * Pecah teks bebas (alergi, daftar obat) menjadi token.
 * Token kosong dan token bermakna "tidak ada" / "n/a" / "unknown" dibuang.
 * Sama seperti ClinicalFormat::splitTokens().
 */
export function splitTokens(value) {
    if (isBlank(value)) return [];

    return String(value)
        .split(/[,;/|]+|\bdan\b/i)
        .map((token) => token.trim())
        .filter((token) => token !== '' && !/^[-–]?$|^tidak ada$|^n\/?a$|^tdk ada$|^unknown$/i.test(token));
}

/** True bila daftar alergi berarti "tidak ada alergi". */
export function hasNoAllergy(value) {
    if (isBlank(value)) return true;
    return /^(tidak ada|n\/?a|tdk ada|unknown|[-–])$/i.test(String(value).trim());
}

/** Selisih hari inklusif (12/09 - 10/09 = 3), dipakai "Hari rawat ke-N". */
export function daysBetweenInclusive(from, to = new Date()) {
    const start = parse(from);
    const end = parse(to);
    if (!start || !end) return null;

    const a = new Date(start.getFullYear(), start.getMonth(), start.getDate());
    const b = new Date(end.getFullYear(), end.getMonth(), end.getDate());
    const diff = Math.round((b.getTime() - a.getTime()) / 86400000) + 1;

    return diff < 1 ? null : diff;
}

/** "Hari rawat ke-3". */
export function hospitalDayLabel(admittedAt, now = new Date()) {
    const days = daysBetweenInclusive(admittedAt, now);
    return days === null ? EMPTY : `Hari rawat ke-${days}`;
}

/** Clamp angka ke rentang, aman untuk nilai null. */
export function clamp(value, min, max) {
    const n = numeric(value);
    if (n === null) return null;
    return Math.min(max, Math.max(min, n));
}

export const formatting = {
    EMPTY,
    dash,
    isBlank,
    text,
    parse,
    formatDate,
    formatTime,
    formatDateTime,
    formatDateID,
    chartLabel,
    dateInputValue,
    timeInputValue,
    dateLabel,
    timeLabel,
    dateTimeLabel,
    formatNumber,
    signed,
    formatVolume,
    formatPercent,
    numeric,
    integer,
    relativeTime,
    initials,
    ageYears,
    ageMonths,
    ageFrom,
    demographicsFrom,
    splitTokens,
    hasNoAllergy,
    daysBetweenInclusive,
    hospitalDayLabel,
    clamp,
};

/**
 * Dipakai halaman dengan cara:
 *   import { useFormatting } from '@/composables/useFormatting';
 *   const { formatDateID, relativeTime } = useFormatting();
 * atau langsung: import { formatDateID } from '@/composables/useFormatting';
 */
export function useFormatting() {
    return formatting;
}

export default formatting;

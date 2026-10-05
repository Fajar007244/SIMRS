/**
 * Helper tampilan AGD dan flag hasil penunjang.
 *
 * LEVEL_STYLE dan flagBadge() adalah port 1:1 dari phase1/penunjang.html
 * (fungsi LEVEL_STYLE / flagBadge). phase1 memakai empat level
 * (criticalLow, criticalHigh, high, low); ReferenceRange menggabungkan dua
 * level kritis menjadi satu `critical` karena stylingnya identik, jadi peta di
 * bawah memakai lima kunci hasil ReferenceRange::classify().
 *
 * analyzeAbg() juga port dari phase1 (fungsi analyzeAbg). Teks detail di
 * prototype rusak (mojibake), jadi kalimatnya ditulis ulang dengan teks
 * Indonesia yang bersih tanpa mengubahيمة ambang dan urutan pertanyaannya.
 */

export const DASH = '-';

export const FLAG_STYLES = {
    critical: 'bg-red-600 text-white border-red-700',
    high: 'bg-red-100 text-red-800 border-red-200',
    low: 'bg-amber-100 text-amber-800 border-amber-200',
    normal: 'bg-emerald-100 text-emerald-800 border-emerald-200',
    none: 'bg-slate-100 text-slate-700 border-slate-200',
};

export const FLAG_ORDER = ['critical', 'high', 'low', 'normal', 'none'];

export function flagStyle(level) {
    return FLAG_STYLES[level] || FLAG_STYLES.none;
}

/** Badge status hasil: nilai normal/'-' selalu ditulis "Normal" seperti phase1. */
export function flagText(row) {
    const level = row?.flag || 'none';

    if (level === 'normal' || level === 'none' || !row?.flagLabel) return 'Normal';

    return row.flagLabel;
}

/** Warna teks nilai pada tabel AGD: kritis merah,_parameter lain slate-900. */
export function valueToneClass(row, key) {
    const flagged = (row?.flags || []).some((flag) => flag.key === key && flag.flag === 'critical');

    return flagged ? 'text-red-700' : 'text-slate-900';
}

/** Warna angka pada sel ringkasan AGD (kritis / di luar rentang / normal). */
export function cellToneClass(level) {
    if (level === 'critical') return 'text-red-700';
    if (level === 'high' || level === 'low') return 'text-amber-700';
    return 'text-slate-900';
}

const PENDING_MARKERS = ['pending', 'menunggu', 'diproses', 'belum', 'pending lab', '-'];

/**
 * Kultur mikrobiologi yang belum keluar. Mengikuti SupportService::isPending():
 * numeric_value kosong DAN nilainya salah satu penanda "pending".
 */
export function isPendingMicro(row) {
    if (row?.numericValue !== null && row?.numericValue !== undefined) return false;

    return PENDING_MARKERS.includes(String(row?.value ?? '').trim().toLowerCase());
}

export function analyzeAbg(row) {
    if (!row) return { label: '-', tone: 'text-slate-500', detail: 'Belum ada data AGD.' };

    const ph = Number(row.ph);
    const pco2 = Number(row.pco2);
    const hco3 = Number(row.hco3);

    if (!Number.isFinite(ph) || !Number.isFinite(pco2) || !Number.isFinite(hco3)) {
        return { label: 'Tidak lengkap', tone: 'text-slate-500', detail: 'Nilai pH / PaCO2 / HCO3 tidak lengkap.' };
    }

    if (ph < 7.35 && hco3 < 22) {
        return {
            label: 'Asidosis Metabolik',
            tone: 'text-red-700',
            detail: 'pH rendah dengan HCO3 rendah - dominan komponen metabolik (kemungkinan AKI / hipoperfusi).',
        };
    }

    if (ph < 7.35 && pco2 > 45) {
        return {
            label: 'Asidosis Respiratorik',
            tone: 'text-red-700',
            detail: 'pH rendah dengan PaCO2 tinggi - hipoventilasi. Pertimbangkan perbaikan jalan napas.',
        };
    }

    if (ph > 7.45 && pco2 < 35) {
        return {
            label: 'Alkalosis Metabolik',
            tone: 'text-amber-700',
            detail: 'pH tinggi dengan PaCO2 rendah - terkompensasi atau primer metabolik (hipokalemia, kehilangan H+).',
        };
    }

    if (ph > 7.45 && pco2 > 45) {
        return {
            label: 'Alkalosis Respiratorik',
            tone: 'text-amber-700',
            detail: 'pH tinggi dengan PaCO2 tinggi - hiperventilasi / alkalosis primer. Periksa saturasi dan adapter.',
        };
    }

    if (Math.abs(ph - 7.4) <= 0.04) {
        return {
            label: 'Normal',
            tone: 'text-emerald-700',
            detail: 'Nilai berada dalam rentang normal. Pantau berkala mengikuti kondisi.',
        };
    }

    return {
        label: 'Borderline',
        tone: 'text-amber-700',
        detail: 'Nilai mendekati batas atas / bawah rentang referensi. Perlu trend berikutnya.',
    };
}
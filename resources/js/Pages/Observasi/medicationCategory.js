/**
 * medicationCategory.js - inferensi kategori obat dari nama obat, dipakai
 * hanya untuk mengisi nilai bawaan dropdown kategori pada baris koreksi
 * pemberian.
 *
 * POLA DIBAWAH INI SALINAN config('formularium.category_patterns'), yang
 * sendirinya port dari inferMedicationCategory() pada
 * phase1/observasi.html. Urutan menentukan hasil: yang pertama cocok menang,
 * dan tidak ada yang cocok berarti "Lainnya".
 *
 * Kategori ini HANYA sugar UI. Sumber kebenaran tetap server:
 * ObservationService::syncMedications() memanggil
 * MedicationRecapService::inferCategory($name) lagi bila `category` kosong,
 * jadi pilihan "Otomatis (dari nama)" tidak pernah menyebabkan data salah
 * terkategori.
 */

export const CATEGORY_PATTERNS = [
    {
        category: 'Inotropik / Vasopressor',
        pattern: /norepi|vasopres|adrenalin|epinefrin|dobutamin|dopamin|vasopressin/,
    },
    {
        category: 'Sedasi & Analgesia',
        pattern: /midazolam|propofol|fentanyl|morfina|remifentanil|dexmed|tramadol|analges|sedat/,
    },
    {
        category: 'Antibiotik',
        pattern: /meropenem|seftri|cef|azitrom|amikasin|gentam|vankom|linezol|antibiotik|piperasilin|tazobakt/,
    },
    {
        category: 'Cairan & Elektrolit',
        pattern: /kcl|nacl|rl|ca |gluk|dextro|mgso|elektrolit|aquabidest|infus|cairan/,
    },
    {
        category: 'Obat Systemic',
        pattern: /paracetamol|ibuprofen|asam|antikoag|heparin|warfarin|insulin/,
    },
];

export const DEFAULT_CATEGORY = 'Lainnya';

/**
 * @param {string|null|undefined} name
 * @returns {string} label kategori, atau string kosong bila nama kosong
 */
export function inferCategory(name) {
    const text = String(name === null || name === undefined ? '' : name).toLowerCase().trim();

    if (!text) return '';

    for (const entry of CATEGORY_PATTERNS) {
        if (entry.pattern.test(text)) return entry.category;
    }

    return DEFAULT_CATEGORY;
}
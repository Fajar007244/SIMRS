/**
 * useToast.js - store toast global (module-scoped reactive array).
 *
 * CARA PAKAI (dari halaman mana pun, tanpa prop drilling):
 *
 *   import { toast } from '@/composables/useToast';
 *
 *   toast.success('Observasi EWS tersimpan.');
 *   toast.error('Gagal menyimpan observasi.');
 *   toast.info('Memuat data_bundle...', { duration: 1500 });
 *
 * ToastHost.vue sudah terpasang di dalam ClinicalLayout, jadi halaman TIDAK
 * perlu memasang ToastHost sendiri. Kalau butuh tampilan toast di luar layout,
 * taruh <ToastHost /> sekali saja.
 *
 * BATAS: maksimal 4 toast tampil bersamaan. Toast paling lama dibuang lebih
 * dulu supaya umpan tidak menutupi layar perawat.
 */

import { reactive, readonly } from 'vue';

export const TOAST_LIMIT = 4;
export const TOAST_DEFAULT_DURATION = 4000;

let sequence = 0;
const timers = new Map();

/** Array reaktif. Dibaca lewat `toastItems` di dalam komponen. */
export const toastItems = reactive([]);

/**
 * Normalisasi argumen: pesan boleh string apa saja, opsi boleh string
 * (durasi) atau objek.
 */
function normalize(message, options) {
    let duration = TOAST_DEFAULT_DURATION;
    let type = 'info';
    let title = '';

    if (typeof options === 'number' && Number.isFinite(options)) {
        duration = options;
    } else if (options && typeof options === 'object') {
        if (Number.isFinite(Number(options.duration))) duration = Number(options.duration);
        if (typeof options.type === 'string') type = options.type;
        if (typeof options.title === 'string') title = options.title;
    }

    const text = message === null || message === undefined ? '' : String(message);

    return { text, type, title, duration: Math.max(0, duration) };
}

/** Buang satu toast berdasarkan id. */
export function dismiss(id) {
    const index = toastItems.findIndex((item) => item.id === id);

    if (index !== -1) toastItems.splice(index, 1);

    if (timers.has(id)) {
        clearTimeout(timers.get(id));
        timers.delete(id);
    }
}

/** Buang semua toast. */
export function clear() {
    toastItems.splice(0, toastItems.length);
    timers.forEach((timer) => clearTimeout(timer));
    timers.clear();
}

/** Tambahkan toast. Fungsi tingkat rendah; pakai toast.success() dsb. */
export function push(message, options) {
    const normalized = normalize(message, options);

    sequence += 1;

    const item = {
        id: `toast-${sequence}`,
        message: normalized.text,
        title: normalized.title,
        type: normalized.type,
        duration: normalized.duration,
    };

    toastItems.push(item);

    // Batas 4: buang yang terlama agar tidak menumpuk.
    while (toastItems.length > TOAST_LIMIT) {
        const oldest = toastItems[0];
        dismiss(oldest.id);
    }

    if (item.duration > 0) {
        timers.set(item.id, setTimeout(() => dismiss(item.id), item.duration));
    }

    return item.id;
}

const TOAST_METHODS = ['success', 'error', 'info', 'warning'];

const api = {
    items: toastItems,
    readOnlyItems: readonly(toastItems),
    push,
    dismiss,
    clear,
    limit: TOAST_LIMIT,
    defaultDuration: TOAST_DEFAULT_DURATION,
};

for (const method of TOAST_METHODS) {
    api[method] = (message, options) => push(message, { ...(typeof options === 'object' && options !== null ? options : {}), type: method, ...(typeof options === 'number' ? { duration: options } : {}) });
}

api.dismiss = dismiss;
api.clear = clear;

/** Objek toast yang diimpor halaman. */
export const toast = api;

/**
 * Dipakai di dalam <script setup> komponen yang mau bereaksi terhadap toast,
 * biasanya tidak perlu - cukup panggil toast.success() langsung.
 */
export function useToast() {
    return toast;
}

export default toast;

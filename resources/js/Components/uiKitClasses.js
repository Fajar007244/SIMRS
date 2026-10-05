/**
 * uiKitClasses.js - DAFTAR KELAS COMPONENT LAYER (Tailwind content marker).
 *
 * ---------------------------------------------------------------------------
 * JANGAN HAPUS FILE INI.
 * ---------------------------------------------------------------------------
 * Tailwind v3 menghapus (`tree-shake`) aturan di dalam `@layer components`
 * yang tidak ditemukan di file mana pun pada `tailwind.config.js` ->
 * `content`. Owner config itu sudah final dan TIDAK boleh diubah oleh developer
 * UI kit, sehingga tidak bisa ditambah `safelist`.
 *
 * akibatnya kelas seperti `btn-icon`, `input-error`, `tab-link-active`,
 * `stat-value`, `empty-block`, `btn-danger`, `table-compact`, `row-hover`,
 * `link`, `chip`, `badge`, `divider`, `textarea`, `field-hint`, dan
 * `panel-title` TIDAK ikut ter-build sampai ada halaman yang memakainya.
 * Padahal 4 developer halaman sedang menulis pemakaiannya secara paralel.
 *
 * File ini adalah "sentinel" yang memuat SEMUA nama kelas component sebagai
 * string literal, sehingga extractor Tailwind selalu melihatnya dan
 *sehingga seluruh kelas ter-build. File ini SENGAJA tidak di-import ke mana pun:
 * ia tidak masuk bundel runtime, hanya dibaca scanner Tailwind.
 *
 * Kalau kamu menambah kelas baru ke `@layer components` di resources/css/
 * app.css, tambahkan juga namanya ke UI_KIT_CLASSES di bawah ini.
 */

/** Seluruh nama kelas yang didefinisikan pada @layer components di app.css. */
export const UI_KIT_CLASSES = [
    'card', 'card-header', 'card-title', 'card-body', 'card-footer',
    'panel', 'panel-title',
    'btn', 'btn-primary', 'btn-secondary', 'btn-ghost', 'btn-danger',
    'btn-success', 'btn-sm', 'btn-icon',
    'input', 'select', 'textarea', 'label', 'field', 'field-hint', 'input-error',
    'badge', 'table', 'thead', 'th', 'td', 'table-compact', 'row-hover', 'table-sticky',
    'tabs', 'tab-link', 'tab-link-active',
    'stat-value', 'mono', 'divider', 'link', 'chip', 'scroll-x', 'empty-block',
    'anim-fade', 'anim-pop', 'anim-slide', 'anim-grow-x', 'anim-spin',
    'print-border', 'pulse-live', 'no-print',
].join(' ');

/** Nama-nama tone yang didukung (lihat resources/js/tone.js). */
export const UI_KIT_TONES = [
    'slate', 'sky', 'emerald', 'teal', 'amber', 'orange',
    'red', 'rose', 'purple', 'indigo', 'yellow', 'hospital',
].join(' ');

/**
 * Warna status EWS yang dideklarasikan di tailwind.config.js
 * (ewsCritical, ewsWarning, ewsNormal). Belum ada markup yang memakainya,
 * sehingga JIT Tailwind tidak pernah mem-build utility-nya. Palet ini sengaja
 * diprioritaskan di UI kit: utilitas yang dipakai HANYA sebagai string di
 * dalam @apply (atau belum dipakai sama sekali) tidak akan muncul di CSS
 * hasil build, dan 4 developer halaman tidak boleh mengedit tailwind.config.js
 * untuk menambahkan safelist. Versi kelasnya didaftarkan di sini supaya
 * selalu tersedia.
 */
export const UI_KIT_EWS_COLORS = [
    'text-ewsCritical', 'bg-ewsCritical', 'border-ewsCritical',
    'text-ewsWarning', 'bg-ewsWarning', 'border-ewsWarning',
    'text-ewsNormal', 'bg-ewsNormal', 'border-ewsNormal',
].join(' ');

/** Hanya untuk dokumentasi; tidak pernah dipakai di runtime. */
export default {
    UI_KIT_CLASSES,
    UI_KIT_TONES,
    UI_KIT_EWS_COLORS,
};
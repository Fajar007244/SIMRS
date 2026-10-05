<script setup>
/**
 * PrintButton.vue - tombol cetak yang memakai aturan @media print yang sudah
 * ada di resources/css/app.css (isolasi #print-area + sembunyikan .no-print).
 *
 * PROPS
 *   label String  default 'Cetak'
 *   icon  String  default 'fa-solid fa-print'
 *   tone  String  default 'secondary'  'primary' | 'secondary' | 'dark' | 'success'
 *   size  String  default ''           '' | 'sm'
 *
 * SLOTS: (tidak ada)
 * EMITS : (tidak ada)
 *
 * CATATAN: ClinicalLayout hanya memasang `id="print-area"` bila prop
 * `printable` true (default). Kalau halaman memanggil Cetak tanpa
 * printable, tidak ada yang tercetak selain area tombol.
 */
const props = defineProps({
    label: { type: String, default: 'Cetak' },
    icon: { type: String, default: 'fa-solid fa-print' },
    tone: {
        type: String,
        default: 'secondary',
        validator: (value) => ['primary', 'secondary', 'dark', 'success'].includes(value),
    },
    size: { type: String, default: '', validator: (value) => ['', 'sm'].includes(value) },
});

const TONE_CLASS = {
    primary: 'bg-sky-600 hover:bg-sky-700 text-white',
    secondary: 'bg-white hover:bg-slate-50 text-slate-700 border border-slate-300',
    dark: 'bg-slate-700 hover:bg-slate-600 text-slate-200 border border-slate-600',
    success: 'bg-emerald-600 hover:bg-emerald-700 text-white',
};

function print() {
    if (typeof window !== 'undefined' && typeof window.print === 'function') {
        window.print();
    }
}
</script>

<template>
    <button
        type="button"
        class="no-print inline-flex items-center gap-1.5 rounded-md font-semibold shadow-sm transition"
        :class="[TONE_CLASS[props.tone] || TONE_CLASS.secondary, props.size === 'sm' ? 'px-2.5 py-1.5 text-[11px]' : 'px-3 py-1.5 text-xs']"
        data-purpose="print-button"
        @click="print"
    >
        <i class="text-slate-400" :class="props.icon" aria-hidden="true"></i>
        <span>{{ props.label }}</span>
    </button>
</template>
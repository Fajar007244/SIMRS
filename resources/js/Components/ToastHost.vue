<script setup>
/**
 * ToastHost.vue - penampil toast global. SUDAH terpasang di dalam
 * ClinicalLayout, jadi halaman TIDAK perlu memakainya lagi.
 *
 * PROPS
 *   limit Number default 4  batas yang ditampilkan (dipakai untuk menggeser
 *                            toast yang meluber ke bawah layar)
 *
 * SLOTS: (tidak ada)
 * EMITS : (tidak ada)
 *
 * Sumber data: composables/useToast.js (module-scoped reactive array).
 * Contoh pemanggilan dari halaman:
 *   import { toast } from '@/composables/useToast';
 *   toast.success('Observasi EWS tersimpan.');
 */
import { computed } from 'vue';
import { toastItems, dismiss, TOAST_LIMIT } from '../composables/useToast';

const props = defineProps({
    limit: { type: Number, default: TOAST_LIMIT },
});

const STYLE = {
    success: { wrap: 'border-emerald-200 bg-emerald-50 text-emerald-900', icon: 'fa-circle-check', iconClass: 'text-emerald-600' },
    error: { wrap: 'border-red-200 bg-red-50 text-red-900', icon: 'fa-triangle-exclamation', iconClass: 'text-red-600' },
    warning: { wrap: 'border-amber-200 bg-amber-50 text-amber-900', icon: 'fa-triangle-exclamation', iconClass: 'text-amber-600' },
    info: { wrap: 'border-sky-200 bg-sky-50 text-sky-900', icon: 'fa-circle-info', iconClass: 'text-sky-600' },
};

function styleFor(type) {
    return STYLE[type] || STYLE.info;
}

/** Toast paling lama dipindah ke bawah supaya muat di layar. */
const items = computed(() => {
    const list = toastItems.slice(-Math.max(1, props.limit));
    return list.map((item, index) => ({ ...item, style: styleFor(item.type), offset: list.length - 1 - index }));
});
</script>

<template>
    <div
        class="no-print pointer-events-none fixed bottom-6 right-6 z-[70] flex w-[min(92vw,22rem)] flex-col items-end gap-2"
        aria-live="polite"
        aria-atomic="false"
        data-purpose="toast-host"
    >
        <div
            v-for="item in items"
            :key="item.id"
            class="anim-slide pointer-events-auto flex w-full items-start gap-2.5 rounded-lg border px-3.5 py-2.5 text-xs font-semibold shadow-lg"
            :class="item.style.wrap"
            :style="{ transform: `translateY(${item.offset * 4}px)` }"
            role="alert"
        >
            <i class="fa-solid mt-0.5 shrink-0" :class="[item.style.icon, item.style.iconClass]" aria-hidden="true"></i>
            <div class="min-w-0 flex-1">
                <strong v-if="item.title" class="block">{{ item.title }}</strong>
                <span class="block break-words leading-relaxed">{{ item.message }}</span>
            </div>
            <button
                type="button"
                class="shrink-0 rounded p-0.5 text-current opacity-60 transition hover:opacity-100"
                aria-label="Tutup notifikasi"
                @click="dismiss(item.id)"
            >
                <i class="fa-solid fa-xmark" aria-hidden="true"></i>
            </button>
        </div>
    </div>
</template>
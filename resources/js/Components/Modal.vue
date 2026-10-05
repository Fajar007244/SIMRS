<script setup>
/**
 * Modal.vue - modal formulir klinis.
 * Port dari phase1/observasi.html blok "ModalFormulirObservasiEWS":
 *   <div class="fixed inset-0 z-50 bg-slate-900/70 backdrop-blur-sm overflow-y-auto
 *                flex items-start justify-center p-2 sm:p-4 md:p-6">
 *     <div class="bg-white w-full max-w-6xl rounded-2xl shadow-2xl border border-slate-300 overflow-hidden my-4">
 *       <div class="bg-slate-800 text-white px-4 py-3 flex items-center justify-between border-b border-slate-700"> ... </div>
 *       <form class="p-5 sm:p-6 space-y-6 max-h-[75vh] overflow-y-auto text-xs text-slate-800"> ... </form>
 *     </div>
 *   </div>
 *
 * FITUR: Teleport ke <body>, focus trap, Escape untuk menutup, kunci scroll
 * body, dan pengembalian fokus ke elemen pemicu saat ditutup.
 *
 * PROPS
 *   open           Boolean default false  buka / tutup modal
 *   title          String  default ''     judul di header
 *   subtitle       String  default ''     keterangan kecil di bawah judul
 *   icon           String  default 'fa-solid fa-file-medical'
 *   size           String  default 'lg'    'sm' | 'md' | 'lg' | 'xl'
 *                                            sm = max-w-md
 *                                            md = max-w-2xl
 *                                            lg = max-w-4xl
 *                                            xl = max-w-6xl  (phase1)
 *   closeOnBackdrop Boolean default true   klik area gelap menutup modal
 *   closeOnEscape  Boolean default true   tombol Escape menutup modal
 *   bodyClass      String  default 'p-5 sm:p-6 max-h-[75vh] overflow-y-auto'
 *
 * EMITS
 *   close  void  dipanggil saat user meminta menutup (X, backdrop, Escape).
 *               Komponen TIDAK menutup dirinya sendiri - halaman yang memutuskan
 *               (`:open="formOpen" @close="formOpen = false"`).
 *
 * SLOTS
 *   default  isi modal (form / tabel)
 *   header   ganti seluruh header. Slot ini menerima slot `title` dan
 *            `subtitle` sebagai default-nya.
 *   footer   bar tombol di bawah, sticky di dasar panel
 *
 * CATATAN AKSESIBILITAS: panel punya role="dialog" aria-modal="true",
 * aria-labelledby menunjuk ke judul.
 */
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';

const props = defineProps({
    open: { type: Boolean, default: false },
    title: { type: String, default: '' },
    subtitle: { type: String, default: '' },
    icon: { type: String, default: 'fa-solid fa-file-medical' },
    size: { type: String, default: 'lg', validator: (value) => ['sm', 'md', 'lg', 'xl'].includes(value) },
    closeOnBackdrop: { type: Boolean, default: true },
    closeOnEscape: { type: Boolean, default: true },
    bodyClass: { type: String, default: 'p-5 sm:p-6 max-h-[75vh] overflow-y-auto' },
});

const emit = defineEmits(['close']);

const SIZE_CLASS = {
    sm: 'max-w-md',
    md: 'max-w-2xl',
    lg: 'max-w-4xl',
    xl: 'max-w-6xl',
};

const panel = ref(null);
let previouslyFocused = null;
let previousOverflow = '';

const panelClass = computed(() => `bg-white ${SIZE_CLASS[props.size] || SIZE_CLASS.lg} w-full rounded-2xl shadow-2xl border border-slate-300 overflow-hidden transform my-4`);

const FOCUSABLE = 'a[href], button:not([disabled]), textarea:not([disabled]), input:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])';

function focusables() {
    if (!panel.value) return [];
    return Array.from(panel.value.querySelectorAll(FOCUSABLE)).filter((node) => node.offsetParent !== null);
}

function onKeydown(event) {
    if (event.key === 'Escape' && props.closeOnEscape) {
        event.stopPropagation();
        emit('close');
        return;
    }

    if (event.key !== 'Tab') return;

    const nodes = focusables();
    if (nodes.length === 0) {
        event.preventDefault();
        return;
    }

    const first = nodes[0];
    const last = nodes[nodes.length - 1];
    const active = document.activeElement;

    if (event.shiftKey && (active === first || !panel.value?.contains(active))) {
        event.preventDefault();
        last.focus();
        return;
    }

    if (!event.shiftKey && active === last) {
        event.preventDefault();
        first.focus();
    }
}

function requestClose() {
    emit('close');
}

function onBackdropClick() {
    if (props.closeOnBackdrop) requestClose();
}

watch(
    () => props.open,
    async (isOpen) => {
        if (isOpen) {
            previouslyFocused = document.activeElement;
            previousOverflow = document.body.style.overflow;
            document.body.style.overflow = 'hidden';
            document.addEventListener('keydown', onKeydown, true);

            await nextTick();
            const nodes = focusables();
            (nodes[0] || panel.value)?.focus?.();
            return;
        }

        document.removeEventListener('keydown', onKeydown, true);
        document.body.style.overflow = previousOverflow;

        if (previouslyFocused && typeof previouslyFocused.focus === 'function') {
            previouslyFocused.focus();
        }
        previouslyFocused = null;
    },
);

onBeforeUnmount(() => {
    document.removeEventListener('keydown', onKeydown, true);
    document.body.style.overflow = previousOverflow;
});
</script>

<template>
    <Teleport to="body">
        <Transition name="ew-modal">
            <div
                v-if="props.open"
                class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/70 p-2 backdrop-blur-sm sm:p-4 md:p-6"
                data-purpose="modal-backdrop"
                @click.self="onBackdropClick"
            >
                <div
                    ref="panel"
                    class="anim-pop"
                    :class="panelClass"
                    role="dialog"
                    aria-modal="true"
                    aria-labelledby="clinical-modal-title"
                    tabindex="-1"
                    data-purpose="modal"
                >
                    <slot name="header">
                        <div class="flex items-center justify-between gap-3 border-b border-slate-700 bg-slate-800 px-4 py-3 text-white">
                            <div class="flex min-w-0 items-center gap-3">
                                <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-sky-600 text-white">
                                    <i class="fa-solid fa-file-medical text-base" :class="props.icon" aria-hidden="true"></i>
                                </div>
                                <div class="min-w-0">
                                    <h3 id="clinical-modal-title" class="truncate text-base font-bold leading-tight text-white">
                                        {{ props.title }}
                                    </h3>
                                    <p v-if="props.subtitle" class="truncate text-xs text-slate-300">{{ props.subtitle }}</p>
                                    <slot name="title"></slot>
                                </div>
                            </div>
                            <button
                                type="button"
                                class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-slate-700 text-slate-400 transition hover:bg-slate-600 hover:text-white"
                                aria-label="Tutup"
                                data-purpose="modal-close"
                                @click="requestClose"
                            >
                                <i class="fa-solid fa-xmark text-lg" aria-hidden="true"></i>
                            </button>
                        </div>
                    </slot>

                    <div class="text-xs text-slate-800" :class="props.bodyClass">
                        <slot></slot>
                    </div>

                    <div
                        v-if="$slots.footer"
                        class="sticky bottom-0 flex flex-wrap items-center justify-end gap-2 border-t border-slate-200 bg-slate-50 px-5 py-3"
                        data-purpose="modal-footer"
                    >
                        <slot name="footer"></slot>
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>

<style scoped>
.ew-modal-enter-active,
.ew-modal-leave-active {
    transition: opacity 0.15s ease-out;
}
.ew-modal-enter-from,
.ew-modal-leave-to {
    opacity: 0;
}
</style>
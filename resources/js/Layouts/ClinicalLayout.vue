<script setup>
/**
 * ClinicalLayout.vue - kerangka halaman untuk SEMUA modul klinis.
 *
 * Port 1:1 dari blok <!-- BEGIN: TopNavigation --> yang identik di
 * phase1/observasi.html, farmasi.html, bundles.html, penunjang.html,
 * cppt.html, dan profil.html:
 *   1. Top bar bg-slate-900   - logo, nama rumah sakit, badge ICU denyut,
 *                                baris versi per modul, link "Daftar Pasien",
 *                                area user + tombol Keluar
 *   2. Tab bar modul bg-slate-800/80 - enam tab, bisa di-scroll horizontal
 *   3. Area konten max-w-[1720px]
 *
 * Ditambah: flash message dari usePage().props.flash (sukses auto-hilang 4
 * detik) dan <ToastHost /> supaya halaman bisa panggil toast.success() tanpa
 * prop drilling.
 *
 * PROPS
 *   title       String                default ''
 *              judul dokumen, dipasang ke <Head>.
 *   subtitle    String                default ''
 *              baris versi modul, mis.
 *              "SIMRS EMR v4.8 - Modul Monitoring Kritis Terpadu".
 *   activeTab   String                default ''
 *              kunci tab aktif: 'profil' | 'cppt' | 'penunjang' | 'farmasi'
 *              | 'observasi' | 'bundles'. Nilai tak dikenal = tidak ada tab
 *              aktif.
 *   encounterId [String, Number, null] default null
 *              id encounter. null/undefined/'' = link "Daftar Pasien"
 *              disembunyikan dan tab modul tidak bisa diklik.
 *   wide        Boolean               default true
 *              true -> max-w-[1720px] (phase1). false -> max-w-7xl.
 *   printable   Boolean               default true
 *              true -> konten dibungkus id="print-area" (syarat @media
 *              print di app.css). false -> id="print-area-content".
 *
 * SLOTS
 *   default  isi halaman (kartu, tabel, grafik)
 *   status   teks status modul; untuk diteruskan ke PatientHeaderBanner. Dicetak
 *            di baris abu-abu banner lewat <PatientHeaderBanner>. Sediakan
 *            di sini supaya halaman cukup menulis satu blok.
 *   actions  tombol/menu di kanan top bar, SEBELUM area user
 *
 * EMITS : (tidak ada)
 *
 * CATATAN <Head>: layout ini memasang <Head :title="title">. Kalau halaman
 * juga memasang <Head title="...">, yang dirender terakhir menang; slot
 * halaman dirender setelah layout, jadi judul milik halaman menang. Keduanya
 * aman dipakai bersamaan.
 *
 * DATA USER dari usePage().props.auth.user:
 *   { id, name, role, roleLabel, specialty, initials }
 * roleLabel / specialty / initials boleh null; komponen menghitung label
 * peran dan inisial sendiri dari name + role.
 *
 * TOKEN CSRF dari usePage().props.csrfToken (string, selalu ada):
 *   Dipakai form "Keluar" di top bar. Halaman Inertia tidak merender @csrf,
 *   dan token session di-regenerate setiap session()->regenerate(), jadi
 *   token dari HTML halaman login lama sudah basi. Halaman yang butuh
 *   <form method="post"> lain (mis. konfirmasi hapus) WAJIB menyalin nilai
 *   ini ke <input type="hidden" name="_token">.
 */
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import { CLINICAL_TABS, url, active as isRouteActive } from '../router';
import { initials as initialsOf, isBlank } from '../composables/useFormatting';
import ToastHost from '../Components/ToastHost.vue';

const props = defineProps({
    title: { type: String, default: '' },
    subtitle: { type: String, default: '' },
    activeTab: { type: String, default: '' },
    encounterId: { type: [String, Number], default: null },
    wide: { type: Boolean, default: true },
    printable: { type: Boolean, default: true },
});

const page = usePage();

/* --------------------------------- user --------------------------------- */

const user = computed(() => {
    const auth = page.props.auth || {};
    return auth.user && typeof auth.user === 'object' ? auth.user : null;
});

/** Dipakai hanya bila server tidak mengirim `roleLabel`. */
const ROLE_LABELS = {
    perawat: 'Perawat ICU',
    bidan: 'Bidan',
    dokter: 'Dokter / DPJP',
    dpjp: 'DPJP',
    farmasis: 'Farmasis',
    admin: 'Administrator',
    superadmin: 'Superadmin',
};

const roleLabel = computed(() => {
    const source = user.value;

    if (!source) return '';
    if (!isBlank(source.roleLabel)) return String(source.roleLabel);

    const key = String(source.role || '').trim().toLowerCase();

    if (ROLE_LABELS[key]) return ROLE_LABELS[key];

    return isBlank(source.role) ? '' : String(source.role);
});

const userInitials = computed(() => {
    const source = user.value;

    if (!source) return '';
    if (!isBlank(source.initials)) return String(source.initials);

    return isBlank(source.name) ? '' : initialsOf(source.name);
});

const userSecondary = computed(() => {
    const source = user.value;

    if (!source) return '';

    const specialty = isBlank(source.specialty) ? '' : String(source.specialty);

    if (specialty && roleLabel.value) return `${roleLabel.value} - ${specialty}`;

    return specialty || roleLabel.value;
});

/**
 * Token CSRF untuk <form method="post"> biasa.
 *
 * WAJIB: halaman Inertia tidak pernah merender @csrf seperti Blade, dan
 * token SESSION ikut di-regenerate setiap kali session()->regenerate()
 * dipanggil (termasuk pada POST /login), jadi token yang diambil dari
 * HTML halaman login lama akan basi. ClinicalLayout membacanya dari
 * HandleInertiaRequests::share() => props.csrfToken.
 */
const csrfToken = computed(() => page.props.csrfToken || '');

const logoutUrl = computed(() => url('logout'));
const patientsUrl = computed(() => url('pasien'));

const hasEncounter = computed(
    () => props.encounterId !== null && props.encounterId !== undefined && props.encounterId !== '',
);

/* --------------------------------- tabs --------------------------------- */

const tabs = computed(() =>
    CLINICAL_TABS.map((tab) => ({
        ...tab,
        isActive: props.activeTab === tab.key,
        href: hasEncounter.value ? url(tab.route, { encounter: props.encounterId }) : null,
        isCurrent: isRouteActive(tab.route),
    })),
);

/* -------------------------------- flash --------------------------------- */

const SUCCESS_TIMEOUT = 4000;

const flashes = ref([]);
let flashTimer = null;

const FLASH_STYLE = {
    success: { wrap: 'border-emerald-200 bg-emerald-50 text-emerald-900', icon: 'fa-circle-check', iconClass: 'text-emerald-600' },
    error: { wrap: 'border-red-200 bg-red-50 text-red-900', icon: 'fa-circle-xmark', iconClass: 'text-red-600' },
};

function flashStyle(type) {
    return FLASH_STYLE[type] || FLASH_STYLE.success;
}

function dismissFlash(id) {
    flashes.value = flashes.value.filter((entry) => entry.id !== id);
}

function pushFlash(type, message) {
    if (isBlank(message)) return;

    flashes.value = [
        ...flashes.value,
        { id: `flash-${type}-${Date.now()}-${flashes.value.length}`, type, message: String(message) },
    ];
}

watch(
    () => [page.props.flash?.success ?? null, page.props.flash?.error ?? null],
    ([success, error]) => {
        if (flashTimer) {
            clearTimeout(flashTimer);
            flashTimer = null;
        }

        flashes.value = [];
        pushFlash('success', success);
        pushFlash('error', error);

        if (!isBlank(success)) {
            flashTimer = setTimeout(() => {
                const entry = flashes.value.find((item) => item.type === 'success');
                if (entry) dismissFlash(entry.id);
                flashTimer = null;
            }, SUCCESS_TIMEOUT);
        }
    },
    { immediate: true },
);

onBeforeUnmount(() => {
    if (flashTimer) clearTimeout(flashTimer);
});
</script>

<template>
    <Head :title="props.title || undefined" />

    <div class="min-h-screen bg-slate-100 text-slate-800 antialiased" data-page-region="root">
        <!-- ============ 1. TOP BAR ============ -->
        <header class="sticky top-0 z-40 border-b border-slate-800 bg-slate-900 text-white shadow-md no-print" data-page-region="topbar">
            <div class="mx-auto flex max-w-[1720px] flex-wrap items-center justify-between gap-3 px-4 py-2.5 sm:px-6">
                <div class="flex items-center space-x-3">
                    <div class="flex h-9 w-9 items-center justify-center rounded-lg bg-sky-600 text-xl font-black tracking-wider text-white shadow-inner">
                        <i class="fa-solid fa-heart-pulse" aria-hidden="true"></i>
                    </div>
                    <div>
                        <div class="flex items-center gap-2">
                            <span class="text-sm font-extrabold uppercase tracking-wide text-sky-400 sm:text-base">
                                RSP Dr. H. A. Rotinsulu
                            </span>
                            <span class="inline-flex items-center gap-1 rounded border border-red-500/40 bg-red-500/20 px-2 py-0.5 text-[10px] font-bold text-red-400">
                                <span class="pulse-live h-1.5 w-1.5 rounded-full bg-red-500"></span>
                                ICU INTENSIVE CARE
                            </span>
                        </div>
                        <p class="font-mono text-[11px] text-slate-400">
                            {{ props.subtitle || 'SIMRS EMR v4.8 - Modul Monitoring Kritis Terpadu' }}
                        </p>
                    </div>
                </div>

                <div class="flex items-center gap-2.5">
                    <a
                        v-if="hasEncounter"
                        :href="patientsUrl"
                        class="inline-flex items-center gap-1.5 text-xs font-semibold text-slate-300 transition hover:text-sky-400"
                        data-page-region="back-link"
                    >
                        <i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Daftar Pasien
                    </a>

                    <slot name="actions"></slot>

                    <span v-if="user" class="flex items-center gap-2.5" data-page-region="user-area">
                        <span class="hidden flex-col items-end leading-tight sm:flex">
                            <span class="text-[11px] font-bold text-slate-100">{{ user.name }}</span>
                            <span class="text-[10px] text-slate-400">{{ userSecondary }}</span>
                        </span>
                        <span
                            v-if="userInitials"
                            class="flex h-7 w-7 items-center justify-center rounded-full bg-sky-700 text-[10px] font-bold text-white"
                            :title="user.name"
                        >{{ userInitials }}</span>
                        <form method="post" :action="logoutUrl" class="inline">
                            <input type="hidden" name="_token" :value="csrfToken" />
                            <button
                                type="submit"
                                class="inline-flex items-center gap-2 rounded-lg border border-slate-600 bg-slate-800 px-3.5 py-2 text-xs font-semibold text-slate-200 shadow transition hover:bg-slate-700"
                            >
                                <i class="fa-solid fa-right-from-bracket text-slate-400" aria-hidden="true"></i>
                                <span>Keluar</span>
                            </button>
                        </form>
                    </span>
                </div>
            </div>

            <!-- ============ 2. MODULE TAB BAR ============ -->
            <div class="scroll-x border-t border-slate-700/60 bg-slate-800/80 px-4 text-xs font-semibold sm:px-6" data-page-region="module-tabs">
                <div class="mx-auto flex max-w-[1720px] items-center space-x-1 py-1">
                    <a
                        v-for="tab in tabs"
                        :key="tab.key"
                        :href="tab.href || '#'"
                        class="flex items-center gap-1.5 rounded-md px-3 py-1.5 transition"
                        :class="tab.isActive
                            ? 'bg-sky-700 text-white shadow-sm'
                            : (tab.href ? 'text-slate-300 hover:bg-slate-700/70 hover:text-white' : 'cursor-not-allowed text-slate-500')"
                        :aria-current="tab.isActive ? 'page' : undefined"
                        :data-tab="tab.key"
                        :data-active="tab.isActive ? 'true' : 'false'"
                        @click="!tab.href && $event.preventDefault()"
                    >
                        <i class="fa-solid" :class="[tab.icon, tab.iconClass]" aria-hidden="true"></i>
                        <span class="whitespace-nowrap">{{ tab.label }}</span>
                    </a>
                </div>
            </div>
        </header>

        <!-- ============ 3. FLASH ============ -->
        <div
            v-if="flashes.length"
            class="mx-auto w-full max-w-[1720px] space-y-2 px-4 pt-4 no-print sm:px-6"
            data-page-region="flash"
        >
            <div
                v-for="entry in flashes"
                :key="entry.id"
                class="anim-slide flex items-start gap-2.5 rounded-lg border px-4 py-3 text-sm font-semibold shadow-sm"
                :class="flashStyle(entry.type).wrap"
                role="alert"
            >
                <i class="fa-solid mt-0.5" :class="[flashStyle(entry.type).icon, flashStyle(entry.type).iconClass]" aria-hidden="true"></i>
                <span class="min-w-0 flex-1">{{ entry.message }}</span>
                <button type="button" class="shrink-0 opacity-60 transition hover:opacity-100" aria-label="Tutup" @click="dismissFlash(entry.id)">
                    <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                </button>
            </div>
        </div>

        <!-- ============ 4. KONTEN HALAMAN ============ -->
        <main
            :id="props.printable ? 'print-area' : 'print-area-content'"
            class="mx-auto w-full space-y-4 px-4 pt-5 sm:px-6"
            :class="props.wide ? 'max-w-[1720px]' : 'max-w-7xl'"
            data-page-region="content"
        >
            <slot name="status"></slot>
            <slot></slot>
        </main>

        <footer
            class="mx-auto w-full max-w-[1720px] px-4 py-6 text-center text-[11px] text-slate-400 no-print sm:px-6"
            data-page-region="footer"
        >
            SIMRS RSP Dr. H. A. Rotinsulu - Modul Monitoring Kritis Terpadu ICU
        </footer>

        <ToastHost />
    </div>
</template>
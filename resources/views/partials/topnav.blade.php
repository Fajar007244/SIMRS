{{--
    Top bar (Blade) - port 1:1 dari blok data-page-region="topbar" di
    resources/js/Layouts/ClinicalLayout.vue, tanpa tab bar modul.

    Dipakai HANYA oleh halaman patients (resources/views/pasien/index.blade.php)
    karena halaman itu tidak punya konteks encounter, jadi tidak ada tab modul
    yang bisa ditampilkan. Modul klinis memakai ClinicalLayout.vue.

    Bedanya dengan ClinicalLayout: Blade punya @csrf sehingga form "Keluar"
    tidak perlu props.csrfToken dari Inertia.

    Data user dibaca dari auth()->user() (sumber yang sama dengan
    HandleInertiaRequests::user(), termasuk cara menghitung initials).
--}}
@php
    $navUser = auth()->user();
    $navRoleLabel = $navUser?->role?->label();
    $navSpecialty = $navUser?->specialty;
    $navSecondary = collect([$navRoleLabel, $navSpecialty])->filter()->implode(' - ');
    $navWords = $navUser ? preg_split('/\s+/', trim((string) $navUser->name), -1, PREG_SPLIT_NO_EMPTY) : [];
    $navInitials = $navWords === []
        ? '?'
        : mb_strtoupper(count($navWords) === 1
            ? mb_substr($navWords[0], 0, 1)
            : mb_substr($navWords[0], 0, 1).mb_substr(end($navWords), 0, 1));
@endphp
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
                    SIMRS EMR v4.8 &bull; Modul Monitoring Kritis Terpadu
                </p>
            </div>
        </div>

        @if ($navUser)
            <div class="flex items-center gap-2.5" data-page-region="user-area">
                <span class="hidden flex-col items-end leading-tight sm:flex">
                    <span class="text-[11px] font-bold text-slate-100">{{ $navUser->name }}</span>
                    <span class="text-[10px] text-slate-400">{{ $navSecondary }}</span>
                </span>
                <span class="flex h-7 w-7 items-center justify-center rounded-full bg-sky-700 text-[10px] font-bold text-white" title="{{ $navUser->name }}">
                    {{ $navInitials }}
                </span>
                <form method="post" action="{{ route('logout') }}" class="inline">
                    @csrf
                    <button type="submit" class="inline-flex items-center gap-2 rounded-lg border border-slate-600 bg-slate-800 px-3.5 py-2 text-xs font-semibold text-slate-200 shadow transition hover:bg-slate-700">
                        <i class="fa-solid fa-right-from-bracket text-slate-400" aria-hidden="true"></i>
                        <span>Keluar</span>
                    </button>
                </form>
            </div>
        @endif
    </div>
</header>

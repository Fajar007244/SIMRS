{{--
    Daftar pasien / census ICU - SERVER-RENDERED Blade.

    Port dari phase1/pasien.html: kartu statistik, filter, tabel/card census,
    tombol "Tambah Pasien", dan modal admisi lima tab.

    Halaman ini sengaja full page load, bukan SPA: census tetap hidup walau
    bundel JavaScript gagal dimuat dan halamannya bisa dicetak apa adanya.
    Karena itu skrip di halaman ini murni progressive enhancement.

    Layout: resources/views/layouts/page.blade.php (HTML penuh + @stack scripts).
    Data dari PasienController@index; bentuk baris patient-card dijelaskan di
    docs/FRONTEND_CONTRACT.md 6.2.
--}}
@extends('layouts.page')

@push('title', 'Daftar Pasien | ')

@section('body')
@include('partials.topnav')

@php
    $statCards = [
        ['label' => 'Total Pasien', 'value' => $stats['total'], 'sub' => 'seluruh episode rawat', 'icon' => 'fa-users', 'tone' => 'slate', 'detail' => 'Jumlah seluruh episode perawatan, berapa pun statusnya.'],
        ['label' => 'Pasien Aktif', 'value' => $stats['active'], 'sub' => 'status episode = Aktif', 'icon' => 'fa-user-check', 'tone' => 'sky', 'detail' => 'Episode yang masih berjalan dan bisa dibuka modul kliniknya.'],
        ['label' => 'Berisiko Tinggi', 'value' => $stats['atRisk'], 'sub' => 'EWS high / emergency', 'icon' => 'fa-triangle-exclamation', 'tone' => 'red', 'detail' => 'Pasien dengan risiko EWS terakhir high atau emergency.'],
        ['label' => 'Cocok Filter', 'value' => $stats['matching'], 'sub' => 'dari '.$stats['total'].' episode', 'icon' => 'fa-filter', 'tone' => 'emerald', 'detail' => 'Jumlah episode yang cocok dengan pencarian, unit, dan status yang dipilih.'],
    ];
    $resetUrl = route('pasien');
@endphp

<main id="print-area" class="mx-auto w-full max-w-[1720px] space-y-4 px-4 pb-16 pt-5 sm:px-6" data-page-region="content">

    {{-- Judul + jumlah pasien aktif + tombol cetak (phase1/pasien.html) --}}
    <section class="flex flex-wrap items-end justify-between gap-3 no-print">
        <div>
            <h1 class="flex items-center gap-2.5 text-2xl font-black tracking-tight">
                <i class="fa-solid fa-bed-pulse text-sky-600" aria-hidden="true"></i>Daftar Pasien
            </h1>
            <p class="mt-1 text-sm text-slate-500">
                Pantau pasien terpasang, triase risiko EWS, dan buka lembar perawatan tiap episode rawat.
            </p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <div class="flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-2.5 shadow-sm">
                <i class="fa-solid fa-users text-sky-600" aria-hidden="true"></i>
                <span class="text-xs text-slate-500">Pasien aktif</span>
                <strong class="font-mono text-lg text-slate-900" id="livePatientCount">{{ $stats['active'] }}</strong>
            </div>
            <button type="button" id="printButton"
                    class="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-4 py-2.5 text-sm font-bold text-slate-700 shadow-sm transition hover:bg-slate-50">
                <i class="fa-solid fa-print text-slate-400" aria-hidden="true"></i>Cetak
            </button>
        </div>
    </section>

    @if (session('success'))
        <section class="no-print flex items-start gap-2 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-800"
                 role="status" data-purpose="page-flash">
            <i class="fa-solid fa-circle-check mt-0.5 shrink-0" aria-hidden="true"></i>
            <span>{{ session('success') }}</span>
        </section>
    @endif

    {{-- Baris statistik: bentuk StatCard.vue, ditulis dengan kelas yang sama --}}
    <section class="grid grid-cols-2 gap-3 xl:grid-cols-4" data-purpose="patient-stats">
        @foreach ($statCards as $card)
            @php($cardTone = \App\Support\BadgeTone::stat($card['tone']))
            <div class="rounded-xl border border-l-4 border-slate-200 bg-white p-3.5 shadow-sm {{ $cardTone['accent'] }}"
                 title="{{ $card['detail'] }}">
                <div class="mb-1 flex items-center justify-between gap-2 text-slate-500">
                    <span class="truncate text-[10px] font-bold uppercase tracking-wider">{{ $card['label'] }}</span>
                    <i class="shrink-0 fa-solid {{ $card['icon'] }} {{ $cardTone['icon'] }}" aria-hidden="true"></i>
                </div>
                <div class="stat-value {{ $cardTone['value'] }}">{{ $card['value'] }}</div>
                <div class="mt-0.5 text-[10px] text-slate-400">{{ $card['sub'] }}</div>
            </div>
        @endforeach
    </section>

    {{-- Filter: form GET, tetap berfungsi tanpa JavaScript --}}
    <section class="no-print rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5" data-purpose="patient-filters">
        <form method="GET" action="{{ route('pasien') }}" class="flex flex-wrap items-end gap-3">
            <div class="field relative min-w-[220px] flex-1">
                <label class="label" for="searchInput">Cari pasien</label>
                <i class="fa-solid fa-magnifying-glass pointer-events-none absolute left-3 top-[34px] text-sm text-slate-400" aria-hidden="true"></i>
                <input id="searchInput" name="search" type="search" value="{{ $filters['search'] }}"
                       placeholder="Cari nama, No. RM, atau diagnosa..."
                       class="input py-2 pl-10 pr-3 text-sm" autocomplete="off">
            </div>

            <div class="field w-full sm:w-auto">
                <label class="label" for="unitFilter">Unit pelayanan</label>
                <select id="unitFilter" name="unit" class="select py-2 pr-8 text-sm" data-autosubmit>
                    <option value="">Semua unit</option>
                    @foreach ($units as $unit)
                        <option value="{{ $unit }}" @selected($filters['unit'] === $unit)>{{ $unit }}</option>
                    @endforeach
                </select>
            </div>

            <div class="field w-full sm:w-auto">
                <label class="label" for="statusFilter">Status episode</label>
                <select id="statusFilter" name="status" class="select py-2 pr-8 text-sm" data-autosubmit>
                    <option value="aktif" @selected($filters['status'] === 'aktif')>Aktif</option>
                    <option value="all" @selected($filters['status'] === 'all')>Semua status</option>
                </select>
            </div>

            <div class="flex items-center gap-2">
                <button type="submit" class="btn btn-primary py-2 text-sm">
                    <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>Terapkan
                </button>
                <a href="{{ $resetUrl }}" class="btn btn-secondary py-2 text-sm">
                    <i class="fa-solid fa-rotate-left" aria-hidden="true"></i>Reset
                </a>
            </div>

            {{--
                Pemicu modal "Tambah Pasien" - ujung baris filter, terdorong ke
                kanan oleh ml-auto (persis phase1/pasien.html baris 62).

                type="button" supaya tidak ikut mengirim form GET. Hanya
                ditampilkan untuk dokter: membuat admisi berarti menulis ASMED
                + diagnosa + prosedur, dan docs/AUTHORIZATION.md membatasi
                ketiganya ke peran dokter. Penegakannya juga ada di server
                (routes/pages.pasien.php -> role:dokter) supaya tidak hanya
                bergantung markup.
            --}}
            @if ($canCreateAdmission)
                <button id="openAddPatientBtn" type="button"
                        class="ml-auto inline-flex items-center gap-2 rounded-lg bg-emerald-600 px-4 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-emerald-700">
                    <i class="fa-solid fa-user-plus"></i>Tambah Pasien
                </button>
            @endif
        </form>
        <p class="field-hint">
            Menampilkan {{ $patients->total() }} episode
            @if ($patients->total() > $patients->perPage())
                (halaman {{ $patients->currentPage() }} dari {{ $patients->lastPage() }})
            @endif
            &middot; diurutkan dari risiko EWS tertinggi.
        </p>
    </section>

    {{-- Census pasien --}}
    @if ($patients->isEmpty())
        <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm" data-purpose="patient-empty">
            <div class="empty-block">
                <i class="fa-solid fa-inbox text-lg text-slate-400" aria-hidden="true"></i>
                <p class="mt-2 text-xs font-semibold text-slate-700">Belum ada pasien yang sesuai filter</p>
                <p class="mx-auto mt-1 max-w-md text-[11px] leading-relaxed text-slate-500">
                    Ubah kata kunci, pilih unit lain, atau set status ke "Semua status".
                </p>
                <a href="{{ $resetUrl }}" class="btn btn-secondary mt-3">
                    <i class="fa-solid fa-rotate-left" aria-hidden="true"></i>Reset filter
                </a>
            </div>
        </section>
    @else
        <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3" data-purpose="patient-census">
            @foreach ($patients as $patient)
                @include('partials.patient-card', ['p' => $patient])
            @endforeach
        </section>

        {{-- Paginasi (view bawaan Tailwind Laravel) --}}
        <nav class="no-print" aria-label="Navigasi halaman" data-purpose="patient-pagination">
            {{ $patients->onEachSide(1)->links() }}
        </nav>
    @endif
</main>

{{--
    Modal "Tambah Pasien" (lima tab), diletakkan setelah <main> seperti
    prototype. Hanya dirender untuk dokter, sama seperti tombol pemicunya:
    markup ini tanpa ada kegunaan bagi peran lain dan tidak perlu ikut
    terkirim ke peramban mereka.
--}}
@if ($canCreateAdmission)
    @include('partials.add-patient-modal')
@endif

<footer class="mx-auto w-full max-w-[1720px] px-4 pb-8 text-center text-[11px] text-slate-400 no-print sm:px-6" data-page-region="footer">
    SIMRS RSP Dr. H. A. Rotinsulu &mdash; Modul Monitoring Kritis Terpadu ICU
</footer>
@endsection

@push('scripts')
<script>
    /*
     * Progressive enhancement HANYA - form filter tetap bekerja penuh tanpa
     * JavaScript (method="GET" + tombol Terapkan). Skrip ini hanya
     * (:a) mengirim form saat <select> berubah, (:b) memicu Cetak.
     * Sengaja vanilla, tanpa framework, supaya halaman ini tetap hidup walau
     * bundel SPA gagal dimuat.
     */
    (function () {
        'use strict';

        var form = document.querySelector('[data-purpose="patient-filters"] form');

        if (form) {
            form.querySelectorAll('[data-autosubmit]').forEach(function (element) {
                element.addEventListener('change', function () {
                    form.submit();
                });
            });
        }

        var printButton = document.getElementById('printButton');

        if (printButton) {
            printButton.addEventListener('click', function () {
                window.print();
            });
        }
    })();
</script>
@endpush

{{-- Skrip modal "Tambah Pasien" (port savePatient() dari phase1/pasien.html).

   CONDITION SAMA DENGAN MARKUPNYA. Script-nya sudah keluar sendiri kalau
   markup modal tidak ada (`if (! modal) return;`), tapi tetap tidak dikirim
   ke perawat/bidan: supaya halaman mereka tidak memuat markup fitur yang
   tidak bisa mereka pakai, dan supaya tidak ada satu pun referensi
   `openAddPatientBtn` di HTML mereka. --}}
@if ($canCreateAdmission)
    @include('partials.add-patient-modal-script')
@endif
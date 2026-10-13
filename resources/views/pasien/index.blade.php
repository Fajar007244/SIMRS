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
        ['label' => 'Cocok Filter', 'value' => $stats['matching'], 'sub' => 'dari '.$stats['total'].' episode', 'icon' => 'fa-filter', 'tone' => 'emerald', 'detail' => 'Jumlah episode yang cocok dengan pencarian, unit, dan status yang dipilih.', 'purpose' => 'stat-matching'],
    ];
    $resetUrl = route('pasien');

    /*
     * Template rute "Selesaikan Episode" dengan placeholder __ENCOUNTER__.
     *
     * Setiap kartu punya kode episode yang berbeda, jadi action form tidak
     * bisa ditulis Blade sekaligus - ia diisi dari data-encounter tombol
     * pemicu saat modal dibuka (lihat skrip di bawah).
     *
     * route() dipakai supaya pembentukannya tetap milik Laravel: prefix,
     * domain, dan escaping tidak ditulis ulang manual di JavaScript.
     *
     * Placeholder-nya sengaja bukan "enc-..." supaya tidak ikut tertimpa
     * str_replace: ia memuat __ENCOUNTER__ sebagai substring, dan urutannya
     * penting.
     */
    $dischargeTemplate = str_replace(
        'enc-__ENCOUNTER__',
        '__ENCOUNTER__',
        route('encounter.discharge', ['encounter' => 'enc-__ENCOUNTER__'])
    );
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
                <div class="stat-value {{ $cardTone['value'] }}"@isset($card['purpose']) data-purpose="{{ $card['purpose'] }}"@endisset>{{ $card['value'] }}</div>
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
        @include('partials.patient-summary', ['patients' => $patients])
    </section>

    {{-- Census pasien (partial dipakai ulang oleh respons AJAX) --}}
    <div id="censusRegion">
        @include('partials.patient-census', ['patients' => $patients, 'resetUrl' => $resetUrl, 'canCloseEpisode' => $canCloseEpisode])
    </div>
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

{{--
    Modal "Selesaikan Episode" (partials/discharge-modal.blade.php).

    Dipakai variabel yang sama dengan tombol "Tambah Pasien" di atas. Keduanya
    dibatasi ke dokter karena docs/AUTHORIZATION.md menutup episode hanya untuk
    peran itu. Bedanya: tombol pemicu "Selesaikan Episode" per kartu juga
    memeriksa status episode di partials/patient-card.blade.php, sedangkan
    modal ini dirender sekali untuk seluruh halaman.
--}}
@if ($canCloseEpisode)
    @include('partials.discharge-modal')
@endif

<footer class="mx-auto w-full max-w-[1720px] px-4 pb-8 text-center text-[11px] text-slate-400 no-print sm:px-6" data-page-region="footer">
    SIMRS RSP Dr. H. A. Rotinsulu &mdash; Modul Monitoring Kritis Terpadu ICU
</footer>
@endsection

@push('scripts')
<script>
    /*
     * Filter tanpa reload (AJAX) - progressive enhancement.
     *
     * Tanpa JavaScript, form ini adalah GET biasa + tombol Terapkan dan semua
     * tetap bekerja (full page load). Dengan JavaScript, pengiriman form
     * (ketikan pencarian, perubahan dropdown, tombol Terapkan, Enter)
     * diambil alih menjadi fetch: server mengembalikan potongan HTML yang
     * sama persis dengan full page load (partials/patient-census), lalu
     * client menukarnya ke #censusRegion. Markup kartu tidak diduplikasi di
     * sini - satu-satunya sumber adalah Blade di server.
     *
     * Sengaja vanilla, tanpa framework, supaya halaman ini tetap hidup walau
     * bundel SPA gagal dimuat.
     */
    (function () {
        'use strict';

        var form = document.querySelector('[data-purpose="patient-filters"] form');
        var censusRegion = document.getElementById('censusRegion');

        if (! form || ! censusRegion) {
            return;
        }

        var searchInput = document.getElementById('searchInput');
        var searchTimer = null;
        var requestSeq = 0;

        /*
         * Ambil hasil filter lewat fetch, tukar ke halaman tanpa reload.
         * pushUrl: perbarui address bar supaya hasil bisa di-bookmark dan
         * tombol kembali tetap masuk akal.
         */
        var fetchResults = function (url, pushUrl) {
            var seq = requestSeq + 1;
            requestSeq = seq;

            censusRegion.setAttribute('aria-busy', 'true');
            censusRegion.style.opacity = '0.55';

            var done = function () {
                // Hanya respons terbaru yang boleh menyentuh DOM; yang basi
                // (diketik cepat) diabaikan supaya hasil tidak melompat.
                if (seq !== requestSeq) {
                    return;
                }

                censusRegion.removeAttribute('aria-busy');
                censusRegion.style.opacity = '';
            };

            window.fetch(url, {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                }
            }).then(function (response) {
                if (! response.ok) {
                    throw new Error('HTTP ' + response.status);
                }

                return response.json();
            }).then(function (data) {
                if (seq !== requestSeq) {
                    return;
                }

                censusRegion.innerHTML = data.html;

                var summary = document.querySelector('[data-purpose="result-summary"]');

                if (summary && typeof data.summary === 'string') {
                    summary.outerHTML = data.summary;
                }

                var matching = document.querySelector('[data-purpose="stat-matching"]');

                if (matching && typeof data.matching !== 'undefined') {
                    matching.textContent = data.matching;
                }

                if (pushUrl && url !== window.location.href) {
                    window.history.replaceState(null, '', url);
                }

                done();
            }).catch(function () {
                if (seq !== requestSeq) {
                    return;
                }

                done();

                // Gagal (jaringan putus, session habis): jangan diam. Full
                // reload lewat submit biasa akan menampilkan keadaan yang
                // benar - termasuk redirect ke login bila session habis.
                form.submit();
            });
        };

        var currentUrl = function () {
            var params = new URLSearchParams(new FormData(form));

            // FormData ikut mengirim tombol submit bila ada; buang supaya URL
            // bersih dan stabil.
            return form.action + '?' + params.toString();
        };

        var fetchCurrentFilters = function (pushUrl) {
            fetchResults(currentUrl(), pushUrl !== false);
        };

        // Dropdown unit/status: langsung saring tanpa menunggu Terapkan.
        // Tombol Terapkan tetap ada dan tetap berfungsi (submit biasa bila
        // JS mati; fetch bila JS hidup - lihat di bawah).
        form.querySelectorAll('[data-autosubmit]').forEach(function (element) {
            element.addEventListener('change', function () {
                fetchCurrentFilters(true);
            });
        });

        if (searchInput) {
            // Tautan hasil bersama (?search=...) yang dibuka langsung:
            // kembalikan fokus + karet ke ujung teks supaya bisa lanjut mengetik.
            if (searchInput.value !== '') {
                searchInput.focus();

                try {
                    searchInput.setSelectionRange(
                        searchInput.value.length,
                        searchInput.value.length
                    );
                } catch (ignore) {
                    // type="search" selalu mendukung ini; abaikan bila tidak.
                }
            }

            /*
             * Cari sambil mengetik: fetch 400ms setelah ketikan terakhir.
             * Tanpa debounce, tiap huruf memicu request (mahal di Vercel
             * walau tanpa reload). Tanpa fetch sama sekali, petugas harus
             * Enter/Terapkan tiap kali.
             */
            searchInput.addEventListener('input', function () {
                if (searchTimer !== null) {
                    window.clearTimeout(searchTimer);
                }

                searchTimer = window.setTimeout(function () {
                    searchTimer = null;
                    fetchCurrentFilters(true);
                }, 400);
            });
        }

        // Tombol Terapkan + Enter: tetap berfungsi, hanya tanpa reload.
        form.addEventListener('submit', function (event) {
            event.preventDefault();

            if (searchTimer !== null) {
                window.clearTimeout(searchTimer);
                searchTimer = null;
            }

            fetchCurrentFilters(true);
        });

        // Paginasi hasil saringan: tautan halaman ikut tanpa reload.
        censusRegion.addEventListener('click', function (event) {
            var link = event.target.closest('nav[data-purpose="patient-pagination"] a');

            if (! link || ! link.href) {
                return;
            }

            event.preventDefault();
            fetchResults(link.href, true);
        });

        var printButton = document.getElementById('printButton');

        if (printButton) {
            printButton.addEventListener('click', function () {
                window.print();
            });
        }

        /*
         * Modal "Selesaikan Episode" (partials/discharge-modal.blade.php).
         *
         * Progressive enhancement. Gate JavaScript di sini HANYA mengaktifkan
         * tombol submit dan mengisi action form - itu kenyamanan, bukan
         * penjaga. Kata "SELESAI" divalidasi ulang di server
         * (DischargeController::guardConfirmation), jadi permintaan yang dibuat
         * tanpa JavaScript, atau dengan kolom konfirmasi dikosongkan, tetap
         * ditolak.
         *
         * Blade menulis action form sebagai placeholder; di sini action itu
         * diisi dari data-encounter tombol pemicu, karena rute memakai
         * encounter_id dan setiap kartu punya kode yang berbeda.
         */
        var dischargeModal = document.getElementById('dischargeModal');

        if (dischargeModal) {
            var dischargeForm = dischargeModal.querySelector('[data-purpose="discharge-form"]');

            /*
             * Template rute dibuat pada blok PHP di atas tag script ini.
             */
            var dischargeTemplate = @json($dischargeTemplate);

            var dischargeName = dischargeModal.querySelector('[data-purpose="discharge-patient-name"]');
            var dischargeConfirm = document.getElementById('dischargeConfirmation');
            var dischargeSubmit = dischargeModal.querySelector('[data-purpose="discharge-submit"]');
            var dischargeHidden = dischargeForm.querySelector('input[name="encounter"]');
            var confirmationWord = @json(\App\Services\DischargeService::CONFIRMATION_WORD);

            var openModal = function (button) {
                var encounter = button.getAttribute('data-encounter');
                var name = button.getAttribute('data-name') || '';

                if (! encounter || ! dischargeTemplate) {
                    return;
                }

                dischargeHidden.value = encounter;
                dischargeForm.action = dischargeTemplate.replace('__ENCOUNTER__', encodeURIComponent(encounter));
                dischargeName.textContent = name;

                // Nama pasien ikut diletakkan di title supaya petugas bisa
                // memastikan menutup episode yang benar sebelum mengetik.
                dischargeModal.querySelector('[data-purpose="discharge-confirm-field"] input').placeholder = confirmationWord;

                dischargeModal.classList.remove('hidden');
                dischargeModal.classList.add('flex');

                // Kunci fokus ke isian pertama supaya keyboard tidak menggulir
                // ke belakang halaman di belakang modal.
                var firstField = document.getElementById('dischargeDate');
                if (firstField) {
                    firstField.focus();
                }
            };

            var closeModal = function () {
                dischargeModal.classList.add('hidden');
                dischargeModal.classList.remove('flex');
            };

            // Delegasi di document (bukan bind per tombol) supaya tombol pada
            // kartu hasil pencarian AJAX ikut berfungsi: innerHTML #censusRegion
            // diganti tiap saring, dan tombol baru tidak akan punya listener
            // bila di-bind sekali saat load.
            document.addEventListener('click', function (event) {
                var button = event.target.closest('[data-purpose="discharge-open"]');

                if (button) {
                    openModal(button);
                }
            });

            dischargeModal.querySelectorAll('[data-purpose="discharge-close"]').forEach(function (button) {
                button.addEventListener('click', closeModal);
            });

            // Klik area gelap di luar panel menutup modal, seperti dialog
            // bawaan browser.
            dischargeModal.addEventListener('click', function (event) {
                if (event.target === dischargeModal) {
                    closeModal();
                }
            });

            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape' && ! dischargeModal.classList.contains('hidden')) {
                    closeModal();
                }
            });

            // Tombol submit TIDAK aktif sebelum konfirmasi tepat. Ini yang
            // membuat "SELESAI" terasa lebih dari sekadar teks.
            var syncSubmit = function () {
                var typed = dischargeConfirm.value.replace(/\s+/g, '').toUpperCase();
                var matches = typed === confirmationWord;

                dischargeSubmit.disabled = ! matches;
                dischargeSubmit.classList.toggle('opacity-50', ! matches);
            };

            dischargeConfirm.addEventListener('input', syncSubmit);
            syncSubmit();
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
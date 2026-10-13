{{--
    Isi hasil census /pasien: kartu-kartu pasien ATAU status kosong + paginasi.

    Dipakai dua tempat dengan markup yang SAMA PERSIS (satu sumber kebenaran):
      - resources/views/pasien/index.blade.php (full page load awal)
      - respons JSON PasienController@index untuk pencarian AJAX
        (client menukar innerHTML #censusRegion tanpa reload)

    Jangan menambah markup hasil pencarian di JavaScript: kartu dirender
    server lewat partials/patient-card di bawah, jadi perubahan kartu otomatis
    ikut ke kedua jalur.

    Variabel: $patients (LengthAwarePaginator baris listPatients),
    $resetUrl (tautan Reset saat kosong), $canCloseEpisode (ditampilkan hanya
    tombol "Selesaikan Episode" untuk dokter - diteruskan ke patient-card
    supaya tombolnya tidak hilang setelah pencarian AJAX).
--}}
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
            @include('partials.patient-card', ['p' => $patient, 'canCloseEpisode' => $canCloseEpisode ?? false])
        @endforeach
    </section>

    {{-- Paginasi (view bawaan Tailwind Laravel) --}}
    <nav class="no-print" aria-label="Navigasi halaman" data-purpose="patient-pagination">
        {{ $patients->onEachSide(1)->links() }}
    </nav>
@endif

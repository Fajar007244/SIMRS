{{--
    Panel 4 "Diagnosa" dari modal Tambah Pasien.

    Port markup phase1/pasien.html baris 200-206.

    Perilaku baris mengikuti prototype: baris menumpuk, satu baris "Utama"
    di-seed saat modal dibuka (atau saat baris terakhir dihapus), tombol
    "Tambah Diagnosa" menambah baris "Penyerta", dan baris hanya ikut terkirim
    bila nama diagnosanya tidak kosong.

    Karena baris dirender server dengan indeks nyata, form tetap punya satu
    diagnosa yang sah walau JavaScript tidak pernah berjalan. Baris tambahan
    diambil dari <template> di bawah file ini.
--}}
@php
    /*
     * Baris hasil old() bila validasi gagal, kalau tidak satu baris kosong
     * dengan jenis "utama" - persis addDiagnosaRow('utama', '', '') di
     * patients.html.
     */
    $diagnosisRows = is_array($oldDiagnoses) && $oldDiagnoses !== []
        ? $oldDiagnoses
        : [[]];
@endphp
<section data-panel="diagnosa" class="panel {{ $initialTab === 3 ? '' : 'hidden' }}">
    <div class="mb-3 flex items-center justify-between">
        <p class="text-xs text-slate-500">Minimal 1 diagnosa (Utama wajib).</p>
        <button id="addDiagnosaBtn" type="button" class="inline-flex items-center gap-1.5 rounded-lg bg-teal-600 px-3 py-2 text-xs font-bold text-white transition hover:bg-teal-700"><i class="fa-solid fa-plus"></i>Tambah Diagnosa</button>
    </div>

    @error('diagnoses')
        <p class="mb-2 flex items-center gap-1.5 text-[11px] font-semibold text-red-600">
            <i class="fa-solid fa-circle-exclamation"></i> {{ $message }}
        </p>
    @enderror

    <div id="diagnosaRows" class="space-y-3">
        @foreach ($diagnosisRows as $rowIndex => $row)
            @include('partials.diagnosis-row', ['index' => $rowIndex, 'row' => is_array($row) ? $row : []])
        @endforeach
    </div>

    {{-- Cetakan baris tambahan; __INDEX__ diganti skrip dengan indeks berikutnya. --}}
    <template id="diagnosisRowTemplate">
        @include('partials.diagnosis-row', ['index' => '__INDEX__', 'row' => ['type' => 'penyerta']])
    </template>
</section>
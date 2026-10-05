{{--
    Satu baris diagnosa pada panel "Diagnosa".

    Dipakai dua kali:
      1. langsung di panel untuk baris yang dirender server (indeks nyata), dan
      2. di dalam <template> sebagai cetakan yang di-clone JavaScript untuk
         baris tambahan (indeks masih __INDEX__ dan diganti skrip).

    Atribut name memakai indeks supaya semua baris terkirim sebagai
    diagnoses[0], diagnoses[1], dst. Selektor kelas (.dx-type / .dx-text /
    .dx-code / .dx-remove) sengaja dipertahankan dari prototype karena
    collectDiagnoses() pada patients.html bergantung pada kelas itu.
--}}
<div data-row data-row-index="{{ $index }}"
     class="grid gap-2 sm:grid-cols-[130px_1fr_150px_40px] items-center">
    <select name="diagnoses[{{ $index }}][type]" data-indexed class="dx-type rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500" aria-label="Jenis diagnosa">
        <option value="utama" @selected(($row['type'] ?? 'utama') === 'utama')>Utama</option>
        <option value="penyerta" @selected(($row['type'] ?? '') === 'penyerta')>Penyerta</option>
    </select>
    <input type="text" name="diagnoses[{{ $index }}][text]" data-indexed value="{{ $row['text'] ?? '' }}" placeholder="Nama diagnosa" autocomplete="off" class="dx-text rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500" aria-label="Nama diagnosa">
    <input type="text" name="diagnoses[{{ $index }}][code]" data-indexed value="{{ $row['code'] ?? '' }}" placeholder="ICD-10" autocomplete="off" class="dx-code rounded-lg border-slate-300 font-mono text-sm focus:border-teal-500 focus:ring-teal-500" aria-label="Kode ICD-10">
    <button type="button" class="dx-remove flex h-9 w-9 items-center justify-center rounded-lg text-red-500 transition hover:bg-red-50" aria-label="Hapus diagnosa">
        <i class="fa-solid fa-trash-can"></i>
    </button>
</div>
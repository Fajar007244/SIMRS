{{--
    Satu baris prosedur pada panel "Procedure".

    Dipakai dua kali: baris yang dirender server (indeks nyata) dan cetakan
    <template> untuk baris tambahan (indeks __INDEX__).

    Klassen .pc-name / .pc-date / .pc-operator / .pc-code / .pc-remove
    mengikuti collectProcedures() pada patients.html.
--}}
<div data-row data-row-index="{{ $index }}"
     class="grid gap-2 sm:grid-cols-[1fr_140px_1fr_130px_40px] items-center">
    <input type="text" name="procedures[{{ $index }}][name]" data-indexed value="{{ $row['name'] ?? '' }}" placeholder="Nama tindakan" autocomplete="off" class="pc-name rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500" aria-label="Nama tindakan">
    <input type="date" name="procedures[{{ $index }}][performed_at]" data-indexed value="{{ $row['performed_at'] ?? '' }}" class="pc-date rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500" aria-label="Tanggal prosedur">
    <input type="text" name="procedures[{{ $index }}][operator]" data-indexed value="{{ $row['operator'] ?? '' }}" placeholder="Operator" autocomplete="off" class="pc-operator rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500" aria-label="Operator">
    <input type="text" name="procedures[{{ $index }}][code]" data-indexed value="{{ $row['code'] ?? '' }}" placeholder="ICD-9-CM" autocomplete="off" class="pc-code rounded-lg border-slate-300 font-mono text-sm focus:border-teal-500 focus:ring-teal-500" aria-label="Kode ICD-9-CM">
    <button type="button" class="pc-remove flex h-9 w-9 items-center justify-center rounded-lg text-red-500 transition hover:bg-red-50" aria-label="Hapus prosedur">
        <i class="fa-solid fa-trash-can"></i>
    </button>
</div>
{{--
    Panel 5 "Procedure" dari modal Tambah Pasien.

    Port markup phase1/pasien.html baris 208-214.

    Berbeda dari diagnosa, panel ini mulai dari nol baris: tombol "Tambah
    Prosedur" satu-satunya cara menambah baris, dan menghapus sampai kosong
    diperbolehkan. Baris hanya ikut terkirim bila nama tindakannya tidak kosong.
--}}
@php
    $procedureRows = is_array(old('procedures')) && old('procedures') !== [] ? old('procedures') : [];
@endphp
<section data-panel="procedure" class="panel {{ $initialTab === 4 ? '' : 'hidden' }}">
    <div class="mb-3 flex items-center justify-between">
        <p class="text-xs text-slate-500">Prosedur / tindakan yang dilakukan pada admisi ini.</p>
        <button id="addProcedureBtn" type="button" class="inline-flex items-center gap-1.5 rounded-lg bg-teal-600 px-3 py-2 text-xs font-bold text-white transition hover:bg-teal-700"><i class="fa-solid fa-plus"></i>Tambah Prosedur</button>
    </div>

    <div id="procedureRows" class="space-y-3">
        @foreach ($procedureRows as $rowIndex => $row)
            @include('partials.procedure-row', ['index' => $rowIndex, 'row' => is_array($row) ? $row : []])
        @endforeach
    </div>

    <template id="procedureRowTemplate">
        @include('partials.procedure-row', ['index' => '__INDEX__', 'row' => []])
    </template>
</section>
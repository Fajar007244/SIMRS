{{--
    Panel 3 "Asuhan Keperawatan / Bidan" dari modal Tambah Pasien.

    Port markup phase1/pasien.html baris 178-199, termasuk tiga pilihan shift
    yang tepat (Pagi / Siang / Malam) dan nilai bawaan Pagi.
--}}
<section data-panel="keperawatan" class="panel {{ $initialTab === 2 ? '' : 'hidden' }}">
    <div class="grid gap-4 sm:grid-cols-2">
        <label class="block text-xs font-bold text-slate-600 sm:col-span-2">Pengkajian
            <textarea id="fAssessment" name="nursing_care[assessment]" rows="2" class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500">{{ old('nursing_care.assessment') }}</textarea>
        </label>

        <label class="block text-xs font-bold text-slate-600 sm:col-span-2">Masalah / diagnosa keperawatan
            <textarea id="fProblems" name="nursing_care[problems]" rows="2" class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500">{{ old('nursing_care.problems') }}</textarea>
        </label>

        <label class="block text-xs font-bold text-slate-600 sm:col-span-2">Intervensi / rencana
            <textarea id="fInterventions" name="nursing_care[interventions]" rows="2" class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500">{{ old('nursing_care.interventions') }}</textarea>
        </label>

        <label class="block text-xs font-bold text-slate-600">Perawat / bidan
            <input id="fNurse" name="nursing_care[nurse]" type="text" value="{{ old('nursing_care.nurse') }}" autocomplete="off"
                   class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500">
        </label>

        <label class="block text-xs font-bold text-slate-600">Shift
            <select id="fShift" name="nursing_care[shift]" class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                @foreach (['Pagi', 'Siang', 'Malam'] as $shift)
                    <option value="{{ $shift }}" @selected(old('nursing_care.shift', 'Pagi') === $shift)>{{ $shift }}</option>
                @endforeach
            </select>
        </label>
    </div>
</section>
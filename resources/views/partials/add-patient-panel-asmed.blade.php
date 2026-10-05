{{--
    Panel 2 "ASMED" dari modal Tambah Pasien.

    Port markup phase1/pasien.html baris 148-176.

    Lima vital disimpan sebagai input terpisah (pola yang sama dengan ASMED di
    form prototype), lalu digabung jadi satu string "TD / HR / RR / Suhu /
    SpO2" di AdmissionService::writeAsmed() - persis hasil
    filter(Boolean).join(' / ') pada buildPayload() prototype. Server yang
    menggabungkan supaya nilai yang tersimpan bisa diverifikasi dan tidak
    bergantung JavaScript.
--}}
<section data-panel="asmed" class="panel {{ $initialTab === 1 ? '' : 'hidden' }}">
    <div class="grid gap-4 sm:grid-cols-2">
        <label class="block text-xs font-bold text-slate-600 sm:col-span-2">Keluhan utama
            <textarea id="fComplaint" name="asmed[complaint]" rows="2" class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500">{{ old('asmed.complaint') }}</textarea>
        </label>

        <label class="block text-xs font-bold text-slate-600 sm:col-span-2">Riwayat penyakit
            <textarea id="fHistory" name="asmed[history]" rows="2" class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500">{{ old('asmed.history') }}</textarea>
        </label>

        <label class="block text-xs font-bold text-slate-600 sm:col-span-2">Pemeriksaan fisik
            <textarea id="fPhysical" name="asmed[physical_exam]" rows="2" class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500">{{ old('asmed.physical_exam') }}</textarea>
        </label>

        <div class="sm:col-span-2">
            <span class="block text-xs font-bold text-slate-600">Vital awal (TD / HR / RR / Suhu / SpO2)</span>
            <div class="mt-1 grid grid-cols-2 gap-2 sm:grid-cols-5">
                <input id="fVitalsTD" name="asmed[vitals_td]" type="text" value="{{ old('asmed.vitals_td') }}" placeholder="TD" class="rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                <input id="fVitalsHR" name="asmed[vitals_hr]" type="text" value="{{ old('asmed.vitals_hr') }}" placeholder="HR" class="rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                <input id="fVitalsRR" name="asmed[vitals_rr]" type="text" value="{{ old('asmed.vitals_rr') }}" placeholder="RR" class="rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                <input id="fVitalsSuhu" name="asmed[vitals_suhu]" type="text" value="{{ old('asmed.vitals_suhu') }}" placeholder="Suhu" class="rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                <input id="fVitalsSpO2" name="asmed[vitals_spo2]" type="text" value="{{ old('asmed.vitals_spo2') }}" placeholder="SpO2" class="rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500">
            </div>
        </div>

        <label class="block text-xs font-bold text-slate-600 sm:col-span-2">Rencana / instruksi medis
            <textarea id="fPlan" name="asmed[plan]" rows="2" class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500">{{ old('asmed.plan') }}</textarea>
        </label>

        <label class="block text-xs font-bold text-slate-600 sm:col-span-2">Dokter pemeriksa
            <input id="fExaminer" name="asmed[examiner]" type="text" value="{{ old('asmed.examiner') }}" autocomplete="off"
                   class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500">
        </label>
    </div>
</section>
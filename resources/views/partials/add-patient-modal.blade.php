{{--
    Modal "Tambah Pasien" - port dari blok #addPatientModal di
    phase1/pasien.html (baris 87-226).

    Perbedaan yang disengaja dari prototype, semuanya konsekuensi pemindahan
    dari penyimpanan localStorage ke form HTML sungguhan:

      1. Seluruh markup memakai satu <form method="POST" action="pasien.store">
         berisi @csrf, bukan pengoleksi objek di memori peramban. Baris
         diagnosa dan prosedur yang dirender server adalah baris NYATA dengan
         atribut name, sedangkan baris tambahan diambil dari <template> di
         bawah dan di-clone JavaScript.
      2. Dialog alert / confirm bawaan peramban digantikan banner galat in-app
         (admissionErrorBanner) dan kotak konfirmasi in-app (duplicateConfirm).
         Kalimatnya sama persis dengan prototype.
      3. Kalimat "Lengkapi data wajib: ..." yang jadi alert prototype dipakai
         lagi sebagai pesan validasi diagnoses.required / diagnoses.min di
         PasienController, jadi permintaan tanpa JavaScript melihat teks yang
         sama.
      4. Gate "role:dokter" hanya ditegakkan di server (routes/pages.pasien.php);
         tombol pemicunya disembunyikan di index.blade.php untuk peran lain
         supaya tidak ada jalan buntu, tetapi penolakan 403 tetap jadi safeguard
         kalau markup dirender ulang atau permintaan dibuat langsung.

    Variabel (dari PasienController@index):
      $canCreateAdmission  bool  pengguna boleh membuat admisi (dokter)
      $knownPatients       array<int, string>  No. RM yang sudah terdaftar
      $knownPatientNames   array<string, string>  peta No. RM => nama
      $duplicateMrn        string  No. RM hasil old() (untuk pesan konfirmasi)
--}}
@php
    $duplicateKnown = $duplicateMrn !== '' && array_key_exists($duplicateMrn, $knownPatientNames);
    $duplicateMessage = $duplicateKnown
        ? \App\Http\Controllers\PasienController::duplicateMrnMessage($duplicateMrn, $knownPatientNames[$duplicateMrn])
        : null;

    /*
     * Modal hanya perlu dibuka ulang kalau server yang menolaknya (validasi
     * gagal atau No. RM ganda tanpa konfirmasi). active_tab menyimpan tab
     * terakhir yang dilihat supaya isian yang diketik tidak hilang makanan
     * akibat redirect.
     */
    $oldDiagnoses = old('diagnoses');
    $hasOldDiagnosis = is_array($oldDiagnoses) && $oldDiagnoses !== [];
    $openOnLoad = $errors->any() && old('admission_form') === '1';
    $initialTab = (int) old('active_tab', $hasOldDiagnosis ? 0 : 3);
    $initialTab = max(0, min(4, $initialTab));
@endphp

<form id="addPatientForm" method="POST" action="{{ route('pasien.store') }}" class="contents" data-purpose="add-patient-form">
    @csrf
    <input type="hidden" name="admission_form" value="1">
    <input type="hidden" name="active_tab" id="activeTabInput" value="{{ old('active_tab', (string) $initialTab) }}">
    <input type="hidden" name="confirm_duplicate" id="confirmDuplicateInput" value="{{ old('confirm_duplicate', '') }}">

    <div id="addPatientModal" class="fixed inset-0 z-50 hidden items-center justify-center p-4" role="dialog" aria-modal="true" aria-labelledby="addPatientModalTitle" data-open-on-load="{{ $openOnLoad ? '1' : '0' }}">
        <div class="absolute inset-0 bg-slate-900/70 backdrop-blur-sm" data-close-modal></div>
        <div class="relative z-10 flex max-h-[92vh] w-full max-w-3xl flex-col overflow-hidden rounded-2xl bg-white shadow-2xl">

            <div class="flex items-center justify-between border-b border-slate-200 bg-slate-50 px-5 py-4">
                <h2 id="addPatientModalTitle" class="text-lg font-black text-slate-900"><i class="fa-solid fa-user-plus mr-2 text-emerald-600"></i>Tambah Pasien</h2>
                <button type="button" data-close-modal aria-label="Tutup"
                        class="flex h-9 w-9 items-center justify-center rounded-lg text-slate-500 transition hover:bg-slate-200 hover:text-slate-700"><i class="fa-solid fa-xmark"></i></button>
            </div>

            <div class="border-b border-slate-200 bg-teal-50/60 px-5 pt-2">
                <div id="addTabs" class="flex flex-wrap gap-1 text-xs font-bold" role="tablist">
                    @foreach ([['identitas', 'Identitas'], ['asmed', 'ASMED'], ['keperawatan', 'Asuhan Keperawatan / Bidan'], ['diagnosa', 'Diagnosa'], ['procedure', 'Procedure']] as $tabIndex => $tab)
                        <button type="button" role="tab" data-tab="{{ $tab[0] }}" data-tab-index="{{ $tabIndex }}"
                                aria-selected="{{ $tabIndex === $initialTab ? 'true' : 'false' }}"
                                class="rounded-t-lg px-3.5 py-2.5 transition hover:text-teal-700 {{ $tabIndex === $initialTab ? 'bg-white text-teal-800 shadow-sm' : 'text-slate-600' }}">{{ $tab[1] }}</button>
                    @endforeach
                </div>
            </div>

            <div class="flex-1 overflow-y-auto p-5">

                {{-- Banner galat in-app (pengganti dialog alert prototype) --}}
                <div id="admissionErrorBanner" role="alert" aria-live="polite"
                     class="mb-4 flex items-start gap-2 rounded-lg border border-red-300 bg-red-50 px-3 py-2.5 text-xs font-semibold text-red-700 {{ $errors->any() ? '' : 'hidden' }}"
                     data-purpose="admission-error">
                    <i class="fa-solid fa-circle-exclamation mt-0.5 shrink-0" aria-hidden="true"></i>
                    <span id="admissionErrorText" class="flex-1">
                        @if ($errors->any())
                            @foreach ($errors->all() as $message)
                                <span class="block">{{ $message }}</span>
                            @endforeach
                        @endif
                    </span>
                    <button type="button" data-close-error aria-label="Tutup pesan"
                            class="shrink-0 text-red-400 transition hover:text-red-700">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>

                @include('partials.add-patient-panel-identitas')

                @include('partials.add-patient-panel-asmed')

                @include('partials.add-patient-panel-keperawatan')

                @include('partials.add-patient-panel-diagnosa')

                @include('partials.add-patient-panel-procedure')
            </div>

            {{-- Konfirmasi in-app No. RM ganda (pengganti dialog confirm prototype) --}}
            <div id="duplicateConfirm" class="flex flex-wrap items-center justify-between gap-3 border-t border-amber-200 bg-amber-50 px-5 py-3 {{ $duplicateMessage === null ? 'hidden' : '' }}"
                 data-purpose="duplicate-confirm">
                <p id="duplicateConfirmText" class="flex items-center gap-2 text-xs font-bold text-amber-800">
                    <i class="fa-solid fa-triangle-exclamation shrink-0" aria-hidden="true"></i>
                    <span>{{ $duplicateMessage ?? '' }}</span>
                </p>
                <div class="flex items-center gap-2">
                    <button type="button" id="duplicateConfirmNo"
                            class="rounded-lg border border-amber-300 bg-white px-3 py-2 text-xs font-bold text-amber-800 transition hover:bg-amber-100">Batal</button>
                    <button type="button" id="duplicateConfirmYes"
                            class="inline-flex items-center gap-1.5 rounded-lg bg-amber-600 px-3 py-2 text-xs font-bold text-white transition hover:bg-amber-700">
                        <i class="fa-solid fa-check" aria-hidden="true"></i>Ya, Tambahkan Admisi
                    </button>
                </div>
            </div>

            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 bg-slate-50 px-5 py-4">
                <button id="cancelModalBtn" type="button"
                        class="rounded-lg border border-slate-300 bg-white px-4 py-2.5 text-sm font-bold text-slate-600 transition hover:bg-slate-100">Batal</button>
                <div class="flex items-center gap-2">
                    <button id="prevStepBtn" type="button"
                            class="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-4 py-2.5 text-sm font-bold text-slate-600 transition hover:bg-slate-100">&larr; Sebelumnya</button>
                    <button id="nextStepBtn" type="button"
                            class="inline-flex items-center gap-2 rounded-lg bg-slate-800 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-slate-900">Berikutnya &rarr;</button>
                    <button id="savePatientBtn" type="submit"
                            class="hidden items-center gap-2 rounded-lg bg-emerald-600 px-5 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-emerald-700"><i class="fa-solid fa-floppy-disk"></i>Simpan Pasien</button>
                </div>
            </div>
        </div>
    </div>
</form>

{{-- No. RM terdaftar, untuk deteksi duplikat di sisi klien (tanpa query tambahan) --}}
<script type="application/json" id="knownPatientsData">@json($knownPatients, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)</script>
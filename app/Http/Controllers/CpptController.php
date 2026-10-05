<?php

namespace App\Http\Controllers;

use App\Enums\DiagnosisType;
use App\Enums\NursingShift;
use App\Models\Encounter;
use App\Services\AdmissionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Halaman "Medis & CPPT" (nama route `cppt`).
 *
 * Port 1:1 dari phase1/cppt.html. Kelima tab pada prototype (ASMED, Asuhan
 * Keperawatan, Diagnosa, Prosedur, Timeline) sekarang punya jalur tulis
 * masing-masing; hanya panel Timeline yang dulu dibangun, karena empat tab lain
 * tidak punya payload di kontrak baca.
 *
 * PROPS Inertia (resources/js/Pages/Cppt.vue):
 *   encounterId  string  nilai kolom `encounters.encounter_id`
 *   banner       array   12 kunci AdmissionService::getBannerPayload()
 *   data         array   { stats, notes } dari AdmissionService::getCppt()
 *   completion   array   { asmed, nursingCare, diagnosis, procedure } bool
 *   activeTab    string  selalu 'cppt'
 *   panels       array   { asmed, nursingCare, diagnoses, procedures } dari
 *                         AdmissionService::getCpptPanels() - OPSIONAL, default
 *                         null supaya halaman versi lama yang tidak mengirim
 *                         prop ini tetap bisa dirender (tabnya tampil kosong).
 *
 * JALUR TULIS
 * Lima aksi store di bawah memanggil method tulis yang semuanya hidup di
 * AdmissionService (alasannya ada di blok komentar Jalur tulis - modul CPPT
 * pada file itu). Validasi ditulis di sini lewat $request->validate() dengan
 * kalimat Indonesia yang disalin dari gate JavaScript prototype, sehingga
 * kegagalan jadi ValidationException dan masuk ke error bag Inertia - bukan
 * dialog alert() dan bukan 500.
 *
 * Otorisasi mengikuti docs/AUTHORIZATION.md dan dipasang di
 * routes/pages.cppt.php: ASMED / diagnosa / prosedur / CPPT hanya dokter;
 * asuhan keperawatan terbuka untuk perawat, bidan, dan dokter.
 */
class CpptController extends Controller
{
    public function __construct(
        private readonly AdmissionService $admissions,
    ) {}

    /**
     * GET /encounters/{encounter}/cppt
     *
     * `{encounter}` di-bind ke kolom `encounters.encounter_id` (string bisnis,
     * mis. `enc-159853-icu-20260906`) oleh Route::bind() di routes/pages.cppt.php,
     * BUKAN ke primary key numerik. Numeric pk karena itu selalu 404.
     */
    public function show(Request $request, Encounter $encounter): Response
    {
        abort_unless(
            $encounter->exists,
            404,
            'Episode perawatan tidak ditemukan. Periksa kembali kode encounter pada alamat halaman.'
        );

        return Inertia::render('Cppt', [
            'encounterId' => (string) $encounter->encounter_id,
            'banner' => $this->admissions->getBannerPayload($encounter),
            'data' => $this->admissions->getCppt($encounter),
            'completion' => $this->completion($encounter),
            'activeTab' => 'cppt',
            'panels' => $this->admissions->getCpptPanels($encounter),
        ]);
    }

    /**
     * POST /encounters/{encounter}/cppt/note  - "cppt.note.store"
     *
     * Simpan satu catatan progres CPPT (S/O/A/P) dari modal "Tambah CPPT" di
     * tab Timeline. Nama, peran, dan spesialisasi penulis SELALU berasal dari
     * user yang sedang login - field itu tidak pernah ada di rules(), jadi
     * request yang memalsukannya tidak punya efek apa pun.
     *
     * `shift` opsional: kolomnya nullable (lihat migration
     * 2026_10_02_000001) dan hanya diisi bila petugas memilihnya.
     */
    public function storeNote(Request $request, Encounter $encounter): RedirectResponse
    {
        $data = $request->validate(self::noteRules(), self::noteMessages(), self::noteAttributes());

        $this->admissions->storeMedicalNote($encounter, $data, $request->user());

        return back()->with('success', 'Catatan CPPT tersimpan.');
    }

    /**
     * POST /encounters/{encounter}/cppt/asmed  - "cppt.asmed.store"
     *
     * ASMED unik per episode, jadi ini UPSERT: tombol "Simpan ASMED" pada
     * episode yang sudah punya ASMED menyunting baris itu, bukan menambah
     * yang kedua. Karena itu pesan sukses memakai kata "diperbarui" bila
     * episode ternyata sudah terisi.
     */
    public function storeAsmed(Request $request, Encounter $encounter): RedirectResponse
    {
        $data = $request->validate(self::asmedRules(), self::asmedMessages(), self::asmedAttributes());

        $existed = $encounter->asmed()->exists();

        $this->admissions->storeAsmed($encounter, $data, $request->user());

        return back()->with('success', $existed ? 'ASMED diperbarui.' : 'ASMED tersimpan.');
    }

    /**
     * POST /encounters/{encounter}/cppt/nursing  - "cppt.nursing.store"
     *
     * Tambah satu baris asuhan (bukan upsert - asuhan memang diinput ulang
     * tiap shift). Baris sebelumnya otomatis kehilangan flag `is_latest`.
     */
    public function storeNursingCare(Request $request, Encounter $encounter): RedirectResponse
    {
        $data = $request->validate(self::nursingRules(), self::nursingMessages(), self::nursingAttributes());

        $this->admissions->storeNursingCare($encounter, $data, $request->user());

        return back()->with('success', 'Asuhan keperawatan tersimpan.');
    }

    /**
     * POST /encounters/{encounter}/cppt/diagnosis  - "cppt.diagnosis.store"
     *
     * Satu baris diagnosis per permintaan. `text` wajib diisi dengan kalimat
     * yang sama persis dengan gate prototype di cppt.html (submit diagForm):
     * "Nama diagnosa wajib diisi.".
     */
    public function storeDiagnosis(Request $request, Encounter $encounter): RedirectResponse
    {
        $data = $request->validate(self::diagnosisRules(), self::diagnosisMessages(), self::diagnosisAttributes());

        $this->admissions->storeDiagnosis($encounter, $data, $request->user());

        return back()->with('success', 'Diagnosa ditambahkan.');
    }

    /**
     * POST /encounters/{encounter}/cppt/procedure  - "cppt.procedure.store"
     *
     * Satu baris prosedur per permintaan. `name` wajib diisi dengan kalimat
     * yang sama persis dengan gate prototype (submit procForm):
     * "Nama tindakan wajib diisi.".
     */
    public function storeProcedure(Request $request, Encounter $encounter): RedirectResponse
    {
        $data = $request->validate(self::procedureRules(), self::procedureMessages(), self::procedureAttributes());

        $this->admissions->storeProcedure($encounter, $data);

        return back()->with('success', 'Prosedur ditambahkan.');
    }

    /**
     * @return array<string, mixed>
     */
    private static function noteRules(): array
    {
        return [
            'noted_at' => ['required', 'date'],
            'shift' => ['nullable', Rule::enum(NursingShift::class)],
            'note_type' => ['nullable', 'string', 'max:64'],
            'subjective' => ['nullable', 'string', 'max:5000'],
            'objective' => ['nullable', 'string', 'max:5000'],
            'assessment' => ['nullable', 'string', 'max:5000'],
            'plan' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function noteMessages(): array
    {
        return [
            'noted_at.required' => 'Tanggal & waktu CPPT wajib diisi.',
            'noted_at.date' => 'Tanggal & waktu CPPT tidak valid.',
            'shift.enum' => 'Shift harus Pagi, Siang, Sore, atau Malam.',
            'note_type.max' => 'Jenis catatan maksimal 64 karakter.',
            'subjective.max' => 'Subjektif maksimal 5000 karakter.',
            'objective.max' => 'Objektif maksimal 5000 karakter.',
            'assessment.max' => 'Assessment maksimal 5000 karakter.',
            'plan.max' => 'Plan maksimal 5000 karakter.',
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function noteAttributes(): array
    {
        return [
            'noted_at' => 'tanggal & waktu CPPT',
            'shift' => 'shift',
            'note_type' => 'jenis catatan',
            'subjective' => 'subjektif',
            'objective' => 'objektif',
            'assessment' => 'assessment',
            'plan' => 'plan',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function asmedRules(): array
    {
        return [
            'complaint' => ['nullable', 'string', 'max:5000'],
            'examiner' => ['nullable', 'string', 'max:255'],
            'history' => ['nullable', 'string', 'max:5000'],
            'physical_exam' => ['nullable', 'string', 'max:5000'],
            'vitals' => ['nullable', 'string', 'max:2000'],
            'plan' => ['nullable', 'string', 'max:20000'],
            'examined_at' => ['nullable', 'date'],
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function asmedMessages(): array
    {
        return [
            'complaint.max' => 'Keluhan utama maksimal 5000 karakter.',
            'examiner.max' => 'Nama pemeriksa maksimal 255 karakter.',
            'history.max' => 'Riwayat pendamping maksimal 5000 karakter.',
            'physical_exam.max' => 'Pemeriksaan fisik maksimal 5000 karakter.',
            'vitals.max' => 'Tanda vital maksimal 2000 karakter.',
            'plan.max' => 'Rencana tatalaksana maksimal 20000 karakter.',
            'examined_at.date' => 'Tanggal & waktu pemeriksaan tidak valid.',
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function asmedAttributes(): array
    {
        return [
            'complaint' => 'keluhan utama',
            'examiner' => 'nama pemeriksa',
            'history' => 'riwayat pendamping',
            'physical_exam' => 'pemeriksaan fisik',
            'vitals' => 'tanda vital',
            'plan' => 'rencana tatalaksana',
            'examined_at' => 'tanggal & waktu pemeriksaan',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function nursingRules(): array
    {
        return [
            'assessment' => ['nullable', 'string', 'max:5000'],
            'problems' => ['nullable', 'string', 'max:5000'],
            'interventions' => ['nullable', 'string', 'max:5000'],
            'nurse' => ['nullable', 'string', 'max:255'],
            'shift' => ['nullable', Rule::enum(NursingShift::class)],
            'recorded_at' => ['nullable', 'date'],
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function nursingMessages(): array
    {
        return [
            'assessment.max' => 'Assessment maksimal 5000 karakter.',
            'problems.max' => 'Diagnosa keperawatan maksimal 5000 karakter.',
            'interventions.max' => 'Intervensi maksimal 5000 karakter.',
            'nurse.max' => 'Nama perawat / bidan maksimal 255 karakter.',
            'shift.enum' => 'Shift harus Pagi, Siang, Sore, atau Malam.',
            'recorded_at.date' => 'Tanggal & waktu pencatatan tidak valid.',
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function nursingAttributes(): array
    {
        return [
            'assessment' => 'assessment',
            'problems' => 'diagnosa keperawatan',
            'interventions' => 'intervensi',
            'nurse' => 'nama perawat / bidan',
            'shift' => 'shift',
            'recorded_at' => 'tanggal & waktu pencatatan',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function diagnosisRules(): array
    {
        return [
            'type' => ['required', 'string', Rule::in(array_column(DiagnosisType::cases(), 'value'))],
            'text' => ['required', 'string', 'max:500'],
            'code' => ['nullable', 'string', 'max:32'],
        ];
    }

    /**
     * `text.required` disalin PERSIS dari gate prototype di cppt.html
     * (submit diagForm) supaya pesan yang tampil sama di kedua lapisan.
     *
     * @return array<string, string>
     */
    private static function diagnosisMessages(): array
    {
        return [
            'type.required' => 'Jenis diagnosa wajib diisi.',
            'type.in' => 'Jenis diagnosa harus Utama atau Penyerta.',
            'text.required' => 'Nama diagnosa wajib diisi.',
            'text.max' => 'Nama diagnosa maksimal 500 karakter.',
            'code.max' => 'Kode ICD-10 maksimal 32 karakter.',
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function diagnosisAttributes(): array
    {
        return [
            'type' => 'jenis diagnosa',
            'text' => 'nama diagnosa',
            'code' => 'kode ICD-10',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function procedureRules(): array
    {
        return [
            'performed_at' => ['required', 'date'],
            'name' => ['required', 'string', 'max:255'],
            'operator' => ['nullable', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:32'],
        ];
    }

    /**
     * `name.required` disalin PERSIS dari gate prototype di cppt.html
     * (submit procForm) supaya pesan yang tampil sama di kedua lapisan.
     *
     * @return array<string, string>
     */
    private static function procedureMessages(): array
    {
        return [
            'performed_at.required' => 'Tanggal tindakan wajib diisi.',
            'performed_at.date' => 'Tanggal tindakan tidak valid.',
            'name.required' => 'Nama tindakan wajib diisi.',
            'name.max' => 'Nama tindakan maksimal 255 karakter.',
            'operator.max' => 'Pelaksana maksimal 255 karakter.',
            'code.max' => 'Kode ICD-9-CM maksimal 32 karakter.',
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function procedureAttributes(): array
    {
        return [
            'performed_at' => 'tanggal tindakan',
            'name' => 'nama tindakan',
            'operator' => 'pelaksana',
            'code' => 'kode ICD-9-CM',
        ];
    }

    /**
     * Empat boolean kelengkapan dokumentasi: asmed, asuhan keperawatan,
     * diagnosis, prosedur.
     *
     * Sumbernya adalah `AdmissionService::getProfile()['completion']`. Method
     * itu memanggil getProfile() penuh - flowsheet, daftar device, jumlah
     * obat, careTeam - padahal halaman ini hanya butuh empat boolean tersebut.
     * `$encounter->completion` adalah accessor yang DIJALANKAN OLEH
     * getProfile() untuk menghasilkan key yang sama persis; karena
     * getCppt() dan getBannerPayload() sudah me-load `asmed`, `nursingCares`,
     * dan `diagnoses`, accessor itu hanya menambah satu kueri (procedures).
     * Nilai keluarannya identik.
     *
     * @return array{asmed: bool, nursingCare: bool, diagnosis: bool, procedure: bool}
     */
    private function completion(Encounter $encounter): array
    {
        $completion = $encounter->completion;

        return [
            'asmed' => (bool) ($completion['asmed'] ?? false),
            'nursingCare' => (bool) ($completion['nursingCare'] ?? false),
            'diagnosis' => (bool) ($completion['diagnosis'] ?? false),
            'procedure' => (bool) ($completion['procedure'] ?? false),
        ];
    }
}

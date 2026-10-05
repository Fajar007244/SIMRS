<?php

namespace App\Http\Controllers;

use App\Enums\DiagnosisType;
use App\Enums\PaymentType;
use App\Enums\Sex;
use App\Models\Patient;
use App\Services\AdmissionService;
use App\Services\Support\ClinicalFormat;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Daftar pasien (sensus ICU) - SERVER-RENDERED Blade, bukan bagian dari SPA.
 *
 * Full page load ke /encounters/{encounter}/profil dari tiap kartu memang
 * disengaja oleh pemilik produk: halaman ini adalah titik masuk ke modul
 * klinis. Keuntungannya census tetap hidup walau bundel JS gagal dimuat, dan
 * bisa dicetak apa adanya.
 *
 * Rute: routes/pages.pasien.php - GET "pasien" (middleware `auth`) dan
 * POST "pasien.store" (middleware `auth` + `role:dokter`).
 *
 * OTORISASI
 * Membaca daftar pasien tetap terbuka untuk perawat, bidan, dan dokter
 * (semua baris "Ya" di docs/AUTHORIZATION.md), sehingga GET /pasien tidak
 * diberi `role` sama sekali - akun demo `perawat` tetap bisa membuka halaman
 * tanpa 403. Hanya PENULISAN yang dibatasi ke dokter, karena membuat admisi
 * berarti menulis ASMED, diagnosa, dan prosedur sekaligus, dan ketiganya
 * hanya boleh diisi dokter pada matriks tersebut. Tombol "Tambah Pasien" juga
 * disembunyikan untuk peran lain supaya tidak ada jalan buntu di UI.
 *
 * MODAL TAMBAH PASIEN
 * Halaman ini juga menyajikan modal lima tab yang diport dari
 * phase1/pasien.html (identitas, ASMED, keperawatan, diagnosa, procedure).
 * Baris diagnosa dan prosedur ditambahkan lewat <template> HTML yang di-clone
 * JavaScript, dan form-nya POST sungguhan ke "pasien.store" sehingga validasi
 * server tetap menjadi sumber kebenaran. Percabangan No. RM ganda (pasien
 * baru vs admisi baru pada pasien lama) diputuskan server; lihat store().
 *
 * PAGINASI
 * AdmissionService::listPatients() mengembalikan array (bukan paginator) dan
 * sudah diurutkan di server menurut risiko -> EWS -> observasi terbaru. Karena
 * itu paginasi diterapkan di memory SETELAH service mengembalikan barisnya:
 * urutan prioritas risiko tetap terjaga antar halaman, dan filter diteruskan
 * lewat withQueryString(). Census produksi bisa besar, jadi perPage dibatasi.
 */
class PasienController extends Controller
{
    /**
     * Jumlah baris per halaman. 12 memberi 4 baris pada grid 3 kolom.
     */
    private const PER_PAGE = 12;

    /**
     * Kalimat yang sama persis dengan gate JavaScript di prototype
     * (patients.html savePatient()). Dipakai untuk diagnoses.required dan
     * diagnoses.min supaya permintaan tanpa JavaScript melihat pesan yang sama.
     */
    public const REQUIRED_FIELDS_MESSAGE = 'Lengkapi data wajib: nama, No. RM, unit, bed, tanggal & jam masuk, dan minimal 1 diagnosa.';

    /**
     * Format konfirmasi No. RM ganda, sama dengan dialog confirm yang
     * diprototipekan (diganti jadi konfirmasi in-app, bukan dialog browser).
     */
    public static function duplicateMrnMessage(string $mrn, string $name): string
    {
        return 'No. RM '.$mrn.' sudah terdaftar untuk '.$name.'. Tambahkan sebagai admisi baru?';
    }

    public function __construct(private readonly AdmissionService $admission) {}

    /**
     * Halaman daftar pasien.
     *
     * @param  array<string, string>  $filters  search | unit | status
     */
    public function index(Request $request): View
    {
        $filters = $this->filters($request);

        /*
         * Satu panggilan untuk seluruh episode (tanpa filter pencarian/unit)
         * supaya statistik header dan daftar unit berasal dari sumber yang
         * sama: bentuk baris yang dikembalikan listPatients().
         */
        $census = $this->admission->listPatients(['status' => 'all']);

        $rows = $this->admission->listPatients($filters);

        $page = max(1, (int) $request->query('page', 1));

        $patients = new LengthAwarePaginator(
            array_slice($rows, ($page - 1) * self::PER_PAGE, self::PER_PAGE) ?: [],
            count($rows),
            self::PER_PAGE,
            $page,
            ['path' => $request->url()],
        );

        // Filter (search / unit / status) ikut terbawa saat pindah halaman.
        $patients->withQueryString();

        $knownPatients = $this->knownPatients($census);
        $duplicateMrn = trim((string) old('mrn'));

        return view('pasien.index', [
            'patients' => $patients,
            'units' => $this->units($census),
            'filters' => $filters,
            'stats' => $this->stats($census, count($rows)),
            'canCreateAdmission' => $request->user()?->isDoctor() ?? false,
            'knownPatients' => $knownPatients,
            'knownPatientNames' => $this->knownPatientNames($census),
            'duplicateMrn' => $duplicateMrn,
        ]);
    }

    /**
     * Normalisasi query string menjadi filter service.
     *
     * `status` default "aktif"; string kosong atau "all" berarti tanpa filter
     * status (lihat AdmissionService::listPatients()).
     *
     * @return array{search: string, unit: string, status: string}
     */
    private function filters(Request $request): array
    {
        $search = trim((string) $request->query('search', ''));
        $unit = trim((string) $request->query('unit', ''));
        $status = trim((string) $request->query('status', 'aktif'));

        if ($status === '' || mb_strtolower($status) === 'all') {
            return ['search' => $search, 'unit' => $unit, 'status' => 'all'];
        }

        return ['search' => $search, 'unit' => $unit, 'status' => $status];
    }

    /**
     * Daftar unit pelayanan unik, diurutkan natural (case-insensitive).
     *
     * @param  array<int, array<string, mixed>>  $census
     * @return array<int, string>
     */
    private function units(array $census): array
    {
        $units = [];

        foreach ($census as $row) {
            $unit = (string) ($row['unit'] ?? '');

            if ($unit !== '' && $unit !== '-' && ! in_array($unit, $units, true)) {
                $units[] = $unit;
            }
        }

        sort($units, SORT_NATURAL | SORT_FLAG_CASE);

        return $units;
    }

    /**
     * Ringkasan untuk baris kartu statistik.
     *
     * - total    = seluruh episode, berapa pun statusnya
     * - active   = episode berstatus "aktif"
     * - atRisk   = episode dengan risiko EWS terakhir high / emergency
     * - matching = episode yang cocok dengan filter sekarang
     *
     * @param  array<int, array<string, mixed>>  $census
     * @return array{total: int, active: int, atRisk: int, matching: int}
     */
    private function stats(array $census, int $matching): array
    {
        $active = 0;
        $atRisk = 0;

        foreach ($census as $row) {
            if (($row['status'] ?? null) === 'aktif') {
                $active++;
            }

            if (($row['atRisk'] ?? false) === true) {
                $atRisk++;
            }
        }

        return [
            'total' => count($census),
            'active' => $active,
            'atRisk' => $atRisk,
            'matching' => $matching,
        ];
    }

    /**
     * Simpan admisi baru dari modal "Tambah Pasien".
     *
     * Rute: POST /pasien, name "pasien.store", middleware `auth` + `role:dokter`
     * (lihat routes/pages.pasien.php dan docs/AUTHORIZATION.md).
     *
     * Percabangan No. RM ganda SEPENUHNYA diputuskan di server. Form hanya
     * menampilkan konfirmasi in-app lalu mengirim confirm_duplicate=1; kalau
     * field itu tidak ada, permintaan tetap ditolak dengan pesan prototype,
     * jadi tidak ada jalur yang bisa melewati konfirmasi.
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $this->admissionPayload($request);

        $this->guardDuplicateMrn($data['mrn'], $request->boolean('confirm_duplicate'));

        $result = $this->admission->createAdmission($data, $request->user());

        $encounter = $result['encounter'];
        $unitBed = $encounter->unit.' / '.$encounter->bed;

        return redirect()
            ->route('profil', ['encounter' => $encounter->encounter_id])
            ->with('success', $result['isNewPatient']
                ? 'Pasien '.$result['patient']->name.' admisi di '.$unitBed.'.'
                : 'Admisi baru untuk '.$result['patient']->name.' di '.$unitBed.'.');
    }

    /**
     * Validasi payload modal, lalu bentuk data yang siap ditulis service.
     *
     * Kegagalan validasi dilempar sebagai ValidationException sehingga Laravel
     * mengembalikan 302 ke halaman sebelumnya lengkap dengan error bag dan
     * input lama (old()); halaman /pasien lalu membuka kembali modal dengan
     * isian yang sama.
     *
     * @return array<string, mixed>
     */
    private function admissionPayload(Request $request): array
    {
        $data = $request->validate(
            self::admissionRules(),
            self::admissionMessages(),
            self::admissionAttributes()
        );

        $data['mrn'] = trim((string) $data['mrn']);
        $data['name'] = trim((string) $data['name']);
        $data['unit'] = trim((string) $data['unit']);
        $data['bed'] = trim((string) $data['bed']);
        $data['admitted_at'] = $this->normalizeDateTime((string) $data['admitted_at']);

        $data['diagnoses'] = $this->filterRows($data['diagnoses'] ?? [], 'text');

        if (array_key_exists('procedures', $data) && is_array($data['procedures'])) {
            $data['procedures'] = $this->filterRows($data['procedures'], 'name');
        } else {
            $data['procedures'] = [];
        }

        return $data;
    }

    /**
     * Aturan validasi modal "Tambah Pasien".
     *
     * Pesan pada diagnoses.required / diagnoses.min sengaja memakai kalimat
     * yang sama persis dengan gate JavaScript di prototype:
     * "Lengkapi data wajib: nama, No. RM, unit, bed, tanggal & jam masuk, dan
     * minimal 1 diagnosa." - supaya permintaan tanpa JavaScript melihat pesan
     * yang sama, bukan kalimat generik Laravel.
     *
     * `confirm_duplicate` tidak divalidasi di sini; keberadaannya diperiksa
     * guardDuplicateMrn() yang juga tahu apakah No. RM-nya memang sudah
     * terdaftar.
     *
     * @return array<string, mixed>
     */
    private static function admissionRules(): array
    {
        return [
            'admission_form' => ['nullable'],
            'active_tab' => ['nullable', 'integer', 'min:0', 'max:4'],
            'confirm_duplicate' => ['nullable'],

            'name' => ['required', 'string', 'max:255'],
            'mrn' => ['required', 'string', 'max:255'],
            'sex' => ['nullable', 'string', Rule::in(array_column(Sex::cases(), 'value'))],
            'blood_type' => ['nullable', 'string', Rule::in(['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'])],
            'birth_date' => ['nullable', 'date'],
            'allergies' => ['nullable', 'string', 'max:2000'],
            'address' => ['nullable', 'string', 'max:500'],
            'payment' => ['nullable', 'string', Rule::in(array_column(PaymentType::cases(), 'value'))],
            'unit' => ['required', 'string', 'max:255'],
            'bed' => ['required', 'string', 'max:255'],
            'admitted_at' => ['required', 'date'],
            'attending_physician' => ['nullable', 'string', 'max:255'],

            'asmed' => ['nullable', 'array'],
            'asmed.complaint' => ['nullable', 'string', 'max:5000'],
            'asmed.history' => ['nullable', 'string', 'max:5000'],
            'asmed.physical_exam' => ['nullable', 'string', 'max:5000'],
            'asmed.vitals_td' => ['nullable', 'string', 'max:64'],
            'asmed.vitals_hr' => ['nullable', 'string', 'max:64'],
            'asmed.vitals_rr' => ['nullable', 'string', 'max:64'],
            'asmed.vitals_suhu' => ['nullable', 'string', 'max:64'],
            'asmed.vitals_spo2' => ['nullable', 'string', 'max:64'],
            'asmed.plan' => ['nullable', 'string', 'max:20000'],
            'asmed.examiner' => ['nullable', 'string', 'max:255'],

            'nursing_care' => ['nullable', 'array'],
            'nursing_care.assessment' => ['nullable', 'string', 'max:5000'],
            'nursing_care.problems' => ['nullable', 'string', 'max:5000'],
            'nursing_care.interventions' => ['nullable', 'string', 'max:5000'],
            'nursing_care.nurse' => ['nullable', 'string', 'max:255'],
            'nursing_care.shift' => ['nullable', 'string', Rule::in(['Pagi', 'Siang', 'Malam'])],

            'diagnoses' => ['required', 'array', 'min:1'],
            'diagnoses.*.type' => ['required', 'string', Rule::in(array_column(DiagnosisType::cases(), 'value'))],
            'diagnoses.*.text' => ['required', 'string', 'max:500'],
            'diagnoses.*.code' => ['nullable', 'string', 'max:32'],

            'procedures' => ['nullable', 'array'],
            'procedures.*.name' => ['nullable', 'string', 'max:255'],
            'procedures.*.performed_at' => ['nullable', 'date'],
            'procedures.*.operator' => ['nullable', 'string', 'max:255'],
            'procedures.*.code' => ['nullable', 'string', 'max:32'],
        ];
    }

    /**
     * Pesan validasi berbahasa Indonesia.
     *
     * @return array<string, string>
     */
    private static function admissionMessages(): array
    {
        $required = self::REQUIRED_FIELDS_MESSAGE;

        return [
            'admission_form.required' => $required,
            'name.required' => 'Nama lengkap wajib diisi.',
            'name.max' => 'Nama lengkap maksimal 255 karakter.',
            'mrn.required' => 'No. RM wajib diisi.',
            'mrn.max' => 'No. RM maksimal 255 karakter.',
            'sex.in' => 'Jenis kelamin harus Laki-Laki atau Perempuan.',
            'blood_type.in' => 'Golongan darah tidak valid.',
            'birth_date.date' => 'Tanggal lahir tidak valid.',
            'allergies.max' => 'Alergi maksimal 2000 karakter.',
            'address.max' => 'Alamat maksimal 500 karakter.',
            'payment.in' => 'Pembiayaan harus BPJS PBI, BPJS Non PBI, Asuransi, atau Umum.',
            'unit.required' => 'Unit/Ruang wajib diisi.',
            'unit.max' => 'Unit/Ruang maksimal 255 karakter.',
            'bed.required' => 'Bed wajib diisi.',
            'bed.max' => 'Bed maksimal 255 karakter.',
            'admitted_at.required' => 'Tanggal & jam masuk wajib diisi.',
            'admitted_at.date' => 'Tanggal & jam masuk tidak valid.',
            'attending_physician.max' => 'DPJP maksimal 255 karakter.',

            'asmed.complaint.max' => 'Keluhan utama maksimal 5000 karakter.',
            'asmed.history.max' => 'Riwayat penyakit maksimal 5000 karakter.',
            'asmed.physical_exam.max' => 'Pemeriksaan fisik maksimal 5000 karakter.',
            'asmed.plan.max' => 'Rencana / instruksi medis maksimal 20000 karakter.',
            'asmed.examiner.max' => 'Dokter pemeriksa maksimal 255 karakter.',

            'nursing_care.assessment.max' => 'Pengkajian maksimal 5000 karakter.',
            'nursing_care.problems.max' => 'Masalah / diagnosa keperawatan maksimal 5000 karakter.',
            'nursing_care.interventions.max' => 'Intervensi / rencana maksimal 5000 karakter.',
            'nursing_care.nurse.max' => 'Perawat / bidan maksimal 255 karakter.',
            'nursing_care.shift.in' => 'Shift harus Pagi, Siang, atau Malam.',

            'diagnoses.required' => $required,
            'diagnoses.min' => $required,
            'diagnoses.*.type.required' => 'Jenis diagnosa wajib diisi.',
            'diagnoses.*.type.in' => 'Jenis diagnosa harus Utama atau Penyerta.',
            'diagnoses.*.text.required' => 'Nama diagnosa wajib diisi.',
            'diagnoses.*.text.max' => 'Nama diagnosa maksimal 500 karakter.',
            'diagnoses.*.code.max' => 'Kode ICD-10 maksimal 32 karakter.',

            'procedures.*.performed_at.date' => 'Tanggal prosedur tidak valid.',
            'procedures.*.operator.max' => 'Operator maksimal 255 karakter.',
            'procedures.*.code.max' => 'Kode ICD-9-CM maksimal 32 karakter.',
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function admissionAttributes(): array
    {
        return [
            'name' => 'nama lengkap',
            'mrn' => 'No. RM',
            'sex' => 'jenis kelamin',
            'blood_type' => 'golongan darah',
            'birth_date' => 'tanggal lahir',
            'allergies' => 'alergi',
            'address' => 'alamat',
            'payment' => 'pembiayaan',
            'unit' => 'unit/ruang',
            'bed' => 'bed',
            'admitted_at' => 'tanggal & jam masuk',
            'attending_physician' => 'DPJP',
            'asmed.complaint' => 'keluhan utama',
            'asmed.history' => 'riwayat penyakit',
            'asmed.physical_exam' => 'pemeriksaan fisik',
            'asmed.plan' => 'rencana / instruksi medis',
            'asmed.examiner' => 'dokter pemeriksa',
            'nursing_care.assessment' => 'pengkajian',
            'nursing_care.problems' => 'masalah / diagnosa keperawatan',
            'nursing_care.interventions' => 'intervensi / rencana',
            'nursing_care.nurse' => 'perawat / bidan',
            'nursing_care.shift' => 'shift',
            'diagnoses' => 'diagnosa',
            'diagnoses.*.type' => 'jenis diagnosa',
            'diagnoses.*.text' => 'nama diagnosa',
            'diagnoses.*.code' => 'kode ICD-10',
            'procedures.*.name' => 'nama tindakan',
            'procedures.*.performed_at' => 'tanggal prosedur',
            'procedures.*.operator' => 'operator',
            'procedures.*.code' => 'kode ICD-9-CM',
        ];
    }

    /**
     * Tolak permintaan admisi pada No. RM yang sudah terdaftar bila
     * confirm_duplicate tidak dikirim.
     *
     * Ini penjaga server untuk Percabangan "No. RM sudah ada" di prototype:
     * konfirmasinya berupa tombol in-app di form, dan field confirm_duplicate
     * adalah bukti bahwa pengguna benar-benar menekan tombol itu. Permintaan
     * langsung (tanpa JavaScript, ataucrafted tangan) tetap ditolak.
     *
     * @throws ValidationException
     */
    private function guardDuplicateMrn(string $mrn, bool $confirmed): void
    {
        if ($confirmed) {
            return;
        }

        $existing = Patient::query()->where('mrn', $mrn)->first();

        if ($existing === null) {
            return;
        }

        throw ValidationException::withMessages([
            'mrn' => 'No. RM '.$mrn.' sudah terdaftar untuk '.$existing->name.'. Tambahkan sebagai admisi baru?',
        ]);
    }

    /**
     * Buang baris diagnosa / prosedur yang isian utamanya kosong.
     *
     * Prototype memakai collectDiagnoses() / collectProcedures(): baris tanpa
     * nama tidak ikut dikirim. Pemotongan yang sama diulang di server supaya
     * permintaan yang dibuat tanpa JavaScript tidak menyimpan baris kosong.
     *
     * Baris diurutkan ulang berdasarkan indeksnya sebelum disimpan. Browser
     * mengirim field sesuai urutan DOM, jadi urutannya sudah benar; ksort
     * membuat hasil sama walau klien mengirim field tidak berurutan, dan itu
     * penting karena urutan diagnosis menentukan diagnosis_summary dan baris
     * `utama` yang dibaca primaryDiagnosis().
     *
     * @param  array<int|string, mixed>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function filterRows(array $rows, string $key): array
    {
        ksort($rows);

        $kept = [];

        foreach ($rows as $row) {
            if (! is_array($row) || trim((string) ($row[$key] ?? '')) === '') {
                continue;
            }

            $kept[] = $row;
        }

        return $kept;
    }

    /**
     * <input type="datetime-local"> mengirim "Y-m-d\TH:i"; kolom timestamp
     * lebih mudah dibaca dengan spasi. Nilai dinormalkan supaya old() juga
     * mengembalikan format yang sah untuk input tersebut.
     */
    private function normalizeDateTime(string $value): string
    {
        return str_replace('T', ' ', trim($value));
    }

    /**
     * Daftar No. RM yang sudah terdaftar, untuk deteksi duplikat di sisi klien.
     *
     * Disusun dari census yang sudah diambil untuk kartu statistik, jadi tidak
     * ada query tambahan. Daftarnya hanya NO. RM - nama pasien dipakai server
     * saat menyusun pesan konfirmasi, bukan dikirim ke peramban.
     *
     * @param  array<int, array<string, mixed>>  $census
     * @return array<int, string>
     */
    private function knownPatients(array $census): array
    {
        $mrns = [];

        foreach ($census as $row) {
            $mrn = trim((string) ($row['mrn'] ?? ''));

            if ($mrn !== '' && $mrn !== ClinicalFormat::EMPTY) {
                $mrns[$mrn] = $mrn;
            }
        }

        return array_values($mrns);
    }

    /**
     * Peta No. RM => nama pasien terdaftar, dipakai server untuk menyusun pesan
     * konfirmasi duplikat di dalam modal.
     *
     * @param  array<int, array<string, mixed>>  $census
     * @return array<string, string>
     */
    private function knownPatientNames(array $census): array
    {
        $names = [];

        foreach ($census as $row) {
            $mrn = trim((string) ($row['mrn'] ?? ''));

            if ($mrn === '' || $mrn === ClinicalFormat::EMPTY) {
                continue;
            }

            $names[$mrn] = (string) ($row['name'] ?? '');
        }

        return $names;
    }
}

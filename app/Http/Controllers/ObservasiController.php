<?php

namespace App\Http\Controllers;

use App\Enums\ConsciousnessLevel;
use App\Enums\DeviceCode;
use App\Enums\MedicationCategory;
use App\Models\Encounter;
use App\Services\AdmissionService;
use App\Services\BundleService;
use App\Services\ObservationService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Halaman "Observasi EWS & Hemodinamik" (nama route `observasi`).
 *
 * Port 1:1 dari phase1/observasi.html, termasuk jalur tulisnya:
 *   saveObservation()   -> ObservationService::save()   (store)
 *   deleteObservation() -> ObservationService::delete() (destroy)
 *
 * PROPS Inertia (resources/js/Pages/Observasi.vue):
 *   encounterId      string  `encounters.encounter_id`,mis.
 *                             "enc-159853-icu-20260906" (BUKAN primary key)
 *   banner           array   12 kunci, AdmissionService::getBannerPayload()
 *   telemetry        array   getTelemetry()  (latest / previous / deltas)
 *   ewsTrend         array   getEwsTrend()   (points / max / avg)
 *   flowsheet        array   getFlowsheet()  (TERBARU DI ATAS)
 *   bundleCompliance array   BundleService::getSummary()
 *   devices          array   BundleService::getDeviceSummary() (8 baris)
 *   reference        array   katalog yang dibutuhkan formulir (lihat
 *                             reference()): band EWS, item/grup bundle, katalog
 *                             perangkat, dan opsi kategori obat
 *   filters          array   filter aktif dari query string
 *   activeTab        string  'observasi'
 *
 * ATURAN KONTRAK PENTING
 *
 *  1. SKOR EWS SELALU DIHITUNG ULANG DI SERVER. Field `ews_total`, `ews_scores`,
 *     dan `ews_risk` dari klien DIABAIKAN total: payload yang diteruskan ke
 *     ObservationService::save() dibangun dari daftar putih field di bawah, jadi
 *     tidak ada jalur yang bisa menyuntikkan skor milik klien.
 *
 *  2. SLOT JAM = IDEMPOTENCY KEY. save() adalah UPSERT pada
 *     (encounter_id, observation_date, observation_time) dan mengembalikan
 *     {observation, created, duplicated}. `duplicated` berarti "slot ini sudah ada,
 *     data diperbarui" - bukan kondisi galat, jadi flash-nya sukses dengan
 *     kalimat yang berbeda. phase1 menghapus lalu menyimpan ulang (confirm +
 *     retry); di sini tidak perlu karena satu permintaan sudah idempoten.
 *
 *  3. PESAN VALIDASI SELALU BAHASA INDONESIA dan TIDAK PERNAH 500. Dua lapis:
 *     validasi bentuk payload di controller (pesan ringkas per field), lalu
 *     rentang wajar + kebutuhan Field di ObservationService (pesan yang sama
 *     persis, lihat RANGE_MESSAGES di sana). Keduanya ditangkap dan diubah
 *     jadi back() + withErrors() + flash `error`.
 *
 *  4. RENTANG SANAITY 1:1 DARI LAYER SERVICE. Kolom `min`/`max` di rules() di
 *     bawah sengaja diduplikasi dari ObservationService::VITAL_RANGES supaya
 *     tanda min/max pada <input> di formulir sama dengan batas yang ditegakkan
 *     server. Ubah keduanya bersama.
 */
class ObservasiController extends Controller
{
    /**
     * Nilai yang boleh dipilih untuk kolom `observations.status`.
     *
     * @var array<string, string>
     */
    private const STATUS_OPTIONS = [
        'final' => 'Final',
        'draft' => 'Draft (menunggu verifikasi)',
        'revised' => 'Revisi (koreksi data)',
    ];

    /**
     * @param  array<string, mixed>  $filters
     */
    public function __construct(
        private readonly ObservationService $observations,
        private readonly BundleService $bundles,
        private readonly AdmissionService $admissions,
    ) {}

    /**
     * GET /encounters/{encounter}/observasi
     *
     * Query string (semuanya opsional, semuanya bisa berupa string kosong):
     *   dateFrom    batas bawah tanggal observasi   (Y-m-d)
     *   dateTo      batas atas tanggal observasi    (Y-m-d)
     *   risk        low | medium | high | emergency, '' = semua
     *   hasBundle   '1' = hanya observasi berjawaban bundle, '0' = yang tidak
     *   medication  nama obat (pencocokan sebagian)
     *   search      catatan / petugas / nama obat
     *
     * Nilai yang tidak dikenal TIDAK dibatalkan ke filter lain: dateFrom/dateTo
     * diabaikan bila tidak berbentuk Y-m-d, risk diabaikan bila bukan salah satu
     * dari empat tingkat EWS, dan risk='all' berarti tanpa filter. Jadi URL yang
     * diketik manual tidak pernah menghasilkan galat.
     */
    public function show(Request $request, Encounter $encounter): Response
    {
        abort_unless(
            $encounter->exists,
            404,
            'Episode perawatan tidak ditemukan. Periksa kembali kode encounter pada alamat halaman.'
        );

        $filters = $this->filters($request);

        return Inertia::render('Observasi', [
            'encounterId' => (string) $encounter->encounter_id,
            'banner' => $this->admissions->getBannerPayload($encounter),
            'telemetry' => $this->observations->getTelemetry($encounter),
            'ewsTrend' => $this->observations->getEwsTrend($encounter),
            'flowsheet' => $this->observations->getFlowsheet($encounter, $filters),
            'bundleCompliance' => $this->bundles->getSummary($encounter),
            'devices' => $this->bundles->getDeviceSummary($encounter),
            'reference' => $this->reference(),
            'filters' => $filters,
            'activeTab' => 'observasi',
            // Empat boolean dari Encounter::getCompletionAttribute(). Strip tab
            // admisi di dalam formulir observasi (#data-admission-tab pada
            // phase1/observasi.html) membaca ini untuk menampilkan "Data Sudah
            // Diisi" / "Belum Diisi". Ditambahkan secara aditif; halaman lama
            // yang tidak memakai prop ini tetap berjalan karena punya default.
            'admissionCompletion' => (array) $encounter->completion,
        ]);
    }

    /**
     * POST /encounters/{encounter}/observasi
     * name: observasi.store
     *
     * Satu slot jam per permintaan. Sukses dan kegagalan keduanya mengembalikan
     * 302 ke halaman sebelumnya supaya ClinicalLayout membaca flash baru; tidak
     * ada jalur yang mengembalikan 500.
     */
    public function store(Request $request, Encounter $encounter)
    {
        try {
            $data = $request->validate($this->rules(), $this->messages());
        } catch (ValidationException $e) {
            return back()
                ->withInput()
                ->withErrors($e->errors())
                ->with('error', 'Observasi EWS gagal disimpan. Periksa kembali isian formulir yang ditandai.');
        }

        try {
            // PENTING: $this->payload() adalah daftar putih. Skor EWS milik klien
            // tidak pernah ikut, sehingga ObservationService::save() wajib
            // menghitung ulang dari tanda vital yang tersimpan.
            $result = $this->observations->save(
                $encounter,
                $this->payload($data),
                $request->user(),
            );
        } catch (ValidationException $e) {
            return back()
                ->withInput()
                ->withErrors($e->errors())
                ->with('error', 'Observasi EWS gagal disimpan. Periksa kembali nilai tanda vital yang Anda masukkan.');
        }

        return back()->with('success', $result['duplicated']
            ? 'Slot jam ini sudah ada, data diperbarui.'
            : 'Observasi EWS tersimpan.');
    }

    /**
     * POST /encounters/{encounter}/observasi/delete
     * name: observasi.destroy
     *
     * Menghapus satu baris berdasarkan slot jamnya. Slot yang tidak ada bukan
     * galat HTTP: balasannya 302 dengan flash `error` berbahasa Indonesia,
     * supaya tombol hapus di flowsheet tidak pernah menampilkan halaman error.
     */
    public function destroy(Request $request, Encounter $encounter)
    {
        try {
            $data = $request->validate([
                'date' => ['required', 'date_format:Y-m-d'],
                'time' => ['required', 'date_format:H:i'],
            ], [
                'date.required' => 'Tanggal observasi wajib diisi.',
                'date.date_format' => 'Tanggal observasi harus berformat YYYY-MM-DD.',
                'time.required' => 'Jam observasi wajib diisi.',
                'time.date_format' => 'Jam observasi harus berformat HH:MM.',
            ]);
        } catch (ValidationException $e) {
            return back()
                ->withErrors($e->errors())
                ->with('error', 'Observasi tidak dapat dihapus: slot jam tidak valid.');
        }

        $deleted = $this->observations->delete($encounter, $data['date'], $data['time']);

        return $deleted
            ? back()->with('success', 'Observasi EWS tanggal '.$data['date'].' pukul '.$data['time'].' dihapus.')
            : back()->with('error', 'Observasi pada slot '.$data['date'].' pukul '.$data['time'].' tidak ditemukan.');
    }

    /**
     * Normalisasi filter query string.
     *
     * @return array{dateFrom: string|null, dateTo: string|null, risk: string, hasBundle: string, medication: string|null, search: string|null}
     */
    private function filters(Request $request): array
    {
        return [
            'dateFrom' => $this->dateOrNull($request->query('dateFrom', $request->query('date_from'))),
            'dateTo' => $this->dateOrNull($request->query('dateTo', $request->query('date_to'))),
            'risk' => $this->risk($request->query('risk', $request->query('ews_risk'))),
            'hasBundle' => $this->flag($request->query('hasBundle')),
            'medication' => $this->text($request->query('medication')),
            'search' => $this->text($request->query('search', $request->query('q'))),
        ];
    }

    /**
     * Katalog yang dibutuhkan formulir.
     *
     * `ews` dikirim utuh dari config('ews') (parameter + band + eskalasi) supaya
     * pratinjau skor di sisi klien memakai tabel yang PERSIS sama dengan
     * EwsScoringService, termasuk celah band pada `Temp` yang sengaja skor 0.
     *
     * @return array{
     *     ews: array<string, mixed>,
     *     bundleItems: array<int, array<string, string>>,
     *     bundleGroups: array<int, array<string, string>>,
     *     deviceCatalog: array<int, array<string, string>>,
     *     deviceReviewAfterDays: int,
     *     medicationCategories: array<string, string>,
     *     consciousnessLevels: array<string, string>,
     *     statuses: array<string, string>
     * }
     */
    private function reference(): array
    {
        return [
            'ews' => (array) config('ews', []),
            'bundleItems' => (array) config('hai.bundle_items', []),
            'bundleGroups' => (array) config('hai.bundle_groups', []),
            'deviceCatalog' => (array) config('hai.device_catalog', []),
            'deviceReviewAfterDays' => (int) config('hai.device_review_after_days', 7),
            'medicationCategories' => MedicationCategory::options(),
            'consciousnessLevels' => ConsciousnessLevel::options(),
            'statuses' => self::STATUS_OPTIONS,
        ];
    }

    /**
     * Aturan validasi bentuk payload.
     *
     * Rentang `min`/`max` untuk vital sengaja SAMA dengan
     * ObservationService::VITAL_RANGES supaya batas yang ditegakkan di sini,
     * batas di <input> formulir, dan batas di service tidak pernah berbeda.
     *
     * @return array<string, array<int, mixed>>
     */
    private function rules(): array
    {
        return [
            'observation_date' => ['required', 'date_format:Y-m-d'],
            'observation_time' => ['required', 'date_format:H:i'],

            'sys' => ['required', 'numeric', 'min:20', 'max:350'],
            'dia' => ['required', 'numeric', 'min:5', 'max:250'],
            'map' => ['nullable', 'numeric', 'min:5', 'max:300'],
            'hr' => ['required', 'numeric', 'min:10', 'max:300'],
            'rr' => ['required', 'numeric', 'min:0', 'max:100'],
            'spo2' => ['required', 'numeric', 'min:20', 'max:100'],
            'suhu' => ['required', 'numeric', 'min:20', 'max:50'],
            'kesadaran' => ['required', 'string', Rule::in(array_column(ConsciousnessLevel::cases(), 'value'))],

            'gcs' => ['nullable', 'string', 'max:50'],
            'gcs_text' => ['nullable', 'string', 'max:50'],
            'weight' => ['nullable', 'numeric', 'min:0', 'max:500'],
            'blood_glucose' => ['nullable', 'numeric', 'min:0', 'max:2000'],
            'iwl' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'intake' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'output' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'transfusion_volume' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'parenteral' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'enteral' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'urine' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'drain' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'bp_method' => ['nullable', 'string', Rule::in(['NIBP', 'IBP'])],
            'respiratory_problem' => ['nullable', 'string', Rule::in(['Ya', 'Tidak'])],
            'transfusion_type' => ['nullable', 'string', Rule::in(['PRC', 'FFP', 'TC'])],
            'nursing_action' => ['nullable', 'string', 'max:255'],
            'rhythm' => ['nullable', 'string', 'max:100'],
            'breath_type' => ['nullable', 'string', 'max:100'],
            'o2_support' => ['nullable', 'string', 'max:150'],
            'rass' => ['nullable', 'string', 'max:10'],
            'notes' => ['nullable', 'string', 'max:4000'],
            'status' => ['nullable', 'string', Rule::in(array_keys(self::STATUS_OPTIONS))],
            'ventilator_settings' => ['nullable', 'array'],
            'ventilator_settings.ventilator' => ['nullable', 'string', 'max:2000'],
            'ventilator_settings.glucose' => ['nullable', 'string', 'max:40'],
            'ventilator_settings.lungIssue' => ['nullable', 'string', 'max:40'],
            'ventilator_settings.bloodPressureMethod' => ['nullable', 'string', 'max:40'],
            'ventilator_settings.weight' => ['nullable', 'numeric', 'min:0', 'max:500'],

            'medications' => ['nullable', 'array', 'max:60'],
            'medications.*.name' => ['nullable', 'string', 'max:150'],
            'medications.*.dose' => ['nullable', 'string', 'max:150'],
            'medications.*.category' => ['nullable', 'string', Rule::in(array_column(MedicationCategory::cases(), 'value'))],
            'medications.*.volume' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'medications.*.route' => ['nullable', 'string', 'max:40'],
            'medications.*.status' => ['nullable', 'string', 'max:40'],
            'medications.*.sort_order' => ['nullable', 'integer', 'min:0', 'max:200'],

            'bundles' => ['nullable', 'array', 'max:20'],
            'bundles.*' => ['nullable', 'string', 'max:10'],
            'replace_bundles' => ['nullable', 'boolean'],

            'devices' => ['nullable', 'array', 'max:20'],
            'devices.*.key' => ['required', 'string', Rule::in(array_column(DeviceCode::cases(), 'value'))],
            'devices.*.present' => ['nullable', 'boolean'],
            'devices.*.startDate' => ['nullable', 'date_format:Y-m-d'],
            'devices.*.note' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Pesan validasi berbahasa Indonesia.
     *
     * Kalimat untuk rentang vital disalin PERSIS dari
     * ObservationService::RANGE_MESSAGES supaya pesan yang tampil di formulir
     * sama dengan pesan yang dibangkitkan service, apa pun lapisan yang
     * lebih dulu menolak.
     *
     * @return array<string, string>
     */
    private function messages(): array
    {
        return [
            'observation_date.required' => 'Tanggal observasi wajib diisi.',
            'observation_date.date_format' => 'Tanggal observasi harus berformat YYYY-MM-DD.',
            'observation_time.required' => 'Jam observasi wajib diisi (format HH:MM).',
            'observation_time.date_format' => 'Jam observasi harus berformat HH:MM.',

            'sys.required' => 'Tekanan sistolik wajib diisi.',
            'sys.numeric' => 'Tekanan sistolik harus berupa angka.',
            'sys.min' => 'Tekanan sistolik di luar rentang wajar (20 - 350).',
            'sys.max' => 'Tekanan sistolik di luar rentang wajar (20 - 350).',
            'dia.required' => 'Tekanan diastolik wajib diisi.',
            'dia.numeric' => 'Tekanan diastolik harus berupa angka.',
            'dia.min' => 'Tekanan diastolik di luar rentang wajar (5 - 250).',
            'dia.max' => 'Tekanan diastolik di luar rentang wajar (5 - 250).',
            'map.numeric' => 'MAP harus berupa angka.',
            'map.min' => 'MAP di luar rentang wajar (5 - 300).',
            'map.max' => 'MAP di luar rentang wajar (5 - 300).',
            'hr.required' => 'Nadi wajib diisi.',
            'hr.numeric' => 'Nadi harus berupa angka.',
            'hr.min' => 'Nadi di luar rentang wajar (10 - 300).',
            'hr.max' => 'Nadi di luar rentang wajar (10 - 300).',
            'rr.required' => 'Frekuensi napas wajib diisi.',
            'rr.numeric' => 'Frekuensi napas harus berupa angka.',
            'rr.min' => 'Frekuensi napas di luar rentang wajar (0 - 100).',
            'rr.max' => 'Frekuensi napas di luar rentang wajar (0 - 100).',
            'spo2.required' => 'Saturasi oksigen wajib diisi.',
            'spo2.numeric' => 'Saturasi oksigen harus berupa angka.',
            'spo2.min' => 'Saturasi oksigen di luar rentang wajar (20 - 100).',
            'spo2.max' => 'Saturasi oksigen di luar rentang wajar (20 - 100).',
            'suhu.required' => 'Suhu tubuh wajib diisi.',
            'suhu.numeric' => 'Suhu tubuh harus berupa angka.',
            'suhu.min' => 'Suhu tubuh di luar rentang wajar (20 - 50).',
            'suhu.max' => 'Suhu tubuh di luar rentang wajar (20 - 50).',
            'kesadaran.required' => 'Tingkat kesadaran wajib diisi.',
            'kesadaran.in' => 'Tingkat kesadaran tidak dikenal. Pilih salah satu nilai AVPU yang tersedia.',

            'gcs.max' => 'GCS (ETT) terlalu panjang (maksimal 50 karakter).',
            'gcs_text.max' => 'GCS (ETT) terlalu panjang (maksimal 50 karakter).',
            'weight.numeric' => 'Berat badan harus berupa angka.',
            'weight.min' => 'Berat badan di luar rentang wajar (0 - 500).',
            'weight.max' => 'Berat badan di luar rentang wajar (0 - 500).',
            'blood_glucose.numeric' => 'Gula darah harus berupa angka.',
            'blood_glucose.min' => 'Gula darah di luar rentang wajar (0 - 2.000).',
            'blood_glucose.max' => 'Gula darah di luar rentang wajar (0 - 2.000).',
            'iwl.numeric' => 'IWL harus berupa angka.',
            'iwl.min' => 'IWL di luar rentang wajar (0 - 1.000.000).',
            'iwl.max' => 'IWL di luar rentang wajar (0 - 1.000.000).',
            'transfusion_volume.numeric' => 'Volume cairan transfusi harus berupa angka.',
            'transfusion_volume.min' => 'Volume cairan transfusi di luar rentang wajar (0 - 1.000.000).',
            'transfusion_volume.max' => 'Volume cairan transfusi di luar rentang wajar (0 - 1.000.000).',
            'parenteral.numeric' => 'Volume parenteral harus berupa angka.',
            'parenteral.min' => 'Volume parenteral di luar rentang wajar (0 - 1.000.000).',
            'parenteral.max' => 'Volume parenteral di luar rentang wajar (0 - 1.000.000).',
            'enteral.numeric' => 'Volume enteral harus berupa angka.',
            'enteral.min' => 'Volume enteral di luar rentang wajar (0 - 1.000.000).',
            'enteral.max' => 'Volume enteral di luar rentang wajar (0 - 1.000.000).',
            'urine.numeric' => 'Volume urine / BAB harus berupa angka.',
            'urine.min' => 'Volume urine / BAB di luar rentang wajar (0 - 1.000.000).',
            'urine.max' => 'Volume urine / BAB di luar rentang wajar (0 - 1.000.000).',
            'drain.numeric' => 'Volume output drain / NGT harus berupa angka.',
            'drain.min' => 'Volume output drain / NGT di luar rentang wajar (0 - 1.000.000).',
            'drain.max' => 'Volume output drain / NGT di luar rentang wajar (0 - 1.000.000).',
            'bp_method.in' => 'Metode tekanan darah tidak dikenal. Pilih NIBP atau IBP.',
            'respiratory_problem.in' => 'Gangguan paru tidak dikenal. Pilih Ya atau Tidak.',
            'transfusion_type.in' => 'Jenis transfusi tidak dikenal. Pilih PRC, FFP, atau TC.',
            'nursing_action.max' => 'Tindakan keperawatan terlalu panjang (maksimal 255 karakter).',
            'intake.numeric' => 'Volume masuk harus berupa angka.',
            'intake.min' => 'Volume masuk di luar rentang wajar (0 - 1.000.000).',
            'intake.max' => 'Volume masuk di luar rentang wajar (0 - 1.000.000).',
            'output.numeric' => 'Volume keluar harus berupa angka.',
            'output.min' => 'Volume keluar di luar rentang wajar (0 - 1.000.000).',
            'output.max' => 'Volume keluar di luar rentang wajar (0 - 1.000.000).',

            'rhythm.max' => 'Irama nadi terlalu panjang (maksimal 100 karakter).',
            'breath_type.max' => 'Tipe napas terlalu panjang (maksimal 100 karakter).',
            'o2_support.max' => 'Jenis dukungan oksigen terlalu panjang (maksimal 150 karakter).',
            'rass.max' => 'Nilai RASS harus berupa angka bulat -5 sampai +4.',
            'notes.max' => 'Catatan terlalu panjang (maksimal 4.000 karakter).',
            'status.in' => 'Status observasi tidak dikenal. Pilih salah satu status yang tersedia.',
            'ventilator_settings.ventilator.max' => 'Setelan ventilator terlalu panjang (maksimal 2.000 karakter).',
            'ventilator_settings.glucose.max' => 'Nilai gula darah terlalu panjang (maksimal 40 karakter).',
            'ventilator_settings.lungIssue.max' => 'Gangguan paru terlalu panjang (maksimal 40 karakter).',
            'ventilator_settings.bloodPressureMethod.max' => 'Metode tekanan darah terlalu panjang (maksimal 40 karakter).',
            'ventilator_settings.weight.min' => 'Berat badan di luar rentang wajar (0 - 500).',
            'ventilator_settings.weight.max' => 'Berat badan di luar rentang wajar (0 - 500).',

            'medications.max' => 'Terlalu banyak baris koreksi pemberian obat (maksimal 60 baris).',
            'medications.*.name.max' => 'Nama obat terlalu panjang (maksimal 150 karakter).',
            'medications.*.dose.max' => 'Dosis terlalu panjang (maksimal 150 karakter).',
            'medications.*.category.in' => 'Kategori obat tidak dikenal.',
            'medications.*.volume.min' => 'Volume obat tidak boleh negatif.',
            'medications.*.volume.max' => 'Volume obat terlalu besar (maksimal 100.000 mL).',
            'medications.*.route.max' => 'Rute pemberian terlalu panjang (maksimal 40 karakter).',
            'medications.*.status.max' => 'Status pemberian terlalu panjang (maksimal 40 karakter).',

            'bundles.max' => 'Terlalu banyak jawaban bundle (maksimal 20 item).',
            'bundles.*.max' => 'Jawaban bundle hanya boleh Ya atau Tidak.',

            'devices.max' => 'Terlalu banyak baris perangkat invasif (maksimal 20 baris).',
            'devices.*.key.required' => 'Kode perangkat wajib diisi.',
            'devices.*.key.in' => 'Kode perangkat invasif tidak dikenal.',
            'devices.*.startDate.date_format' => 'Tanggal pemasangan perangkat harus berformat YYYY-MM-DD.',
            'devices.*.note.max' => 'Catatan perangkat terlalu panjang (maksimal 255 karakter).',
        ];
    }

    /**
     * DAFTAR PUTIH field yang diteruskan ke ObservationService::save().
     *
     * Sengaja TIDAK ada `ews`, `ews_total`, `ews_scores`, atau `ews_risk` di sini:
     * skor EWS dihitung ulang di server oleh EwsScoringService, jadi payload
     * klien tidak punya jalur apa pun untuk memaksakan skor.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function payload(array $data): array
    {
        $ventilator = is_array($data['ventilator_settings'] ?? null) ? $data['ventilator_settings'] : [];
        $ventilator = array_filter($ventilator, static fn ($value) => $value !== null && $value !== '');

        $medications = [];

        foreach (($data['medications'] ?? []) as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            $name = $this->text($row['name'] ?? null);

            // Baris tanpa nama dilewati oleh service; lewati juga di sini supaya
            // MedicationRecapService tidak pernah dipanggil untuk baris kosong.
            if ($name === null) {
                continue;
            }

            $medications[] = [
                'name' => $name,
                'dose' => $this->text($row['dose'] ?? null),
                'category' => $this->text($row['category'] ?? null),
                'volume' => $row['volume'] ?? null,
                'route' => $this->text($row['route'] ?? null),
                'status' => $this->text($row['status'] ?? null),
                'sort_order' => $index,
            ];
        }

        $bundles = [];

        foreach (($data['bundles'] ?? []) as $key => $answer) {
            $value = $this->text($answer);

            if ($value === null) {
                continue;
            }

            $bundles[(string) $key] = mb_strtolower($value) === 'tidak' ? 'Tidak' : 'Ya';
        }

        $devices = [];

        foreach (($data['devices'] ?? []) as $row) {
            if (! is_array($row) || ! isset($row['key'])) {
                continue;
            }

            $devices[] = [
                'key' => (string) $row['key'],
                'present' => filter_var($row['present'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'startDate' => $this->text($row['startDate'] ?? null),
                'note' => $this->text($row['note'] ?? null),
            ];
        }

        return [
            'observationDate' => $data['observation_date'],
            'observationTime' => $data['observation_time'],
            'sys' => $data['sys'],
            'dia' => $data['dia'],
            'map' => $data['map'] ?? null,
            'hr' => $data['hr'],
            'rr' => $data['rr'],
            'spo2' => $data['spo2'],
            'suhu' => $data['suhu'],
            'kesadaran' => $data['kesadaran'],
            'gcs' => $data['gcs'] ?? null,
            'gcsText' => $this->text($data['gcs_text'] ?? null),
            'weight' => $data['weight'] ?? null,
            'bloodGlucose' => $data['blood_glucose'] ?? null,
            'bpMethod' => $this->text($data['bp_method'] ?? null),
            'respiratoryProblem' => $this->text($data['respiratory_problem'] ?? null),
            'intake' => $data['intake'] ?? null,
            'output' => $data['output'] ?? null,
            'iwl' => $data['iwl'] ?? null,
            'transfusionType' => $this->text($data['transfusion_type'] ?? null),
            'transfusionVolume' => $data['transfusion_volume'] ?? null,
            'parenteral' => $data['parenteral'] ?? null,
            'enteral' => $data['enteral'] ?? null,
            'urine' => $data['urine'] ?? null,
            'drain' => $data['drain'] ?? null,
            'rhythm' => $this->text($data['rhythm'] ?? null),
            'breathType' => $this->text($data['breath_type'] ?? null),
            'o2Support' => $this->text($data['o2_support'] ?? null),
            'rass' => $this->text($data['rass'] ?? null),
            'notes' => $this->text($data['notes'] ?? null),
            'nursingAction' => $this->text($data['nursing_action'] ?? null),
            'status' => $this->text($data['status'] ?? null) ?? 'final',
            'ventilatorSettings' => $ventilator === [] ? [] : $ventilator,
            'medications' => $medications,
            'bundles' => $bundles,
            // Dipakai agar petugas yang membuka ulang slot yang sama dan
            // mencentang lebih sedikit tidak meninggalkan jawaban basi.
            'replaceBundles' => filter_var($data['replace_bundles'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'devices' => $devices,
        ];
    }

    /**
     * Tanggal Y-m-d atau null. Nilai lain diabaikan, bukan dibatalkan.
     */
    private function dateOrNull(mixed $value): ?string
    {
        $text = $this->text($value);

        if ($text === null) {
            return null;
        }

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $text) === 1 ? $text : null;
    }

    /**
     * Tingkat risiko EWS yang valid, atau '' untuk "semua".
     */
    private function risk(mixed $value): string
    {
        $text = mb_strtolower((string) $this->text($value) ?? '');

        if ($text === 'all') {
            return '';
        }

        return in_array($text, ['low', 'medium', 'high', 'emergency'], true) ? $text : '';
    }

    /**
     * Flag tri-state dari query string: '1' atau '0', string kosong = tanpa
     * filter. 'true'/'false' juga diterima karena itu yang dikirimkan form.
     */
    private function flag(mixed $value): string
    {
        $text = mb_strtolower((string) $this->text($value) ?? '');

        if (in_array($text, ['1', 'true', 'ya', 'yes', 'on'], true)) {
            return '1';
        }

        if (in_array($text, ['0', 'false', 'tidak', 'no', 'off'], true)) {
            return '0';
        }

        return '';
    }

    /**
     * Teks yang tidak kosong setelah di-trim, atau null untuk null/''/'-'.
     */
    private function text(mixed $value): ?string
    {
        if ($value === null || is_array($value)) {
            return null;
        }

        $text = trim((string) $value);

        return ($text === '' || $text === '-') ? null : $text;
    }
}

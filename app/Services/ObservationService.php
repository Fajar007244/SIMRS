<?php

namespace App\Services;

use App\Enums\BundleAnswer;
use App\Enums\ConsciousnessLevel;
use App\Enums\DeviceCode;
use App\Enums\EwsRiskLevel;
use App\Models\Encounter;
use App\Models\InvasiveDevice;
use App\Models\Observation;
use App\Models\ObservationBundleAnswer;
use App\Models\ObservationMedication;
use App\Models\User;
use App\Services\Support\ClinicalFormat;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sumber kebenaran untuk observasi EWS: penyimpanan (upsert per slot jam),
 * flowsheet, telemetri, dan tren skor EWS.
 *
 * SLOT JAM = UNIQUE KEY (encounter_id, observation_date, observation_time).
 * Triplet itu adalah idempotencyKey phase1 (app-context.js
 * "<encounterId>:<tanggal>:<waktu>"). Menyimpan ulang slot yang sama berarti
 * MEMPERBARUI, bukan menambah - inilah yang membuat save() aman dipanggil
 * dari dua tab sekaligus. Unique constraint di database tetap menjadi
 * penjaga terakhir bila dua tab benar-benar menulis bersamaan.
 *
 * ATURAN LAYER INI:
 *  - Semua method publik mengembalikan array PHP biasa (DTO Inertia-safe).
 *    Tidak ada model atau Collection yang keluar dari sini.
 *  - Semua penulisan multi-tabel dibungkus DB::transaction().
 *  - Tidak ada lazy loading: koreksi obat dan jawaban bundle untuk seluruh
 *    baris diambil dalam satu kueri masing-masing, bukan satu per baris.
 *
 * URUTAN BARIS: seluruh DTO baris returned TERBARU DI ATAS, sama dengan
 * `getObservations().reverse()` pada phase1 yang juga dipakai kartu
 * dashboard dan tabel flowsheet. Satu-satunya pengecualian adalah getEwsTrend(),
 * yang mengurutkan LAMA ke BARU karena hasilnya langsung diplot.
 */
class ObservationService
{
    /**
     * Batas aman baris flowsheet per permintaan.
     *
     * Observasi ICU dicatat per jam, jadi satu encounter yang lama bisa menumpuk
     * ratusan baris; rowsFor() juga menarik seluruh medication dan bundle answer
     * untuk baris-baris itu, sehingga tanpa batas satu permintaan bisa menarik
     * ribuan baris. Nilai ini jauh di atas kasus nyata (10 baris pada seed,
     * sekitar 24 untuk satu hari penuh) sehingga tidak mengubah apa pun yang
     * terlihat, tapi tetap menutup jalur tanpa batas.
     */
    public const MAX_FLOWSHEET_ROWS = 500;

    public function __construct(private readonly EwsScoringService $ews) {}

    /**
     * Rentang sanity untuk tanda vital. Sengaja dibuat longgar: tujuannya
     * menangkap salah ketik (SpO2 180, suhu 380) tanpa menolak data klinis
     * yang sah. Kolom database sudah lebih ketat (smallInteger / decimal).
     *
     * @var array<string, array{0: float|int, 1: float|int}>
     */
    private const VITAL_RANGES = [
        'sys' => [20, 350],
        'dia' => [5, 250],
        'map' => [5, 300],
        'hr' => [10, 300],
        'rr' => [0, 100],
        'spo2' => [20, 100],
        'suhu' => [20, 50],
        'gcs' => [3, 15],
        'intake' => [0, 1000000],
        'output' => [0, 1000000],
    ];

    /**
     * Nama field untuk pesan validasi.
     *
     * @var array<string, string>
     */
    private const RANGE_MESSAGES = [
        'sys' => 'Tekanan sistolik',
        'dia' => 'Tekanan diastolik',
        'map' => 'MAP',
        'hr' => 'Nadi',
        'rr' => 'Frekuensi napas',
        'spo2' => 'Saturasi oksigen',
        'suhu' => 'Suhu tubuh',
        'gcs' => 'GCS',
        'intake' => 'Volume masuk',
        'output' => 'Volume keluar',
    ];

    /**
     * Rentang sanity field formulir prototype yang ditambahkan lewat migrasi
     * 2026_10_01_000001. NONE of them wajib: prototype tidak punya satupun
     * atribut `required`, jadi kolom yang kosong hanya berarti "tidak diisi".
     *
     * @var array<string, array{0: float|int, 1: float|int}>
     */
    private const OPTIONAL_RANGES = [
        'weight_kg' => [0, 500],
        'blood_glucose' => [0, 2000],
        'transfusion_volume' => [0, 1000000],
        'parenteral_volume' => [0, 1000000],
        'enteral_volume' => [0, 1000000],
        'urine_volume' => [0, 1000000],
        'drain_volume' => [0, 1000000],
        'iwl_volume' => [0, 1000000],
    ];

    /**
     * Nama field untuk pesan validasi, memakai kalimat yang sama persis dengan
     * RANGE_MESSAGES di atas.
     *
     * @var array<string, string>
     */
    private const OPTIONAL_RANGE_MESSAGES = [
        'weight_kg' => 'Berat badan',
        'blood_glucose' => 'Gula darah',
        'transfusion_volume' => 'Volume cairan transfusi',
        'parenteral_volume' => 'Volume parenteral',
        'enteral_volume' => 'Volume enteral',
        'urine_volume' => 'Volume urine / BAB',
        'drain_volume' => 'Volume output drain / NGT',
        'iwl_volume' => 'IWL',
    ];

    /**
     * Nilai yang diizinkan untuk field ber-enum dari radio prototype.
     *
     * @var array<string, array<int, string>>
     */
    private const ENUM_VALUES = [
        'bp_method' => ['NIBP', 'IBP'],
        'respiratory_problem' => ['Tidak', 'Ya'],
        'transfusion_type' => ['PRC', 'FFP', 'TC'],
    ];

    /**
     * Pesan untuk nilai enum yang tidak dikenal (radio yang tidak dikenal).
     *
     * @var array<string, string>
     */
    private const ENUM_MESSAGES = [
        'bp_method' => 'Metode tekanan darah tidak dikenal. Pilih NIBP atau IBP.',
        'respiratory_problem' => 'Gangguan paru tidak dikenal. Pilih Ya atau Tidak.',
        'transfusion_type' => 'Jenis transfusi tidak dikenal. Pilih PRC, FFP, atau TC.',
    ];

    /**
     * Simpan satu observasi pada slot jam tertentu (upsert).
     *
     * Semua penulisan berikut berada dalam SATU transaksi:
     *   1. upsert baris `observations` (skor EWS dihitung ulang di server)
     *   2. hapus + buat ulang `observation_medications`
     *   3. upsert `observation_bundle_answers` per (observation_id, item_key)
     *   4. sinkronkan `invasive_devices`
     *   5. perbarui `encounters.latest_observation_at`
     *
     * INPUT yang diterima (snake_case Laravel ATAU nama phase1):
     *   observationDate|observation_date|date, observationTime|observation_time|time,
     *   sys|sistolik, dia|diastolik, map|mapValue, hr, rhythm,
     *   rr, breathType|breath_type, suhu|temp, spo2,
     *   o2Support|o2_support, kesadaran, rass, gcs, intake, output, notes,
     *   medications[] (name, dose, category, volume, route, status),
     *   bundles|bundleAnswers (peta item_key => Ya/Tidak, atau array berisi
     *     key/answer/note),
     *   devices[] (key, present, startDate, note) - bentuk phase1
     *     collectDevices(),
     *   replaceBundles (bool, opsional) - bila true, jawaban bundle yang
     *     TIDAK ada di payload ikut dihapus agar tidak ada baris basi.
     *
     * `observation` yang dikembalikan adalah baris flowsheet dengan bentuk
     * yang sama persis seperti getFlowsheet(), sehingga halaman observasi
     * bisa langsung menyisipkannya tanpa membangun ulang di sisi klien.
     *
     * @param  array<string, mixed>  $data
     * @return array{observation: array<string, mixed>, created: bool, duplicated: bool}
     *
     * @throws ValidationException
     */
    public function save(Encounter $encounter, array $data, User $user): array
    {
        $date = $this->slotDate($data);
        $time = $this->slotTime($data);

        $kesadaran = $this->ews->consciousnessFromInput(
            $this->pick($data, 'kesadaran', 'consciousness')
        );

        $sys = ClinicalFormat::integer($this->pick($data, 'sys', 'sistolik'));
        $dia = ClinicalFormat::integer($this->pick($data, 'dia', 'diastolik'));
        $hr = ClinicalFormat::integer($this->pick($data, 'hr', 'nadi'));
        $rr = ClinicalFormat::integer($this->pick($data, 'rr', 'respirasi'));
        $spo2 = ClinicalFormat::integer($this->pick($data, 'spo2', 'saturasi'));
        $suhu = ClinicalFormat::numeric($this->pick($data, 'suhu', 'temp', 'temperatur'));
        $gcs = ClinicalFormat::integer($this->pick($data, 'gcs'));
        $intake = ClinicalFormat::numeric($this->pick($data, 'intake'));
        $output = ClinicalFormat::numeric($this->pick($data, 'output'));
        $map = ClinicalFormat::integer($this->pick($data, 'map', 'mapValue', 'map_value'));

        // Field formulir prototype yang ditambahkan migrasi 2026_10_01_000001.
        // Semuanya OPSIONAL: tabel observations menerima NULL untuk semua.
        $weight = ClinicalFormat::numeric($this->pick($data, 'weight', 'weightKg', 'weight_kg'));
        $glucose = ClinicalFormat::numeric($this->pick($data, 'bloodGlucose', 'blood_glucose', 'glucose'));
        $transfusionVolume = ClinicalFormat::numeric($this->pick($data, 'transfusionVolume', 'transfusion_volume'));
        $parenteral = ClinicalFormat::numeric($this->pick($data, 'parenteral', 'parenteralVolume', 'parenteral_volume'));
        $enteral = ClinicalFormat::numeric($this->pick($data, 'enteral', 'enteralVolume', 'enteral_volume'));
        $urine = ClinicalFormat::numeric($this->pick($data, 'urine', 'urineVolume', 'urine_volume'));
        $drain = ClinicalFormat::numeric($this->pick($data, 'drain', 'drainVolume', 'drain_volume'));
        $nursingAction = $this->nullableText($this->pick($data, 'nursingAction', 'nursing_action'));
        // `gcs` pada formulir prototype berupa TEKS ("Contoh: E3-Vt-M5"), bukan
        // angka. Kalau isinya bukan angka, verbatim-nya disimpan di `gcs_text`
        // dan kolom integer `gcs` dibiarkan null - tidak ada data yang dibuang.
        // Kalau isinya angka, angka itu juga yang mengisi `gcs` supaya baris
        // seed lama dan baris hasil migration lama tetap terbaca.
        $gcsText = $this->nullableText($this->pick($data, 'gcsText', 'gcs_text'));

        if ($gcsText === null) {
            $rawGcs = $this->pick($data, 'gcs');

            if (is_string($rawGcs) && trim($rawGcs) !== '' && ! is_numeric(trim($rawGcs))) {
                $gcsText = mb_substr(trim($rawGcs), 0, 50);
            }
        }

        // IWL = round(15 x berat badan / 24), sama seperti recalculateIWL() pada
        // phase1. Kalau klien mengirimkannya sendiri, nilai itu yang dipakai;
        // kalau tidak, dihitung ulang di sini dari berat badan supaya kolom
        // iwl_volume tidak pernah menyimpang dari isian berat badan.
        $iwl = ClinicalFormat::numeric($this->pick($data, 'iwl', 'iwlVolume', 'iwl_volume'))
            ?? ($weight === null ? null : (float) round((15 * $weight) / 24));

        $bpMethod = $this->enumValue($data, ['bpMethod', 'bp_method', 'bloodPressureMethod', 'blood_pressure_method']);
        $respiratoryProblem = $this->enumValue($data, ['respiratoryProblem', 'respiratory_problem', 'lungIssue', 'lung_issue']);
        $transfusionType = $this->enumValue($data, ['transfusionType', 'transfusion_type']);

        $errors = [];

        foreach (['sys' => $sys, 'dia' => $dia, 'hr' => $hr, 'rr' => $rr, 'spo2' => $spo2, 'suhu' => $suhu] as $field => $value) {
            if ($value === null) {
                $errors[$field] = self::RANGE_MESSAGES[$field].' wajib diisi.';
            }
        }

        if ($kesadaran === null) {
            $errors['kesadaran'] = 'Tingkat kesadaran wajib diisi.';
        }

        if ($map === null && $sys !== null && $dia !== null && $sys > 0 && $dia > 0 && $sys >= $dia) {
            $map = (int) round($dia + (($sys - $dia) / 3));
        }

        $checked = [
            'sys' => $sys, 'dia' => $dia, 'map' => $map, 'hr' => $hr, 'rr' => $rr,
            'spo2' => $spo2, 'suhu' => $suhu, 'gcs' => $gcs, 'intake' => $intake, 'output' => $output,
        ];

        foreach ($checked as $field => $value) {
            if ($value === null) {
                continue;
            }

            [$min, $max] = self::VITAL_RANGES[$field];

            if ($value < $min || $value > $max) {
                $errors[$field] = self::RANGE_MESSAGES[$field].' di luar rentang wajar ('
                    .ClinicalFormat::number($min).' - '.ClinicalFormat::number($max).').';
            }
        }

        $optional = [
            'weight_kg' => $weight,
            'blood_glucose' => $glucose,
            'transfusion_volume' => $transfusionVolume,
            'parenteral_volume' => $parenteral,
            'enteral_volume' => $enteral,
            'urine_volume' => $urine,
            'drain_volume' => $drain,
            'iwl_volume' => $iwl,
        ];

        foreach ($optional as $field => $value) {
            if ($value === null) {
                continue;
            }

            [$min, $max] = self::OPTIONAL_RANGES[$field];

            if ($value < $min || $value > $max) {
                $errors[$field] = self::OPTIONAL_RANGE_MESSAGES[$field].' di luar rentang wajar ('
                    .ClinicalFormat::number($min).' - '.ClinicalFormat::number($max).').';
            }
        }

        foreach (['bp_method' => $bpMethod, 'respiratory_problem' => $respiratoryProblem, 'transfusion_type' => $transfusionType] as $field => $value) {
            if ($value !== null && ! in_array($value, self::ENUM_VALUES[$field], true)) {
                $errors[$field] = self::ENUM_MESSAGES[$field];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $scoring = $this->ews->calculate([
            'rr' => $rr,
            'hr' => $hr,
            'sys' => $sys,
            'spo2' => $spo2,
            'suhu' => $suhu,
            'kesadaran' => $kesadaran->value,
        ]);

        $ventilator = $this->pick($data, 'ventilatorSettings', 'ventilator_settings') ?? [];
        $ventilator = is_array($ventilator) ? $ventilator : [];

        // RASS disimpan pada kolom integer `rass` DAN pada ventilator_settings.rass.
        // Keduanya ditulis sinkron: kolom integer supaya bisa di-query/urutkan,
        // ventilator_settings karena itulah lokasi yang dibaca
        // consciousnessLabel() untuk merakit "DPO (RASS -2)" di baris flowsheet.
        // Kolom `rass` tidak ada pada baris seed lama, jadi pembacaan memakai
        // kolom dulu baru jatuh ke JSON (lihat consciousnessLabel()).
        $rass = ClinicalFormat::integer($this->pick($data, 'rass'));

        if ($rass !== null) {
            $ventilator['rass'] = (string) $rass;
        }

        $existing = $this->findSlot($encounter, $date, $time);
        $created = $existing === null;

        $saved = DB::transaction(function () use ($encounter, $data, $user, $date, $time, $existing, $sys, $dia, $map, $hr, $rr, $spo2, $suhu, $kesadaran, $gcs, $intake, $output, $scoring, $ventilator, $weight, $gcsText, $rass, $bpMethod, $glucose, $respiratoryProblem, $transfusionType, $transfusionVolume, $parenteral, $enteral, $urine, $drain, $iwl, $nursingAction) {
            $attributes = [
                'sys' => $sys,
                'dia' => $dia,
                'map' => $map,
                'hr' => $hr,
                'rhythm' => $this->nullableText($this->pick($data, 'rhythm')),
                'rr' => $rr,
                'breath_type' => $this->nullableText($this->pick($data, 'breathType', 'breath_type')),
                'suhu' => $suhu,
                'spo2' => $spo2,
                'o2_support' => $this->nullableText($this->pick($data, 'o2Support', 'o2_support')),
                'kesadaran' => $kesadaran->value,
                'gcs' => $gcs,
                'gcs_text' => $gcsText,
                'rass' => $rass,
                'weight_kg' => $weight,
                'bp_method' => $bpMethod,
                'blood_glucose' => $glucose,
                'respiratory_problem' => $respiratoryProblem,
                'intake' => $intake,
                'output' => $output,
                'transfusion_type' => $transfusionType,
                'transfusion_volume' => $transfusionVolume,
                'parenteral_volume' => $parenteral,
                'enteral_volume' => $enteral,
                'urine_volume' => $urine,
                'drain_volume' => $drain,
                'iwl_volume' => $iwl,
                'nursing_action' => $nursingAction,
                'ews_total' => $scoring['total'],
                'ews_scores' => $scoring['scores'],
                'ews_risk' => $scoring['risk'],
                'notes' => $this->nullableText($this->pick($data, 'notes')),
                'ventilator_settings' => $ventilator === [] ? null : $ventilator,
                'status' => $this->nullableText($this->pick($data, 'status')) ?? 'final',
                'recorded_by' => $this->nullableText($this->pick($data, 'recordedBy', 'recorded_by')) ?? $user->name,
                'recorded_at' => now(),
                'idempotency_key' => $encounter->encounter_id.':'.$date.':'.$time,
            ];

            if ($existing === null) {
                $observation = Observation::query()->create($attributes + [
                    'encounter_id' => $encounter->getKey(),
                    'observation_date' => $date,
                    'observation_time' => $time,
                ]);
            } else {
                $observation = $existing;
                $observation->forceFill($attributes)->save();
            }

            $this->syncMedications($observation, $data);
            $this->syncBundleAnswers($observation, $data);
            $this->syncDevices($encounter, $date, $data);

            $stamp = $date.' '.$time.':00';
            $current = $encounter->latest_observation_at;

            if (! $current instanceof Carbon || $stamp > $current->format('Y-m-d H:i:s')) {
                $encounter->forceFill(['latest_observation_at' => $stamp])->save();
            }

            return $observation;
        });

        return [
            'observation' => $this->rowFrom($saved->fresh(), collect(), collect()),
            'created' => $created,
            'duplicated' => ! $created,
        ];
    }

    /**
     * Hapus satu observasi berdasarkan slot jamnya. Baris anak (koreksi
     * pemberian obat dan jawaban bundle) ikut terhapus lewat cascade foreign
     * key, lalu penanda `latest_observation_at` dihitung ulang.
     */
    public function delete(Encounter $encounter, string $date, string $time): bool
    {
        $deleted = Observation::query()
            ->where('encounter_id', $encounter->getKey())
            ->whereDate('observation_date', $date)
            ->where(fn (Builder $query) => $query
                ->where('observation_time', $time)
                ->orWhere('observation_time', $time.':00'))
            ->delete();

        if ($deleted === 0) {
            return false;
        }

        $latest = Observation::query()
            ->where('encounter_id', $encounter->getKey())
            ->latestFirst()
            ->first(['observation_date', 'observation_time']);

        $encounter->forceFill([
            'latest_observation_at' => $latest === null
                ? null
                : $latest->observation_date->format('Y-m-d').' '.$latest->observation_time->format('H:i').':00',
        ])->save();

        return true;
    }

    /**
     * Baris flowsheet, TERBARU DI ATAS.
     *
     * FILTER yang didukung:
     *   dateFrom, dateTo  -> batas tanggal observasi (Y-m-d)
     *   risk              -> low|medium|high|emergency, atau array-nya
     *   hasBundle         -> bool: observasi yang punya / tidak punya jawaban
     *   medication        -> nama obat (partial match) pada observasi itu
     *   search            -> catatan, petugas, atau nama obat
     *
     * @param  array<string, mixed>  $filters
     * @param  int|null  $limit  Batas baris; null berarti self::MAX_FLOWSHEET_ROWS.
     * @return array<int, array<string, mixed>>
     */
    public function getFlowsheet(Encounter $encounter, array $filters = [], ?int $limit = null): array
    {
        return $this->rowsFor($encounter, $filters, $limit ?? self::MAX_FLOWSHEET_ROWS);
    }

    /**
     * Ringkasan untuk kartu dashboard: observasi terakhir, sebelumnya, delta
     * tanda vital, dan jumlah observasi berisiko tinggi/emergensi.
     *
     * @return array{
     *     latest: array<string, mixed>|null,
     *     previous: array<string, mixed>|null,
     *     deltas: array{hr: int, sbp: int, spo2: int, suhu: float, rr: int},
     *     atRiskCount: int,
     *     lastUpdatedAt: string|null,
     *     lastUpdatedBy: string|null
     * }
     */
    public function getTelemetry(Encounter $encounter): array
    {
        // Hanya dua baris terbaru yang dipakai di bawah, jadi batasi kuerinya.
        $rows = $this->rowsFor($encounter, [], 2);

        $latest = $rows[0] ?? null;
        $previous = $rows[1] ?? null;

        $deltas = ['hr' => 0, 'sbp' => 0, 'spo2' => 0, 'suhu' => 0.0, 'rr' => 0];

        if ($latest !== null && $previous !== null) {
            $deltas = [
                'hr' => (int) $latest['hr'] - (int) $previous['hr'],
                'sbp' => (int) $latest['sys'] - (int) $previous['sys'],
                'spo2' => (int) $latest['spo2'] - (int) $previous['spo2'],
                'suhu' => round((float) $latest['suhu'] - (float) $previous['suhu'], 1),
                'rr' => (int) $latest['rr'] - (int) $previous['rr'],
            ];
        }

        $atRiskCount = Observation::query()
            ->where('encounter_id', $encounter->getKey())
            ->whereIn('ews_risk', [EwsRiskLevel::HIGH->value, EwsRiskLevel::EMERGENCY->value])
            ->count();

        return [
            'latest' => $latest,
            'previous' => $previous,
            'deltas' => $deltas,
            'atRiskCount' => (int) $atRiskCount,
            'lastUpdatedAt' => $latest['recordedAt'] ?? null,
            'lastUpdatedBy' => $latest['recordedBy'] ?? null,
        ];
    }

    /**
     * Deret tren skor EWS untuk sparkline / grafik.
     *
     * Titik returned dari yang LAMA ke yang BARU (urutan menggambar garis).
     * `label` memakai ClinicalFormat::chartLabel() (d/m H:i) supaya konsisten
     * dengan tren bundle dan penunjang.
     *
     * `max` adalah plafon sumbu Y, yaitu skor maksimum skala British 4 tingkat
     * (18), BUKAN maksimum data, supaya nilai 0 tetap terbaca jujur dan
     * garisgrid 0 / 4 / 7 / 13 / 18 bisa digambar.
     *
     * @return array{points: array<int, array{at: string, label: string, total: int, risk: string, riskLabel: string}>, max: int, avg: float}
     */
    public function getEwsTrend(Encounter $encounter, int $limit = 24): array
    {
        $observations = Observation::query()
            ->where('encounter_id', $encounter->getKey())
            ->latestFirst()
            ->limit(max(1, $limit))
            ->get(['id', 'observation_date', 'observation_time', 'ews_total', 'ews_risk']);

        $points = [];
        $sum = 0;

        foreach ($observations->reverse() as $observation) {
            $score = (int) $observation->ews_total;
            $sum += $score;

            $risk = $observation->ews_risk?->value ?? EwsRiskLevel::LOW->value;
            $at = $observation->recorded_on;

            $points[] = [
                'at' => ClinicalFormat::iso($at) ?? ClinicalFormat::EMPTY,
                'label' => ClinicalFormat::chartLabel($at),
                'total' => $score,
                'risk' => $risk,
                'riskLabel' => $this->ews->riskLabel($risk),
            ];
        }

        return [
            'points' => $points,
            'max' => $this->ews->maxTotal(),
            'avg' => $points === [] ? 0.0 : round($sum / count($points), 2),
        ];
    }

    /**
     * Cari baris pada satu slot jam.
     *
     * Jam dibandingkan langsung sebagai string 'H:i' atau 'H:i:s', BUKAN
     * memakai whereTime(): whereTime() membungkus nilai dengan strftime()
     * SQLite yang salah membaca nilai jam tanpa detik, dan kolom
     * `observation_date` yang di-cast `date` tersimpan sebagai
     * 'Y-m-d 00:00:00' sehingga perbandingan 'Y-m-d' tidak akan cocok.
     */
    private function findSlot(Encounter $encounter, string $date, string $time): ?Observation
    {
        return Observation::query()
            ->where('encounter_id', $encounter->getKey())
            ->whereDate('observation_date', $date)
            ->where(fn (Builder $query) => $query
                ->where('observation_time', $time)
                ->orWhere('observation_time', $time.':00'))
            ->first();
    }

    /**
     * Query utama flowsheet: 1 kueri observasi + 1 kueri koreksi obat + 1
     * kueri jawaban bundle untuk seluruh baris sekaligus. Tidak ada N+1.
     *
     * @param  array<string, mixed>  $filters
     * @param  int  $limit  Batas baris, selalu jadi max(1, $limit).
     * @return array<int, array<string, mixed>>
     */
    private function rowsFor(Encounter $encounter, array $filters, int $limit): array
    {
        $query = Observation::query()->where('encounter_id', $encounter->getKey())->limit(max(1, $limit));

        $this->applyFilters($query, $filters);

        $observations = $query->latestFirst()->get([
            'id', 'encounter_id', 'observation_date', 'observation_time', 'sys', 'dia', 'map',
            'hr', 'rhythm', 'rr', 'breath_type', 'suhu', 'spo2', 'o2_support', 'kesadaran',
            'gcs', 'weight_kg', 'gcs_text', 'rass', 'bp_method', 'blood_glucose',
            'respiratory_problem', 'intake', 'output', 'transfusion_type', 'transfusion_volume',
            'parenteral_volume', 'enteral_volume', 'urine_volume', 'drain_volume', 'iwl_volume',
            'ews_total', 'ews_risk', 'notes', 'nursing_action', 'ventilator_settings',
            'status', 'recorded_by', 'recorded_at',
        ]);

        if ($observations->isEmpty()) {
            return [];
        }

        $ids = $observations->pluck('id')->all();

        $medications = ObservationMedication::query()
            ->whereIn('observation_id', $ids)
            ->orderBy('observation_id')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->groupBy('observation_id');

        $answers = ObservationBundleAnswer::query()
            ->whereIn('observation_id', $ids)
            ->orderBy('observation_id')
            ->orderBy('id')
            ->get()
            ->groupBy('observation_id');

        $rows = [];

        foreach ($observations as $observation) {
            $rows[] = $this->rowFrom(
                $observation,
                $medications->get($observation->id, collect()),
                $answers->get($observation->id, collect())
            );
        }

        return $rows;
    }

    /**
     * SATU-SATUNYA pembangun baris flowsheet, dipakai oleh getFlowsheet(),
     * getTelemetry(), dan save(). phase1 memakai prinsip yang sama (lihat
     * buildObservationRow() di app-context.js) agar isi kolom tidak pernah
     * berbeda sebelum vs sesudah refresh.
     *
     * @param  Collection<int, ObservationMedication>  $medications
     * @param  Collection<int, ObservationBundleAnswer>  $answers
     * @return array<string, mixed>
     */
    private function rowFrom(Observation $observation, $medications, $answers): array
    {
        $at = $observation->recorded_on;
        $risk = $observation->ews_risk?->value ?? EwsRiskLevel::LOW->value;
        $score = (int) $observation->ews_total;

        $medicationNames = [];

        foreach ($medications as $medication) {
            $medicationNames[] = (string) $medication->name;
        }

        $bundle = $this->bundleStats($answers);

        $map = $observation->map === null ? null : (int) $observation->map;
        $sys = (int) $observation->sys;
        $dia = (int) $observation->dia;

        return [
            'id' => (int) $observation->getKey(),
            'date' => $observation->observation_date?->format('Y-m-d'),
            'time' => $observation->observation_time?->format('H:i'),
            'recordedAt' => ClinicalFormat::iso($at),
            'recordedAtLabel' => ClinicalFormat::dateTimeLabel($at),
            'dateLabel' => ClinicalFormat::dateLabel($observation->observation_date),
            'timeLabel' => ClinicalFormat::timeLabel($observation->observation_time),
            'sys' => $sys,
            'dia' => $dia,
            'map' => $map,
            'bpLabel' => $sys.'/'.$dia,
            'hr' => (int) $observation->hr,
            'rhythm' => $observation->rhythm,
            'rr' => (int) $observation->rr,
            'breathType' => $observation->breath_type,
            'suhu' => (float) $observation->suhu,
            'spo2' => (int) $observation->spo2,
            'o2Support' => $observation->o2_support,
            'kesadaran' => $this->consciousnessLabel($observation),
            'gcs' => $observation->gcs === null ? null : (int) $observation->gcs,
            'gcsText' => $observation->gcs_text ?? (
                $observation->gcs === null ? null : (string) (int) $observation->gcs
            ),
            'rass' => $observation->rass ?? ($observation->ventilator_settings['rass'] ?? null),
            'weightKg' => $observation->weight_kg === null ? null : (float) $observation->weight_kg,
            'bpMethod' => $observation->bp_method,
            'bloodGlucose' => $observation->blood_glucose === null ? null : (int) $observation->blood_glucose,
            'respiratoryProblem' => $observation->respiratory_problem,
            'intake' => $observation->intake === null ? null : (float) $observation->intake,
            'output' => $observation->output === null ? null : (float) $observation->output,
            'transfusionType' => $observation->transfusion_type,
            'transfusionVolume' => $observation->transfusion_volume === null ? null : (float) $observation->transfusion_volume,
            'parenteralVolume' => $observation->parenteral_volume === null ? null : (float) $observation->parenteral_volume,
            'enteralVolume' => $observation->enteral_volume === null ? null : (float) $observation->enteral_volume,
            'urineVolume' => $observation->urine_volume === null ? null : (float) $observation->urine_volume,
            'drainVolume' => $observation->drain_volume === null ? null : (float) $observation->drain_volume,
            'iwlVolume' => $observation->iwl_volume === null ? null : (float) $observation->iwl_volume,
            'nursingAction' => $observation->nursing_action,
            'ventilator' => $observation->ventilator_settings['ventilator'] ?? null,
            'fluidBalance' => $observation->fluid_balance,
            'ewsTotal' => $score,
            'ewsRisk' => $risk,
            'riskLabel' => $this->ews->riskLabel($risk),
            'riskTone' => $this->ews->riskTone($risk),
            'badgeClasses' => $this->ews->badgeClasses($score, $risk),
            'recordedBy' => ClinicalFormat::dash($observation->recorded_by),
            'status' => $observation->status,
            'medicationCount' => count($medicationNames),
            'medicationNames' => $medicationNames,
            'hasBundle' => $bundle['answered'] > 0,
            'bundlePercent' => $bundle['percent'],
            'notes' => $observation->notes,
        ];
    }

    /**
     * Tingkat kepatuhan bundle untuk satu observasi.
     *
     * Pembulatan sama dengan countBundleCompliance() phase1: persen dihitung
     * terhadap jumlah item yang DIJAWAB, bukan terhadap seluruh 13 item
     * konfigurasi. Observasi tanpa jawaban bundle menghasilkan persen 0.
     *
     * @param  Collection<int, ObservationBundleAnswer>  $answers
     * @return array{answered: int, compliant: int, percent: int}
     */
    private function bundleStats($answers): array
    {
        $answered = 0;
        $compliant = 0;

        foreach ($answers as $answer) {
            $answered++;

            if ($answer->answer === BundleAnswer::YA) {
                $compliant++;
            }
        }

        return [
            'answered' => $answered,
            'compliant' => $compliant,
            'percent' => $answered > 0 ? (int) round($compliant / $answered * 100) : 0,
        ];
    }

    /**
     * Nilai `kesadaran` untuk ditampilkan.
     *
     * phase1 menyimpan bentuk DISPLAY ("DPO (RASS -2)") di baris flowsheet,
     * sedangkan kolom database hanya menyimpan enum ConsciousnessLevel. Karena
     * baris flowsheet adalah DTO tampilan (sudah ada dateLabel, bpLabel,
     * riskLabel), nilai RASS ditambahkan kembali di sini agar informasi sedasi
     * tidak hilang. RASS diambil dari ventilator_settings.rass.
     */
    private function consciousnessLabel(Observation $observation): string
    {
        $level = $observation->kesadaran;

        if ($level === null) {
            return ClinicalFormat::EMPTY;
        }

        // Kolom `rass` (integer, diisi sejak migrasi 2026_10_01) didahulukan;
        // ventilator_settings.rass dipakai sebagai fallback supaya baris yang
        // ditulis sebelum migrasi itu tetap menampilkan RASS-nya.
        $rass = $observation->rass ?? ($observation->ventilator_settings['rass'] ?? null);

        if ($level === ConsciousnessLevel::DPO && $rass !== null && trim((string) $rass) !== '') {
            return $level->value.' (RASS '.(is_int($rass) ? $rass : trim((string) $rass)).')';
        }

        return $level->value;
    }

    /**
     * @param  Builder<Observation>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters($query, array $filters): void
    {
        $dateFrom = $this->nullableText($filters['dateFrom'] ?? $filters['date_from'] ?? null);

        if ($dateFrom !== null) {
            $query->whereDate('observation_date', '>=', $dateFrom);
        }

        $dateTo = $this->nullableText($filters['dateTo'] ?? $filters['date_to'] ?? null);

        if ($dateTo !== null) {
            $query->whereDate('observation_date', '<=', $dateTo);
        }

        $risk = $filters['risk'] ?? $filters['ews_risk'] ?? null;

        if (is_string($risk) && trim($risk) !== '' && $risk !== 'all') {
            $query->where('ews_risk', trim($risk));
        } elseif (is_array($risk) && $risk !== []) {
            $query->whereIn('ews_risk', $risk);
        }

        if (array_key_exists('hasBundle', $filters) && $filters['hasBundle'] !== null && $filters['hasBundle'] !== '') {
            $this->toBool($filters['hasBundle'])
                ? $query->whereHas('bundleAnswers')
                : $query->whereDoesntHave('bundleAnswers');
        }

        $medication = $this->nullableText($filters['medication'] ?? null);

        if ($medication !== null) {
            $like = '%'.$medication.'%';

            $query->whereHas('medications', fn ($q) => $q->where('name', 'like', $like));
        }

        $search = $this->nullableText($filters['search'] ?? null);

        if ($search !== null) {
            $like = '%'.$search.'%';

            $query->where(function ($q) use ($like) {
                $q->where('notes', 'like', $like)
                    ->orWhere('recorded_by', 'like', $like)
                    ->orWhereHas('medications', fn ($m) => $m->where('name', 'like', $like));
            });
        }
    }

    /**
     * Hapus + buat ulang baris koreksi pemberian obat / cairan.
     * Dipilih delete-then-insert (bukan upsert) karena baris ini tidak punya
     * natural key dan urutannya harus mengikuti urutan ketikan petugas.
     *
     * @param  array<string, mixed>  $data
     */
    private function syncMedications(Observation $observation, array $data): void
    {
        $items = $this->pick($data, 'medications', 'medicines', 'drugs') ?? [];
        $items = is_array($items) ? $items : [];

        // Baris cairan disintesis dari isian form, bukan dikirim klien, supaya
        // baris transfusi / parenteral / enteral tetap muncul di modul Farmasi
        // walau kliennya tidak mengirimkannya. Bandingkan dengan
        // buildMedicationEntries() phase1: nama, dosis, kategori, indikasi, dan
        // statusnya sama persis.
        $items = array_merge($items, $this->fluidMedicationRows($data));

        ObservationMedication::query()->where('observation_id', $observation->getKey())->delete();

        $order = 0;

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $name = $this->nullableText($item['name'] ?? null);

            if ($name === null) {
                continue;
            }

            $category = $this->nullableText($item['category'] ?? null)
                ?? MedicationRecapService::inferCategory($name);

            ObservationMedication::query()->create([
                'observation_id' => $observation->getKey(),
                'name' => $name,
                'dose' => $this->nullableText($item['dose'] ?? null) ?? ClinicalFormat::EMPTY,
                'category' => $category,
                'volume' => ClinicalFormat::numeric($item['volume'] ?? null) ?? 0,
                'route' => $this->nullableText($item['route'] ?? null),
                'status' => $this->nullableText($item['status'] ?? null) ?? 'diberikan',
                'given_at' => $observation->recorded_on,
                'sort_order' => $item['sort_order'] ?? $order,
            ]);

            $order++;
        }
    }

    /**
     * Upsert jawaban bundle per (observation_id, item_key).
     *
     * `updateOrCreate` dipakai untuk setiap item yang ADA di payload, sesuai
     * kontrak "upsert keyed on (observation_id, item_key)". Opt-in
     * `replaceBundles` = true untuk memangkas juga item yang tidak lagi
     * dikirim, supaya tidak tertinggal jawaban basi ketika petugas membuka
     * ulang slot yang sama lalu mencentang kurang.
     *
     * @param  array<string, mixed>  $data
     */
    private function syncBundleAnswers(Observation $observation, array $data): void
    {
        $payload = $this->pick($data, 'bundles', 'bundleAnswers', 'bundle_answers') ?? [];
        $items = $this->normalizeBundlePayload($payload);
        $index = [];

        foreach ((array) config('hai.bundle_items', []) as $item) {
            $index[$item['key']] = $item;
        }

        foreach ($items as $key => $item) {
            $answer = BundleAnswer::normalize($item['answer'] ?? null);

            if ($answer === null || ! isset($index[$key])) {
                continue;
            }

            ObservationBundleAnswer::query()->updateOrCreate(
                [
                    'observation_id' => $observation->getKey(),
                    'item_key' => $key,
                ],
                [
                    'bundle_group' => $index[$key]['group'],
                    'answer' => $answer->value,
                    'note' => $this->nullableText($item['note'] ?? null),
                ]
            );
        }

        if ($this->toBool($this->pick($data, 'replaceBundles', 'replace_bundles', 'bundlesReplace'))) {
            ObservationBundleAnswer::query()
                ->where('observation_id', $observation->getKey())
                ->whereNotIn('item_key', array_keys($items))
                ->delete();
        }
    }

    /**
     * Terima bentuk peta phase1 ({vap_1: 'Ya'}) maupun bentuk array
     * ({key, answer, note}) yang lebih wajar untuk form Laravel.
     *
     * @return array<string, array{answer: mixed, note: mixed}>
     */
    private function normalizeBundlePayload(mixed $payload): array
    {
        $items = [];

        if (! is_array($payload)) {
            return $items;
        }

        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $itemKey = $this->nullableText($value['key'] ?? $value['item_key'] ?? $key);
                $items[(string) $itemKey] = [
                    'answer' => $value['answer'] ?? null,
                    'note' => $value['note'] ?? null,
                ];

                continue;
            }

            $items[(string) $key] = ['answer' => $value, 'note' => null];
        }

        return $items;
    }

    /**
     * Sinkronkan perangkat invasif dari bentuk phase1 collectDevices().
     *
     * Tabel invasive_devices memakai rentang tanggal, jadi:
     *  - present = true  -> baris aktif dibuat bila belum ada (start_date
     *    default = tanggal observasi, sama seperti
     *    syncBundleDatesWithObservation() di observasi.html), atau
     *    diperbarui bila baris aktif sudah ada.
     *  - present = false -> baris aktif ditutup dengan end_date = tanggal
     *    observasi, karena phase1 hanya menyimpan status per jam dan tidak
     *    bisa membedakan "baru dilepas" dari "tidak pernah dipasang".
     *
     * @param  array<string, mixed>  $data
     */
    private function syncDevices(Encounter $encounter, string $date, array $data): void
    {
        $payload = $this->pick($data, 'devices', 'invasiveDevices', 'invasive_devices') ?? [];

        if (! is_array($payload) || $payload === []) {
            return;
        }

        $rows = [];

        foreach ($payload as $item) {
            if (! is_array($item)) {
                continue;
            }

            $code = DeviceCode::tryFrom((string) ($item['key'] ?? $item['device_code'] ?? ''));

            if ($code === null) {
                continue;
            }

            $rows[] = [
                'code' => $code,
                'present' => $this->toBool($item['present'] ?? $item['is_active'] ?? false),
                'startDate' => ClinicalFormat::parse($item['startDate'] ?? $item['start_date'] ?? null),
                'label' => $this->nullableText($item['label'] ?? null) ?? $code->label(),
                'note' => $this->nullableText($item['note'] ?? null),
            ];
        }

        if ($rows === []) {
            return;
        }

        $codes = array_map(fn (array $row) => $row['code']->value, $rows);

        $existing = InvasiveDevice::query()
            ->where('encounter_id', $encounter->getKey())
            ->whereIn('device_code', $codes)
            ->get()
            ->groupBy(fn (InvasiveDevice $device) => $device->device_code?->value);

        foreach ($rows as $row) {
            $active = ($existing->get($row['code']->value) ?? collect())
                ->first(fn (InvasiveDevice $device) => $device->is_active);

            if ($row['present']) {
                if ($active === null) {
                    InvasiveDevice::query()->create([
                        'encounter_id' => $encounter->getKey(),
                        'device_code' => $row['code']->value,
                        'label' => $row['label'],
                        'start_date' => ($row['startDate'] ?? ClinicalFormat::parse($date))->format('Y-m-d'),
                        'is_active' => true,
                        'note' => $row['note'],
                    ]);

                    continue;
                }

                $active->forceFill([
                    'label' => $row['label'],
                    'note' => $row['note'] ?? $active->note,
                    'is_active' => true,
                ]);

                if ($row['startDate'] !== null) {
                    $active->setAttribute('start_date', $row['startDate']->format('Y-m-d'));
                }

                $active->save();

                continue;
            }

            if ($active === null) {
                continue;
            }

            $active->forceFill([
                'is_active' => false,
                'end_date' => $row['startDate']?->format('Y-m-d') ?? $date,
            ])->save();
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function slotDate(array $data): string
    {
        $parsed = ClinicalFormat::parse($this->pick($data, 'observationDate', 'observation_date', 'date'));

        if ($parsed === null) {
            throw ValidationException::withMessages([
                'observation_date' => 'Tanggal observasi wajib diisi.',
            ]);
        }

        return $parsed->format('Y-m-d');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function slotTime(array $data): string
    {
        $text = trim((string) ($this->pick($data, 'observationTime', 'observation_time', 'time') ?? ''));

        if ($text === '' || preg_match('/^\d{1,2}:\d{2}/', $text) !== 1) {
            throw ValidationException::withMessages([
                'observation_time' => 'Jam observasi wajib diisi (format HH:MM).',
            ]);
        }

        return substr($text, 0, 5);
    }

    /**
     * Ambil satu kunci dari payload dengan mencoba beberapa nama
     * (snake_case Laravel dan nama phase1) secara bergantian.
     *
     * @param  array<string, mixed>  $data
     */
    private function pick(array $data, string ...$keys): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $data)) {
                return $data[$key];
            }
        }

        return null;
    }

    private function nullableText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = trim((string) $value);

        return ($text === '' || $text === '-') ? null : $text;
    }

    /**
     * Ambil nilai enum dari payload dengan mencoba beberapa nama kunci. String
     * kosong / '-' menjadi null (field tidak diisi); nilai lain TIDAK ikut
     * divalidasi di sini supaya pengganggil bisa melaporkan pesannya sendiri
     * lewat ENUM_MESSAGES.
     *
     * @param  array<int, string>  $keys
     */
    private function enumValue(array $data, array $keys): ?string
    {
        return $this->nullableText($this->pick($data, ...$keys));
    }

    /**
     * buildMedicationEntries() phase1: tiga entri ekstra dari isian cairan.
     *
     *   - Transfusi  -> hanya kalau jenis transfusi terisi DAN volumenya > 0
     *   - Parenteral -> kalau volume parenteral > 0
     *   - Enteral    -> kalau volume enteral > 0
     *
     * Kategori ditulis eksplisit (identik dengan hasil inferensi regex nama di
     * atas untuk ketiga nama ini) supaya hasilnya tidak bergantung urutan regex.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fluidMedicationRows(array $data): array
    {
        $rows = [];

        $transfusionType = $this->nullableText($this->pick($data, 'transfusionType', 'transfusion_type'));
        $transfusionVolume = ClinicalFormat::numeric($this->pick($data, 'transfusionVolume', 'transfusion_volume')) ?? 0.0;

        if ($transfusionType !== null && $transfusionVolume > 0) {
            $rows[] = [
                'name' => 'Transfusi '.$transfusionType,
                'dose' => ClinicalFormat::number($transfusionVolume).' mL',
                'category' => 'Obat Systemic',
                'indication' => 'Transfusi komponen darah',
                'volume' => $transfusionVolume,
                'route' => 'IV',
                'status' => 'Diberikan',
            ];
        }

        $parenteral = ClinicalFormat::numeric($this->pick($data, 'parenteral', 'parenteralVolume', 'parenteral_volume')) ?? 0.0;

        if ($parenteral > 0) {
            $rows[] = [
                'name' => 'Cairan Parenteral',
                'dose' => ClinicalFormat::number($parenteral).' mL',
                'category' => 'Cairan & Elektrolit',
                'indication' => 'Infus parenteral',
                'volume' => $parenteral,
                'route' => 'IV',
                'status' => 'Diberikan',
            ];
        }

        $enteral = ClinicalFormat::numeric($this->pick($data, 'enteral', 'enteralVolume', 'enteral_volume')) ?? 0.0;

        if ($enteral > 0) {
            $rows[] = [
                'name' => 'Cairan Enteral',
                'dose' => ClinicalFormat::number($enteral).' mL',
                'category' => 'Cairan & Elektrolit',
                'indication' => 'Pemberian enteral',
                'volume' => $enteral,
                'route' => 'Enteral',
                'status' => 'Diberikan',
            ];
        }

        return $rows;
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            return in_array(mb_strtolower(trim($value)), ['1', 'true', 'yes', 'ya', 'on'], true);
        }

        return (bool) $value;
    }
}

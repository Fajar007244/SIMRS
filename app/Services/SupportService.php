<?php

namespace App\Services;

use App\Enums\SupportGroup;
use App\Models\AbgResult;
use App\Models\Encounter;
use App\Models\SupportResult;
use App\Models\User;
use App\Services\Support\ClinicalFormat;
use App\Services\Support\ReferenceRange;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Area penunjang: laboratorium, darah lengkap, mikrobiologi, radiologi, dan
 * Analisa Gas Darah.
 *
 * Port dari phase1/penunjang.html:
 *   getSupportBucket()   -> SupportService::getGroup() / getAll()
 *   renderResultTable()  -> SupportService::getGroup()
 *   classify()          -> App\Services\Support\ReferenceRange::classify()
 *   renderAbgTable()     -> SupportService::getAbgRecords()
 *   renderAbgSummary()  -> SupportService::getLatestAbg()
 *   renderPending()     -> SupportService::getPending()
 *   renderAbnormal()    -> SupportService::getAbnormal()
 *   renderTrend()       -> SupportService::getTrend()
 *   renderStats()       -> SupportService::getSummary()
 *   saveSupportResult() -> SupportService::saveSupportResult()
 *   saveAbg()           -> SupportService::saveAbg()
 *
 * CATATAN TENTANG DUA METHOD YANG MENGEMBALIKAN MODEL:
 * saveSupportResult() dan saveAbg() diketik eksplisit sebagai
 * `: SupportResult` / `: AbgResult` sesuai kontrak, jadi keduanya mengembalikan
 * model Eloquent. Kontrak method LAINNYA tanpa kecuali mengembalikan array.
 * Controller yang memakai kedua method ini WAJIB mengubahnya menjadi array
 * sebelum dikirim ke Inertia (mis. `$result->toArray()` atau array chosen).
 */
class SupportService
{
    /**
     * Token pada kolom `value` yang berarti hasil belum keluar. phase1 memakai
     * status 'pending' pada array lokal, sedangkan skema database tidak punya
     * kolom status untuk SupportResult, jadi pencocokan token dilakukan di
     * sini. Kolom `numeric_value` yang terisi selalu berarti hasil sudah ada.
     *
     * @var array<int, string>
     */
    private const PENDING_MARKERS = ['pending', 'menunggu', 'diproses', 'belum', 'pending lab', '-'];

    /**
     * Batas kewajaran AGD. pH dan PaCO2 wajib diisi dan rentangnya persis
     * seperti saveAbg() phase1 (5-9 dan 10-150); parameter opsional diberi
     * batas longgar yang mengikuti rentang kritis di ABG_REF, hanya untuk
     * menangkap salah ketik.
     *
     * @var array<string, array{0: float|int, 1: float|int, 2: bool}>
     */
    private const ABG_RANGES = [
        'ph' => [5, 9, true],
        'pco2' => [10, 150, true],
        'po2' => [10, 700, false],
        'hco3' => [3, 60, false],
        'be' => [-35, 35, false],
        'sao2' => [30, 100, false],
        'fio2' => [21, 100, false],
    ];

    /**
     * Nama parameter AGD untuk pesan validasi.
     *
     * @var array<string, string>
     */
    private const ABG_LABELS = [
        'ph' => 'pH',
        'pco2' => 'PaCO2',
        'po2' => 'PaO2',
        'hco3' => 'HCO3',
        'be' => 'BE',
        'sao2' => 'SaO2',
        'fio2' => 'FiO2',
    ];

    /**
     * Hasil terbaru untuk tiap item pada satu kelompok, diurutkan mengikuti
     * urutan katalog prototype (LAB_CATALOG). Item yang tidak punya hasil
     * tidak muncul, karena tabel penunjang phase1 hanya menampilkan baris yang
     * benar-benar ada; panjang daftar isi tetap ditentukan katalog.
     *
     * @return array<int, array{key: string, label: string, value: string, numericValue: float|null, unit: string|null, flag: string, flagLabel: string, reference: string, resultedAt: string|null, resultedAtLabel: string, by: string}>
     */
    public function getGroup(Encounter $encounter, SupportGroup|string $group): array
    {
        $value = $group instanceof SupportGroup ? $group->value : trim($group);

        return $this->rowsFor($value, SupportResult::query()
            ->where('encounter_id', $encounter->getKey())
            ->where('group', $value)
            ->latestFirst()
            ->get(['result_key', 'value', 'numeric_value', 'unit', 'flag', 'reference', 'resulted_at', 'created_by']));
    }

    /**
     * Semua kelompok sekaligus dengan SATU kueri, lalu dipecah per kelompok.
     * AGD sengaja tidak termasuk; baca lewat getAbgRecords().
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function getAll(Encounter $encounter): array
    {
        $results = SupportResult::query()
            ->where('encounter_id', $encounter->getKey())
            ->latestFirst()
            ->get(['group', 'result_key', 'value', 'numeric_value', 'unit', 'flag', 'reference', 'resulted_at', 'created_by']);

        $buckets = [];

        foreach (SupportGroup::resultGroups() as $group) {
            $buckets[$group] = $results->where('group', $group)->values();
        }

        return [
            'lab' => $this->rowsFor('lab', $buckets['lab']),
            'blood' => $this->rowsFor('blood', $buckets['blood']),
            'micro' => $this->rowsFor('micro', $buckets['micro']),
            'rad' => $this->rowsFor('rad', $buckets['rad']),
        ];
    }

    /**
     * Riwayat AGD, TERBARU DI ATAS (getAbgRecords() phase1 mengurutkan
     * menaik lalu membalik). `flags` berisi daftar parameter yang di luar
     * rentang beserta levelnya, untuk kolom "Analisis".
     *
     * @return array<int, array{id: int, ph: float, pco2: float, po2: float, hco3: float, be: float|null, sao2: float|null, fio2: float|null, method: string, measuredAt: string|null, measuredAtLabel: string, by: string, abnormal: bool, flags: array<int, array{key: string, label: string, value: float, flag: string, flagLabel: string}>}>
     */
    public function getAbgRecords(Encounter $encounter): array
    {
        $rows = [];

        foreach ($this->abgQuery($encounter)->get() as $abg) {
            $values = [
                'ph' => $abg->ph,
                'pco2' => $abg->pco2,
                'po2' => $abg->po2,
                'hco3' => $abg->hco3,
                'be' => $abg->be,
                'sao2' => $abg->sao2,
                'fio2' => $abg->fio2,
            ];

            $cast = [];

            foreach ($values as $key => $value) {
                $cast[$key] = $value === null ? null : (float) $value;
            }

            $flags = [];

            foreach (ReferenceRange::abgFlags($cast) as $key => $flag) {
                $classified = ReferenceRange::classify($cast[$key], ReferenceRange::abg()[$key] ?? null);

                $flags[] = [
                    'key' => $key,
                    'label' => self::ABG_LABELS[$key] ?? $key,
                    'value' => (float) $cast[$key],
                    'flag' => $flag,
                    'flagLabel' => $classified['label'],
                ];
            }

            $at = $abg->measured_at;

            $rows[] = [
                'id' => (int) $abg->getKey(),
                'ph' => (float) $abg->ph,
                'pco2' => (float) $abg->pco2,
                'po2' => (float) $abg->po2,
                'hco3' => (float) $abg->hco3,
                'be' => $abg->be === null ? null : (float) $abg->be,
                'sao2' => $abg->sao2 === null ? null : (float) $abg->sao2,
                'fio2' => $abg->fio2 === null ? null : (float) $abg->fio2,
                'method' => ClinicalFormat::dash($abg->method),
                'measuredAt' => ClinicalFormat::iso($at),
                'measuredAtLabel' => ClinicalFormat::dateTimeLabel($at),
                'by' => ClinicalFormat::dash($abg->created_by),
                'abnormal' => $flags !== [],
                'flags' => $flags,
            ];
        }

        return $rows;
    }

    /**
     * AGD terbaru, atau null bila belum ada.
     *
     * @return array<string, mixed>|null
     */
    public function getLatestAbg(Encounter $encounter): ?array
    {
        return $this->getAbgRecords($encounter)[0] ?? null;
    }

    /**
     * Enam kartu statistik area penunjang, mengikuti statistik yang dihitung
     * renderStats() pada phase1: jumlah hasil lab + darah, jumlah yang
     * abnormal, kultur yang masih menunggu, jumlah radiologi, pH terakhir,
     * dan hari perawatan.
     *
     * `abgPh` bernilai float atau null (UI menampilkan '-' saat null);
     * `los` memakai accessor length_of_stay_days milik model Encounter.
     *
     * @return array{labCount: int, abnormalCount: int, culturePending: int, radiologyCount: int, abgPh: float|null, abgAt: string|null, abgAtLabel: string|null, los: int}
     */
    public function getSummary(Encounter $encounter): array
    {
        $results = SupportResult::query()
            ->where('encounter_id', $encounter->getKey())
            ->get(['group', 'result_key', 'numeric_value', 'value']);

        $labCount = 0;
        $abnormal = 0;

        foreach ($results as $result) {
            if (! in_array($result->group?->value, ['lab', 'blood'], true)) {
                continue;
            }

            $labCount++;

            $level = ReferenceRange::classify(
                $result->numeric_value,
                ReferenceRange::reference($result->result_key)
            )['level'];

            if (in_array($level, ReferenceRange::ABNORMAL_LEVELS, true)) {
                $abnormal++;
            }
        }

        $pending = 0;

        foreach ($results as $result) {
            if ($result->group?->value === 'micro' && $this->isPending($result)) {
                $pending++;
            }
        }

        $radiology = $results->filter(fn (SupportResult $row) => $row->group?->value === 'rad')->count();

        $latestAbg = $this->abgQuery($encounter)->first();
        $at = $latestAbg?->measured_at;

        return [
            'labCount' => $labCount,
            'abnormalCount' => $abnormal,
            'culturePending' => $pending,
            'radiologyCount' => (int) $radiology,
            'abgPh' => $latestAbg === null ? null : (float) $latestAbg->ph,
            'abgAt' => ClinicalFormat::iso($at),
            'abgAtLabel' => ClinicalFormat::dateTime($at),
            'los' => (int) $encounter->length_of_stay_days,
        ];
    }

    /**
     * Kultur mikrobiologi yang belum keluar, beserta lama tunggunya dalam
     * hari (phase1 memakai daysSince pada tanggal permintaan).
     *
     * @return array<int, array{id: int, key: string, label: string, value: string, at: string|null, atLabel: string, daysWaiting: int|null, by: string}>
     */
    public function getPending(Encounter $encounter): array
    {
        $rows = [];

        $results = SupportResult::query()
            ->where('encounter_id', $encounter->getKey())
            ->where('group', SupportGroup::MICRO->value)
            ->latestFirst()
            ->get(['id', 'result_key', 'value', 'numeric_value', 'resulted_at', 'created_by']);

        foreach ($results as $result) {
            if (! $this->isPending($result)) {
                continue;
            }

            $meta = ReferenceRange::meta($result->result_key);
            $at = $result->resulted_at;

            $rows[] = [
                'id' => (int) $result->getKey(),
                'key' => $meta['key'],
                'label' => $meta['name'],
                'value' => ClinicalFormat::dash($result->value),
                'at' => ClinicalFormat::iso($at),
                'atLabel' => ClinicalFormat::dateTimeLabel($at),
                'daysWaiting' => $this->daysSince($at),
                'by' => ClinicalFormat::dash($result->created_by),
            ];
        }

        return $rows;
    }

    /**
     * Hasil yang tidak dalam rentang referensi. Port renderAbnormal() phase1:
     * satu baris per nama item (pakai hasil TERBARU), kritis didahulukan.
     * Hanya kelompok lab dan darah, sama seperti prototype.
     *
     * @return array<int, array{key: string, label: string, value: string, numericValue: float|null, unit: string|null, reference: string, flag: string, flagLabel: string, resultedAt: string|null, resultedAtLabel: string}>
     */
    public function getAbnormal(Encounter $encounter): array
    {
        $rows = [];

        $results = SupportResult::query()
            ->where('encounter_id', $encounter->getKey())
            ->whereIn('group', [SupportGroup::LAB->value, SupportGroup::BLOOD->value])
            ->latestFirst()
            ->get(['result_key', 'value', 'numeric_value', 'resulted_at']);

        foreach ($results as $result) {
            $key = (string) $result->result_key;
            $reference = ReferenceRange::reference($key);

            if ($reference === null) {
                continue;
            }

            $level = ReferenceRange::classify($result->numeric_value, $reference)['level'];

            if (! in_array($level, ReferenceRange::ABNORMAL_LEVELS, true)) {
                continue;
            }

            $meta = ReferenceRange::meta($key);
            $at = $result->resulted_at;

            $rows[$meta['name']] = [
                'key' => $key,
                'label' => $meta['name'],
                'value' => ClinicalFormat::dash($result->value),
                'numericValue' => $result->numeric_value === null ? null : (float) $result->numeric_value,
                'unit' => ReferenceRange::unit($key),
                'reference' => ReferenceRange::referenceLabel($key),
                'flag' => $level,
                'flagLabel' => ReferenceRange::classify($result->numeric_value, $reference)['label'],
                'resultedAt' => ClinicalFormat::iso($at),
                'resultedAtLabel' => ClinicalFormat::dateTimeLabel($at),
            ];
        }

        $rows = array_values($rows);

        usort($rows, function (array $a, array $b) {
            $criticalA = $a['flag'] === ReferenceRange::LEVEL_CRITICAL ? 0 : 1;
            $criticalB = $b['flag'] === ReferenceRange::LEVEL_CRITICAL ? 0 : 1;

            return $criticalA <=> $criticalB;
        });

        return $rows;
    }

    /**
     * Deret tren untuk satu item hasil. Hanya baris yang punya `numeric_value`
     * yang bisa diplot; hasil kultur (teks) otomatis dilewati.
     *
     * Ketika $key null, item bawaan kelompok itu yang dipakai (leukosit
     * untuk lab, kreatinin untuk blood) - urutan pertama di trendTargets() phase1.
     *
     * `min` / `max` dilebarkan sampai memuat batas bawah/atas rentang referensi
     * bila keduanya bukan nol, persis seperti renderTrend() phase1, supaya garis
     * rentang normal ikut tergambar.
     *
     * @return array{key: string, label: string, unit: string|null, points: array<int, array{at: string, label: string, value: float}>, min: float|null, max: float|null}
     */
    public function getTrend(Encounter $encounter, string $group, ?string $key = null, int $limit = 20): array
    {
        $key ??= ReferenceRange::defaultTrendKey($group);

        if ($key === null) {
            return ['key' => '', 'label' => '', 'unit' => null, 'points' => [], 'min' => null, 'max' => null];
        }

        $meta = ReferenceRange::meta($key);
        $reference = ReferenceRange::reference($key);

        $results = SupportResult::query()
            ->where('encounter_id', $encounter->getKey())
            ->where('group', $group)
            ->where('result_key', $key)
            ->whereNotNull('numeric_value')
            ->latestFirst()
            ->limit(max(1, $limit))
            ->get(['numeric_value', 'resulted_at'])
            ->reverse();

        $points = [];
        $values = [];

        foreach ($results as $result) {
            $value = (float) $result->numeric_value;
            $values[] = $value;
            $at = $result->resulted_at;

            $points[] = [
                'at' => ClinicalFormat::iso($at) ?? ClinicalFormat::EMPTY,
                'label' => ClinicalFormat::chartLabel($at),
                'value' => $value,
            ];
        }

        $min = $values === [] ? null : min($values);
        $max = $values === [] ? null : max($values);

        if ($min !== null && $max !== null && $reference !== null) {
            if ((float) $reference['low'] !== 0.0) {
                $min = min($min, (float) $reference['low']);
            }

            if ((float) $reference['high'] !== 0.0) {
                $max = max($max, (float) $reference['high']);
            }

            if ($max - $min < 0.001) {
                $max = $min + 1;
            }
        }

        return [
            'key' => $key,
            'label' => $meta['name'],
            'unit' => ReferenceRange::unit($key),
            'points' => $points,
            'min' => $min,
            'max' => $max,
        ];
    }

    /**
     * Simpan satu hasil penunjang generik (lab / darah / mikrobiologi /
     * radiologi). Fungsi-fungsi di phase1 menolak nilai kosong dengan pesan
     * "Nilai harus berupa angka."; validasi yang sama diterapkan di sini.
     *
     * Kolom yang diisi otomatis dari ReferenceRange bila tidak dikirim:
     * `numeric_value` (dari `value` bila angka), `unit`, `flag`, `reference`.
     *
     * SIKIAT NILAI BERBEDA PER KELOMPOK, ini disengaja:
     *  - lab / blood  -> WAJIB angka, persis seperti saveSupportResult() phase1
     *                    yang menolak teks dengan "Nilai harus berupa angka."
     *  - micro / rad  -> teks bebas WAJIB, numeric_value boleh null. Hasil
     *                    kultur ("Escherichia coli > 10^5 CFU/mL") dan
     *                    temuan radiologi memang teks, dan kolom `value`
     *                    didesain untuk keduanya (lihat migration
     *                    create_support_results_table). Tanpa percabangan ini
     *                    kelompok mikrobiologi dan radiologi tidak akan pernah
     *                    bisa ditulis lewat service ini.
     *
     * CATATAN: kontrak method ini diketik eksplisit mengembalikan SupportResult,
     * bukan array. Lihat catatan pada docblock kelas.
     *
     * @param  array<string, mixed>  $data  key/value/numericValue/unit/flag/reference/resultedAt/by
     *
     * @throws ValidationException
     */
    public function saveSupportResult(Encounter $encounter, string $group, array $data, User $user): SupportResult
    {
        $groupValue = SupportGroup::tryFrom(trim($group));

        if ($groupValue === null || ! in_array($groupValue->value, SupportGroup::resultGroups(), true)) {
            throw ValidationException::withMessages([
                'group' => 'Kelompok penunjang tidak dikenal.',
            ]);
        }

        $key = trim((string) ($data['key'] ?? $data['result_key'] ?? ''));
        $raw = $data['value'] ?? null;
        $textual = in_array($groupValue->value, [SupportGroup::MICRO->value, SupportGroup::RAD->value], true);

        $errors = [];

        if ($key === '') {
            $errors['key'] = 'Item wajib dipilih.';
        }

        $numeric = ClinicalFormat::numeric($raw);
        $blank = $raw === null || trim((string) $raw) === '';

        if ($textual) {
            if ($blank) {
                $errors['value'] = 'Nilai wajib diisi.';
            }
        } elseif ($blank || $numeric === null) {
            $errors['value'] = 'Nilai harus berupa angka.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $reference = ReferenceRange::reference($key);
        $resultedAt = ClinicalFormat::parse($data['resultedAt'] ?? $data['resulted_at'] ?? $data['at'] ?? null) ?? now();

        return SupportResult::query()->create([
            'encounter_id' => $encounter->getKey(),
            'group' => $groupValue->value,
            'result_key' => $key,
            'value' => (string) $raw,
            'numeric_value' => $numeric,
            'unit' => $data['unit'] ?? ($reference['unit'] ?? null),
            'flag' => $data['flag'] ?? ReferenceRange::classify($numeric, $reference)['level'],
            'reference' => $data['reference'] ?? ReferenceRange::referenceLabel($key),
            'resulted_at' => $resultedAt,
            'created_by' => trim((string) ($data['by'] ?? $data['created_by'] ?? '')) !== ''
                ? (string) $data['by']
                : $user->name,
        ]);
    }

    /**
     * Hapus hasil TERBARU untuk satu kunci pada satu kelompok, mengembalikan
     * true bila ada baris yang benar-benar terhapus.
     */
    public function deleteSupportResult(Encounter $encounter, string $group, string $key): bool
    {
        $groupValue = SupportGroup::tryFrom(trim($group));

        if ($groupValue === null || ! in_array($groupValue->value, SupportGroup::resultGroups(), true)) {
            return false;
        }

        $target = SupportResult::query()
            ->where('encounter_id', $encounter->getKey())
            ->where('group', $groupValue->value)
            ->where('result_key', trim($key))
            ->latestFirst()
            ->first();

        if ($target === null) {
            return false;
        }

        return (bool) $target->delete();
    }

    /**
     * Simpan satu hasil AGD.
     *
     * pH dan PaCO2 wajib diisi (sesuai saveAbg() phase1), parameter lain
     * opsional. Rentang pH 5-9 dan PaCO2 10-150 sama persis dengan
     * prototype; parameter opsional memakai batas kewajaran yang lebih longgar
     * dan melempar ValidationException bila dilanggar.
     *
     * CATATAN: kontrak method ini diketik eksplisit mengembalikan AbgResult,
     * bukan array. Lihat catatan pada docblock kelas.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function saveAbg(Encounter $encounter, array $data, User $user): AbgResult
    {
        $errors = [];
        $values = [];

        foreach (self::ABG_RANGES as $field => [$min, $max, $required]) {
            $raw = $data[$field] ?? $data[strtoupper($field)] ?? null;
            $number = ClinicalFormat::numeric($raw);

            if ($number === null) {
                if ($required) {
                    $errors[$field] = self::ABG_LABELS[$field].' wajib diisi.';
                }

                continue;
            }

            if ($number < $min || $number > $max) {
                $errors[$field] = self::ABG_LABELS[$field].' di luar rentang wajar ('
                    .ClinicalFormat::number($min).' - '.ClinicalFormat::number($max).').';
            }

            $values[$field] = $number;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $method = trim((string) ($data['method'] ?? ''));

        return AbgResult::query()->create([
            'encounter_id' => $encounter->getKey(),
            'ph' => $values['ph'],
            'pco2' => $values['pco2'],
            'po2' => $values['po2'] ?? 0,
            'hco3' => $values['hco3'] ?? 0,
            'be' => $values['be'] ?? null,
            'sao2' => $values['sao2'] ?? null,
            'fio2' => $values['fio2'] ?? null,
            'method' => $method !== '' ? $method : 'Arteri',
            'measured_at' => ClinicalFormat::parse($data['measuredAt'] ?? $data['measured_at'] ?? $data['at'] ?? null) ?? now(),
            'created_by' => trim((string) ($data['by'] ?? $data['created_by'] ?? '')) !== ''
                ? (string) $data['by']
                : $user->name,
        ]);
    }

    /**
     * Hapus satu hasil AGD milik episode ini saja.
     */
    public function deleteAbg(Encounter $encounter, int $id): bool
    {
        $abg = $this->abgQuery($encounter)->whereKey($id)->first();

        if ($abg === null) {
            return false;
        }

        return (bool) $abg->delete();
    }

    /**
     * Kueri AGD yang selalu newest-first.
     *
     * @return Builder<AbgResult>
     */
    private function abgQuery(Encounter $encounter)
    {
        return AbgResult::query()
            ->where('encounter_id', $encounter->getKey())
            ->latestFirst();
    }

    /**
     * Bentuk baris hasil penunjang, diurutkan mengikuti katalog prototype.
     *
     * `flag` dihitung ulang dari rentang referensi (bukan dibaca dari kolom
     * `flag`), karena ReferenceRange adalah satu-satunya sumber kebenaran
     * klasifikasi. Kolom `flag` pada database dipakai sebagai cadangan ketika
     * itemnya tidak punya rentang referensi.
     *
     * @param  Collection<int, SupportResult>  $results
     * @return array<int, array<string, mixed>>
     */
    private function rowsFor(string $group, $results): array
    {
        $latest = [];

        foreach ($results as $result) {
            $key = (string) $result->result_key;

            if (! isset($latest[$key])) {
                $latest[$key] = $result;
            }
        }

        $ordered = [];

        foreach (array_keys($latest) as $key) {
            $ordered[] = [
                'position' => ReferenceRange::catalogPosition($group, $key),
                'result' => $latest[$key],
            ];
        }

        usort($ordered, fn (array $a, array $b) => $a['position'] <=> $b['position']);

        $rows = [];

        foreach ($ordered as $entry) {
            /** @var SupportResult $result */
            $result = $entry['result'];
            $key = (string) $result->result_key;
            $meta = ReferenceRange::meta($key);
            $reference = ReferenceRange::reference($key);

            $classified = ReferenceRange::classify($result->numeric_value, $reference);
            $flag = $classified['level'];

            if ($flag === ReferenceRange::LEVEL_NONE && $reference === null) {
                $flag = (string) ($result->flag ?: ReferenceRange::LEVEL_NONE);
            }

            $at = $result->resulted_at;

            $rows[] = [
                'key' => $key,
                'label' => $meta['name'],
                'value' => ClinicalFormat::dash($result->value),
                'numericValue' => $result->numeric_value === null ? null : (float) $result->numeric_value,
                'unit' => $result->unit ?: ReferenceRange::unit($key),
                'flag' => $flag,
                'flagLabel' => $classified['label'],
                'reference' => $result->reference ?: ReferenceRange::referenceLabel($key),
                'resultedAt' => ClinicalFormat::iso($at),
                'resultedAtLabel' => ClinicalFormat::dateTimeLabel($at),
                'by' => ClinicalFormat::dash($result->created_by),
            ];
        }

        return $rows;
    }

    /**
     * Hasil kultur dianggap masih berjalan bila tidak punya angka dan nilainya
     * termasuk salah satu penanda "pending" (phase1 memakai status 'pending').
     */
    private function isPending(SupportResult $result): bool
    {
        if ($result->numeric_value !== null) {
            return false;
        }

        $value = mb_strtolower(trim((string) $result->value));

        foreach (self::PENDING_MARKERS as $marker) {
            if ($value === $marker) {
                return true;
            }
        }

        return false;
    }

    private function daysSince(mixed $value): ?int
    {
        $moment = ClinicalFormat::parse($value);

        if ($moment === null) {
            return null;
        }

        return (int) $moment->startOfDay()->diffInDays(now()->startOfDay());
    }
}

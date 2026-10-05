<?php

namespace App\Services;

use App\Enums\MedicationCategory;
use App\Models\Asmed;
use App\Models\Encounter;
use App\Models\Observation;
use App\Models\ObservationMedication;
use App\Services\Support\ClinicalFormat;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Rekapitulasi pemberian obat / cairan: daftar administrasi, kartu statistik
 * farmasi, drip aktif, balance cairan, komposisi kategori, deteksi konflik
 * alergi, pemeriksaan rencana terapi ASMED, dan formularium unit.
 *
 * Port dari phase1:
 *   getMedicationAdministrations() -> getAdministrations()
 *   getActiveMedications()         -> getActiveDrips()  (dibatasi 2 kategori)
 *   getMedicationSummary()         -> getSummary()
 *   renderFluids()                 -> getFluidBalance()
 *   renderCategoryBars()           -> getCategoryDistribution()
 *   renderAllergyPanel()           -> getAllergyConflicts()
 *   PLAN_CHECKS + findPlanFragment()-> getTherapyPlanChecks()
 *   buildFormularium()             -> getFormularium()
 *
 * URUTAN BARIS: seperti ObservationService, baris returned TERBARU DI ATAS.
 * phase1 berjalan kronologis lalu `reverse()`, dan itulah yang dirender tabel
 * farmasi ("terbaru di atas"). `getFluidBalance()` menjadi pengecualian yang
 * disengaja karena hasilnya memang deret waktu untuk grafik.
 */
class MedicationRecapService
{
    /**
     * Batas aman baris administrasi obat per permintaan.
     *
     * Satu encounter ICU menumpuk satu baris untuk tiap pemberian, jadi tanpa
     * batas daftar ini bisa tumbuh tanpa batas pada rawat inap yang lama. Nilai
     * ini jauh di atas kasus nyata (sekitar 40 baris pada seed) sehingga tidak
     * mengubah apa pun yang terlihat, tapi menutup jalur tanpa batas.
     */
    public const MAX_ADMINISTRATION_ROWS = 1000;

    /**
     * Dua kategori yang isinya "drip / obat aktif" pada fase1: infus
     * kontinu dan sedasi. Charge ini disaring karena kedua kategori
     * itulah satu-satunya obat yang diberikan terus-menerus.
     *
     * @var array<int, string>
     */
    private const DRIP_CATEGORIES = [
        MedicationCategory::INOTROPIK_VASOPRESOR->value,
        MedicationCategory::SEDASI_ANALGESIA->value,
    ];

    /**
     * Daftar koreksi pemberian obat, TERBARU DI ATAS.
     *
     * FILTER yang didukung: category (nilai MedicationCategory), search
     * (nama obat / dosis / petugas), dateFrom, dateTo.
     *
     * @param  array<string, mixed>  $filters
     * @param  int|null  $limit  Batas baris; null berarti self::MAX_ADMINISTRATION_ROWS.
     * @return array<int, array<string, mixed>>
     */
    public function getAdministrations(Encounter $encounter, array $filters = [], ?int $limit = null): array
    {
        $rows = [];

        $query = $this->administrationQuery($encounter, $filters)->limit(max(1, $limit ?? self::MAX_ADMINISTRATION_ROWS));

        foreach ($query->get() as $medication) {
            $rows[] = $this->rowFrom($medication);
        }

        return $rows;
    }

    /**
     * Enam kartu statistik farmasi.
     *
     * `categoryCount` menghitung kategori yang benar-benar punya baris, sama
     * seperti `Object.keys(byCategory).length` pada prototype. Bandingkan
     * dengan getCategoryDistribution() yang selalu mengembalikan 6 kategori.
     *
     * @return array{
     *     total: int,
     *     uniqueDrugs: int,
     *     totalVolume: float,
     *     activeDrips: int,
     *     categoryCount: int,
     *     lastGivenAt: string|null,
     *     lastGivenAtLabel: string|null,
     *     lastGivenBy: string|null
     * }
     */
    public function getSummary(Encounter $encounter): array
    {
        $medications = $this->administrationQuery($encounter)->get();

        $unique = [];
        $drips = [];
        $categories = [];
        $volume = 0.0;
        $latest = null;

        foreach ($medications as $medication) {
            $name = (string) $medication->name;
            $key = mb_strtolower($name);

            $unique[$key] = true;
            $categories[$medication->category?->value ?? MedicationCategory::LAINNYA->value] = true;

            $amount = (float) $medication->volume;
            $volume += $amount;

            if (in_array($medication->category?->value, self::DRIP_CATEGORIES, true)) {
                $drips[$key] = true;
            }

            $latest ??= $medication;
        }

        $at = $latest?->observation?->recorded_on;

        return [
            'total' => count($medications),
            'uniqueDrugs' => count($unique),
            'totalVolume' => round($volume, 1),
            'activeDrips' => count($drips),
            'categoryCount' => count($categories),
            'lastGivenAt' => ClinicalFormat::iso($at),
            'lastGivenAtLabel' => ClinicalFormat::dateTime($at),
            'lastGivenBy' => $latest?->observation?->recorded_by,
        ];
    }

    /**
     * Drip / obat aktif: satu baris per nama obat (case-insensitive) pada
     * kategori Inotropik / Vasopressor dan Sedasi & Analgesia, baris
     * TERBARU yang menang. Bentuk baris sama dengan getAdministrations() agar
     * komponen kartu farmasi bisa dipakai ulang.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getActiveDrips(Encounter $encounter): array
    {
        $filters = ['category' => self::DRIP_CATEGORIES];

        $rows = [];

        foreach ($this->administrationQuery($encounter, $filters)->get() as $medication) {
            $rows[mb_strtolower((string) $medication->name)] = $this->rowFrom($medication);
        }

        return array_values($rows);
    }

    /**
     * Balance cairan.
     *
     * `intake` / `output` / `balance` dijumlahkan untuk observasi dalam
     * jendela $hours terakhir berdasarkan jam dinding (sekarang), sedangkan
     * `cumulativeBalance` adalah total SELURUHepisode - itu yang ditampilkan
     * sebagai "kumulatif selama perawatan" di farmasi.html.
     *
     * `rows` returned dari LAMA ke BARU supaya langsung bisa diplot.
     *
     * @return array{hours: int, intake: float, output: float, balance: float, cumulativeBalance: float, rows: array<int, array{at: string, label: string, intake: float, output: float, balance: float}>}
     */
    public function getFluidBalance(Encounter $encounter, int $hours = 24): array
    {
        $hours = max(1, $hours);
        $since = now()->subHours($hours);

        $observations = Observation::query()
            ->where('encounter_id', $encounter->getKey())
            ->where(function (Builder $query) use ($since) {
                $query->where('observation_date', '>=', $since->format('Y-m-d'))
                    ->orWhere(function (Builder $inner) use ($since) {
                        $inner->where('observation_date', $since->format('Y-m-d'))
                            ->where('observation_time', '>=', $since->format('H:i'));
                    });
            })
            ->orderBy('observation_date')
            ->orderBy('observation_time')
            ->get(['observation_date', 'observation_time', 'intake', 'output']);

        $rows = [];
        $intake = 0.0;
        $output = 0.0;

        foreach ($observations as $observation) {
            $in = $observation->intake === null ? 0.0 : (float) $observation->intake;
            $out = $observation->output === null ? 0.0 : (float) $observation->output;

            $intake += $in;
            $output += $out;

            $at = $observation->recorded_on;

            $rows[] = [
                'at' => ClinicalFormat::iso($at) ?? ClinicalFormat::EMPTY,
                'label' => ClinicalFormat::chartLabel($at),
                'intake' => round($in, 1),
                'output' => round($out, 1),
                'balance' => round($in - $out, 1),
            ];
        }

        $cumulative = Observation::query()
            ->where('encounter_id', $encounter->getKey())
            ->selectRaw('COALESCE(SUM(intake), 0) as total_in, COALESCE(SUM(output), 0) as total_out')
            ->first();

        return [
            'hours' => $hours,
            'intake' => round($intake, 1),
            'output' => round($output, 1),
            'balance' => round($intake - $output, 1),
            'cumulativeBalance' => round((float) $cumulative->total_in - (float) $cumulative->total_out, 1),
            'rows' => $rows,
        ];
    }

    /**
     * Komposisi volume per kategori obat, SELURUH 6 kategori dalam urutan
     * App\Enums\MedicationCategory (termasuk yang volumenya nol) karena grafik
     * batang farmasi.html mengurutkan berdasarkan urutan itu.
     *
     * `percent` memakai total seluruh 6 kategori; `count` adalah jumlah baris
     * pemberian pada kategori itu (bukan jumlah obat unik).
     *
     * @return array<int, array{category: string, categoryLabel: string, volume: float, percent: float, count: int}>
     */
    public function getCategoryDistribution(Encounter $encounter): array
    {
        $totals = $this->administrationQuery($encounter)
            ->selectRaw('category, COUNT(*) as row_count, COALESCE(SUM(volume), 0) as total_volume')
            ->groupBy('category')
            ->get()
            ->keyBy(fn ($row) => $row->category);

        $sum = 0.0;

        foreach (MedicationCategory::cases() as $category) {
            $sum += (float) ($totals[$category->value]->total_volume ?? 0);
        }

        $rows = [];

        foreach (MedicationCategory::cases() as $category) {
            $row = $totals[$category->value] ?? null;
            $volume = (float) ($row->total_volume ?? 0);

            $rows[] = [
                'category' => $category->value,
                'categoryLabel' => $category->label(),
                'volume' => round($volume, 1),
                'percent' => $sum > 0 ? round($volume / $sum * 100, 1) : 0.0,
                'count' => (int) ($row->row_count ?? 0),
            ];
        }

        // Persentase dikoreksi pada baris terakhir supaya jumlahannya tepat
        // 100 setelah pembulatan satu desimal.
        $drift = round(100.0 - array_sum(array_column($rows, 'percent')), 1);

        if ($drift !== 0.0 && $rows !== []) {
            $last = array_key_last($rows);
            $rows[$last]['percent'] = round($rows[$last]['percent'] + $drift, 1);
        }

        return $rows;
    }

    /**
     * Skrining konflik obat terhadap alergi pasien.
     *
     * Port renderAllergyPanel() farmasi.html. Alergi dipecah menjadi token
     * dengan pemisah koma, titik koma, garis miring, garis tegak, atau kata
     * "dan", lalu setiap token dicocokkan sebagai SUBSTRING dari nama obat
     * yang diberikan (case-insensitive). Baris yang cocok dikelompokkan per
     * nama obat.
     *
     * `allergies` mengembalikan token yang berhasil diurai; array kosong
     * berarti tidak ada alergi yang bisa diperiksa, dan `hasConflict` otomatis
     * false.
     *
     * @return array{
     *     hasConflict: bool,
     *     allergies: array<int, string>,
     *     conflicts: array<int, array{id: int, name: string, matchedAllergy: string, rows: array<int, array<string, mixed>>}>
     * }
     */
    public function getAllergyConflicts(Encounter $encounter): array
    {
        $patient = $encounter->relationLoaded('patient') ? $encounter->getRelation('patient') : $encounter->patient()->first();
        $raw = trim((string) ($patient->allergies ?? ''));

        $emptyAllergy = $raw === ''
            || preg_match('/^(tidak ada|n\/?a|tdk ada|unknown)$/i', $raw) === 1;

        $tokens = $emptyAllergy ? [] : ClinicalFormat::splitTokens($raw);

        $conflicts = [];

        if ($tokens !== []) {
            foreach ($this->administrationQuery($encounter)->get() as $medication) {
                $name = (string) $medication->name;
                $haystack = mb_strtolower($name);
                $matched = [];

                foreach ($tokens as $token) {
                    if ($token === '' || mb_strtolower($token) === '') {
                        continue;
                    }

                    if (str_contains($haystack, mb_strtolower($token))) {
                        $matched[] = $token;
                    }
                }

                if ($matched === []) {
                    continue;
                }

                $key = mb_strtolower($name);
                $row = $this->rowFrom($medication);

                if (! isset($conflicts[$key])) {
                    $conflicts[$key] = [
                        'id' => (int) $medication->getKey(),
                        'name' => $name,
                        'matchedAllergy' => $matched[0],
                        'rows' => [],
                    ];
                }

                $conflicts[$key]['matchedAllergy'] = implode(', ', array_unique(array_merge(
                    explode(', ', $conflicts[$key]['matchedAllergy']),
                    $matched
                )));

                $conflicts[$key]['rows'][] = $row;
            }
        }

        return [
            'hasConflict' => $conflicts !== [],
            'allergies' => $tokens,
            'conflicts' => array_values($conflicts),
        ];
    }

    /**
     * Empat pemeriksaan rencana terapi dari config('formularium.plan_checks').
     * Teks ASMED `plan` dicocokkan dengan regex pada konfigurasi; `evidence`
     * berisi kalimat pertama yang memuat pola tersebut (potongan yang sama
     * dengan findPlanFragment() phase1) agar UI bisa mengutip sumbernya.
     *
     * @return array<int, array{key: string, label: string, inPlan: bool, evidence: string|null}>
     */
    public function getTherapyPlanChecks(Encounter $encounter): array
    {
        $plan = (string) ($this->asmed($encounter)?->plan ?? '');
        $rows = [];

        foreach ((array) config('formularium.plan_checks', []) as $key => $check) {
            $evidence = ClinicalFormat::planFragment($plan, (string) ($check['pattern'] ?? ''));

            $rows[] = [
                'key' => (string) $key,
                'label' => MedicationCategory::tryFrom((string) $key)?->label() ?? (string) $key,
                'inPlan' => $evidence !== null,
                'evidence' => $evidence,
            ];
        }

        return $rows;
    }

    /**
     * Formularium obat terjadwal unit.
     *
     * Daftar disusun dari config('formularium'): item bersyarat yang
     * plan_keywords-nya ditemukan di `asmeds.plan` lebih dulu, baru item yang
     * selalu dijadwalkan. Urutan ini mengikuti buildFormularium() phase1,
     * yang menaruh Norepinefrin dan Antibiotik Empiris sebelum Midazolam dan
     * Meropenem.
     *
     * `givenCount` memakai aturan `realization` per item:
     *   - 'category'       -> jumlah pemberian pada kategori obat yang sama
     *   - 'name_contains'  -> jumlah pemberian yang nama obatnya mengandung
     *                         salah satu `match_keys`
     * Persis seperti countRealizations() pada prototype.
     *
     * @return array<int, array{name: string, dose: string, category: string, categoryLabel: string, always: bool, inPlan: bool, givenCount: int, realizationLabel: string}>
     */
    public function getFormularium(Encounter $encounter): array
    {
        $plan = Str::lower((string) ($this->asmed($encounter)?->plan ?? ''));
        $medications = $this->administrationQuery($encounter)->get(['name', 'category']);
        $rows = [];

        $conditional = (array) config('formularium.conditionally_scheduled', []);

        foreach ($conditional as $entry) {
            $matched = $this->matchesKeywords($plan, (array) ($entry['plan_keywords'] ?? []));

            if (! $matched) {
                continue;
            }

            $rows[] = $this->formulariumRow($entry, $medications, false, true);
        }

        foreach ((array) config('formularium.always_scheduled', []) as $entry) {
            $rows[] = $this->formulariumRow($entry, $medications, true, true);
        }

        return $rows;
    }

    /**
     * Inferensi kategori obat dari nama, port inferMedicationCategory()
     * observasi.html. Pola dibaca dari config('formularium.category_patterns')
     * dan dievaluasi berurutan; yang pertama cocok menang, tidak ada yang
     * cocok berarti 'Lainnya'.
     */
    public static function inferCategory(?string $name): string
    {
        $text = Str::lower(trim((string) $name));

        if ($text === '') {
            return (string) config('formularium.default_category', MedicationCategory::LAINNYA->value);
        }

        foreach ((array) config('formularium.category_patterns', []) as $rule) {
            if (preg_match('/'.(string) ($rule['pattern'] ?? '').'/i', $text) === 1) {
                return (string) $rule['category'];
            }
        }

        return (string) config('formularium.default_category', MedicationCategory::LAINNYA->value);
    }

    /**
     * Kueri dasar: seluruh koreksi pemberian obat pada satu episode, dengan
     * relasi observasi ikut dimuat agar tidak terjadi N+1 saat membentuk baris.
     * Urutan terbaru-di-atas mengikuti tabel farmasi pada phase1.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<ObservationMedication>
     */
    private function administrationQuery(Encounter $encounter, array $filters = []): Builder
    {
        $query = ObservationMedication::query()
            ->whereHas('observation', fn (Builder $observation) => $observation->where('encounter_id', $encounter->getKey()))
            ->with('observation:id,encounter_id,observation_date,observation_time,recorded_by,recorded_at')
            ->orderByDesc('observation_id')
            ->orderByDesc('sort_order')
            ->orderByDesc('id');

        $category = $filters['category'] ?? $filters['kategori'] ?? null;

        if (is_string($category) && trim($category) !== '' && $category !== 'all') {
            $query->where('category', trim($category));
        } elseif (is_array($category) && $category !== []) {
            $query->whereIn('category', $category);
        }

        $search = $filters['search'] ?? $filters['q'] ?? null;

        if (is_string($search) && trim($search) !== '') {
            $like = '%'.trim($search).'%';

            $query->where(function (Builder $q) use ($like) {
                $q->where('name', 'like', $like)
                    ->orWhere('dose', 'like', $like)
                    ->orWhereHas(
                        'observation',
                        fn (Builder $o) => $o->where('recorded_by', 'like', $like)
                    );
            });
        }

        $dateFrom = $filters['dateFrom'] ?? $filters['date_from'] ?? null;

        if (is_string($dateFrom) && trim($dateFrom) !== '') {
            $query->whereHas('observation', fn (Builder $o) => $o->whereDate('observation_date', '>=', trim($dateFrom)));
        }

        $dateTo = $filters['dateTo'] ?? $filters['date_to'] ?? null;

        if (is_string($dateTo) && trim($dateTo) !== '') {
            $query->whereHas('observation', fn (Builder $o) => $o->whereDate('observation_date', '<=', trim($dateTo)));
        }

        return $query;
    }

    /**
     * Bentuk baris administrasi, sama persis dengan yang dikembalikan
     * getAdministrations() supaya getActiveDrips() memakai DTO yang sama.
     *
     * @return array<string, mixed>
     */
    private function rowFrom(ObservationMedication $medication): array
    {
        $observation = $medication->observation;
        $at = $observation?->recorded_on;
        $category = $medication->category;

        return [
            'id' => (int) $medication->getKey(),
            'observationId' => (int) $medication->observation_id,
            'observationDate' => $observation?->observation_date?->format('Y-m-d'),
            'observationTime' => $observation?->observation_time?->format('H:i'),
            'recordedAt' => ClinicalFormat::iso($at),
            'recordedAtLabel' => ClinicalFormat::dateTimeLabel($at),
            'name' => (string) $medication->name,
            'dose' => ClinicalFormat::dash($medication->dose),
            'category' => $category?->value ?? MedicationCategory::LAINNYA->value,
            'categoryLabel' => $category?->label() ?? MedicationCategory::LAINNYA->label(),
            'volume' => $medication->volume === null ? 0.0 : (float) $medication->volume,
            'status' => ClinicalFormat::dash($medication->status),
            'route' => $medication->route,
            'recordedBy' => ClinicalFormat::dash($observation?->recorded_by),
        ];
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  Collection<int, ObservationMedication>  $medications
     * @return array<string, mixed>
     */
    private function formulariumRow(array $entry, $medications, bool $always, bool $inPlan): array
    {
        $category = (string) ($entry['category'] ?? config('formularium.default_category'));
        $realization = (string) ($entry['realization'] ?? 'name_contains');
        $matchKeys = array_map('mb_strtolower', (array) ($entry['match_keys'] ?? []));

        $count = 0;

        foreach ($medications as $medication) {
            if ($realization === 'category') {
                if (($medication->category?->value ?? null) === $category) {
                    $count++;
                }

                continue;
            }

            $name = mb_strtolower((string) $medication->name);

            foreach ($matchKeys as $key) {
                if ($key !== '' && str_contains($name, $key)) {
                    $count++;
                    break;
                }
            }
        }

        return [
            'name' => (string) ($entry['name'] ?? ClinicalFormat::EMPTY),
            'dose' => (string) ($entry['dose'] ?? ClinicalFormat::EMPTY),
            'category' => $category,
            'categoryLabel' => MedicationCategory::tryFrom($category)?->label() ?? $category,
            'always' => $always,
            'inPlan' => $inPlan,
            'givenCount' => $count,
            'realizationLabel' => $count > 0
                ? ClinicalFormat::number($count).'× diberikan'
                : 'Belum tercatat',
        ];
    }

    /**
     * @param  array<int, string>  $keywords
     */
    private function matchesKeywords(string $lowerPlan, array $keywords): bool
    {
        foreach ($keywords as $keyword) {
            $keyword = mb_strtolower(trim((string) $keyword));

            if ($keyword !== '' && str_contains($lowerPlan, $keyword)) {
                return true;
            }
        }

        return false;
    }

    private function asmed(Encounter $encounter): ?Asmed
    {
        if ($encounter->relationLoaded('asmed')) {
            return $encounter->getRelation('asmed');
        }

        return $encounter->asmed()->first();
    }
}

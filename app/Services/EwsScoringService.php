<?php

namespace App\Services;

use App\Enums\ConsciousnessLevel;
use App\Enums\EwsRiskLevel;
use App\Services\Support\ClinicalFormat;

/**
 * SATU-SATUNYA sumber kebenaran untuk skor EWS.
 *
 * Skala yang dipakai aplikasi ini adalah BRITISH EARLY WARNING SCALE 4 TINGKAT,
 * BUKAN MEWS 3-tingkat standar. Don't "fix" tabel ke MEWS saat ditinjau.
 *
 * Port byte-faithful dari phase1/app-context.js:
 *   EWS_TABLE        -> config('ews.parameters')
 *   ewsScoreRange()  -> EwsScoringService::scoreParameter()
 *   hitungEWS()      -> EwsScoringService::calculate()
 *   ewsKategori()    -> EwsScoringService::riskFrom() + riskLabel()
 *   ewsRiskLevel()   -> EwsScoringService::riskFrom()
 *   ewsBadgeClasses()-> EwsScoringService::badgeClasses()
 *
 * ============================ PERILAKU CELAH BAND ============================
 * Rentang band pada parameter `Temp` di prototype SENGAJA memiliki celah:
 * 35.0-35.1, 36.0-36.1, 38.0-38.1, dan 41.0-41.1. Nilai yang jatuh di dalam
 * celah tersebut TIDAK cocok dengan band mana pun, jadi ewsScoreRange() mengembalikan
 * null dan hitungEWS() menjumlahkan null sebagai 0. Artinya:
 *
 *     suhu 35.05 -> skor 0, suhu 36.05 -> skor 0
 *     suhu 38.05 -> skor 0, suhu 41.05 -> skor 0
 *
 * Ini bukan bug yang boleh "dirapikan". Tabel di bawah disalin apa adanya dari
 * phase1/observasi.html (blok data-purpose="ews-config") dan
 * phase1/app-context.js. Field `inGap` pada tiap komponen menandai nilai
 * numerik yang jatuh ke celah, sehingga UI bisa membedakan "tidak diisi"
 * dari "terisi tapi jatuh celah" - keduanya sama-sama bernilai 0.
 * ===========================================================================
 */
class EwsScoringService
{
    /**
     * Kunci input yang diterima untuk setiap parameter, baik snake_case
     * (payload Laravel) maupun nama phase1. Pencocokan_keys bersifat
     * case-insensitive sebagai jaring pengaman.
     *
     * @var array<string, array<int, string>>
     */
    private const INPUT_ALIASES = [
        'RR' => ['rr', 'respirasi', 'freq'],
        'HR' => ['hr', 'nadi', 'pulse'],
        'SBP' => ['sys', 'sbp', 'sistolik', 'map_sistolik'],
        'SpO2' => ['spo2', 'sp_o2', 'saturasi'],
        'Temp' => ['suhu', 'temp', 'temperatur', 'temperature'],
        'Kesadaran' => ['kesadaran', 'kesadarankey', 'awareness', 'consciousness'],
    ];

    /**
     * Set kelas Tailwind untuk badge EWS, hasil port ewsBadgeClasses().
     * Urutan kunci dan isi string sama persis dengan prototype:
     * "bg-* text-* border-*".
     *
     * @var array<string, array<int, string>>
     */
    private const BADGE_CLASSES = [
        EwsRiskLevel::EMERGENCY->value => ['bg-red-600', 'text-white', 'border-red-700'],
        EwsRiskLevel::HIGH->value => ['bg-red-100', 'text-red-800', 'border-red-200'],
        EwsRiskLevel::MEDIUM->value => ['bg-amber-100', 'text-amber-800', 'border-amber-200'],
        EwsRiskLevel::LOW->value => ['bg-emerald-100', 'text-emerald-800', 'border-emerald-200'],
    ];

    private const FALLBACK_BADGE_CLASSES = ['bg-slate-100', 'text-slate-700', 'border-slate-200'];

    /**
     * Nilai risk yang dipakai ketika belum ada observasi sama sekali.
     * phase1 ewsRiskLevel(null) memakai string 'none'.
     */
    public const RISK_NONE = 'none';

    /**
     * Total maksimum 6 parameter x skor 3 = 18.
     */
    public function maxTotal(): int
    {
        return (int) config('ews.max_total', 18);
    }

    /**
     * Hitung skor EWS lengkap dari satu set tanda vital.
     *
     * Menerima kunci snake_case (sys, rr, hr, spo2, suhu, kesadaran) maupun
     * nama yang dipakai phase1 (sistolik, rr, hr, spo2, suhu, kesadaran).
     * Nilai yang bukan angka dianggap belum diisi dan menyumbang 0, sama
     * seperti ewsScoreRange() yang mengembalikan null untuk NaN.
     *
     * @param  array<string, mixed>  $vitals
     * @return array{
     *     scores: array<string, int>,
     *     total: int,
     *     risk: string,
     *     riskLabel: string,
     *     level: int,
     *     maxTotal: int,
     *     components: array<string, array{label: string, value: float|int|null, score: int, band: string, inGap: bool}>
     * }
     */
    public function calculate(array $vitals): array
    {
        $scores = [];
        $components = [];

        foreach (array_keys(config('ews.parameters', [])) as $parameter) {
            $raw = $this->extract($vitals, $parameter);
            $meta = $this->scoreWithMeta($parameter, $raw);

            $scores[$parameter] = $meta['score'];
            $components[$parameter] = [
                'label' => (string) (config("ews.parameters.{$parameter}.label") ?? $parameter),
                'value' => $meta['value'],
                'score' => $meta['score'],
                'band' => $meta['band'],
                'inGap' => $meta['inGap'],
            ];
        }

        $total = $this->totalFrom($scores);
        $risk = $this->riskFrom($total);

        return [
            'scores' => $scores,
            'total' => $total,
            'risk' => $risk,
            'riskLabel' => $this->riskLabel($risk),
            'level' => $this->levelFrom($risk),
            'maxTotal' => $this->maxTotal(),
            'components' => $components,
        ];
    }

    /**
     * Skor satu parameter, 0 bila kosong atau jatuh pada celah band.
     */
    public function scoreParameter(string $parameter, mixed $value): int
    {
        return $this->scoreWithMeta($parameter, $value)['score'];
    }

    /**
     * Jumlahkan peta skor. Nilai null/absent dihitung 0, sama seperti
     * hitungEWS() yang memakai `(v ?? 0)`.
     *
     * @param  array<string, int|null>  $scores
     */
    public function totalFrom(array $scores): int
    {
        $total = 0;

        foreach (config('ews.parameters', []) as $parameter => $ignored) {
            $value = $scores[$parameter] ?? null;
            $total += $value === null ? 0 : (int) $value;
        }

        return $total;
    }

    /**
     * Eskalasi 4 tingkat: 0-2 low, 3-4 medium, 5-6 high, >=7 emergency.
     * Port ewsRiskLevel() / ewsKategori() phase1.
     */
    public function riskFrom(int $total): string
    {
        foreach ((array) config('ews.escalation', []) as $tier) {
            $min = (int) $tier['min'];
            $max = $tier['max'] === null ? null : (int) $tier['max'];

            if ($total >= $min && ($max === null || $total <= $max)) {
                return (string) $tier['level'];
            }
        }

        return EwsRiskLevel::LOW->value;
    }

    /**
     * Label badge pendek, mis. "Emergency". Prototipe menulis
     * `"9 (Emergency)"`; angka totalnya sudah ada di field `total`/`ewsTotal`,
     * jadi service ini hanya mengembalikan kata kualitatifnya.
     * Unknown / 'none' menghasilkan '-' (selaras default ewsBadgeClasses()).
     */
    public function riskLabel(string $risk): string
    {
        $level = EwsRiskLevel::tryFrom($risk);

        return $level === null ? ClinicalFormat::EMPTY : $level->badgeLabel();
    }

    /**
     * Kumpulan kelas Tailwind untuk badge risiko, digabung dengan spasi
     * persis seperti return ewsBadgeClasses() phase1.
     */
    public function riskTone(string $risk): string
    {
        return $this->badgeClasses(0, $risk)['class'];
    }

    /**
     * Port ewsBadgeClasses() phase1, dikembalikan sebagai array supaya
     * aman di-serialize ke Inertia.
     *
     * Dikembalikan sebagai array dengan kunci class/bg/text/border:
     *  - `class` = string gabungan, siap tempel ke :class
     *  - `bg`, `text`, `border` = masing-masing kelas, untuk cases wherein
     *    komponen warna dipakai terpisah (mis. hanya border pada kartu)
     *
     * $total dipakai sebagai cadangan: bila $risk tidak dikenal atau kosong,
     * level diturunkan dari $total.
     *
     * @return array{class: string, bg: string, text: string, border: string}
     */
    public function badgeClasses(int $total, string $risk): array
    {
        if (! array_key_exists($risk, self::BADGE_CLASSES)) {
            $risk = $risk === '' ? $this->riskFrom($total) : $risk;
        }

        [$bg, $text, $border] = self::BADGE_CLASSES[$risk] ?? self::FALLBACK_BADGE_CLASSES;

        return [
            'class' => $bg.' '.$text.' '.$border,
            'bg' => $bg,
            'text' => $text,
            'border' => $border,
        ];
    }

    /**
     * Nomor tingkat 1-4, mengikuti urutan config('ews.escalation').
     * Unknown / 'none' menghasilkan 0.
     */
    public function levelFrom(string $risk): int
    {
        $level = 0;

        foreach ((array) config('ews.escalation', []) as $index => $tier) {
            if ((string) $tier['level'] === $risk) {
                $level = $index + 1;
            }
        }

        return $level;
    }

    /**
     * Hitung skor sambil membawa metadata band untuk UI.
     *
     * @return array{score: int, band: string, inGap: bool, value: float|int|null}
     */
    private function scoreWithMeta(string $parameter, mixed $value): array
    {
        $definition = (array) config("ews.parameters.{$parameter}", []);

        if (($definition['type'] ?? null) === 'map') {
            return $this->scoreMap($definition, $value);
        }

        return $this->scoreRange($definition, $value);
    }

    /**
     * Parameter bertipe map (Kesadaran): skor dari peta nilai diskret.
     * Nilai yang tidak dikenal tetap 0, sama seperti
     * `EWS_TABLE.Kesadaran[data.kesadaran] || 0` di prototype.
     *
     * @param  array<string, mixed>  $definition
     * @return array{score: int, band: string, inGap: bool, value: float|int|null}
     */
    private function scoreMap(array $definition, mixed $value): array
    {
        $values = (array) ($definition['values'] ?? []);

        if (is_string($value)) {
            $value = trim($value);
        }

        // Formulir "DPO (RASS -2)" yang disimpan observasi.html tetap dikenali
        // sebagai DPO, karena prefix-nya sama persis dengan kunci AVPU.
        foreach ($values as $key => $score) {
            if (is_string($value) && str_starts_with($value, (string) $key)) {
                return ['score' => (int) $score, 'band' => (string) $key, 'inGap' => false, 'value' => null];
            }
        }

        if ($value === null || $value === '') {
            return ['score' => 0, 'band' => ClinicalFormat::EMPTY, 'inGap' => false, 'value' => null];
        }

        if (is_string($value)) {
            return ['score' => 0, 'band' => $value, 'inGap' => false, 'value' => null];
        }

        return ['score' => 0, 'band' => ClinicalFormat::EMPTY, 'inGap' => false, 'value' => null];
    }

    /**
     * Parameter bertipe range: skor dari daftar [min, max, score].
     *
     * CELAH: bila nilai ada tapi tidak masuk band mana pun, skor 0 dan
     * `inGap` true. Lihat blok penjelasan di docblock kelas.
     *
     * @param  array<string, mixed>  $definition
     * @return array{score: int, band: string, inGap: bool, value: float|int|null}
     */
    private function scoreRange(array $definition, mixed $value): array
    {
        $number = ClinicalFormat::numeric($value);

        if ($number === null) {
            return ['score' => 0, 'band' => ClinicalFormat::EMPTY, 'inGap' => false, 'value' => null];
        }

        foreach ((array) ($definition['bands'] ?? []) as $band) {
            $min = (float) $band['min'];
            $max = (float) $band['max'];

            if ($number >= $min && $number <= $max) {
                return [
                    'score' => (int) $band['score'],
                    'band' => $this->bandLabel($band),
                    'inGap' => false,
                    'value' => $number,
                ];
            }
        }

        return [
            'score' => 0,
            'band' => ClinicalFormat::EMPTY,
            'inGap' => true,
            'value' => $number,
        ];
    }

    /**
     * @param  array<string, mixed>  $band
     */
    private function bandLabel(array $band): string
    {
        return $band['min'].' - '.$band['max'];
    }

    /**
     * Ambil nilai parameter dari payload, mencoba alias persis lebih dulu
     * lalu pencarian case-insensitive sebagai jaring pengaman.
     */
    private function extract(array $vitals, string $parameter): mixed
    {
        foreach (self::INPUT_ALIASES[$parameter] ?? [] as $alias) {
            if (array_key_exists($alias, $vitals) && $vitals[$alias] !== '') {
                return $vitals[$alias];
            }
        }

        $lowered = [];

        foreach ($vitals as $key => $value) {
            $lowered[mb_strtolower((string) $key)] = $value;
        }

        foreach (self::INPUT_ALIASES[$parameter] ?? [] as $alias) {
            $alias = mb_strtolower($alias);

            if (array_key_exists($alias, $lowered) && $lowered[$alias] !== '') {
                return $lowered[$alias];
            }
        }

        return null;
    }

    /**
     * Terjemahkan input(select) tingkat kesadaran menjadi enum, dengan menerima
     * bentuk display "DPO (RASS -2)" yang disimpan observasi.html.
     */
    public function consciousnessFromInput(mixed $value): ?ConsciousnessLevel
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $value = trim((string) $value);

        foreach (ConsciousnessLevel::cases() as $case) {
            if ($value === $case->value || str_starts_with($value, $case->value.' ')) {
                return $case;
            }
        }

        return null;
    }
}

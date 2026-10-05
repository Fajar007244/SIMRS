<?php

/*
 * scripts/verify-ews-seed.php
 *
 * Silang-verifikasi 60 baris observasi hasil seed terhadap
 * EwsScoringService. Yang diperiksa, untuk setiap baris:
 *
 *   1. ews_total == jumlah ews_scores (konsistensi literal seed vs JSON);
 *   2. ews_risk == riskFrom(ews_total) (label risiko dari config escalation);
 *   3. calculate() pada vital yang TERSIMPAN menghasilkan ews_total yang sama;
 *   4. keenam skor parameter sama persis dengan ews_scores seed;
 *   5. tidak ada nilai NaN / null tak terduga di vital yang tersimpan.
 *
 * Skrip ini READ-ONLY. Jalankan setelah `php artisan migrate:fresh --seed`:
 *
 *   php artisan migrate:fresh --seed --force
 *   php scripts/verify-ews-seed.php
 *
 * Exit code 0 bila tidak ada mismatch, 1 bila ada.
 */

declare(strict_types=1);

use App\Services\EwsScoringService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

$root = dirname(__DIR__);
require $root.'/vendor/autoload.php';

/** @var Application $app */
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

/** @var EwsScoringService $scoring */
$scoring = $app->make(EwsScoringService::class);

/** @var Collection<int, object> $rows */
$rows = DB::table('observations')->orderBy('id')->get();

$parameters = array_keys(config('ews.parameters'));

$checked = 0;
$mismatched = 0;
$problems = [];

foreach ($rows as $row) {
    $checked++;

    /** @var array<string, int> $seedScores */
    $seedScores = json_decode((string) $row->ews_scores, true) ?: [];

    $seedTotal = (int) $row->ews_total;
    $seedSum = array_sum(array_map('intval', $seedScores));

    if ($seedSum !== $seedTotal) {
        $mismatched++;
        $problems[] = sprintf('#%d (%s %s): jumlah ews_scores %d != ews_total %d', $row->id, $row->observation_date, $row->observation_time, $seedSum, $seedTotal);
    }

    if (array_keys($seedScores) !== $parameters) {
        $mismatched++;
        $problems[] = sprintf('#%d: kunci ews_scores %s tidak lengkap', $row->id, implode(',', array_keys($seedScores)));
    }

    $vitals = [
        'rr' => $row->rr,
        'hr' => $row->hr,
        'sys' => $row->sys,
        'spo2' => $row->spo2,
        'suhu' => $row->suhu,
        'kesadaran' => $row->kesadaran,
    ];

    foreach ($vitals as $key => $value) {
        if ($value === null || ! is_numeric($value)) {
            if ($key === 'kesadaran') {
                continue;
            }

            $mismatched++;
            $problems[] = sprintf('#%d: vital %s bukan angka (%s)', $row->id, $key, var_export($value, true));

            continue 2;
        }
    }

    $computed = $scoring->calculate([
        'rr' => (float) $row->rr,
        'hr' => (float) $row->hr,
        'sys' => (float) $row->sys,
        'spo2' => (float) $row->spo2,
        'suhu' => (float) $row->suhu,
        'kesadaran' => $row->kesadaran,
    ]);

    if ($computed['total'] !== $seedTotal) {
        $mismatched++;
        $problems[] = sprintf('#%d: calculate() total %d != ews_total %d', $row->id, $computed['total'], $seedTotal);
    }

    if ($computed['risk'] !== (string) $row->ews_risk) {
        $mismatched++;
        $problems[] = sprintf('#%d: calculate() risk %s != ews_risk %s', $row->id, $computed['risk'], (string) $row->ews_risk);
    }

    foreach ($parameters as $parameter) {
        $fromSeed = (int) ($seedScores[$parameter] ?? -1);
        $fromCalc = (int) ($computed['scores'][$parameter] ?? -1);

        if ($fromSeed !== $fromCalc) {
            $mismatched++;
            $problems[] = sprintf('#%d: skor %s seed %d != calculate() %d', $row->id, $parameter, $fromSeed, $fromCalc);
        }
    }
}

fwrite(STDOUT, sprintf("verify-ews-seed: checked %d / mismatched %d\n", $checked, $mismatched));

foreach (array_slice($problems, 0, 25) as $problem) {
    fwrite(STDOUT, '  - '.$problem."\n");
}

exit($mismatched > 0 ? 1 : 0);

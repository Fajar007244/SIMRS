<?php

/*
 * scripts/verify-ews-save.php
 *
 * Membuktikan hasil POST formulir observasi prototipe terhadap database:
 * nilai vital/cairan baru tersimpan, baris obat SINTESIS (transfusi,
 * parenteral, enteral) ikut tercipta dengan kategori hasil inferensi regex,
 * 13 jawaban bundle tersimpan pada kunci yang benar, slot jam bersifat upsert,
 * dan slot yang gagal validasi tidak menulis baris sama sekali.
 *
 * READ-ONLY. Jalankan setelah POST di scripts/smoke-test atau manual:
 *   php scripts/verify-ews-save.php --date=2026-09-08 --time=07:00
 */

declare(strict_types=1);

use App\Models\Observation;
use App\Models\ObservationBundleAnswer;
use App\Models\ObservationMedication;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;

$root = dirname(__DIR__);
require $root.'/vendor/autoload.php';

/** @var Application $app */
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$date = '2026-09-08';
$time = '07:00';
$rejectedDate = '2026-09-09';
$rejectedTime = '11:00';

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--date=')) {
        $date = substr($arg, 7);
    }

    if (str_starts_with($arg, '--time=')) {
        $time = substr($arg, 7);
    }
}

$slot = fn (string $d, string $t): int => Observation::query()
    ->whereDate('observation_date', $d)
    ->where('observation_time', $t)
    ->count();

$o = Observation::query()
    ->whereDate('observation_date', $date)
    ->where('observation_time', $time)
    ->first();

if ($o === null) {
    fwrite(STDERR, sprintf("Baris %s %s tidak ada.\n", $date, $time));
    exit(2);
}

$w = fn (string $label, mixed $value): string => sprintf("  %-22s = %s\n", $label, var_export($value, true));

/** Nilai backing dari enum yang mungkin sudah di-cast ke objek enum. */
$enumValue = static fn (mixed $value): ?string => $value instanceof BackedEnum
    ? (string) $value->value
    : ($value === null ? null : (string) $value);

fwrite(STDOUT, sprintf("slot %s %s : %d baris (upsert)\n", $date, $time, $slot($date, $time)));
fwrite(STDOUT, sprintf("slot %s %s : %d baris (harus 0 - vital di luar rentang)\n\n", $rejectedDate, $rejectedTime, $slot($rejectedDate, $rejectedTime)));

fwrite(STDOUT, "== kolom baru dari formulir prototipe ==\n");
foreach ([
    'weight_kg', 'gcs_text', 'gcs', 'rass', 'bp_method', 'blood_glucose', 'respiratory_problem',
    'transfusion_type', 'transfusion_volume', 'parenteral_volume', 'enteral_volume',
    'urine_volume', 'drain_volume', 'iwl_volume', 'nursing_action',
] as $column) {
    fwrite(STDOUT, $w($column, $o->{$column}));
}

fwrite(STDOUT, "\n== kolom yang sudah ada ==\n");
foreach (['sys', 'dia', 'map', 'hr', 'rr', 'suhu', 'spo2', 'kesadaran', 'o2_support', 'intake', 'output', 'ews_total', 'ews_risk'] as $column) {
    fwrite(STDOUT, $w($column, $o->{$column}));
}

fwrite(STDOUT, $w('ventilator_settings', $o->ventilator_settings));
fwrite(STDOUT, $w('ews_scores', $o->ews_scores));
fwrite(STDOUT, $w('recorded_by', $o->recorded_by));

/* ---------------------------------------------- baris obat observation_medications */

fwrite(STDOUT, "\n== observation_medications untuk observasi ini ==\n");

$meds = ObservationMedication::query()->where('observation_id', $o->id)->orderBy('sort_order')->get();

foreach ($meds as $med) {
    fwrite(STDOUT, sprintf(
        "  [%d] %-28s | %-14s | %-22s | vol %-6s | %s\n",
        $med->sort_order,
        $med->name,
        $med->dose,
        $med->category?->value ?? $enumValue($med->category),
        (string) $med->volume,
        $med->status,
    ));
}

fwrite(STDOUT, sprintf("  total baris obat: %d\n", $meds->count()));

$synth = $meds->whereIn('name', ['Transfusi PRC', 'Cairan Parenteral', 'Cairan Enteral']);

fwrite(STDOUT, sprintf("  baris sintetis (transfusi/parenteral/enteral): %d\n", $synth->count()));

$expected = [
    'Transfusi PRC' => 'Obat Systemic',
    'Cairan Parenteral' => 'Cairan & Elektrolit',
    'Cairan Enteral' => 'Cairan & Elektrolit',
];
$inferOk = true;

foreach ($expected as $name => $category) {
    $row = $meds->firstWhere('name', $name);

    if ($row === null) {
        $inferOk = false;
        fwrite(STDOUT, sprintf("  BARIS HILANG: %s\n", $name));

        continue;
    }

    $actual = $enumValue($row->category);
    $ok = $actual === $category;
    $inferOk = $inferOk && $ok;

    fwrite(STDOUT, sprintf("  %-20s kategori %-20s %s\n", $name, $actual, $ok ? 'OK' : 'HARUS '.$category));
}

$emptyRows = $meds->filter(fn ($med) => $med->name === '' || $med->name === null)->count();
fwrite(STDOUT, sprintf("  baris kosong yang bocor: %d (harus 0)\n", $emptyRows));

/* ------------------------------------------------------- jawaban bundle 13 butir */

fwrite(STDOUT, "\n== observation_bundle_answers untuk observasi ini ==\n");

$expectedKeys = [];

foreach (config('hai.bundle_items') as $item) {
    // Nilai backing enum BundleAnswer adalah 'ya'/'tidak' (huruf kecil); label
    // 'Ya'/'Tidak' yang tampil di UI berasal dari answerLabel pada prop bundleAnswers.
    $expectedKeys[$item['key']] = 'ya';
}

$answers = ObservationBundleAnswer::query()->where('observation_id', $o->id)->get();

$seen = [];

foreach ($answers as $answer) {
    $seen[$answer->item_key] = $answer->answer instanceof BackedEnum ? $answer->answer->value : (string) $answer->answer;
}

ksort($expectedKeys);
ksort($seen);

fwrite(STDOUT, sprintf("  jumlah jawaban tersimpan: %d (harus %d)\n", count($seen), count($expectedKeys)));

foreach ($expectedKeys as $key => $answer) {
    $actual = $seen[$key] ?? '<tidak ada>';

    fwrite(STDOUT, sprintf("  %-10s = %-8s %s\n", $key, $actual, $actual === $answer ? 'OK' : 'HARUS '.$answer));
}

$extra = array_diff(array_keys($seen), array_keys($expectedKeys));
fwrite(STDOUT, sprintf("  kunci asing: %d (harus 0)\n", count($extra)));

/* --------------------------------------------------------- perangkat invasif */

fwrite(STDOUT, "\n== invasive_devices untuk observasi ini ==\n");

$devices = DB::table('invasive_devices')
    ->where('encounter_id', $o->encounter_id)
    ->whereNotNull('start_date')
    ->orderBy('device_code')
    ->get(['device_code', 'start_date', 'end_date', 'note']);

foreach ($devices as $device) {
    fwrite(STDOUT, sprintf("  %-10s %s .. %s %s\n", $device->device_code, (string) $device->start_date, (string) $device->end_date, (string) $device->note));
}

$bad = 0;
$bad += count($extra);
$bad += $emptyRows;
$bad += $synth->count() === 3 ? 0 : 1;
$bad += $inferOk ? 0 : 1;
$bad += count($seen) === count($expectedKeys) ? 0 : 1;
$bad += $slot($rejectedDate, $rejectedTime) === 0 ? 0 : 1;

fwrite(STDOUT, sprintf("\nverify-ews-save: problems %d\n", $bad));

exit($bad > 0 ? 1 : 0);

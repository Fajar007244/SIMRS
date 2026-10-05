<?php

/*
 * scripts/dump-render-props.php
 *
 * Membuang props Inertia yang SEBENARNYA dikirim server ke setiap halaman SPA,
 * lalu menuliskannya ke JSON supaya scripts/check-render.mjs bisa me-mount
 * komponen dengan bentuk data yang persis sama dengan produksi. Skrip ini
 * READ-ONLY: tidak menulis ke database sama sekali.
 *
 * Dipakai manual setelah `php artisan migrate:fresh --seed`:
 *
 *   php scripts/dump-render-props.php
 *   php scripts/dump-render-props.php --out=scripts/fixtures/render-props.json
 *
 * Request dibuat internal (tanpa jaringan) lewat HTTP kernel dengan header
 * X-Inertia supaya Inertia menjawab JSON dan bukan halaman Blade. User dipasang
 * langsung ke session guard supaya middleware `auth` dan `role` lolos.
 */

declare(strict_types=1);

use App\Models\Encounter;
use App\Models\User;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Vite;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Skrip ini hanya boleh dijalankan lewat CLI.\n");
    exit(1);
}

$root = dirname(__DIR__);
require $root.'/vendor/autoload.php';

/* @var \Illuminate\Foundation\Application $app */
$app = require $root.'/bootstrap/app.php';

$out = $root.'/scripts/fixtures/render-props.json';
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--out=')) {
        $out = $root.'/'.substr($arg, 6);
    }
}

/* Hash manifest = versi aset, supaya Inertia tidak menjawab 409. */

/* @var Kernel $kernel */
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

$version = null;
if (is_file($root.'/public/build/manifest.json')) {
    $version = Vite::manifestHash();
}

$user = User::query()->where('username', 'perawat')->first();
if (! $user) {
    fwrite(STDERR, "User perawat tidak ada. Jalankan migrate:fresh --seed dulu.\n");
    exit(2);
}

$all = Encounter::query()->orderBy('encounter_id')->pluck('encounter_id')->all();
if ($all === []) {
    fwrite(STDERR, "Tidak ada encounter. Jalankan migrate:fresh --seed dulu.\n");
    exit(2);
}

/* Encounter yang dipakai smoke test dipakai lebih dulu agar render check */
/* dan smoke test menguji data yang sama. */
$encounters = array_values(array_unique(array_filter(['enc-159853-icu-20260906', $all[0]], fn ($v) => in_array($v, $all, true))));

/* Varian query string supaya cabang tab/filter yang berbeda ikut diuji. */
$variants = [
    'profil' => [''],
    'cppt' => [''],
    'penunjang' => ['', '?group=abg'],
    'farmasi' => ['', '?category=Antibiotik'],
    'observasi' => ['', '?risk=high'],
    'bundles' => ['', '?group=vap'],
];

/** Ambil satu halaman Inertia lewat HTTP kernel internal. */
function inertiaGet($app, Kernel $kernel, User $user, string $uri, ?string $version): array
{
    $request = Request::create($uri, 'GET');
    $request->headers->set('X-Inertia', 'true');
    $request->headers->set('X-Requested-With', 'XMLHttpRequest');
    $request->headers->set('Accept', 'text/html, application/xhtml+xml');
    if ($version !== null) {
        $request->headers->set('X-Inertia-Version', $version);
    }

    /* SessionGuard menolak dibuat sebelum ada request yang terikat, jadi
     * request di-iktakan ke container lebih dulu. */
    $app->instance('request', $request);
    Auth::guard('web')->setUser($user);

    $response = $kernel->handle($request);
    $body = (string) $response->getContent();
    $decoded = json_decode($body, true);

    if (! is_array($decoded) || ! isset($decoded['component'], $decoded['props'])) {
        fwrite(STDERR, sprintf('Gagal ambil page Inertia untuk %s (HTTP %d): %s%s', $uri, $response->getStatusCode(), substr($body, 0, 400), PHP_EOL));
        exit(3);
    }

    return $decoded;
}

$pages = [];
foreach ($encounters as $encounterId) {
    foreach (['profil', 'cppt', 'penunjang', 'farmasi', 'observasi', 'bundles'] as $module) {
        $isPrimary = $encounterId === $encounters[0];
        foreach ($isPrimary ? $variants[$module] : [''] as $variant) {
            $page = inertiaGet($app, $kernel, $user, sprintf('/encounters/%s/%s%s', $encounterId, $module, $variant), $version);
            $key = $module.'|'.$encounterId;

            if (! isset($pages[$key])) {
                $pages[$key] = ['component' => $page['component'], 'url' => $page['url'], 'props' => $page['props'], 'variants' => []];
            }

            $label = ltrim($variant, '?');
            $pages[$key]['variants'][$label === '' ? '(default)' : $label] = ['url' => $page['url'], 'props' => $page['props']];
        }
    }
}

/* Rute `/` tidak lagi punya halaman Inertia (hanya redirect ke /pasien atau */
/* /login), jadi tidak ada lagi yang perlu di-mount di sini. */

$dir = dirname($out);
if (! is_dir($dir)) {
    mkdir($dir, 0755, true);
}

file_put_contents($out, json_encode($pages, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL);

fwrite(STDERR, sprintf('render-props: %d halaman ditulis ke %s%s', count($pages), $out, PHP_EOL));

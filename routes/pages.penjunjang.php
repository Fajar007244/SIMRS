<?php

use App\Http\Controllers\PenunjangController;
use App\Models\Encounter;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Halaman "Penunjang & AGD"
|--------------------------------------------------------------------------
|
| Satu halaman, enam tab, dan jalur tulis yang sesungguhnya:
|
|   penunjang                GET    /encounters/{encounter}/penunjang
|   penunjang.result.store   POST   /encounters/{encounter}/penunjang/results
|   penunjang.result.destroy POST   /encounters/{encounter}/penunjang/results/delete
|   penunjang.abg.store      POST   /encounters/{encounter}/penunjang/abg
|   penunjang.abg.destroy    POST   /encounters/{encounter}/penunjang/abg/delete
|   penunjang.reset          POST   /encounters/{encounter}/penunjang/reset
|
| Tab aktif dibawa lewat query string (?group=lab) supaya reload tidak
| mengembalikan halaman ke tab default. resources/js/router.js adalah cermin
| manual routes/web.php dan TIDAK memuat lima route tulis di atas, jadi
| Penunjang.vue membentuk URL-nya dari url('penunjang', ...) + suffix. Menambah
| lima nama itu ke router.js ROUTES adalah perubahan file beku yang dilaporkan
| di hasil wave ini, bukan yang dikerjakan di sini.
|
| Matriks otorisasi: "Device invasive / ABG / penunjang" boleh untuk ketiga
| role (docs/AUTHORIZATION.md), jadi semua rute memakai role yang sama.
|
| {encounter} adalah STRING `encounters.encounter_id`, bukan primary key.
|
*/

Route::bind('encounter', function (string $value) {
    return Encounter::query()
        ->where('encounter_id', $value)
        ->first()
        ?? abort(404, 'Episode perawatan tidak ditemukan. Periksa kembali kode encounter pada alamat halaman.');
});

Route::middleware(['auth', 'role:perawat,bidan,dokter'])->group(function () {
    Route::get('/encounters/{encounter}/penunjang', [PenunjangController::class, 'show'])
        ->name('penunjang');

    Route::post('/encounters/{encounter}/penunjang/results', [PenunjangController::class, 'storeResult'])
        ->name('penunjang.result.store');

    Route::post('/encounters/{encounter}/penunjang/results/delete', [PenunjangController::class, 'destroyResult'])
        ->name('penunjang.result.destroy');

    Route::post('/encounters/{encounter}/penunjang/abg', [PenunjangController::class, 'storeAbg'])
        ->name('penunjang.abg.store');

    Route::post('/encounters/{encounter}/penunjang/abg/delete', [PenunjangController::class, 'destroyAbg'])
        ->name('penunjang.abg.destroy');

    Route::post('/encounters/{encounter}/penunjang/reset', [PenunjangController::class, 'reset'])
        ->name('penunjang.reset');
});

<?php

namespace App\Http\Middleware;

use App\Models\AccessLog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Mencatat akses terautentikasi ke modul klinis (UU PDP Pasal 26).
 *
 * YANG DICATAT: siapa (user_id), data siapa (encounter_id + patient_id),
 * lewat modul apa, baca atau tulis, kapan. TIDAK PERNAH isi rekam medis.
 *
 * DUA KEPUTUSAN YANG DISENGAJA
 *
 *  1. Ditulis sinkron, bukan antrean. Aplikasi ini memakai QUEUE_CONNECTION
 *     sync tanpa worker, jadi "async" di sini hanya ilusi - job tetap jalan
 *     di request yang sama. Sinkron + try/catch jujur soal biayanya (satu
 *     INSERT per request) dan tidak pernah menghilangkan log diam-diam.
 *
 *  2. Kegagalan tulis DITELAN. Middleware ini tidak boleh mengubah hasil
 *     klinis: bila tabel log rusak/hilang, halaman pasien tetap 200. Tanpa
 *     try/catch, satu tabel audit yang bermasalah akan mengubah seluruh
 *     bacaan klinis menjadi 500 - kegagalan yang jauh lebih buruk daripada
 *     kehilangan satu baris log.
 *
 * CAKUPAN: route bernama dengan prefix modul klinis (lihat $modules).
 * /up (tanpa nama), /login, dan /logout tidak dicatat: yang pertama tidak
 * ada usernya, dua terakhir adalah peristiwa auth tanpa akses data pasien.
 */
class LogRecordAccess
{
    /**
     * Prefix nama route => nama modul. Urutan penting: prefix yang lebih
     * panjang harus lebih dulu bila ada yang saling mengawali.
     *
     * @var array<string, string>
     */
    protected array $modules = [
        'encounter.discharge' => 'pasien',
        'pasien' => 'pasien',
        'profil' => 'profil',
        'penunjang' => 'penunjang',
        'farmasi' => 'farmasi',
        'observasi' => 'observasi',
        'bundles' => 'bundles',
        'cppt' => 'cppt',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        try {
            $this->record($request);
        } catch (Throwable) {
            // Audit tidak boleh menjatuhkan bacaan klinis. Lihat catatan kelas.
        }

        return $response;
    }

    protected function record(Request $request): void
    {
        $user = $request->user();

        if ($user === null) {
            return;
        }

        $routeName = $request->route()?->getName();

        if (! is_string($routeName) || $routeName === '') {
            return;
        }

        $module = null;

        foreach ($this->modules as $prefix => $name) {
            if (str_starts_with($routeName, $prefix)) {
                $module = $name;
                break;
            }
        }

        if ($module === null) {
            return;
        }

        $encounter = $request->route('encounter');

        AccessLog::query()->create([
            'user_id' => $user->getKey(),
            // encounter_id = kode string unik (mis. enc-159853-...).
            'encounter_id' => is_object($encounter) ? $encounter->encounter_id : null,
            // patient_id = PK integer patients.id, BUKAN kode string pasien.
            // $encounter->patient_id adalah FK integer; kode stringnya hanya
            // bisa dibaca lewat relasi patient dan tidak disimpan di sini.
            'patient_id' => is_object($encounter) ? $encounter->patient_id : null,
            'action' => $request->isMethod('GET') ? AccessLog::ACTION_READ : AccessLog::ACTION_WRITE,
            'module' => $module,
            'route' => mb_substr($routeName, 0, 255),
            'method' => $request->method(),
            'ip_address' => mb_substr((string) $request->ip(), 0, 45) ?: null,
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255) ?: null,
            'created_at' => now(),
        ]);
    }
}

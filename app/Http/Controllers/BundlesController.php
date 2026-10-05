<?php

namespace App\Http\Controllers;

use App\Enums\BundleGroup;
use App\Models\Encounter;
use App\Services\AdmissionService;
use App\Services\BundleService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Halaman "Bundle HAIs (VAP/CLABSI/CAUTI)" (nama route `bundles`).
 *
 * READ-ONLY. Port 1:1 dari phase1/bundles.html. Halaman ini tidak punya form
 * create apa pun: penilaian bundle HAIs dan pencatatan perangkat invasif
 * dilakukan pada formulir observasi EWS (modul Observasi), sedangkan modul
 * ini hanya mengevaluasinya lewat BundleService.
 *
 * Pemetaan phase1 -> service:
 *   renderStats()     -> getSummary()['overall'] + ['groups'] + latestAt
 *   renderBundleCards -> getSummary()['groups'][<g>]['items']
 *   renderTrend()     -> getHistory()
 *   renderHistory()   -> getHistory()
 *   renderGaps()      -> getGaps()
 *   renderDevices()   -> getDeviceSummary()
 *   percentTone()     -> ComplianceBar / tone.js percentTone()
 *
 * CATATAN getDeviceSummary(): `needsReview` hanya true bila perangkat masih
 * aktif DAN `days >= config('hai.device_review_after_days')` (= 7), dengan
 * acuan lama pakai berupa tanggal observasi TERAKHIR episode itu. Data seed
 * hanya mencapai "Hari #3", jadi tidak satu pun perangkat yang perlu review.
 * Halaman tetap merender flag itu apa adanya dan tidak boleh memberi
 * kesan semua perangkat sudah lewat batas.
 *
 * CATATAN getHistory(): service mengembalikan baris TERBARU DI ATAS
 * (lihat docblock BundleService::getHistory()). Label sumbu X pada grafik
 * dibangun di sisi klien dari urutan itu TANPA membalik array-nya; sumbu X
 * digeser dari ujung supaya observasi terbaru berada di kanan.
 *
 * PROPS Inertia (resources/js/Pages/Bundles.vue):
 *   encounterId string   nilai kolom `encounters.encounter_id`
 *   banner      array    12 kunci AdmissionService::getBannerPayload()
 *   summary     array    getSummary()
 *   history     array    getHistory($enc, 7) - terbaru di atas
 *   gaps        array    getGaps()
 *   devices     array    getDeviceSummary() - selalu 8 baris katalog
 *   filters     array    { group, search }
 *   activeTab   string   selalu 'bundles'
 */
class BundlesController extends Controller
{
    /**
     * Jumlah observasi yang diplot pada tren. Nilai ini juga menjadi batas
     * jumlah baris tabel riwayat.
     */
    private const TREND_OBSERVATIONS = 7;

    public function __construct(
        private readonly BundleService $bundles,
        private readonly AdmissionService $admissions,
    ) {}

    /**
     * GET /encounters/{encounter}/bundles
     *
     * `{encounter}` di-bind ke kolom `encounters.encounter_id` (string bisnis,
     * mis. `enc-159853-icu-20260906`) oleh Route::bind() di
     * routes/pages.bundles.php, BUKAN ke primary key numerik. Numeric pk
     * karena itu selalu 404.
     *
     * Query string (opsional, di-whitelist):
     *   group   'vap' | 'clabsi' | 'cauti', atau kosong untuk semua
     *   search  kecocokan pada label waktu observasi
     *
     * Kedua filter diterapkan di sisi klien pada tabel riwayat karena
     * BundleService::getHistory() tidak menerima filter. Nilai yang sudah
     * divalidasi tetap dikirim balik lewat `filters` supaya pilihan pengguna
     * tidak hilang saat halaman dimuat ulang.
     */
    public function show(Request $request, Encounter $encounter): Response
    {
        abort_unless(
            $encounter->exists,
            404,
            'Episode perawatan tidak ditemukan. Periksa kembali kode encounter pada alamat halaman.'
        );

        return Inertia::render('Bundles', [
            'encounterId' => (string) $encounter->encounter_id,
            'banner' => $this->admissions->getBannerPayload($encounter),
            'summary' => $this->bundles->getSummary($encounter),
            'history' => $this->bundles->getHistory($encounter, self::TREND_OBSERVATIONS),
            'gaps' => $this->bundles->getGaps($encounter),
            'devices' => $this->bundles->getDeviceSummary($encounter),
            'filters' => [
                'group' => $this->group($request->query('group')),
                'search' => $this->text($request->query('search', $request->query('q'))),
            ],
            'activeTab' => 'bundles',
        ]);
    }

    /**
     * Kunci grup bundle dari query string; nilai asing menjadi null (semua).
     */
    private function group(mixed $value): ?string
    {
        $value = $this->text($value);

        if ($value === null) {
            return null;
        }

        return BundleGroup::tryFrom($value)?->value;
    }

    /**
     * Teks query string: trim, string kosong menjadi null.
     */
    private function text(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value === '' ? null : $value;
    }
}

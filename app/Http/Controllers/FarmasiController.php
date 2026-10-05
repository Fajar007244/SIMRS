<?php

namespace App\Http\Controllers;

use App\Enums\MedicationCategory;
use App\Models\Encounter;
use App\Services\AdmissionService;
use App\Services\MedicationRecapService;
use DateTimeImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Halaman "Farmasi & Drip Inotropik" (nama route `farmasi`).
 *
 * READ-ONLY. Port 1:1 dari phase1/farmasi.html. Halaman ini tidak punya
 * form create apa pun: seluruh isi modul berasal dari koreksi pemberian
 * obat/cairan yang tercatat pada formulir observasi EWS (modul Observasi),
 * dan modul ini hanya membacanya lewat MedicationRecapService.
 *
 * Pemetaan phase1 -> service:
 *   renderStats()            -> getSummary()             (enam StatCard)
 *   renderTimeline()         -> getAdministrations()     (tabel + filter)
 *   renderActiveMedications() -> getActiveDrips()
 *   renderFluids()           -> getFluidBalance($enc, 24)
 *   renderCategoryBars()     -> getCategoryDistribution()
 *   renderAllergyPanel()     -> getAllergyConflicts()
 *   renderPlanPanel()        -> getTherapyPlanChecks()
 *   renderFormularium()      -> getFormularium()
 *
 * CATATAN getFluidBalance(): jendela 24 jam dihitung dari `now()`, bukan dari
 * tanggal observasi terakhir. Untuk episode yang observasinya sudah lewat
 * (seluruh data seed) jendela itu kosong secara sah - intake/output/balance
 * bernilai 0 dan `rows` kosong - sedangkan `cumulativeBalance` tetap terisi.
 * Halaman menampilkan KEDUA angka dengan label berbeda, bukan menyamarkan
 * jendela kosong. Service TIDAK diubah.
 *
 * PROPS Inertia (resources/js/Pages/Farmasi.vue):
 *   encounterId  string   nilai kolom `encounters.encounter_id`
 *   banner       array    12 kunci AdmissionService::getBannerPayload()
 *   summary      array    getSummary()
 *   medications  array    getAdministrations() SUDAH tersaring server
 *   activeDrips  array    getActiveDrips()
 *   fluidBalance array    getFluidBalance($enc, 24)
 *   categoryDistribution array getCategoryDistribution() (selalu 6 kategori)
 *   allergyConflicts array getAllergyConflicts()
 *   therapyPlanChecks array getTherapyPlanChecks() (selalu 4 baris)
 *   formularium  array    getFormularium()
 *   formulariumMeta array helper UI dari config('formularium'), lihat
 *                        method formulariumMeta()
 *   filters      array    { category, search, dateFrom, dateTo }
 *   activeTab    string   selalu 'farmasi'
 */
class FarmasiController extends Controller
{
    /**
     * Lebar jendela rekapitulasi cairan yang dikirim ke halaman, dalam jam.
     * Nilainya ikut dikirim balik di `fluidBalance.hours` supaya label
     * "24 Jam" pada UI tidak pernah di-hardcode di dua tempat.
     */
    private const FLUID_WINDOW_HOURS = 24;

    public function __construct(
        private readonly MedicationRecapService $medications,
        private readonly AdmissionService $admissions,
    ) {}

    /**
     * GET /encounters/{encounter}/farmasi
     *
     * `{encounter}` di-bind ke kolom `encounters.encounter_id` (string bisnis,
     * mis. `enc-159853-icu-20260906`) oleh Route::bind() di
     * routes/pages.farmasi.php, BUKAN ke primary key numerik. Numeric pk
     * karena itu selalu 404.
     *
     * Query string (semua opsional dan di-whitelist):
     *   category  nilai MedicationCategory, alias `kategori`
     *   search    nama obat / dosis / petugas, alias `q`
     *   dateFrom  `Y-m-d`, alias `date_from`
     *   dateTo    `Y-m-d`, alias `date_to`
     *
     * Filter diteruskan ke MedicationRecapService::getAdministrations()
     * sehingga penyaringan terjadi di server dan URL-nya tetap bisa
     * disalin atau dibagikan.
     */
    public function show(Request $request, Encounter $encounter): Response
    {
        abort_unless(
            $encounter->exists,
            404,
            'Episode perawatan tidak ditemukan. Periksa kembali kode encounter pada alamat halaman.'
        );

        $filters = $this->filters($request);

        /*
         * Banner dipanggil lebih dulu karena getBannerPayload() me-load relasi
         * `patient` dan `diagnoses`. getAllergyConflicts() membaca relasi
         * `patient` dan menghormatinya kalau sudah termuat, jadi pemanggilan
         * ini tidak menambah kueri.
         */
        $banner = $this->admissions->getBannerPayload($encounter);

        /*
         * getTherapyPlanChecks() dan getFormularium() sama-sama membaca
         * asmeds. Dimuat satu kali di sini supaya tidak dua kueri.
         */
        $encounter->loadMissing('asmed');

        return Inertia::render('Farmasi', [
            'encounterId' => (string) $encounter->encounter_id,
            'banner' => $banner,
            'summary' => $this->medications->getSummary($encounter),
            'medications' => $this->medications->getAdministrations($encounter, $filters),
            'activeDrips' => $this->medications->getActiveDrips($encounter),
            'fluidBalance' => $this->medications->getFluidBalance($encounter, self::FLUID_WINDOW_HOURS),
            'categoryDistribution' => $this->medications->getCategoryDistribution($encounter),
            'allergyConflicts' => $this->medications->getAllergyConflicts($encounter),
            'therapyPlanChecks' => $this->medications->getTherapyPlanChecks($encounter),
            'formularium' => $this->medications->getFormularium($encounter),
            'formulariumMeta' => $this->formulariumMeta(),
            'filters' => $filters,
            'activeTab' => 'farmasi',
        ]);
    }

    /**
     * Normalisasi filter dari query string.
     *
     * Nilai yang tidak dikenal dibuang (null) alih-alih diteruskan ke
     * service: kategori di-whitelist ke App\Enums\MedicationCategory, dan
     * tanggal harus benar-benar `Y-m-d`. Dengan begitu isi `filters` yang
     * dikirim ke Vue persis sama dengan yang dipakai untuk kueri.
     *
     * @return array{category: string|null, search: string|null, dateFrom: string|null, dateTo: string|null}
     */
    private function filters(Request $request): array
    {
        $category = $this->text($request->query('category', $request->query('kategori')));

        if ($category !== null) {
            $category = MedicationCategory::tryFrom($category)?->value;
        }

        return [
            'category' => $category,
            'search' => $this->text($request->query('search', $request->query('q'))),
            'dateFrom' => $this->date($request->query('dateFrom', $request->query('date_from'))),
            'dateTo' => $this->date($request->query('dateTo', $request->query('date_to'))),
        ];
    }

    /**
     * Teks query string: trim, dan string kosong menjadi null supaya
     * `url()` di router.js membuangnya dari query string berikutnya.
     */
    private function text(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value === '' ? null : $value;
    }

    /**
     * Tanggal `Y-m-d` yang benar-benar valid; nilai lain menjadi null.
     */
    private function date(mixed $value): ?string
    {
        $value = $this->text($value);

        if ($value === null) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return ($date !== false && $date->format('Y-m-d') === $value) ? $value : null;
    }

    /**
     * Metadata formularium yang tidak ikut dikembalikan
     * MedicationRecapService::getFormularium(): kolom Rute, Target, dan
     * Status pada tabel phase1.
     *
     * Ketiganya memang ada di config/formularium.php (file beku) tetapi
     * TIDAK dikembalikan service - yang kembali hanya name, dose, category,
     * categoryLabel, always, inPlan, givenCount, dan realizationLabel.
     * Daripada membuat tiga kolom berisi tanda hubung yang menyesatkan,
     * nilainya diambil langsung dari config yang sama, dikunci dengan
     * `mb_strtolower(name)`. Nol kueri tambahan dan tetap sinkron karena
     * sumbernya file config yang juga dibaca service.
     *
     * @return array<string, array{route: string|null, target: string|null, status: string|null}>
     */
    private function formulariumMeta(): array
    {
        $meta = [];

        $groups = [
            (array) config('formularium.conditionally_scheduled', []),
            (array) config('formularium.always_scheduled', []),
        ];

        foreach ($groups as $entries) {
            foreach ($entries as $entry) {
                $name = trim((string) ($entry['name'] ?? ''));

                if ($name === '') {
                    continue;
                }

                $meta[mb_strtolower($name)] = [
                    'route' => $this->text($entry['route'] ?? null),
                    'target' => $this->text($entry['target'] ?? null),
                    'status' => $this->text($entry['status'] ?? null),
                ];
            }
        }

        return $meta;
    }
}

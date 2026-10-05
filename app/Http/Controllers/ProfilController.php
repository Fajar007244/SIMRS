<?php

namespace App\Http\Controllers;

use App\Models\Encounter;
use App\Services\AdmissionService;
use App\Services\ObservationService;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * Profil & Ringkasan ICU - halaman Inertia (`Pages/Profil.vue`).
 *
 * Rute: routes/pages.profil.php (name "profil"), middleware `auth`.
 * URL: /encounters/{encounter}/profil, dengan {encounter} = STRING
 * encounters.encounter_id (mis. enc-159853-icu-20260906), bukan primary key.
 * Pengikatan parameter dilakukan oleh Route::bind() di file rute tersebut,
 * bukan dengan mengubah model Encounter.
 *
 * URUTAN PANGGILAN SERVICE (penting untuk jumlah query)
 * getProfile() dijalankan LEBIH DAHULU karena service itu me-loadMissing
 * relasi patient / diagnoses / procedures / nursingCares. getBannerPayload()
 * penggunaan berikutnya memakai relasi yang sudah ter-cache itu, sehingga
 * ada query patient / diagnoses yang terduplikasi.
 *
 * latestObservation diambil dari ObservationService::getTelemetry() supaya
 * delta tanda vital dan jumlah observasi berisiko ikut tersedia tanpa
 * memanggil getFlowsheet() untuk kedua kalinya.
 */
class ProfilController extends Controller
{
    public function __construct(
        private readonly AdmissionService $admission,
        private readonly ObservationService $observations,
    ) {}

    /**
     * Halaman profil satu episode.
     */
    public function show(Request $request, Encounter $encounter): Response
    {
        abort_unless($encounter->exists, 404, 'Episode perawatan tidak ditemukan.');

        $profile = $this->admission->getProfile($encounter);

        return inertia('Profil', [
            'encounterId' => $encounter->encounter_id,
            'activeTab' => 'profil',
            'banner' => $this->admission->getBannerPayload($encounter),
            'profile' => $profile,
            'telemetry' => $this->observations->getTelemetry($encounter),
        ]);
    }
}

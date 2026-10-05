<?php

namespace Database\Seeders;

use App\Enums\BundleAnswer;
use App\Enums\ConsciousnessLevel;
use App\Enums\DiagnosisType;
use App\Enums\EncounterStatus;
use App\Enums\EwsRiskLevel;
use App\Enums\MedicationCategory;
use App\Enums\NursingShift;
use App\Enums\PaymentType;
use App\Enums\Sex;
use App\Enums\SupportGroup;
use App\Enums\UserRole;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * ============================================================================
 * SEEDER UTAMA - LAPISAN DATA AWAL SIMRS RSP ROTINSULU
 * ============================================================================
 *
 * URUTAN PEMANGGILAN
 *   1. UserSeeder            - tiga akun petugas dari phase1/auth.js
 *   2. PatientEncounterSeeder- 6 pasien, 6 episode, ASMED, diagnosis,
 *                              prosedur, asuhan keperawatan, CPPT
 *   3. ObservationSeeder     - 60 observasi EWS, koreksi pemberian obat,
 *                              jawaban bundle, perangkat invasif
 *   4. AbgResultSeeder       - 15 hasil analisa gas darah
 *   5. SupportResultSeeder   - 118 hasil laboratorium, darah, mikroba, radiologi
 *   6. Assertion blok        - pemeriksaan integritas data, lihat bawah
 *
 * CATATAN TENTANG ClinicalReferenceSeeder
 *   Sengaja tidak ada. Katalog bundle, perangkat invasif, formularium, dan
 *   tabel EWS pada aplikasi ini adalah konstanta klinis yang sudah berada di
 *   config/hai.php, config/formularium.php, dan config/ews.php, bukan baris
 *   database. Membuat tabel rujukan tambahan hanya akan menduplikasi
 *   konfigurasi dan Padahal tidak pernah dipakai.
 *
 * IDEMPOTENSI
 *   Seluruh seeder memakai updateOrCreate dengan kunci bisnis, bukan insert
 *   buta, sehingga `php artisan db:seed` boleh dijalankan berulang di Railway
 *   tanpa bentrok unique index. Kunci yang dipakai:
 *     - users                        : username
 *     - patients                     : patient_id
 *     - encounters                   : encounter_id
 *     - diagnoses                    : encounter_id + code + text
 *     - procedures                   : encounter_id + name + performed_at
 *     - asmeds                       : encounter_id (ada unique index)
 *     - nursing_cares                : encounter_id + recorded_at
 *     - medical_notes                : encounter_id + order_number
 *     - observations                 : encounter_id + observation_date +
 *                                     observation_time (ada unique index)
 *     - observation_medications      : observation_id + name + sort_order
 *     - observation_bundle_answers   : observation_id + item_key (unique index)
 *     - invasive_devices             : encounter_id + device_code + start_date
 *     - support_results              : encounter_id + group + result_key +
 *                                     resulted_at
 *     - abg_results                  : encounter_id + measured_at
 *
 * JARING PENGAMAN INTEGRITAS DATA
 *   Semua model memakai cast enum, sehingga satu nilai yang tidak valid akan
 *   melempar exception pada saat baris dibaca - termasuk di dalam halaman SPA.
 *   Agar kegagalan itu ketahuan saat seeding dan bukan saat produksi, blok
 *   assertSeedIntegrity() di bawah membaca tabel secara RAW (bukan lewat model,
 *   supaya model tidak ikut melempar lebih dulu), mengumpulkan SEMUA pelanggaran
 *   sekaligus, lalu melempar satu RuntimeException berisi daftar lengkapnya.
 *   Tidak memakai framework test apa pun.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            UserSeeder::class,
            PatientEncounterSeeder::class,
            ObservationSeeder::class,
            AbgResultSeeder::class,
            SupportResultSeeder::class,
        ]);

        $this->assertSeedIntegrity();
    }

    /*
    |--------------------------------------------------------------------------
    | Assertion integritas data
    |--------------------------------------------------------------------------
    |
    | Sengaja membaca nilai mentah lewat query builder, bukan lewat model.
    | model akan memaksa cast enum berjalan, dan cast itu melempar exception
    | pada nilai pertama yang tidak valid - persis hal yang ingin dicegah di
    | sini. Karena itu setiap pelanggaran dikumpulkan dulu, baru dilempar
    | sekaligus di akhir.
    |
    */

    protected function assertSeedIntegrity(): void
    {
        $violations = [];

        $maxTotal = (int) config('ews.max_total', 18);
        $riskLevels = array_column(EwsRiskLevel::cases(), 'value');
        $medicationCategories = array_column(MedicationCategory::cases(), 'value');
        $bundleAnswers = array_column(BundleAnswer::cases(), 'value');
        $consciousnessLevels = array_column(ConsciousnessLevel::cases(), 'value');
        $supportGroups = array_column(SupportGroup::cases(), 'value');
        $resultGroups = SupportGroup::resultGroups();
        $diagnosisTypes = array_column(DiagnosisType::cases(), 'value');
        $nursingShifts = array_column(NursingShift::cases(), 'value');
        $bundleItemKeys = array_column((array) config('hai.bundle_items', []), 'key');
        $bundleItemGroups = [];
        foreach ((array) config('hai.bundle_items', []) as $item) {
            $bundleItemGroups[$item['key']] = $item['group'];
        }
        $deviceKeys = array_column((array) config('hai.device_catalog', []), 'key');

        // Observation: rentang skor, risiko valid, DAN konsistensi ews_total
        // dengan penjumlahan ews_scores. Yang terakhir murni pemeriksaan
        // integritas data, bukan penilaian ulang skor dari tanda vital.
        foreach (DB::table('observations')->orderBy('id')->get() as $row) {
            $where = 'observations.id='.$row->id.' ('.$row->observation_date.' '.$row->observation_time.')';

            $total = (int) $row->ews_total;

            if ($total < 0 || $total > $maxTotal) {
                $violations[] = 'observations: ews_total di luar rentang 0-'.$maxTotal.' pada '.$where.', nilai '.$row->ews_total;
            }

            if (! in_array((string) $row->ews_risk, $riskLevels, true)) {
                $violations[] = 'observations: ews_risk tidak valid pada '.$where.', nilai "'.$row->ews_risk.'"';
            }

            if (! in_array((string) $row->kesadaran, $consciousnessLevels, true)) {
                $violations[] = 'observations: kesadaran tidak valid pada '.$where.', nilai "'.$row->kesadaran.'"';
            }

            $scores = json_decode((string) $row->ews_scores, true);
            if (is_array($scores) && $scores !== []) {
                $sum = 0;
                foreach ($scores as $score) {
                    $sum += (int) $score;
                }
                if ($sum !== $total) {
                    $violations[] = 'observations: ews_total tidak sama dengan jumlah ews_scores pada '.$where.', total '.$total.' jumlah skor '.$sum;
                }
            } else {
                $violations[] = 'observations: ews_scores kosong pada '.$where;
            }
        }

        // ObservationMedication: kategori harus salah satu MedicationCategory.
        foreach (DB::table('observation_medications')->orderBy('id')->get() as $row) {
            if (! in_array((string) $row->category, $medicationCategories, true)) {
                $violations[] = 'observation_medications: category tidak valid pada id '.$row->id.', nilai "'.$row->category.'"';
            }
        }

        // ObservationBundleAnswer: jawaban valid dan kunci item harus ada di
        // config('hai.bundle_items'), sekaligus grupnya harus cocok dengan
        // grup yang dideklarasikan config.
        foreach (DB::table('observation_bundle_answers')->orderBy('id')->get() as $row) {
            if (! in_array((string) $row->answer, $bundleAnswers, true)) {
                $violations[] = 'observation_bundle_answers: answer tidak valid pada id '.$row->id.', nilai "'.$row->answer.'"';
            }

            if (! in_array((string) $row->item_key, $bundleItemKeys, true)) {
                $violations[] = 'observation_bundle_answers: item_key tidak ada di config hai.bundle_items pada id '.$row->id.', nilai "'.$row->item_key.'"';
            } elseif (isset($bundleItemGroups[$row->item_key]) && $bundleItemGroups[$row->item_key] !== $row->bundle_group) {
                $violations[] = 'observation_bundle_answers: bundle_group tidak cocok dengan config pada id '.$row->id
                    .' (item '.$row->item_key.', tersimpan "'.$row->bundle_group.'", config "'.$bundleItemGroups[$row->item_key].'")';
            }
        }

        // InvasiveDevice: kode harus ada di config('hai.device_catalog').
        foreach (DB::table('invasive_devices')->orderBy('id')->get() as $row) {
            if (! in_array((string) $row->device_code, $deviceKeys, true)) {
                $violations[] = 'invasive_devices: device_code tidak ada di config hai.device_catalog pada id '.$row->id.', nilai "'.$row->device_code.'"';
            }
        }

        // SupportResult: kelompok harus valid dan TIDAK boleh abg. AGD punya
        // tabel sendiri (abg_results), jadi kemunculannya di sini berarti
        // data salah tempat dan akan hilang dari halaman penunjang.
        foreach (DB::table('support_results')->orderBy('id')->get() as $row) {
            if (! in_array((string) $row->group, $supportGroups, true)) {
                $violations[] = 'support_results: group tidak valid pada id '.$row->id.', nilai "'.$row->group.'"';
            } elseif (! in_array((string) $row->group, $resultGroups, true)) {
                $violations[] = 'support_results: group "'.$row->group.'" tidak boleh disimpan di support_results pada id '.$row->id
                    .' (AGD harus di tabel abg_results)';
            }
        }

        // Sisa kolom enum pada tabel episode, agar tidak ada kejutan saat
        // halaman profil / CPPT membacanya.
        foreach (DB::table('patients')->orderBy('id')->get() as $row) {
            if (! in_array((string) $row->sex, array_column(Sex::cases(), 'value'), true)) {
                $violations[] = 'patients: sex tidak valid pada id '.$row->id.', nilai "'.$row->sex.'"';
            }
            if (! in_array((string) $row->payment, array_column(PaymentType::cases(), 'value'), true)) {
                $violations[] = 'patients: payment tidak valid pada id '.$row->id.', nilai "'.$row->payment.'"';
            }
        }

        foreach (DB::table('encounters')->orderBy('id')->get() as $row) {
            if (! in_array((string) $row->status, array_column(EncounterStatus::cases(), 'value'), true)) {
                $violations[] = 'encounters: status tidak valid pada id '.$row->id.', nilai "'.$row->status.'"';
            }
        }

        foreach (DB::table('diagnoses')->orderBy('id')->get() as $row) {
            if (! in_array((string) $row->type, $diagnosisTypes, true)) {
                $violations[] = 'diagnoses: type tidak valid pada id '.$row->id.', nilai "'.$row->type.'"';
            }
        }

        foreach (DB::table('nursing_cares')->orderBy('id')->get() as $row) {
            if ($row->shift !== null && ! in_array((string) $row->shift, $nursingShifts, true)) {
                $violations[] = 'nursing_cares: shift tidak valid pada id '.$row->id.', nilai "'.$row->shift.'"';
            }
        }

        foreach (DB::table('users')->orderBy('id')->get() as $row) {
            if (! in_array((string) $row->role, array_column(UserRole::cases(), 'value'), true)) {
                $violations[] = 'users: role tidak valid pada id '.$row->id.', nilai "'.$row->role.'"';
            }
        }

        if ($violations !== []) {
            throw new \RuntimeException(
                'Seeding selesai tetapi pemeriksaan integritas data menemukan '
                .count($violations)." pelanggaran:\n - ".implode("\n - ", $violations)
            );
        }
    }
}

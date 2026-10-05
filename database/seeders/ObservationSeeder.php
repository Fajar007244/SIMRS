<?php

namespace Database\Seeders;

use App\Enums\BundleAnswer;
use App\Enums\ConsciousnessLevel;
use App\Enums\DeviceCode;
use App\Enums\EwsRiskLevel;
use App\Enums\MedicationCategory;
use App\Models\Encounter;
use App\Models\InvasiveDevice;
use App\Models\Observation;
use App\Models\ObservationBundleAnswer;
use App\Models\ObservationMedication;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Observasi EWS, koreksi pemberian obat, jawaban bundle, dan perangkat invasif.
 *
 * ============ SUMBER DATA DAN BATASANNYA ============
 *
 * phase1/app-context.js HANYA menyeed DUA baris observasi, dan hanya untuk satu
 * episode (enc-159853-icu-20260906, tanggal 2026-09-06 jam 08:00 dan 12:00).
 * Kedua baris itu disalin apa adanya di bawah, ditandai ObservationSeeder
 * baris prototype. Seluruh baris lainnya tidak ada di prototype, sehingga
 * nilainya diturunkan dari tabel EWS yang sama (config/ews.php, identik dengan
 * EWS_TABLE pada app-context.js) dan dari kondisi klinis tiap episode.
 *
 * ============ MENGAPA SKOR TIDAK DIHITUNG ULANG DI SINI ============
 *
 * Kolom ews_total, ews_scores, dan ews_risk ditulis sebagai literal, bukan
 * hasil hitungan pada saat seeding. Nilai-nilai tersebut adalah sumber
 * otoritatif yang berasal dari prototype, dan sengaja TIDAK memanggil service
 * penilaian EWS apa pun supaya seeder tidak bergantung pada service yang
 * dikerjakan terpisah. Bila service penilaian sudah siap, nilainya mestinya
 * cocok dengan literal di sini.
 *
 * ============ PERANGKAT INASIF ============
 *
 * phase1 tidak menyimpan perangkat invasif di data seed sama sekali; start_date
 * diisi manual oleh petugas di formulir. Karena itu start_date di bawah dipilih
 * mengikuti tanggal tindakan nyata pada tiap episode (tanggal intubasi, CVC,
 * hemodialisis, dan akses pada tanggal masuk), sehingga hitungan
 * hari pemakaian yang dihitung getDeviceSummary() sama dengan yang akan dibaca
 * pengguna di formulir. Acuan hari adalah tanggal observasi terakhir episode.
 */
class ObservationSeeder extends Seeder
{
    /**
     * Jumlah baris yang benar-benar disalin dari prototype.
     */
    public const PROTOTYPE_ROWS = 2;

    public function run(): void
    {
        $bundleItems = (array) config('hai.bundle_items', []);

        foreach ($this->observations() as $encounterCode => $payload) {
            $encounter = Encounter::query()->where('encounter_id', $encounterCode)->first();

            if (! $encounter instanceof Encounter) {
                continue;
            }

            $latest = null;

            foreach ($payload['observations'] as $row) {
                $observation = $this->seedObservation($encounter, $row);
                $this->seedMedications($observation, $payload['medications'] ?? [], $row['meds'] ?? []);
                $this->seedBundleAnswers($observation, $bundleItems, $row['gaps'] ?? []);

                $latest = $observation->recorded_at;
            }

            $this->seedDevices($encounter, $payload['devices'] ?? []);

            // latest_observation_at adalah denormalisasi untuk banner identitas,
            // jadi harus ditulis setelah seluruh observasi episode ini masuk.
            $encounter->forceFill(['latest_observation_at' => $latest])->save();
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Penulis baris
    |--------------------------------------------------------------------------
    */

    protected function seedObservation(Encounter $encounter, array $row): Observation
    {
        $recordedAt = $row['at'];
        [$date, $time] = explode(' ', $recordedAt);

        return Observation::query()->updateOrCreate(
            [
                'encounter_id' => $encounter->getKey(),
                'observation_date' => Carbon::parse($date)->startOfDay(),
                'observation_time' => $time,
            ],
            [
                'sys' => $row['sys'],
                'dia' => $row['dia'],
                'map' => $row['map'],
                'hr' => $row['hr'],
                'rhythm' => $row['rhythm'],
                'rr' => $row['rr'],
                'breath_type' => $row['breath_type'],
                'suhu' => $row['suhu'],
                'spo2' => $row['spo2'],
                'o2_support' => $row['o2_support'],
                'kesadaran' => ConsciousnessLevel::from($row['kesadaran']),
                'gcs' => $row['gcs'],
                'intake' => $row['intake'],
                'output' => $row['output'],
                'ews_total' => $row['ews_total'],
                'ews_scores' => $row['ews_scores'],
                'ews_risk' => EwsRiskLevel::from($row['ews_risk']),
                'notes' => $row['notes'] ?? null,
                'ventilator_settings' => $row['ventilator_settings'] ?? null,
                'status' => 'final',
                'recorded_by' => $row['recorded_by'],
                'recorded_at' => $recordedAt,
                'idempotency_key' => $encounter->encounter_id.':'.$date.':'.$time,
            ]
        );
    }

    /**
     * @param  array<string, array{0: string, 1: string, 2: string, 3: float, 4: string}>  $catalogue
     * @param  list<string>  $keys
     */
    protected function seedMedications(Observation $observation, array $catalogue, array $keys): void
    {
        foreach (array_values($keys) as $sortOrder => $key) {
            if (! isset($catalogue[$key])) {
                continue;
            }

            [$name, $dose, $category, $volume, $route] = $catalogue[$key];

            ObservationMedication::query()->updateOrCreate(
                [
                    'observation_id' => $observation->getKey(),
                    'name' => $name,
                    'sort_order' => $sortOrder,
                ],
                [
                    'dose' => $dose,
                    'category' => MedicationCategory::from($category),
                    'volume' => $volume,
                    'route' => $route,
                    'status' => 'diberikan',
                    'given_at' => $observation->recorded_at,
                ]
            );
        }
    }

    /**
     * Seluruh 13 item bundle dijawab pada setiap observasi, mengikuti default
     * formulir observasi.html yang memang mencentang "Ya" untuk semua item.
     * Key pada $gaps dibuat "Tidak" beserta alasannya supaya panel critical gap
     * pada bundles.html punya isi, bukan 100% kosong.
     *
     * @param  list<array{group: string, key: string, label: string}>  $bundleItems
     * @param  array<string, string>  $gaps
     */
    protected function seedBundleAnswers(Observation $observation, array $bundleItems, array $gaps): void
    {
        foreach ($bundleItems as $item) {
            $isGap = array_key_exists($item['key'], $gaps);

            ObservationBundleAnswer::query()->updateOrCreate(
                [
                    'observation_id' => $observation->getKey(),
                    'item_key' => $item['key'],
                ],
                [
                    'bundle_group' => $item['group'],
                    'answer' => $isGap ? BundleAnswer::TIDAK : BundleAnswer::YA,
                    'note' => $isGap ? $gaps[$item['key']] : null,
                ]
            );
        }
    }

    protected function seedDevices(Encounter $encounter, array $rows): void
    {
        foreach ($rows as $row) {
            InvasiveDevice::query()->updateOrCreate(
                [
                    'encounter_id' => $encounter->getKey(),
                    'device_code' => $row['device_code'],
                    'start_date' => Carbon::parse($row['start_date']),
                ],
                [
                    'label' => DeviceCode::from($row['device_code'])->label(),
                    'end_date' => $row['end_date'] ?? null,
                    'is_active' => $row['is_active'] ?? true,
                    'note' => $row['note'] ?? null,
                ]
            );
        }
    }
    /*
    |--------------------------------------------------------------------------
    | Data observasi, obat, dan perangkat
    |--------------------------------------------------------------------------
    */

    protected function observations(): array
    {
        return [

            'enc-159853-icu-20260906' => [
                'medications' => [
                    'norepi' => ['Norepinefrin 4 mg / 50 mL', 'Syringe pump 0,32 mcg/kg/mnt', 'Inotropik / Vasopressor', 50.0, 'Syringe pump'],
                    'mida' => ['Midazolam 50 mg / 50 mL', '2 mg/jam, target RASS -2', 'Sedasi & Analgesia', 50.0, 'Syringe pump'],
                    'mero' => ['Meropenem 1 g', '1 g tiap 8 jam', 'Antibiotik', 100.0, 'IV intermittent'],
                    'ringer' => ['Ringer Laktat', 'Loading 500 mL', 'Cairan & Elektrolit', 500.0, 'IV'],
                    'kcl' => ['KCl dalam NaCl 0,9%', '25 mEq per 500 mL', 'Cairan & Elektrolit', 500.0, 'IV'],
                    'nacl' => ['NaCl 0,9%', 'Maintenance 40 mL/jam', 'Cairan & Elektrolit', 500.0, 'IV'],
                    'dextrosa' => ['Dextrose 5%', 'Maintenance 40 mL/jam', 'Cairan & Elektrolit', 500.0, 'IV'],
                ],
                'devices' => [
                    ['device_code' => 'ett', 'start_date' => '2026-09-06', 'note' => 'ETT No. 7.5 cuffed, diikat 1 cm di atas garis Zemour'],
                    ['device_code' => 'cvc', 'start_date' => '2026-09-06', 'note' => 'CVC jugular dextra 7.5 Fr, dressing diganti tiap 7 hari'],
                    ['device_code' => 'ventTubing', 'start_date' => '2026-09-06', 'note' => 'Circuit ventilator tipe adult'],
                    ['device_code' => 'ngt', 'start_date' => '2026-09-06', 'note' => 'NGT No. 14 dengan aspirat tersambung ke bejana'],
                    ['device_code' => 'arterial', 'start_date' => '2026-09-06', 'note' => 'Arteri line radial dextra 20 G, transducer set'],
                    ['device_code' => 'dc', 'start_date' => '2026-09-06', 'note' => 'Dower catheter Foley No. 16'],
                    ['device_code' => 'infus', 'start_date' => '2026-09-06', 'note' => 'Dua jalur infus perifer, tangan kiri dan kanan'],
                    ['device_code' => 'lain', 'start_date' => '2026-09-06', 'note' => 'Rawat luka ulkus dekubitus grade 1 sacrum'],
                ],
                'observations' => [
                    [
                        'at' => '2026-09-06 08:00',
                        'sys' => 88, 'dia' => 52, 'map' => 64, 'hr' => 118, 'rr' => 24,
                        'suhu' => 38.4, 'spo2' => 92, 'kesadaran' => 'DPO', 'gcs' => 8,
                        'rhythm' => 'Sinus Takikardia',
                        'breath_type' => 'PSIMV (FiO2 40%)',
                        'o2_support' => 'Ventilator PSIMV',
                        'intake' => 500, 'output' => 320,
                        'ews_total' => 8,
                        'ews_scores' => ['RR' => 2, 'HR' => 2, 'SBP' => 1, 'SpO2' => 2, 'Temp' => 1, 'Kesadaran' => 0],
                        'ews_risk' => 'emergency',
                        'notes' => 'Suction ETT berkala dan oral hygiene Chlorhexidine 0,2%',
                        'ventilator_settings' => ['mode' => 'PSIMV', 'rr' => 6, 'pc' => 10, 'ps' => 6, 'peep' => 6, 'fio2' => 40],
                        'recorded_by' => 'Ns. Tri Handayani',
                        'meds' => ['norepi', 'mida', 'mero', 'ringer', 'kcl'],
                    ],
                    [
                        'at' => '2026-09-06 10:00',
                        'sys' => 94, 'dia' => 56, 'map' => 69, 'hr' => 112, 'rr' => 22,
                        'suhu' => 38.2, 'spo2' => 93, 'kesadaran' => 'DPO', 'gcs' => 8,
                        'rhythm' => 'Sinus Takikardia',
                        'breath_type' => 'PSIMV (FiO2 45%)',
                        'o2_support' => 'Ventilator PSIMV',
                        'intake' => 450, 'output' => 290,
                        'ews_total' => 7,
                        'ews_scores' => ['RR' => 2, 'HR' => 2, 'SBP' => 1, 'SpO2' => 1, 'Temp' => 1, 'Kesadaran' => 0],
                        'ews_risk' => 'emergency',
                        'notes' => 'Resusitasi cairan dilanjutkan, pantau respons MAP',
                        'ventilator_settings' => ['mode' => 'PSIMV', 'rr' => 6, 'pc' => 10, 'ps' => 6, 'peep' => 6, 'fio2' => 45],
                        'recorded_by' => 'Ns. Tri Handayani',
                        'meds' => ['norepi', 'mida', 'mero', 'ringer', 'kcl'],
                    ],
                    [
                        'at' => '2026-09-06 12:00',
                        'sys' => 102, 'dia' => 60, 'map' => 74, 'hr' => 104, 'rr' => 20,
                        'suhu' => 37.6, 'spo2' => 95, 'kesadaran' => 'DPO', 'gcs' => 8,
                        'rhythm' => 'Sinus Takikardia',
                        'breath_type' => 'PSIMV (FiO2 40%)',
                        'o2_support' => 'Ventilator PSIMV',
                        'intake' => 400, 'output' => 260,
                        'ews_total' => 1,
                        'ews_scores' => ['RR' => 0, 'HR' => 1, 'SBP' => 0, 'SpO2' => 0, 'Temp' => 0, 'Kesadaran' => 0],
                        'ews_risk' => 'low',
                        'notes' => 'Reposisikan miring kanan atau miring kiri setiap 2 jam',
                        'ventilator_settings' => ['mode' => 'PSIMV', 'rr' => 14, 'pc' => 12, 'ps' => 8, 'peep' => 6, 'fio2' => 40],
                        'recorded_by' => 'Ns. Tri Handayani',
                        'meds' => ['norepi', 'mida', 'mero', 'ringer', 'kcl'],
                    ],
                    [
                        'at' => '2026-09-06 14:00',
                        'sys' => 108, 'dia' => 64, 'map' => 79, 'hr' => 98, 'rr' => 20,
                        'suhu' => 37.4, 'spo2' => 95, 'kesadaran' => 'DPO', 'gcs' => 9,
                        'rhythm' => 'Sinus Takikardia',
                        'breath_type' => 'PSIMV (FiO2 40%)',
                        'o2_support' => 'Ventilator PSIMV',
                        'intake' => 400, 'output' => 250,
                        'ews_total' => 1,
                        'ews_scores' => ['RR' => 0, 'HR' => 1, 'SBP' => 0, 'SpO2' => 0, 'Temp' => 0, 'Kesadaran' => 0],
                        'ews_risk' => 'low',
                        'notes' => 'Norepinefrin dititrasi turun sesuai respons MAP',
                        'ventilator_settings' => ['mode' => 'PSIMV', 'rr' => 16, 'pc' => 12, 'ps' => 8, 'peep' => 6, 'fio2' => 40],
                        'recorded_by' => 'Ns. Tri Handayani',
                        'meds' => ['norepi', 'mida', 'mero', 'ringer'],
                        'gaps' => ['vap_3' => 'Oral hygiene terlewat pada shift siang'],
                    ],
                    [
                        'at' => '2026-09-06 16:00',
                        'sys' => 112, 'dia' => 66, 'map' => 81, 'hr' => 94, 'rr' => 18,
                        'suhu' => 37.2, 'spo2' => 96, 'kesadaran' => 'DPO', 'gcs' => 9,
                        'rhythm' => 'Sinus Ritme',
                        'breath_type' => 'PSIMV (FiO2 40%)',
                        'o2_support' => 'Ventilator PSIMV',
                        'intake' => 380, 'output' => 240,
                        'ews_total' => 1,
                        'ews_scores' => ['RR' => 0, 'HR' => 1, 'SBP' => 0, 'SpO2' => 0, 'Temp' => 0, 'Kesadaran' => 0],
                        'ews_risk' => 'low',
                        'notes' => 'Kultur sputum dikirim laboratorium',
                        'ventilator_settings' => ['mode' => 'PSIMV', 'rr' => 16, 'pc' => 12, 'ps' => 8, 'peep' => 5, 'fio2' => 40],
                        'recorded_by' => 'Ns. Tri Handayani',
                        'meds' => ['norepi', 'mida', 'mero', 'nacl'],
                    ],
                    [
                        'at' => '2026-09-06 20:00',
                        'sys' => 110, 'dia' => 64, 'map' => 79, 'hr' => 92, 'rr' => 18,
                        'suhu' => 37.0, 'spo2' => 96, 'kesadaran' => 'DPO', 'gcs' => 9,
                        'rhythm' => 'Sinus Ritme',
                        'breath_type' => 'PSIMV (FiO2 40%)',
                        'o2_support' => 'Ventilator PSIMV',
                        'intake' => 350, 'output' => 230,
                        'ews_total' => 1,
                        'ews_scores' => ['RR' => 0, 'HR' => 1, 'SBP' => 0, 'SpO2' => 0, 'Temp' => 0, 'Kesadaran' => 0],
                        'ews_risk' => 'low',
                        'notes' => 'Suction ETT berkala dan oral hygiene Chlorhexidine 0,2%',
                        'ventilator_settings' => ['mode' => 'PSIMV', 'rr' => 16, 'pc' => 12, 'ps' => 8, 'peep' => 5, 'fio2' => 40],
                        'recorded_by' => 'Ns. Tri Handayani',
                        'meds' => ['norepi', 'mida', 'mero', 'nacl'],
                        'gaps' => ['clabsi_3' => 'Dressing CVC basah, dijadwalkan diganti'],
                    ],
                    [
                        'at' => '2026-09-07 00:00',
                        'sys' => 106, 'dia' => 62, 'map' => 77, 'hr' => 90, 'rr' => 16,
                        'suhu' => 36.9, 'spo2' => 97, 'kesadaran' => 'DPO', 'gcs' => 10,
                        'rhythm' => 'Sinus Ritme',
                        'breath_type' => 'PSIMV (FiO2 35%)',
                        'o2_support' => 'Ventilator PSIMV',
                        'intake' => 300, 'output' => 210,
                        'ews_total' => 0,
                        'ews_scores' => ['RR' => 0, 'HR' => 0, 'SBP' => 0, 'SpO2' => 0, 'Temp' => 0, 'Kesadaran' => 0],
                        'ews_risk' => 'low',
                        'notes' => 'Continued weaning, turunkan FiO2 bertahap',
                        'ventilator_settings' => ['mode' => 'PSIMV', 'rr' => 18, 'pc' => 12, 'ps' => 8, 'peep' => 5, 'fio2' => 35],
                        'recorded_by' => 'Ns. Tri Handayani',
                        'meds' => ['norepi', 'mida', 'mero', 'nacl'],
                    ],
                    [
                        'at' => '2026-09-07 04:00',
                        'sys' => 108, 'dia' => 64, 'map' => 79, 'hr' => 88, 'rr' => 16,
                        'suhu' => 36.8, 'spo2' => 97, 'kesadaran' => 'DPO', 'gcs' => 10,
                        'rhythm' => 'Sinus Ritme',
                        'breath_type' => 'PSIMV (FiO2 35%)',
                        'o2_support' => 'Ventilator PSIMV',
                        'intake' => 300, 'output' => 200,
                        'ews_total' => 0,
                        'ews_scores' => ['RR' => 0, 'HR' => 0, 'SBP' => 0, 'SpO2' => 0, 'Temp' => 0, 'Kesadaran' => 0],
                        'ews_risk' => 'low',
                        'notes' => 'Reposisikan miring kanan atau miring kiri setiap 2 jam',
                        'ventilator_settings' => ['mode' => 'PSIMV', 'rr' => 18, 'pc' => 12, 'ps' => 8, 'peep' => 5, 'fio2' => 35],
                        'recorded_by' => 'Ns. Tri Handayani',
                        'meds' => ['norepi', 'mida', 'mero', 'nacl'],
                        'gaps' => ['vap_2' => 'Posisi kepala tidak dapat diubah karena tindakan'],
                    ],
                    [
                        'at' => '2026-09-07 08:00',
                        'sys' => 112, 'dia' => 66, 'map' => 81, 'hr' => 86, 'rr' => 16,
                        'suhu' => 36.9, 'spo2' => 97, 'kesadaran' => 'DPO', 'gcs' => 10,
                        'rhythm' => 'Sinus Ritme',
                        'breath_type' => 'PSIMV (FiO2 30%)',
                        'o2_support' => 'Ventilator PSIMV',
                        'intake' => 320, 'output' => 210,
                        'ews_total' => 0,
                        'ews_scores' => ['RR' => 0, 'HR' => 0, 'SBP' => 0, 'SpO2' => 0, 'Temp' => 0, 'Kesadaran' => 0],
                        'ews_risk' => 'low',
                        'notes' => 'Konst Written Marques akan turunkan FiO2 bertahap',
                        'ventilator_settings' => ['mode' => 'PSIMV', 'rr' => 20, 'pc' => 10, 'ps' => 8, 'peep' => 4, 'fio2' => 30],
                        'recorded_by' => 'dr. Rangga Saputra',
                        'meds' => ['norepi', 'mida', 'mero', 'dextrosa'],
                    ],
                    [
                        'at' => '2026-09-07 12:00',
                        'sys' => 114, 'dia' => 68, 'map' => 83, 'hr' => 84, 'rr' => 16,
                        'suhu' => 37.0, 'spo2' => 98, 'kesadaran' => 'DPO', 'gcs' => 10,
                        'rhythm' => 'Sinus Ritme',
                        'breath_type' => 'PSIMV (FiO2 30%)',
                        'o2_support' => 'Ventilator PSIMV',
                        'intake' => 320, 'output' => 200,
                        'ews_total' => 0,
                        'ews_scores' => ['RR' => 0, 'HR' => 0, 'SBP' => 0, 'SpO2' => 0, 'Temp' => 0, 'Kesadaran' => 0],
                        'ews_risk' => 'low',
                        'notes' => 'Rencana: monitoring hemodinamik per jam, evaluasi balance cairan tiap 6 jam, kultur sputum ulang bila febris di atas 38.5 C',
                        'ventilator_settings' => ['mode' => 'PSIMV', 'rr' => 20, 'pc' => 10, 'ps' => 8, 'peep' => 4, 'fio2' => 30],
                        'recorded_by' => 'dr. Rangga Saputra',
                        'meds' => ['norepi', 'mida', 'mero', 'dextrosa'],
                    ],
                ],
            ],

            'enc-162210-hcu-20260910' => [
                'medications' => [
                    'seftriakson' => ['Seftriakson 1 g', '1 g tiap 24 jam', 'Antibiotik', 100.0, 'IV intermittent'],
                    'nacl' => ['NaCl 0,9%', 'Maintenance 40 mL/jam', 'Cairan & Elektrolit', 500.0, 'IV'],
                    'dekstrosa' => ['Dextrose 5%', 'Maintenance 40 mL/jam', 'Cairan & Elektrolit', 500.0, 'IV'],
                    'metformin' => ['Metformin 850 mg', '850 mg 1x/hari setelah makan', 'Lainnya', 0.0, 'Oral'],
                    'nebu' => ['Nebulizer Salbutamol', '2,5 mg nebulasi setiap 8 jam', 'Lainnya', 2.5, 'Inhalasi'],
                    'parasetamol' => ['Paracetamol 500 mg', '500 mg 1x/bila demam', 'Obat Systemic', 0.0, 'Oral'],
                ],
                'devices' => [
                    ['device_code' => 'infus', 'start_date' => '2026-09-10', 'note' => 'Satu jalur infus perifer tangan kiri'],
                    ['device_code' => 'lain', 'start_date' => '2026-09-10', 'note' => 'Nebulizer dan masker oksigen nasal kanul'],
                ],
                'observations' => [
                    [
                        'at' => '2026-09-10 14:00',
                        'sys' => 128, 'dia' => 78, 'map' => 95, 'hr' => 96, 'rr' => 22,
                        'suhu' => 38.6, 'spo2' => 94, 'kesadaran' => 'Alert', 'gcs' => 15,
                        'rhythm' => 'Sinus Takikardia', 'breath_type' => 'Takipnea', 'o2_support' => 'Nasal Kanul',
                        'intake' => 300, 'output' => 200,
                        'ews_total' => 6,
                        'ews_scores' => ['RR' => 2, 'HR' => 1, 'SBP' => 0, 'SpO2' => 1, 'Temp' => 2, 'Kesadaran' => 0],
                        'ews_risk' => 'high',
                        'notes' => 'Nebulizer salbutamol 2,5 mg',
                        'recorded_by' => 'Bd. Sari Wulandari',
                        'meds' => ['seftriakson', 'nacl', 'metformin', 'nebu', 'parasetamol'],
                    ],
                    [
                        'at' => '2026-09-10 18:00',
                        'sys' => 126, 'dia' => 76, 'map' => 93, 'hr' => 94, 'rr' => 21,
                        'suhu' => 38.3, 'spo2' => 95, 'kesadaran' => 'Alert', 'gcs' => 15,
                        'rhythm' => 'Sinus Takikardia', 'breath_type' => 'Takipnea', 'o2_support' => 'Nasal Kanul',
                        'intake' => 280, 'output' => 190,
                        'ews_total' => 4,
                        'ews_scores' => ['RR' => 2, 'HR' => 1, 'SBP' => 0, 'SpO2' => 0, 'Temp' => 1, 'Kesadaran' => 0],
                        'ews_risk' => 'medium',
                        'notes' => 'Fisioterapi napas dan mobilisasi duduk',
                        'recorded_by' => 'Bd. Sari Wulandari',
                        'meds' => ['seftriakson', 'nacl', 'metformin', 'nebu', 'parasetamol'],
                    ],
                    [
                        'at' => '2026-09-10 22:00',
                        'sys' => 124, 'dia' => 74, 'map' => 91, 'hr' => 90, 'rr' => 20,
                        'suhu' => 38.0, 'spo2' => 95, 'kesadaran' => 'Alert', 'gcs' => 15,
                        'rhythm' => 'Sinus Ritme', 'breath_type' => 'Reguler', 'o2_support' => 'Nasal Kanul',
                        'intake' => 260, 'output' => 180,
                        'ews_total' => 0,
                        'ews_scores' => ['RR' => 0, 'HR' => 0, 'SBP' => 0, 'SpO2' => 0, 'Temp' => 0, 'Kesadaran' => 0],
                        'ews_risk' => 'low',
                        'notes' => 'Monitoring gula darah tiap 4 jam',
                        'recorded_by' => 'Bd. Sari Wulandari',
                        'meds' => ['seftriakson', 'nacl', 'metformin', 'nebu'],
                    ],
                    [
                        'at' => '2026-09-11 02:00',
                        'sys' => 122, 'dia' => 74, 'map' => 90, 'hr' => 88, 'rr' => 19,
                        'suhu' => 37.8, 'spo2' => 96, 'kesadaran' => 'Alert', 'gcs' => 15,
                        'rhythm' => 'Sinus Ritme', 'breath_type' => 'Reguler', 'o2_support' => 'Simple Mask',
                        'intake' => 240, 'output' => 170,
                        'ews_total' => 0,
                        'ews_scores' => ['RR' => 0, 'HR' => 0, 'SBP' => 0, 'SpO2' => 0, 'Temp' => 0, 'Kesadaran' => 0],
                        'ews_risk' => 'low',
                        'notes' => 'Edukasi diet dan hidrasi',
                        'recorded_by' => 'Bd. Sari Wulandari',
                        'meds' => ['seftriakson', 'nacl', 'metformin', 'dekstrosa'],
                    ],
                    [
                        'at' => '2026-09-11 06:00',
                        'sys' => 124, 'dia' => 76, 'map' => 92, 'hr' => 86, 'rr' => 18,
                        'suhu' => 37.6, 'spo2' => 96, 'kesadaran' => 'Alert', 'gcs' => 15,
                        'rhythm' => 'Sinus Ritme', 'breath_type' => 'Reguler', 'o2_support' => 'Simple Mask',
                        'intake' => 250, 'output' => 170,
                        'ews_total' => 0,
                        'ews_scores' => ['RR' => 0, 'HR' => 0, 'SBP' => 0, 'SpO2' => 0, 'Temp' => 0, 'Kesadaran' => 0],
                        'ews_risk' => 'low',
                        'notes' => 'Nebulizer salbutamol 2,5 mg',
                        'recorded_by' => 'Bd. Sari Wulandari',
                        'meds' => ['seftriakson', 'nacl', 'metformin', 'dekstrosa'],
                    ],
                    [
                        'at' => '2026-09-11 10:00',
                        'sys' => 126, 'dia' => 78, 'map' => 94, 'hr' => 84, 'rr' => 18,
                        'suhu' => 37.4, 'spo2' => 97, 'kesadaran' => 'Alert', 'gcs' => 15,
                        'rhythm' => 'Sinus Ritme', 'breath_type' => 'Reguler', 'o2_support' => 'Simple Mask',
                        'intake' => 260, 'output' => 175,
                        'ews_total' => 0,
                        'ews_scores' => ['RR' => 0, 'HR' => 0, 'SBP' => 0, 'SpO2' => 0, 'Temp' => 0, 'Kesadaran' => 0],
                        'ews_risk' => 'low',
                        'notes' => 'Fisioterapi napas dan mobilisasi duduk',
                        'recorded_by' => 'Bd. Sari Wulandari',
                        'meds' => ['seftriakson', 'nacl', 'metformin', 'dekstrosa'],
                    ],
                    [
                        'at' => '2026-09-11 14:00',
                        'sys' => 128, 'dia' => 78, 'map' => 95, 'hr' => 82, 'rr' => 18,
                        'suhu' => 37.2, 'spo2' => 97, 'kesadaran' => 'Alert', 'gcs' => 15,
                        'rhythm' => 'Sinus Ritme', 'breath_type' => 'Spontan', 'o2_support' => 'Simple Mask',
                        'intake' => 280, 'output' => 180,
                        'ews_total' => 0,
                        'ews_scores' => ['RR' => 0, 'HR' => 0, 'SBP' => 0, 'SpO2' => 0, 'Temp' => 0, 'Kesadaran' => 0],
                        'ews_risk' => 'low',
                        'notes' => 'Edukasi diet dan hidrasi',
                        'recorded_by' => 'Bd. Sari Wulandari',
                        'meds' => ['seftriakson', 'nacl', 'metformin', 'parasetamol'],
                    ],
                    [
                        'at' => '2026-09-11 18:00',
                        'sys' => 126, 'dia' => 76, 'map' => 93, 'hr' => 80, 'rr' => 17,
                        'suhu' => 37.1, 'spo2' => 97, 'kesadaran' => 'Alert', 'gcs' => 15,
                        'rhythm' => 'Sinus Ritme', 'breath_type' => 'Spontan', 'o2_support' => 'Tidak',
                        'intake' => 260, 'output' => 175,
                        'ews_total' => 0,
                        'ews_scores' => ['RR' => 0, 'HR' => 0, 'SBP' => 0, 'SpO2' => 0, 'Temp' => 0, 'Kesadaran' => 0],
                        'ews_risk' => 'low',
                        'notes' => 'Oksigen dihentikan, toleransi napas baik',
                        'recorded_by' => 'Bd. Sari Wulandari',
                        'meds' => ['nacl', 'metformin', 'parasetamol'],
                        'gaps' => ['vap_4' => 'APD tidak dipakai saat nebulisasi'],
                    ],
                    [
                        'at' => '2026-09-11 22:00',
                        'sys' => 124, 'dia' => 74, 'map' => 91, 'hr' => 78, 'rr' => 16,
                        'suhu' => 37.0, 'spo2' => 97, 'kesadaran' => 'Alert', 'gcs' => 15,
                        'rhythm' => 'Sinus Ritme', 'breath_type' => 'Spontan', 'o2_support' => 'Tidak',
                        'intake' => 250, 'output' => 170,
                        'ews_total' => 0,
                        'ews_scores' => ['RR' => 0, 'HR' => 0, 'SBP' => 0, 'SpO2' => 0, 'Temp' => 0, 'Kesadaran' => 0],
                        'ews_risk' => 'low',
                        'notes' => 'Rencana: evaluasi ulang penunjang 24 jam',
                        'recorded_by' => 'Bd. Sari Wulandari',
                        'meds' => ['nacl', 'metformin'],
                    ],
                    [
                        'at' => '2026-09-12 06:00',
                        'sys' => 122, 'dia' => 74, 'map' => 90, 'hr' => 76, 'rr' => 16,
                        'suhu' => 36.9, 'spo2' => 98, 'kesadaran' => 'Alert', 'gcs' => 15,
                        'rhythm' => 'Sinus Ritme', 'breath_type' => 'Spontan', 'o2_support' => 'Tidak',
                        'intake' => 240, 'output' => 165,
                        'ews_total' => 0,
                        'ews_scores' => ['RR' => 0, 'HR' => 0, 'SBP' => 0, 'SpO2' => 0, 'Temp' => 0, 'Kesadaran' => 0],
                        'ews_risk' => 'low',
                        'notes' => 'Pasien siap dipindahkan ke ruang perawatan biasa',
                        'recorded_by' => 'Bd. Sari Wulandari',
                        'meds' => ['nacl', 'metformin'],
                    ],
                ],
            ],
            'enc-158774-icu-20260908' => [
                'medications' => [
                    'asa' => ['Asam asetilsalisilat 160 mg', '160 mg 1x/hari', 'Obat Systemic', 0.0, 'Oral'],
                    'clopidogrel' => ['Clopidogrel 75 mg', '75 mg 1x/hari', 'Obat Systemic', 0.0, 'Oral'],
                    'heparin' => ['Heparin 5000 IU', 'Bolus 5000 IU lalu 1000 IU/jam', 'Obat Systemic', 0.0, 'IV infus'],
                    'atorvastatin' => ['Atorvastatin 40 mg', '40 mg 1x/malam', 'Lainnya', 0.0, 'Oral'],
                    'nacl' => ['NaCl 0,9%', 'Maintenance 40 mL/jam', 'Cairan & Elektrolit', 500.0, 'IV'],
                ],
                'devices' => [
                    ['device_code' => 'infus', 'start_date' => '2026-09-08', 'note' => 'Satu jalur infus perifer tangan kanan'],
                    ['device_code' => 'lain', 'start_date' => '2026-09-08', 'note' => 'Monitor EKG kontinu dan defibrilator siap'],
                ],
                'observations' => [
                    [
                        'at' => '2026-09-08 04:00',
                        'sys' => 96, 'dia' => 60, 'map' => 72, 'hr' => 112, 'rr' => 20,
                        'suhu' => 36.8, 'spo2' => 97, 'kesadaran' => 'Alert', 'gcs' => 15,
                        'rhythm' => 'Sinus Takikardia', 'breath_type' => 'Dangkal', 'o2_support' => 'Nasal Kanul',
                        'intake' => 300, 'output' => 200,
                        'ews_total' => 3,
                        'ews_scores' => ['RR' => 0, 'HR' => 2, 'SBP' => 1, 'SpO2' => 0, 'Temp' => 0, 'Kesadaran' => 0],
                        'ews_risk' => 'medium',
                        'notes' => 'Monitor EKG kontinu, denyut jantung teronitor',
                        'recorded_by' => 'Ns. Tri Handayani',
                        'meds' => ['asa', 'clopidogrel', 'heparin', 'atorvastatin', 'nacl'],
                    ],
                    [
                        'at' => '2026-09-08 08:00',
                        'sys' => 100, 'dia' => 62, 'map' => 75, 'hr' => 104, 'rr' => 19,
                        'suhu' => 36.9, 'spo2' => 97, 'kesadaran' => 'Alert', 'gcs' => 15,
                        'rhythm' => 'Sinus Takikardia', 'breath_type' => 'Dangkal', 'o2_support' => 'Nasal Kanul',
                        'intake' => 300, 'output' => 210,
                        'ews_total' => 2,
                        'ews_scores' => ['RR' => 0, 'HR' => 1, 'SBP' => 1, 'SpO2' => 0, 'Temp' => 0, 'Kesadaran' => 0],
                        'ews_risk' => 'low',
                        'notes' => 'Pasien dalam persiapan prosedur PCI primer',
                        'recorded_by' => 'Ns. Tri Handayani',
                        'meds' => ['asa', 'clopidogrel', 'heparin', 'atorvastatin', 'nacl'],
                    ],
                    [
                        'at' => '2026-09-08 12:00',
                        'sys' => 104, 'dia' => 66, 'map' => 79, 'hr' => 96, 'rr' => 18,
                        'suhu' => 37.0, 'spo2' => 98, 'kesadaran' => 'Alert', 'gcs' => 15,
                        'rhythm' => 'Sinus Takikardia', 'breath_type' => 'Reguler', 'o2_support' => 'Nasal Kanul',
                        'intake' => 320, 'output' => 220,
                        'ews_total' => 1,
                        'ews_scores' => ['RR' => 0, 'HR' => 1, 'SBP' => 0, 'SpO2' => 0, 'Temp' => 0, 'Kesadaran' => 0],
                        'ews_risk' => 'low',
                        'notes' => 'Pascapci, denyut jantung regular, tanpa keluhan',
                        'recorded_by' => 'Ns. Tri Handayani',
                        'meds' => ['asa', 'clopidogrel', 'heparin', 'atorvastatin', 'nacl'],
                    ],
                    [
                        'at' => '2026-09-08 16:00',
                        'sys' => 108, 'dia' => 68, 'map' => 81, 'hr' => 90, 'rr' => 17,
                        'suhu' => 37.1, 'spo2' => 98, 'kesadaran' => 'Alert', 'gcs' => 15,
                        'rhythm' => 'Sinus Ritme', 'breath_type' => 'Reguler', 'o2_support' => 'Simple Mask',
                        'intake' => 320, 'output' => 230,
                        'ews_total' => 0,
                        'ews_scores' => ['RR' => 0, 'HR' => 0, 'SBP' => 0, 'SpO2' => 0, 'Temp' => 0, 'Kesadaran' => 0],
                        'ews_risk' => 'low',
                        'notes' => 'Nyeri dada teratasi dengan terapi',
                        'recorded_by' => 'Ns. Tri Handayani',
                        'meds' => ['asa', 'clopidogrel', 'heparin', 'atorvastatin', 'nacl'],
                    ],
                    [
                        'at' => '2026-09-08 20:00',
                        'sys' => 110, 'dia' => 70, 'map' => 83, 'hr' => 86, 'rr' => 16,
                        'suhu' => 37.0, 'spo2' => 98, 'kesadaran' => 'Alert', 'gcs' => 15,
                        'rhythm' => 'Sinus Ritme', 'breath_type' => 'Reguler', 'o2_support' => 'Simple Mask',
                        'intake' => 300, 'output' => 220,
                        'ews_total' => 0,
                        'ews_scores' => ['RR' => 0, 'HR' => 0, 'SBP' => 0, 'SpO2' => 0, 'Temp' => 0, 'Kesadaran' => 0],
                        'ews_risk' => 'low',
                        'notes' => 'Antikoagulan heparin dilanjutkan sesuai aPTT',
                        'recorded_by' => 'Ns. Tri Handayani',
                        'meds' => ['asa', 'clopidogrel', 'heparin', 'atorvastatin', 'nacl'],
                        'gaps' => ['clabsi_2' => 'Scrub the hub kurang dari 15 detik'],
                    ],
                    [
                        'at' => '2026-09-09 00:00',
                        'sys' => 112, 'dia' => 70, 'map' => 84, 'hr' => 84, 'rr' => 16,
                        'suhu' => 36.9, 'spo2' => 98, 'kesadaran' => 'Alert', 'gcs' => 15,
                        'rhythm' => 'Sinus Ritme', 'breath_type' => 'Reguler', 'o2_support' => 'Simple Mask',
                        'intake' => 280, 'output' => 210,
                        'ews_total' => 0,
                        'ews_scores' => ['RR' => 0, 'HR' => 0, 'SBP' => 0, 'SpO2' => 0, 'Temp' => 0, 'Kesadaran' => 0],
                        'ews_risk' => 'low',
                        'notes' => 'Mobilisasi bertahap dengan bantuan',
                        'recorded_by' => 'Ns. Tri Handayani',
                        'meds' => ['asa', 'clopidogrel', 'heparin', 'nacl'],
                    ],
                    [
                        'at' => '2026-09-09 04:00',
                        'sys' => 114, 'dia' => 72, 'map' => 86, 'hr' => 82, 'rr' => 15,
                        'suhu' => 36.8, 'spo2' => 98, 'kesadaran' => 'Alert', 'gcs' => 15,
                        'rhythm' => 'Sinus Ritme', 'breath_type' => 'Spontan', 'o2_support' => 'Nasal Kanul',
                        'intake' => 280, 'output' => 205,
                        'ews_total' => 0,
                        'ews_scores' => ['RR' => 0, 'HR' => 0, 'SBP' => 0, 'SpO2' => 0, 'Temp' => 0, 'Kesadaran' => 0],
                        'ews_risk' => 'low',
                        'notes' => 'Rencana: rehabilitasi kardiak fase awal',
                        'recorded_by' => 'Ns. Tri Handayani',
                        'meds' => ['asa', 'clopidogrel', 'nacl'],
                    ],
                    [
                        'at' => '2026-09-09 08:00',
                        'sys' => 116, 'dia' => 72, 'map' => 87, 'hr' => 80, 'rr' => 15,
                        'suhu' => 36.8, 'spo2' => 99, 'kesadaran' => 'Alert', 'gcs' => 15,
                        'rhythm' => 'Sinus Ritme', 'breath_type' => 'Spontan', 'o2_support' => 'Nasal Kanul',
                        'intake' => 300, 'output' => 210,
                        'ews_total' => 0,
                        'ews_scores' => ['RR' => 0, 'HR' => 0, 'SBP' => 0, 'SpO2' => 0, 'Temp' => 0, 'Kesadaran' => 0],
                        'ews_risk' => 'low',
                        'recorded_by' => 'Ns. Tri Handayani',
                        'notes' => 'Pasien sudah tidak membutuhkan oksigen tambahan',
                    ],
                    [
                        'at' => '2026-09-09 12:00',
                        'sys' => 116, 'dia' => 74, 'map' => 88, 'hr' => 78, 'rr' => 15,
                        'suhu' => 36.9, 'spo2' => 99, 'kesadaran' => 'Alert', 'gcs' => 15,
                        'rhythm' => 'Sinus Ritme', 'breath_type' => 'Spontan', 'o2_support' => 'Tidak',
                        'intake' => 300, 'output' => 210,
                        'ews_total' => 0,
                        'ews_scores' => ['RR' => 0, 'HR' => 0, 'SBP' => 0, 'SpO2' => 0, 'Temp' => 0, 'Kesadaran' => 0],
                        'ews_risk' => 'low',
                        'notes' => 'Oksigen dihentikan, napas spontan',
                        'recorded_by' => 'Ns. Tri Handayani',
                        'meds' => ['asa', 'clopidogrel', 'nacl'],
                    ],
                    [
                        'at' => '2026-09-09 16:00',
                        'sys' => 118, 'dia' => 74, 'map' => 89, 'hr' => 76, 'rr' => 14,
                        'suhu' => 36.8, 'spo2' => 99, 'kesadaran' => 'Alert', 'gcs' => 15,
                        'rhythm' => 'Sinus Ritme', 'breath_type' => 'Spontan', 'o2_support' => 'Tidak',
                        'intake' => 280, 'output' => 200,
                        'ews_total' => 0,
                        'ews_scores' => ['RR' => 0, 'HR' => 0, 'SBP' => 0, 'SpO2' => 0, 'Temp' => 0, 'Kesadaran' => 0],
                        'ews_risk' => 'low',
                        'notes' => 'Rencana: rehabilitasi kardiak fase awal',
                        'recorded_by' => 'Ns. Tri Handayani',
                        'meds' => ['asa', 'clopidogrel'],
                    ],
                ],
            ],
            'enc-160045-hcu-20260911' => [
                'medications' => [
                    'insulin' => ['Insulin regular 20 IU/jam', 'Infus 0,1 IU/kg/jam', 'Obat Systemic', 20.0, 'IV infus'],
                    'insulinbasal' => ['Insulin basal 8 IU', '8 unit subkutan 1x/malam', 'Obat Systemic', 0.0, 'SC'],
                    'nacl' => ['NaCl 0,9%', 'Loading dan maintenance', 'Cairan & Elektrolit', 500.0, 'IV'],
                    'kcl' => ['KCl dalam NaCl 0,9%', '25 mEq per 500 mL', 'Cairan & Elektrolit', 500.0, 'IV'],
                    'dekstrosa' => ['Dextrose 5%', 'Maintenance 40 mL/jam', 'Cairan & Elektrolit', 500.0, 'IV'],
                    'seftriakson' => ['Seftriakson 1 g', '1 g tiap 12 jam', 'Antibiotik', 100.0, 'IV intermittent'],
                ],
                'devices' => [
                    ['device_code' => 'infus', 'start_date' => '2026-09-11', 'note' => 'Dua jalur infus perifer untuk insulin dan antibiotik'],
                    ['device_code' => 'dc', 'start_date' => '2026-09-11', 'note' => 'Dower catheter Foley No. 14 untuk monitor IWL'],
                    ['device_code' => 'ngt', 'start_date' => '2026-09-11', 'note' => 'NGT No. 14 untuk dekompresi Aspirat'],
                    ['device_code' => 'lain', 'start_date' => '2026-09-11', 'note' => 'Continuous glucose monitoring'],
                ],
                'observations' => [
                    [
                        'at' => '2026-09-11 19:00',
                        'sys' => 108, 'dia' => 68, 'map' => 81, 'hr' => 118, 'rr' => 24,
                        'suhu' => 37.4, 'spo2' => 97, 'kesadaran' => 'Voice', 'gcs' => 14,
                        'rhythm' => 'Sinus Takikardia', 'breath_type' => 'Takipnea', 'o2_support' => 'Simple Mask',
                        'intake' => 400, 'output' => 280,
                        'ews_total' => 5,
                        'ews_scores' => ['RR' => 2, 'HR' => 2, 'SBP' => 0, 'SpO2' => 0, 'Temp' => 0, 'Kesadaran' => 1],
                        'ews_risk' => 'high',
                        'notes' => 'Monitor glukosa darah tiap 2 jam',
                        'recorded_by' => 'Bd. Sari Wulandari',
                        'meds' => ['insulin', 'nacl', 'seftriakson'],
                    ],
                    [
                        'at' => '2026-09-11 21:00',
                        'sys' => 112, 'dia' => 70, 'map' => 84, 'hr' => 112, 'rr' => 22,
                        'suhu' => 37.2, 'spo2' => 97, 'kesadaran' => 'Alert', 'gcs' => 15,
                        'rhythm' => 'Sinus Takikardia', 'breath_type' => 'Takipnea', 'o2_support' => 'Simple Mask',
                        'intake' => 400, 'output' => 260,
                        'ews_total' => 4,
                        'ews_scores' => ['RR' => 2, 'HR' => 2, 'SBP' => 0, 'SpO2' => 0, 'Temp' => 0, 'Kesadaran' => 0],
                        'ews_risk' => 'medium',
                        'notes' => 'Insulin infus dititrasi sesuai glukosa',
                        'recorded_by' => 'Bd. Sari Wulandari',
                        'meds' => ['insulin', 'nacl', 'seftriakson'],
                    ],
                    [
                        'at' => '2026-09-11 23:00',
                        'sys' => 116, 'dia' => 72, 'map' => 87, 'hr' => 106, 'rr' => 20,
                        'suhu' => 37.0, 'spo2' => 98, 'kesadaran' => 'Alert', 'gcs' => 15,
                        'rhythm' => 'Sinus Takikardia', 'breath_type' => 'Dangkal', 'o2_support' => 'Simple Mask',
                        'intake' => 420, 'output' => 240,
                        'ews_total' => 1,
                        'ews_scores' => ['RR' => 0, 'HR' => 1, 'SBP' => 0, 'SpO2' => 0, 'Temp' => 0, 'Kesadaran' => 0],
                        'ews_risk' => 'low',
                        'notes' => 'Koreksi cairan sesuai hitungan IWL per jam',
                        'recorded_by' => 'Bd. Sari Wulandari',
                        'meds' => ['insulin', 'nacl', 'seftriakson', 'kcl'],
                    ],
                    [
                        'at' => '2026-09-12 01:00',
                        'sys' => 118, 'dia' => 74, 'map' => 90, 'hr' => 102, 'rr' => 19,
                        'suhu' => 36.9, 'spo2' => 98, 'kesadaran' => 'Alert', 'gcs' => 15,
                        'rhythm' => 'Sinus Takikardia', 'breath_type' => 'Dangkal', 'o2_support' => 'Simple Mask',
                        'intake' => 400, 'output' => 230,
                        'ews_total' => 1,
                        'ews_scores' => ['RR' => 0, 'HR' => 1, 'SBP' => 0, 'SpO2' => 0, 'Temp' => 0, 'Kesadaran' => 0],
                        'ews_risk' => 'low',
                        'notes' => 'Insulin infus dititrasi sesuai glukosa',
                        'recorded_by' => 'Bd. Sari Wulandari',
                        'meds' => ['insulin', 'nacl', 'seftriakson', 'kcl'],
                    ],
                    [
                        'at' => '2026-09-12 03:00',
                        'sys' => 120, 'dia' => 76, 'map' => 91, 'hr' => 98, 'rr' => 18,
                        'suhu' => 36.8, 'spo2' => 98, 'kesadaran' => 'Alert', 'gcs' => 15,
                        'rhythm' => 'Sinus Takikardia', 'breath_type' => 'Dangkal', 'o2_support' => 'Nasal Kanul',
                        'intake' => 380, 'output' => 220,
                        'ews_total' => 1,
                        'ews_scores' => ['RR' => 0, 'HR' => 1, 'SBP' => 0, 'SpO2' => 0, 'Temp' => 0, 'Kesadaran' => 0],
                        'ews_risk' => 'low',
                        'notes' => 'Perineum hygiene dan perawatan katheter',
                        'recorded_by' => 'Bd. Sari Wulandari',
                        'meds' => ['insulin', 'nacl', 'seftriakson', 'dekstrosa'],
                        'gaps' => ['cauti_3' => 'Perineum hygiene tertunda satu siklus'],
                    ],
                    [
                        'at' => '2026-09-12 05:00',
                        'sys' => 122, 'dia' => 76, 'map' => 92, 'hr' => 96, 'rr' => 17,
                        'suhu' => 36.8, 'spo2' => 98, 'kesadaran' => 'Alert', 'gcs' => 15,
                        'rhythm' => 'Sinus Ritme', 'breath_type' => 'Dangkal', 'o2_support' => 'Nasal Kanul',
                        'intake' => 360, 'output' => 210,
                        'ews_total' => 1,
                        'ews_scores' => ['RR' => 0, 'HR' => 1, 'SBP' => 0, 'SpO2' => 0, 'Temp' => 0, 'Kesadaran' => 0],
                        'ews_risk' => 'low',
                        'notes' => 'Insulin infus dititrasi sesuai glukosa',
                        'recorded_by' => 'Bd. Sari Wulandari',
                        'meds' => ['insulin', 'nacl', 'dekstrosa'],
                    ],
                    [
                        'at' => '2026-09-12 07:00',
                        'sys' => 124, 'dia' => 78, 'map' => 93, 'hr' => 94, 'rr' => 17,
                        'suhu' => 36.7, 'spo2' => 99, 'kesadaran' => 'Alert', 'gcs' => 15,
                        'rhythm' => 'Sinus Ritme', 'breath_type' => 'Spontan', 'o2_support' => 'Nasal Kanul',
                        'intake' => 340, 'output' => 200,
                        'ews_total' => 1,
                        'ews_scores' => ['RR' => 0, 'HR' => 1, 'SBP' => 0, 'SpO2' => 0, 'Temp' => 0, 'Kesadaran' => 0],
                        'ews_risk' => 'low',
                        'notes' => 'Monitor glukosa darah tiap 2 jam',
                        'recorded_by' => 'Bd. Sari Wulandari',
                        'meds' => ['insulin', 'dekstrosa'],
                        'gaps' => ['clabsi_2' => 'Scrub the hub kurang dari 15 detik'],
                    ],
                    [
                        'at' => '2026-09-12 09:00',
                        'sys' => 124, 'dia' => 78, 'map' => 93, 'hr' => 92, 'rr' => 16,
                        'suhu' => 36.8, 'spo2' => 99, 'kesadaran' => 'Alert', 'gcs' => 15,
                        'rhythm' => 'Sinus Ritme', 'breath_type' => 'Spontan', 'o2_support' => 'Nasal Kanul',
                        'intake' => 320, 'output' => 195,
                        'ews_total' => 1,
                        'ews_scores' => ['RR' => 0, 'HR' => 1, 'SBP' => 0, 'SpO2' => 0, 'Temp' => 0, 'Kesadaran' => 0],
                        'ews_risk' => 'low',
                        'notes' => 'Koreksi cairan sesuai hitungan IWL per jam',
                        'recorded_by' => 'Bd. Sari Wulandari',
                        'meds' => ['insulin', 'dekstrosa'],
                    ],
                    [
                        'at' => '2026-09-12 11:00',
                        'sys' => 126, 'dia' => 78, 'map' => 94, 'hr' => 90, 'rr' => 16,
                        'suhu' => 36.7, 'spo2' => 99, 'kesadaran' => 'Alert', 'gcs' => 15,
                        'rhythm' => 'Sinus Ritme', 'breath_type' => 'Spontan', 'o2_support' => 'Tidak',
                        'intake' => 300, 'output' => 190,
                        'ews_total' => 0,
                        'ews_scores' => ['RR' => 0, 'HR' => 0, 'SBP' => 0, 'SpO2' => 0, 'Temp' => 0, 'Kesadaran' => 0],
                        'ews_risk' => 'low',
                        'notes' => 'Insulin infus dihentikan, beralih ke insulin basal',
                        'recorded_by' => 'Bd. Sari Wulandari',
                        'meds' => ['dekstrosa'],
                    ],
                    [
                        'at' => '2026-09-12 13:00',
                        'sys' => 126, 'dia' => 80, 'map' => 95, 'hr' => 88, 'rr' => 16,
                        'suhu' => 36.8, 'spo2' => 99, 'kesadaran' => 'Alert', 'gcs' => 15,
                        'rhythm' => 'Sinus Ritme', 'breath_type' => 'Spontan', 'o2_support' => 'Tidak',
                        'intake' => 300, 'output' => 185,
                        'ews_total' => 0,
                        'ews_scores' => ['RR' => 0, 'HR' => 0, 'SBP' => 0, 'SpO2' => 0, 'Temp' => 0, 'Kesadaran' => 0],
                        'ews_risk' => 'low',
                        'notes' => 'Rencana: kultur urine ulang bila demam',
                        'recorded_by' => 'Bd. Sari Wulandari',
                        'meds' => ['insulinbasal', 'nacl'],
                    ],
                ],
            ],
            'enc-157330-icu-20260905' => [
                'medications' => [
                    'prc' => ['PRC 1 Unit', '1 unit slow drip 4 jam', 'Obat Systemic', 300.0, 'IV'],
                    'nacl' => ['NaCl 0,9%', 'Maintenance dan sisa kebutuhan', 'Cairan & Elektrolit', 500.0, 'IV'],
                    'kcl' => ['KCl dalam NaCl 0,9%', '20 mEq per 500 mL sesuai hasil', 'Cairan & Elektrolit', 500.0, 'IV'],
                    'kalsium' => ['Calcium gluconat dalam NaCl 0,9%', '10 mL 10% bila hipokalsemia', 'Cairan & Elektrolit', 10.0, 'IV'],
                    'furosemid' => ['Furosemid 20 mg IV', '20 mg 1x/hari', 'Lainnya', 0.0, 'IV'],
                    'vitb12' => ['Vitamin B12 1000 mcg', '1000 mcg 1x/minggu', 'Lainnya', 0.0, 'IM'],
                    'epoetin' => ['Epoetin alfa 4000 IU', '4000 IU 2x/minggu', 'Lainnya', 0.0, 'SC'],
                ],
                'devices' => [
                    ['device_code' => 'dc', 'start_date' => '2026-09-05', 'end_date' => '2026-09-06', 'note' => 'Dower catheter Foley No. 16 untuk balance cairan'],
                    ['device_code' => 'infus', 'start_date' => '2026-09-05', 'note' => 'Satu jalur infus perifer tangan kiri'],
                    ['device_code' => 'lain', 'start_date' => '2026-09-05', 'note' => 'Akses vaskular untuk hemodialisis di pundialbaan'],
                ],
                'observations' => [
                    [
                        'at' => '2026-09-05 10:00',
                        'sys' => 150, 'dia' => 90, 'map' => 110, 'hr' => 96, 'rr' => 22,
                        'suhu' => 36.6, 'spo2' => 95, 'kesadaran' => 'Alert', 'gcs' => 15,
                        'rhythm' => 'Sinus Takikardia', 'breath_type' => 'Dangkal', 'o2_support' => 'Nasal Kanul',
                        'intake' => 350, 'output' => 250,
                        'ews_total' => 3,
                        'ews_scores' => ['RR' => 2, 'HR' => 1, 'SBP' => 0, 'SpO2' => 0, 'Temp' => 0, 'Kesadaran' => 0],
                        'ews_risk' => 'medium',
                        'notes' => 'Pantau elektrolit dan IWL per jam',
                        'recorded_by' => 'Ns. Tri Handayani',
                        'meds' => ['prc', 'nacl', 'kcl', 'furosemid'],
                    ],
                    [
                        'at' => '2026-09-05 14:00',
                        'sys' => 148, 'dia' => 88, 'map' => 108, 'hr' => 94, 'rr' => 21,
                        'suhu' => 36.6, 'spo2' => 96, 'kesadaran' => 'Alert', 'gcs' => 15,
                        'rhythm' => 'Sinus Takikardia', 'breath_type' => 'Dangkal', 'o2_support' => 'Nasal Kanul',
                        'intake' => 350, 'output' => 240,
                        'ews_total' => 3,
                        'ews_scores' => ['RR' => 2, 'HR' => 1, 'SBP' => 0, 'SpO2' => 0, 'Temp' => 0, 'Kesadaran' => 0],
                        'ews_risk' => 'medium',
                        'notes' => 'Transfusi PRC diberikan perlahan',
                        'recorded_by' => 'Ns. Tri Handayani',
                        'meds' => ['prc', 'nacl', 'kcl', 'furosemid'],
                    ],
                    [
                        'at' => '2026-09-05 18:00',
                        'sys' => 146, 'dia' => 86, 'map' => 106, 'hr' => 90, 'rr' => 20,
                        'suhu' => 36.7, 'spo2' => 96, 'kesadaran' => 'Alert', 'gcs' => 15,
                        'rhythm' => 'Sinus Takikardia', 'breath_type' => 'Reguler', 'o2_support' => 'Nasal Kanul',
                        'intake' => 340, 'output' => 230,
                        'ews_total' => 0,
                        'ews_scores' => ['RR' => 0, 'HR' => 0, 'SBP' => 0, 'SpO2' => 0, 'Temp' => 0, 'Kesadaran' => 0],
                        'ews_risk' => 'low',
                        'notes' => 'Batasi cairan sesuai perhitungan IWL',
                        'recorded_by' => 'Ns. Tri Handayani',
                        'meds' => ['prc', 'nacl', 'furosemid', 'vitb12'],
                    ],
                    [
                        'at' => '2026-09-05 22:00',
                        'sys' => 144, 'dia' => 86, 'map' => 105, 'hr' => 88, 'rr' => 19,
                        'suhu' => 36.8, 'spo2' => 97, 'kesadaran' => 'Alert', 'gcs' => 15,
                        'rhythm' => 'Sinus Ritme', 'breath_type' => 'Reguler', 'o2_support' => 'Nasal Kanul',
                        'intake' => 320, 'output' => 220,
                        'ews_total' => 0,
                        'ews_scores' => ['RR' => 0, 'HR' => 0, 'SBP' => 0, 'SpO2' => 0, 'Temp' => 0, 'Kesadaran' => 0],
                        'ews_risk' => 'low',
                        'notes' => 'Siapkan akses untuk hemodialisis',
                        'recorded_by' => 'Ns. Tri Handayani',
                        'meds' => ['nacl', 'furosemid', 'vitb12', 'epoetin'],
                    ],
                    [
                        'at' => '2026-09-06 02:00',
                        'sys' => 142, 'dia' => 84, 'map' => 103, 'hr' => 86, 'rr' => 18,
                        'suhu' => 36.7, 'spo2' => 97, 'kesadaran' => 'Alert', 'gcs' => 15,
                        'rhythm' => 'Sinus Ritme', 'breath_type' => 'Reguler', 'o2_support' => 'Simple Mask',
                        'intake' => 300, 'output' => 210,
                        'ews_total' => 0,
                        'ews_scores' => ['RR' => 0, 'HR' => 0, 'SBP' => 0, 'SpO2' => 0, 'Temp' => 0, 'Kesadaran' => 0],
                        'ews_risk' => 'low',
                        'notes' => 'Rencana: hemodialisis terjadwal',
                        'recorded_by' => 'Ns. Tri Handayani',
                        'meds' => ['nacl', 'kcl', 'furosemid', 'epoetin'],
                    ],
                    [
                        'at' => '2026-09-06 06:00',
                        'sys' => 140, 'dia' => 84, 'map' => 102, 'hr' => 84, 'rr' => 17,
                        'suhu' => 36.6, 'spo2' => 97, 'kesadaran' => 'Alert', 'gcs' => 15,
                        'rhythm' => 'Sinus Ritme', 'breath_type' => 'Spontan', 'o2_support' => 'Simple Mask',
                        'intake' => 300, 'output' => 205,
                        'ews_total' => 0,
                        'ews_scores' => ['RR' => 0, 'HR' => 0, 'SBP' => 0, 'SpO2' => 0, 'Temp' => 0, 'Kesadaran' => 0],
                        'ews_risk' => 'low',
                        'notes' => 'Pantau elektrolit dan IWL per jam',
                        'recorded_by' => 'Ns. Tri Handayani',
                        'meds' => ['nacl', 'kcl', 'furosemid', 'epoetin', 'kalsium'],
                        'gaps' => ['cauti_2' => 'Urine bag tidak digantung lebih rendah dari bladder'],
                    ],
                    [
                        'at' => '2026-09-06 10:00',
                        'sys' => 138, 'dia' => 82, 'map' => 100, 'hr' => 82, 'rr' => 17,
                        'suhu' => 36.5, 'spo2' => 97, 'kesadaran' => 'Alert', 'gcs' => 15,
                        'rhythm' => 'Sinus Ritme', 'breath_type' => 'Spontan', 'o2_support' => 'Nasal Kanul',
                        'intake' => 290, 'output' => 200,
                        'ews_total' => 0,
                        'ews_scores' => ['RR' => 0, 'HR' => 0, 'SBP' => 0, 'SpO2' => 0, 'Temp' => 0, 'Kesadaran' => 0],
                        'ews_risk' => 'low',
                        'notes' => 'Hemodialisis selesai, pantau sadar',
                        'recorded_by' => 'Ns. Tri Handayani',
                        'meds' => ['nacl', 'kcl', 'epoetin', 'kalsium'],
                        'gaps' => ['vap_5' => 'Suction endotrakeal tertunda pada jadwal pagi'],
                    ],
                    [
                        'at' => '2026-09-06 14:00',
                        'sys' => 136, 'dia' => 82, 'map' => 100, 'hr' => 80, 'rr' => 16,
                        'suhu' => 36.5, 'spo2' => 98, 'kesadaran' => 'Alert', 'gcs' => 15,
                        'rhythm' => 'Sinus Ritme', 'breath_type' => 'Spontan', 'o2_support' => 'Nasal Kanul',
                        'intake' => 280, 'output' => 195,
                        'ews_total' => 0,
                        'ews_scores' => ['RR' => 0, 'HR' => 0, 'SBP' => 0, 'SpO2' => 0, 'Temp' => 0, 'Kesadaran' => 0],
                        'ews_risk' => 'low',
                        'notes' => 'Batasi cairan sesuai perhitungan IWL',
                        'recorded_by' => 'Ns. Tri Handayani',
                        'meds' => ['nacl', 'kcl', 'epoetin'],
                    ],
                    [
                        'at' => '2026-09-06 18:00',
                        'sys' => 134, 'dia' => 80, 'map' => 98, 'hr' => 78, 'rr' => 16,
                        'suhu' => 36.4, 'spo2' => 98, 'kesadaran' => 'Alert', 'gcs' => 15,
                        'rhythm' => 'Sinus Ritme', 'breath_type' => 'Spontan', 'o2_support' => 'Tidak',
                        'intake' => 260, 'output' => 190,
                        'ews_total' => 0,
                        'ews_scores' => ['RR' => 0, 'HR' => 0, 'SBP' => 0, 'SpO2' => 0, 'Temp' => 0, 'Kesadaran' => 0],
                        'ews_risk' => 'low',
                        'notes' => 'Siapkan edukasi diet dan cairan saat pulang',
                        'recorded_by' => 'Ns. Tri Handayani',
                        'meds' => ['nacl', 'kcl'],
                    ],
                    [
                        'at' => '2026-09-06 22:00',
                        'sys' => 132, 'dia' => 80, 'map' => 97, 'hr' => 76, 'rr' => 16,
                        'suhu' => 36.4, 'spo2' => 98, 'kesadaran' => 'Alert', 'gcs' => 15,
                        'rhythm' => 'Sinus Ritme', 'breath_type' => 'Spontan', 'o2_support' => 'Tidak',
                        'intake' => 250, 'output' => 185,
                        'ews_total' => 0,
                        'ews_scores' => ['RR' => 0, 'HR' => 0, 'SBP' => 0, 'SpO2' => 0, 'Temp' => 0, 'Kesadaran' => 0],
                        'ews_risk' => 'low',
                        'notes' => 'Pasien pulang, laporan perawat diserahkan',
                        'recorded_by' => 'Ns. Tri Handayani',
                        'meds' => ['nacl', 'kcl'],
                    ],
                ],
            ],
            'enc-163901-hcu-20260912' => [
                'medications' => [
                    'mgso4' => ['MgSO4 20% 4 g / 8 jam', 'Loading 4 g lalu infus 1 g per jam', 'Cairan & Elektrolit', 100.0, 'IV infus'],
                    'nifedipin' => ['Nifedipin 10 mg', '10 mg 1x/4 jam bila tekanan tinggi', 'Lainnya', 0.0, 'Oral'],
                    'labetalol' => ['Labetalol 20 mg IV', '20 mg slow push bila tekanan lebih dari 160/110', 'Lainnya', 0.0, 'IV'],
                    'nacl' => ['NaCl 0,9%', 'Maintenance 40 mL/jam', 'Cairan & Elektrolit', 500.0, 'IV'],
                    'dekstrosa' => ['Dextrose 5%', 'Maintenance 40 mL/jam', 'Cairan & Elektrolit', 500.0, 'IV'],
                ],
                'devices' => [
                    ['device_code' => 'infus', 'start_date' => '2026-09-12', 'note' => 'Satu jalur infus perifer untuk MgSO4'],
                    ['device_code' => 'dc', 'start_date' => '2026-09-12', 'note' => 'Dower catheter Foley No. 16 untuk output urine'],
                    ['device_code' => 'arterial', 'start_date' => '2026-09-12', 'note' => 'Arteri line radial dextra 20 G untuk monitor tekanan darah'],
                    ['device_code' => 'lain', 'start_date' => '2026-09-12', 'note' => 'Pemantauan denyut jantung janin dengan doppler'],
                ],
                'observations' => [
                    [
                        'at' => '2026-09-12 07:00',
                        'sys' => 168, 'dia' => 110, 'map' => 129, 'hr' => 96, 'rr' => 20,
                        'suhu' => 36.9, 'spo2' => 98, 'kesadaran' => 'Alert', 'gcs' => 15,
                        'rhythm' => 'Sinus Takikardia', 'breath_type' => 'Reguler', 'o2_support' => 'Tidak',
                        'intake' => 300, 'output' => 250,
                        'ews_total' => 1,
                        'ews_scores' => ['RR' => 0, 'HR' => 1, 'SBP' => 0, 'SpO2' => 0, 'Temp' => 0, 'Kesadaran' => 0],
                        'ews_risk' => 'low',
                        'notes' => 'Pantau kadar magnesium serum dan refleks patella',
                        'recorded_by' => 'Bd. Sari Wulandari',
                        'meds' => ['mgso4', 'nifedipin', 'nacl', 'dekstrosa'],
                    ],
                    [
                        'at' => '2026-09-12 10:00',
                        'sys' => 164, 'dia' => 106, 'map' => 125, 'hr' => 94, 'rr' => 20,
                        'suhu' => 36.8, 'spo2' => 98, 'kesadaran' => 'Alert', 'gcs' => 15,
                        'rhythm' => 'Sinus Takikardia', 'breath_type' => 'Reguler', 'o2_support' => 'Tidak',
                        'intake' => 300, 'output' => 240,
                        'ews_total' => 1,
                        'ews_scores' => ['RR' => 0, 'HR' => 1, 'SBP' => 0, 'SpO2' => 0, 'Temp' => 0, 'Kesadaran' => 0],
                        'ews_risk' => 'low',
                        'notes' => 'Monitor denyut jantung janin dua kali per jam',
                        'recorded_by' => 'Bd. Sari Wulandari',
                        'meds' => ['mgso4', 'nifedipin', 'nacl', 'dekstrosa'],
                    ],
                    [
                        'at' => '2026-09-12 13:00',
                        'sys' => 160, 'dia' => 104, 'map' => 123, 'hr' => 92, 'rr' => 19,
                        'suhu' => 36.8, 'spo2' => 98, 'kesadaran' => 'Alert', 'gcs' => 15,
                        'rhythm' => 'Sinus Takikardia', 'breath_type' => 'Reguler', 'o2_support' => 'Tidak',
                        'intake' => 280, 'output' => 230,
                        'ews_total' => 1,
                        'ews_scores' => ['RR' => 0, 'HR' => 1, 'SBP' => 0, 'SpO2' => 0, 'Temp' => 0, 'Kesadaran' => 0],
                        'ews_risk' => 'low',
                        'notes' => 'Posisi tidur miring kiri untuk perfusi plasenta',
                        'recorded_by' => 'Bd. Sari Wulandari',
                        'meds' => ['mgso4', 'nifedipin', 'nacl', 'dekstrosa'],
                    ],
                    [
                        'at' => '2026-09-12 16:00',
                        'sys' => 156, 'dia' => 100, 'map' => 119, 'hr' => 90, 'rr' => 19,
                        'suhu' => 36.7, 'spo2' => 98, 'kesadaran' => 'Alert', 'gcs' => 15,
                        'rhythm' => 'Sinus Ritme', 'breath_type' => 'Reguler', 'o2_support' => 'Tidak',
                        'intake' => 280, 'output' => 225,
                        'ews_total' => 0,
                        'ews_scores' => ['RR' => 0, 'HR' => 0, 'SBP' => 0, 'SpO2' => 0, 'Temp' => 0, 'Kesadaran' => 0],
                        'ews_risk' => 'low',
                        'notes' => 'Antihipertensi dilanjutkan sesuai tekanan darah',
                        'recorded_by' => 'Bd. Sari Wulandari',
                        'meds' => ['mgso4', 'nifedipin', 'nacl', 'dekstrosa'],
                    ],
                    [
                        'at' => '2026-09-12 19:00',
                        'sys' => 152, 'dia' => 98, 'map' => 116, 'hr' => 88, 'rr' => 18,
                        'suhu' => 36.7, 'spo2' => 98, 'kesadaran' => 'Alert', 'gcs' => 15,
                        'rhythm' => 'Sinus Ritme', 'breath_type' => 'Reguler', 'o2_support' => 'Tidak',
                        'intake' => 270, 'output' => 220,
                        'ews_total' => 0,
                        'ews_scores' => ['RR' => 0, 'HR' => 0, 'SBP' => 0, 'SpO2' => 0, 'Temp' => 0, 'Kesadaran' => 0],
                        'ews_risk' => 'low',
                        'notes' => 'Monitor denyut jantung janin dua kali per jam',
                        'recorded_by' => 'Bd. Sari Wulandari',
                        'meds' => ['mgso4', 'nifedipin', 'labetalol', 'nacl', 'dekstrosa'],
                        'gaps' => ['clabsi_1' => 'Hand hygiene terlewat saat memanipulasi'],
                    ],
                    [
                        'at' => '2026-09-12 22:00',
                        'sys' => 150, 'dia' => 96, 'map' => 114, 'hr' => 86, 'rr' => 18,
                        'suhu' => 36.6, 'spo2' => 98, 'kesadaran' => 'Alert', 'gcs' => 15,
                        'rhythm' => 'Sinus Ritme', 'breath_type' => 'Reguler', 'o2_support' => 'Tidak',
                        'intake' => 260, 'output' => 215,
                        'ews_total' => 0,
                        'ews_scores' => ['RR' => 0, 'HR' => 0, 'SBP' => 0, 'SpO2' => 0, 'Temp' => 0, 'Kesadaran' => 0],
                        'ews_risk' => 'low',
                        'notes' => 'Pantau kadar magnesium serum dan refleks patella',
                        'recorded_by' => 'Bd. Sari Wulandari',
                        'meds' => ['mgso4', 'nifedipin', 'labetalol', 'nacl', 'dekstrosa'],
                    ],
                    [
                        'at' => '2026-09-13 01:00',
                        'sys' => 146, 'dia' => 94, 'map' => 111, 'hr' => 84, 'rr' => 17,
                        'suhu' => 36.6, 'spo2' => 99, 'kesadaran' => 'Alert', 'gcs' => 15,
                        'rhythm' => 'Sinus Ritme', 'breath_type' => 'Spontan', 'o2_support' => 'Tidak',
                        'intake' => 250, 'output' => 210,
                        'ews_total' => 0,
                        'ews_scores' => ['RR' => 0, 'HR' => 0, 'SBP' => 0, 'SpO2' => 0, 'Temp' => 0, 'Kesadaran' => 0],
                        'ews_risk' => 'low',
                        'notes' => 'Posisi tidur miring kiri untuk perfusi plasenta',
                        'recorded_by' => 'Bd. Sari Wulandari',
                        'meds' => ['mgso4', 'nifedipin', 'labetalol', 'nacl'],
                    ],
                    [
                        'at' => '2026-09-13 04:00',
                        'sys' => 144, 'dia' => 92, 'map' => 109, 'hr' => 82, 'rr' => 17,
                        'suhu' => 36.5, 'spo2' => 99, 'kesadaran' => 'Alert', 'gcs' => 15,
                        'rhythm' => 'Sinus Ritme', 'breath_type' => 'Spontan', 'o2_support' => 'Tidak',
                        'intake' => 250, 'output' => 205,
                        'ews_total' => 0,
                        'ews_scores' => ['RR' => 0, 'HR' => 0, 'SBP' => 0, 'SpO2' => 0, 'Temp' => 0, 'Kesadaran' => 0],
                        'ews_risk' => 'low',
                        'notes' => 'Rencana: persalinan bila ibu atau janin memburuk',
                        'recorded_by' => 'Bd. Sari Wulandari',
                        'meds' => ['mgso4', 'nifedipin', 'nacl'],
                    ],
                    [
                        'at' => '2026-09-13 07:00',
                        'sys' => 142, 'dia' => 90, 'map' => 107, 'hr' => 80, 'rr' => 16,
                        'suhu' => 36.5, 'spo2' => 99, 'kesadaran' => 'Alert', 'gcs' => 15,
                        'rhythm' => 'Sinus Ritme', 'breath_type' => 'Spontan', 'o2_support' => 'Tidak',
                        'intake' => 240, 'output' => 200,
                        'ews_total' => 0,
                        'ews_scores' => ['RR' => 0, 'HR' => 0, 'SBP' => 0, 'SpO2' => 0, 'Temp' => 0, 'Kesadaran' => 0],
                        'ews_risk' => 'low',
                        'notes' => 'Monitor denyut jantung janin dua kali per jam',
                        'recorded_by' => 'Bd. Sari Wulandari',
                        'meds' => ['nifedipin', 'nacl'],
                    ],
                    [
                        'at' => '2026-09-13 10:00',
                        'sys' => 140, 'dia' => 88, 'map' => 105, 'hr' => 78, 'rr' => 16,
                        'suhu' => 36.4, 'spo2' => 99, 'kesadaran' => 'Alert', 'gcs' => 15,
                        'rhythm' => 'Sinus Ritme', 'breath_type' => 'Spontan', 'o2_support' => 'Tidak',
                        'intake' => 240, 'output' => 195,
                        'ews_total' => 0,
                        'ews_scores' => ['RR' => 0, 'HR' => 0, 'SBP' => 0, 'SpO2' => 0, 'Temp' => 0, 'Kesadaran' => 0],
                        'ews_risk' => 'low',
                        'notes' => 'Pantau kadar magnesium serum dan refleks patella',
                        'recorded_by' => 'Bd. Sari Wulandari',
                        'meds' => ['nifedipin', 'nacl'],
                    ],
                ],
            ],
        ];
    }
}

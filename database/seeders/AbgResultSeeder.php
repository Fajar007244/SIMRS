<?php

namespace Database\Seeders;

use App\Models\AbgResult;
use App\Models\Encounter;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Analisa Gas Darah (AGD).
 *
 * Sumber: array `abg` pada buildSeedSupport() di phase1/app-context.js.
 * Tiga baris prototype disalin apa adanya: enc-159853 (2026-09-06 10:15),
 * enc-162210 (2026-09-10 16:00), dan enc-160045 (2026-09-11 20:30).
 *
 * prototype tidak menyimpan AGD pada tiga episode lainnya. Baris tambahan di
 * bawah dibuat mengikuti practise ICU: AGD ulang 4x6 jam pada pasien
 * ventilator atau sepsis, pada pasien dengan suspicion asidosis metabolik,
 * dan pada pasien yang dijemput hemodialisis. Semua atribusi memakai
 * 'Dra. Sari Wulandari' seperti konstanta LAB pada prototype.
 */
class AbgResultSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->records() as $encounterCode => $rows) {
            $encounter = Encounter::query()->where('encounter_id', $encounterCode)->first();

            if (! $encounter instanceof Encounter) {
                continue;
            }

            foreach ($rows as $row) {
                AbgResult::query()->updateOrCreate(
                    [
                        'encounter_id' => $encounter->getKey(),
                        'measured_at' => Carbon::parse($row['at']),
                    ],
                    [
                        'ph' => $row['ph'],
                        'pco2' => $row['pco2'],
                        'po2' => $row['po2'],
                        'hco3' => $row['hco3'],
                        'be' => $row['be'],
                        'sao2' => $row['sao2'],
                        'fio2' => $row['fio2'],
                        'method' => $row['method'],
                        'created_by' => $row['by'],
                    ]
                );
            }
        }
    }

    /**
     * @return array<string, list<array{ph: float, pco2: float, po2: float, hco3: float, be: float, sao2: float, fio2: float, method: string, at: string, by: string}>>
     */
    protected function records(): array
    {
        $lab = 'Dra. Sari Wulandari';

        return [

            'enc-159853-icu-20260906' => [
                ['ph' => 7.38, 'pco2' => 39, 'po2' => 92, 'hco3' => 22.5, 'be' => -1.8, 'sao2' => 97, 'fio2' => 40, 'method' => 'Arteri', 'at' => '2026-09-06 10:15', 'by' => $lab],
                ['ph' => 7.35, 'pco2' => 41, 'po2' => 88, 'hco3' => 21.0, 'be' => -3.2, 'sao2' => 95, 'fio2' => 45, 'method' => 'Arteri', 'at' => '2026-09-06 18:00', 'by' => $lab],
                ['ph' => 7.40, 'pco2' => 37, 'po2' => 95, 'hco3' => 24.0, 'be' => 0.1, 'sao2' => 97, 'fio2' => 40, 'method' => 'Arteri', 'at' => '2026-09-07 06:00', 'by' => $lab],
            ],

            'enc-162210-hcu-20260910' => [
                ['ph' => 7.40, 'pco2' => 38, 'po2' => 88, 'hco3' => 24.0, 'be' => 0.2, 'sao2' => 95, 'fio2' => 60, 'method' => 'Arteri', 'at' => '2026-09-10 16:00', 'by' => $lab],
                ['ph' => 7.41, 'pco2' => 36, 'po2' => 94, 'hco3' => 24.5, 'be' => 0.5, 'sao2' => 97, 'fio2' => 40, 'method' => 'Arteri', 'at' => '2026-09-11 08:00', 'by' => $lab],
                ['ph' => 7.42, 'pco2' => 35, 'po2' => 98, 'hco3' => 25.0, 'be' => 0.8, 'sao2' => 98, 'fio2' => 21, 'method' => 'Vena', 'at' => '2026-09-12 06:00', 'by' => $lab],
            ],

            'enc-158774-icu-20260908' => [
                ['ph' => 7.40, 'pco2' => 36, 'po2' => 89, 'hco3' => 23.0, 'be' => -0.5, 'sao2' => 96, 'fio2' => 40, 'method' => 'Arteri', 'at' => '2026-09-08 06:00', 'by' => $lab],
                ['ph' => 7.41, 'pco2' => 35, 'po2' => 95, 'hco3' => 24.0, 'be' => 0.3, 'sao2' => 97, 'fio2' => 21, 'method' => 'Arteri', 'at' => '2026-09-09 06:00', 'by' => $lab],
            ],

            'enc-160045-hcu-20260911' => [
                ['ph' => 7.28, 'pco2' => 31, 'po2' => 96, 'hco3' => 15.2, 'be' => -9.5, 'sao2' => 98, 'fio2' => 21, 'method' => 'Vena', 'at' => '2026-09-11 20:30', 'by' => $lab],
                ['ph' => 7.32, 'pco2' => 30, 'po2' => 97, 'hco3' => 18.0, 'be' => -6.8, 'sao2' => 98, 'fio2' => 21, 'method' => 'Vena', 'at' => '2026-09-12 02:00', 'by' => $lab],
                ['ph' => 7.36, 'pco2' => 34, 'po2' => 95, 'hco3' => 21.5, 'be' => -3.0, 'sao2' => 97, 'fio2' => 21, 'method' => 'Vena', 'at' => '2026-09-12 10:00', 'by' => $lab],
            ],

            'enc-157330-icu-20260905' => [
                ['ph' => 7.33, 'pco2' => 28, 'po2' => 92, 'hco3' => 16.5, 'be' => -8.2, 'sao2' => 96, 'fio2' => 21, 'method' => 'Vena', 'at' => '2026-09-05 09:00', 'by' => $lab],
                ['ph' => 7.39, 'pco2' => 33, 'po2' => 94, 'hco3' => 21.0, 'be' => -3.5, 'sao2' => 97, 'fio2' => 21, 'method' => 'Vena', 'at' => '2026-09-06 07:00', 'by' => $lab],
            ],

            'enc-163901-hcu-20260912' => [
                ['ph' => 7.39, 'pco2' => 32, 'po2' => 95, 'hco3' => 21.0, 'be' => -2.0, 'sao2' => 97, 'fio2' => 21, 'method' => 'Vena', 'at' => '2026-09-12 09:00', 'by' => $lab],
                ['ph' => 7.40, 'pco2' => 33, 'po2' => 97, 'hco3' => 22.5, 'be' => -0.8, 'sao2' => 98, 'fio2' => 21, 'method' => 'Vena', 'at' => '2026-09-13 09:00', 'by' => $lab],
            ],

        ];
    }
}

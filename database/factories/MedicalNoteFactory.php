<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\Encounter;
use App\Models\MedicalNote;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<MedicalNote>
 *
 * Empat blok CPPT memakai kosakata Indonesia yang lazim pada chart progress:
 * baris S, O, A, dan P diisi terpisah pada kolomnya masing-masing. Nilai
 * defaultnya sengaja generik supaya test tidak terkunci pada satu kasus klinis.
 */
class MedicalNoteFactory extends Factory
{
    /**
     * @var class-string<MedicalNote>
     */
    protected $model = MedicalNote::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $notedAt = fake()->dateTimeBetween('-5 days', 'now');

        $subjective = [
            'Sesak napas meningkat dan sulit tiduran',
            'Nyeri dada menembus punggung, tidak berubah posisi',
            'Demam dan menggigil sejak semalam',
            'Mual dan muntah satu kali',
        ];

        $assessment = [
            'Infeksi paru, sedang dalam penyihan',
            'Gagal napas, distabilkan dengan oksigen tambahan',
            'Syok, responsif terhadap resusitasi cairan dan vasopressor',
        ];

        return [
            'encounter_id' => Encounter::factory(),
            'author_name' => 'dr. Rangga Saputra, Sp.An-TI',
            'author_role' => UserRole::DOKTER,
            'author_specialty' => 'Konsultan Intensif',
            'note_type' => 'CPPT',
            'subjective' => fake()->randomElement($subjective),
            'objective' => 'TD '.fake()->numberBetween(90, 160).'/'.fake()->numberBetween(50, 95)
                .' mmHg, HR '.fake()->numberBetween(60, 130).' x/mnt, RR '.fake()->numberBetween(14, 30)
                .' x/mnt, Suhu '.fake()->numberBetween(36, 40).' C, SpO2 '.fake()->numberBetween(90, 100).'%',
            'assessment' => fake()->randomElement($assessment),
            'plan' => 'Lanjutkan terapi yang sedang berjalan, monitor tanda vital per jam, '
                .'evaluasi ulang hasil penunjang dalam 24 jam',
            'noted_at' => Carbon::instance($notedAt),
            'order_number' => 1,
        ];
    }
}

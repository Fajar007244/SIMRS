<?php

namespace Database\Factories;

use App\Enums\EncounterStatus;
use App\Models\Encounter;
use App\Models\Patient;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Encounter>
 */
class EncounterFactory extends Factory
{
    /**
     * @var class-string<Encounter>
     */
    protected $model = Encounter::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $admittedAt = fake()->dateTimeBetween('-10 days', '-1 hour');

        return [
            'encounter_id' => 'enc-'.fake()->unique()->numerify('######-icu-######'),
            'patient_id' => Patient::factory(),
            'unit' => fake()->randomElement(['ICU Bed 01', 'HCU Melati', 'HCU Anggrek', 'Ruang Nusa Indah']),
            'bed' => 'Bed '.str_pad((string) fake()->numberBetween(1, 12), 2, '0', STR_PAD_LEFT),
            'admitted_at' => Carbon::instance($admittedAt),
            'discharged_at' => null,
            'attending_physician' => fake()->randomElement([
                'dr. Rangga Saputra, Sp.An-TI, Subsp.T.I.(K)',
                'dr. Maya Lestari, Sp.PD',
                'dr. Hendra Wijaya, Sp.PD',
            ]),
            'status' => EncounterStatus::AKTIF,
            'diagnosis_summary' => null,
            'allergy_alert' => null,
            'latest_observation_at' => null,
            'notes' => null,
        ];
    }

    public function forPatient(Patient $patient): static
    {
        return $this->state(fn (array $attributes): array => [
            'patient_id' => $patient->getKey(),
        ]);
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => EncounterStatus::AKTIF,
            'discharged_at' => null,
        ]);
    }

    public function discharged(): static
    {
        return $this->state(function (array $attributes): array {
            $admittedAt = $attributes['admitted_at'] instanceof Carbon
                ? $attributes['admitted_at']
                : Carbon::now();

            return [
                'status' => EncounterStatus::SELESAI,
                'discharged_at' => $admittedAt->copy()->addDays(fake()->numberBetween(2, 9))->setTime(10, 0),
            ];
        });
    }

    public function unit(string $unit, string $bed): static
    {
        return $this->state(fn (array $attributes): array => [
            'unit' => $unit,
            'bed' => $bed,
        ]);
    }
}

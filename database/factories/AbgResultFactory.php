<?php

namespace Database\Factories;

use App\Models\AbgResult;
use App\Models\Encounter;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AbgResult>
 *
 * Rentang nilai mengikuti gas darah arteri normal dewasa, dengan toleransi
 * yang sering dipakai pada pasien sepsis dan gagal ginjal, sehingga data uji
 * tidak selalu berada di tengah rentang fisiologis normal.
 */
class AbgResultFactory extends Factory
{
    /**
     * @var class-string<AbgResult>
     */
    protected $model = AbgResult::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'encounter_id' => Encounter::factory(),
            'ph' => fake()->randomFloat(2, 7.20, 7.55),
            'pco2' => fake()->randomFloat(1, 26.0, 55.0),
            'po2' => fake()->randomFloat(1, 60.0, 110.0),
            'hco3' => fake()->randomFloat(1, 14.0, 28.0),
            'be' => fake()->randomFloat(1, -12.0, 4.0),
            'sao2' => fake()->randomFloat(1, 88.0, 99.0),
            'fio2' => fake()->randomElement([21.0, 40.0, 60.0]),
            'method' => fake()->randomElement(['Arteri', 'Vena']),
            'measured_at' => fake()->dateTimeBetween('-4 days', 'now'),
            'created_by' => 'Dra. Sari Wulandari',
        ];
    }

    public function forEncounter(Encounter $encounter): static
    {
        return $this->state(fn (array $attributes): array => [
            'encounter_id' => $encounter->getKey(),
        ]);
    }

    /**
     * Asidosis metabolik denganneau good.
     */
    public function metabolicAcidosis(): static
    {
        return $this->state(fn (array $attributes): array => [
            'ph' => fake()->randomFloat(2, 7.18, 7.32),
            'pco2' => fake()->randomFloat(1, 25.0, 35.0),
            'hco3' => fake()->randomFloat(1, 12.0, 18.0),
            'be' => fake()->randomFloat(1, -14.0, -6.0),
        ]);
    }

    /**
     * Gangguan oksigenasi: hipoksemia dengan PaO2 rendah.
     */
    public function hypoxemia(): static
    {
        return $this->state(fn (array $attributes): array => [
            'ph' => fake()->randomFloat(2, 7.30, 7.45),
            'pco2' => fake()->randomFloat(1, 30.0, 45.0),
            'po2' => fake()->randomFloat(1, 55.0, 80.0),
            'sao2' => fake()->randomFloat(1, 86.0, 93.0),
        ]);
    }

    public function normal(): static
    {
        return $this->state(fn (array $attributes): array => [
            'ph' => 7.40,
            'pco2' => 40.0,
            'po2' => 95.0,
            'hco3' => 24.0,
            'be' => 0.0,
            'sao2' => 98.0,
        ]);
    }
}

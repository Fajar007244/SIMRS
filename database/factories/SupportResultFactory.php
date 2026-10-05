<?php

namespace Database\Factories;

use App\Enums\SupportGroup;
use App\Models\Encounter;
use App\Models\SupportResult;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupportResult>
 *
 * Panel penunjang lokal memakai nilai rentang referensi ala laboratorium
 * Indonesia, dan flag high / low / normal supaya bisa dipakai langsung oleh
 * scopeAbnormal(). Hasil kultur sengaja punya numeric_value null karena
 * kultur tidak bisa diplot pada grafik tren.
 */
class SupportResultFactory extends Factory
{
    /**
     * @var class-string<SupportResult>
     */
    protected $model = SupportResult::class;

    /**
     * Rentang referensi per item, dipisah dari nilai agar mudah dibaca.
     *
     * @var array<string, array{unit: string, reference: string}>
     */
    protected static array $catalogue = [
        'leukosit' => ['unit' => 'x10^3/uL', 'reference' => '4,0 - 10,5 x10^3/uL'],
        'hgb' => ['unit' => 'g/dL', 'reference' => '12,0 - 16,0 g/dL'],
        'pjk' => ['unit' => 'x10^3/uL', 'reference' => '150 - 400 x10^3/uL'],
        'lpf' => ['unit' => '%', 'reference' => '1 - 8 %'],
        'crp' => ['unit' => 'mg/L', 'reference' => '< 5,0 mg/L'],
        'prokalcitonin' => ['unit' => 'ng/mL', 'reference' => '< 0,05 ng/mL'],
        'kreatinin' => ['unit' => 'mg/dL', 'reference' => '0,7 - 1,3 mg/dL'],
        'bun' => ['unit' => 'mg/dL', 'reference' => '10 - 20 mg/dL'],
        'natrem' => ['unit' => 'mEq/L', 'reference' => '135 - 145 mEq/L'],
        'kalium' => ['unit' => 'mEq/L', 'reference' => '3,5 - 5,1 mEq/L'],
        'glukosa' => ['unit' => 'mg/dL', 'reference' => '70 - 110 mg/dL'],
        'albumin' => ['unit' => 'g/dL', 'reference' => '3,5 - 5,0 g/dL'],
    ];

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $resultKey = fake()->randomElement(array_keys(self::$catalogue));
        $entry = self::$catalogue[$resultKey];

        $values = [
            'leukosit' => fake()->randomFloat(1, 4.0, 24.0),
            'hgb' => fake()->randomFloat(1, 6.5, 16.5),
            'pjk' => fake()->randomFloat(1, 80.0, 450.0),
            'lpf' => fake()->randomFloat(1, 1.0, 25.0),
            'crp' => fake()->randomFloat(1, 1.0, 160.0),
            'prokalcitonin' => fake()->randomFloat(2, 0.01, 8.0),
            'kreatinin' => fake()->randomFloat(2, 0.5, 6.0),
            'bun' => fake()->randomFloat(1, 8.0, 70.0),
            'natrem' => fake()->numberBetween(125, 152),
            'kalium' => fake()->randomFloat(1, 2.8, 6.4),
            'glukosa' => fake()->numberBetween(60, 400),
            'albumin' => fake()->randomFloat(1, 1.8, 5.2),
        ];

        $numeric = $values[$resultKey];

        return [
            'encounter_id' => Encounter::factory(),
            'group' => fake()->randomElement(SupportGroup::resultGroups()),
            'result_key' => $resultKey,
            'value' => (string) $numeric,
            'numeric_value' => $numeric,
            'unit' => $entry['unit'],
            'flag' => 'normal',
            'reference' => $entry['reference'],
            'resulted_at' => fake()->dateTimeBetween('-4 days', 'now'),
            'created_by' => 'Dra. Sari Wulandari',
        ];
    }

    public function forEncounter(Encounter $encounter): static
    {
        return $this->state(fn (array $attributes): array => [
            'encounter_id' => $encounter->getKey(),
        ]);
    }

    public function group(SupportGroup $group, string $resultKey): static
    {
        return $this->state(function (array $attributes) use ($group, $resultKey): array {
            $entry = self::$catalogue[$resultKey] ?? ['unit' => null, 'reference' => null];

            return [
                'group' => $group,
                'result_key' => $resultKey,
                'unit' => $entry['unit'],
                'reference' => $entry['reference'],
            ];
        });
    }

    /**
     * Nilai kultur: tidak bisa diplot, jadi numeric_value sengaja null.
     */
    public function culture(string $finding, string $status = 'positif'): static
    {
        return $this->state(fn (array $attributes): array => [
            'group' => SupportGroup::MICRO,
            'result_key' => 'kultur_darah',
            'value' => $finding,
            'numeric_value' => null,
            'unit' => null,
            'flag' => $status,
            'reference' => null,
        ]);
    }

    public function flagged(string $flag): static
    {
        return $this->state(fn (array $attributes): array => ['flag' => $flag]);
    }
}

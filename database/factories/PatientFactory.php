<?php

namespace Database\Factories;

use App\Enums\PaymentType;
use App\Enums\Sex;
use App\Models\Patient;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @extends Factory<Patient>
 *
 * Default-nya memakai nama, golongan darah, dan nilai payment khas Indonesia
 * supaya data uji tidak terlihat seperti hasil generator generik. Alergi
 * negatif memakai string "Tidak ada" (bukan null) karena phase1 dan
 * Patient::hasAllergies() membedaakannya dari "belum ditanyakan".
 */
class PatientFactory extends Factory
{
    /**
     * @var class-string<Patient>
     */
    protected $model = Patient::class;

    /**
     * Nama Indonesia gaya master data RS.
     *
     * @var list<string>
     */
    protected static array $indonesianNames = [
        'Siti Aminah', 'Budi Santoso', 'Dewi Lestari', 'Agus Salim',
        'Rina Kusuma', 'Hendra Wijaya', 'Maya Puspita', 'Joko Prasetya',
        'Nurul Hidayah', 'Eko Waluyo', 'Sri Wahyuni', 'Taufik Hidayat',
    ];

    /**
     * Golongan darah ABO yang lazim dipakai di Indonesia.
     *
     * @var list<string>
     */
    protected static array $bloodTypes = ['A', 'B', 'AB', 'O'];

    /**
     * Alergi yang lazim tercatat pada formulir admisi lokal.
     *
     * @var list<string>
     */
    protected static array $allergyList = [
        'Tidak ada',
        'Penisilin',
        'Sulfa',
        'Latex',
        'Ciprofloxacin',
        'Seftriakson',
    ];

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'patient_id' => 'patient-'.fake()->unique()->numerify('######'),
            'mrn' => (string) fake()->unique()->numerify('######'),
            'name' => Str::upper(fake()->randomElement(self::$indonesianNames)),
            'sex' => fake()->randomElement(Sex::cases()),
            'birth_date' => Carbon::create(
                fake()->numberBetween(1950, 1998),
                fake()->numberBetween(1, 12),
                fake()->numberBetween(1, 28)
            ),
            'address' => 'Jl. '.fake()->randomElement(['Melati', 'Kenanga', 'Cendana', 'Mawar', 'Flamboyan'])
                .' No. '.fake()->numberBetween(1, 120)
                .', '.fake()->randomElement(['Sunggumanai', 'Pancoran', 'Malaka', 'Bonto', 'Ujung Pandang'])
                .', Makassar',
            'phone' => '08'.fake()->numerify('##########'),
            'blood_type' => fake()->randomElement(self::$bloodTypes),
            'allergies' => fake()->randomElement(self::$allergyList),
            'payment' => fake()->randomElement(PaymentType::cases()),
            'is_active' => true,
        ];
    }

    public function male(): static
    {
        return $this->state(fn (array $attributes): array => ['sex' => Sex::LAKI_LAKI]);
    }

    public function female(): static
    {
        return $this->state(fn (array $attributes): array => ['sex' => Sex::PEREMPUAN]);
    }

    /**
     * Tanpa alergi. Nilai "Tidak ada" persis seperti phase1, bukan null.
     */
    public function noAllergies(): static
    {
        return $this->state(fn (array $attributes): array => ['allergies' => 'Tidak ada']);
    }

    public function allergicTo(string $substance): static
    {
        return $this->state(fn (array $attributes): array => ['allergies' => $substance]);
    }

    public function payment(PaymentType $payment): static
    {
        return $this->state(fn (array $attributes): array => ['payment' => $payment]);
    }

    /**
     * Jadikan umur cetak tepat N tahun (memakai accessor Patient::age).
     */
    public function aged(int $years): static
    {
        return $this->state(fn (array $attributes): array => [
            'birth_date' => Carbon::now()->subYears($years),
        ]);
    }
}

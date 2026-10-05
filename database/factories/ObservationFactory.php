<?php

namespace Database\Factories;

use App\Enums\ConsciousnessLevel;
use App\Enums\EwsRiskLevel;
use App\Models\Encounter;
use App\Models\Observation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Observation>
 *
 * Slot observasi dibatasi per jam (observation_date + observation_time) karena
 * ada unique constraint observations_slot_unique. Factory karena itu selalu
 * mengambil jam dari daftar tetap dan memakai configure() untuk menurunkan
 * idempotency_key setelah encounter_id benar-benar ter-resolve, sehingga slot
 * yang sama tidak bentrok dengan baris lain pada episode yang sama.
 *
 * Skor EWS dihitung di sini langsung dari config('ews.parameters') supaya
 * factory tidak bergantung pada service penilaian yang sedang dikembangkan.
 * Band parameternya memang konstanta config, bukan logika aplikasi.
 */
class ObservationFactory extends Factory
{
    /**
     * @var class-string<Observation>
     */
    protected $model = Observation::class;

    /**
     * Jam-jam flowsheet yang dipakai ICU/HCU lokal.
     *
     * @var list<string>
     */
    protected static array $hours = ['00:00', '02:00', '04:00', '06:00', '08:00', '10:00', '12:00', '14:00', '16:00', '18:00', '20:00', '22:00'];

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $hour = fake()->randomElement(self::$hours);
        $date = fake()->dateTimeBetween('-5 days', 'now');

        $vitals = [
            'sys' => fake()->numberBetween(95, 145),
            'hr' => fake()->numberBetween(60, 105),
            'rr' => fake()->numberBetween(14, 22),
            'suhu' => fake()->randomElement([36.4, 36.8, 37.0, 37.2, 37.6, 38.0]),
            'spo2' => fake()->numberBetween(93, 99),
            'kesadaran' => fake()->randomElement([ConsciousnessLevel::ALERT, ConsciousnessLevel::ALERT, ConsciousnessLevel::DPO]),
        ];

        $score = self::score($vitals);
        $total = array_sum($score);

        return [
            'encounter_id' => Encounter::factory(),
            'observation_date' => Carbon::instance($date)->startOfDay(),
            'observation_time' => $hour,
            'sys' => $vitals['sys'],
            'dia' => fake()->numberBetween(55, 90),
            'map' => (int) round($vitals['sys'] / 3),
            'hr' => $vitals['hr'],
            'rhythm' => 'Sinus Ritme',
            'rr' => $vitals['rr'],
            'breath_type' => 'Spontan',
            'suhu' => $vitals['suhu'],
            'spo2' => $vitals['spo2'],
            'o2_support' => 'Tidak',
            'kesadaran' => $vitals['kesadaran'],
            'gcs' => 15,
            'intake' => fake()->numberBetween(150, 500),
            'output' => fake()->numberBetween(100, 320),
            'ews_total' => $total,
            'ews_scores' => $score,
            'ews_risk' => self::risk($total),
            'notes' => null,
            'ventilator_settings' => null,
            'status' => 'final',
            'recorded_by' => 'Ns. Tri Handayani',
            'recorded_at' => Carbon::parse($date->format('Y-m-d').' '.$hour),
            'idempotency_key' => null,
        ];
    }

    /**
     * Turunkan idempotency_key dari slot observasi, sama seperti phase1
     * app-context.js memakai "<encounterId>:<tanggal>:<waktu>".
     */
    public function configure(): static
    {
        return $this->afterMaking(function (Observation $observation): void {
            if ($observation->idempotency_key !== null) {
                return;
            }

            $encounterCode = Encounter::query()
                ->whereKey($observation->encounter_id)
                ->value('encounter_id');

            if ($encounterCode === null) {
                return;
            }

            $observation->idempotency_key = $encounterCode.':'
                .Carbon::instance($observation->observation_date)->format('Y-m-d').':'
                .Carbon::instance($observation->observation_time)->format('H:i');
        });
    }

    /**
     * Skor per parameter, dibaca dari config('ews.parameters').
     *
     * @param  array<string, int|float|string>  $vitals
     * @return array<string, int|null>
     */
    protected static function score(array $vitals): array
    {
        $scores = [];

        foreach ((array) config('ews.parameters', []) as $name => $parameter) {
            $value = $vitals[$parameter['field'] ?? $name] ?? null;

            if (($parameter['type'] ?? null) === 'map') {
                $scores[$name] = (int) (($parameter['values'][$value] ?? 0));

                continue;
            }

            $match = null;

            foreach ((array) ($parameter['bands'] ?? []) as $band) {
                if ($value >= $band['min'] && $value <= $band['max']) {
                    $match = (int) $band['score'];

                    break;
                }
            }

            $scores[$name] = $match;
        }

        return $scores;
    }

    protected static function risk(int $total): EwsRiskLevel
    {
        foreach ((array) config('ews.escalation', []) as $band) {
            $max = $band['max'];

            if ($total >= $band['min'] && ($max === null || $total <= $max)) {
                return EwsRiskLevel::from($band['level']);
            }
        }

        return EwsRiskLevel::LOW;
    }

    public function forEncounter(Encounter $encounter): static
    {
        return $this->state(fn (array $attributes): array => [
            'encounter_id' => $encounter->getKey(),
        ]);
    }

    public function at(string $date, string $time): static
    {
        return $this->state(fn (array $attributes): array => [
            'observation_date' => Carbon::parse($date)->startOfDay(),
            'observation_time' => $time,
            'recorded_at' => Carbon::parse($date.' '.$time),
        ]);
    }

    /**
     * Pasien tidak stabil: EWS naik ke medium / high / emergency.
     */
    public function highRisk(): static
    {
        return $this->state(function (array $attributes): array {
            $vitals = [
                'sys' => fake()->numberBetween(80, 95),
                'hr' => fake()->numberBetween(112, 128),
                'rr' => fake()->numberBetween(25, 32),
                'suhu' => fake()->randomElement([38.7, 39.1, 39.4]),
                'spo2' => fake()->numberBetween(88, 92),
                'kesadaran' => fake()->randomElement([ConsciousnessLevel::VOICE, ConsciousnessLevel::UNRESPONSIVE]),
            ];

            $score = self::score($vitals);
            $total = array_sum($score);

            return [
                'sys' => $vitals['sys'],
                'hr' => $vitals['hr'],
                'rr' => $vitals['rr'],
                'suhu' => $vitals['suhu'],
                'spo2' => $vitals['spo2'],
                'kesadaran' => $vitals['kesadaran'],
                'rhythm' => 'Sinus Takikardia',
                'gcs' => 12,
                'ews_total' => $total,
                'ews_scores' => $score,
                'ews_risk' => self::risk($total),
            ];
        });
    }

    public function stable(): static
    {
        return $this->state(fn (array $attributes): array => [
            'sys' => fake()->numberBetween(110, 135),
            'hr' => fake()->numberBetween(65, 88),
            'rr' => fake()->numberBetween(14, 19),
            'suhu' => fake()->randomElement([36.3, 36.5, 36.7, 36.9]),
            'spo2' => fake()->numberBetween(96, 100),
            'kesadaran' => ConsciousnessLevel::ALERT,
            'gcs' => 15,
        ]);
    }

    /**
     * Pasien yang sedang berbantuan ventilator mekanis.
     *
     * @param  array<string, mixed>  $settings
     */
    public function ventilated(array $settings = []): static
    {
        return $this->state(fn (array $attributes): array => [
            'breath_type' => 'Dengan bantuan ventilator',
            'o2_support' => 'Ventilator',
            'kesadaran' => ConsciousnessLevel::DPO,
            'gcs' => 8,
            'ventilator_settings' => $settings === [] ? [
                'mode' => 'PSIMV',
                'rr' => 16,
                'pc' => 12,
                'ps' => 8,
                'peep' => 6,
                'fio2' => 40,
            ] : $settings,
        ]);
    }
}

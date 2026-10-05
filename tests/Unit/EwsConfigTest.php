<?php

namespace Tests\Unit;

use App\Enums\ConsciousnessLevel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Kontrak tabel EWS pada config/ews.php.
 *
 * Menggantikan placeholder `assertTrue(true)`. Skala di sini adalah British
 * Early Warning Scale 4 tingkat, BUKAN MEWS 3 tingkat, jadi batas tabelnya
 * perlu dijaga supaya tidak "diperbaiki" menjadi MEWS saat ditinjau.
 *
 * Tes ini murni membaca file config: tidak butuh container, jadi cepat dan
 * tidak menyentuh database. Perilaku EwsScoringService terhadap config ini
 * diuji lewat Feature test (ObservationWriteTest).
 */
class EwsConfigTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function config(): array
    {
        return require __DIR__.'/../../config/ews.php';
    }

    /**
     * @return array<string, array{int, string}>
     */
    public static function escalationTiers(): array
    {
        return [
            'total 0' => [0, 'low'],
            'total 2' => [2, 'low'],
            'total 3' => [3, 'medium'],
            'total 4' => [4, 'medium'],
            'total 5' => [5, 'high'],
            'total 6' => [6, 'high'],
            'total 7' => [7, 'emergency'],
            'total 18' => [18, 'emergency'],
        ];
    }

    #[DataProvider('escalationTiers')]
    public function test_every_total_falls_into_exactly_one_escalation_tier(int $total, string $expected): void
    {
        $config = $this->config();

        $matches = [];

        foreach ($config['escalation'] as $tier) {
            $max = $tier['max'] === null ? PHP_INT_MAX : $tier['max'];

            if ($total >= $tier['min'] && $total <= $max) {
                $matches[] = $tier['level'];
            }
        }

        $this->assertSame([$expected], $matches, "total $total harus jatuh tepat satu tingkat");
    }

    public function test_escalation_tiers_cover_every_possible_total_without_gaps(): void
    {
        $config = $this->config();

        $covered = [];

        foreach ($config['escalation'] as $tier) {
            $max = $tier['max'] === null ? $config['max_total'] : $tier['max'];

            for ($total = $tier['min']; $total <= $max; $total++) {
                $this->assertArrayNotHasKey($total, $covered, "total $total terduitasi dua kali");
                $covered[$total] = $tier['level'];
            }
        }

        $this->assertCount($config['max_total'] + 1, $covered, 'tidak boleh ada total tanpa tingkat');
    }

    public function test_scale_metadata_matches_the_table(): void
    {
        $config = $this->config();

        $this->assertCount(6, $config['parameters'], 'EWS di sini punya 6 parameter, bukan 5');
        $this->assertSame(4, $config['tiers']);
        $this->assertSame(18, $config['max_total']);
        $this->assertSame('British Early Warning Scale', $config['scale']);
    }

    public function test_every_parameter_spans_the_full_score_range(): void
    {
        $config = $this->config();

        foreach ($config['parameters'] as $name => $parameter) {
            if (($parameter['type'] ?? null) === 'map') {
                $this->assertContains(3, $parameter['values'], "parameter $name harus bisa berskor 3");

                continue;
            }

            $scores = array_column($parameter['bands'], 'score');
            $this->assertContains(3, $scores, "parameter $name harus bisa berskor 3");
            $this->assertContains(0, $scores, "parameter $name harus bisa berskor 0");
        }
    }

    public function test_consciousness_is_scored_from_a_discrete_map(): void
    {
        $values = $this->config()['parameters']['Kesadaran']['values'];

        $this->assertSame(0, $values['Alert']);
        $this->assertSame(1, $values['Voice']);
        $this->assertSame(2, $values['Pain']);
        $this->assertSame(3, $values['Unresponsive']);
        $this->assertSame(0, $values['DPO'], 'DPO (sedasi) bernilai 0; digantikan RASS');
    }

    public function test_consciousness_enum_and_config_stay_in_sync(): void
    {
        $values = $this->config()['parameters']['Kesadaran']['values'];

        foreach (ConsciousnessLevel::cases() as $level) {
            $this->assertArrayHasKey(
                $level->value,
                $values,
                'enum ConsciousnessLevel dan config/ews.php harus punya kunci yang sama',
            );
        }
    }

    public function test_the_temperature_gaps_are_deliberate(): void
    {
        // Celah band pada `Temp` disengaja (35.0-35.1, 36.0-36.1, 38.0-38.1,
        // 41.0-41.1). Nilai di dalamnya tidak boleh cocok band mana pun; skor
        // -nya 0, bukan error.
        $bands = $this->config()['parameters']['Temp']['bands'];

        foreach ([35.05, 36.05, 38.05, 41.05] as $gap) {
            $matched = false;

            foreach ($bands as $band) {
                if ($gap >= $band['min'] && $gap <= $band['max']) {
                    $matched = true;
                }
            }

            $this->assertFalse($matched, "suhu $gap seharusnya jatuh di celah band");
        }
    }

    public function test_range_parameter_bands_are_ordered_and_do_not_overlap(): void
    {
        foreach ($this->config()['parameters'] as $name => $parameter) {
            if (($parameter['type'] ?? null) !== 'range') {
                continue;
            }

            $previousMax = null;

            foreach ($parameter['bands'] as $band) {
                $this->assertLessThanOrEqual($band['max'], $band['min'], "band $name terbalik");

                if ($previousMax !== null) {
                    $this->assertGreaterThan($previousMax, $band['min'], "band $name tumpang tindih");
                }

                $previousMax = $band['max'];
            }
        }
    }
}

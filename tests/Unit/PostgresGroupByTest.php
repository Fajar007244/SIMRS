<?php

namespace Tests\Unit;

use App\Models\Encounter;
use App\Models\Observation;
use App\Models\ObservationMedication;
use App\Services\MedicationRecapService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Aturan SQL yang HANYA ditegakkan PostgreSQL.
 *
 * SQLite dan MySQL memaafkan kolom di ORDER BY yang tidak ada di SELECT
 * maupun GROUP BY. PostgreSQL menolaknya dengan SQLSTATE 42803. Akibatnya
 * seluruh tes lokal (SQLite) lolos, lalu produksi hancur hanya di produksi.
 *
 * Tes ini memeriksa bentuk SQL-nya, bukan hanya "halaman tidak 500",
 * supaya jenis bug ini tidak bisa lolos diam-diam lagi.
 */
class PostgresGroupByTest extends TestCase
{
    use RefreshDatabase;

    private Encounter $encounter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->encounter = Encounter::factory()->create();

        // Dibuat langsung, bukan lewat ObservationFactory: factory itu menghitung
        // skor EWS dari config dan tidak dipakai tes ini sama sekali.
        $observation = Observation::create([
            'encounter_id' => $this->encounter->getKey(),
            'observation_date' => '2026-09-07',
            'observation_time' => '10:00',
            'sys' => 120,
            'dia' => 80,
            'hr' => 80,
            'rr' => 16,
            'suhu' => 37,
            'spo2' => 98,
            'kesadaran' => 'Alert',
            'ews_total' => 0,
            'ews_risk' => 'low',
        ]);

        ObservationMedication::create([
            'observation_id' => $observation->getKey(),
            'name' => 'Amoksisilin',
            'dose' => '500 mg',
            'category' => 'Antibiotik',
            'volume' => 10,
        ]);
    }

    public function test_the_category_distribution_query_is_valid_for_postgres(): void
    {
        $service = app(MedicationRecapService::class);

        // Tangkap SQL yang benar-benar dikirim ke database.
        $sql = null;

        DB::listen(function ($query) use (&$sql): void {
            if (str_contains($query->sql, 'group by') && str_contains($query->sql, 'row_count')) {
                $sql = $query->sql;
            }
        });

        $service->getCategoryDistribution($this->encounter);

        $this->assertNotNull($sql, 'Query agregat kategori tidak pernah dieksekusi.');
        $this->assertStringNotContainsString(
            'order by',
            strtolower((string) $sql),
            'Query ber-GROUP BY tidak boleh membawa ORDER BY per baris: PostgreSQL menolak dengan 42803.'
        );
    }

    /**
     * Menegakkan aturan yang sama tanpa bergantung pada database sama sekali,
     * supaya tes ini tetap berguna walau driver tes diganti.
     */
    public function test_aggregate_queries_never_order_by_ungrouped_columns(): void
    {
        $service = app(MedicationRecapService::class);

        $statements = [];

        DB::listen(function ($query) use (&$statements): void {
            $statements[] = strtolower($query->sql);
        });

        $service->getCategoryDistribution($this->encounter);

        foreach ($statements as $sql) {
            if (! str_contains($sql, 'group by')) {
                continue;
            }

            $this->assertDoesNotMatchRegularExpression(
                '/\border\s+by\b[^;]*\b(observation_id|sort_order)\b/',
                $sql,
                "Kolom observation_id/sort_order di ORDER BY tapi tidak di GROUP BY:\n".$sql
            );
        }
    }

    public function test_the_row_listing_query_still_keeps_its_ordering(): void
    {
        $service = app(MedicationRecapService::class);

        $sql = null;

        DB::listen(function ($query) use (&$sql): void {
            if (str_contains($query->sql, 'from "observation_medications"')
                && ! str_contains(strtolower($query->sql), 'group by')) {
                $sql = strtolower($query->sql);
            }
        });

        $service->getAdministrations($this->encounter);

        $this->assertNotNull($sql);
        $this->assertStringContainsString('order by', $sql, 'Daftar baris harus tetap terurut.');
    }
}
<?php

namespace Tests\Feature;

use App\Support\DatabaseErrorReport;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PDOException;
use Tests\TestCase;

/**
 * Diagnosa error database ke tabel `error_reports`.
 *
 * Alasan alat ini ada: PostgreSQL membatalkan seluruh transaksi begitu satu
 * statement ditolak, sehingga statement berikutnya melaporkan 25P02 "current
 * transaction is aborted" dan menunjuk ke pernyataan yang TIDAK bersalah.
 * Kalau penyebab aslinya tersembunyi di balik symptom itu, alat diagnosa ini
 * justru ikut menyimpan symptom dan membuat kita menebak keliru.
 *
 * Semua kelas di sini SEMENTARA dan harus dihapus setelah penyebabnya ketemu.
 */
class ErrorReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_database_failures_are_recorded(): void
    {
        DatabaseErrorReport::record(new \RuntimeException('bukan error database'), Request::create('/pasien'));

        $this->assertSame(0, DB::table('error_reports')->count());
    }

    public function test_a_database_failure_is_recorded_with_its_sql_and_sqlstate(): void
    {
        DatabaseErrorReport::record(
            $this->queryException('23505', 'insert into "observations" ...', 'duplicate key value violates unique constraint'),
            Request::create('/encounters/x/observasi')
        );

        $row = DB::table('error_reports')->sole();

        $this->assertSame('23505', $row->sqlstate);
        $this->assertSame('insert into "observations" ...', $row->sql);
        $this->assertSame('encounters/x/observasi', $row->path);
    }

    /**
     * Bentuk yang benar-benar muncul di produksi: QueryException(25P02)
     * membungkus PDOException yang pesan driver-nya menyimpan penyebab asli.
     */
    public function test_the_driver_message_is_kept_in_the_chain(): void
    {
        DatabaseErrorReport::record(
            $this->queryException(
                '25P02',
                'delete from "observation_medications" where "observation_id" = 64',
                'current transaction is aborted, commands ignored until end of transaction block'
            ),
            Request::create('/encounters/x/observasi')
        );

        $chain = json_decode(DB::table('error_reports')->sole()->chain, true);

        $this->assertCount(2, $chain, 'Rantai harus QueryException -> PDOException.');
        $this->assertSame('Illuminate\Database\QueryException', $chain[0]['class']);
        $this->assertSame('25P02', $chain[0]['sqlstate']);
        $this->assertSame('delete from "observation_medications" where "observation_id" = 64', $chain[0]['sql']);

        $this->assertSame('PDOException', $chain[1]['class']);
        $this->assertStringContainsString('current transaction is aborted', $chain[1]['message']);
        $this->assertNull($chain[1]['sql'], 'PDOException tidak membawa SQL.');
    }

    /**
     * Kalau rantainya ternyata lebih dalam, sqlstate di dalam itu yang
     * perlu diperhatikan - di situlah penyebab sebenarnya berada.
     */
    public function test_a_deeper_chain_exposes_an_inner_sqlstate(): void
    {
        $inner = $this->queryException('23505', 'insert into "observations" ...', 'duplicate key value violates unique constraint "observations_slot_unique"');
        $outer = $this->queryException('25P02', 'delete from "observation_medications"', 'current transaction is aborted');

        // Exception::$previous protected; diset lewat reflection.
        (new \ReflectionProperty(\Exception::class, 'previous'))->setValue($outer, $inner);

        DatabaseErrorReport::record($outer, Request::create('/x'));

        $chain = json_decode(DB::table('error_reports')->sole()->chain, true);

        $this->assertGreaterThanOrEqual(3, count($chain));
        $this->assertSame('23505', $chain[1]['sqlstate'], 'SQLSTATE dalam rantai harus ikut tersimpan.');
    }

    /**
 * Tes integrasi: exception database yang tidak tertangani harus menjadi 500
 * yang wajar, dan detailnya ikut tersimpan lewat reportable() di
 * bootstrap/app.php. Ini yang membuktikan alat diagnosa benar-benar terpasang,
 * bukan hanya kelasnya yang benar.
 */
    public function test_the_same_error_is_persisted_when_storage_works(): void
    {
        Route::post('/__probe/error', function (): void {
            throw $this->queryException(
                '25P02',
                'delete from "observation_medications" where "observation_id" = 64',
                'current transaction is aborted, commands ignored until end of transaction block'
            );
        });

        $this->postJson('/__probe/error')->assertStatus(500);

        $row = DB::table('error_reports')->sole();

        $this->assertSame('25P02', $row->sqlstate);
        $this->assertSame('delete from "observation_medications" where "observation_id" = 64', $row->sql);
        $this->assertSame('__probe/error', $row->path);

        $chain = json_decode($row->chain, true);
        $this->assertSame('PDOException', $chain[1]['class']);
    }

    /**
     * Bentuk asli Laravel: QueryException(connectionName, sql, bindings, pdoException).
     *
     * SQLSTATE pada PDOException sungguhan datang dari property errorInfo[0],
     * bukan dari argumen kedua (yang selalu 0). Test ini menirukan itu lewat
     * reflection supaya kelas diuji dengan bentuk yang benar-benar muncul.
     */
    private function queryException(string $sqlstate, string $sql, string $driverMessage): QueryException
    {
        $pdo = new PDOException($driverMessage);

        (new \ReflectionProperty(PDOException::class, 'errorInfo'))
            ->setValue($pdo, [$sqlstate, 7, $driverMessage]);

        return new QueryException('pgsql', $sql, [], $pdo);
    }
}
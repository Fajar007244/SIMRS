<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PDOException;
use Throwable;

/**
 * SEMENTARA - penangkap error database untuk diagnosa produksi.
 *
 * Hapus kelas ini, migration 2026_10_11_000001, dan blok `reportable()` di
 * bootstrap/app.php setelah penyebab error 25P02 ditemukan.
 *
 * CARA KERJA
 *
 * PostgreSQL membatalkan seluruh transaksi begitu satu statement ditolak.
 * Statement berikutnya - yang innocent - lalu melaporkan 25P02 "current
 * transaction is aborted". Halaman error Laravel tidak menampilkan rantai
 * getPrevious(), jadi penyebab aslinya hilang. Kelas ini menyimpan seluruh
 * rantai ke tabel `error_reports` supaya penyebabnya bisa dibaca.
 *
 * CATATAN KONEKSI
 *
 * Kalau penyebabnya justru koneksi yang rusak, INSERT ke tabel ini bisa ikut
 * gagal. Kegagalan penulisan sengaja ditelan tanpa menggagalkan request,
 * dan reconnect() SENGAJA tidak dicoba karena menutup koneksi juga membuang
 * transaksi yang masih terbuka.
 */
final class DatabaseErrorReport
{
    /**
     * Berapa lama baris disimpan sebelum dibersihkan sendiri.
     *
     * Tabel ini sementara, jadi isinya tidak boleh menumpuk selamanya.
     */
    private const RETENTION_HOURS = 24;

    public static function record(Throwable $e, Request $request): void
    {
        if (! self::isDatabaseFailure($e)) {
            return;
        }

        $chain = self::chain($e);

        // SENGAJA TIDAK ADA reconnect() DI SINI.
        //
        // reconnect() terlihat seperti perbaikan kalau penyebabnya koneksi
        // yang rusak, tapi efek sampingnya berbahaya: koneksi yang ditutup
        // membuang transaksi yang masih terbuka, sehingga perubahan yang
        // seharusnya di-rollback malah ikut commit. Alat diagnosa tidak boleh
        // mengubah hasil request yang sedang dilaporkan.
        // Kalau ternyata penyebabnya koneksi, INSERT di sini juga akan gagal dan
        // ditelan oleh catch di pemanggil - dan itu sendiri sudah jadi petunjuk.
        DB::table('error_reports')->insert([
            'exception_class' => $chain[0]['class'],
            'message' => $chain[0]['message'],
            'chain' => json_encode($chain, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            'sql' => $chain[0]['sql'],
            'sqlstate' => $chain[0]['sqlstate'],
            'path' => mb_substr($request->path(), 0, 255),
            'vercel_id' => mb_substr((string) $request->header('x-vercel-id'), 0, 64) ?: null,
            'server_name' => mb_substr((string) $request->header('x-vercel-deployment-url'), 0, 128) ?: null,
            'created_at' => Carbon::now(),
        ]);

        self::prune();
    }

    /**
     * Hanya error yang menyentuh database. Exception lain (misalnya
     * ValidationException) tidak relevan dan tidak perlu disimpan.
     */
    private static function isDatabaseFailure(Throwable $e): bool
    {
        do {
            if ($e instanceof QueryException) {
                return true;
            }

            $e = $e->getPrevious();
        } while ($e !== null);

        return false;
    }

    /**
     * Rantai exception, outermost dulu.
     *
     * Bentuk nyata di Laravel: QueryException membungkus satu PDOException,
     * jadi rantainya biasanya hanya dua tingkat. justement itu yang membuat
     * alat ini berguna: kalau rantainya ternyata LEBIH dalam dari dua tingkat
     * atau SQLSTATEnya bukan 25P02, berarti penyebabnya ada di dalam
     * aplikasi dan bisa dibaca. Kalau tidak, penyebabnya di lapisan koneksi.
     *
     * @return list<array{class: string, message: string, sql: ?string, sqlstate: ?string, file: string, line: int}>
     */
    private static function chain(Throwable $e): array
    {
        $chain = [];

        while ($e !== null) {
            $chain[] = [
                'class' => $e::class,
                'message' => mb_substr($e->getMessage(), 0, 2000),
                'sql' => $e instanceof QueryException
                    ? mb_substr($e->getSql(), 0, 2000)
                    : null,
                'sqlstate' => self::sqlstateOf($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ];

            $e = $e->getPrevious();
        }

        return $chain;
    }

    /**
     * SQLSTATE. QueryException menerimanya lewat getCode(); PDOException
     * menyimpannya di errorInfo[0]. Dua sumber itu harus dicek dua-duanya.
     */
    private static function sqlstateOf(Throwable $e): ?string
    {
        if ($e instanceof QueryException && $e->getCode()) {
            return mb_substr((string) $e->getCode(), 0, 8);
        }

        if ($e instanceof PDOException
            && is_array($e->errorInfo ?? null)
            && isset($e->errorInfo[0])) {
            return mb_substr((string) $e->errorInfo[0], 0, 8);
        }

        return null;
    }


    /**
     * Buang baris yang sudah lewat masa simpan, supaya tabel tidak tumbuh
     * tanpa batas selama masa diagnosa.
     */
    private static function prune(): void
    {
        DB::table('error_reports')
            ->where('created_at', '<', Carbon::now()->subHours(self::RETENTION_HOURS))
            ->limit(200)
            ->delete();
    }
}

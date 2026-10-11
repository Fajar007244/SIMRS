<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabel sementara error runtime, dipakai sebagai jendela diagnosa ketika
 * aplikasi berjalan di platform tanpa file log yang bisa dibaca ulang.
 *
 * Latar: di Vercel, LOG_CHANNEL=stderr membuat log masuk ke Function Logs
 * dashboard, dan isinya tidak selalu bisa diambil. File log pun mustahil:
 * storage read-only dan /tmp dihapus tiap instance selesai. Satu-satunya
 * tempat yang bertahan adalah database yang sudah dipakai aplikasi.
 *
 * APA YANG DISIMPAN
 *
 *  1. Rantai exception PENUH lewat getPrevious(), bukan cuma pesan luarnya.
 *     PostgreSQL membatalkan seluruh transaksi begitu satu statement gagal,
 *     sehingga statement berikutnya melaporkan 25P02 "current transaction is
 *     aborted". Padahal statement yang SEBENARNYA ditolak adalah yang pertama
 *     di rantai itu. Tanpa rantai ini penyebabnya mustahil dibaca.
 *
 *  2. sql dan sqlstate pernyataan yang ditolak, supaya tidak perlu menebak
 *     dari pesan error.
 *
 *  3. URL dan x-vercel-id sebagai penanda request, supaya mudah dicari di
 *     Function Logs kalau tetap perlu.
 *
 * CATATAN KEAMANAN
 *
 *  Isi tabel hanya pesan error internal, tidak ada data pasien di dalamnya.
 *  Tetap harus dibersihkan sesekali. Setelah penyebab 25P02 ditemukan,
 *  migration ini dan pemanggilnya di bootstrap/app.php sebaiknya dihapus
 *  beserta tabelnya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('error_reports', function (Blueprint $table) {
            $table->id();

            $table->text('exception_class');
            $table->text('message');

            // JSON: [{class, message, sql, sqlstate, file, line}, ...]
            $table->json('chain');

            $table->text('sql')->nullable();
            $table->string('sqlstate', 8)->nullable();

            $table->text('path')->nullable();
            $table->string('vercel_id', 64)->nullable();
            $table->string('server_name', 128)->nullable();

            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('error_reports');
    }
};
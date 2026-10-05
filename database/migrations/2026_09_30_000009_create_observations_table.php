<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Observasi EWS per jam.
     *
     * KUNCI UNIK (encounter_id, observation_date, observation_time) ADALAH
     * BAGIAN YANG PENTING dari desain ini, bukan sekadar index biasa.
     * phase1/app-context.js memakai "<encounterId>:<tanggal>:<waktu>" sebagai
     * idempotencyKey: saveObservation() menolak menyimpan duplikat, dan
     * deleteObservation() menghapus berdasarkan triplet yang sama. Tanpa
     * unique constraint di database, dua tab terbuka bisa saja menyimpan
     * dua baris untuk jam yang sama. Unique constraint inilah yang membuat
     * upsert "simpan atau timpa" itu bisa diwajibkan oleh database.
     *
     * CATATAN RESERVED WORD - kenapa `map`, `output`, dan `group` tetap aman:
     *
     * 1. Kata `MAP` di PostgreSQL berstatus NON-reserved (bisa dipakai sebagai
     *    nama kolom tanpa kutip). Kata `OUTPUT` dan `GROUP` memang reserved,
     *    tetapi Laravel MENGAPIT SEMUA identifier dengan tanda kutip dua
     *    lewat Grammar::wrap() pada grammar PostgreSQL maupun SQLite, sehingga
     *    keduanya menjadi ordinary identifier. Karena itu tidak perlu
     *    nama pengganti seperti `map_pressure`.
     * 2. Kolom `map` sengaja dipertahankan agar cocok dengan payload phase1
     *    (app-context.js memakai `observation.map`) dan nama label pada UI.
     * 3. Raw SQL di kemudian hari WAJIB konsisten memakai quoting Laravel
     *    (DB::raw('"map"')) bila tidak lewat builder.
     *
     * Catatan tipe kolom:
     * - Tidak ada kolom enum native (tidak kompatibel lintas driver).
     * - `ews_scores` json: peta skor per parameter, mis. {"RR":2,"HR":3,...}.
     * - `ventilator_settings` json: setelan ventilator bila terpasang.
     * - `suhu` decimal(4,1) muat rentang -99.9 sampai 999.9, cukup untuk
     *   suhu badan dan tetap presisi 1 desimal.
     * - `intake` / `output` decimal(8,1) muat hingga 9.999.999,9 mL.
     */
    public function up(): void
    {
        Schema::create('observations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('encounter_id')->constrained('encounters')->cascadeOnDelete();
            $table->date('observation_date');
            $table->time('observation_time');

            $table->smallInteger('sys');
            $table->smallInteger('dia');
            $table->smallInteger('map')->nullable();
            $table->smallInteger('hr');
            $table->string('rhythm')->nullable();
            $table->smallInteger('rr');
            $table->string('breath_type')->nullable();
            $table->decimal('suhu', 4, 1);
            $table->smallInteger('spo2');
            $table->string('o2_support')->nullable();
            $table->string('kesadaran');
            $table->smallInteger('gcs')->nullable();
            $table->decimal('intake', 8, 1)->nullable();
            $table->decimal('output', 8, 1)->nullable();

            $table->smallInteger('ews_total');
            $table->json('ews_scores')->nullable();
            $table->string('ews_risk');
            $table->text('notes')->nullable();
            $table->json('ventilator_settings')->nullable();
            $table->string('status')->default('final');

            $table->string('recorded_by')->nullable();
            $table->timestamp('recorded_at')->nullable();
            $table->string('idempotency_key')->nullable();

            $table->timestamps();

            $table->unique(['encounter_id', 'observation_date', 'observation_time'], 'observations_slot_unique');
            $table->index(['encounter_id', 'ews_risk'], 'observations_risk_index');
            $table->index('recorded_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('observations');
    }
};

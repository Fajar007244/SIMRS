<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\DischargeDestination;
use App\Enums\EncounterStatus;
use App\Models\Encounter;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Penutupan episode perawatan (discharge).
 *
 * MENGAPA BUKAN TOGGLE
 *
 * Toggle "aktif/nonaktif" terlihat sederhana, tapi status episode bukan
 * saklar: begitu episode selesai, seluruh data kliniknya terkunci - termasuk
 * tanggal keluar. Satu klik salah berarti ratusan baris observasi tidak bisa
 * dikoreksi. Karena itu penutupan episode memakai form yang memaksa petugas
 * memilih tanggal keluar, memilih nasib pasien, dan mengetik kata konfirmasi.
 *
 * MENGAPA TIDAK ADA "BATAL"
 *
 * Dokumen klinis yang sudah ditutup tidak dibuka kembali lewat aplikasi ini.
 * Bila ternyata ada yang salah, koreksi dilakukan pada episode yang masih
 * berjalan, bukan dengan membongkar episode lama. Ini membuat status dan
 * discharged_at hanya bergerak satu arah, sehingga rekap lama tidak pernah
 * berubah sendiri.
 *
 * ATURAN YANG DITEGAKKAN DI SINI
 *
 *  1. Hanya episode `aktif` yang boleh ditutup. Menutup yang sudah selesai
 *     tidak mungkin dan tidak boleh mengubah apa pun.
 *  2. Tanggal keluar tidak boleh lebih awal dari tanggal masuk.
 *  3. Penutupan menyimpan AKTOR (discharged_by) dan alasan
 *     (discharge_note) supaya status tidak pernah berubah tanpa jejak.
 *  4. Pasien TIDAK ikut berubah. `patients.is_active` tidak disentuh di sini:
 *     status rek medis berbeda dari status episode, dan mencampur keduanya
 *     membuat pasien yang sudah pulang tapi masih berobat hilang dari
 *     pencarian.
 */
final class DischargeService
{
    /**
     * Kata yang harus diketik ulang petugas. huruf besar semua, tanpa spasi,
     * supaya tidak bisa terisi kebetulan oleh tombol Tab atau Enter.
     */
    public const CONFIRMATION_WORD = 'SELESAI';

    /**
     * @param  array<string, mixed>  $data  discharged_at, destination, note, confirmation
     *
     * @throws ValidationException
     */
    public function discharge(Encounter $encounter, array $data, User $actor): Encounter
    {
        $this->assertClosable($encounter);

        $dischargedAt = $this->parseDate($data['discharged_at'] ?? null);
        $destination = $this->parseDestination($data['destination'] ?? $data['discharge_destination'] ?? null);
        $note = $this->nullableNote($data['note'] ?? $data['discharge_note'] ?? null);

        $this->assertNotBeforeAdmission($encounter, $dischargedAt);

        // Seluruh penulisan dalam SATU transaksi supaya episode tidak pernah
        // terlihat "selesai" tanpa tanggal keluar (atau sebaliknya) oleh
        // pembaca lain di sela-sela.
        return DB::transaction(function () use ($encounter, $dischargedAt, $destination, $note, $actor) {
            $encounter->forceFill([
                'status' => EncounterStatus::SELESAI->value,
                'discharged_at' => $dischargedAt,
                'discharged_by' => $actor->getKey(),
                'discharge_destination' => $destination->value,
                'discharge_note' => $note,
            ])->save();

            return $encounter->fresh();
        });
    }

    /**
     * Pesan yang muncul kalau episode sudah selesai, untuk ditampilkan di UI
     * read-only. Memisahkan "menolak" dari "mengecek" supaya pemanggil tidak
     * perlu tahu detail aturan mainnya.
     */
    public function closableMessage(Encounter $encounter): ?string
    {
        if ($encounter->status === EncounterStatus::SELESAI) {
            return 'Episode ini sudah selesai pada '
                .$encounter->discharged_at?->translatedFormat('d M Y H:i').'.';
        }

        return null;
    }

    private function assertClosable(Encounter $encounter): void
    {
        if ($encounter->status === EncounterStatus::SELESAI) {
            throw ValidationException::withMessages([
                'encounter' => ['Episode perawatan ini sudah selesai.'],
            ]);
        }
    }

    private function assertNotBeforeAdmission(Encounter $encounter, Carbon $dischargedAt): void
    {
        if (! $encounter->admitted_at instanceof Carbon) {
            return;
        }

        if ($dischargedAt->lt($encounter->admitted_at->copy()->startOfDay())) {
            throw ValidationException::withMessages([
                'discharged_at' => ['Tanggal keluar tidak boleh lebih awal dari tanggal masuk.'],
            ]);
        }
    }

    private function parseDate(mixed $value): Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            throw ValidationException::withMessages([
                'discharged_at' => ['Tanggal keluar wajib diisi.'],
            ]);
        }

        try {
            // Formulir mengirim <input type="date">, jadi formatnya Y-m-d.
            return Carbon::createFromFormat('Y-m-d', trim($value))->startOfDay();
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'discharged_at' => ['Tanggal keluar tidak valid.'],
            ]);
        }
    }

    private function parseDestination(mixed $value): DischargeDestination
    {
        if (! is_string($value) || trim($value) === '') {
            throw ValidationException::withMessages([
                'destination' => ['Pilih kondisi pasien saat keluar.'],
            ]);
        }

        $destination = DischargeDestination::tryFrom(trim($value));

        if ($destination === null) {
            throw ValidationException::withMessages([
                'destination' => ['Kondisi pasien saat keluar tidak dikenal.'],
            ]);
        }

        return $destination;
    }

    private function nullableNote(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $note = trim($value);

        return $note === '' ? null : mb_substr($note, 0, 1000);
    }
}

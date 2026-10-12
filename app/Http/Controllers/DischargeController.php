<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\DischargeDestination;
use App\Models\Encounter;
use App\Services\DischargeService;
use App\Services\Support\ClinicalFormat;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * POST /encounters/{encounter}/discharge
 *
 * Menutup satu episode perawatan.
 *
 * Rute memakai `role:dokter` sesuai baris "Tutup encounter (discharge)" di
 * docs/AUTHORIZATION.md: hanya dokter yang boleh menutup episode. Perawat dan
 * bidan tetap bisa MEMBACA episode yang sudah selesai - riwayat medis tidak
 * hilang hanya karena episode ditutup.
 *
 * KENAPA TIDAK ADA TOGGLE
 *
 * Status episode mengunci seluruh data kliniknya, jadi penutupan memakai
 * petugas memilih tanggal keluar, memilih nasib pasien, dan mengetik kata
 * konfirmasi. Salah klik tidak mungkin menutup episode karena tidak ada
 * tombol yang langsung mengubah status.
 *
 * KONFIRMASI DIJAGA DI SERVER
 *
 * Kata "SELESAI" tidak hanya dicek di JavaScript. `guardConfirmation()`
 * menolak permintaan yang mengirimannya kosong atau salah, termasuk permintaan
 * yang dibuat tanpa JavaScript atau diketik tangan. Tanpa penjaga itu, form
 * bisa di-submit kosong dan episode tertutup tanpa disengaja.
 */
class DischargeController extends Controller
{
    public function __construct(private readonly DischargeService $discharge) {}

    /**
     * Pesan sukses. Dipisah supaya tes dan pemanggil lain memakai
     * kalimat yang sama persis.
     */
    public static function successMessage(string $patientName, string $destinationLabel): string
    {
        return 'Episode perawatan '.$patientName.' ditutup - '.$destinationLabel.'.';
    }

    public function store(Request $request, Encounter $encounter): RedirectResponse
    {
        abort_unless(
            $encounter->exists,
            404,
            'Episode perawatan tidak ditemukan. Periksa kembali kode encounter pada alamat halaman.'
        );

        $data = $this->payload($request);

        $this->guardConfirmation($data['confirmation']);

        $closed = $this->discharge->discharge($encounter, $data, $request->user());

        // Redirect ke daftar dengan filter "semua status", bukan default
        // "aktif". Setelah ditutup, episode ini tidak lagi cocok dengan filter
        // default, jadi dengan redirect biasa petugas akan menduga datanya
        // hilang padahal sebenarnya perubahan berhasil.
        return redirect()
            ->route('pasien', ['status' => 'all'])
            ->with('success', self::successMessage(
                (string) $closed->patient?->name,
                $closed->discharge_destination?->label() ?? '-'
            ));
    }

    /**
     * Validasi bentuk payload.
     *
     * Aturan yang TIDAK ada di sini: tanggal keluar tidak boleh mendahului
     * tanggal masuk, dan episode harus masih aktif. Keduanya butuh knowledge
     * tentang episode itu sendiri, jadi keduanya ditangani DischargeService.
     * Memisahkannya juga berarti service bisa dipakai dari tempat lain
     * (misalnya impor data) tanpa melewati aturan yang sama.
     *
     * @return array<string, mixed>
     */
    private function payload(Request $request): array
    {
        return $request->validate([
            'discharged_at' => ['required', 'date_format:Y-m-d'],
            'destination' => ['required', 'string', Rule::in(array_column(DischargeDestination::cases(), 'value'))],
            'note' => ['nullable', 'string', 'max:1000'],
            'confirmation' => ['required', 'string'],
        ], [
            'discharged_at.required' => 'Tanggal keluar wajib diisi.',
            'discharged_at.date_format' => 'Tanggal keluar tidak valid.',
            'destination.required' => 'Pilih kondisi pasien saat keluar.',
            'destination.in' => 'Kondisi pasien saat keluar tidak dikenal.',
            'note.max' => 'Keterangan maksimal 1000 karakter.',
            'confirmation.required' => 'Ketik SELESAI untuk mengonfirmasi penutupan episode.',
        ], [
            'discharged_at' => 'tanggal keluar',
            'destination' => 'kondisi pasien saat keluar',
            'note' => 'keterangan',
            'confirmation' => 'konfirmasi',
        ]);
    }

    /**
     * Penjaga server untuk konfirmasi petugas.
     *
     * Perbandingan dilakukan setelah normalisasi spasi dan huruf besar, supaya
     * ketik "selesai" tetap diterima - yang ditolak adalah mengosongkan kolom
     * atau mengetik kata lain, bukan variasi kapitalisasi.
     *
     * @throws ValidationException
     */
    private function guardConfirmation(mixed $value): void
    {
        $normalized = is_string($value)
            ? mb_strtoupper(preg_replace('/\s+/', '', trim($value)) ?? '')
            : '';

        if ($normalized !== DischargeService::CONFIRMATION_WORD) {
            throw ValidationException::withMessages([
                'confirmation' => 'Ketik '.DischargeService::CONFIRMATION_WORD.' untuk mengonfirmasi penutupan episode.',
            ]);
        }
    }

    /**
     * Nilai bawaan tanggal keluar untuk pre-fill form: hari ini.
     */
    public static function defaultDischargeDate(): string
    {
        return ClinicalFormat::iso(now()) ?? now()->toDateString();
    }
}

<?php

namespace App\Enums;

/**
 * Kemana pasien pergi setelah episode perawatan ditutup.
 *
 * Di RSP, nasib pasien bukan satu pilihan tunggal: ada yang pulang ke
 * yang dirawat inap karena kondisinya belum stabil. Karena itu disimpan
 * sebagai kolom terpisah dari `encounters.status` yang hanya menjawab
 * "sudah selesai atau belum".
 *
 * Nilai backing dipakai apa adanya di database, jadi menambah anggota baru
 * nanti tidak mengubah baris lama.
 */
enum DischargeDestination: string
{
    /** Pulang ke rumah, dilanjutkan rawat jalan. */
    case PULANG = 'pulang';

    /** Dirawat inap karena kondisi belum stabil. */
    case RAWAT_INAP = 'rawat_inap';

    /** Meninggal dunia. */
    case MENINGGAL = 'meninggal';

    /** Rujuk ke fasilitas lain. */
    case RUJUK = 'rujuk';

    /** Pulang dalam kondisi perlu kontrol cepat. */
    case PULANG_KONTROL = 'pulang_kontrol';

    public function label(): string
    {
        return match ($this) {
            self::PULANG => 'Pulang',
            self::RAWAT_INAP => 'Rawat Inap',
            self::MENINGGAL => 'Meninggal',
            self::RUJUK => 'Rujuk',
            self::PULANG_KONTROL => 'Pulang Kontrol',
        };
    }

    /**
     * @return array<string, string> label => value
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->label()] = $case->value;
        }

        return $options;
    }

    /**
     * Destinasi yang membuat episode lama sebenarnya belum berakhir.
     *
     * Episode yang ditutup dengan alasan ini tidak boleh dibaca sebagai
     * "pasien sudah tidak ada di RSP": pasien masih di sini, hanya berpindah
     * unit atau rujukan. Dipakai untuk memberi peringatan di
     * layar, bukan untuk memblokir apa pun.
     */
    public function isOngoingCare(): bool
    {
        return in_array($this, [self::RAWAT_INAP, self::RUJUK], true);
    }
}

<?php

namespace App\Services\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Format tanggal, waktu, dan angka untuk UI berbahasa Indonesia.
 *
 * Seluruh layer service memakai kelas ini agar tanggal yang sampai ke Inertia
 * selalu berbentuk sama dengan yang dirender phase1: tanggal d/m/Y dan waktu
 * 24 jam H:i.
 *
 * KONVENSI PENTING untuk empat developer front-end:
 *  - Nilai mentah selalu dikirim apa adanya pada field biasa (mis. `recordedAt`
 *    berisi ISO 8601, `date` berisi `Y-m-d`).
 *  - Field yang berakhiran `*Label` sudah diformat SIAP TAMPIL dan memakai
 *    '-' sebagai pengganti ketika datanya kosong. Fase1 memakai '-' di semua
 *    tempat ini, jadi '-' bukan tanda data hilang yang ambigu.
 *  - Label untuk titik grafik memakai ClinicalFormat::chartLabel() (d/m H:i)
 *    di semua service, supaya halaman farmasi, bundles, observasi, dan
 *    penunjang memakai format yang sama.
 */
final class ClinicalFormat
{
    /** Nilai pengganti untuk seluruh field `*Label` yang kosong. */
    public const EMPTY = '-';

    /**
     * Teks siap tampil, atau '-' bila null / kosong / sudah berupa '-'.
     */
    public static function dash(mixed $value): string
    {
        if ($value === null) {
            return self::EMPTY;
        }

        $text = trim((string) $value);

        return ($text === '' || $text === '-') ? self::EMPTY : $text;
    }

    /**
     * d/m/Y
     */
    public static function date(mixed $value): ?string
    {
        return self::parse($value)?->format('d/m/Y');
    }

    /**
     * H:i
     */
    public static function time(mixed $value): ?string
    {
        return self::parse($value)?->format('H:i');
    }

    /**
     * d/m/Y H:i
     */
    public static function dateTime(mixed $value): ?string
    {
        return self::parse($value)?->format('d/m/Y H:i');
    }

    /**
     * Label sumbu X / tooltip tren: d/m H:i.
     */
    public static function chartLabel(mixed $value): string
    {
        return self::parse($value)?->format('d/m H:i') ?? self::EMPTY;
    }

    public static function dateLabel(mixed $value): string
    {
        return self::date($value) ?? self::EMPTY;
    }

    public static function timeLabel(mixed $value): string
    {
        return self::time($value) ?? self::EMPTY;
    }

    public static function dateTimeLabel(mixed $value): string
    {
        return self::dateTime($value) ?? self::EMPTY;
    }

    /**
     * ISO 8601 lengkap dengan offset, aman langsung di-parse JavaScript.
     * Ini nilai mentah, bukan untuk ditampilkan.
     */
    public static function iso(mixed $value): ?string
    {
        return self::parse($value)?->toIso8601String();
    }

    /**
     * Angka dengan pemisah ribuan gaya Indonesia (1.234,56).
     */
    public static function number(float|int|string|null $value, int $decimals = 0): string
    {
        if ($value === null || $value === '') {
            return self::EMPTY;
        }

        if (! is_numeric($value)) {
            return self::dash($value);
        }

        return number_format((float) $value, $decimals, ',', '.');
    }

    /**
     * Angka bertanda untuk balance cairan: +180 / -120 / 0.
     */
    public static function signed(float|int|null $value, int $decimals = 0): string
    {
        if ($value === null) {
            return self::EMPTY;
        }

        $formatted = number_format(abs((float) $value), $decimals, ',', '.');

        return ((float) $value) > 0 ? '+'.$formatted : $formatted;
    }

    /**
     * Ubah input bebas menjadi float, atau null bila bukan angka.
     *
     * Port numberOrNull() di app-context.js. Bedanya: string kosong dan
     * whitespace tidak dianggap 0. JavaScript Number('') adalah 0, tetapi
     * saveSupportResult() di prototype sudah menolak nilai kosong lebih dulu
     * dengan pesan "Nilai harus berupa angka."
     */
    public static function numeric(mixed $value): ?float
    {
        if ($value === null || $value === '' || is_bool($value) || is_array($value)) {
            return null;
        }

        if (is_string($value) && trim($value) === '') {
            return null;
        }

        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * Bilangan bulat atau null, untuk kolom yang di-cast integer.
     */
    public static function integer(mixed $value): ?int
    {
        return self::numeric($value) === null ? null : (int) round((float) $value);
    }

    /**
     * Potong kalimat pertama pada `plan` yang memuat pola regex.
     *
     * Port persis findPlanFragment() pada phase1/farmasi.html: cari kemunculan
     * pertama pola pada teks huruf kecil, lalu expand ke batas titik di kiri
     * dan kanan, dan potong dari teks ASMED ASLI (bukan versi lowercase).
     */
    public static function planFragment(?string $plan, string $pattern): ?string
    {
        $plan = trim((string) $plan);

        if ($plan === '' || trim($pattern) === '') {
            return null;
        }

        $lower = Str::lower($plan);

        if (preg_match('/'.$pattern.'/i', $lower, $matches) !== 1) {
            return null;
        }

        $needle = $matches[0];
        $index = strpos($lower, $needle);

        if ($index === false) {
            return null;
        }

        $start = strrpos(substr($lower, 0, $index), '.');

        if ($start === false) {
            $start = 0;
        } else {
            $start += 1;
        }

        $end = strpos($lower, '.', $index + strlen($needle));

        if ($end === false) {
            $end = strlen($lower);
        }

        return trim(substr($plan, $start, $end - $start));
    }

    /**
     * Pecah teks bebas (alergi, daftar obat) menjadi token, buang token kosong
     * dan token yang berarti "tidak ada" / "n/a" / "unknown".
     */
    public static function splitTokens(?string $value, string $pattern = '/[,;\/|]+|\bdan\b/i'): array
    {
        $value = trim((string) $value);

        if ($value === '') {
            return [];
        }

        $tokens = preg_split($pattern, $value) ?: [];

        $clean = [];

        foreach ($tokens as $token) {
            $token = trim($token);

            if ($token !== '' && ! preg_match('/^[-–]?$|^tidak ada$|^n\/?a$|^tdk ada$|^unknown$/i', $token)) {
                $clean[] = $token;
            }
        }

        return $clean;
    }

    /**
     * Parse bebas (Y-m-d, Y-m-d H:i:s, ISO 8601, Carbon) menjadi Carbon.
     * Mengembalikan null, bukan melempar, bila input tidak bisa diparse.
     */
    public static function parse(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof Carbon) {
            return $value;
        }

        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}

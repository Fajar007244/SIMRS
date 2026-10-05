<?php

namespace App\Support;

/**
 * Peta warna "tone" untuk halaman Blade.
 *
 * TUJUAN
 * Blade tidak bisa memakai komponen Vue (EwsBadge, StatCard, ...), jadi kelas
 * Tailwind-nya harus ditulis langsung di view. Supaya warnanya tidak menyimpang
 * dari SPA, semua pemetaan di sini adalah salinan verbatim dari:
 *
 *   - resources/js/tone.js             (TONE, EWS_BADGE_CLASSES, percentTone)
 *   - app/Services/EwsScoringService  (BADGE_CLASSES / FALLBACK_BADGE_CLASSES)
 *   - app/Services/BundleService      (tone(): ambang 100 / 80 / 50)
 *
 * Kalau ada tone atau ambang baru di sisi Vue, tambahkan DI SINI dan di tone.js
 * sekaligus supaya keduanya tidak pernah berbeda.
 *
 * CATATAN TAILWIND
 * tailwind.config.js hanya memindai storage/framework/views, blade di
 * resources/views, dan vue/js di resources/js - file di app/ TIDAK dipindai.
 * Karena itu kelas di bawah WAJIB tetap tercakup oleh tone.js (yang ikut
 * ter-build) supaya benar-benar tersedia di CSS hasil build. Jangan menghapus
 * tone dari tone.js tanpa memperbarui kelas di sini.
 */
class BadgeTone
{
    /**
     * Nama tone yang valid, urutan sama dengan TONE_NAMES di tone.js.
     *
     * @var array<int, string>
     */
    public const TONE_NAMES = [
        'slate', 'sky', 'emerald', 'teal', 'amber', 'orange',
        'red', 'rose', 'purple', 'indigo', 'yellow', 'hospital',
    ];

    /**
     * TONE, disalin dari resources/js/tone.js. Hanya key yang dipakai Blade;
     * deep / deepBorder / deepLabel / deepValue / hex milik komponen Vue saja.
     *
     * @var array<string, array<string, string>>
     */
    private const TONE = [
        'slate' => [
            'accent' => 'border-l-slate-400', 'icon' => 'text-slate-500', 'value' => 'text-slate-900',
            'fill' => 'bg-slate-500', 'track' => 'bg-slate-100', 'soft' => 'bg-slate-100',
            'softText' => 'text-slate-700', 'softBorder' => 'border-slate-200', 'text' => 'text-slate-600',
        ],
        'sky' => [
            'accent' => 'border-l-sky-500', 'icon' => 'text-sky-500', 'value' => 'text-sky-700',
            'fill' => 'bg-sky-500', 'track' => 'bg-slate-100', 'soft' => 'bg-sky-100',
            'softText' => 'text-sky-700', 'softBorder' => 'border-sky-200', 'text' => 'text-sky-600',
        ],
        'emerald' => [
            'accent' => 'border-l-emerald-500', 'icon' => 'text-emerald-500', 'value' => 'text-emerald-600',
            'fill' => 'bg-emerald-500', 'track' => 'bg-slate-100', 'soft' => 'bg-emerald-100',
            'softText' => 'text-emerald-700', 'softBorder' => 'border-emerald-200', 'text' => 'text-emerald-600',
        ],
        'teal' => [
            'accent' => 'border-l-teal-500', 'icon' => 'text-teal-500', 'value' => 'text-teal-700',
            'fill' => 'bg-teal-500', 'track' => 'bg-slate-100', 'soft' => 'bg-teal-100',
            'softText' => 'text-teal-700', 'softBorder' => 'border-teal-200', 'text' => 'text-teal-600',
        ],
        'amber' => [
            'accent' => 'border-l-amber-500', 'icon' => 'text-amber-500', 'value' => 'text-amber-800',
            'fill' => 'bg-amber-500', 'track' => 'bg-slate-100', 'soft' => 'bg-amber-100',
            'softText' => 'text-amber-800', 'softBorder' => 'border-amber-200', 'text' => 'text-amber-600',
        ],
        'orange' => [
            'accent' => 'border-l-orange-500', 'icon' => 'text-orange-500', 'value' => 'text-orange-600',
            'fill' => 'bg-orange-500', 'track' => 'bg-slate-100', 'soft' => 'bg-orange-100',
            'softText' => 'text-orange-800', 'softBorder' => 'border-orange-200', 'text' => 'text-orange-600',
        ],
        'red' => [
            'accent' => 'border-l-red-500', 'icon' => 'text-red-500', 'value' => 'text-red-600',
            'fill' => 'bg-red-500', 'track' => 'bg-slate-100', 'soft' => 'bg-red-100',
            'softText' => 'text-red-700', 'softBorder' => 'border-red-200', 'text' => 'text-red-600',
        ],
        'rose' => [
            'accent' => 'border-l-rose-500', 'icon' => 'text-rose-500', 'value' => 'text-rose-600',
            'fill' => 'bg-rose-500', 'track' => 'bg-slate-100', 'soft' => 'bg-rose-100',
            'softText' => 'text-rose-700', 'softBorder' => 'border-rose-200', 'text' => 'text-rose-600',
        ],
        'purple' => [
            'accent' => 'border-l-purple-500', 'icon' => 'text-purple-500', 'value' => 'text-purple-700',
            'fill' => 'bg-purple-500', 'track' => 'bg-slate-100', 'soft' => 'bg-purple-100',
            'softText' => 'text-purple-700', 'softBorder' => 'border-purple-200', 'text' => 'text-purple-600',
        ],
        'indigo' => [
            'accent' => 'border-l-indigo-500', 'icon' => 'text-indigo-500', 'value' => 'text-indigo-700',
            'fill' => 'bg-indigo-500', 'track' => 'bg-slate-100', 'soft' => 'bg-indigo-100',
            'softText' => 'text-indigo-700', 'softBorder' => 'border-indigo-200', 'text' => 'text-indigo-600',
        ],
        'yellow' => [
            'accent' => 'border-l-yellow-500', 'icon' => 'text-yellow-500', 'value' => 'text-yellow-600',
            'fill' => 'bg-yellow-500', 'track' => 'bg-slate-100', 'soft' => 'bg-yellow-100',
            'softText' => 'text-yellow-800', 'softBorder' => 'border-yellow-200', 'text' => 'text-yellow-600',
        ],
        'hospital' => [
            'accent' => 'border-l-hospital-500', 'icon' => 'text-hospital-500', 'value' => 'text-hospital-700',
            'fill' => 'bg-hospital-500', 'track' => 'bg-slate-100', 'soft' => 'bg-hospital-100',
            'softText' => 'text-hospital-700', 'softBorder' => 'border-hospital-100', 'text' => 'text-hospital-600',
        ],
    ];

    /**
     * EWS_BADGE_CLASSES di tone.js == BADGE_CLASSES di EwsScoringService.
     *
     * @var array<string, array<int, string>>
     */
    private const EWS_BADGE_CLASSES = [
        'emergency' => ['bg-red-600', 'text-white', 'border-red-700'],
        'high' => ['bg-red-100', 'text-red-800', 'border-red-200'],
        'medium' => ['bg-amber-100', 'text-amber-800', 'border-amber-200'],
        'low' => ['bg-emerald-100', 'text-emerald-800', 'border-emerald-200'],
    ];

    /**
     * @var array<int, string>
     */
    private const EWS_BADGE_FALLBACK = ['bg-slate-100', 'text-slate-700', 'border-slate-200'];

    /**
     * Kelas badge EWS. Bentuk return sama dengan
     * EwsScoringService::badgeClasses() / ewsBadgeClasses() di tone.js.
     *
     * @return array{class: string, bg: string, text: string, border: string}
     */
    public static function badge(string $risk): array
    {
        $parts = self::EWS_BADGE_CLASSES[strtolower(trim($risk))] ?? self::EWS_BADGE_FALLBACK;

        return [
            'class' => implode(' ', $parts),
            'bg' => $parts[0],
            'text' => $parts[1],
            'border' => $parts[2],
        ];
    }

    /**
     * Label pendek badge risiko, cermin EWS_RISK_LABELS di tone.js dan
     * EwsScoringService::riskLabel(). Level tidak dikenal -> '-'.
     */
    public static function label(string $risk): string
    {
        return match (strtolower(trim($risk))) {
            'low' => 'Low',
            'medium' => 'Medium',
            'high' => 'High',
            'emergency' => 'Emergency',
            default => '-',
        };
    }

    /**
     * Kelas satu tone untuk kartu statistik / chip / batang. Nama tone
     * case-insensitive; tidak dikenal -> slate.
     *
     * @return array{
     *     accent: string, icon: string, value: string, fill: string, track: string,
     *     soft: string, softText: string, softBorder: string, text: string
     * }
     */
    public static function stat(string $tone): array
    {
        return self::TONE[strtolower(trim($tone))] ?? self::TONE['slate'];
    }

    /**
     * Nama tone untuk persentase kepatuhan, port percentTone() di tone.js
     * (>=100 emerald, >=80 sky, >=50 amber, selain itu red).
     */
    public static function compliance(float $percent): string
    {
        if ($percent >= 100) {
            return 'emerald';
        }

        if ($percent >= 80) {
            return 'sky';
        }

        if ($percent >= 50) {
            return 'amber';
        }

        return 'red';
    }
}

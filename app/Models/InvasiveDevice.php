<?php

namespace App\Models;

use App\Enums\DeviceCode;
use App\Models\Concerns\BelongsToEncounter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Perangkat invasif yang terpasang pada satu episode, dengan rentang tanggal.
 *
 * Di phase1 perangkat dicatat per observasi (hadir atau tidak setiap jam).
 * Di sini menjadi baris mandiri dengan start_date / end_date, sehingga "lama
 * pakai" bisa dihitung tanpa menggabungkan seluruh observasi.
 */
class InvasiveDevice extends Model
{
    use BelongsToEncounter, HasFactory;

    protected $fillable = [
        'encounter_id',
        'device_code',
        'label',
        'start_date',
        'end_date',
        'is_active',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'device_code' => DeviceCode::class,
            'start_date' => 'date',
            'end_date' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeForDevice(Builder $query, DeviceCode|array $codes): Builder
    {
        $codes = is_array($codes) ? $codes : [$codes];

        return $query->whereIn('device_code', array_map(
            fn (DeviceCode $code) => $code->value,
            $codes
        ));
    }

    /**
     * Lama pakai dalam hari terhadap tanggal acuan, minimum 1 hari.
     * phase1 memakai pembulatan ke bawah selisih hari + 1.
     */
    public function daysInUse(?Carbon $reference = null): ?int
    {
        if (! $this->start_date instanceof Carbon) {
            return null;
        }

        $end = $this->end_date instanceof Carbon
            ? $this->end_date
            : ($reference ?? now());

        return max(1, (int) $this->start_date->startOfDay()->diffInDays($end->copy()->startOfDay()) + 1);
    }

    /**
     * True bila perangkat aktif dan sudah dipakai >= ambang review.
     * phase2 getDeviceSummary() menandai needsReview pada ambang yang sama
     * (lihat config('hai.device_review_after_days')).
     */
    public function needsReview(?Carbon $reference = null): bool
    {
        if (! $this->is_active) {
            return false;
        }

        $days = $this->daysInUse($reference);

        return $days !== null && $days >= (int) config('hai.device_review_after_days', 7);
    }
}

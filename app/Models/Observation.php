<?php

namespace App\Models;

use App\Enums\ConsciousnessLevel;
use App\Enums\EwsRiskLevel;
use App\Models\Concerns\BelongsToEncounter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Satu observasi EWS per jam pada satu episode.
 *
 * Keunikan (encounter_id, observation_date, observation_time) dijamin unique
 * constraint di database. triplets itu adalah "slot jam" yang dipakai phase1
 * sebagai idempotencyKey, jadi menyimpan ulang slot yang sama berarti memperbarui,
 * bukan menambah.
 */
class Observation extends Model
{
    use BelongsToEncounter, HasFactory;

    protected $fillable = [
        'encounter_id',
        'observation_date',
        'observation_time',
        'sys',
        'dia',
        'map',
        'hr',
        'rhythm',
        'rr',
        'breath_type',
        'suhu',
        'spo2',
        'o2_support',
        'kesadaran',
        'gcs',
        'weight_kg',
        'gcs_text',
        'rass',
        'bp_method',
        'blood_glucose',
        'respiratory_problem',
        'intake',
        'output',
        'transfusion_type',
        'transfusion_volume',
        'parenteral_volume',
        'enteral_volume',
        'urine_volume',
        'drain_volume',
        'iwl_volume',
        'ews_total',
        'ews_scores',
        'ews_risk',
        'notes',
        'nursing_action',
        'ventilator_settings',
        'status',
        'recorded_by',
        'recorded_at',
        'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'observation_date' => 'date',
            'observation_time' => 'datetime:H:i',
            'kesadaran' => ConsciousnessLevel::class,
            'ews_risk' => EwsRiskLevel::class,
            'ews_scores' => 'array',
            'ventilator_settings' => 'array',
            'suhu' => 'decimal:1',
            'intake' => 'decimal:1',
            'output' => 'decimal:1',
            'map' => 'integer',
            'gcs' => 'integer',
            'weight_kg' => 'decimal:1',
            'rass' => 'integer',
            'blood_glucose' => 'integer',
            'transfusion_volume' => 'decimal:1',
            'parenteral_volume' => 'decimal:1',
            'enteral_volume' => 'decimal:1',
            'urine_volume' => 'decimal:1',
            'drain_volume' => 'decimal:1',
            'iwl_volume' => 'decimal:1',
            'ews_total' => 'integer',
            'recorded_at' => 'datetime',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Relasi
    |--------------------------------------------------------------------------
    */

    public function medications(): HasMany
    {
        return $this->hasMany(ObservationMedication::class)->orderBy('sort_order')->orderBy('id');
    }

    public function bundleAnswers(): HasMany
    {
        return $this->hasMany(ObservationBundleAnswer::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Scope
    |--------------------------------------------------------------------------
    */

    /**
     * Urut kronologis: tanggal lalu jam, keduanya menaik.
     */
    public function scopeChronological(Builder $query): Builder
    {
        return $query->orderBy('observation_date')->orderBy('observation_time');
    }

    /**
     * Urut terbaru lebih dulu, sama seperti tampilan flowsheet di phase1.
     */
    public function scopeLatestFirst(Builder $query): Builder
    {
        return $query->orderByDesc('observation_date')->orderByDesc('observation_time');
    }

    public function scopeOnDate(Builder $query, string|Carbon $date): Builder
    {
        return $query->whereDate('observation_date', $date);
    }

    public function scopeAtRisk(Builder $query, EwsRiskLevel|array $levels): Builder
    {
        $levels = is_array($levels) ? $levels : [$levels];

        return $query->whereIn('ews_risk', array_map(
            fn (EwsRiskLevel $level) => $level->value,
            $levels
        ));
    }

    /*
    |--------------------------------------------------------------------------
    | Accessor
    |--------------------------------------------------------------------------
    */

    /**
     * Cap waktu observasi gabungan tanggal + jam, dipakai untuk sorting
     * (phase1 sortObservationsChronologically) dan badge kolom pertama.
     */
    public function getRecordedOnAttribute(): ?Carbon
    {
        if (! $this->observation_date instanceof Carbon) {
            return null;
        }

        $time = $this->observation_time;

        $base = $this->observation_date->copy()->startOfDay();

        if (! $time instanceof Carbon) {
            return $base;
        }

        return $base->setTime(
            (int) $time->format('H'),
            (int) $time->format('i'),
            (int) $time->format('s')
        );
    }

    /**
     * Tekanan darah gabungan "88/52" untuk kolom flowsheet.
     */
    public function getBloodPressureAttribute(): string
    {
        return $this->sys.'/'.$this->dia;
    }

    /**
     * Balance cairan satu entri observasi, dalam mL.
     * phase1 getSummary() memakai intake - output per entri, bukan kumulatif.
     */
    public function getFluidBalanceAttribute(): ?float
    {
        if ($this->intake === null && $this->output === null) {
            return null;
        }

        return (float) $this->intake - (float) $this->output;
    }
}

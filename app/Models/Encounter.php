<?php

namespace App\Models;

use App\Enums\DiagnosisType;
use App\Enums\EncounterStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Episode perawatan / admisi.
 *
 * `completion` menggantikan fungsi getCompletion() pada phase1
 * app-context.js secara 1:1. Empat boolean itu menentukan badge
 * "Data Sudah Diisi" pada tiap tab di halaman profil / CPPT, sehingga harus
 * dijawab hanya dari keberadaan baris, tanpa menebak isi kolom.
 */
class Encounter extends Model
{
    use HasFactory;

    protected $fillable = [
        'encounter_id',
        'patient_id',
        'unit',
        'bed',
        'admitted_at',
        'discharged_at',
        'attending_physician',
        'status',
        'diagnosis_summary',
        'allergy_alert',
        'latest_observation_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'status' => EncounterStatus::class,
            'admitted_at' => 'datetime',
            'discharged_at' => 'datetime',
            'latest_observation_at' => 'datetime',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Relasi
    |--------------------------------------------------------------------------
    */

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /**
     * ASMED bersifat one-to-one: hanya ada satu per episode (dijamin unique
     * constraint di database).
     */
    public function asmed(): HasOne
    {
        return $this->hasOne(Asmed::class);
    }

    public function diagnoses(): HasMany
    {
        return $this->hasMany(Diagnosis::class)->orderBy('id');
    }

    public function procedures(): HasMany
    {
        return $this->hasMany(Procedure::class)->orderByDesc('performed_at')->orderByDesc('id');
    }

    public function nursingCares(): HasMany
    {
        return $this->hasMany(NursingCare::class)->latest('recorded_at')->latest('id');
    }

    public function medicalNotes(): HasMany
    {
        return $this->hasMany(MedicalNote::class)
            ->orderBy('noted_at')
            ->orderBy('order_number')
            ->orderBy('id');
    }

    public function observations(): HasMany
    {
        return $this->hasMany(Observation::class);
    }

    public function observationMedications(): HasManyThrough
    {
        return $this->hasManyThrough(
            ObservationMedication::class,
            Observation::class,
            'encounter_id',
            'observation_id'
        );
    }

    public function observationBundleAnswers(): HasManyThrough
    {
        return $this->hasManyThrough(
            ObservationBundleAnswer::class,
            Observation::class,
            'encounter_id',
            'observation_id'
        );
    }

    public function invasiveDevices(): HasMany
    {
        return $this->hasMany(InvasiveDevice::class);
    }

    public function supportResults(): HasMany
    {
        return $this->hasMany(SupportResult::class);
    }

    public function abgResults(): HasMany
    {
        return $this->hasMany(AbgResult::class);
    }

    /**
     * Asuhan keperawatan terakhir, dipakai untuk badge "Data Sudah Diisi".
     * Memakai flag `is_latest` lebih dulu, lalu jatuh ke recorded_at terbaru
     * bila flag belum sempat diisi.
     */
    public function latestNursingCare(): HasOne
    {
        return $this->hasOne(NursingCare::class)->latestOfMany('recorded_at');
    }

    public function latestObservation(): HasOne
    {
        return $this->hasOne(Observation::class)->latestOfMany('recorded_at');
    }

    /*
    |--------------------------------------------------------------------------
    | Scope
    |--------------------------------------------------------------------------
    */

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', EncounterStatus::AKTIF->value);
    }

    public function scopeForUnit(Builder $query, ?string $unit): Builder
    {
        return $unit === null || trim($unit) === ''
            ? $query
            : $query->where('unit', trim($unit));
    }

    public function scopeForPatient(Builder $query, Patient|int $patient): Builder
    {
        return $query->where(
            'patient_id',
            $patient instanceof Patient ? $patient->getKey() : $patient
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Completion - pengganti fungsi getCompletion() pada phase1
    |--------------------------------------------------------------------------
    |
    | Keempatnya bernilai true bila minimal satu record terkait ADA, persis seperti
    | computeCompletion() di app-context.js. Sengaja tidak menyimpan statusnya
    | di kolom, karena sumber kebenaran yang benar adalah keberadaan baris.
    |
        | CATATAN: asmed dicek lewat relasi hasOne (bukan exists()) supaya ketika
    | relasi sudah di-eager load, accessor ini tidak memicu query tambahan.
    |
    */

    public function getCompletionAttribute(): array
    {
        return [
            'asmed' => $this->relationLoaded('asmed')
                ? $this->getRelation('asmed') !== null
                : $this->asmed()->exists(),

            'nursingCare' => $this->relationLoaded('nursingCares')
                ? $this->getRelation('nursingCares')->isNotEmpty()
                : $this->nursingCares()->exists(),

            'diagnosis' => $this->relationLoaded('diagnoses')
                ? $this->getRelation('diagnoses')->isNotEmpty()
                : $this->diagnoses()->exists(),

            'procedure' => $this->relationLoaded('procedures')
                ? $this->getRelation('procedures')->isNotEmpty()
                : $this->procedures()->exists(),
        ];
    }

    /**
     * Daftar diagnosis utama, untuk banner ringkasan.
     */
    public function primaryDiagnosis(): ?Diagnosis
    {
        return $this->diagnoses
            ->first(fn (Diagnosis $diagnosis) => $diagnosis->type === DiagnosisType::UTAMA);
    }

    /*
    |--------------------------------------------------------------------------
    | Accessor pendukung banner
    |--------------------------------------------------------------------------
    */

    /**
     * "ICU / Bed 02" untuk banner.
     */
    public function getUnitBedAttribute(): string
    {
        return trim($this->unit.' / '.$this->bed);
    }

    /**
     * Hari rawat ke-berapa, dihitung dari tanggal masuk sampai hari ini.
     * phase1 memakai pembulatan ke bawah jumlah hari + 1 dengan minimum 1.
     */
    public function getLengthOfStayDaysAttribute(): int
    {
        if (! $this->admitted_at instanceof Carbon) {
            return 0;
        }

        $start = $this->admitted_at->copy()->startOfDay();
        $end = ($this->discharged_at instanceof Carbon ? $this->discharged_at : now())->copy()->startOfDay();

        return max(1, (int) $start->diffInDays($end) + 1);
    }
}

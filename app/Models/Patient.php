<?php

namespace App\Models;

use App\Enums\EncounterStatus;
use App\Enums\PaymentType;
use App\Enums\Sex;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Master data pasien.
 *
 * Accessor `age` dan `initials` sengaja ditambahkan karena keduanya dibaca
 * oleh banner identitas pasien di setiap halaman. Keduanya dihitung, bukan
 * disimpan, supaya tidak pernah basi.
 */
class Patient extends Model
{
    use HasFactory;

    protected $fillable = [
        'patient_id',
        'mrn',
        'name',
        'sex',
        'birth_date',
        'address',
        'phone',
        'blood_type',
        'allergies',
        'payment',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'sex' => Sex::class,
            'payment' => PaymentType::class,
            'birth_date' => 'date',
            'is_active' => 'boolean',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Relasi
    |--------------------------------------------------------------------------
    */

    public function encounters(): HasMany
    {
        return $this->hasMany(Encounter::class);
    }

    /**
     * Episode yang masih berjalan, paling baru masuk lebih dulu.
     */
    public function activeEncounters(): HasMany
    {
        return $this->hasMany(Encounter::class)
            ->where('status', EncounterStatus::AKTIF->value)
            ->latest('admitted_at');
    }

    public function latestEncounter(): ?Encounter
    {
        return $this->encounters()->latest('admitted_at')->first();
    }

    /*
    |--------------------------------------------------------------------------
    | Scope
    |--------------------------------------------------------------------------
    */

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if ($term === null || trim($term) === '') {
            return $query;
        }

        $like = '%'.trim($term).'%';

        return $query->where(function (Builder $q) use ($like) {
            $q->where('name', 'like', $like)
                ->orWhere('mrn', 'like', $like)
                ->orWhere('patient_id', 'like', $like);
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Accessor untuk banner identitas pasien
    |--------------------------------------------------------------------------
    */

    /**
     * Umur dalam tahun, dihitung dari birth_date pada tanggal hari ini.
     * Logikanya sama dengan phase1 ageFromBirthDate(): tahun berjalan dikurangi
     * tahun lahir, lalu dikurangi satu bila bulan atau tanggal lahir belum
     * tercapai pada tahun ini.
     */
    public function getAgeAttribute(): ?int
    {
        if (! $this->birth_date instanceof Carbon) {
            return null;
        }

        $now = now();
        $age = $now->year - $this->birth_date->year;

        if ($now->month < $this->birth_date->month) {
            $age -= 1;
        } elseif ($now->month === $this->birth_date->month && $now->day < $this->birth_date->day) {
            $age -= 1;
        }

        return max(0, $age);
    }

    /**
     * Inisial untuk avatar, sama seperti phase1 initialsOf(): huruf pertama
     * kata pertama ditambah huruf pertama kata kedua, atau dua huruf awal
     * bila nama hanya satu kata.
     */
    public function getInitialsAttribute(): string
    {
        $words = preg_split('/\s+/', trim((string) $this->name), -1, PREG_SPLIT_NO_EMPTY);

        if (! $words) {
            return '??';
        }

        if (count($words) === 1) {
            return strtoupper(mb_substr($words[0], 0, 2));
        }

        return strtoupper(mb_substr($words[0], 0, 1).mb_substr($words[1], 0, 1));
    }

    /**
     * Baris demografi untuk banner, mis. "Laki-Laki · 70 tahun".
     */
    public function getDemographicsAttribute(): string
    {
        $sex = $this->sex?->label() ?? '-';
        $age = $this->age;

        return $age === null ? $sex : $sex.' · '.$age.' tahun';
    }

    /**
     * phase1 memakai string "Tidak ada" sebagai nilai alergi negatif, bukan
     * null. Panel keamanan obat pada farmasi.html memakai ini untuk
     * membedakan "tidak ada alergi" dari "belum ditanyakan".
     */
    public function hasAllergies(): bool
    {
        $value = trim((string) $this->allergies);

        return $value !== ''
            && ! in_array(mb_strtolower($value), ['tidak ada', 'n/a', 'tdk ada', 'unknown'], true);
    }
}

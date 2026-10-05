<?php

namespace App\Models;

use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'username',
        'name',
        'email',
        'password',
        'role',
        'specialty',
        'phone',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Otorisasi
    |--------------------------------------------------------------------------
    |
    | Sengaja sederhana: satu kolom `role` dan helper hasRole(). Tidak memakai
    | spatie/laravel-permission karena kebutuhan aplikasi ini hanya tiga peran
    | dan tidak ada permission per granular.
    |
    */

    /**
     * True bila pengguna punya salah satu dari peran yang diminta.
     * Pemanggilan tanpa argumen selalu mengembalikan false.
     */
    public function hasRole(UserRole ...$roles): bool
    {
        if ($roles === []) {
            return false;
        }

        return $this->role !== null && in_array($this->role, $roles, true);
    }

    /**
     * True bila pengguna bukan salah satu peran yang diminta.
     */
    public function lacksRole(UserRole ...$roles): bool
    {
        return ! $this->hasRole(...$roles);
    }

    public function isDoctor(): bool
    {
        return $this->hasRole(UserRole::DOKTER);
    }

    /**
     * Perawat atau bidan, yaitu petugas yang mengisi observasi dan asuhan.
     */
    public function isNurse(): bool
    {
        return $this->hasRole(UserRole::PERAWAT, UserRole::BIDAN);
    }

    /*
    |--------------------------------------------------------------------------
    | Scope
    |--------------------------------------------------------------------------
    */

    public function scopeWithRole(Builder $query, UserRole|array $roles): Builder
    {
        $roles = is_array($roles) ? $roles : [$roles];

        return $query->whereIn('role', array_map(
            fn (UserRole $role) => $role->value,
            $roles
        ));
    }
}

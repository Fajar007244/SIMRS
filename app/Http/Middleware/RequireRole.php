<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use App\Models\User;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Membatasi akses ke salah satu dari tiga peran yang ada.
 *
 * Contoh pemakaian:
 *
 *   Route::get('/observasi', ...)->middleware('role:perawat,bidan,dokter');
 *   Route::get('/asmed',    ...)->middleware('role:dokter');
 *
 * Belum dipasang di routes/web.php; wave berikutnya yang mendaftarkan rute
 * SPA. Matriks lengkap: docs/AUTHORIZATION.md
 */
class RequireRole
{
    /**
     * @param  string  ...$roles  nilai enum UserRole, mis. 'dokter' atau 'perawat,bidan'
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            // Biarkan middleware `auth` bawaan yang mengarahkan tamu ke login
            // (bootstrap/app.php mendaftarkan redirectGuestsTo).
            throw new AuthenticationException;
        }

        $allowed = $this->resolveRoles($roles);

        if ($allowed === [] || ! $user->hasRole(...$allowed)) {
            abort(403, 'Anda tidak memiliki akses ke modul ini.');
        }

        return $next($request);
    }

    /**
     * @param  array<int, string>  $roles
     * @return array<int, UserRole>
     */
    protected function resolveRoles(array $roles): array
    {
        $values = Str::of(implode(',', $roles))
            ->explode(',')
            ->map(fn (string $role) => trim($role))
            ->filter()
            ->all();

        return array_values(array_filter(
            array_map(fn (string $role) => UserRole::tryFrom($role), $values),
            fn (?UserRole $role) => $role !== null,
        ));
    }
}

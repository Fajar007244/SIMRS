<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Autentikasi berbasis session, tanpa paket tambahan (tanpa Breeze/Jetstream/
 * spatie-permission).
 *
 * Dipetakan dari phase1/auth.js (objek window.EwsAuth):
 * - login()         -> store()
 * - logout()        -> destroy()
 * - getSession()    -> auth()->user() yang dibagikan ke Inertia sebagai
 *                      `auth.user` oleh HandleInertiaRequests::share()
 * - requireAuth()   -> middleware `auth` + redirectGuestsTo(route('login'))
 * - ACCOUNTS        -> UserSeeder, tiga akun dengan kredensial yang sama
 */
class LoginController extends Controller
{
    /**
     * Halaman login. Dirender server-side sebagai Blade, bukan bagian dari SPA.
     *
     * Parameter `next` adalah path tujuan setelah login berhasil. Nilainya
     * dibersihkan lebih dulu karena berasal dari query string.
     */
    public function create(Request $request): View
    {
        return view('auth.login', [
            'next' => $this->safeNext($request->input('next')),
        ]);
    }

    /**
     * Proses login.
     *
     * @throws ValidationException bila username/password tidak cocok.
     */
    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
            'remember' => ['nullable', 'boolean'],
        ], [
            'username.required' => 'Username wajib diisi.',
            'password.required' => 'Password wajib diisi.',
        ]);

        $remember = $request->boolean('remember');

        $user = User::query()->where('username', $credentials['username'])->first();

        $attempted = $user !== null && Auth::attempt([
            'username' => $credentials['username'],
            'password' => $credentials['password'],
        ], $remember);

        if (! $attempted) {
            // Satu pesan yang sama untuk username tidak dikenal maupun password
            // salah, supaya tidak bisa dipakai memetakan username yang ada.
            // Input lama (kecuali password) otomatis di-flash oleh Laravel.
            throw ValidationException::withMessages([
                'username' => 'Username atau password salah.',
            ]);
        }

        $request->session()->regenerate();

        return redirect()
            ->intended($this->safeNext($request->input('next')) ?: route('pasien'))
            ->with('success', 'Selamat datang, '.$user->name.'.');
    }

    /**
     * Proses logout.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()
            ->route('login')
            ->with('success', 'Anda telah keluar dari aplikasi.');
    }

    /**
     * Alias untuk pemanggil yang mengharapkan nama showLoginForm().
     */
    public function showLoginForm(Request $request): View
    {
        return $this->create($request);
    }

    /**
     * Terima hanya path internal sebagai tujuan redirect.
     *
     * Di phase1/auth.js nilai `next` dibaca dari location.search dan sudah
     * dicegov terhadap `://` dan `//`. Di sini perkuatannya: hasil wajib
     * diawali satu garis miring dan tidak boleh mengandung skema.
     */
    protected function safeNext(?string $next): ?string
    {
        if (! is_string($next) || trim($next) === '') {
            return null;
        }

        $next = trim($next);

        if (Str::contains($next, '://') || Str::startsWith($next, ['//', '\\']) || ! Str::startsWith($next, '/')) {
            return null;
        }

        return $next;
    }
}

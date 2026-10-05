<?php

namespace App\Http\Middleware;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\Str;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     *
     * Hash manifest Vite dipakai supaya setiap `npm run build` otomatis
     * membusting cache aset di browser tanpa menaikkan nomor versi manual.
     * Dikembalikan null ketika manifest belum ada (mis. saat `npm run dev`
     * masih berjalan) supaya Inertia tidak memaksa reload.
     */
    public function version(Request $request): ?string
    {
        if (config('app.asset_url')) {
            return hash('xxh128', config('app.asset_url'));
        }

        if (! file_exists(public_path('build/manifest.json'))) {
            return null;
        }

        return Vite::manifestHash();
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),

            'auth' => [
                'user' => fn () => $this->user($request->user()),
            ],
            'appName' => config('app.name'),

            /*
             * Token CSRF untuk <form method="post"> biasa (mis. tombol
             * "Keluar" di ClinicalLayout). Halaman Inertia tidak pernah
             * merender @csrf seperti Blade, sedangkan token SESSION ikut
             * di-regenerate setiap kali session()->regenerate() dipanggil -
             * termasuk pada POST /login. Tanpa prop ini, logout dari
             * ClinicalLayout selalu berakhir 419 TokenMismatch.
             */
            'csrfToken' => fn () => $request->session()->token(),

            /*
             * Dipakai top nav. Tab modul klinik sengaja tidak diletakkan di
             * sini karena komponen layout klinik dimiliki developer lain.
             */
            'nav' => [
                'backUrl' => '/pasien',
                'backLabel' => 'Daftar Pasien',
            ],

            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
        ];
    }

    /**
     * Bentuk data pengguna untuk consumption di sisi klien.
     *
     * `initials` menggantikan header phase1/auth.js yang hanya menampilkan
     * username + label peran.
     *
     * @return array<string, mixed>|null
     */
    protected function user(?User $user): ?array
    {
        if (! $user) {
            return null;
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
            'username' => $user->username,
            'specialty' => $user->specialty,
            'role' => $user->role?->value,
            'roleLabel' => $user->role?->label(),
            'initials' => $this->initials($user->name),
        ];
    }

    /**
     * Inisial dua huruf dari nama petugas, mis. "Ns. Tri Handayani" => "TH".
     */
    protected function initials(?string $name): string
    {
        if (! $name) {
            return '?';
        }

        $words = Str::of($name)
            ->explode(' ')
            ->map(fn (string $word) => trim($word))
            ->filter()
            ->all();

        if ($words === []) {
            return '?';
        }

        $letters = array_map(fn (string $word) => Str::substr($word, 0, 1), $words);

        return strtoupper(count($letters) === 1
            ? $letters[0]
            : $letters[0].end($letters));
    }
}

<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RequireRole;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            HandleInertiaRequests::class,
        ]);

        // Halaman login adalah Blade, bukan SPA. Semua tamu yang belum login
        // (apa pun rute yang dicoba) diarahkan ke sana. Rute intended
        // disimpan di session oleh middleware `auth` bawaan Laravel.
        $middleware->redirectGuestsTo(fn () => route('login'));

        // Roti untuk modul klinis, dipakai pada wave berikutnya. Alias sengaja
        // dibuat sekarang supaya rute cukup menulis ->middleware('role:...')
        // tanpa registrasi tambahan. Matriks: docs/AUTHORIZATION.md
        $middleware->alias([
            'role' => RequireRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Pesan berbahasa Indonesia untuk 403 dan 404. Permintaan Inertia juga
        // menerima halaman Blade yang sama: klien Inertia melakukan hard reload
        // saat menerima respons non-Inertia dengan status 4xx/5xx, sehingga tidak
        // diperlukan komponen Vue error terpisah.
        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            $status = $e->getStatusCode();

            if (! in_array($status, [403, 404], true)) {
                return null;
            }

            $message = $e->getMessage() ?: match ($status) {
                403 => 'Anda tidak memiliki akses ke modul ini.',
                default => 'Halaman yang Anda cari tidak ditemukan.',
            };

            if ($request->expectsJson()) {
                return response()->json(['message' => $message], $status);
            }

            return response()->view("errors.{$status}", [
                'status' => $status,
                'message' => $message,
            ], $status);
        });
    })->create();

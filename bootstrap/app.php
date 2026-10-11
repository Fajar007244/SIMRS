<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RequireRole;
use App\Support\DatabaseErrorReport;
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
        // --- Percayai proxy di depan aplikasi -----------------------------
        // Semua jalur deploy (Vercel, Railway, Render, nginx) menyajikan
        // HTTPS di ujung depan lalu meneruskan HTTP ke PHP di belakang. Header
        // `X-Forwarded-Proto: https` yang dikirim proxy itu diabaikan Laravel
        // bila proxy tidak dipercaya, sehingga Request::isSecure() false.
        //
        // Akibatnya route() membangun URL dengan skema http://, termasuk
        // `action` pada form login dan logout:
        //     <form action="http://domain.simu/login" ...>
        // Browser membacanya sebagai submit lintas skema dan menampilkan
        // "The information you're about to submit is not secure". Form logout
        // di topnav.blade.php punya masalah yang sama.
        //
        // '*' di sini aman: Laravel hanya percaya REMOTE_ADDR (proxy yang
        // langsung menghubungi kita), bukan seluruh rantai X-Forwarded-For.
        // Artinya klien yang memalsukan header tidak bisa memengaruhi
        // skema/host yang dipakai aplikasi.
        $middleware->trustProxies(at: '*');

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

        // --- Diagnosa error database ke tabel `error_reports` ---------------
        // SEMENTARA. Hapus blok ini beserta migration
        // 2026_10_11_000001 setelah penyebab 25P02 ditemukan.
        //
        // Kenapa perlu: di Vercel, satu statement yang gagal membatalkan
        // SELURUH transaksi PostgreSQL, sehingga statement berikutnya
        // melaporkan 25P02 "current transaction is aborted" - menunjuk ke
        // statement yang innocent, bukan ke penyebabnya. Halaman error
        // Laravel tidak menampilkan rantai getPrevious(), dan LOG_CHANNEL
        // di produksi adalah stderr (masuk Function Logs yang tidak selalu
        // bisa diambil ulang). Satu-satunya tempat yang bertahan di Vercel
        // Hobby adalah database, jadi detailnya ditulis ke sana.
        // reportable() hanya menerima exception-nya. Request diambil dari
        // container supaya kelas ini tidak ikut jadi parameter callback.
        $exceptions->reportable(function (Throwable $e): void {
            $request = app('request');

            try {
                DatabaseErrorReport::record($e, $request);
            } catch (Throwable) {
                // Alat diagnosa tidak boleh menjatuhkan aplikasi.
            }
        });
    })->create();

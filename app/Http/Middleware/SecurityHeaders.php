<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Menambahkan header keamanan pada setiap respons.
 *
 * HSTS sudah ditangani Vercel di edge dan tidak diulang di sini.
 *
 * CATATAN CSP: script-src menyertakan 'unsafe-inline' dengan sadar. Halaman
 * Blade (/pasien, /login) memakai inline <script> vanilla untuk progressive
 * enhancement, dan memaksakan nonce sekarang berarti menulis ulang semua
 * view + @stack('scripts'). 'unsafe-inline' pada script tetap memblokir
 * injeksi dari origin asing (yang diblokir default-src/script-src), hanya
 * saja tidak memblokir inline milik sendiri. Pengerasan ke nonce adalah
 * pekerjaan lanjutan, bukan bagian patch ini.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Buang signature PHP bila ada. Header ini tidak dipakai browser dan
        // hanya membantu mempersempit versi untuk hunting CVE. (Bila header
        // dikirim level PHP expose_php, penghapusan penuh butuh konfigurasi
        // runtime; baris ini menangani yang level aplikasi.)
        $response->headers->remove('X-Powered-By');

        $response->headers->set('X-Content-Type-Options', 'nosniff');

        // DENY, bukan SAMEORIGIN: aplikasi tidak pernah perlu di-embed frame.
        $response->headers->set('X-Frame-Options', 'DENY');

        // Jangan bocorkan path/query berisi ID pasien ke origin lain.
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        $response->headers->set(
            'Permissions-Policy',
            'camera=(), microphone=(), geolocation=(), payment=(), usb=()'
        );

        $response->headers->set('Content-Security-Policy', $this->contentSecurityPolicy());

        return $response;
    }

    protected function contentSecurityPolicy(): string
    {
        $directives = [
            "default-src 'self'",

            // 'unsafe-inline' disengaja untuk saat ini, lihat catatan kelas.
            "script-src 'self' 'unsafe-inline'",

            // Fonts + ikon dimuat dari CDN pihak pertama yang eksplisit.
            // JANGAN ganti dengan wildcard.
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdnjs.cloudflare.com",
            "font-src 'self' data: https://fonts.gstatic.com https://cdnjs.cloudflare.com",

            "img-src 'self' data: blob:",
            "connect-src 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",

            // Redundan sengaja dengan X-Frame-Options untuk browser lama.
            "frame-ancestors 'none'",
        ];

        return implode('; ', $directives);
    }
}

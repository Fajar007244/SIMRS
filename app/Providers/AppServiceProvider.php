<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureRateLimiting();
    }

    /**
     * Batas percobaan login: 5 kali per menit per kombinasi username + IP.
     *
     * phase1/auth.js tidak punya throttle sama sekali karena auth-nya berjalan
     * sepenuhnya di browser. Di server, endpoint POST /login harus dibatasi
     * agar tidak bisa ditebak-tebak.
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('login', function (Request $request) {
            $key = 'login|'.Str::lower((string) $request->input('username')).'|'.$request->ip();

            return Limit::perMinute(5)->by($key)->response(function (Request $request, array $headers) {
                return redirect()
                    ->route('login')
                    ->withInput($request->only('username', 'remember'))
                    ->with('error', 'Terlalu banyak percobaan masuk. Silakan coba lagi dalam satu menit.')
                    ->withHeaders($headers)
                    ->setStatusCode(429);
            });
        });
    }
}

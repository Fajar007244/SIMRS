<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Kesalahan') | {{ config('app.name', 'SIMRS RSP Rotinsulu') }}</title>
    <link href="https://fonts.googleapis.com" rel="preconnect">
    <link crossorigin href="https://fonts.gstatic.com" rel="preconnect">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&amp;family=JetBrains+Mono:wght@500;700&amp;display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    @vite(['resources/css/app.css'])
</head>
<body class="bg-slate-100 text-slate-800 font-sans antialiased">
    <main class="flex min-h-screen items-center justify-center px-4 py-10">
        <section class="w-full max-w-md overflow-hidden rounded-2xl bg-white shadow-2xl ring-1 ring-slate-900/5">
            <div class="bg-gradient-to-r from-sky-950 via-slate-900 to-slate-800 px-6 py-7 text-center text-white">
                <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-sky-600 text-2xl text-white shadow-inner">
                    <i class="fa-solid @yield('icon', 'fa-triangle-exclamation')"></i>
                </div>
                <h1 class="mt-4 text-xl font-black tracking-tight">@yield('code', '500')</h1>
                <p class="mt-1 text-xs text-slate-400">@yield('subtitle', 'Terjadi kesalahan')</p>
            </div>
            <div class="px-6 py-6 text-center">
                <p class="text-sm text-slate-600">@yield('message', 'Terjadi kesalahan yang tidak terduga.')</p>
                <a href="{{ url('/') }}"
                    class="mt-5 inline-flex items-center gap-2 rounded-lg bg-emerald-600 px-4 py-2.5 text-sm font-bold text-white shadow transition hover:bg-emerald-700 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2">
                    <i class="fa-solid fa-arrow-left"></i>
                    <span>Kembali</span>
                </a>
            </div>
        </section>
    </main>
</body>
</html>
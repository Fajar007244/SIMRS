{{--
    Layout HTML lengkap untuk halaman Blade server-rendered (bukan SPA).

    Dipakai resources/views/pasien/index.blade.php. Halaman clinical modules
    (profil / cppt / penunjang / farmasi / observasi / bundles) memakai
    Inertia lewat resources/views/app.blade.php, jadi layout ini sengaja tidak
    memakai @inertia.

    Yang ditambahkan dibanding tulis langsung di index.blade.php adalah
    @stack('scripts') di akhir body: seluruh skrip vanilla halaman dikirim lewat
    push supaya markup halaman tetap bisa dibaca terpisah dari logikanya.
    Halaman auth memakai layouts.guest.blade.php (yang juga punya stack
    'title' dengan pola sama).
--}}
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>@stack('title'){{ config('app.name', 'SIMRS RSP Rotinsulu') }}</title>

    <link href="https://fonts.googleapis.com" rel="preconnect">
    <link crossorigin href="https://fonts.gstatic.com" rel="preconnect">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&amp;family=JetBrains+Mono:wght@500;700&amp;display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('head')
</head>
<body class="bg-slate-100 text-slate-800 font-sans antialiased selection:bg-sky-500 selection:text-white">
@yield('body')
@stack('scripts')
</body>
</html>
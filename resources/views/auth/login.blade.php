@extends('layouts.guest')

@push('title', 'Login Petugas | SIMRS RSP Rotinsulu')

@section('body')
<div id="authCheckOverlay"
    class="pointer-events-none fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/70 px-4 backdrop-blur-sm">
    <div class="flex flex-col items-center gap-3 rounded-2xl bg-white px-8 py-6 text-center shadow-2xl ring-1 ring-slate-900/5">
        <i class="fa-solid fa-circle-notch fa-spin text-3xl text-sky-600"></i>
        <p class="text-xs font-bold uppercase tracking-wide text-slate-600">Memverifikasi identitas&hellip;</p>
    </div>
</div>

<main class="flex min-h-screen items-center justify-center bg-gradient-to-br from-slate-900 via-slate-800 to-slate-900 px-4 py-10">

    <div class="w-full max-w-md">
        <section class="overflow-hidden rounded-2xl bg-white shadow-2xl ring-1 ring-slate-900/5">
            <div class="bg-gradient-to-r from-sky-950 via-slate-900 to-slate-800 px-6 py-7 text-center text-white">
                <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-sky-600 text-2xl text-white shadow-inner">
                    <i class="fa-solid fa-heart-pulse"></i>
                </div>
                <h1 class="mt-4 text-xl font-black tracking-tight">RSP Dr. H. A. Rotinsulu</h1>
                <p class="mt-1 text-xs text-slate-400">SIMRS EMR v4.8 &middot; Login Petugas</p>
            </div>

            <form id="loginForm" action="{{ route('login.store') }}" method="POST" class="space-y-4 px-6 py-6">
                @csrf

                @if (session('error'))
                    <div class="flex items-start gap-2 rounded-lg border border-red-300 bg-red-50 px-3 py-2.5 text-xs font-semibold text-red-700"
                        role="alert">
                        <i class="fa-solid fa-circle-exclamation mt-0.5"></i>
                        <span class="flex-1">{{ session('error') }}</span>
                        <button type="button" data-dismiss-alert aria-label="Tutup pesan"
                            class="shrink-0 text-red-400 transition hover:text-red-700">
                            <i class="fa-solid fa-xmark"></i>
                        </button>
                    </div>
                @endif

                <div>
                    <label for="username" class="block text-xs font-bold uppercase tracking-wide text-slate-600">Username</label>
                    <div class="relative mt-1.5">
                        <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-slate-400"><i class="fa-solid fa-user"></i></span>
                        <input id="username" name="username" type="text" autocomplete="username" autofocus required
                            value="{{ old('username') }}"
                            @error('username') aria-invalid="true" aria-describedby="usernameError" @enderror
                            class="block w-full rounded-lg border-slate-300 pl-9 text-sm shadow-sm focus:border-sky-500 focus:ring-sky-500"
                            placeholder="masukkan username">
                    </div>
                    @error('username')
                        <p id="usernameError" class="mt-1.5 flex items-center gap-1.5 text-xs font-semibold text-red-600">
                            <i class="fa-solid fa-circle-exclamation"></i> {{ $message }}
                        </p>
                    @enderror
                </div>

                <div>
                    <label for="password" class="block text-xs font-bold uppercase tracking-wide text-slate-600">Password</label>
                    <div class="relative mt-1.5">
                        <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-slate-400"><i class="fa-solid fa-lock"></i></span>
                        <input id="password" name="password" type="password" autocomplete="current-password" required
                            @error('password') aria-invalid="true" aria-describedby="passwordError" @enderror
                            class="block w-full rounded-lg border-slate-300 pl-9 pr-10 text-sm shadow-sm focus:border-sky-500 focus:ring-sky-500"
                            placeholder="masukkan password">
                        <button type="button" id="togglePassword" aria-label="Tampilkan password" aria-pressed="false"
                            class="absolute inset-y-0 right-0 hidden items-center px-3 text-slate-400 transition hover:text-slate-600"
                            data-requires-js>
                            <i class="fa-solid fa-eye" id="togglePasswordIcon"></i>
                        </button>
                    </div>
                    @error('password')
                        <p id="passwordError" class="mt-1.5 flex items-center gap-1.5 text-xs font-semibold text-red-600">
                            <i class="fa-solid fa-circle-exclamation"></i> {{ $message }}
                        </p>
                    @enderror
                </div>

                <div class="flex items-center justify-between gap-3">
                    <label for="remember"
                        class="inline-flex cursor-pointer select-none items-center gap-2 text-xs font-semibold text-slate-600">
                        <input id="remember" name="remember" type="checkbox" value="1" @checked(old('remember'))
                            class="h-4 w-4 rounded border-slate-300 text-sky-600 focus:ring-sky-600">
                        <span>Ingat saya di perangkat ini</span>
                    </label>
                </div>

                <input type="hidden" name="next" value="{{ old('next', request('next')) }}">

                <button type="submit" id="submitButton"
                    class="inline-flex w-full items-center justify-center gap-2 rounded-lg bg-emerald-600 px-4 py-2.5 text-sm font-bold text-white shadow transition hover:bg-emerald-700 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-70">
                    <i class="fa-solid fa-right-to-bracket" id="submitIcon"></i>
                    <span>Masuk</span>
                </button>
            </form>

            <div class="border-t border-slate-100 bg-slate-50 px-6 py-4">
                <p class="flex items-center gap-1.5 text-[11px] font-bold uppercase tracking-wide text-slate-500">
                    <i class="fa-solid fa-circle-info text-sky-600"></i> Akun demo pilot
                </p>
                <ul class="mt-2 space-y-1 text-[11px] text-slate-600">
                    <li class="flex items-center justify-between gap-2 rounded border border-slate-200 bg-white px-2.5 py-1.5">
                        <span><strong class="font-mono font-bold text-slate-800">perawat</strong> / perawat123</span>
                        <span class="text-slate-400">Perawat ICU</span>
                    </li>
                    <li class="flex items-center justify-between gap-2 rounded border border-slate-200 bg-white px-2.5 py-1.5">
                        <span><strong class="font-mono font-bold text-slate-800">bidan</strong> / bidan123</span>
                        <span class="text-slate-400">Bidan</span>
                    </li>
                    <li class="flex items-center justify-between gap-2 rounded border border-slate-200 bg-white px-2.5 py-1.5">
                        <span><strong class="font-mono font-bold text-slate-800">dokter</strong> / dokter123</span>
                        <span class="text-slate-400">DPJP</span>
                    </li>
                </ul>
                <p class="mt-2 text-[10px] leading-relaxed text-slate-400">
                    Kredensial demo milik pilot lokal. Jangan memakai akun yang sama di lingkungan produksi.
                </p>
            </div>
        </section>

        <p class="mt-4 text-center text-[11px] text-slate-500">
            <i class="fa-solid fa-shield-halved mr-1"></i>Pilot lokal &middot; data tersimpan di perangkat
        </p>
    </div>
</main>

<script>
    (function () {
        'use strict';

        var form = document.getElementById('loginForm');
        var passwordInput = document.getElementById('password');
        var toggleBtn = document.getElementById('togglePassword');
        var toggleIcon = document.getElementById('togglePasswordIcon');
        var submitBtn = document.getElementById('submitButton');
        var submitIcon = document.getElementById('submitIcon');
        var overlay = document.getElementById('authCheckOverlay');

        toggleBtn.classList.remove('hidden');
        toggleBtn.classList.add('flex');

        toggleBtn.addEventListener('click', function () {
            var showing = passwordInput.type === 'text';
            passwordInput.type = showing ? 'password' : 'text';
            toggleIcon.className = showing ? 'fa-solid fa-eye' : 'fa-solid fa-eye-slash';
            toggleBtn.setAttribute('aria-label', showing ? 'Tampilkan password' : 'Sembunyikan password');
            toggleBtn.setAttribute('aria-pressed', showing ? 'false' : 'true');
        });

        Array.prototype.forEach.call(document.querySelectorAll('[data-dismiss-alert]'), function (btn) {
            btn.addEventListener('click', function () {
                var alert = btn.closest('[role="alert"]');
                if (alert) alert.parentNode.removeChild(alert);
            });
        });

        form.addEventListener('submit', function () {
            overlay.classList.remove('hidden');
            overlay.classList.add('flex');
            submitBtn.disabled = true;
            submitIcon.className = 'fa-solid fa-circle-notch fa-spin';
        });
    })();
</script>
@endsection
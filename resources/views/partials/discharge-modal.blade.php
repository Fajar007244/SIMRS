{{--
    Modal "Selesaikan Episode" pada census /pasien.

    Hanya dirender untuk dokter (tombol pemicunya juga disembunyikan untuk
    peran lain), karena docs/AUTHORIZATION.md membatasi "Tutup encounter
    (discharge)" ke peran dokter. Penegakannya tetap ada di server
    (routes/pages.pasien.php -> role:dokter), jadi menyembunyikan markup di
    sini bukan satu-satunya penjaga.

    BUKAN TOGGLE, dan itu disengaja. Status episode mengunci seluruh data
    kliniknya, jadi penutupan memakai form yang memaksa petugas mengisi
    tanggal keluar, memilih nasib pasien, dan mengetik kata konfirmasi.

    Kata "SELESAI" divalidasi LAGI di server (DischargeController), bukan
    hanya di sini. Gate JavaScript di bawah hanyalah kenyamanan; tanpa
    penjaga server, form bisa dikirim kosong dan episode ikut tertutup.

    Form tetap POST sungguhan, jadi tanpa JavaScript pun isian sampai ke
    server (kecuali gate konfirmasi yang memang perlu JavaScript untuk
    mengaktifkan tombol).
--}}
@php
    $destinations = \App\Enums\DischargeDestination::cases();
    $defaultDate = now()->toDateString();
@endphp

<div id="dischargeModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/50 p-4"
     data-purpose="discharge-modal" role="dialog" aria-modal="true" aria-labelledby="dischargeModalTitle">
    <div class="w-full max-w-lg rounded-2xl bg-white shadow-xl" data-purpose="discharge-panel">
        <form method="POST" data-purpose="discharge-form">
            @csrf

            {{-- Diterjemahkan ke rute nyata oleh JavaScript saat modal dibuka,
                 supaya markup ini tetap utuh walau JS gagal dimuat. --}}
            <input type="hidden" name="encounter" value="">

            <header class="flex items-start justify-between gap-3 border-b border-slate-200 px-5 py-4">
                <div>
                    <h2 id="dischargeModalTitle" class="text-base font-bold text-slate-900">
                        <i class="fa-solid fa-door-open mr-1 text-slate-400" aria-hidden="true"></i>Selesaikan Episode
                    </h2>
                    <p class="mt-1 text-xs text-slate-500" data-purpose="discharge-patient-name"></p>
                </div>
                <button type="button" data-purpose="discharge-close"
                        class="rounded-lg p-1.5 text-slate-400 transition hover:bg-slate-100 hover:text-slate-600"
                        aria-label="Tutup">
                    <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                </button>
            </header>

            <div class="space-y-4 px-5 py-4">

                {{-- Peringatan: ini aksi yang mengunci data --}}
                <p class="flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-[11px] leading-relaxed text-amber-800">
                    <i class="fa-solid fa-triangle-exclamation mt-0.5 shrink-0" aria-hidden="true"></i>
                    <span>
                        Setelah episode ditutup, observasi, catatan obat, dan CPPT tidak bisa ditambah lagi.
                        Rekam medis tetap dapat dibaca.
                    </span>
                </p>

                {{-- Error validasi tetap muncul setelah submit gagal --}}
                @error('discharged_at')
                    <p class="text-xs font-semibold text-red-600">{{ $message }}</p>
                @enderror
                @error('destination')
                    <p class="text-xs font-semibold text-red-600">{{ $message }}</p>
                @enderror
                @error('confirmation')
                    <p class="text-xs font-semibold text-red-600">{{ $message }}</p>
                @enderror
                @error('encounter')
                    <p class="text-xs font-semibold text-red-600">{{ $message }}</p>
                @enderror

                <div class="field">
                    <label class="label" for="dischargeDate">Tanggal keluar</label>
                    <input id="dischargeDate" name="discharged_at" type="date"
                           value="{{ old('discharged_at', $defaultDate) }}"
                           max="{{ $defaultDate }}" required
                           class="input py-2 text-sm">
                    <p class="field-hint">Tanggal pasien keluar dari ruangan.</p>
                </div>

                <div class="field">
                    <label class="label" for="dischargeDestination">Kondisi pasien saat keluar</label>
                    <select id="dischargeDestination" name="destination" required class="select py-2 pr-8 text-sm">
                        <option value="">-- Pilih --</option>
                        @foreach ($destinations as $destination)
                            <option value="{{ $destination->value }}"
                                    @selected(old('destination') === $destination->value)>
                                {{ $destination->label() }}
                            </option>
                        @endforeach
                    </select>
                    <p class="field-hint">Dipakai untuk rekap pasien keluar per unit.</p>
                </div>

                <div class="field">
                    <label class="label" for="dischargeNote">Keterangan <span class="font-normal text-slate-400">(opsional)</span></label>
                    <textarea id="dischargeNote" name="note" rows="2"
                              placeholder="Contoh: kondisi membaik, kontrol 7 hari."
                              class="input py-2 text-sm">{{ old('note') }}</textarea>
                </div>

                <div class="field" data-purpose="discharge-confirm-field">
                    <label class="label" for="dischargeConfirmation">
                        Ketik <span class="mono font-bold text-red-600">SELESAI</span> untuk konfirmasi
                    </label>
                    <input id="dischargeConfirmation" name="confirmation" type="text"
                           autocomplete="off" spellcheck="false"
                           placeholder="SELESAI" class="input mono py-2 text-sm">
                    <p class="field-hint">Dipakai supaya episode tidak tertutup karena salah klik.</p>
                </div>
            </div>

            <footer class="flex justify-end gap-2 border-t border-slate-200 px-5 py-4">
                <button type="button" data-purpose="discharge-close"
                        class="btn btn-secondary py-2 text-sm">
                    Batal
                </button>
                <button type="submit" data-purpose="discharge-submit" disabled
                        class="btn btn-primary py-2 text-sm opacity-50">
                    <i class="fa-solid fa-door-open" aria-hidden="true"></i>Selesaikan Episode
                </button>
            </footer>
        </form>
    </div>
</div>

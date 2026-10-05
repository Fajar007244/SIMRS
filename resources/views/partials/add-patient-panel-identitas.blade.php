{{--
    Panel 1 "Identitas" dari modal Tambah Pasien.

    Port markup phase1/pasien.html baris 106-147. Label membungkus input (tanpa
    atribut for=, persis seperti prototype); setiap isian punya name supaya
    form-nya benar-benar POST, dan old() mengembalikan isian terakhir ketika
    validasi gagal.

    Kolom wajib: nama, No. RM, unit, bed, tanggal & jam masuk - sama dengan
    gate savePatient() di prototype.
--}}
<section data-panel="identitas" class="panel {{ $initialTab === 0 ? '' : 'hidden' }}">
    <div class="grid gap-4 sm:grid-cols-2">
        <label class="block text-xs font-bold text-slate-600">Nama lengkap <span class="text-red-500">*</span>
            <input id="fName" name="name" type="text" value="{{ old('name') }}" autocomplete="off"
                   @error('name') aria-invalid="true" @enderror
                   class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500 {{ $errors->has('name') ? 'input-error' : '' }}">
            @error('name')<span class="mt-1 block text-[11px] font-semibold normal-case text-red-600">{{ $message }}</span>@enderror
        </label>

        <label class="block text-xs font-bold text-slate-600">No. RM <span class="text-red-500">*</span>
            <input id="fMrn" name="mrn" type="text" value="{{ old('mrn') }}" autocomplete="off" inputmode="numeric"
                   @error('mrn') aria-invalid="true" @enderror
                   class="mt-1 w-full rounded-lg border-slate-300 font-mono text-sm focus:border-teal-500 focus:ring-teal-500 {{ $errors->has('mrn') ? 'input-error' : '' }}">
            @error('mrn')<span class="mt-1 block font-mono text-[11px] font-semibold normal-case text-red-600">{{ $message }}</span>@enderror
        </label>

        <label class="block text-xs font-bold text-slate-600">Jenis kelamin
            <select id="fSex" name="sex" class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                <option value="">Pilih</option>
                <option value="Laki-Laki" @selected(old('sex') === 'Laki-Laki')>Laki-Laki</option>
                <option value="Perempuan" @selected(old('sex') === 'Perempuan')>Perempuan</option>
            </select>
        </label>

        <label class="block text-xs font-bold text-slate-600">Tanggal lahir
            <input id="fBirthDate" name="birth_date" type="date" value="{{ old('birth_date') }}"
                   @error('birth_date') aria-invalid="true" @enderror
                   class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500 {{ $errors->has('birth_date') ? 'input-error' : '' }}">
            @error('birth_date')<span class="mt-1 block text-[11px] font-semibold normal-case text-red-600">{{ $message }}</span>@enderror
        </label>

        <label class="block text-xs font-bold text-slate-600">Alergi
            <input id="fAllergies" name="allergies" type="text" value="{{ old('allergies') }}" autocomplete="off"
                   class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500">
        </label>

        <label class="block text-xs font-bold text-slate-600">Pembiayaan
            <select id="fPayment" name="payment" class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                @foreach (\App\Enums\PaymentType::cases() as $payment)
                    <option value="{{ $payment->value }}" @selected(old('payment', $payment->value) === $payment->value)>{{ $payment->value }}</option>
                @endforeach
            </select>
        </label>

        <label class="block text-xs font-bold text-slate-600">Unit/Ruang <span class="text-red-500">*</span>
            <input id="fUnit" name="unit" type="text" value="{{ old('unit') }}" autocomplete="off" list="admissionUnitList"
                   @error('unit') aria-invalid="true" @enderror
                   class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500 {{ $errors->has('unit') ? 'input-error' : '' }}">
            @error('unit')<span class="mt-1 block text-[11px] font-semibold normal-case text-red-600">{{ $message }}</span>@enderror
        </label>

        <label class="block text-xs font-bold text-slate-600">Bed <span class="text-red-500">*</span>
            <input id="fBed" name="bed" type="text" value="{{ old('bed') }}" autocomplete="off"
                   @error('bed') aria-invalid="true" @enderror
                   class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500 {{ $errors->has('bed') ? 'input-error' : '' }}">
            @error('bed')<span class="mt-1 block text-[11px] font-semibold normal-case text-red-600">{{ $message }}</span>@enderror
        </label>

        <label class="block text-xs font-bold text-slate-600">Tanggal &amp; jam masuk <span class="text-red-500">*</span>
            <input id="fAdmittedAt" name="admitted_at" type="datetime-local" value="{{ old('admitted_at') }}"
                   @error('admitted_at') aria-invalid="true" @enderror
                   class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500 {{ $errors->has('admitted_at') ? 'input-error' : '' }}">
            @error('admitted_at')<span class="mt-1 block text-[11px] font-semibold normal-case text-red-600">{{ $message }}</span>@enderror
        </label>

        {{-- Golongan darah opsional, jadi tidak masuk gate savePatient(). Dipakai
             <select>, bukan input teks: salah ketik golongan darah adalah
             masalah keamanan transfusi, jadi daftar pilihan tertutup lebih aman
             daripada mengetik bebas. Slot di sebelah "Tanggal & jam masuk"
             sengaja dipakai - kolom itu sudah kosong, jadi tidak ada pasangan
             baris yang bergeser dan Unit/Ruang tetap berdampingan dengan Bed. --}}
        <label class="block text-xs font-bold text-slate-600">Golongan darah
            <select id="fBloodType" name="blood_type"
                    @error('blood_type') aria-invalid="true" @enderror
                    class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500 {{ $errors->has('blood_type') ? 'input-error' : '' }}">
                <option value="">Belum diketahui</option>
                @foreach (['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'] as $bloodType)
                    <option value="{{ $bloodType }}" @selected(old('blood_type') === $bloodType)>{{ $bloodType }}</option>
                @endforeach
            </select>
            @error('blood_type')<span class="mt-1 block text-[11px] font-semibold normal-case text-red-600">{{ $message }}</span>@enderror
        </label>

        <label class="block text-xs font-bold text-slate-600 sm:col-span-2">DPJP
            <input id="fAttending" name="attending_physician" type="text" value="{{ old('attending_physician') }}" autocomplete="off"
                   class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500">
        </label>

        {{-- Alamat opsional, jadi tidak masuk gate savePatient(); textarea
             karena isinya bebas dan sering panjang. --}}
        <label class="block text-xs font-bold text-slate-600 sm:col-span-2">Alamat
            <textarea id="fAddress" name="address" rows="2"
                      @error('address') aria-invalid="true" @enderror
                      class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500 {{ $errors->has('address') ? 'input-error' : '' }}">{{ old('address') }}</textarea>
            @error('address')<span class="mt-1 block text-[11px] font-semibold normal-case text-red-600">{{ $message }}</span>@enderror
        </label>
    </div>

    {{-- Saran unit dari census yang sudah ada, supaya tidak perlu mengetik ulang --}}
    <datalist id="admissionUnitList">
        @foreach ($units as $unit)
            <option value="{{ $unit }}"></option>
        @endforeach
    </datalist>
</section>
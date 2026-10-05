{{--
    Satu kartu pasien pada census /pasien.

    Bentuk baris ($p) adalah hasil AdmissionService::listPatients() - JANGAN
    menebak nama kuncinya, baca docs/FRONTEND_CONTRACT.md 6.2.

    Visual design mengikuti phase1/pasien.html (avatar bulat sky, nama
    uppercase, chip mono untuk No. RM, tombol "Buka" bg-sky-600), ditambah
    field yang memang dibutuhkan untuk triase harian: pembayaran, tanggal
    masuk, LOS, DPJP, ringkasan diagnosa, peringatan alergi, indikator
    kelengkapan admisi, dan badge EWS terakhir.

    Kartu ini adalah tautan ordinary <a href> (bukan router.visit), jadi klik
    melakukan full page load ke modul Inertia /encounters/{id}/profil.
--}}
@php
    $profileUrl = route('profil', ['encounter' => $p['encounterId']]);
    $completion = (array) $p['completion'];
    $completionLabels = [
        'asmed' => 'ASMED',
        'nursingCare' => 'Keperawatan',
        'diagnosis' => 'Diagnosis',
        'procedure' => 'Prosedur',
    ];
@endphp
<article class="flex flex-col rounded-xl border border-slate-200 bg-white p-4 shadow-sm print-border transition hover:border-sky-300 hover:shadow-md"
         data-purpose="patient-card"
         data-encounter="{{ $p['encounterId'] }}">

    {{-- Kepala: avatar, nama, demografi, pembayaran, badge EWS --}}
    <div class="flex items-start gap-3">
        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-sky-200 bg-sky-100 text-xs font-black text-sky-700">
            {{ $p['initials'] }}
        </span>
        <div class="min-w-0 flex-1">
            <div class="flex flex-wrap items-center gap-2">
                <h3 class="truncate text-sm font-bold uppercase tracking-tight text-slate-900" title="{{ $p['name'] }}">
                    {{ $p['name'] }}
                </h3>
                @include('partials.ews-badge', [
                    'risk' => $p['latestRisk'],
                    'total' => $p['latestEws'],
                    'label' => $p['latestRiskLabel'],
                    'size' => 'sm',
                ])
            </div>
            <p class="mt-0.5 text-xs text-slate-500">
                {{ $p['demographics'] }}
                @if ($p['paymentLabel'] && $p['paymentLabel'] !== '-')
                    <span class="ml-1 rounded border border-emerald-200 bg-emerald-50 px-1.5 py-0.5 text-[10px] font-bold text-emerald-700">
                        {{ $p['paymentLabel'] }}
                    </span>
                @endif
            </p>
        </div>
    </div>

    {{-- Ringkasan episode --}}
    <dl class="mt-3 grid grid-cols-2 gap-x-3 gap-y-2 border-t border-slate-100 pt-3 text-[11px] sm:grid-cols-3">
        <div>
            <dt class="text-[10px] font-bold uppercase tracking-wider text-slate-400">No. RM</dt>
            <dd class="mono mt-0.5 font-bold text-slate-800">{{ $p['mrn'] }}</dd>
        </div>
        <div>
            <dt class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Unit / Bed</dt>
            <dd class="mt-0.5 font-semibold text-slate-800" title="{{ $p['unitBed'] }}">{{ $p['unitBed'] }}</dd>
        </div>
        <div>
            <dt class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Masuk</dt>
            <dd class="mono mt-0.5 text-slate-700">{{ $p['admittedAtLabel'] }}</dd>
        </div>
        <div>
            <dt class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Lama Rawat</dt>
            <dd class="mt-0.5 text-slate-700">{{ $p['losDays'] }} hari</dd>
        </div>
        <div class="col-span-2">
            <dt class="text-[10px] font-bold uppercase tracking-wider text-slate-400">DPJP</dt>
            <dd class="mt-0.5 text-slate-700" title="{{ $p['dpjp'] }}">{{ $p['dpjp'] }}</dd>
        </div>
        <div>
            <dt class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Status</dt>
            <dd class="mt-0.5 text-slate-700">{{ $p['statusLabel'] }}</dd>
        </div>
    </dl>

    {{-- Ringkasan diagnosa --}}
    <div class="mt-3 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2">
        <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Diagnosis</p>
        <p class="mt-0.5 text-xs text-slate-700" title="{{ $p['diagnosisSummary'] }}">{{ $p['diagnosisSummary'] }}</p>
        @if (! empty($p['diagnosisCodes']))
            <p class="mono mt-1 text-[10px] text-slate-500">{{ implode(', ', $p['diagnosisCodes']) }}</p>
        @endif
    </div>

    {{-- Peringatan alergi (merah hanya bila ada alergi) --}}
    @if ($p['hasAllergies'] || $p['allergyAlert'])
        <p class="mt-2 flex items-start gap-1.5 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-[11px] font-bold text-red-700">
            <i class="fa-solid fa-triangle-exclamation mt-0.5 shrink-0" aria-hidden="true"></i>
            <span>Alergi: {{ $p['allergyAlert'] ?? $p['allergies'] }}</span>
        </p>
    @endif

    {{-- Indikator kelengkapan data admisi --}}
    <div class="mt-3 flex flex-wrap items-center gap-1.5">
        <span class="text-[10px] font-bold uppercase tracking-wider text-slate-400">
            Kelengkapan {{ $p['completionCount'] }}/4
        </span>
        @foreach ($completionLabels as $completionKey => $completionLabel)
            @php($isDone = (bool) ($completion[$completionKey] ?? false))
            <span class="inline-flex items-center gap-1 rounded border px-1.5 py-0.5 text-[10px] font-bold {{ $isDone ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-slate-200 bg-white text-slate-400' }}"
                  title="{{ $completionLabel }}: {{ $isDone ? 'sudah diisi' : 'belum diisi' }}">
                <i class="fa-solid {{ $isDone ? 'fa-circle-check' : 'fa-circle-xmark' }}" aria-hidden="true"></i>
                {{ $completionLabel }}
            </span>
        @endforeach
    </div>

    {{-- Footer: observasi terakhir + tautan ke modul --}}
    <div class="mt-auto flex flex-wrap items-center justify-between gap-2 border-t border-slate-100 pt-3 text-[11px]">
        <div class="min-w-0 text-slate-500">
            <span class="font-semibold text-slate-800">{{ $p['latestObsAtLabel'] }}</span>
            <span class="block truncate">
                oleh {{ $p['latestObsBy'] }} &middot; {{ $p['medicationCount'] }} pemberian obat
            </span>
        </div>
        <a href="{{ $profileUrl }}"
           class="no-print inline-flex items-center gap-1.5 rounded-lg bg-sky-600 px-3 py-2 text-xs font-bold text-white shadow-sm transition hover:bg-sky-700">
            <i class="fa-solid fa-folder-open" aria-hidden="true"></i>Buka
        </a>
    </div>
</article>

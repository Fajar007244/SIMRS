{{--
    Badge EWS 4 tingkat - padanan Blade dari resources/js/Components/EwsBadge.vue.

    Pemetaan kelas diambil dari App\Support\BadgeTone::badge() yang merupakan
    salinan verbatim tone.js (EWS_BADGE_CLASSES) dan
    EwsScoringService::badgeClasses(), termasuk fallback saat risk tidak
    dikenal:
      emergency -> bg-red-600    text-white    border-red-700
      high      -> bg-red-100    text-red-800  border-red-200
      medium    -> bg-amber-100  text-amber-800 border-amber-200
      low       -> bg-emerald-100 text-emerald-800 border-emerald-200
      lainnya   -> bg-slate-100  text-slate-700  border-slate-200

    Variabel:
      $risk   string  level risiko (low|medium|high|emergency|none|'')
      $total  int|null skor EWS; null tampil '-'
      $label  string|null label kustom; null -> BadgeTone::label($risk)
      $size   string  sm|md|lg (default md)
--}}
@php
    $ewsSize = $size ?? 'md';
    $ewsTotal = $total ?? null;
    $ewsTotalText = $ewsTotal === null ? '-' : (string) (int) $ewsTotal;
    $ewsLabel = trim((string) ($label ?? '')) !== '' ? (string) $label : \App\Support\BadgeTone::label((string) ($risk ?? ''));
    $ewsClasses = match ($ewsSize) {
        'sm' => 'gap-1 px-1.5 py-0.5 text-[10px]',
        'lg' => 'gap-1.5 px-2.5 py-1 text-sm',
        default => 'gap-1 px-2 py-0.5 text-[11px]',
    };
    $ewsScore = match ($ewsSize) {
        'sm' => 'text-[11px]',
        'lg' => 'text-base',
        default => 'text-xs',
    };
    $ewsTone = \App\Support\BadgeTone::badge((string) ($risk ?? ''));
@endphp
<span class="inline-flex items-center rounded border font-bold {{ $ewsClasses }} {{ $ewsTone['class'] }}"
      title="EWS {{ $ewsTotalText }} ({{ $ewsLabel }})"
      data-purpose="ews-badge">
    <span class="font-mono {{ $ewsScore }}">{{ $ewsTotalText }}</span>
    @if ($ewsLabel !== '-')
        <span>{{ $ewsLabel }}</span>
    @endif
    @isset($suffix)
        <span>{{ $suffix }}</span>
    @endisset
</span>

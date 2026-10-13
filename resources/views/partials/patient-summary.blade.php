{{--
    Ringkasan "Menampilkan N episode ..." di bawah filter /pasien.

    Diekstrak supaya pencarian AJAX bisa memperbaruinya tanpa menduplikasi
    kalimat di JavaScript: PasienController@index mengirim hasil render
    partial ini dalam respons JSON, dan client menukarnya ke
    [data-purpose="result-summary"].

    Variabel: $patients (LengthAwarePaginator).
--}}
<p class="field-hint" data-purpose="result-summary">
    Menampilkan {{ $patients->total() }} episode
    @if ($patients->total() > $patients->perPage())
        (halaman {{ $patients->currentPage() }} dari {{ $patients->lastPage() }})
    @endif
    &middot; diurutkan dari risiko EWS tertinggi.
</p>

<?php

namespace App\Http\Controllers;

use App\Enums\SupportGroup;
use App\Models\Encounter;
use App\Services\AdmissionService;
use App\Services\Support\ReferenceRange;
use App\Services\SupportService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Halaman "Penunjang & AGD" (nama route `penunjang`).
 *
 * Port 1:1 dari phase1/penunjang.html, termasuk jalur tulisnya:
 *   saveSupportResult() -> SupportService::saveSupportResult()  (storeResult)
 *   saveAbg()           -> SupportService::saveAbg()            (storeAbg)
 *   deleteSupportResult -> SupportService::deleteSupportResult() (destroyResult)
 *   deleteAbg           -> SupportService::deleteAbg()           (destroyAbg)
 *   reset bucket        -> relasi SupportResult / AbgResult      (reset)
 *
 * ATURAN KONTRAK PENTING
 *  - saveSupportResult() dan saveAbg() mengembalikan MODEL ELOQUENT, bukan
 *    array (lihat docblock SupportService). Model itu TIDAK PERNAH dikirim ke
 *    Inertia: setiap aksi menulis mengembalikan back() supaya halaman dimuat
 *    ulang dari server dengan props yang sudah segar (statistik, tabel, tren).
 *  - Group `abg` TIDAK boleh ditulis lewat storeResult(): AGD punya tabelnya
 *    sendiri (abg_results) dan bentuk datanya berbeda. Validasi menolaknya
 *    dengan pesan "Kelompok penunjang tidak dikenal." (SupportGroup::resultGroups()
 *    hanya berisi lab, blood, micro, rad).
 *  - `lab` dan `blood` WAJIB angka ("Nilai harus berupa angka."); `micro` dan
 *    `rad` menerima teks bebas ("Nilai wajib diisi."). Sama persis dengan
 *    pemabangan di SupportService::saveSupportResult().
 *
 * PROPS Inertia (resources/js/Pages/Penjunjang.vue):
 *   encounterId  string, banner array (12 kunci), data array (getAll),
 *   abg array, latestAbg array|null, summary array, pending array,
 *   abnormal array, trend array, activeGroup string, activeTab 'penunjang'
 *   + trendGroup, trendKey, trendRef, catalog, trendTargets (helper UI)
 */
class PenunjangController extends Controller
{
    /**
     * Enam tab modul penunjang, sama dengan phase1/penunjang.html.
     *
     * @var array<int, string>
     */
    private const TABS = ['lab', 'blood', 'abg', 'micro', 'rad', 'trend'];

    /**
     * Kelompok yang boleh dikosongkan oleh aksi reset. Sama dengan
     * SupportGroup::resultGroups() ditambah 'abg'.
     *
     * @var array<int, string>
     */
    private const RESETTABLE = ['lab', 'blood', 'abg', 'micro', 'rad'];

    /**
     * Kelompok hasil generik yang angka WAJIB, bukan teks bebas.
     *
     * @var array<int, string>
     */
    private const NUMERIC_GROUPS = ['lab', 'blood'];

    public function __construct(
        private readonly SupportService $support,
        private readonly AdmissionService $admissions,
    ) {}

    /**
     * GET /encounters/{encounter}/penunjang
     *
     * Query string:
     *   group      tab aktif, default 'lab' (satu dari self::TABS)
     *   trendGroup kelompok untuk grafik tren, default 'lab'
     *   trendKey   item tren, default null -> item bawaan kelompok itu
     *              (leukosit untuk lab, kreatinin untuk blood)
     */
    public function show(Request $request, Encounter $encounter): Response
    {
        abort_unless(
            $encounter->exists,
            404,
            'Episode perawatan tidak ditemukan. Periksa kembali kode encounter pada alamat halaman.'
        );

        $abg = $this->support->getAbgRecords($encounter);
        $trend = $this->support->getTrend(
            $encounter,
            $this->resultGroup($request->query('trendGroup', 'lab')),
            $this->trendKey($request->query('trendKey')),
        );

        return Inertia::render('Penunjang', [
            'encounterId' => (string) $encounter->encounter_id,
            'banner' => $this->admissions->getBannerPayload($encounter),
            'data' => $this->support->getAll($encounter),
            'abg' => $abg,
            // getLatestAbg() = getAbgRecords()[0], karena abgQuery() selalu
            // newest-first. Dipakai langsung supaya tidak ada kueri ganda.
            'latestAbg' => $abg[0] ?? null,
            'summary' => $this->support->getSummary($encounter),
            'pending' => $this->support->getPending($encounter),
            'abnormal' => $this->support->getAbnormal($encounter),
            'trend' => $trend,
            'trendGroup' => $this->resultGroup($request->query('trendGroup', 'lab')),
            'trendKey' => $trend['key'] !== '' ? $trend['key'] : null,
            'trendRef' => $this->trendRef($trend),
            'catalog' => $this->catalog(),
            'trendTargets' => ReferenceRange::trendTargets(),
            'activeGroup' => $this->tab($request->query('group', '')),
            'activeTab' => 'penunjang',
        ]);
    }

    /**
     * POST /encounters/{encounter}/penunjang/results
     * name: penunjang.result.store
     *
     * Satu endpoint untuk lab / blood / micro / rad. `group` divalidasi
     * terhadap SupportGroup::resultGroups() sehingga `abg` (dan nilai asing
     * lain) ditolak sebelum menyentuh service.
     */
    public function storeResult(Request $request, Encounter $encounter)
    {
        $group = trim((string) $request->input('group'));
        $numeric = in_array($group, self::NUMERIC_GROUPS, true);

        $messages = [
            'group.in' => 'Kelompok penunjang tidak dikenal.',
            'group.required' => 'Kelompok penunjang wajib dipilih.',
            'key.required' => 'Item wajib dipilih.',
            'value.required' => 'Nilai wajib diisi.',
            'value.numeric' => 'Nilai harus berupa angka.',
            'date.date_format' => 'Tanggal hasil harus berformat YYYY-MM-DD.',
            'time.date_format' => 'Jam hasil harus berformat HH:MM.',
            'by.max' => 'Nama petugas terlalu panjang (maksimal 150 karakter).',
        ];

        if (! $numeric) {
            $messages['value.required'] = 'Nilai wajib diisi.';
        }

        $data = $request->validate([
            'group' => ['required', 'string', Rule::in(SupportGroup::resultGroups())],
            'key' => ['required', 'string', 'max:120'],
            'value' => ['required', 'string', 'max:255', $numeric ? 'numeric' : 'string'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'time' => ['nullable', 'date_format:H:i'],
            'by' => ['nullable', 'string', 'max:150'],
        ], $messages);

        $payload = [
            'key' => $data['key'],
            'value' => $data['value'],
            'by' => $data['by'] ?? null,
            'resultedAt' => $this->measuredAt($data['date'] ?? null, $data['time'] ?? null),
        ];

        try {
            $this->support->saveSupportResult($encounter, $data['group'], $payload, $request->user());
        } catch (ValidationException $e) {
            return back()
                ->withErrors($e->errors)
                ->with('error', 'Hasil penunjang gagal disimpan. Periksa kembali isian Anda.');
        }

        return back()->with('success', 'Hasil '.SupportGroup::from($data['group'])->label().' tersimpan.');
    }

    /**
     * POST /encounters/{encounter}/penunjang/results/delete
     * name: penunjang.result.destroy
     *
     * Menghapus hasil TERBARU untuk satu kunci pada satu kelompok.
     */
    public function destroyResult(Request $request, Encounter $encounter)
    {
        $data = $request->validate([
            'group' => ['required', 'string', Rule::in(SupportGroup::resultGroups())],
            'key' => ['required', 'string', 'max:120'],
        ], [
            'group.in' => 'Kelompok penunjang tidak dikenal.',
            'key.required' => 'Item wajib dipilih.',
        ]);

        $label = ReferenceRange::meta($data['key'])['name'];
        $deleted = $this->support->deleteSupportResult($encounter, $data['group'], $data['key']);

        return $deleted
            ? back()->with('success', 'Hasil '.$label.' dihapus.')
            : back()->with('error', 'Tidak ada hasil '.$label.' yang bisa dihapus.');
    }

    /**
     * POST /encounters/{encounter}/penunjang/abg
     * name: penunjang.abg.store
     *
     * Batas kewajaran identik dengan SupportService::ABG_RANGES dan pesan
     * errornya juga ("pH di luar rentang wajar (5 - 9)."), sehingga kesalahan
     * ketik ketahuan sebagai error inline, bukan sebagai 500.
     */
    public function storeAbg(Request $request, Encounter $encounter)
    {
        $data = $request->validate([
            'ph' => ['required', 'numeric', 'min:5', 'max:9'],
            'pco2' => ['required', 'numeric', 'min:10', 'max:150'],
            'po2' => ['nullable', 'numeric', 'min:10', 'max:700'],
            'hco3' => ['nullable', 'numeric', 'min:3', 'max:60'],
            'be' => ['nullable', 'numeric', 'min:-35', 'max:35'],
            'sao2' => ['nullable', 'numeric', 'min:30', 'max:100'],
            'fio2' => ['nullable', 'numeric', 'min:21', 'max:100'],
            'method' => ['nullable', 'string', 'max:50'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'time' => ['nullable', 'date_format:H:i'],
            'by' => ['nullable', 'string', 'max:150'],
        ], [
            'ph.required' => 'pH wajib diisi.',
            'ph.numeric' => 'pH harus berupa angka.',
            'ph.min' => 'pH di luar rentang wajar (5 - 9).',
            'ph.max' => 'pH di luar rentang wajar (5 - 9).',
            'pco2.required' => 'PaCO2 wajib diisi.',
            'pco2.numeric' => 'PaCO2 harus berupa angka.',
            'pco2.min' => 'PaCO2 di luar rentang wajar (10 - 150).',
            'pco2.max' => 'PaCO2 di luar rentang wajar (10 - 150).',
            'po2.numeric' => 'PaO2 harus berupa angka.',
            'po2.min' => 'PaO2 di luar rentang wajar (10 - 700).',
            'po2.max' => 'PaO2 di luar rentang wajar (10 - 700).',
            'hco3.numeric' => 'HCO3 harus berupa angka.',
            'hco3.min' => 'HCO3 di luar rentang wajar (3 - 60).',
            'hco3.max' => 'HCO3 di luar rentang wajar (3 - 60).',
            'be.numeric' => 'BE harus berupa angka.',
            'be.min' => 'BE di luar rentang wajar (-35 - 35).',
            'be.max' => 'BE di luar rentang wajar (-35 - 35).',
            'sao2.numeric' => 'SaO2 harus berupa angka.',
            'sao2.min' => 'SaO2 di luar rentang wajar (30 - 100).',
            'sao2.max' => 'SaO2 di luar rentang wajar (30 - 100).',
            'fio2.numeric' => 'FiO2 harus berupa angka.',
            'fio2.min' => 'FiO2 di luar rentang wajar (21 - 100).',
            'fio2.max' => 'FiO2 di luar rentang wajar (21 - 100).',
            'method.max' => 'Metode pemeriksaan terlalu panjang (maksimal 50 karakter).',
            'date.date_format' => 'Tanggal pemeriksaan harus berformat YYYY-MM-DD.',
            'time.date_format' => 'Jam pemeriksaan harus berformat HH:MM.',
            'by.max' => 'Nama petugas terlalu panjang (maksimal 150 karakter).',
        ]);

        $payload = [
            'ph' => $data['ph'],
            'pco2' => $data['pco2'],
            'po2' => $data['po2'] ?? null,
            'hco3' => $data['hco3'] ?? null,
            'be' => $data['be'] ?? null,
            'sao2' => $data['sao2'] ?? null,
            'fio2' => $data['fio2'] ?? null,
            'method' => $data['method'] ?? null,
            'by' => $data['by'] ?? null,
            'measuredAt' => $this->measuredAt($data['date'] ?? null, $data['time'] ?? null),
        ];

        try {
            $this->support->saveAbg($encounter, $payload, $request->user());
        } catch (ValidationException $e) {
            return back()
                ->withErrors($e->errors)
                ->with('error', 'Hasil AGD gagal disimpan. Periksa kembali nilai yang Anda masukkan.');
        }

        return back()->with('success', 'Hasil AGD tersimpan.');
    }

    /**
     * POST /encounters/{encounter}/penunjang/abg/delete
     * name: penunjang.abg.destroy
     */
    public function destroyAbg(Request $request, Encounter $encounter)
    {
        $data = $request->validate([
            'id' => ['required', 'integer', 'min:1'],
        ], [
            'id.required' => 'Data AGD tidak valid.',
            'id.integer' => 'Data AGD tidak valid.',
        ]);

        $deleted = $this->support->deleteAbg($encounter, (int) $data['id']);

        return $deleted
            ? back()->with('success', 'Hasil AGD dihapus.')
            : back()->with('error', 'Hasil AGD tidak ditemukan pada episode ini.');
    }

    /**
     * POST /encounters/{encounter}/penunjang/reset
     * name: penunjang.reset
     *
     * Aksi destruktif. Cakupan yang dipilih: SATU KELOMPOK yang dipilih, bukan
     * seluruh episode, jadi satu tab tidak bisa menghapus kulturMikrobiologi
     * atau AGD hanya karena sedang melihat tab Lab. `group=abg` mengosongkan
     * tabel abg_results; `lab|blood|micro|rad` mengosongkan support_results
     * pada kelompok itu saja.
     *
     * Konfirmasi dibuat dua lapis: Modal.vue di sisi klien (dua langkah),
     * DAN field `confirm=1` di sisi server. Tanpa field itu permintaan
     * ditolak 403 supaya tidak bisa dipicu oleh form yang ter-sembunyikan
     * field atau oleh GET yang diubah jadi POST.
     */
    public function reset(Request $request, Encounter $encounter)
    {
        abort_unless(
            (string) $request->input('confirm') === '1',
            403,
            'Reset data penunjang harus dikonfirmasi secara eksplisit.'
        );

        $data = $request->validate([
            'group' => ['required', 'string', Rule::in(self::RESETTABLE)],
        ], [
            'group.in' => 'Kelompok penunjang tidak dikenal.',
            'group.required' => 'Pilih kelompok data yang akan dikosongkan.',
        ]);

        $group = $data['group'];

        $deleted = $group === SupportGroup::ABG->value
            ? $encounter->abgResults()->delete()
            : $encounter->supportResults()->where('group', $group)->delete();

        return back()->with('success', sprintf(
            '%d hasil %s dihapus dari episode ini.',
            (int) $deleted,
            SupportGroup::from($group)->label()
        ));
    }

    /**
     * Tab aktif dari query string, default 'lab'.
     */
    private function tab(mixed $group): string
    {
        $group = is_string($group) ? trim($group) : '';

        return in_array($group, self::TABS, true) ? $group : 'lab';
    }

    /**
     * Kelompok hasil generik dari query string, default 'lab'.
     */
    private function resultGroup(mixed $group): string
    {
        $group = is_string($group) ? trim($group) : '';

        return in_array($group, SupportGroup::resultGroups(), true) ? $group : 'lab';
    }

    /**
     * Item tren dari query string; string kosong berarti "pakai bawaan".
     */
    private function trendKey(mixed $key): ?string
    {
        $key = is_string($key) ? trim($key) : '';

        return $key === '' ? null : $key;
    }

    /**
     * Gabung tanggal + jam dari form menjadi satu string ISO; null berarti
     * "sekarang", yang adalah default kedua service.
     */
    private function measuredAt(?string $date, ?string $time): ?string
    {
        $date = trim((string) $date);
        $time = trim((string) $time);

        if ($date === '') {
            return null;
        }

        return $date.'T'.($time === '' ? '00:00' : $time).':00';
    }

    /**
     * Rentang referensi item tren untuk garis target pada grafik dan satuan
     * yang tampil di header. Kunci yang tidak dikenal aman: reference() null.
     *
     * @param  array<string, mixed>  $trend
     * @return array{key: string, label: string, unit: string|null, reference: string, low: float|null, high: float|null, criticalLow: float|null, criticalHigh: float|null, decimals: int}
     */
    private function trendRef(array $trend): array
    {
        $key = (string) ($trend['key'] ?? '');
        $reference = ReferenceRange::reference($key);

        return [
            'key' => $key,
            'label' => (string) ($trend['label'] ?? ''),
            'unit' => $trend['unit'] ?? null,
            'reference' => ReferenceRange::referenceLabel($key),
            'low' => $reference !== null ? (float) $reference['low'] : null,
            'high' => $reference !== null ? (float) $reference['high'] : null,
            'criticalLow' => isset($reference['criticalLow']) ? (float) $reference['criticalLow'] : null,
            'criticalHigh' => isset($reference['criticalHigh']) ? (float) $reference['criticalHigh'] : null,
            'decimals' => ReferenceRange::decimals($key),
        ];
    }

    /**
     * Katalog item per kelompok (lab / blood / micro) dari ReferenceRange,
     * untuk mengisi dropdown item pada form tulis. Kelompok `rad` tidak punya
     * katalog di phase1, jadi opsi dropdown-nya diambil dari baris rad yang
     * sudah ada di sisi klien.
     *
     * @return array<string, array<int, array{key: string, name: string, panel: string}>>
     */
    private function catalog(): array
    {
        $catalog = [];

        foreach (ReferenceRange::catalog() as $group => $items) {
            foreach ($items as $item) {
                $catalog[$group][] = [
                    'key' => (string) $item['key'],
                    'name' => (string) $item['name'],
                    'panel' => (string) $item['panel'],
                ];
            }
        }

        return $catalog;
    }
}

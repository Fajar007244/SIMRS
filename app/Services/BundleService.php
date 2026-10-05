<?php

namespace App\Services;

use App\Enums\BundleAnswer;
use App\Enums\BundleGroup;
use App\Enums\DeviceCode;
use App\Models\Encounter;
use App\Models\InvasiveDevice;
use App\Models\Observation;
use App\Models\ObservationBundleAnswer;
use App\Services\Support\ClinicalFormat;
use Illuminate\Support\Carbon;

/**
 * Bundle pencegahan infeksi (VAP / CLABSI / CAUTI) dan perangkat invasif.
 *
 * Port dari phase1:
 *   countBundleCompliance() -> kuitansi per-observasi di bawah
 *   getBundleSummary()      -> BundleService::getSummary()
 *   getBundleRecords()      -> BundleService::getHistory()
 *   criticalGaps            -> BundleService::getGaps()
 *   getDeviceSummary()      -> BundleService::getDeviceSummary()
 *   percentTone()           -> BundleService::tone()
 *
 * CATATAN WAKTU RUJUKAN (acuan) untuk "lama pakai perangkat":
 * phase1 memakai TANGGAL OBSERVASI TERAKHIR sebagai acuan, bukan hari ini,
 * supaya badge "ETT Hari #3" pada halaman bundle merujuk pada waktu data itu
 * direkam. Aturan yang sama dipakai di sini, dengan konfigurasi ambangnya
 * diambil dari config('hai.device_review_after_days') (= 7) alih-alih angka
 * hardcode 7 pada prototype.
 */
class BundleService
{
    /**
     * Ambang kepatuhan untuk tone, hasil port percentTone() phase1.
     *
     * @var array<int, array{0: float, 1: string}>
     */
    private const TONES = [
        [100, 'emerald'],
        [80, 'sky'],
        [50, 'amber'],
    ];

    /**
     * Ringkasan evaluasi bundle pada observasi TERAKHIR yang punya jawaban.
     *
     * Mengembalikan `groups` untuk 3 bundle (selalu lengkap, walau tidak ada
     * jawabannya) plus `overall` yang menggabungkan seluruh 13 item.
     *
     * `percent` memakai pembulatan countBundleCompliance() phase1: pembagi
     * adalah jumlah item yang DIJAWAB, bukan jumlah item di konfigurasi.
     * `itemCount` karena itu tetap 5/4/4 dan berguna untuk melihat berapa
     * item yang bisa dinilai.
     *
     * `items` di dalam setiap grup berisi jawaban observasi terakhir, jadi
     * kartu checklist pada bundles.html bisa dirender tanpa query tambahan.
     *
     * @return array{
     *     groups: array<string, array<string, mixed>>,
     *     overall: array{group: string, groupLabel: string, itemCount: int, answered: int, compliant: int, percent: float, tone: string},
     *     latestAt: string|null,
     *     latestAtLabel: string|null
     * }
     */
    public function getSummary(Encounter $encounter): array
    {
        $latest = $this->latestEvaluatedObservation($encounter);

        $answers = [];

        if ($latest !== null) {
            $answers = ObservationBundleAnswer::query()
                ->where('observation_id', $latest->getKey())
                ->get()
                ->keyBy('item_key');
        }

        $devices = $this->getDeviceSummary($encounter);
        $devicesByCode = [];

        foreach ($devices as $device) {
            $devicesByCode[$device['code']] = $device;
        }

        $groups = [];
        $total = ['itemCount' => 0, 'answered' => 0, 'compliant' => 0];

        foreach ($this->bundleGroups() as $group) {
            $items = [];
            $stats = ['itemCount' => 0, 'answered' => 0, 'compliant' => 0];

            foreach ($this->bundleItemsOf($group['id']) as $item) {
                /** @var ObservationBundleAnswer|null $answer */
                $answer = $answers[$item['key']] ?? null;
                $stats['itemCount']++;

                if ($answer !== null) {
                    $stats['answered']++;

                    if ($answer->answer === BundleAnswer::YA) {
                        $stats['compliant']++;
                    }
                }

                $items[$item['key']] = [
                    'key' => $item['key'],
                    'label' => $item['label'],
                    'answer' => $answer?->answer?->value,
                    'answerLabel' => $answer?->answer?->label() ?? ClinicalFormat::EMPTY,
                    'compliant' => $answer?->answer?->isCompliant() ?? false,
                    'note' => $answer?->note,
                ];
            }

            $device = $devicesByCode[$group['device_key']] ?? null;
            $label = BundleGroup::tryFrom($group['id'])?->label() ?? $group['title'];

            $groups[$group['id']] = [
                'group' => $group['id'],
                'label' => $label,
                'deviceCode' => $group['device_key'],
                'deviceLabel' => $device['label'] ?? $group['device_key'],
                'deviceDays' => $device['days'] ?? null,
                'itemCount' => $stats['itemCount'],
                'answered' => $stats['answered'],
                'compliant' => $stats['compliant'],
                'percent' => $this->percent($stats['compliant'], $stats['answered']),
                'tone' => $this->tone($this->percent($stats['compliant'], $stats['answered'])),
                'items' => $items,
            ];

            $total['itemCount'] += $stats['itemCount'];
            $total['answered'] += $stats['answered'];
            $total['compliant'] += $stats['compliant'];
        }

        $at = $latest?->recorded_on;

        return [
            'groups' => $groups,
            'overall' => [
                'group' => 'overall',
                'groupLabel' => 'Seluruh Bundle',
                'itemCount' => $total['itemCount'],
                'answered' => $total['answered'],
                'compliant' => $total['compliant'],
                'percent' => $this->percent($total['compliant'], $total['answered']),
                'tone' => $this->tone($this->percent($total['compliant'], $total['answered'])),
            ],
            'latestAt' => ClinicalFormat::iso($at),
            'latestAtLabel' => ClinicalFormat::dateTime($at),
        ];
    }

    /**
     * Satu baris per observasi, TERBARU DI ATAS, untuk tabel riwayat dan
     * grafik tren bundle. Nilai tiap grup adalah persen kepatuhan pada
     * observasi itu (0.0 bila grup tidak dijawab), persis seperti phase1
     * yang memplot `bucket.items ? bucket.percent : 0`.
     *
     * Untuk menggambar garis, balik barisnya (`array_reverse`) supaya urutannya
     * dari lama ke baru.
     *
     * @return array<int, array{observationId: int, at: string, label: string, vap: float, clabsi: float, cauti: float, overall: float}>
     */
    public function getHistory(Encounter $encounter, int $limit = 7): array
    {
        $observations = Observation::query()
            ->where('encounter_id', $encounter->getKey())
            ->latestFirst()
            ->limit(max(1, $limit))
            ->get(['id', 'observation_date', 'observation_time']);

        if ($observations->isEmpty()) {
            return [];
        }

        $answers = ObservationBundleAnswer::query()
            ->whereIn('observation_id', $observations->pluck('id')->all())
            ->get(['observation_id', 'bundle_group', 'answer'])
            ->groupBy('observation_id');

        $rows = [];

        foreach ($observations as $observation) {
            $stats = ['itemCount' => 0, 'answered' => 0, 'compliant' => 0];
            $byGroup = [];

            foreach ($answers->get($observation->id, collect()) as $answer) {
                $group = $answer->bundle_group?->value ?? 'overall';
                $byGroup[$group] ??= ['answered' => 0, 'compliant' => 0];
                $byGroup[$group]['answered']++;

                if ($answer->answer === BundleAnswer::YA) {
                    $byGroup[$group]['compliant']++;
                    $stats['compliant']++;
                }

                $stats['answered']++;
            }

            $at = $observation->recorded_on;

            $rows[] = [
                'observationId' => (int) $observation->getKey(),
                'at' => ClinicalFormat::iso($at) ?? ClinicalFormat::EMPTY,
                'label' => ClinicalFormat::chartLabel($at),
                'vap' => $this->groupPercent($byGroup, BundleGroup::VAP->value),
                'clabsi' => $this->groupPercent($byGroup, BundleGroup::CLABSI->value),
                'cauti' => $this->groupPercent($byGroup, BundleGroup::CAUTI->value),
                'overall' => $this->percent($stats['compliant'], $stats['answered']),
            ];
        }

        return $rows;
    }

    /**
     * Item bundle yang tidak terpenuhi. Satu baris per item_key, berisi
     * observasi TERBARU yang menjawab "Tidak" untuk item tersebut - persis
     * seperti criticalGaps() phase1 yang berhenti pada kemunculan pertama
     * tiap kunci saat berjalan dari observasi terbaru.
     *
     * @return array<int, array{group: string, groupLabel: string, itemKey: string, itemLabel: string, answer: string, answerLabel: string, observationAt: string, observationAtLabel: string, deviceLabel: string, deviceDays: int|null}>
     */
    public function getGaps(Encounter $encounter, int $limit = 12): array
    {
        $observations = Observation::query()
            ->where('encounter_id', $encounter->getKey())
            ->latestFirst()
            ->get(['id', 'observation_date', 'observation_time', 'recorded_by']);

        if ($observations->isEmpty()) {
            return [];
        }

        $answers = ObservationBundleAnswer::query()
            ->whereIn('observation_id', $observations->pluck('id')->all())
            ->where('answer', BundleAnswer::TIDAK->value)
            ->orderBy('id')
            ->get()
            ->groupBy('observation_id');

        $devices = $this->getDeviceSummary($encounter);
        $devicesByCode = [];

        foreach ($devices as $device) {
            $devicesByCode[$device['code']] = $device;
        }

        $labels = $this->itemLabels();
        $deviceByGroup = [];

        foreach ($this->bundleGroups() as $group) {
            $deviceByGroup[$group['id']] = $devicesByCode[$group['device_key']] ?? null;
        }

        $seen = [];
        $rows = [];

        foreach ($observations as $observation) {
            foreach ($answers->get($observation->id, collect()) as $answer) {
                $key = (string) $answer->item_key;

                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $group = $answer->bundle_group?->value ?? 'overall';
                $device = $deviceByGroup[$group] ?? null;
                $at = $observation->recorded_on;

                $rows[] = [
                    'group' => $group,
                    'groupLabel' => BundleGroup::tryFrom($group)?->label() ?? $group,
                    'itemKey' => $key,
                    'itemLabel' => $labels[$key] ?? $key,
                    'answer' => $answer->answer?->value ?? BundleAnswer::TIDAK->value,
                    'answerLabel' => $answer->answer?->label() ?? BundleAnswer::TIDAK->label(),
                    'observationAt' => ClinicalFormat::iso($at) ?? ClinicalFormat::EMPTY,
                    'observationAtLabel' => ClinicalFormat::dateTime($at) ?? ClinicalFormat::EMPTY,
                    'deviceLabel' => $device['label'] ?? $group,
                    'deviceDays' => $device['days'] ?? null,
                ];

                if (count($rows) >= max(1, $limit)) {
                    return $rows;
                }
            }
        }

        return $rows;
    }

    /**
     * Status 8 perangkat invasif, satu baris per katalog PERNAH pun
     * perangkat itu tidak terpasang, sama seperti getDeviceSummary() phase1
     * yang selalu mengembalikan seluruh DEVICE_CATALOG.
     *
     * `days` = lama pakai inklusif (+1) terhadap acuan, yaitu tanggal
     * observasi terakhir pada episode ini; bila belum ada observasi,
     * acuannya adalah hari ini. `needsReview` memakai ambang config('hai.device_review_after_days')
     * dan hanya true bila perangkat masih aktif.
     *
     * @return array<int, array{code: string, label: string, startDate: string|null, startDateLabel: string, days: int|null, isActive: bool, needsReview: bool, reviewAfterDays: int, bundleGroup: string|null, bundleLabel: string|null}>
     */
    public function getDeviceSummary(Encounter $encounter): array
    {
        $reviewAfter = (int) config('hai.device_review_after_days', 7);

        $reference = Observation::query()
            ->where('encounter_id', $encounter->getKey())
            ->latestFirst()
            ->value('observation_date');

        $reference = ClinicalFormat::parse($reference) ?? now();

        $rows = InvasiveDevice::query()
            ->where('encounter_id', $encounter->getKey())
            ->orderByDesc('is_active')
            ->orderBy('start_date')
            ->orderBy('id')
            ->get();

        $latestByCode = [];

        foreach ($rows as $device) {
            $code = $device->device_code?->value;

            if ($code === null) {
                continue;
            }

            $active = $latestByCode[$code] ?? null;

            if ($active === null || ($device->is_active && ! $active->is_active)) {
                $latestByCode[$code] = $device;
            }
        }

        $summary = [];

        foreach ((array) config('hai.device_catalog', []) as $entry) {
            $code = DeviceCode::tryFrom((string) $entry['key']);
            $device = $latestByCode[$entry['key']] ?? null;

            $startDate = $device?->start_date;
            $days = $device?->daysInUse($reference);
            $isActive = (bool) ($device?->is_active ?? false);
            $group = $device?->device_code?->bundleGroup() ?: ($entry['group'] ?? '');

            $summary[] = [
                'code' => $entry['key'],
                'label' => $device?->label ?: ($device?->device_code?->label() ?? $entry['label']),
                'startDate' => $startDate instanceof Carbon ? $startDate->format('Y-m-d') : null,
                'startDateLabel' => ClinicalFormat::dateLabel($startDate),
                'days' => $days,
                'isActive' => $isActive,
                'needsReview' => $isActive && $days !== null && $days >= $reviewAfter,
                'reviewAfterDays' => $reviewAfter,
                'bundleGroup' => $group === '' ? null : $group,
                'bundleLabel' => $group === '' ? null : (BundleGroup::tryFrom($group)?->label() ?? $group),
            ];
        }

        return $summary;
    }

    /**
     * Observasi terbaru yang punya minimal satu jawaban bundle.
     */
    private function latestEvaluatedObservation(Encounter $encounter): ?Observation
    {
        return Observation::query()
            ->where('encounter_id', $encounter->getKey())
            ->whereHas('bundleAnswers')
            ->latestFirst()
            ->first(['id', 'observation_date', 'observation_time', 'recorded_by', 'recorded_at']);
    }

    /**
     * @return array<int, array{id: string, title: string, short: string, device_key: string}>
     */
    private function bundleGroups(): array
    {
        return (array) config('hai.bundle_groups', []);
    }

    /**
     * @return array<int, array{group: string, key: string, label: string}>
     */
    private function bundleItemsOf(string $group): array
    {
        $items = [];

        foreach ((array) config('hai.bundle_items', []) as $item) {
            if (($item['group'] ?? null) === $group) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * @return array<string, string> item_key => label
     */
    private function itemLabels(): array
    {
        $labels = [];

        foreach ((array) config('hai.bundle_items', []) as $item) {
            $labels[$item['key']] = $item['label'];
        }

        return $labels;
    }

    /**
     * @param  array<string, array{answered: int, compliant: int}>  $byGroup
     */
    private function groupPercent(array $byGroup, string $group): float
    {
        $bucket = $byGroup[$group] ?? ['answered' => 0, 'compliant' => 0];

        return $this->percent($bucket['compliant'], $bucket['answered']);
    }

    /**
     * Pembagian nol menghasilkan 0, sama dengan countBundleCompliance() phase1.
     * Hasilnya float supaya bisa langsung dipakai sebagai titik SVG.
     */
    private function percent(int $compliant, int $answered): float
    {
        return $answered > 0 ? (float) round($compliant / $answered * 100) : 0.0;
    }

    /**
     * Port percentTone() phase1: >=100 emerald, >=80 sky, >=50 amber, else red.
     */
    public function tone(float $percent): string
    {
        foreach (self::TONES as [$threshold, $tone]) {
            if ($percent >= $threshold) {
                return $tone;
            }
        }

        return 'red';
    }
}

<?php

/*
|--------------------------------------------------------------------------
| Konfigurasi Skor Awal Kegawatdaruratan (Early Warning Score)
|--------------------------------------------------------------------------
|
| Tabel ini adalah konstanta klinis, bukan data aplikasi, karena itu
| diletakkan di config/ dan bukan di migration.
|
| PENTING - JANGAN "DIPERBAIKI" SENDIRI:
|
| Skala yang dipakai aplikasi ini adalah BRITISH EARLY WARNING SCALE
| 4 TINGKAT, bukan MEWS 3-tingkat standar.
|
| UKURAN YANG MEMBEDAKANNYA:
|   1. Ada 6 parameter, bukan 5. "Kesadaran" dihitung sebagai parameter
|      tersendiri, bukan tersirat di dalam parameter lain.
|   2. Semua parameter punya rentang skor penuh 0-3. Pada MEWS konvensional
|      skor 3 hanya mungkin muncul pada satu parameter, sedangkan di sini
|      RR, HR, SBP, SpO2, dan Suhu masing-masing bisa mencapai 3.
|   3. Eskalasi berjenjang 4 tingkat: 0-2 low, 3-4 medium, 5-6 high,
|      7 atau lebih emergency. MEWS hanya punya tiga tingkat.
|
| Kesalahan yang paling sering terjadi pada tabel ini adalah diganti ke
| MEWS standar saat ditinjau, karena MEWS lebih dikenal luas. Tabel di
| bawah sengaja mengikuti phase1/app-context.js (EWS_TABLE) dan
| phase1/observasi.html (blok data-purpose="ews-config") apa adanya.
|
| STRUKTUR: array `parameters` dapat diiterasi secara generik. Parameter
| bertipe 'range' punya `bands` berisi [min, max, score], urut dari nilai
| terendah dan kedua batas inklusif. Parameter bertipe 'map' punya
| `values`, yaitu skor untuk setiap nilai diskret. Array `escalation`
| juga generik, berisi rentang total skor dengan `max` null untuk batas
| atas terbuka.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Metadata
    |--------------------------------------------------------------------------
    */
    'scale' => 'British Early Warning Scale',
    'tiers' => 4,
    'parameter_count' => 6,
    'max_total' => 18,
    'notes' => [
        '4-tier British early warning scale, bukan MEWS 3-tingkat standar.',
        '6 parameter; Kesadaran dihitung terpisah dari parameter lain.',
        'Setiap parameter memiliki rentang skor penuh 0-3.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Parameter dan Bands
    |--------------------------------------------------------------------------
    |
    | Kunci array = nama parameter pada formulir dan pada json ews_scores.
    | `field` = nama kolom terkait pada tabel observations.
    |
    */
    'parameters' => [

        'RR' => [
            'label' => 'Respirasi (x/mnt)',
            'field' => 'rr',
            'unit' => 'x/mnt',
            'type' => 'range',
            'bands' => [
                ['min' => 0, 'max' => 8, 'score' => 2],
                ['min' => 9, 'max' => 11, 'score' => 1],
                ['min' => 12, 'max' => 20, 'score' => 0],
                ['min' => 21, 'max' => 24, 'score' => 2],
                ['min' => 25, 'max' => 999, 'score' => 3],
            ],
        ],

        'HR' => [
            'label' => 'Nadi (x/mnt)',
            'field' => 'hr',
            'unit' => 'x/mnt',
            'type' => 'range',
            'bands' => [
                ['min' => 0, 'max' => 40, 'score' => 2],
                ['min' => 41, 'max' => 50, 'score' => 1],
                ['min' => 51, 'max' => 90, 'score' => 0],
                ['min' => 91, 'max' => 110, 'score' => 1],
                ['min' => 111, 'max' => 130, 'score' => 2],
                ['min' => 131, 'max' => 999, 'score' => 3],
            ],
        ],

        'SBP' => [
            'label' => 'Tekanan Darah Sistolik (mmHg)',
            'field' => 'sys',
            'unit' => 'mmHg',
            'type' => 'range',
            'bands' => [
                ['min' => 0, 'max' => 70, 'score' => 3],
                ['min' => 71, 'max' => 80, 'score' => 2],
                ['min' => 81, 'max' => 100, 'score' => 1],
                ['min' => 101, 'max' => 179, 'score' => 0],
                ['min' => 180, 'max' => 220, 'score' => 1],
                ['min' => 221, 'max' => 999, 'score' => 2],
            ],
        ],

        'SpO2' => [
            'label' => 'Saturasi Oksigen (%)',
            'field' => 'spo2',
            'unit' => '%',
            'type' => 'range',
            'bands' => [
                ['min' => 0, 'max' => 90, 'score' => 3],
                ['min' => 91, 'max' => 92, 'score' => 2],
                ['min' => 93, 'max' => 94, 'score' => 1],
                ['min' => 95, 'max' => 100, 'score' => 0],
            ],
        ],

        'Temp' => [
            'label' => 'Suhu Tubuh',
            'field' => 'suhu',
            'unit' => 'C',
            'type' => 'range',
            'bands' => [
                ['min' => 0, 'max' => 35.0, 'score' => 2],
                ['min' => 35.1, 'max' => 36.0, 'score' => 1],
                ['min' => 36.1, 'max' => 38.0, 'score' => 0],
                ['min' => 38.1, 'max' => 38.5, 'score' => 1],
                ['min' => 38.6, 'max' => 41, 'score' => 2],
                ['min' => 41.1, 'max' => 99, 'score' => 3],
            ],
        ],

        /*
        | Kesadaran (AVPU + DPO). Bukan rentang numerik melainkan peta nilai
        | diskret karena inputnya berupa select, bukan angka. DPO (sedasi)
        | bernilai 0; pada praktik klinis skor ini digantikan oleh RASS
        | yang dicatat terpisah pada formulir observasi.
        */
        'Kesadaran' => [
            'label' => 'Tingkat Kesadaran (AVPU)',
            'field' => 'kesadaran',
            'type' => 'map',
            'values' => [
                'Alert' => 0,
                'Voice' => 1,
                'Pain' => 2,
                'Unresponsive' => 3,
                'DPO' => 0,
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Ambang Eskalasi
    |--------------------------------------------------------------------------
    |
    | Total 0-2     => low
    | Total 3-4     => medium
    | Total 5-6     => high
    | Total 7 atau lebih => emergency
    |
    | `max` null berarti terbuka ke atas. Nilai `level` harus sinkron dengan
    | App\Enums\EwsRiskLevel.
    |
    */
    'escalation' => [
        ['min' => 0, 'max' => 2, 'level' => 'low', 'label' => 'Low'],
        ['min' => 3, 'max' => 4, 'level' => 'medium', 'label' => 'Medium'],
        ['min' => 5, 'max' => 6, 'level' => 'high', 'label' => 'High'],
        ['min' => 7, 'max' => null, 'level' => 'emergency', 'label' => 'Emergency'],
    ],

];

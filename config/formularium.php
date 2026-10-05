<?php

/*
|--------------------------------------------------------------------------
| Formularium Obat Terjadwal Unit ICU
|--------------------------------------------------------------------------
|
| PORT CATATAN DARI phase1/farmasi.html (fungsi buildFormularium, baris
| sekitar 832-844). Formularium di sana TIDAK sepenuhnya hardcoded: dua
| item selalu ditambahkan tanpa syarat, dan dua item lain hanya muncul bila
| kata kunci tertentu ditemukan di teks rencana terapi ASMED.
|
| Karena daftar ini merupakan konstanta klinis unit, isinya dipindahkan ke
| sini agar bisa dipakai server-side dan tidak hilang saat UI ditulis ulang.
| Aturan pemicunya ikut disimpan pada `plan_keywords` / `plan_checks`.
|
| `realization` menyatakan cara menghitung kolom Realisasi pada tabel:
|  - 'category' = jumlah pemberian pada kategori obat yang sama
|  - 'name_contains' = jumlah pemberian yang nama obatnya mengandung salah
|    satu dari `match_keys`
|
*/

return [

    'unit' => 'ICU',
    'title' => 'Daftar Obat Terjadwal & Formularium Unit',

    /*
    | Urutan kategori. WAJIB sama dengan App\Enums\MedicationCategory karena
    | farmasi.html mengurutkan baris dan diagram batang mengikuti urutan ini.
    */
    'category_order' => [
        'Inotropik / Vasopressor',
        'Sedasi & Analgesia',
        'Antibiotik',
        'Cairan & Elektrolit',
        'Obat Systemic',
        'Lainnya',
    ],

    /*
    | Item yang selalu ada pada episode perawatan di unit ini.
    */
    'always_scheduled' => [
        [
            'name' => 'Midazolam 50 mg / 50 mL',
            'route' => 'Syringe pump',
            'dose' => '2 mg/jam',
            'target' => 'RASS -2 s/d -3',
            'status' => 'Aktif',
            'category' => 'Sedasi & Analgesia',
            'realization' => 'name_contains',
            'match_keys' => ['midazolam'],
        ],
        [
            'name' => 'Meropenem 1 g',
            'route' => 'IV intermittent',
            'dose' => '3 x 1 g',
            'target' => 'Antibiotik empiris',
            'status' => 'Sesuai jadwal',
            'category' => 'Antibiotik',
            'realization' => 'name_contains',
            'match_keys' => ['meropenem'],
        ],
    ],

    /*
    | Item bersyarat: hanya masuk daftar bila salah satu kata kunci pada
    | `plan_keywords` ditemukan di teks rencana terapi ASMED (case-insensitive,
    | substring match - sama seperti indexOf di phase1).
    */
    'conditionally_scheduled' => [
        [
            'name' => 'Norepinefrin 4 mg / 50 mL',
            'route' => 'Syringe pump',
            'dose' => 'Titrated',
            'target' => 'MAP >65',
            'status' => 'Aktif / titrasi',
            'category' => 'Inotropik / Vasopressor',
            'plan_keywords' => ['norepinefrin', 'norepi', 'noradrenalin'],
            'realization' => 'name_contains',
            'match_keys' => ['norepi', 'noradren'],
        ],
        [
            'name' => 'Antibiotik Empiris',
            'route' => 'IV',
            'dose' => 'Sesuai protokol',
            'target' => 'Infeksi teratasi',
            'status' => 'Sesuai jadwal',
            'category' => 'Antibiotik',
            'plan_keywords' => ['antibiotik'],
            'realization' => 'category',
            'match_keys' => [],
        ],
    ],

    /*
    | Pemeriksaan rencana terapi (PLAN_CHECKS di farmasi.html). Mengembalikan
    | potongan kalimat pertama yang memuat pola tersebut dari `asmeds.plan`,
    | sehingga UI bisa mengutip sumbernya. Urutan slot = urutan kartu pada UI
    | dan harus sinkron dengan MedicationCategory.
    */
    'plan_checks' => [
        'Inotropik / Vasopressor' => [
            'slot' => 'inotrope',
            'pattern' => 'norepi|vasopres|adrenalin|epinefrin|dobutamin|dopamin|vasopressin',
        ],
        'Sedasi & Analgesia' => [
            'slot' => 'sedasi',
            'pattern' => 'midazolam|propofol|fentanyl|morfina|remifentanil|dexmed|tramadol|analges|sedat',
        ],
        'Antibiotik' => [
            'slot' => 'antibiotik',
            'pattern' => 'meropenem|seftri|cef|azitrom|amikasin|gentam|vankom|linezol|antibiotik|piperasilin|tazobakt',
        ],
        'Cairan & Elektrolit' => [
            'slot' => 'cairan',
            'pattern' => 'kcl|nacl|rl|ca |gluk|dextro|mgso|elektrolit|aquabidest|infus|cairan',
        ],
    ],

    /*
    | Pola inferensi kategori obat dari nama obat (inferMedicationCategory di
    | observasi.html). Dievaluasi berurutan dari atas; yang pertama cocok
    | menang, dan tidak ada yang cocok berarti "Lainnya".
    */
    'category_patterns' => [
        ['category' => 'Inotropik / Vasopressor', 'pattern' => 'norepi|vasopres|adrenalin|epinefrin|dobutamin|dopamin|vasopressin'],
        ['category' => 'Sedasi & Analgesia', 'pattern' => 'midazolam|propofol|fentanyl|morfina|remifentanil|dexmed|tramadol|analges|sedat'],
        ['category' => 'Antibiotik', 'pattern' => 'meropenem|seftri|cef|azitrom|amikasin|gentam|vankom|linezol|antibiotik|piperasilin|tazobakt'],
        ['category' => 'Cairan & Elektrolit', 'pattern' => 'kcl|nacl|rl|ca |gluk|dextro|mgso|elektrolit|aquabidest|infus|cairan'],
        ['category' => 'Obat Systemic', 'pattern' => 'paracetamol|ibuprofen|asam|antikoag|heparin|warfarin|insulin'],
    ],

    'default_category' => 'Lainnya',

];

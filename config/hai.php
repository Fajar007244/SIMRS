<?php

/*
|--------------------------------------------------------------------------
| Referensi Keperawatan: Bundle Pencegahan Infeksi & Perangkat Invasif
|--------------------------------------------------------------------------
|
| Konstanta klinis yang di-port verbatim dari phase1/app-context.js
| (BUNDLE_GROUPS, BUNDLE_ITEMS, DEVICE_CATALOG). Nilai label memakai
| degree symbol asli, bukan escape sequence, supaya siap dipakai langsung
| di UI.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | BUNDLE_GROUPS - 3 bundle yang dinilai per observasi
    |--------------------------------------------------------------------------
    |
    | `device_key` menunjuk ke DEVICE_CATALOG: bundle hanya relevan bila
    | perangkat terkait terpasang pada pasien.
    |
    */
    'bundle_groups' => [
        [
            'id' => 'vap',
            'title' => 'VAP Bundle (Ventilator)',
            'short' => 'VAP',
            'device_key' => 'ett',
        ],
        [
            'id' => 'clabsi',
            'title' => 'CLABSI Bundle (CVC Jugular)',
            'short' => 'CLABSI',
            'device_key' => 'cvc',
        ],
        [
            'id' => 'cauti',
            'title' => 'CAUTI Bundle (Dower Catheter)',
            'short' => 'CAUTI',
            'device_key' => 'dc',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | BUNDLE_ITEMS - 13 itembundle
    |--------------------------------------------------------------------------
    |
    | Kunci unik (observation_bundle_answers) adalah kolom `key`, bukan `id`
    | grup. phase1 juga memakai `key` sebagai idempotency per item.
    |
    */
    'bundle_items' => [
        ['group' => 'vap', 'key' => 'vap_1', 'label' => 'Melakukan kebersihan tangan secara rutin setiap prosedur tindakan atau manipulasi pasien dan peralatan kesehatan'],
        ['group' => 'vap', 'key' => 'vap_2', 'label' => 'Posisi kepala 30 s/d 45°'],
        ['group' => 'vap', 'key' => 'vap_3', 'label' => 'Melakukan perawatan kebersihan mulut dan hidung secara rutin'],
        ['group' => 'vap', 'key' => 'vap_4', 'label' => 'Menggunakan APD sesuai indikasi paparan'],
        ['group' => 'vap', 'key' => 'vap_5', 'label' => 'Manajemen sekresi oropharingeal dan endotrakeal'],
        ['group' => 'cauti', 'key' => 'cauti_1', 'label' => 'Sistem drainase tertutup'],
        ['group' => 'cauti', 'key' => 'cauti_2', 'label' => 'Letak urine bag (digantung) lebih rendah dari bladder'],
        ['group' => 'cauti', 'key' => 'cauti_3', 'label' => 'Area peri uretra dibersihkan 2 x sehari'],
        ['group' => 'cauti', 'key' => 'cauti_4', 'label' => 'Menggunakan wadah berbeda pada setiap pasien saat membuang urine'],
        ['group' => 'clabsi', 'key' => 'clabsi_1', 'label' => 'Hand hygiene sebelum dan sesudah memanipulasi Catheter'],
        ['group' => 'clabsi', 'key' => 'clabsi_2', 'label' => 'Scrub the hub menggunakan antiseptik (alkohol 70%) minimal 15 detik sebelum akses port'],
        ['group' => 'clabsi', 'key' => 'clabsi_3', 'label' => 'Dressing CVC intak, bersih, dan kering (diganti bila kotor/lepas)'],
        ['group' => 'clabsi', 'key' => 'clabsi_4', 'label' => 'Kaji harian kebutuhan CVC, evaluasi untuk penggantian/pencabutan sesuai indikasi'],
    ],

    /*
    |--------------------------------------------------------------------------
    | DEVICE_CATALOG - 8 perangkat invasif
    |--------------------------------------------------------------------------
    |
    | `group` menunjuk ke BUNDLE_GROUPS; string kosong berarti tidak terikat
    | pada bundle tertentu.
    |
    | fields `date_input_id` / `checkbox_name` / `note_input_id` berasal dari
    | DOM phase1. Di Laravel ini tidak dipakai sebagai nama kolom, hanya
    | disimpan agar pemetaan ke phase1 tetap dapat ditelusuri.
    |
    */
    'device_catalog' => [
        ['key' => 'ett', 'label' => 'Endotracheal Tube / Tracheostomy Tube', 'date_input_id' => 'ettStartDate', 'checkbox_name' => 'dev_ett', 'group' => 'vap'],
        ['key' => 'cvc', 'label' => 'Central Venous Catheter / CVC (Jugular)', 'date_input_id' => 'cvcStartDate', 'checkbox_name' => 'dev_cvc', 'group' => 'clabsi'],
        ['key' => 'ventTubing', 'label' => 'Tubing Ventilator', 'date_input_id' => 'ventTubingStartDate', 'checkbox_name' => 'dev_ventTubing', 'group' => 'vap'],
        ['key' => 'ngt', 'label' => 'Nasogastric Tube / NGT', 'date_input_id' => 'ngtStartDate', 'checkbox_name' => 'dev_ngt', 'group' => ''],
        ['key' => 'arterial', 'label' => 'Arteri Line Catheter (Radial Dextra)', 'date_input_id' => 'arterialStartDate', 'checkbox_name' => 'dev_arterial', 'group' => 'clabsi'],
        ['key' => 'dc', 'label' => 'Dower Catheter (Foley No. 16)', 'date_input_id' => 'dcStartDate', 'checkbox_name' => 'dev_dc', 'group' => 'cauti'],
        ['key' => 'infus', 'label' => 'Infus Perifer', 'date_input_id' => 'infusStartDate', 'checkbox_name' => 'dev_infus', 'group' => ''],
        ['key' => 'lain', 'label' => 'Tindakan Lain', 'date_input_id' => 'lainStartDate', 'checkbox_name' => 'dev_lain', 'note_input_id' => 'lainNoteInput', 'group' => ''],
    ],

    /*
    |--------------------------------------------------------------------------
    | Ambang device review
    |--------------------------------------------------------------------------
    |
    | phase1 getDeviceSummary() menandai `needsReview` bila perangkat terpasang
    | dan sudah dipakai >= 7 hari (indikasi evaluasi ulang / cabut).
    |
    */
    'device_review_after_days' => 7,

];

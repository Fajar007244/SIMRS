<?php

namespace Database\Seeders;

use App\Enums\DiagnosisType;
use App\Enums\EncounterStatus;
use App\Enums\NursingShift;
use App\Enums\PaymentType;
use App\Enums\Sex;
use App\Enums\UserRole;
use App\Models\Asmed;
use App\Models\Diagnosis;
use App\Models\Encounter;
use App\Models\MedicalNote;
use App\Models\NursingCare;
use App\Models\Patient;
use App\Models\Procedure;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Enam pasien, enam episode, beserta seluruh isi formulir admisi.
 *
 * Sumber: phase1/app-context.js fungsi seedData() (baris 231-506). Nama, MRN,
 * tanggal lahir, jenis kelamin, alergi, guarantee, unit, bed, jam masuk, DPJP,
 * diagnosis beserta kode ICD-10, ASMED, asuhan keperawatan, dan prosedur
 * beserta kode ICD-9 disalin apa adanya. Tidak ada data yang disederhanakan.
 *
 * Yang ditambahkan di luar prototype (prototype tidak punya sama sekali):
 * - address, phone, blood_type, is_active pada patients (wajib untuk kelengkapan
 *   master data di Laravel, tidak pernah tampil di phase1);
 * - discharge pada episode BUDI SANTOSO yang berstatus "selesai";
 * - baris CPPT (medical_notes), karena app-context.js tidak menyimpan CPPT sama
 *   sekali. Teksnya ditulis mengikuti blok S / O / A / P yang dipakai cppt.html
 *   dan diturunkan dari ASMED + penunjang episode yang sama.
 *
 * Semua operasi memakai updateOrCreate dengan kunci bisnis (patient_id,
 * encounter_id, dan kombinasi kolom yang unik secara logis) supaya db:seed
 * dapat dijalankan berulang tanpa bentrok unique index.
 */
class PatientEncounterSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->patients() as $patientRow) {
            $patient = Patient::query()->updateOrCreate(
                ['patient_id' => $patientRow['patient_id']],
                [
                    'mrn' => $patientRow['mrn'],
                    'name' => $patientRow['name'],
                    'sex' => Sex::from($patientRow['sex']),
                    'birth_date' => $patientRow['birth_date'],
                    'address' => $patientRow['address'],
                    'phone' => $patientRow['phone'],
                    'blood_type' => $patientRow['blood_type'],
                    'allergies' => $patientRow['allergies'],
                    'payment' => PaymentType::from($patientRow['payment']),
                    'is_active' => true,
                ]
            );

            foreach ($patientRow['encounters'] as $encounterRow) {
                $encounter = $this->seedEncounter($patient, $encounterRow);
                $this->seedDiagnoses($encounter, $encounterRow['diagnoses']);
                $this->seedProcedures($encounter, $encounterRow['procedures']);
                $this->seedAsmed($encounter, $encounterRow['asmed']);
                $this->seedNursingCare($encounter, $encounterRow['nursing_care']);
                $this->seedMedicalNotes($encounter, $this->medicalNotes()[$encounterRow['encounter_id']] ?? []);
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Episode
    |--------------------------------------------------------------------------
    */

    protected function seedEncounter(Patient $patient, array $row): Encounter
    {
        // diagnosis_summary disusun persis seperti renderPatientIdentity() di
        // phase1: seluruh teks diagnosis episodic digabung dengan ", ".
        $summary = implode(', ', array_map(
            fn (array $diagnosis): string => $diagnosis['text'],
            $row['diagnoses']
        ));

        return Encounter::query()->updateOrCreate(
            ['encounter_id' => $row['encounter_id']],
            [
                'patient_id' => $patient->getKey(),
                'unit' => $row['unit'],
                'bed' => $row['bed'],
                'admitted_at' => $row['admitted_at'],
                'discharged_at' => $row['discharged_at'],
                'attending_physician' => $row['attending_physician'],
                'status' => EncounterStatus::from($row['status']),
                'diagnosis_summary' => $summary,
                'allergy_alert' => $patient->allergies,
                'latest_observation_at' => $row['latest_observation_at'],
                'notes' => $row['notes'],
            ]
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Anak episode
    |--------------------------------------------------------------------------
    */

    protected function seedDiagnoses(Encounter $encounter, array $rows): void
    {
        foreach ($rows as $row) {
            Diagnosis::query()->updateOrCreate(
                [
                    'encounter_id' => $encounter->getKey(),
                    'code' => $row['code'],
                    'text' => $row['text'],
                ],
                [
                    'type' => DiagnosisType::from($row['type']),
                    'author' => $row['author'],
                ]
            );
        }
    }

    protected function seedProcedures(Encounter $encounter, array $rows): void
    {
        foreach ($rows as $row) {
            Procedure::query()->updateOrCreate(
                [
                    'encounter_id' => $encounter->getKey(),
                    'name' => $row['name'],
                    'performed_at' => Carbon::parse($row['performed_at']),
                ],
                [
                    'code' => $row['code'],
                    'operator' => $row['operator'],
                ]
            );
        }
    }

    /**
     * Kolom `vitals` di database bertipe json, sedangkan prototype menyimpannya
     * sebagai satu kalimat bebas. Supaya tidak kehilangan kalimat aslinya, teks
     * itu disimpan pada kunci `ringkasan` dan angka yang sudah dipisah disimpan
     * pada kunci masing-masing.
     */
    protected function seedAsmed(Encounter $encounter, ?array $row): void
    {
        if ($row === null) {
            return;
        }

        Asmed::query()->updateOrCreate(
            ['encounter_id' => $encounter->getKey()],
            [
                'complaint' => $row['complaint'],
                'history' => $row['history'],
                'physical_exam' => $row['physical_exam'],
                'vitals' => [
                    'ringkasan' => $row['vitals'],
                    'tekanan_darah' => $row['tekanan_darah'],
                    'nadi' => $row['nadi'],
                    'respirasi' => $row['respirasi'],
                    'suhu' => $row['suhu'],
                    'saturasi' => $row['saturasi'],
                ],
                'plan' => $row['plan'],
                'examiner' => $row['examiner'],
                'examined_at' => $row['examined_at'],
            ]
        );
    }

    protected function seedNursingCare(Encounter $encounter, ?array $row): void
    {
        if ($row === null) {
            return;
        }

        NursingCare::query()->updateOrCreate(
            [
                'encounter_id' => $encounter->getKey(),
                'recorded_at' => Carbon::parse($row['recorded_at']),
            ],
            [
                'assessment' => $row['assessment'],
                'problems' => $row['problems'],
                'interventions' => $row['interventions'],
                'nurse' => $row['nurse'],
                'shift' => NursingShift::from($row['shift']),
                'is_latest' => true,
            ]
        );
    }

    protected function seedMedicalNotes(Encounter $encounter, array $rows): void
    {
        foreach ($rows as $row) {
            MedicalNote::query()->updateOrCreate(
                [
                    'encounter_id' => $encounter->getKey(),
                    'order_number' => $row['order_number'],
                ],
                [
                    'author_name' => $row['author_name'],
                    'author_role' => UserRole::from($row['author_role']),
                    'author_specialty' => $row['author_specialty'],
                    'note_type' => $row['note_type'],
                    'subjective' => $row['subjective'],
                    'objective' => $row['objective'],
                    'assessment' => $row['assessment'],
                    'plan' => $row['plan'],
                    'noted_at' => $row['noted_at'],
                ]
            );
        }
    }
    /*
    |--------------------------------------------------------------------------
    | Data pasien dan episode
    |--------------------------------------------------------------------------
    |
    | Transkripsi langsung dari array `patients` pada seedData() di
    | phase1/app-context.js. Kunci snake_case dipakai di sisi Laravel, tetapi
    | setiap nilai klinis (nama, MRN, tanggal, kode, teks) apa adanya.
    |
    */

    protected function patients(): array
    {
        return [

            [
                'patient_id' => 'patient-159853',
                'mrn' => '159853',
                'name' => 'OO JAENAL',
                'sex' => Sex::LAKI_LAKI->value,
                'birth_date' => '1956-03-12',
                'allergies' => 'Ciprofloxacin',
                'payment' => PaymentType::BPJS_PBI->value,
                'address' => 'Jl. Melati No. 14, Sunggumanai, Makassar',
                'phone' => '081312450101',
                'blood_type' => 'B',
                'encounters' => [
                    [
                        'encounter_id' => 'enc-159853-icu-20260906',
                        'unit' => 'Ruang Nusa Indah',
                        'bed' => 'Bed 02',
                        'admitted_at' => '2026-09-06 08:30',
                        'discharged_at' => null,
                        'attending_physician' => 'dr. Rangga Saputra, Sp.An-TI, Subsp.T.I.(K)',
                        'status' => EncounterStatus::AKTIF->value,
                        'latest_observation_at' => '2026-09-07 12:00',
                        'notes' => null,
                        'diagnoses' => [
                            ['type' => DiagnosisType::UTAMA->value, 'text' => 'Syok septik ec HAP', 'code' => 'A41.9', 'author' => 'dr. Rangga Saputra, Sp.An-TI'],
                            ['type' => DiagnosisType::PENYERTA->value, 'text' => 'ARDS mild-moderate', 'code' => 'J80', 'author' => 'dr. Rangga Saputra, Sp.An-TI'],
                            ['type' => DiagnosisType::PENYERTA->value, 'text' => 'AKI stage 2', 'code' => 'N17.9', 'author' => 'dr. Rangga Saputra, Sp.An-TI'],
                        ],
                        'asmed' => [
                            'complaint' => 'Sesak napas dan penurunan kesadaran sejak 1 hari',
                            'history' => 'Riwayat dirawat 5 hari sebelumnya karena pneumonia, demam tinggi',
                            'physical_exam' => 'GCS E3-Vt-M5, akral dingin, CRT lebih 3 detik, ronki basah bilateral',
                            'vitals' => 'TD 88/52 mmHg, HR 118x/mnt, RR 24x/mnt, Suhu 38.4 C, SpO2 92%',
                            'tekanan_darah' => '88/52 mmHg',
                            'nadi' => '118 x/mnt',
                            'respirasi' => '24 x/mnt',
                            'suhu' => '38.4 C',
                            'saturasi' => '92%',
                            'plan' => 'Resusitasi cairan, norepinefrin titrasi, kultur darah dan sputum, antibiotik empiris',
                            'examiner' => 'dr. Rangga Saputra, Sp.An-TI',
                            'examined_at' => '2026-09-06 09:10',
                        ],
                        'nursing_care' => [
                            'assessment' => 'Gangguan perfusi jaringan, pola napas tidak efektif, risiko infeksi',
                            'problems' => 'Perfusi perifer tidak efektif; Bersihan jalan napas tidak efektif',
                            'interventions' => 'Monitor MAP tiap jam, suction berkala, rawat luka, bundle VAP',
                            'nurse' => 'Ns. Tri Handayani',
                            'shift' => NursingShift::PAGI->value,
                            'recorded_at' => '2026-09-06 09:30',
                        ],
                        'procedures' => [
                            ['name' => 'Intubasi ETT 7.5 + Ventilator PSIMV', 'code' => '96.04', 'performed_at' => '2026-09-06', 'operator' => 'dr. Rangga Saputra'],
                            ['name' => 'Pemasangan CVC (Central Venous Catheter)', 'code' => '38.93', 'performed_at' => '2026-09-06', 'operator' => 'dr. Rangga Saputra'],
                        ],
                    ],
                ],
            ],

            [
                'patient_id' => 'patient-162210',
                'mrn' => '162210',
                'name' => 'SITI NURHALIZA',
                'sex' => Sex::PEREMPUAN->value,
                'birth_date' => '1968-07-21',
                'allergies' => 'Tidak ada',
                'payment' => PaymentType::BPJS_NON_PBI->value,
                'address' => 'Jl. Kenanga No. 7, Pandotodong, Makassar',
                'phone' => '081312450102',
                'blood_type' => 'O',
                'encounters' => [
                    [
                        'encounter_id' => 'enc-162210-hcu-20260910',
                        'unit' => 'HCU Melati',
                        'bed' => 'Bed 05',
                        'admitted_at' => '2026-09-10 14:15',
                        'discharged_at' => null,
                        'attending_physician' => 'dr. Maya Lestari, Sp.PD',
                        'status' => EncounterStatus::AKTIF->value,
                        'latest_observation_at' => '2026-09-12 06:00',
                        'notes' => null,
                        'diagnoses' => [
                            ['type' => DiagnosisType::UTAMA->value, 'text' => 'Pneumonia komunitas', 'code' => 'J18.9', 'author' => 'dr. Maya Lestari, Sp.PD'],
                            ['type' => DiagnosisType::PENYERTA->value, 'text' => 'Diabetes melitus tipe 2', 'code' => 'E11.9', 'author' => 'dr. Maya Lestari, Sp.PD'],
                        ],
                        'asmed' => [
                            'complaint' => 'Demam dan batuk berdahak sejak 4 hari',
                            'history' => 'Diabetes melitus tipe 2 terkontrol metformin, hipertensi',
                            'physical_exam' => 'Krepitasi basal dextra, retraksi ringan, akral hangat',
                            'vitals' => 'TD 128/78 mmHg, HR 96x/mnt, RR 22x/mnt, Suhu 38.6 C, SpO2 94%',
                            'tekanan_darah' => '128/78 mmHg',
                            'nadi' => '96 x/mnt',
                            'respirasi' => '22 x/mnt',
                            'suhu' => '38.6 C',
                            'saturasi' => '94%',
                            'plan' => 'Antibiotik seftriakson, nebulizer, koreksi gula darah, fisioterapi napas',
                            'examiner' => 'dr. Maya Lestari, Sp.PD',
                            'examined_at' => '2026-09-10 15:00',
                        ],
                        'nursing_care' => null,
                        'procedures' => [],
                    ],
                ],
            ],

            [
                'patient_id' => 'patient-158774',
                'mrn' => '158774',
                'name' => 'AHMAD FAUZI',
                'sex' => Sex::LAKI_LAKI->value,
                'birth_date' => '1962-01-30',
                'allergies' => 'Penisilin',
                'payment' => PaymentType::ASURANSI->value,
                'address' => 'Jl. Cendana No. 22, Rappocini, Makassar',
                'phone' => '081312450103',
                'blood_type' => 'A',
                'encounters' => [
                    [
                        'encounter_id' => 'enc-158774-icu-20260908',
                        'unit' => 'ICU Bed 01',
                        'bed' => 'Bed 01',
                        'admitted_at' => '2026-09-08 03:40',
                        'discharged_at' => null,
                        'attending_physician' => 'dr. Rangga Saputra, Sp.An-TI, Subsp.T.I.(K)',
                        'status' => EncounterStatus::AKTIF->value,
                        'latest_observation_at' => '2026-09-09 16:00',
                        'notes' => null,
                        'diagnoses' => [
                            ['type' => DiagnosisType::UTAMA->value, 'text' => 'Infark miokard akut STEMI anterior', 'code' => 'I21.0', 'author' => 'dr. Rangga Saputra, Sp.An-TI'],
                            ['type' => DiagnosisType::PENYERTA->value, 'text' => 'Hipertensi esensial', 'code' => 'I10', 'author' => 'dr. Rangga Saputra, Sp.An-TI'],
                        ],
                        'asmed' => [
                            'complaint' => 'Nyeri dada menembus punggung sejak 2 jam',
                            'history' => 'Perokok aktif, hipertensi tidak terkontrol',
                            'physical_exam' => 'Nyeri tekan region prekordial, akral dingin, JVP tidak meningkat',
                            'vitals' => 'TD 96/60 mmHg, HR 112x/mnt, RR 20x/mnt, Suhu 36.8 C, SpO2 97%',
                            'tekanan_darah' => '96/60 mmHg',
                            'nadi' => '112 x/mnt',
                            'respirasi' => '20 x/mnt',
                            'suhu' => '36.8 C',
                            'saturasi' => '97%',
                            'plan' => 'Dual antiplatelet, PCI primer, heparin, monitor EKG kontinu',
                            'examiner' => 'dr. Rangga Saputra, Sp.An-TI',
                            'examined_at' => '2026-09-08 04:20',
                        ],
                        'nursing_care' => [
                            'assessment' => 'Nyeri akut, penurunan curah jantung, intoleransi aktivitas',
                            'problems' => 'Nyeri akut; Penurunan curah jantung',
                            'interventions' => 'Tirah baring, monitor EKG, batasi aktivitas, edukasi tidak mengejan',
                            'nurse' => 'Ns. Tri Handayani',
                            'shift' => NursingShift::MALAM->value,
                            'recorded_at' => '2026-09-08 04:45',
                        ],
                        'procedures' => [
                            ['name' => 'PCI / Stent DES LAD', 'code' => '36.07', 'performed_at' => '2026-09-08', 'operator' => 'dr. Rangga Saputra'],
                        ],
                    ],
                ],
            ],

            [
                'patient_id' => 'patient-160045',
                'mrn' => '160045',
                'name' => 'MARIA KAROLINA',
                'sex' => Sex::PEREMPUAN->value,
                'birth_date' => '1981-05-17',
                'allergies' => 'Sulfa',
                'payment' => PaymentType::UMUM->value,
                'address' => 'Jl. Flamboyan No. 5, Ujung Pandang, Makassar',
                'phone' => '081312450104',
                'blood_type' => 'AB',
                'encounters' => [
                    [
                        'encounter_id' => 'enc-160045-hcu-20260911',
                        'unit' => 'HCU Melati',
                        'bed' => 'Bed 03',
                        'admitted_at' => '2026-09-11 19:05',
                        'discharged_at' => null,
                        'attending_physician' => 'dr. Hendra Wijaya, Sp.PD',
                        'status' => EncounterStatus::AKTIF->value,
                        'latest_observation_at' => '2026-09-12 13:00',
                        'notes' => null,
                        'diagnoses' => [
                            ['type' => DiagnosisType::UTAMA->value, 'text' => 'Diabetes ketoasidosis', 'code' => 'E11.1', 'author' => 'dr. Hendra Wijaya, Sp.PD'],
                            ['type' => DiagnosisType::PENYERTA->value, 'text' => 'Sepsis', 'code' => 'A41.9', 'author' => 'dr. Hendra Wijaya, Sp.PD'],
                        ],
                        'asmed' => null,
                        'nursing_care' => [
                            'assessment' => 'Gangguan keseimbangan cairan, risiko hipoglikemia, risiko infeksi',
                            'problems' => 'Defisit volume cairan; Ketidakstabilan kadar gula darah',
                            'interventions' => 'Monitor gula darah tiap 2 jam, balance cairan, insulin infus protokol',
                            'nurse' => 'Bd. Sari Wulandari',
                            'shift' => NursingShift::SIANG->value,
                            'recorded_at' => '2026-09-11 20:00',
                        ],
                        'procedures' => [],
                    ],
                ],
            ],

            [
                'patient_id' => 'patient-157330',
                'mrn' => '157330',
                'name' => 'BUDI SANTOSO',
                'sex' => Sex::LAKI_LAKI->value,
                'birth_date' => '1974-11-02',
                'allergies' => 'Tidak ada',
                'payment' => PaymentType::BPJS_PBI->value,
                'address' => 'Jl. Mawar No. 31, Panakkukang, Makassar',
                'phone' => '081312450105',
                'blood_type' => 'O',
                'encounters' => [
                    [
                        'encounter_id' => 'enc-157330-icu-20260905',
                        'unit' => 'Ruang Nusa Indah',
                        'bed' => 'Bed 04',
                        'admitted_at' => '2026-09-05 10:20',
                        'discharged_at' => '2026-09-06 22:00',
                        'attending_physician' => 'dr. Hendra Wijaya, Sp.PD',
                        'status' => EncounterStatus::SELESAI->value,
                        'latest_observation_at' => '2026-09-06 22:00',
                        'notes' => 'Pulang perbaikan. Kontrol hemodialisis poliklinik nefrologi 2x/minggu, laporan perawat tetap dilanjutkan',
                        'diagnoses' => [
                            ['type' => DiagnosisType::UTAMA->value, 'text' => 'Penyakit ginjal kronik stage 5', 'code' => 'N18.5', 'author' => 'dr. Hendra Wijaya, Sp.PD'],
                            ['type' => DiagnosisType::PENYERTA->value, 'text' => 'Anemia normositik', 'code' => 'D64.9', 'author' => 'dr. Hendra Wijaya, Sp.PD'],
                        ],
                        'asmed' => [
                            'complaint' => 'Mual, lemas, dan bengkak pada kedua kaki',
                            'history' => 'CKD sejak 3 tahun, HD 2x/minggu',
                            'physical_exam' => 'Edema pretibial, pucat, ronki basal ringan',
                            'vitals' => 'TD 150/90 mmHg, HR 88x/mnt, RR 20x/mnt, Suhu 36.5 C, SpO2 96%',
                            'tekanan_darah' => '150/90 mmHg',
                            'nadi' => '88 x/mnt',
                            'respirasi' => '20 x/mnt',
                            'suhu' => '36.5 C',
                            'saturasi' => '96%',
                            'plan' => 'Hemodialisis terjadwal, transfusi PRC, monitor elektrolit',
                            'examiner' => 'dr. Hendra Wijaya, Sp.PD',
                            'examined_at' => '2026-09-05 11:00',
                        ],
                        'nursing_care' => null,
                        'procedures' => [
                            ['name' => 'Hemodialisis (HD) rutin', 'code' => '39.95', 'performed_at' => '2026-09-06', 'operator' => 'Tim HD'],
                        ],
                    ],
                ],
            ],

            [
                'patient_id' => 'patient-163901',
                'mrn' => '163901',
                'name' => 'RATNA SARI DEWI',
                'sex' => Sex::PEREMPUAN->value,
                'birth_date' => '1990-02-08',
                'allergies' => 'Latex',
                'payment' => PaymentType::BPJS_NON_PBI->value,
                'address' => 'Jl. Melati No. 3, Bontong, Makassar',
                'phone' => '081312450106',
                'blood_type' => 'B',
                'encounters' => [
                    [
                        'encounter_id' => 'enc-163901-hcu-20260912',
                        'unit' => 'HCU Anggrek',
                        'bed' => 'Bed 06',
                        'admitted_at' => '2026-09-12 06:50',
                        'discharged_at' => null,
                        'attending_physician' => 'dr. Maya Lestari, Sp.PD',
                        'status' => EncounterStatus::AKTIF->value,
                        'latest_observation_at' => '2026-09-13 10:00',
                        'notes' => null,
                        'diagnoses' => [
                            ['type' => DiagnosisType::UTAMA->value, 'text' => 'Preeklamsia berat', 'code' => 'O14.1', 'author' => 'dr. Maya Lestari, Sp.PD'],
                            ['type' => DiagnosisType::PENYERTA->value, 'text' => 'Anemia ringan', 'code' => 'D64.9', 'author' => 'dr. Maya Lestari, Sp.PD'],
                        ],
                        'asmed' => [
                            'complaint' => 'Nyeri kepala hebat dan pandangan kabur',
                            'history' => 'G2P1, usia kehamilan 34 minggu, ANC teratur',
                            'physical_exam' => 'Edema ekstremitas, TD tinggi, refleks fisiologis meningkat',
                            'vitals' => 'TD 168/110 mmHg, HR 92x/mnt, RR 20x/mnt, Suhu 36.9 C, SpO2 98%',
                            'tekanan_darah' => '168/110 mmHg',
                            'nadi' => '92 x/mnt',
                            'respirasi' => '20 x/mnt',
                            'suhu' => '36.9 C',
                            'saturasi' => '98%',
                            'plan' => 'MgSO4 protokol, antihipertensi, monitoring janin, rencana SC',
                            'examiner' => 'dr. Maya Lestari, Sp.PD',
                            'examined_at' => '2026-09-12 07:30',
                        ],
                        'nursing_care' => null,
                        'procedures' => [],
                    ],
                ],
            ],

        ];
    }
    /*
    |--------------------------------------------------------------------------
    | CPPT (medical_notes)
    |--------------------------------------------------------------------------
    |
    | phase1/app-context.js tidak menyimpan CPPT sama sekali: halaman
    | cppt.html hanya menampilkan isian yang diketik petugas secara interaktif.
    | Dua catatan per episode di bawah ditulis mengikuti blok S, O, A, dan P
    | yang dipakai cppt.html, dan diturunkan dari ASMED, penunjang, serta
    | observasi episode yang sama. Bukan transkripsi, dan ditandai demikian
    | di laporan.
    |
    */

    protected function medicalNotes(): array
    {
        return [

            'enc-159853-icu-20260906' => [
                [
                    'order_number' => 1,
                    'note_type' => 'CPPT Awal',
                    'author_name' => 'dr. Rangga Saputra, Sp.An-TI, Subsp.T.I.(K)',
                    'author_role' => UserRole::DOKTER->value,
                    'author_specialty' => 'Konsultan Intensif',
                    'noted_at' => '2026-09-06 09:10',
                    'subjective' => 'Sesak napas dan penurunan kesadaran sejak 1 hari. Riwayat dirawat 5 hari sebelumnya karena pneumonia, demam tinggi. Alergi ciprofloxacin.',
                    'objective' => 'GCS E3-Vt-M5, akral dingin, CRT lebih 3 detik, ronki basah bilateral. TD 88/52 mmHg, HR 118 x/mnt, RR 24 x/mnt, Suhu 38.4 C, SpO2 92% dengan ventilator PSIMV FiO2 40%. Leukosit 18.4 x10^3/uL, CRP 128 mg/L, prokalcitonin 4.2 ng/mL, kreatinin 2.4 mg/dL.',
                    'assessment' => 'Syok septik ec HAP dengan ARDS mild-moderate dan AKI stage 2. Infeksi paru dengan hipoperfusi dan gangguan pertukaran gas.',
                    'plan' => 'Intubasi ETT 7.5 dengan ventilator PSIMV, resusitasi cairan kristaloid, titrasi norepinefrin sampai MAP di atas 65 mmHg, kultur darah dua set dan kultur sputum, meropenem 1 g tiap 8 jam, pemasangan CVC untuk akses parenteral.',
                ],
                [
                    'order_number' => 2,
                    'note_type' => 'CPPT Harian',
                    'author_name' => 'dr. Rangga Saputra, Sp.An-TI, Subsp.T.I.(K)',
                    'author_role' => UserRole::DOKTER->value,
                    'author_specialty' => 'Konsultan Intensif',
                    'noted_at' => '2026-09-07 08:30',
                    'subjective' => 'Pasien dalam sedasi DPO dengan RASS minus 2, tidak ada keluhan subjektif baru.',
                    'objective' => 'TD 112/66 mmHg, HR 86 x/mnt, RR 16 x/mnt, Suhu 36.9 C, SpO2 98% dengan FiO2 30%. Leukosit 14.2 x10^3/uL, CRP 84 mg/L, prokalcitonin 2.1 ng/mL, kreatinin 2.2 mg/dL. AGD pH 7.40, PaCO2 37 mmHg, PaO2 95 mmHg, HCO3 24.0 mEq/L.',
                    'assessment' => 'Syok septik membaik, ARDS ringan, AKI stage 2 belum selesai. Respon klinis terhadap resusitasi dan antibiotik tercapai.',
                    'plan' => 'Lanjutkan norepinefrin sesuai titrasi MAP, weaning ventilator PSIMV dengan FiO2 30%, kultur ulang bila demam di atas 38.5 C, pertahankan bundle VAP dan CLABSI, evaluasi pencabutan CVC pada hari ke-3.',
                ],
            ],

            'enc-162210-hcu-20260910' => [
                [
                    'order_number' => 1,
                    'note_type' => 'CPPT Awal',
                    'author_name' => 'dr. Maya Lestari, Sp.PD',
                    'author_role' => UserRole::DOKTER->value,
                    'author_specialty' => 'Konsultan Penyakit Dalam',
                    'noted_at' => '2026-09-10 15:00',
                    'subjective' => 'Demam dan batuk berdahak sejak 4 hari. Diabetes melitus tipe 2 terkontrol metformin, hipertensi. Tidak ada alergi obat.',
                    'objective' => 'Krepitasi basal dextra, retraksi ringan, akral hangat. TD 128/78 mmHg, HR 96 x/mnt, RR 22 x/mnt, Suhu 38.6 C, SpO2 94% dengan nasal kanul 3 L/mnt. Leukosit 13.2 x10^3/uL, CRP 42 mg/L, trombosit 240 x10^3/uL, glukosa 186 mg/dL, AGD pH 7.40, PaCO2 38 mmHg, PaO2 88 mmHg.',
                    'assessment' => 'Pneumonia komunitas dengan diabetes melitus tipe 2 dan hipertensi. Gagal napas hipoksemik ringan, belum memerlukan bantuan ventilator.',
                    'plan' => 'Antibiotik seftriakson 1 g tiap 24 jam, oksigen nasal kanul 3 L/mnt dengan nebulisasi, koreksi gula darah, fisioterapi napas dan mobilisasi duduk, evaluasi ulang penunjang 24 jam.',
                ],
                [
                    'order_number' => 2,
                    'note_type' => 'CPPT Harian',
                    'author_name' => 'dr. Maya Lestari, Sp.PD',
                    'author_role' => UserRole::DOKTER->value,
                    'author_specialty' => 'Konsultan Penyakit Dalam',
                    'noted_at' => '2026-09-11 10:00',
                    'subjective' => 'Batuk berdahak berkurang, sesak napas membaik, nafsu makan mulai baik.',
                    'objective' => 'TD 126/78 mmHg, HR 84 x/mnt, RR 18 x/mnt, Suhu 37.4 C, SpO2 97% dengan simple mask 3 L/mnt. Leukosit 9.8 x10^3/uL, CRP 24 mg/L, glukosa 152 mg/dL, AGD pH 7.41, PaCO2 36 mmHg, PaO2 94 mmHg.',
                    'assessment' => 'Pneumonia komunitas merespons terhadap antibiotik, kadar glukosa terkendali, dispense'
                        .'Sesak napas membaik, pasien siap turun ke ruang perawatan biasa.',
                    'plan' => 'Lanjutkan seftriakson, turunkan dukungan oksigen bertahap, fisioterapi napas dua kali sehari,'
                        .' edukasi hidrasi dan inhalasi untuk pasien.',
                ],
            ],

            'enc-158774-icu-20260908' => [
                [
                    'order_number' => 1,
                    'note_type' => 'CPPT Awal',
                    'author_name' => 'dr. Rangga Saputra, Sp.An-TI, Subsp.T.I.(K)',
                    'author_role' => UserRole::DOKTER->value,
                    'author_specialty' => 'Konsultan Intensif',
                    'noted_at' => '2026-09-08 04:20',
                    'subjective' => 'Nyeri dada menembus punggung sejak 2 jam. Perokok aktif, hipertensi tidak terkontrol. Alergi penisilin.',
                    'objective' => 'Nyeri tekan region prekordial, akral dingin, JVP tidak meningkat. TD 96/60 mmHg, HR 112 x/mnt, RR 20 x/mnt, Suhu 36.8 C, SpO2 97%. EKG ST elevasi di V1 sampai V4. Leukosit 11.4 x10^3/uL, kreatinin 1.1 mg/dL, glukosa 132 mg/dL.',
                    'assessment' => 'Infark miokard akut STEMI anterior dengan hipertensi esensial. Killip II tanpa edema paru.',
                    'plan' => 'Dual antiplatelet asam asetilsalisilat dan klopidogrel, antikoagulan heparin, PCI primer dengan stent DES LAD, monitor EKG kontinu, oksigen nasal kanul bila SpO2 di bawah 94%.',
                ],
                [
                    'order_number' => 2,
                    'note_type' => 'CPPT Harian',
                    'author_name' => 'dr. Rangga Saputra, Sp.An-TI, Subsp.T.I.(K)',
                    'author_role' => UserRole::DOKTER->value,
                    'author_specialty' => 'Konsultan Intensif',
                    'noted_at' => '2026-09-09 08:00',
                    'subjective' => 'Nyeri dada berkurang, tidak ada keluhan sesak napas.',
                    'objective' => 'TD 116/72 mmHg, HR 80 x/mnt, RR 15 x/mnt, Suhu 36.8 C, SpO2 99% dengan oksigen ruang. Leukosit 10.2 x10^3/uL, kreatinin 1.1 mg/dL, glukosa 126 mg/dL. AGD pH 7.41, PaCO2 35 mmHg, PaO2 95 mmHg.',
                    'assessment' => 'Infark miokard akut STEMI anterior pascapci, evolusi baik, tanpa komplikasi mekanik.',
                    'plan' => 'Lanjutkan dual antiplatelet minimal 12 bulan, statins dosis tinggi, rehabilitasi kardiak fase awal, mobilisasi bertahap, dan pantau rutin EKG.',
                ],
            ],

            'enc-160045-hcu-20260911' => [
                [
                    'order_number' => 1,
                    'note_type' => 'CPPT Awal',
                    'author_name' => 'dr. Hendra Wijaya, Sp.PD',
                    'author_role' => UserRole::DOKTER->value,
                    'author_specialty' => 'Konsultan Penyakit Dalam',
                    'noted_at' => '2026-09-11 19:30',
                    'subjective' => 'Mual dan muntah satu kali, rasa haus meningkat, sering buang air kecil.',
                    'objective' => 'Kulit kering, turgor menurun, napas dalam dan berbau apel. TD 108/68 mmHg, HR 118 x/mnt, RR 24 x/mnt, Suhu 37.4 C, SpO2 97%. Glukosa 342 mg/dL, natrem 130 mEq/L, kalium 5.4 mEq/L, AGD pH 7.28, HCO3 15.2 mEq/L, BE minus 9.5.',
                    'assessment' => 'Diabetes ketoasidosis dengan sepsis akibat infeksi saluran kemih.',
                    'plan' => 'Insulin regular 20 IU per jam infus, NaCl 0.9% sampai MAP minimal 65 mmHg, koreksi kalium, seftriakson 1 g tiap 12 jam, kultur urine, monitor glukosa tiap 2 jam.',
                ],
                [
                    'order_number' => 2,
                    'note_type' => 'CPPT Harian',
                    'author_name' => 'dr. Hendra Wijaya, Sp.PD',
                    'author_role' => UserRole::DOKTER->value,
                    'author_specialty' => 'Konsultan Penyakit Dalam',
                    'noted_at' => '2026-09-12 09:00',
                    'subjective' => 'Pasien lebih sadar, dapat minum, tidak ada muntah ulang.',
                    'objective' => 'TD 126/78 mmHg, HR 90 x/mnt, RR 16 x/mnt, Suhu 36.8 C, SpO2 99%. Glukosa 218 mg/dL, natrem 134 mEq/L, kalium 4.6 mEq/L, AGD pH 7.36, HCO3 21.5 mEq/L, BE minus 3.0. Kultur urine Escherichia coli lebih dari 10^5 CFU/mL.',
                    'assessment' => 'Status pH membaik, defisit volume tertutup sebagian, infeksi saluran kemih terkonfirmasi.',
                    'plan' => 'Turunkan infus insulin bertahap, lanjutkan antibiotik sesuai hasil kultur, mobilisasi bertahap, dan edukasi diet.',
                ],
            ],

            'enc-157330-icu-20260905' => [
                [
                    'order_number' => 1,
                    'note_type' => 'CPPT Awal',
                    'author_name' => 'dr. Hendra Wijaya, Sp.PD',
                    'author_role' => UserRole::DOKTER->value,
                    'author_specialty' => 'Konsultan Penyakit Dalam',
                    'noted_at' => '2026-09-05 11:00',
                    'subjective' => 'Mual, lemas, dan bengkak pada kedua kaki. CKD sejak 3 tahun dengan hemodialisis 2 kali seminggu.',
                    'objective' => 'Edema pretibial, pucat, ronki basal ringan. TD 150/90 mmHg, HR 88 x/mnt, RR 20 x/mnt, Suhu 36.5 C, SpO2 96%. Leukosit 7.2 x10^3/uL, hemoglobin 7.4 g/dL, kreatinin 5.8 mg/dL, BUN 62 mg/dL, kalium 5.2 mEq/L.',
                    'assessment' => 'Penyakit ginjal kronik stage 5 dengan anemia normositik. Gangguan elektrolit dan asam basa berisiko tinggi.',
                    'plan' => 'Hemodialisis terjadwal, transfusi packed red cell, koreksi hiperkalemia, pembatasan cairan, monitor elektrolit harian.',
                ],
                [
                    'order_number' => 2,
                    'note_type' => 'CPPT Pasca Hemodialisis',
                    'author_name' => 'dr. Hendra Wijaya, Sp.PD',
                    'author_role' => UserRole::DOKTER->value,
                    'author_specialty' => 'Konsultan Penyakit Dalam',
                    'noted_at' => '2026-09-06 12:00',
                    'subjective' => 'Mual berkurang, lemas berkurang, bengkak kedua kaki berkurang.',
                    'objective' => 'TD 132/80 mmHg, HR 72 x/mnt, RR 16 x/mnt, Suhu 36.4 C, SpO2 98%. Hemoglobin 9.6 g/dL, kreatinin 4.8 mg/dL, BUN 50 mg/dL, kalium 4.7 mEq/L. AGD pH 7.39, HCO3 21.0 mEq/L, BE minus 3.5.',
                    'assessment' => 'Penyakit ginjal kronik stage 5 pascahemodialisis, anemia membaik, keseimbangan cairan mendekati target.',
                    'plan' => 'Lanjutkan jadwal hemodialisis dua kali seminggu, monitoring elektrolit,'
                        .' Edukasi diet dan cairan.',
                ],

            ],

            'enc-163901-hcu-20260912' => [
                [
                    'order_number' => 1,
                    'note_type' => 'CPPT Awal',
                    'author_name' => 'dr. Maya Lestari, Sp.PD',
                    'author_role' => UserRole::DOKTER->value,
                    'author_specialty' => 'Konsultan Penyakit Dalam',
                    'noted_at' => '2026-09-12 07:30',
                    'subjective' => 'Nyeri kepala hebat dan pandangan kabur. G2P1, usia kehamilan 34 minggu, ANC teratur. Alergi latex.',
                    'objective' => 'Edema ekstremitas, TD tinggi, refleks fisiologis meningkat. TD 168/110 mmHg, HR 92 x/mnt, RR 20 x/mnt, Suhu 36.9 C, SpO2 98%. Leukosit 14.6 x10^3/uL, hemoglobin 9.8 g/dL, trombosit 175 x10^3/uL, protein urin 3+.',
                    'assessment' => 'Preeklamsia berat dengan anemia ringan, berisiko kejang eklampsia.',
                    'plan' => 'MgSO4 4 g loading lalu 1 g per jam, antihipertensi nifedipin, monitoring denyut jantung janin, dan rencana sectio Caesarea.',
                ],
                [
                    'order_number' => 2,
                    'note_type' => 'CPPT Harian',
                    'author_name' => 'dr. Maya Lestari, Sp.PD',
                    'author_role' => UserRole::DOKTER->value,
                    'author_specialty' => 'Konsultan Penyakit Dalam',
                    'noted_at' => '2026-09-13 08:00',
                    'subjective' => 'Nyeri kepala berkurang, penglihatan membaik, tidak ada gejala kejang.',
                    'objective' => 'TD 140/88 mmHg, HR 74 x/mnt, RR 16 x/mnt, Suhu 36.4 C, SpO2 99%. Leukosit 12.1 x10^3/uL, hemoglobin 10.2 g/dL, protein urin 1+. AGD pH 7.40, HCO3 22.5 mEq/L.',
                    'assessment' => 'Preeklamsia berat dalam perbaikan, tekanan darah terkendali, kondisi ibu stabil.',
                    'plan' => 'Lanjutkan MgSO4 sampai 24 jam setelah persalinan, nifedipin, pantau protein urin dan berat badan, konseling gizi.',
                ],
            ],
        ];
    }
}

<?php

namespace App\Services;

use App\Enums\DiagnosisType;
use App\Enums\EncounterStatus;
use App\Enums\NursingShift;
use App\Enums\PaymentType;
use App\Enums\Sex;
use App\Models\Asmed;
use App\Models\Diagnosis;
use App\Models\Encounter;
use App\Models\MedicalNote;
use App\Models\NursingCare;
use App\Models\Observation;
use App\Models\ObservationMedication;
use App\Models\Patient;
use App\Models\Procedure;
use App\Models\User;
use App\Services\Support\ClinicalFormat;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Admisi: daftar pasien, profil lengkap, CPPT, dan pembuatan admisi baru.
 *
 * Port dari phase1:
 *   getPatients() + collectRows()  -> listPatients()
 *   getCurrentPatient() + profil   -> getProfile()
 *   cppt.html renderStats/Timeline -> getCppt()
 *   renderPatientIdentity()        -> getBannerPayload()
 *
 * PERINGATAN KONTRAK BANNER:
 * getBannerPayload() sengaja hanya mengembalikan 12 kunci yang persis
 *acicak atribut `data-patient="*"` pada keenam halaman phase1. Komponen
 * PatientHeaderBanner dibangun developer lain persis terhadap daftar itu, jadi
 * kunci apa pun yang ditambah atau dihapus akan breaksynchronisasi kontrak.
 * Semua profil yang lebih kaya ada di getProfile().
 */
class AdmissionService
{
    public function __construct(
        private readonly EwsScoringService $ews,
        private readonly BundleService $bundles,
        private readonly ObservationService $observations,
    ) {}

    /**
     * Satu baris per episode, untuk kartu dan tabel daftar pasien.
     *
     * FILTER:
     *   search -> nama / MRM / patient_id (partial, case-insensitive)
     *   unit   -> nama unit pelayanan
     *   status -> status episode; default AKTIF. Beri string kosong
     *             atau 'all' untuk menampilkan seluruh status.
     *
     * Urutan default: risiko tertinggi lebih dulu, lalu EWS tertinggi, lalu
     * observasi terbaru - sama dengan opsi sortir "risiko" di patients.html.
     *
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    public function listPatients(array $filters = []): array
    {
        $status = $filters['status'] ?? EncounterStatus::AKTIF->value;

        $query = Encounter::query()->with('patient');

        if (is_string($status) && trim($status) !== '' && $status !== 'all') {
            $query->where('status', trim($status));
        }

        $unit = $filters['unit'] ?? null;

        if (is_string($unit) && trim($unit) !== '') {
            $query->where('unit', trim($unit));
        }

        $search = $filters['search'] ?? $filters['q'] ?? null;

        if (is_string($search) && trim($search) !== '') {
            $like = '%'.trim($search).'%';

            $query->whereHas('patient', fn (Builder $patient) => $patient
                ->where('name', 'like', $like)
                ->orWhere('mrn', 'like', $like)
                ->orWhere('patient_id', 'like', $like));
        }

        $encounters = $query->get();

        if ($encounters->isEmpty()) {
            return [];
        }

        $encounterIds = $encounters->pluck('id')->all();

        $latestObservations = $this->latestObservationsByEncounter($encounterIds);
        $diagnoses = $this->diagnosesByEncounter($encounterIds);
        $asmed = Asmed::query()->whereIn('encounter_id', $encounterIds)->pluck('encounter_id')->flip();
        $nursing = NursingCare::query()->whereIn('encounter_id', $encounterIds)->distinct()->pluck('encounter_id')->flip();
        $procedures = Procedure::query()->whereIn('encounter_id', $encounterIds)->distinct()->pluck('encounter_id')->flip();

        $medicationCounts = $this->medicationCounts($encounterIds);

        $rows = [];

        foreach ($encounters as $encounter) {
            $rows[] = $this->listRow(
                $encounter,
                $latestObservations[$encounter->getKey()] ?? null,
                $diagnoses[$encounter->getKey()] ?? [],
                [
                    'asmed' => $asmed->has($encounter->getKey()),
                    'nursingCare' => $nursing->has($encounter->getKey()),
                    'diagnosis' => isset($diagnoses[$encounter->getKey()]) && $diagnoses[$encounter->getKey()] !== [],
                    'procedure' => $procedures->has($encounter->getKey()),
                ],
                $medicationCounts[$encounter->getKey()] ?? 0
            );
        }

        usort($rows, function (array $a, array $b) {
            $rank = ['emergency' => 4, 'high' => 3, 'medium' => 2, 'low' => 1, 'none' => 0];

            $byRisk = ($rank[$b['latestRisk']] ?? 0) <=> ($rank[$a['latestRisk']] ?? 0);

            if ($byRisk !== 0) {
                return $byRisk;
            }

            $byScore = ($b['latestEws'] ?? 0) <=> ($a['latestEws'] ?? 0);

            if ($byScore !== 0) {
                return $byScore;
            }

            return strcmp((string) $b['latestObsAt'], (string) $a['latestObsAt']);
        });

        return $rows;
    }

    /**
     * Profil lengkap satu episode untuk halaman profil / CPPT.
     *
     * @return array<string, mixed>
     */
    public function getProfile(Encounter $encounter): array
    {
        $encounter->loadMissing([
            'patient',
            'diagnoses',
            'procedures',
            'nursingCares' => fn ($query) => $query->limit(1),
        ]);

        $patient = $encounter->patient;
        $asmed = $encounter->relationLoaded('asmed')
            ? $encounter->getRelation('asmed')
            : $encounter->asmed()->first();

        $nursing = $encounter->getRelation('nursingCares')->first();
        $flowsheet = $this->observations->getFlowsheet($encounter);
        $latest = $flowsheet[0] ?? null;

        $completion = $encounter->completion;
        $completionCount = count(array_filter($completion));

        return [
            'patient' => $this->patientFields($encounter, $patient),
            'encounter' => $this->encounterFields($encounter),
            'diagnoses' => $this->diagnosisRows($encounter),
            'procedures' => $this->procedureRows($encounter),
            'asmed' => $this->asmedFields($asmed),
            'nursingCare' => $this->nursingCareFields($nursing),
            'medicalNoteCount' => (int) $encounter->medicalNotes()->count(),
            'completion' => $completion,
            'observationCount' => count($flowsheet),
            'medicationCount' => (int) $encounter->observationMedications()->count(),
            'careTeam' => $this->careTeam($encounter, $asmed, $nursing, $latest),
            'devices' => $this->bundles->getDeviceSummary($encounter),
            'latestObservation' => $latest,
        ];
    }

    /**
     * Halaman CPPT: enam kartu statistik dan daftar catatan progres.
     *
     * `notes` diurutkan dari yang paling lama (urutan default relasi
     * Encounter::medicalNotes, yaitu noted_at lalu order_number) karena
     * kronologi progres dokumen yang ditandatangani memang dibaca berurutan.
     *
     * @return array{stats: array<string, mixed>, notes: array<int, array<string, mixed>>}
     */
    public function getCppt(Encounter $encounter): array
    {
        $encounter->loadMissing([
            'patient',
            'asmed',
            'nursingCares' => fn ($query) => $query->limit(1),
        ]);

        $asmed = $encounter->getRelation('asmed');
        $nursing = $encounter->getRelation('nursingCares')->first();

        $latest = $this->observations->getFlowsheet($encounter, [])[0] ?? null;

        $notes = [];

        foreach ($encounter->medicalNotes()->get() as $note) {
            $at = $note->noted_at;

            $notes[] = [
                'id' => (int) $note->getKey(),
                'order' => (int) $note->order_number,
                'authorName' => ClinicalFormat::dash($note->author_name),
                'authorRole' => $note->author_role?->value,
                'authorRoleLabel' => $note->author_role?->label(),
                'authorSpecialty' => $note->author_specialty,
                'noteType' => $note->note_type,
                'subjective' => $note->subjective,
                'objective' => $note->objective,
                'assessment' => $note->assessment,
                'plan' => $note->plan,
                'notedAt' => ClinicalFormat::iso($at),
                'notedAtLabel' => ClinicalFormat::dateTimeLabel($at),
            ];
        }

        return [
            'stats' => [
                'asmed' => $asmed === null ? null : 'Ada',
                'asmedDetail' => $asmed === null
                    ? 'belum diisi'
                    : ClinicalFormat::dash($asmed->examiner).' - '.ClinicalFormat::dateTimeLabel($asmed->examined_at),
                'nursingCare' => $nursing === null ? null : 'Ada',
                'nursingCareDetail' => $nursing === null
                    ? 'belum diisi'
                    : 'Shift '.ClinicalFormat::dash($nursing->shift?->value).' - '.ClinicalFormat::dateTimeLabel($nursing->recorded_at),
                'diagnosis' => (int) $encounter->diagnoses()->count(),
                'procedure' => (int) $encounter->procedures()->count(),
                'ews' => $latest['ewsTotal'] ?? null,
                'ewsDetail' => $latest === null ? 'belum ada observasi' : $latest['recordedAtLabel'],
                'observation' => (int) $encounter->observations()->count(),
            ],
            'notes' => $notes,
        ];
    }

    /**
     * Banner identitas pasien bersama untuk keenam halaman.
     *
     * KONTRAK KETAT: tepat 12 kunci, tidak lebih tidak kurang, yaitu 12
     * atribut `data-patient="*"` yang dipakai phase1:
     *   admission, allergies, demographics, diagnoses, dpjp, initials,
     *   latestObsRecordedBy, latestObsTimestamp, mrn, name, payment, unitBed
     *
     * Nilai sudah siap ditampilkan (label bahasa Indonesia, '-' bila kosong),
     * mengikuti renderPatientIdentity() phase1 persis. Jangan tambah atau
     * hapus kunci di sini tanpa menyinkronkan komponen banner.
     *
     * @return array{
     *     name: string,
     *     demographics: string,
     *     mrn: string,
     *     payment: string,
     *     unitBed: string,
     *     admission: string,
     *     dpjp: string,
     *     allergies: string,
     *     diagnoses: string,
     *     latestObsTimestamp: string,
     *     latestObsRecordedBy: string
     * }
     */
    public function getBannerPayload(Encounter $encounter): array
    {
        $patient = $encounter->relationLoaded('patient')
            ? $encounter->getRelation('patient')
            : $encounter->patient()->first();

        $encounter->loadMissing('diagnoses');

        $latest = Observation::query()
            ->where('encounter_id', $encounter->getKey())
            ->latestFirst()
            ->first(['observation_date', 'observation_time', 'recorded_by']);

        $diagnoses = [];

        foreach ($encounter->getRelation('diagnoses') as $diagnosis) {
            $diagnoses[] = (string) $diagnosis->text;
        }

        $at = $latest?->recorded_on;

        return [
            'name' => ClinicalFormat::dash($patient?->name),
            'initials' => $patient?->initials ?? '??',
            'demographics' => $patient?->demographics ?? ClinicalFormat::EMPTY,
            'mrn' => ClinicalFormat::dash($patient?->mrn),
            'payment' => ClinicalFormat::dash($patient?->payment?->value),
            'unitBed' => $encounter->unit_bed,
            'admission' => ClinicalFormat::dateTimeLabel($encounter->admitted_at),
            'dpjp' => ClinicalFormat::dash($encounter->attending_physician),
            'allergies' => ClinicalFormat::dash($patient?->allergies),
            'diagnoses' => $diagnoses === [] ? ClinicalFormat::EMPTY : implode(', ', $diagnoses),
            'latestObsTimestamp' => ClinicalFormat::dateTimeLabel($at),
            'latestObsRecordedBy' => ClinicalFormat::dash($latest?->recorded_by),
        ];
    }

    /**
     * Satu baris daftar pasien. Bentuk kolomnya mengikuti apa yang dirender
     * patients.html dan dipakai kartu ringkasan unit.
     *
     * @param  iterable<int, Diagnosis>  $diagnoses
     * @param  array{asmed: bool, nursingCare: bool, diagnosis: bool, procedure: bool}  $completion
     * @return array<string, mixed>
     */
    private function listRow(Encounter $encounter, ?Observation $latest, iterable $diagnoses, array $completion, int $medicationCount): array
    {
        $patient = $encounter->patient;

        $score = $latest === null ? null : (int) $latest->ews_total;
        $risk = $latest?->ews_risk?->value ?? EwsScoringService::RISK_NONE;
        $at = $latest?->recorded_on;

        $codes = [];
        $texts = [];

        foreach ($diagnoses as $diagnosis) {
            $codes[] = $diagnosis->code;
            $texts[] = (string) $diagnosis->text;
        }

        $allergies = ClinicalFormat::dash($patient?->allergies);
        $hasAllergies = $patient !== null && $patient->hasAllergies();
        $alert = trim((string) ($encounter->allergy_alert ?? ''));

        return [
            'encounterId' => (string) $encounter->encounter_id,
            'patientId' => (string) $patient?->patient_id,
            'mrn' => ClinicalFormat::dash($patient?->mrn),
            'name' => ClinicalFormat::dash($patient?->name),
            'initials' => $patient?->initials ?? '??',
            'sex' => $patient?->sex?->value,
            'sexLabel' => ClinicalFormat::dash($patient?->sex?->label()),
            'age' => $patient?->age,
            'demographics' => $patient?->demographics ?? ClinicalFormat::EMPTY,
            'payment' => $patient?->payment?->value,
            'paymentLabel' => ClinicalFormat::dash($patient?->payment?->label()),
            'unit' => ClinicalFormat::dash($encounter->unit),
            'bed' => ClinicalFormat::dash($encounter->bed),
            'unitBed' => $encounter->unit_bed,
            'admittedAt' => ClinicalFormat::iso($encounter->admitted_at),
            'admittedAtLabel' => ClinicalFormat::dateTimeLabel($encounter->admitted_at),
            'losDays' => (int) $encounter->length_of_stay_days,
            'dpjp' => ClinicalFormat::dash($encounter->attending_physician),
            'status' => $encounter->status?->value,
            'statusLabel' => ClinicalFormat::dash($encounter->status?->label()),
            'diagnosisSummary' => $this->diagnosisSummary($encounter, $texts),
            'diagnosisCodes' => array_values(array_filter($codes, fn (?string $code) => $code !== null && $code !== '')),
            'allergies' => $allergies,
            'allergyAlert' => $alert !== '' ? $alert : ($hasAllergies ? $allergies : null),
            'hasAllergies' => $hasAllergies,
            'latestObsAt' => ClinicalFormat::iso($at),
            'latestObsAtLabel' => ClinicalFormat::dateTimeLabel($at),
            'latestObsBy' => ClinicalFormat::dash($latest?->recorded_by),
            'latestEws' => $score,
            'latestRisk' => $risk,
            'latestRiskLabel' => $score === null ? ClinicalFormat::EMPTY : $this->ews->riskLabel($risk),
            'latestRiskTone' => $this->ews->riskTone($risk),
            'badgeClasses' => $this->ews->badgeClasses($score ?? 0, $risk),
            'atRisk' => in_array($risk, ['high', 'emergency'], true),
            'medicationCount' => $medicationCount,
            'completion' => $completion,
            'completionCount' => count(array_filter($completion)),
        ];
    }

    /**
     * Observasi terbaru per episode. Diambil dengan satu kueri untuk seluruh
     * episode yang diminta lalu dipilih per episode di memori, karena itu
     * bentuk yang portabel di SQLite, MySQL, dan PostgreSQL tanpa window
     * function.
     *
     * @param  array<int, int>  $encounterIds
     * @return array<int, Observation>
     */
    private function latestObservationsByEncounter(array $encounterIds): array
    {
        $observations = Observation::query()
            ->whereIn('encounter_id', $encounterIds)
            ->latestFirst()
            ->get(['id', 'encounter_id', 'observation_date', 'observation_time', 'ews_total', 'ews_risk', 'recorded_by']);

        $latest = [];

        foreach ($observations as $observation) {
            $latest[$observation->encounter_id] ??= $observation;
        }

        return $latest;
    }

    /**
     * Diagnosis per episode, satu kueri untuk seluruh episode yang diminta.
     *
     * @param  array<int, int>  $encounterIds
     * @return array<int, iterable<int, Diagnosis>>
     */
    private function diagnosesByEncounter(array $encounterIds): array
    {
        return Diagnosis::query()
            ->whereIn('encounter_id', $encounterIds)
            ->orderBy('id')
            ->get()
            ->groupBy('encounter_id')
            ->all();
    }

    /**
     * Jumlah koreksi pemberian obat per episode, satu kueri agregat.
     *
     * @param  array<int, int>  $encounterIds
     * @return array<int, int>
     */
    private function medicationCounts(array $encounterIds): array
    {
        return ObservationMedication::query()
            ->join('observations', 'observations.id', '=', 'observation_medications.observation_id')
            ->whereIn('observations.encounter_id', $encounterIds)
            ->selectRaw('observations.encounter_id as encounter_id, COUNT(*) as total')
            ->groupBy('observations.encounter_id')
            ->pluck('total', 'encounter_id')
            ->map(fn ($value) => (int) $value)
            ->all();
    }

    /**
     * Ringkasan diagnosis untuk kartu daftar pasien.
     *
     * Memakai kolom denormalisasi `encounters.diagnosis_summary` bila sudah
     * diisi (itu memang tujuan kolom tersebut), kalau tidak dirakit dari
     * diagnosis episode dengan pemotongan yang sama seperti patients.html:
     * dua diagnosis pertama, lalu sisanya ditandai (+n).
     *
     * @param  array<int, string>  $texts
     */
    private function diagnosisSummary(Encounter $encounter, array $texts): string
    {
        $cached = ClinicalFormat::dash($encounter->diagnosis_summary);

        if ($cached !== ClinicalFormat::EMPTY) {
            return $cached;
        }

        if ($texts === []) {
            return ClinicalFormat::EMPTY;
        }

        $summary = implode('; ', array_slice($texts, 0, 2));

        if (count($texts) > 2) {
            $summary .= ' (+'.(count($texts) - 2).')';
        }

        return $summary;
    }

    /**
     * @return array<string, mixed>
     */
    private function patientFields(Encounter $encounter, ?Patient $patient): array
    {
        return [
            'patientId' => $patient?->patient_id,
            'mrn' => ClinicalFormat::dash($patient?->mrn),
            'name' => ClinicalFormat::dash($patient?->name),
            'initials' => $patient?->initials ?? '??',
            'sex' => $patient?->sex?->value,
            'sexLabel' => ClinicalFormat::dash($patient?->sex?->label()),
            'age' => $patient?->age,
            'demographics' => $patient?->demographics ?? ClinicalFormat::EMPTY,
            'birthDate' => $patient?->birth_date?->format('Y-m-d'),
            'birthDateLabel' => ClinicalFormat::dateLabel($patient?->birth_date),
            'address' => $patient?->address,
            'phone' => $patient?->phone,
            'bloodType' => ClinicalFormat::dash($patient?->blood_type),
            'allergies' => ClinicalFormat::dash($patient?->allergies),
            'hasAllergies' => $patient !== null && $patient->hasAllergies(),
            'payment' => $patient?->payment?->value,
            'paymentLabel' => ClinicalFormat::dash($patient?->payment?->label()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function encounterFields(Encounter $encounter): array
    {
        return [
            'encounterId' => (string) $encounter->encounter_id,
            'unit' => ClinicalFormat::dash($encounter->unit),
            'bed' => ClinicalFormat::dash($encounter->bed),
            'unitBed' => $encounter->unit_bed,
            'admittedAt' => ClinicalFormat::iso($encounter->admitted_at),
            'admittedAtLabel' => ClinicalFormat::dateTimeLabel($encounter->admitted_at),
            'dischargedAt' => ClinicalFormat::iso($encounter->discharged_at),
            'losDays' => (int) $encounter->length_of_stay_days,
            'attendingPhysician' => ClinicalFormat::dash($encounter->attending_physician),
            'dpjp' => ClinicalFormat::dash($encounter->attending_physician),
            'status' => $encounter->status?->value,
            'statusLabel' => ClinicalFormat::dash($encounter->status?->label()),
            'diagnosisSummary' => ClinicalFormat::dash($encounter->diagnosis_summary),
            'allergyAlert' => $encounter->allergy_alert,
            'notes' => $encounter->notes,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function diagnosisRows(Encounter $encounter): array
    {
        $rows = [];

        foreach ($encounter->getRelation('diagnoses') as $diagnosis) {
            $rows[] = [
                'id' => (int) $diagnosis->getKey(),
                'type' => $diagnosis->type?->value,
                'typeLabel' => $diagnosis->type?->label() ?? ClinicalFormat::EMPTY,
                'text' => ClinicalFormat::dash($diagnosis->text),
                'code' => $diagnosis->code,
                'isPrimary' => $diagnosis->type === DiagnosisType::UTAMA,
                'author' => $diagnosis->author,
            ];
        }

        return $rows;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function procedureRows(Encounter $encounter): array
    {
        $rows = [];

        foreach ($encounter->getRelation('procedures') as $procedure) {
            $rows[] = [
                'id' => (int) $procedure->getKey(),
                'name' => ClinicalFormat::dash($procedure->name),
                'code' => $procedure->code,
                'performedAt' => $procedure->performed_at?->format('Y-m-d'),
                'performedAtLabel' => ClinicalFormat::dateLabel($procedure->performed_at),
                'operator' => ClinicalFormat::dash($procedure->operator),
            ];
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function asmedFields(?Asmed $asmed): ?array
    {
        if ($asmed === null) {
            return null;
        }

        $vitals = $asmed->vitals;

        return [
            'id' => (int) $asmed->getKey(),
            'complaint' => $asmed->complaint,
            'history' => $asmed->history,
            'physicalExam' => $asmed->physical_exam,
            'vitals' => is_array($vitals) ? implode(', ', array_map(
                fn ($value, $key) => $key.': '.(is_scalar($value) ? (string) $value : json_encode($value)),
                $vitals,
                array_keys($vitals)
            )) : $vitals,
            'plan' => $asmed->plan,
            'examiner' => ClinicalFormat::dash($asmed->examiner),
            'examinedAt' => ClinicalFormat::iso($asmed->examined_at),
            'examinedAtLabel' => ClinicalFormat::dateTimeLabel($asmed->examined_at),
            'hasPlan' => $asmed->hasPlan(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function nursingCareFields(?NursingCare $nursing): ?array
    {
        if ($nursing === null) {
            return null;
        }

        return [
            'id' => (int) $nursing->getKey(),
            'assessment' => $nursing->assessment,
            'problems' => $nursing->problems,
            'interventions' => $nursing->interventions,
            'nurse' => ClinicalFormat::dash($nursing->nurse),
            'shift' => $nursing->shift?->value,
            'shiftLabel' => ClinicalFormat::dash($nursing->shift?->label()),
            'recordedAt' => ClinicalFormat::iso($nursing->recorded_at),
            'recordedAtLabel' => ClinicalFormat::dateTimeLabel($nursing->recorded_at),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Jalur tulis - admisi baru
    |--------------------------------------------------------------------------
    |
    | Port dari phase1: patients.html savePatient() memanggil store.addPatient()
    | (pasien baru + encounter pertamanya) atau store.addEncounter() (encounter
    | baru pada pasien yang sudah ada) - percabangan ditentukan oleh apakah
    | No. RM sudah terdaftar. Percabangan itu dipertahankan persis:
    |
    |   - No. RM BARU     -> buat baris Patient beserta encounter pertamanya.
    |   - No. RM TERDAFTAR -> HANYA buat Encounter baru. Field tingkat pasien
    |     dari form (nama, jenis kelamin, tanggal lahir, alergi, pembiayaan)
    |     dibuang; data pasien yang sudah ada tidak disentuh.
    |
    | Keduanya menulis unit, bed, tanggal masuk, DPJP, status AKTIF, daftar
    | diagnosa, daftar prosedur, satu baris ASMED, dan satu baris asuhan
    | keperawatan; episode observasinya sengaja kosong.
    |
    | Letak method ini sengaja di dalam AdmissionService (bukan kelas
    | terpisah) karena jalur tulis harus serasi dengan jalur baca yang sudah ada
    | di file sama: getProfile()/getCppt()/getBannerPayload()/listPatients()
    | membaca kolom denormalisasi encounters.diagnosis_summary dan
    | encounters.allergy_alert, serta completion yang dihitung dari keberadaan
    | baris anak.
    | Kontrak baca yang dikodekan di docs/FRONTEND_CONTRACT.md tidak berubah -
    | hanya ada method baru.
    |
    */

    /**
     * Buat satu admisi baru.
     *
     * Semua penulisan terjadi dalam SATU DB::transaction, jadi episode tidak
     * pernah terlihat setengah jadi di census.
     *
     * @param  array<string, mixed>  $data  payload hasil validasi controller
     * @return array{patient: Patient, encounter: Encounter, isNewPatient: bool}
     */
    public function createAdmission(array $data, User $user): array
    {
        return DB::transaction(function () use ($data, $user): array {
            $patient = Patient::query()->where('mrn', trim((string) $data['mrn']))->first();
            $isNewPatient = $patient === null;

            if ($isNewPatient) {
                $patient = $this->createPatient($data);
            }

            $encounter = $this->createEncounter($patient, $data);

            $this->writeDiagnoses($encounter, $data['diagnoses'] ?? [], $user);
            $this->writeProcedures($encounter, $data['procedures'] ?? []);
            $this->writeAsmed($encounter, $data['asmed'] ?? []);
            $this->writeNursingCare($encounter, $data['nursing_care'] ?? [], $user);

            /*
             * Relasi sengaja dibuang: model yang dikembalikan masih menyimpan
             * cache "belum ada" dari sebelum anak-anaknya dibuat, dan
             * completion harus dihitung dari keberadaan baris yang benar-benar
             * ada di database.
             */
            $encounter->unsetRelation('asmed')
                ->unsetRelation('diagnoses')
                ->unsetRelation('procedures')
                ->unsetRelation('nursingCares');

            return [
                'patient' => $patient,
                'encounter' => $encounter,
                'isNewPatient' => $isNewPatient,
            ];
        });
    }

    /**
     * Baris master pasien baru. Hanya dipakai pada cabang No. RM baru.
     *
     * Kolom patients.sex dan patients.payment NOT NULL dengan default
     * 'Laki-Laki' / 'Umum' (lihat create_patients_table), jadi form yang
     * membiarkan pilihan kosong tidak bisa disimpan apa adanya: nilai bawaan
     * kolom dipakai. Nama, tanggal lahir, dan alergi tetap boleh kosong.
     *
     * @param  array<string, mixed>  $data
     */
    private function createPatient(array $data): Patient
    {
        $mrn = trim((string) $data['mrn']);

        $patient = new Patient;

        // patient_id mengikuti format seeder: "patient-" + No. RM
        // (PatientEncounterSeeder memakai patient-159853, patient-162210, ...).
        $patient->patient_id = 'patient-'.$mrn;
        $patient->mrn = $mrn;
        $patient->name = trim((string) $data['name']);
        $patient->birth_date = $this->blankToNull($data['birth_date'] ?? null);
        $patient->allergies = $this->blankToNull($data['allergies'] ?? null);
        $patient->address = $this->blankToNull($data['address'] ?? null);
        $patient->blood_type = $this->blankToNull($data['blood_type'] ?? null);
        $patient->is_active = true;
        $patient->sex = Sex::tryFrom(trim((string) ($data['sex'] ?? ''))) ?? Sex::LAKI_LAKI;
        $patient->payment = PaymentType::tryFrom(trim((string) ($data['payment'] ?? ''))) ?? PaymentType::BPJS_PBI;

        $patient->save();

        return $patient;
    }

    /**
     * Baris episode untuk admisi ini, baik pasien baru maupun pasien lama.
     *
     * @param  array<string, mixed>  $data
     */
    private function createEncounter(Patient $patient, array $data): Encounter
    {
        $admittedAt = Carbon::parse(trim((string) $data['admitted_at']));
        $diagnoses = $this->collectDiagnoses($data['diagnoses'] ?? []);

        $encounter = new Encounter;

        $encounter->encounter_id = $this->nextEncounterId($patient->mrn, $admittedAt);
        $encounter->patient_id = $patient->getKey();
        $encounter->unit = trim((string) $data['unit']);
        $encounter->bed = trim((string) $data['bed']);
        $encounter->admitted_at = $admittedAt;
        $encounter->attending_physician = $this->blankToNull($data['attending_physician'] ?? null);
        $encounter->status = EncounterStatus::AKTIF;

        // Sama seperti PatientEncounterSeeder: seluruh teks diagnosa episodic
        // digabung dengan ", " pada kolom denormalisasi ini.
        $encounter->diagnosis_summary = $this->joinDiagnosisSummary($diagnoses);

        // allergy_alert disalin dari master pasien, bukan dari form: pada
        // cabang No. RM ganda isi form diabaikan dan master pasien tidak
        // boleh ikut berubah.
        $encounter->allergy_alert = $patient->allergies;
        $encounter->latest_observation_at = null;

        $encounter->save();

        return $encounter;
    }

    /**
     * encounter_id "enc-<mrn>-<YYYYMMDD>", mengikuti pola seeder
     * (enc-159853-icu-20260906) untuk bagian yang bisa diturunkan otomatis.
     *
     * Admisi kedua untuk pasien yang sama pada tanggal yang sama mendapat
     * sufiks -2, -3, dan seterusnya agar tetap unik.
     */
    private function nextEncounterId(string $mrn, Carbon $admittedAt): string
    {
        $base = 'enc-'.$mrn.'-'.$admittedAt->format('Ymd');

        if (! Encounter::query()->where('encounter_id', $base)->exists()) {
            return $base;
        }

        for ($suffix = 2; $suffix < 100; $suffix++) {
            $candidate = $base.'-'.$suffix;

            if (! Encounter::query()->where('encounter_id', $candidate)->exists()) {
                return $candidate;
            }
        }

        return $base.'-'.Str::lower(Str::random(6));
    }

    /**
     * Diagnosis episode. Baris dengan nama diagnosa kosong dilewati, persis
     * seperti collectDiagnoses() di patients.html.
     *
     * `author` tidak ada di prototype; kolomnya nullable dan diisi nama
     * petugas yang menyimpan admisi, supaya konsisten dengan seeder yang juga
     * mengisi kolom ini.
     */
    private function writeDiagnoses(Encounter $encounter, mixed $rows, User $user): void
    {
        foreach ($this->collectDiagnoses($rows) as $diagnosis) {
            Diagnosis::query()->create([
                'encounter_id' => $encounter->getKey(),
                'type' => $diagnosis['type'],
                'text' => $diagnosis['text'],
                'code' => $diagnosis['code'],
                'author' => $user->name,
            ]);
        }
    }

    /**
     * Prosedur episode. Baris tanpa nama dilewati, sama seperti
     * collectProcedures() di patients.html.
     *
     * Kolom procedures.performed_at NOT NULL, jadi baris yang tanggalnya
     * kosong memakai tanggal masuk episode.
     */
    private function writeProcedures(Encounter $encounter, mixed $rows): void
    {
        $fallbackDate = $encounter->admitted_at instanceof Carbon
            ? $encounter->admitted_at->copy()->startOfDay()
            : now()->startOfDay();

        foreach ($this->collectProcedures($rows) as $procedure) {
            Procedure::query()->create([
                'encounter_id' => $encounter->getKey(),
                'name' => $procedure['name'],
                'code' => $procedure['code'],
                'performed_at' => $procedure['performed_at'] ?? $fallbackDate,
                'operator' => $procedure['operator'],
            ]);
        }
    }

    /**
     * Satu baris ASMED per episode (kolom encounter_id-nya unik).
     *
     * Baris ini SELALU dibuat, termasuk saat panel ASMED dibiarkan kosong,
     * karena buildEncounter() di app-context.js selalu memasang objek asmed
     * sehingga computeCompletion().asmed bernilai true. itu yang membuat badge
     * "Data Sudah Diisi" di profil konsisten dengan prototype.
     *
     * `vitals`: kolomnya json, sedangkan prototype menyimpan satu string
     * gabungan (lihat buildPayload() di patients.html: vital TD / HR / RR /
     * Suhu / SpO2 digabung dengan " / "). Supaya serasi dengan
     * PatientEncounterSeeder, string gabungan disimpan pada kunci `ringkasan`
     * dan tiap vital pada kuncinya sendiri. Vital yang kosong TIDAK ditulis,
     * dan bila kelima vital kosong kolomnya disimpan null - sama dengan
     * `|| null` di prototype - supaya asmedFields() tidak pernah merender
     * "tekanan_darah: -".
     *
     * `examined_at` diambil dari tanggal masuk episode: pemeriksaan ASMED
     * adalah bagian dari admisi itu sendiri, jadi tidak bergantung jam server.
     */
    private function writeAsmed(Encounter $encounter, mixed $row): void
    {
        $row = is_array($row) ? $row : [];

        $vitals = [
            'tekanan_darah' => trim((string) ($row['vitals_td'] ?? '')),
            'nadi' => trim((string) ($row['vitals_hr'] ?? '')),
            'respirasi' => trim((string) ($row['vitals_rr'] ?? '')),
            'suhu' => trim((string) ($row['vitals_suhu'] ?? '')),
            'saturasi' => trim((string) ($row['vitals_spo2'] ?? '')),
        ];

        Asmed::query()->create([
            'encounter_id' => $encounter->getKey(),
            'complaint' => $this->blankToNull($row['complaint'] ?? null),
            'history' => $this->blankToNull($row['history'] ?? null),
            'physical_exam' => $this->blankToNull($row['physical_exam'] ?? null),
            'vitals' => $this->vitalsPayload($vitals),
            'plan' => $this->blankToNull($row['plan'] ?? null),
            'examiner' => $this->blankToNull($row['examiner'] ?? null),
            'examined_at' => $encounter->admitted_at,
        ]);
    }

    /**
     * Satu baris asuhan keperawatan / kebidanan, ditandai sebagai yang terbaru.
     *
     * `is_latest` = true karena episode ini baru saja dibuat, jadi baris ini
     * otomatis menjadi asuhan terakhir. Baris berikutnya akan lewat
     * latestOfMany('recorded_at') yang membaca recorded_at.
     *
     * Kolom nursing_cares.nurse NOT NULL. prototype membiarkan kolom ini kosong
     * (tidak ada database user di sana), jadi nama petugas yang mengosongkan
     * kolom diisi identitas petugas yang sedang menyimpan admisi.
     */
    private function writeNursingCare(Encounter $encounter, mixed $row, User $user): void
    {
        $row = is_array($row) ? $row : [];

        $nurse = $this->blankToNull($row['nurse'] ?? null) ?? $user->name;

        NursingCare::query()->create([
            'encounter_id' => $encounter->getKey(),
            'assessment' => $this->blankToNull($row['assessment'] ?? null),
            'problems' => $this->blankToNull($row['problems'] ?? null),
            'interventions' => $this->blankToNull($row['interventions'] ?? null),
            'nurse' => $nurse,
            'shift' => NursingShift::tryFrom(trim((string) ($row['shift'] ?? ''))),
            'recorded_at' => $encounter->admitted_at,
            'is_latest' => true,
        ]);
    }

    /**
     * Normalisasi baris diagnosa dari payload form.
     *
     * @return array<int, array{type: DiagnosisType, text: string, code: string|null}>
     */
    private function collectDiagnoses(mixed $rows): array
    {
        $collected = [];

        foreach ((array) $rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $text = trim((string) ($row['text'] ?? ''));

            if ($text === '') {
                continue;
            }

            $collected[] = [
                'type' => DiagnosisType::tryFrom(trim((string) ($row['type'] ?? ''))) ?? DiagnosisType::UTAMA,
                'text' => $text,
                'code' => $this->blankToNull($row['code'] ?? null),
            ];
        }

        return $collected;
    }

    /**
     * Normalisasi baris prosedur dari payload form.
     *
     * @return array<int, array{name: string, performed_at: Carbon|null, operator: string|null, code: string|null}>
     */
    private function collectProcedures(mixed $rows): array
    {
        $collected = [];

        foreach ((array) $rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $name = trim((string) ($row['name'] ?? ''));

            if ($name === '') {
                continue;
            }

            $collected[] = [
                'name' => $name,
                'performed_at' => $this->toDate($row['performed_at'] ?? null),
                'operator' => $this->blankToNull($row['operator'] ?? null),
                'code' => $this->blankToNull($row['code'] ?? null),
            ];
        }

        return $collected;
    }

    /**
     * Ringkasan diagnosis untuk kolom encounters.diagnosis_summary.
     *
     * @param  array<int, array{type: DiagnosisType, text: string, code: string|null}>  $diagnoses
     */
    private function joinDiagnosisSummary(array $diagnoses): string
    {
        return implode(', ', array_map(
            fn (array $diagnosis): string => $diagnosis['text'],
            $diagnoses
        ));
    }

    /**
     * Bentuk json untuk kolom asmeds.vitals.
     *
     * @param  array<string, string>  $vitals
     * @return array<string, string>|null
     */
    private function vitalsPayload(array $vitals): ?array
    {
        $filled = array_values(array_filter($vitals, fn (string $value): bool => $value !== ''));

        if ($filled === []) {
            return null;
        }

        // `ringkasan` = string gabungan vital, sama dengan hasil
        // filter(Boolean).join(' / ') pada prototype.
        $payload = $vitals;

        foreach ($payload as $key => $value) {
            if ($value === '') {
                unset($payload[$key]);
            }
        }

        $payload['ringkasan'] = implode(' / ', $filled);

        return $payload;
    }

    private function toDate(mixed $value): ?Carbon
    {
        if ($value === null || $value === '' || ! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $parsed = ClinicalFormat::parse($value);

        return $parsed?->copy()->startOfDay();
    }

    /**
     * Teks kosong (setelah di-trim) menjadi null, bukan string kosong.
     */
    private function blankToNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    /**
     * Tim perawatan, tiga baris yang dirender kartu "Tim perawatan" di profil.
     *
     * @param  array<string, mixed>|null  $latest
     * @return array<int, array{key: string, label: string, name: string}>
     */
    private function careTeam(Encounter $encounter, ?Asmed $asmed, ?NursingCare $nursing, ?array $latest): array
    {
        return [
            [
                'key' => 'dpjp',
                'label' => 'DPJP',
                'name' => ClinicalFormat::dash($encounter->attending_physician),
            ],
            [
                'key' => 'lastNurse',
                'label' => 'Perawat terakhir',
                'name' => ClinicalFormat::dash(
                    $latest !== null && ($latest['recordedBy'] ?? null) !== ClinicalFormat::EMPTY
                        ? $latest['recordedBy']
                        : $nursing?->nurse
                ),
            ],
            [
                'key' => 'lastExaminedBy',
                'label' => 'Pemeriksa terakhir',
                'name' => ClinicalFormat::dash($asmed?->examiner),
            ],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Jalur tulis - modul CPPT
    |--------------------------------------------------------------------------
    |
    | Sama seperti createAdmission() di atas, method tulis ini sengaja DI DALAM
    | AdmissionService, bukan kelas terpisah: getProfile(), getCppt(),
    | getBannerPayload(), dan listPatients() membaca kolom denormalisasi yang
    | sama (encounters.diagnosis_summary, encounters.allergy_alert) dan
    | `completion` yang dihitung dari keberadaan baris anak. Menulis diagnosis
    | tanpa menyegarkan diagnosis_summary akan merusak kartu census DAN banner
    | identitas tanpa error yang terlihat.
    |
    | LIMA OPERASI, masing-masing di dalam SATU DB::transaction:
    |   storeMedicalNote()  -> medical_notes   (catatan progres S/O/A/P)
    |   storeAsmed()        -> asmeds          (upsert, unik per encounter)
    |   storeNursingCare()  -> nursing_cares   (is_latest bergantian)
    |   storeDiagnosis()    -> diagnoses       (+ segarkan diagnosis_summary)
    |   storeProcedure()    -> procedures
    |
    | Yang TIDAK boleh masuk dari body request adalah identitas penulis.
    | author_name, author_role, dan author_specialty pada medical_notes SELALU
    | diturunkan dari user yang sedang login, karena dokumen CPPT harus tercatat
    | atas nama petugas yang benar-benar menekan tombol simpan.
    |
    */

    /**
     * Isi keempat panel tab (ASMED, Asuhan, Diagnosa, Prosedur) untuk halaman
     * CPPT.
     *
     * Bentuk tiap kunci persis sama dengan yang dipakai getProfile() - keduanya
     * memanggil pemeta baris yang sama (asmedFields / nursingCareFields /
     * diagnosisRows / procedureRows), jadi panel tab dan kartu profil tidak
     * pernah menampilkan data yang berbeda.
     *
     * Relasi sudah dimuat oleh getCppt() bila controller memanggilnya lebih
     * dulu; loadMissing() di sini membuat method ini aman dipanggil sendiri.
     *
     * @return array{asmed: array<string, mixed>|null, nursingCare: array<string, mixed>|null, diagnoses: array<int, array<string, mixed>>, procedures: array<int, array<string, mixed>>}
     */
    public function getCpptPanels(Encounter $encounter): array
    {
        $encounter->loadMissing([
            'asmed',
            'diagnoses',
            'procedures',
            'nursingCares' => fn ($query) => $query->limit(1),
        ]);

        return [
            'asmed' => $this->asmedFields($encounter->getRelation('asmed')),
            'nursingCare' => $this->nursingCareFields($encounter->getRelation('nursingCares')->first()),
            'diagnoses' => $this->diagnosisRows($encounter),
            'procedures' => $this->procedureRows($encounter),
        ];
    }

    /**
     * Simpan satu catatan progres CPPT (S/O/A/P).
     *
     * `order_number` = max(order_number) yang ada + 1 untuk episode ini, supaya
     * pengurutannya total dan deterministik. Relasi Encounter::medicalNotes()
     * mengurutkan noted_at lalu order_number, jadi dua catatan pada menit yang
     * sama tetap punya urutan yang pasti dan tidak pernah seri.
     *
     * @param  array<string, mixed>  $data  payload hasil validasi controller
     */
    public function storeMedicalNote(Encounter $encounter, array $data, User $user): MedicalNote
    {
        return DB::transaction(function () use ($encounter, $data, $user): MedicalNote {
            $note = new MedicalNote;

            $note->encounter_id = $encounter->getKey();
            $note->author_name = (string) $user->name;
            $note->author_role = $user->role?->value;
            $note->author_specialty = $this->blankToNull($user->specialty);
            $note->note_type = $this->blankToNull($data['note_type'] ?? null) ?? 'CPPT';
            $note->shift = NursingShift::tryFrom(trim((string) ($data['shift'] ?? '')));
            $note->subjective = $this->blankToNull($data['subjective'] ?? null);
            $note->objective = $this->blankToNull($data['objective'] ?? null);
            $note->assessment = $this->blankToNull($data['assessment'] ?? null);
            $note->plan = $this->blankToNull($data['plan'] ?? null);
            $note->noted_at = ClinicalFormat::parse($data['noted_at'] ?? null) ?? now();
            $note->order_number = $this->nextNoteOrder($encounter);

            $note->save();

            return $note;
        });
    }

    /**
     * Simpan ASMED episode ini.
     *
     * UPSERT, bukan insert: kolom asmeds.encounter_id UNIQUE, jadi episode hanya
     * boleh punya satu ASMED. Simpan ulang berarti menyunting baris itu -
     * persis apa yang dilakukan tombol "Edit ASMED" di prototype.
     *
     * `examiner` yang kosong diisi nama petugas yang sedang menyimpan, padanan
     * `|| 'Petugas'` di prototype yang tidak punya database user.
     *
     * @param  array<string, mixed>  $data
     */
    public function storeAsmed(Encounter $encounter, array $data, User $user): Asmed
    {
        return DB::transaction(function () use ($encounter, $data, $user): Asmed {
            return Asmed::query()->updateOrCreate(
                ['encounter_id' => $encounter->getKey()],
                [
                    'complaint' => $this->blankToNull($data['complaint'] ?? null),
                    'history' => $this->blankToNull($data['history'] ?? null),
                    'physical_exam' => $this->blankToNull($data['physical_exam'] ?? null),
                    'vitals' => $this->vitalsFromSummary((string) ($data['vitals'] ?? '')),
                    'plan' => $this->blankToNull($data['plan'] ?? null),
                    'examiner' => $this->blankToNull($data['examiner'] ?? null) ?? $user->name,
                    'examined_at' => ClinicalFormat::parse($data['examined_at'] ?? null) ?? now(),
                ],
            );
        });
    }

    /**
     * Simpan satu baris asuhan keperawatan / kebidanan.
     *
     * Asuhan bukan unik per encounter - diinput ulang tiap shift. Karena itu
     * flag `is_latest` dibalik: baris lama yang masih bertanda true dimatikan
     * lebih dulu, lalu baris baru ditandai true di dalam transaksi yang sama.
     * Encounter::latestNursingCare() (latestOfMany) dan
     * Encounter::getCompletionAttribute() membaca kedua sumber itu, jadi kalau
     * flag dibalik di luar transaksi, ada jendela waktu di mana tidak ada
     * baris "terbaru" sama sekali.
     *
     * @param  array<string, mixed>  $data
     */
    public function storeNursingCare(Encounter $encounter, array $data, User $user): NursingCare
    {
        return DB::transaction(function () use ($encounter, $data, $user): NursingCare {
            NursingCare::query()
                ->where('encounter_id', $encounter->getKey())
                ->where('is_latest', true)
                ->update(['is_latest' => false]);

            $nursing = new NursingCare;

            $nursing->encounter_id = $encounter->getKey();
            $nursing->assessment = $this->blankToNull($data['assessment'] ?? null);
            $nursing->problems = $this->blankToNull($data['problems'] ?? null);
            $nursing->interventions = $this->blankToNull($data['interventions'] ?? null);
            $nursing->nurse = $this->blankToNull($data['nurse'] ?? null) ?? $user->name;
            $nursing->shift = NursingShift::tryFrom(trim((string) ($data['shift'] ?? '')));
            $nursing->recorded_at = ClinicalFormat::parse($data['recorded_at'] ?? null) ?? now();
            $nursing->is_latest = true;

            $nursing->save();

            return $nursing;
        });
    }

    /**
     * Tambahkan satu diagnosis (ICD-10) ke episode ini.
     *
     * Setelah baris masuk, kolom denormalisasi encounters.diagnosis_summary
     * disegarkan ulang. Kartu census di /pasien (listRow) dan banner identitas
     * (getBannerPayload lewat encounterFields) membaca kolom itu, jadi
     * membiarkannya basi akan menampilkan diagnosis yang baru ditambahkan
     * seolah tidak ada. Ringkasannya disusun dengan joinDiagnosisSummary() yang
     * sama persis dengan PatientEncounterSeeder: seluruh teks diagnosis
     * episode digabung dengan ", ".
     *
     * @param  array<string, mixed>  $data
     */
    public function storeDiagnosis(Encounter $encounter, array $data, User $user): Diagnosis
    {
        return DB::transaction(function () use ($encounter, $data, $user): Diagnosis {
            $diagnosis = Diagnosis::query()->create([
                'encounter_id' => $encounter->getKey(),
                'type' => DiagnosisType::tryFrom(trim((string) ($data['type'] ?? ''))) ?? DiagnosisType::UTAMA,
                'text' => trim((string) ($data['text'] ?? '')),
                'code' => $this->blankToNull($data['code'] ?? null),
                'author' => $user->name,
            ]);

            $this->refreshDiagnosisSummary($encounter);

            return $diagnosis;
        });
    }

    /**
     * Tambahkan satu prosedur / tindakan (ICD-9-CM) ke episode ini.
     *
     * PROSEDUR TIDAK IKUT MEMBARUI diagnosis_summary. Kolom itu disusun seeder
     * hanya dari baris `diagnoses` (lihat PatientEncounterSeeder::seedEncounter),
     * dan tidak satu pun pembacanya - kartu census, banner, panel Prosedur -
     * menambah nama tindakan ke sana. Mengisinya dengan isi lain akan membuat
     * ringkasan yang tidak bisa ditelusuri kembali ke diagnosis asalnya.
     *
     * @param  array<string, mixed>  $data
     */
    public function storeProcedure(Encounter $encounter, array $data): Procedure
    {
        return DB::transaction(function () use ($encounter, $data): Procedure {
            return Procedure::query()->create([
                'encounter_id' => $encounter->getKey(),
                'name' => trim((string) ($data['name'] ?? '')),
                'code' => $this->blankToNull($data['code'] ?? null),
                // procedures.performed_at NOT NULL. Tanggal yang kosong (mis.
                // form dikirim tanpa JavaScript) mengikuti tanggal masuk episode,
                // sama seperti writeProcedures() pada jalur admisi.
                'performed_at' => $this->toDate($data['performed_at'] ?? null) ?? $this->fallbackDate($encounter),
                'operator' => $this->blankToNull($data['operator'] ?? null),
            ]);
        });
    }

    /**
     * Nomor urutan berikutnya untuk catatan CPPT episode ini.
     *
     * Episode tanpa catatan apa pun menghasilkan 1, bukan 0.
     */
    private function nextNoteOrder(Encounter $encounter): int
    {
        $highest = MedicalNote::query()
            ->where('encounter_id', $encounter->getKey())
            ->max('order_number');

        return ((int) $highest) + 1;
    }

    /**
     * Susun ulang encounters.diagnosis_summary dari baris diagnosis episode.
     *
     * Dipanggil di dalam transaksi yang sama dengan penulisan diagnosa, supaya
     * tidak ada keadaan setengah jadi di mana diagnosis sudah ada tapi
     * ringkasannya belum.
     */
    private function refreshDiagnosisSummary(Encounter $encounter): void
    {
        $rows = [];

        foreach (Diagnosis::query()
            ->where('encounter_id', $encounter->getKey())
            ->orderBy('id')
            ->get() as $diagnosis) {
            $rows[] = [
                'type' => $diagnosis->type,
                'text' => (string) $diagnosis->text,
                'code' => $diagnosis->code,
            ];
        }

        $encounter->diagnosis_summary = $this->joinDiagnosisSummary($rows);

        $encounter->save();

        // Relasi yang sempat dimuat sudah tidak lagi benar; buang supaya pemanggil
        // berikutnya pada instance yang sama tidak membaca data basi.
        $encounter->unsetRelation('diagnoses');
    }

    /**
     * Bentuk json untuk kolom asmeds.vitals dari SATU field teks bebas.
     *
     * Formulir ASMED pada tab CPPT punya satu textarea "Tanda Vital", sedangkan
     * PatientEncounterSeeder menyimpan json: string gabungan pada `ringkasan`
     * plus tiap vital pada kuncinya sendiri. Supaya kedua jalur menulis kolom
     * yang sama, teks yang diketik dipakai apa adanya sebagai `ringkasan`, lalu
     * vital yang polanya tidak ambigu ikut dipecah ke tekanan_darah / nadi /
     * respirasi / suhu / saturasi.
     *
     * Pencocokan sengaja konservatif:
     *  - Vital yang tidak ditemukan TIDAK ditulis. Tidak ada nilai tebakan di
     *    dokumen klinis, dan kunci yang terisi string kosong akan dirender
     *    `tekanan_darah: -` oleh asmedFields().
     *  - `nadi` dan `respirasi` HANYA diambil dari teks yang melabelinya
     *    ("HR 118x/mnt", "RR 24x/mnt"). Tanpa label keduanya sama-sama
     *    "NN x/mnt", jadi pola yang sama bisa membaca frekuensi nadi sebagai
     *    frekuensi napas - kesalahan yang tidak terlihat di layar.
     */
    private function vitalsFromSummary(string $text): ?array
    {
        $summary = trim($text);

        if ($summary === '') {
            return null;
        }

        $patterns = [
            'tekanan_darah' => '/\b\d{2,3}\s*\/\s*\d{2,3}\s*mmhg\b/i',
            'nadi' => '/\b(?:hr|nadi)\s*[:=]?\s*(\d{2,3}\s*x\s*\/\s*mnt)\b/i',
            'respirasi' => '/\b(?:rr|respirasi|napas)\s*[:=]?\s*(\d{1,2}\s*x\s*\/\s*mnt)\b/i',
            'suhu' => '/\b\d{1,2}[.,]\d\s*c\b/i',
            'saturasi' => '/\b\d{2,3}\s*%/',
        ];

        $payload = [];

        foreach ($patterns as $key => $pattern) {
            if (preg_match($pattern, $summary, $matches) !== 1) {
                continue;
            }

            $payload[$key] = trim((string) (count($matches) > 1 ? $matches[1] : $matches[0]));
        }

        // `ringkasan` selalu ada dan selalu berisi teks apa adanya dari petugas.
        return ['ringkasan' => $summary] + $payload;
    }

    /**
     * Tanggal masuk episode, dipakai saat tanggal prosedur tidak terkirim.
     */
    private function fallbackDate(Encounter $encounter): Carbon
    {
        return $encounter->admitted_at instanceof Carbon
            ? $encounter->admitted_at->copy()->startOfDay()
            : now()->startOfDay();
    }
}

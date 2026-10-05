<?php

namespace Tests\Feature;

use App\Models\Diagnosis;
use App\Models\Encounter;
use App\Models\Patient;
use App\Models\Procedure;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Fitur "Tambah Pasien": POST /pasien (name "pasien.store").
 *
 * Rute: routes/pages.pasien.php, middleware `auth` + `role:dokter`.
 * Perawat dan bidan boleh MEMBACA census (/pasien 200) tetapi tidak boleh
 * MENULIS admisi, jadi POST-nya harus 403 - bukan 500.
 *
 * Percabangan No. RM ganda diputuskan server: tanpa confirm_duplicate
 * permintaan ditolak dengan pesan prototype, bukan ditambahkan diam-diam.
 */
class AddPatientTest extends TestCase
{
    use RefreshDatabase;

    private function dokter(): User
    {
        return User::factory()->dokter()->create(['password' => Hash::make('dokter123')]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Siti Rahma',
            'mrn' => '900001',
            'sex' => 'Perempuan',
            'birth_date' => '1990-05-17',
            'unit' => 'ICU Bed 03',
            'bed' => 'Bed 07',
            'admitted_at' => '2026-10-01 08:00',
            'attending_physician' => 'dr. Rangga Saputra, Sp.An-TI',
            'diagnoses' => [
                ['type' => 'utama', 'text' => 'Sepsis', 'code' => 'A41.9'],
                ['type' => 'penyerta', 'text' => 'Hipotensi', 'code' => 'I95.9'],
            ],
            'procedures' => [
                ['name' => 'Intubasi endotrakeal', 'performed_at' => '2026-10-01 08:30', 'operator' => 'dr. Uji'],
            ],
        ], $overrides);
    }

    public function test_add_patient_button_is_only_rendered_for_a_doctor(): void
    {
        $dokterResponse = $this->actingAs($this->dokter())->get('/pasien');
        $dokterResponse->assertOk();
        $dokterResponse->assertSee('openAddPatientBtn', false);

        foreach (['perawat', 'bidan'] as $role) {
            $response = $this->actingAs(User::factory()->create(['role' => $role]))->get('/pasien');
            $response->assertOk();
            $response->assertDontSee('openAddPatientBtn', false);
        }
    }

    public function test_doctor_can_create_an_admission_for_a_new_patient(): void
    {
        $dokter = $this->dokter();

        $response = $this->actingAs($dokter)->post('/pasien', $this->payload());

        $this->assertSame(1, Patient::query()->count());
        $this->assertSame(1, Encounter::query()->count());

        $patient = Patient::query()->sole();
        $this->assertSame('900001', $patient->mrn);
        $this->assertSame('Siti Rahma', $patient->name);

        $encounter = Encounter::query()->sole();
        $this->assertSame('aktif', $encounter->status->value);
        $this->assertSame('ICU Bed 03', $encounter->unit);
        $this->assertSame('Bed 07', $encounter->bed);
        $this->assertStringContainsString('Sepsis', (string) $encounter->diagnosis_summary);

        // Redirect ke halaman profil episode yang baru dibuat.
        $response->assertRedirect(route('profil', ['encounter' => $encounter->encounter_id]));
        $response->assertSessionHas('success');
    }

    public function test_new_admission_gets_an_encounter_id_derived_from_the_mrn_and_date(): void
    {
        $this->actingAs($this->dokter())->post('/pasien', $this->payload());

        $this->assertSame('enc-900001-20261001', Encounter::query()->sole()->encounter_id);
    }

    public function test_child_rows_are_written_for_the_new_admission(): void
    {
        $encounter = $this->postAdmission();

        $this->assertSame(2, Diagnosis::query()->where('encounter_id', $encounter->getKey())->count());
        $this->assertSame(1, Procedure::query()->where('encounter_id', $encounter->getKey())->count());
        $this->assertSame(1, $encounter->asmed()->count());
        $this->assertSame(1, $encounter->nursingCares()->count());

        $this->assertSame(
            'Sepsis',
            Diagnosis::query()->where('encounter_id', $encounter->getKey())->orderBy('id')->value('text'),
        );
    }

    public function test_the_new_admission_profile_page_opens_without_a_server_error(): void
    {
        $encounter = $this->postAdmission();

        // Regressi yang sama seperti di ClinicalPagesTest: admisi tanpa observasi
        // dulu membuat /profil 500.
        $this->actingAs($this->dokter())
            ->get('/encounters/'.$encounter->encounter_id.'/profil')
            ->assertOk();
    }

    public function test_nurse_cannot_create_an_admission(): void
    {
        $response = $this->actingAs(User::factory()->perawat()->create())->post('/pasien', $this->payload());

        $response->assertForbidden();
        $this->assertSame(0, Patient::query()->count());
        $this->assertSame(0, Encounter::query()->count());
    }

    public function test_midwife_cannot_create_an_admission(): void
    {
        $this->actingAs(User::factory()->bidan()->create())
            ->post('/pasien', $this->payload())
            ->assertForbidden();

        $this->assertSame(0, Encounter::query()->count());
    }

    public function test_guest_cannot_create_an_admission(): void
    {
        $this->post('/pasien', $this->payload())->assertRedirect(route('login'));

        $this->assertSame(0, Encounter::query()->count());
    }

    public function test_duplicate_mrn_is_rejected_without_confirmation(): void
    {
        Patient::factory()->create(['mrn' => '900001', 'name' => 'Pasien Lama']);

        $response = $this->actingAs($this->dokter())
            ->from('/pasien')
            ->post('/pasien', $this->payload());

        $response->assertRedirect('/pasien');
        $response->assertSessionHasErrors('mrn');
        $this->assertSame(0, Encounter::query()->count());
    }

    public function test_duplicate_mrn_with_confirmation_creates_only_a_new_encounter(): void
    {
        $existing = Patient::factory()->create(['mrn' => '900001', 'name' => 'Pasien Lama']);

        $response = $this->actingAs($this->dokter())->post('/pasien', $this->payload([
            'confirm_duplicate' => '1',
        ]));

        $response->assertRedirect();

        $this->assertSame(1, Patient::query()->count(), 'master pasien tidak boleh digandakan');
        $this->assertSame(1, Encounter::query()->count());

        $encounter = Encounter::query()->sole();
        $this->assertTrue($encounter->patient->is($existing));
        $this->assertSame('Pasien Lama', $encounter->patient->name, 'field form tidak boleh menimpa master');
    }

    public function test_required_fields_are_reported_in_indonesian(): void
    {
        $response = $this->actingAs($this->dokter())
            ->from('/pasien')
            ->post('/pasien', ['diagnoses' => []]);

        $response->assertRedirect('/pasien');
        $response->assertSessionHasErrors(['name', 'mrn', 'unit', 'bed', 'admitted_at', 'diagnoses']);

        $messages = session('errors')->all();
        $joined = implode(' | ', $messages);

        $this->assertStringContainsString('Nama lengkap wajib diisi.', $joined);
        $this->assertStringContainsString('No. RM wajib diisi.', $joined);
        $this->assertStringContainsString('Unit/Ruang wajib diisi.', $joined);
        $this->assertStringContainsString('Tanggal & jam masuk wajib diisi.', $joined);

        $this->assertSame(0, Encounter::query()->count());
    }

    public function test_a_diagnosis_row_without_a_name_is_rejected(): void
    {
        // diagnoses.*.text adalah `required`, jadi baris kosong tidak pernah
        // sampai ke service. baris kosong yang lolos harus
        // ditolak, bukan disimpan diam-diam.
        $response = $this->actingAs($this->dokter())
            ->from('/pasien')
            ->post('/pasien', $this->payload([
                'diagnoses' => [['type' => 'utama', 'text' => '   ']],
            ]));

        $response->assertSessionHasErrors('diagnoses.0.text');
        $this->assertStringContainsString(
            'Nama diagnosa wajib diisi.',
            implode(' | ', session('errors')->all()),
        );
        $this->assertSame(0, Encounter::query()->count());
    }

    public function test_an_unknown_diagnosis_type_is_rejected(): void
    {
        $this->actingAs($this->dokter())
            ->from('/pasien')
            ->post('/pasien', $this->payload([
                'diagnoses' => [['type' => 'diagnosa-hantu', 'text' => 'Sepsis']],
            ]))
            ->assertSessionHasErrors('diagnoses.0.type');
    }

    public function test_datetime_local_input_is_normalised_before_storage(): void
    {
        // <input type="datetime-local"> mengirim "Y-m-d\TH:i".
        $this->actingAs($this->dokter())->post('/pasien', $this->payload([
            'admitted_at' => '2026-10-01T08:00',
        ]));

        $encounter = Encounter::query()->sole();
        $this->assertSame('2026-10-01 08:00:00', $encounter->admitted_at->format('Y-m-d H:i:s'));
    }

    public function test_blank_procedure_rows_are_dropped(): void
    {
        // procedures.*.name nullable, jadi baris kosong harus dibuang server
        // (AdmissionService::collectProcedures()), persis collectProcedures()
        // pada prototype patients.html.
        $this->actingAs($this->dokter())->post('/pasien', $this->payload([
            'diagnoses' => [['type' => 'utama', 'text' => 'Sepsis']],
            'procedures' => [
                ['name' => 'Intubasi'],
                ['name' => '   '],
                ['name' => ''],
            ],
        ]));

        $encounter = Encounter::query()->sole();
        $this->assertSame(1, Procedure::query()->where('encounter_id', $encounter->getKey())->count());
        $this->assertSame(
            'Intubasi',
            Procedure::query()->where('encounter_id', $encounter->getKey())->value('name'),
        );
    }

    public function test_new_admission_shows_up_in_the_census(): void
    {
        $encounter = $this->postAdmission();

        $this->actingAs($this->dokter())
            ->get('/pasien')
            ->assertOk()
            ->assertSee(route('profil', ['encounter' => $encounter->encounter_id]), false);
    }

    private function postAdmission(array $overrides = []): Encounter
    {
        $this->actingAs($this->dokter())->post('/pasien', $this->payload($overrides));

        return Encounter::query()->sole();
    }
}

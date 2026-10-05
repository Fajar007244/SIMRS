<?php

namespace Tests\Feature;

use App\Models\Encounter;
use App\Models\InvasiveDevice;
use App\Models\Observation;
use App\Models\ObservationBundleAnswer;
use App\Models\ObservationMedication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Jalur tulis modul Observasi EWS: POST /encounters/{enc}/observasi (store),
 * POST .../observasi/delete (destroy), dan penolakan validasi.
 *
 * Aturan kontrak yang dijaga tes ini:
 *  1. skor EWS selalu dihitung ulang di server; field ews_* milik klien
 *     diabaikan total;
 *  2. slot jam (tanggal + waktu) adalah idempotency key: simpan ulang slot
 *     yang sama memperbarui baris, bukan menambah;
 *  3. nilai vital di luar rentang ditolak dengan pesan bahasa Indonesia dan
 *     TIDAK PERNAH 500;
 *  4. _token basi menghasilkan 419.
 */
class ObservationWriteTest extends TestCase
{
    use RefreshDatabase;

    private Encounter $encounter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->encounter = Encounter::factory()->create();
    }

    private function perawat(): User
    {
        return User::factory()->perawat()->create(['name' => 'Ns. Tri Handayani']);
    }

    /**
     * Vital yang sengaja dipilih agar menyentuh beberapa band sekaligus:
     * RR 26 -> 3, HR 120 -> 2, SBP 88 -> 1, SpO2 92 -> 2, Temp 39.2 -> 2,
     * Kesadaran Voice -> 1. Total 11 => emergency.
     *
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'observation_date' => '2026-09-07',
            'observation_time' => '11:00',
            'sys' => 88,
            'dia' => 55,
            'hr' => 120,
            'rr' => 26,
            'spo2' => 92,
            'suhu' => 39.2,
            'kesadaran' => 'Voice',
            'status' => 'final',
            'notes' => 'Observasi uji.',
        ], $overrides);
    }

    public function test_a_full_observation_payload_is_persisted(): void
    {
        $response = $this->actingAs($this->perawat())
            ->from('/encounters/'.$this->encounter->encounter_id.'/observasi')
            ->post('/encounters/'.$this->encounter->encounter_id.'/observasi', $this->payload());

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $observation = Observation::query()->sole();

        $this->assertSame($this->encounter->getKey(), $observation->encounter_id);
        $this->assertSame('2026-09-07', $observation->observation_date->format('Y-m-d'));
        $this->assertSame('11:00:00', $observation->observation_time->format('H:i:s'));
        $this->assertSame(88, $observation->sys);
        $this->assertSame(26, $observation->rr);
        $this->assertSame(92, $observation->spo2);
        $this->assertSame(39.2, (float) $observation->suhu);
        $this->assertSame('Voice', $observation->kesadaran->value);
        $this->assertSame('final', $observation->status);
        $this->assertSame('Observasi uji.', $observation->notes);
        $this->assertSame('Ns. Tri Handayani', $observation->recorded_by);
    }

    public function test_ews_score_is_recomputed_on_the_server(): void
    {
        $this->postObservation($this->payload());

        $observation = Observation::query()->sole();

        $scores = $observation->ews_scores;

        $this->assertSame(
            ['RR' => 3, 'HR' => 2, 'SBP' => 1, 'SpO2' => 2, 'Temp' => 2, 'Kesadaran' => 1],
            $scores,
        );
        $this->assertSame(11, $observation->ews_total);
        $this->assertSame('emergency', $observation->ews_risk->value);
        $this->assertSame(array_sum($scores), $observation->ews_total);
    }

    public function test_a_client_cannot_force_an_ews_score(): void
    {
        // ObservasiController::payload() adalah daftar putih; ews_total,
        // ews_risk, dan ews_scores milik klien tidak pernah diteruskan.
        $this->postObservation($this->payload([
            'observation_time' => '12:00',
            'ews_total' => 99,
            'ews_risk' => 'low',
            'ews_scores' => ['RR' => 0, 'HR' => 0, 'SBP' => 0, 'SpO2' => 0, 'Temp' => 0, 'Kesadaran' => 0],
        ]));

        $observation = Observation::query()->sole();

        $this->assertNotSame(99, $observation->ews_total);
        $this->assertNotSame('low', $observation->ews_risk->value);
        $this->assertSame(11, $observation->ews_total);
        $this->assertSame('emergency', $observation->ews_risk->value);
    }

    public function test_saving_the_same_hour_slot_twice_updates_one_row(): void
    {
        $this->postObservation($this->payload());
        $this->postObservation($this->payload([
            'notes' => 'Diperbarui.',
            'sys' => 130,
        ]));

        $this->assertSame(1, Observation::query()->count());

        $observation = Observation::query()->sole();
        $this->assertSame('Diperbarui.', $observation->notes);
        $this->assertSame(130, $observation->sys);
    }

    public function test_the_second_save_of_a_slot_reports_a_duplicate_flash_message(): void
    {
        $this->postObservation($this->payload());
        $this->postObservation($this->payload(['notes' => 'Diperbarui.']));

        $this->assertSame('Slot jam ini sudah ada, data diperbarui.', session('success'));
    }

    public function test_a_different_hour_slot_creates_a_second_row(): void
    {
        $this->postObservation($this->payload());
        $this->postObservation($this->payload(['observation_time' => '12:00']));

        $this->assertSame(2, Observation::query()->count());
    }

    public function test_medications_bundles_and_devices_are_saved_alongside_the_observation(): void
    {
        $this->postObservation($this->payload([
            'medications' => [
                [
                    'name' => 'Noradrenalin',
                    'dose' => '0.1 mcg/kg/mnt',
                    'category' => 'Inotropik / Vasopressor',
                    'volume' => 20,
                    'route' => 'IV',
                    'status' => 'Diberi',
                ],
            ],
            'bundles' => ['vap_1' => 'Ya', 'vap_2' => 'Tidak', 'clabsi_1' => 'Ya'],
            'devices' => [
                ['key' => 'ett', 'present' => '1', 'startDate' => '2026-09-06'],
            ],
        ]));

        $observation = Observation::query()->sole();

        $this->assertSame(1, ObservationMedication::query()->where('observation_id', $observation->getKey())->count());
        $this->assertSame('Noradrenalin', ObservationMedication::query()->where('observation_id', $observation->getKey())->value('name'));

        $this->assertSame(3, ObservationBundleAnswer::query()->where('observation_id', $observation->getKey())->count());
        $bundle = ObservationBundleAnswer::query()->where('observation_id', $observation->getKey())->where('item_key', 'vap_1')->first();
        $this->assertSame('vap', $bundle->bundle_group->value);
        $this->assertSame('ya', $bundle->answer->value, 'jawaban bundle disimpan lowercase sesuai enum');

        $this->assertSame(1, InvasiveDevice::query()->where('encounter_id', $this->encounter->getKey())->count());
        $device = InvasiveDevice::query()->where('encounter_id', $this->encounter->getKey())->sole();
        $this->assertSame('ett', $device->device_code->value);
        $this->assertTrue($device->is_active);
    }

    public function test_medication_rows_without_a_name_are_ignored(): void
    {
        $this->postObservation($this->payload([
            'medications' => [
                ['name' => 'Meropenem', 'dose' => '1 g'],
                ['name' => '  ', 'dose' => '1 g'],
            ],
        ]));

        $this->assertSame(1, ObservationMedication::query()->count());
    }

    public function test_the_prototype_form_fields_are_stored(): void
    {
        // Kolom nullable yang ditambahkan migrasi 2026_10_01_000001.
        $this->postObservation($this->payload([
            'weight' => 70,
            'gcs' => 'E3-Vt-M5',
            // Nilai select RASS dikirim sebagai string lewat POST, sama seperti
            // payload ModalFormulirObservasiEWS.vue.
            'rass' => '-2',
            'bp_method' => 'IBP',
            'blood_glucose' => 165,
            'respiratory_problem' => 'Ya',
            'intake' => 500,
            'output' => 400,
            'nursing_action' => 'Posisi Fowler 30 derajat',
        ]));

        $observation = Observation::query()->sole();

        $this->assertSame(70.0, (float) $observation->weight_kg);
        $this->assertSame('E3-Vt-M5', $observation->gcs_text);
        $this->assertNull($observation->gcs, 'gcs berisi teks tidak boleh mengisi kolom integer');
        $this->assertSame(-2, $observation->rass);
        $this->assertSame('IBP', $observation->bp_method);
        $this->assertSame(165, $observation->blood_glucose);
        $this->assertSame('Ya', $observation->respiratory_problem);
        $this->assertSame(500.0, (float) $observation->intake);
        $this->assertSame(400.0, (float) $observation->output);
        $this->assertSame('Posisi Fowler 30 derajat', $observation->nursing_action);
    }

    public function test_iwl_is_recalculated_from_the_weight_when_not_sent(): void
    {
        // round(15 x 70 / 24) = 44
        $this->postObservation($this->payload(['weight' => 70]));

        $this->assertSame(44.0, (float) Observation::query()->sole()->iwl_volume);
    }

    public function test_map_is_derived_from_the_blood_pressure_when_not_sent(): void
    {
        $this->postObservation($this->payload());

        // round(55 + (88 - 55) / 3) = 66
        $this->assertSame(66, Observation::query()->sole()->map);
    }

    public function test_a_vital_outside_the_plausible_range_is_rejected_in_indonesian(): void
    {
        $before = Observation::query()->count();

        $response = $this->actingAs($this->perawat())
            ->from('/encounters/'.$this->encounter->encounter_id.'/observasi')
            ->post('/encounters/'.$this->encounter->encounter_id.'/observasi', $this->payload([
                'observation_time' => '23:00',
                'sys' => 9999,
            ]));

        $response->assertRedirect();
        $response->assertSessionHasErrors('sys');

        $messages = implode(' | ', session('errors')->all());
        $this->assertStringContainsString('Tekanan sistolik di luar rentang wajar (20 - 350).', $messages);

        // Tidak ada baris baru, dan kegagalan selalu 302 dengan flash berbahasa
        // Indonesia - tidak pernah 500.
        $this->assertSame($before, Observation::query()->count());
        $this->assertStringContainsString('Observasi EWS gagal disimpan', (string) session('error'));
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function outOfRangeVitals(): array
    {
        return [
            'sys' => ['sys', '1', 'Tekanan sistolik'],
            'dia' => ['dia', '999', 'Tekanan diastolik'],
            'hr' => ['hr', '999', 'Nadi'],
            'rr' => ['rr', '500', 'Frekuensi napas'],
            'spo2' => ['spo2', '150', 'Saturasi oksigen'],
            'suhu' => ['suhu', '80', 'Suhu tubuh'],
        ];
    }

    #[DataProvider('outOfRangeVitals')]
    public function test_every_vital_has_a_range_check_with_an_indonesian_label(string $field, string $value, string $label): void
    {
        $response = $this->actingAs($this->perawat())
            ->from('/encounters/'.$this->encounter->encounter_id.'/observasi')
            ->post('/encounters/'.$this->encounter->encounter_id.'/observasi', $this->payload([
                'observation_time' => '23:00',
                $field => $value,
            ]));

        $response->assertSessionHasErrors($field);
        $this->assertStringContainsString($label, implode(' | ', session('errors')->all()));
        $this->assertSame(0, Observation::query()->count());
    }

    public function test_a_missing_required_vital_is_rejected(): void
    {
        $payload = $this->payload();
        unset($payload['spo2']);

        $response = $this->actingAs($this->perawat())
            ->from('/encounters/'.$this->encounter->encounter_id.'/observasi')
            ->post('/encounters/'.$this->encounter->encounter_id.'/observasi', $payload);

        $response->assertSessionHasErrors('spo2');
        $this->assertSame(0, Observation::query()->count());
    }

    public function test_an_unknown_consciousness_level_is_rejected(): void
    {
        $this->actingAs($this->perawat())
            ->from('/encounters/'.$this->encounter->encounter_id.'/observasi')
            ->post('/encounters/'.$this->encounter->encounter_id.'/observasi', $this->payload([
                'kesadaran' => 'Mata Tertutup',
            ]))
            ->assertSessionHasErrors('kesadaran');

        $this->assertSame(0, Observation::query()->count());
    }

    public function test_a_malformed_date_or_time_is_rejected(): void
    {
        $perawat = $this->perawat();
        $url = '/encounters/'.$this->encounter->encounter_id.'/observasi';

        $this->actingAs($perawat)->from($url)
            ->post($url, $this->payload(['observation_date' => '07-09-2026']))
            ->assertSessionHasErrors('observation_date');

        $this->actingAs($perawat)->from($url)
            ->post($url, $this->payload(['observation_time' => '11.00']))
            ->assertSessionHasErrors('observation_time');

        $this->assertSame(0, Observation::query()->count());
    }

    public function test_deleting_a_slot_removes_the_row(): void
    {
        $this->postObservation($this->payload());

        $response = $this->actingAs($this->perawat())
            ->from('/encounters/'.$this->encounter->encounter_id.'/observasi')
            ->post('/encounters/'.$this->encounter->encounter_id.'/observasi/delete', [
                'date' => '2026-09-07',
                'time' => '11:00',
            ]);

        $response->assertRedirect();
        $this->assertSame(0, Observation::query()->count());
        $this->assertNull($this->encounter->fresh()->latest_observation_at);
    }

    public function test_deleting_a_missing_slot_is_not_a_server_error(): void
    {
        $response = $this->actingAs($this->perawat())
            ->from('/encounters/'.$this->encounter->encounter_id.'/observasi')
            ->post('/encounters/'.$this->encounter->encounter_id.'/observasi/delete', [
                'date' => '2026-09-07',
                'time' => '11:00',
            ]);

        $response->assertRedirect();
        $this->assertStringContainsString('tidak ditemukan', (string) session('error'));
    }

    public function test_deleting_with_an_invalid_slot_format_is_rejected(): void
    {
        $this->actingAs($this->perawat())
            ->from('/encounters/'.$this->encounter->encounter_id.'/observasi')
            ->post('/encounters/'.$this->encounter->encounter_id.'/observasi/delete', [
                'date' => 'bukan-tanggal',
                'time' => 'sebelas',
            ])
            ->assertSessionHasErrors(['date', 'time']);
    }

    public function test_a_stale_csrf_token_is_a_419(): void
    {
        // Verifikasi CSRF dimatikan otomatis saat app->runningUnitTests(), jadi
        // env diubah supaya middleware benar-benar berjalan di sini.
        $this->app->instance('env', 'local');

        $response = $this->actingAs($this->perawat())->post(
            '/encounters/'.$this->encounter->encounter_id.'/observasi',
            $this->payload(['_token' => 'token-palsu-1234567890']),
        );

        $response->assertStatus(419);
        $this->assertSame(0, Observation::query()->count());
    }

    public function test_every_role_can_write_an_observation(): void
    {
        foreach (['perawat', 'bidan', 'dokter'] as $index => $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))->post(
                '/encounters/'.$this->encounter->encounter_id.'/observasi',
                $this->payload(['observation_time' => sprintf('%02d:00', 8 + $index)]),
            );
        }

        $this->assertSame(3, Observation::query()->count());
    }

    public function test_guest_cannot_write_an_observation(): void
    {
        $this->post('/encounters/'.$this->encounter->encounter_id.'/observasi', $this->payload())
            ->assertRedirect(route('login'));

        $this->assertSame(0, Observation::query()->count());
    }

    public function test_writing_to_an_unknown_encounter_is_a_404(): void
    {
        $this->actingAs($this->perawat())
            ->post('/encounters/enc-tidak-ada-icu-20260906/observasi', $this->payload())
            ->assertNotFound();

        $this->assertSame(0, Observation::query()->count());
    }

    private function postObservation(array $payload): TestResponse
    {
        return $this->actingAs($this->perawat())
            ->from('/encounters/'.$this->encounter->encounter_id.'/observasi')
            ->post('/encounters/'.$this->encounter->encounter_id.'/observasi', $payload);
    }
}

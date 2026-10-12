<?php

namespace Tests\Feature;

use App\Enums\DischargeDestination;
use App\Enums\EncounterStatus;
use App\Models\Encounter;
use App\Models\User;
use App\Services\DischargeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Rute POST /encounters/{encounter}/discharge.
 *
 * Menguji DischargeService sudah dilakukan di DischargeTest. Yang dijaga di
 * sini adalah hal-hal yang hanya bisa salah di lapisan HTTP:
 *
 *  1. Otorisasi - hanya dokter yang boleh menutup episode;
 *  2. Konfirmasi - "SELESAI" wajib diisi, termasuk untuk permintaan yang dibuat
 *     tanpa JavaScript atau diketik tangan;
 *  3. Redirect dan flash message;
 *  4. Penolakan TIDAK boleh meninggalkan episode dalam keadaan setengah jadi.
 */
class DischargeRouteTest extends TestCase
{
    use RefreshDatabase;

    private Encounter $encounter;

    private string $url = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->encounter = Encounter::factory()->create([
            'admitted_at' => '2026-09-10 08:00',
            'discharged_at' => null,
            'status' => EncounterStatus::AKTIF->value,
        ]);

        $this->url = '/encounters/'.$this->encounter->encounter_id.'/discharge';
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'discharged_at' => '2026-09-13',
            'destination' => DischargeDestination::PULANG->value,
            'note' => 'Kondisi membaik.',
            'confirmation' => DischargeService::CONFIRMATION_WORD,
        ], $overrides);
    }

    private function dokter(): User
    {
        return User::factory()->dokter()->create();
    }

    public function test_a_doctor_can_close_an_encounter_over_http(): void
    {
        $response = $this->actingAs($this->dokter())
            ->from('/pasien')
            ->post($this->url, $this->payload());

        $response->assertRedirect('/pasien?status=all');
        $response->assertSessionHas('success');

        $closed = $this->encounter->fresh();

        $this->assertSame(EncounterStatus::SELESAI, $closed->status);
        $this->assertSame('2026-09-13', $closed->discharged_at->toDateString());
        $this->assertSame(DischargeDestination::PULANG, $closed->discharge_destination);
    }

    public function test_a_nurse_cannot_close_an_encounter(): void
    {
        $response = $this->actingAs(User::factory()->perawat()->create())
            ->from('/pasien')
            ->post($this->url, $this->payload());

        $response->assertForbidden();
        $this->assertSame(EncounterStatus::AKTIF, $this->encounter->fresh()->status);
    }

    public function test_a_midwife_cannot_close_an_encounter(): void
    {
        $response = $this->actingAs(User::factory()->bidan()->create())
            ->from('/pasien')
            ->post($this->url, $this->payload());

        $response->assertForbidden();
        $this->assertSame(EncounterStatus::AKTIF, $this->encounter->fresh()->status);
    }

    public function test_a_guest_cannot_close_an_encounter(): void
    {
        $this->post($this->url, $this->payload())->assertRedirect('/login');

        $this->assertSame(EncounterStatus::AKTIF, $this->encounter->fresh()->status);
    }

    /**
     * Kolom konfirmasi dikosongkan harus DITOLAK di server.
     *
     * Ini yang membuat kata "SELESAI" benar-benar berarti sesuatu: gate
     * JavaScript di modal hanyalah kenyamanan, bukan penjaga. Permintaan yang
     * dibuat tanpa JavaScript tetap tidak bisa menutup episode.
     */
    public function test_an_empty_confirmation_is_rejected_on_the_server(): void
    {
        $response = $this->actingAs($this->dokter())
            ->from('/pasien')
            ->post($this->url, $this->payload(['confirmation' => '']));

        $response->assertSessionHasErrors('confirmation');

        $after = $this->encounter->fresh();

        $this->assertSame(EncounterStatus::AKTIF, $after->status);
        $this->assertNull($after->discharged_at);
    }

    public function test_a_wrong_confirmation_word_is_rejected(): void
    {
        $response = $this->actingAs($this->dokter())
            ->from('/pasien')
            ->post($this->url, $this->payload(['confirmation' => 'batal']));

        $response->assertSessionHasErrors('confirmation');

        $this->assertSame(EncounterStatus::AKTIF, $this->encounter->fresh()->status);
    }

    public function test_the_confirmation_is_case_insensitive(): void
    {
        $response = $this->actingAs($this->dokter())
            ->from('/pasien')
            ->post($this->url, $this->payload(['confirmation' => ' selesai ']));

        $response->assertSessionHasNoErrors();

        $this->assertSame(EncounterStatus::SELESAI, $this->encounter->fresh()->status);
    }

    public function test_a_discharge_date_before_admission_is_rejected_over_http(): void
    {
        $response = $this->actingAs($this->dokter())
            ->from('/pasien')
            ->post($this->url, $this->payload(['discharged_at' => '2026-09-01']));

        $response->assertSessionHasErrors('discharged_at');

        $after = $this->encounter->fresh();

        $this->assertSame(EncounterStatus::AKTIF, $after->status);
        $this->assertNull($after->discharged_at);
    }

    public function test_an_unknown_destination_is_rejected_over_http(): void
    {
        $response = $this->actingAs($this->dokter())
            ->from('/pasien')
            ->post($this->url, $this->payload(['destination' => 'entah-ke-mana']));

        $response->assertSessionHasErrors('destination');

        $this->assertSame(EncounterStatus::AKTIF, $this->encounter->fresh()->status);
    }

    public function test_closing_an_already_closed_encounter_is_rejected(): void
    {
        $dokter = $this->dokter();

        $this->actingAs($dokter)->from('/pasien')->post($this->url, $this->payload());

        $first = $this->encounter->fresh();

        $response = $this->actingAs($dokter)
            ->from('/pasien')
            ->post($this->url, $this->payload(['note' => 'Percobaan kedua']));

        $response->assertSessionHasErrors();

        $after = $this->encounter->fresh();

        $this->assertSame($first->discharged_at->toDateString(), $after->discharged_at->toDateString());
        $this->assertSame('Kondisi membaik.', $after->discharge_note);
    }

    public function test_the_note_may_be_left_empty(): void
    {
        $this->actingAs($this->dokter())
            ->from('/pasien')
            ->post($this->url, $this->payload(['note' => '']))
            ->assertSessionHasNoErrors();

        $this->assertNull($this->encounter->fresh()->discharge_note);
    }
}
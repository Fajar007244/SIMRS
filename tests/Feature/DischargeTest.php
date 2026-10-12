<?php

namespace Tests\Feature;

use App\Enums\DischargeDestination;
use App\Enums\EncounterStatus;
use App\Models\Encounter;
use App\Models\User;
use App\Services\DischargeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Penutupan episode (discharge).
 *
 * Yang dijaga tes ini:
 *  1. Episode aktif bisa ditutup, dan jejaknya (aktor + tanggal + nasib)
 *     tersimpan lengkap;
 *  2. Episode yang sudah selesai tidak bisa ditutup lagi;
 *  3. Tanggal keluar tidak boleh mendahului tanggal masuk;
 *  4. Setiap penolakan TIDAK mengubah data - tidak ada efek samping;
 *  5. Penutupan tidak menyentuh patients.is_active, karena status rek medis
 *     berbeda dari status episode.
 *
 * Belum termasuk: tombol di UI dan middleware yang menolak penulisan pada
 * episode tertutup. Keduanya dibangun di atas service ini.
 */
class DischargeTest extends TestCase
{
    use RefreshDatabase;

    private Encounter $encounter;

    private User $dokter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->encounter = Encounter::factory()->create([
            'admitted_at' => '2026-09-10 08:00',
            'discharged_at' => null,
            'status' => EncounterStatus::AKTIF->value,
        ]);

        $this->dokter = User::factory()->dokter()->create();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'discharged_at' => '2026-09-13',
            'destination' => DischargeDestination::PULANG->value,
            'note' => 'Kondisi membaik, kontrol 7 hari.',
            'confirmation' => DischargeService::CONFIRMATION_WORD,
        ], $overrides);
    }

    public function test_an_active_encounter_can_be_closed(): void
    {
        $result = app(DischargeService::class)->discharge($this->encounter, $this->payload(), $this->dokter);

        $this->assertSame(EncounterStatus::SELESAI, $result->status);
        $this->assertSame('2026-09-13', $result->discharged_at->toDateString());
        $this->assertSame($this->dokter->getKey(), $result->discharged_by);
        $this->assertSame(DischargeDestination::PULANG, $result->discharge_destination);
        $this->assertSame('Kondisi membaik, kontrol 7 hari.', $result->discharge_note);
    }

    public function test_an_already_closed_encounter_cannot_be_closed_again(): void
    {
        app(DischargeService::class)->discharge($this->encounter, $this->payload(), $this->dokter);

        $second = $this->encounter->fresh();

        $this->expectException(ValidationException::class);

        try {
            app(DischargeService::class)->discharge(
                $second,
                $this->payload(['discharged_at' => '2026-10-01', 'note' => 'Percobaan kedua']),
                $this->dokter
            );
        } finally {
            // Penolakan tidak boleh mengubah apa pun - termasuk tanggal keluar
            // dan alasan yang sudah tersimpan dari penutupan pertama.
            $after = $this->encounter->fresh();

            $this->assertSame('2026-09-13', $after->discharged_at->toDateString());
            $this->assertSame('Kondisi membaik, kontrol 7 hari.', $after->discharge_note);
        }
    }

    public function test_a_discharge_date_before_admission_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        try {
            app(DischargeService::class)->discharge(
                $this->encounter,
                $this->payload(['discharged_at' => '2026-09-01']),
                $this->dokter
            );
        } finally {
            $after = $this->encounter->fresh();

            $this->assertSame(EncounterStatus::AKTIF, $after->status);
            $this->assertNull($after->discharged_at);
        }
    }

    public function test_a_missing_discharge_date_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        try {
            app(DischargeService::class)->discharge(
                $this->encounter,
                $this->payload(['discharged_at' => '']),
                $this->dokter
            );
        } finally {
            $this->assertNull($this->encounter->fresh()->discharged_at);
        }
    }

    public function test_an_unknown_destination_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        try {
            app(DischargeService::class)->discharge(
                $this->encounter,
                $this->payload(['destination' => 'entah-ke-mana']),
                $this->dokter
            );
        } finally {
            $after = $this->encounter->fresh();

            $this->assertSame(EncounterStatus::AKTIF, $after->status);
            $this->assertNull($after->discharge_destination);
        }
    }

    public function test_a_missing_destination_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        app(DischargeService::class)->discharge(
            $this->encounter,
            $this->payload(['destination' => '']),
            $this->dokter
        );
    }

    /**
     * Penutupan episode TIDAK boleh mengubah status rek medis pasien.
     *
     * Kalau ikut berubah, pasien yang sudah pulang tapi masih berobat akan
     * hilang dari pencarian - dan `patients.is_active` tidak punya tempat
     * untuk menyatakan "tidak boleh diedit" seperti status episode.
     */
    public function test_closing_an_encounter_does_not_change_the_patient_flag(): void
    {
        $patient = $this->encounter->patient;

        $this->assertTrue($patient->is_active);

        app(DischargeService::class)->discharge($this->encounter, $this->payload(), $this->dokter);

        $this->assertTrue(
            $patient->fresh()->is_active,
            'Status rek medis harus terpisah dari status episode.'
        );
    }

    public function test_the_note_is_optional(): void
    {
        $result = app(DischargeService::class)->discharge(
            $this->encounter,
            $this->payload(['note' => '   ']),
            $this->dokter
        );

        $this->assertNull($result->discharge_note);
    }

    public function test_every_destination_is_accepted(): void
    {
        foreach (DischargeDestination::cases() as $index => $destination) {
            $encounter = Encounter::factory()->create([
                'admitted_at' => '2026-09-10 08:00',
                'discharged_at' => null,
                'status' => EncounterStatus::AKTIF->value,
            ]);

            $result = app(DischargeService::class)->discharge(
                $encounter,
                $this->payload(['destination' => $destination->value]),
                $this->dokter
            );

            $this->assertSame(
                $destination,
                $result->discharge_destination,
                'Destinasi '.$destination->value.' harus bisa dipakai.'
            );
        }
    }

    public function test_ongoing_care_destinations_are_flagged(): void
    {
        $this->assertTrue(DischargeDestination::RAWAT_INAP->isOngoingCare());
        $this->assertTrue(DischargeDestination::RUJUK->isOngoingCare());
        $this->assertFalse(DischargeDestination::PULANG->isOngoingCare());
        $this->assertFalse(DischargeDestination::MENINGGAL->isOngoingCare());
    }

    public function test_the_closed_scope_selects_finished_encounters(): void
    {
        app(DischargeService::class)->discharge($this->encounter, $this->payload(), $this->dokter);

        $this->assertTrue($this->encounter->fresh()->status === EncounterStatus::SELESAI);
        $this->assertSame(
            1,
            Encounter::query()->closed()->whereKey($this->encounter->getKey())->count()
        );
        $this->assertSame(
            0,
            Encounter::query()->writable()->whereKey($this->encounter->getKey())->count()
        );
    }
}
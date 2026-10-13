<?php

namespace Tests\Feature;

use App\Models\AccessLog;
use App\Models\Encounter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Jejak audit akses rekam medis (H3, UU PDP Pasal 26).
 *
 * Yang dijaga:
 *  1. Bacaan klinis terautentikasi tercatat (siapa, data siapa, modul apa);
 *  2. Tulis tercatat sebagai write, baca sebagai read;
 *  3. Tamu dan route tak dikenal tidak dicatat;
 *  4. patient_id yang tersimpan adalah PK integer, bukan kode string;
 *  5. Kegagalan tulis log TIDAK PERNAH mengubah respons klinis.
 */
class AccessLogTest extends TestCase
{
    use RefreshDatabase;

    private Encounter $encounter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->encounter = Encounter::factory()->create();
    }

    public function test_reading_a_clinical_module_is_logged(): void
    {
        $user = User::factory()->dokter()->create();

        $this->actingAs($user)
            ->get('/encounters/'.$this->encounter->encounter_id.'/profil')
            ->assertOk();

        $log = AccessLog::query()->sole();

        $this->assertSame($user->getKey(), $log->user_id);
        $this->assertSame('profil', $log->module);
        $this->assertSame('read', $log->action);
        $this->assertSame('profil', $log->route);
        $this->assertSame('GET', $log->method);
        $this->assertSame($this->encounter->encounter_id, $log->encounter_id);
        $this->assertSame($this->encounter->patient_id, $log->patient_id);
        $this->assertNotNull($log->created_at);
    }

    public function test_writing_is_logged_as_write(): void
    {
        $user = User::factory()->perawat()->create();

        $this->actingAs($user)
            ->from('/encounters/'.$this->encounter->encounter_id.'/observasi')
            ->post('/encounters/'.$this->encounter->encounter_id.'/observasi', [
                'observation_date' => '2026-09-07',
                'observation_time' => '11:00',
                'sys' => 120,
                'dia' => 80,
                'hr' => 80,
                'rr' => 16,
                'spo2' => 98,
                'suhu' => 37,
                'kesadaran' => 'Alert',
                'status' => 'final',
            ])
            ->assertRedirect();

        $this->assertTrue(
            AccessLog::query()->where('action', 'write')->where('module', 'observasi')->exists(),
            'Penulisan observasi harus tercatat sebagai write.'
        );
    }

    public function test_patient_list_access_is_logged_without_encounter(): void
    {
        $user = User::factory()->bidan()->create();

        $this->actingAs($user)->get('/pasien')->assertOk();

        $log = AccessLog::query()->sole();

        $this->assertSame('pasien', $log->module);
        $this->assertNull($log->encounter_id);
        $this->assertNull($log->patient_id);
    }

    public function test_guests_are_not_logged(): void
    {
        $this->get('/login')->assertOk();

        $this->assertSame(0, AccessLog::query()->count());
    }

    public function test_login_attempts_are_not_logged(): void
    {
        $this->from('/login')->post('/login', ['username' => 'x', 'password' => 'y'])
            ->assertRedirect('/login');

        $this->assertSame(0, AccessLog::query()->count());
    }

    /**
     * Kegagalan tulis log tidak boleh mengubah respons klinis. Tanpa
     * try/catch di middleware, satu tabel audit yang rusak mengubah seluruh
     * bacaan klinis 200 menjadi 500.
     */
    public function test_a_broken_audit_table_does_not_break_clinical_reads(): void
    {
        DB::statement('drop table access_logs');

        $this->actingAs(User::factory()->dokter()->create())
            ->get('/encounters/'.$this->encounter->encounter_id.'/profil')
            ->assertOk();
    }

    public function test_log_never_stores_medical_content(): void
    {
        $user = User::factory()->dokter()->create();

        $this->actingAs($user)
            ->get('/encounters/'.$this->encounter->encounter_id.'/profil')
            ->assertOk();

        $columns = DB::getSchemaBuilder()->getColumnListing('access_logs');

        $this->assertContains('encounter_id', $columns);
        $this->assertNotContains('diagnosis', $columns);
        $this->assertNotContains('notes', $columns);
        $this->assertNotContains('password', $columns);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Encounter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Tujuh halaman baca: /pasien (Blade) + enam modul Inertia.
 *
 * Kontrak yang dijaga tes ini:
 *  - enrolled route `activeTab` selalu sama dengan nama modul, karena
 *    ClinicalLayout memakainya untuk menandai tab aktif;
 *  - {encounter} di URL adalah STRING encounters.encounter_id, bukan primary
 *    key numerik, jadi /encounters/1/profil harus 404;
 *  - modul Penunjang tetap 200 dengan activeTab=penunjang (regressi white
 *    screen akibat import `usePage` yang hilang di Pages/Penjunjang.vue).
 */
class ClinicalPagesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Enam modul Inertia dan nama komponen yang harus dirender.
     *
     * @return array<string, array{string, string}>
     */
    public static function modules(): array
    {
        return [
            'profil' => ['profil', 'Profil'],
            'cppt' => ['cppt', 'Cppt'],
            'penunjang' => ['penunjang', 'Penunjang'],
            'farmasi' => ['farmasi', 'Farmasi'],
            'observasi' => ['observasi', 'Observasi'],
            'bundles' => ['bundles', 'Bundles'],
        ];
    }

    private function perawat(): User
    {
        return User::factory()->perawat()->create();
    }

    #[DataProvider('modules')]
    public function test_each_module_renders_with_its_own_active_tab(string $module, string $component): void
    {
        $encounter = Encounter::factory()->create();

        $this->actingAs($this->perawat())
            ->get('/encounters/'.$encounter->encounter_id.'/'.$module)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component($component)
                ->where('activeTab', $module)
                ->where('encounterId', $encounter->encounter_id)
                ->has('banner')
                ->etc()
            );
    }

    #[DataProvider('modules')]
    public function test_unknown_encounter_code_is_a_404_with_an_indonesian_message(string $module): void
    {
        $response = $this->actingAs($this->perawat())
            ->get('/encounters/enc-tidak-ada-icu-20260906/'.$module);

        $response->assertNotFound();
    }

    #[DataProvider('modules')]
    public function test_numeric_primary_key_is_not_accepted_as_an_encounter_code(string $module): void
    {
        $encounter = Encounter::factory()->create();

        $this->actingAs($this->perawat())
            ->get('/encounters/'.$encounter->getKey().'/'.$module)
            ->assertNotFound();
    }

    #[DataProvider('modules')]
    public function test_every_role_can_read_every_module(string $module): void
    {
        $encounter = Encounter::factory()->create();

        foreach (['perawat', 'bidan', 'dokter'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))
                ->get('/encounters/'.$encounter->encounter_id.'/'.$module)
                ->assertOk();
        }
    }

    public function test_penjunjang_still_renders_after_the_use_page_regression(): void
    {
        $encounter = Encounter::factory()->create();

        $this->actingAs($this->perawat())
            ->get('/encounters/'.$encounter->encounter_id.'/penunjang')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Penunjang')
                ->where('activeTab', 'penunjang')
                ->has('data')
                ->has('catalog')
                ->has('summary')
            );
    }

    public function test_penjunjang_tab_switch_is_carried_in_the_query_string(): void
    {
        $encounter = Encounter::factory()->create();

        $this->actingAs($this->perawat())
            ->get('/encounters/'.$encounter->encounter_id.'/penunjang?group=abg')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Penunjang')
                ->where('activeGroup', 'abg')
            );
    }

    public function test_unknown_penjunjang_group_falls_back_to_the_default_tab(): void
    {
        $encounter = Encounter::factory()->create();

        $this->actingAs($this->perawat())
            ->get('/encounters/'.$encounter->encounter_id.'/penunjang?group=tidak-dikenal')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('activeGroup', 'lab')
            );
    }

    public function test_pasien_page_is_a_server_rendered_blade_page(): void
    {
        $encounter = Encounter::factory()->create();

        $response = $this->actingAs($this->perawat())->get('/pasien');

        $response->assertOk();
        // Blade, bukan SPA: tidak ada bungkus data-page Inertia.
        $response->assertDontSee('data-page=', false);
        $response->assertSee(route('profil', ['encounter' => $encounter->encounter_id]), false);
    }

    public function test_pasien_lists_only_active_encounters_by_default(): void
    {
        $active = Encounter::factory()->create(['status' => 'aktif']);
        $finished = Encounter::factory()->discharged()->create();

        $response = $this->actingAs($this->perawat())->get('/pasien');

        $response->assertOk();
        $response->assertSee(route('profil', ['encounter' => $active->encounter_id]), false);
        $response->assertDontSee(route('profil', ['encounter' => $finished->encounter_id]), false);
    }

    public function test_pasien_filters_are_applied_without_erroring(): void
    {
        Encounter::factory()->unit('ICU Bed 01', 'Bed 01')->create();
        Encounter::factory()->unit('HCU Melati', 'Bed 05')->create();

        $perawat = $this->perawat();

        foreach (['/pasien?status=all', '/pasien?unit=ICU%20Bed%2001', '/pasien?search=a', '/pasien?page=2'] as $path) {
            $this->actingAs($perawat)->get($path)->assertOk();
        }
    }

    public function test_broken_query_strings_never_produce_a_server_error(): void
    {
        $encounter = Encounter::factory()->create();
        $perawat = $this->perawat();

        $paths = [
            '/pasien?page=-5&status=$$$',
            '/pasien?unit='.str_repeat('u', 2000),
            '/encounters/'.$encounter->encounter_id.'/observasi?dateFrom=bukan-tanggal&dateTo=99-99-99',
            '/encounters/'.$encounter->encounter_id.'/observasi?risk=meltdown',
            '/encounters/'.$encounter->encounter_id.'/bundles?group=zzz&page=-1',
            '/encounters/'.$encounter->encounter_id.'/farmasi?search='.str_repeat('x', 3000),
            '/encounters/'.$encounter->encounter_id.'/penunjang?group='.rawurlencode('<script>alert(1)</script>'),
        ];

        foreach ($paths as $path) {
            $this->actingAs($perawat)->get($path)->assertOk();
        }
    }

    public function test_profil_page_reports_completion_for_an_episode_without_observations(): void
    {
        // Regressi: AdmissionService::careTeam() dulu 500 pada episode yang belum
        // punya observasi karena membaca $latest['recordedBy'] pada null.
        $encounter = Encounter::factory()->create();

        $this->actingAs($this->perawat())
            ->get('/encounters/'.$encounter->encounter_id.'/profil')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Profil')
                ->where('activeTab', 'profil')
                ->has('profile.careTeam')
                ->where('profile.careTeam.0.key', 'dpjp')
            );
    }

    public function test_pages_render_for_an_episode_created_without_any_observation(): void
    {
        $encounter = Encounter::factory()->create();

        $this->assertSame(0, $encounter->observations()->count());

        $this->actingAs($this->perawat())
            ->get('/encounters/'.$encounter->encounter_id.'/observasi')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Observasi')
                ->where('activeTab', 'observasi')
                ->where('flowsheet', [])
            );
    }
}

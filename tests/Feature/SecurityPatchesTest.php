<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

/**
 * Regresi untuk patch keamanan (lihat C:\Document\New folder\audit-draft).
 *
 * H2: seeder demo tidak boleh jalan di production + kotak kredensial demo
 *     tidak dirender di production.
 * M1: username tak dikenal vs password salah menghasilkan respons identik.
 * M2: header keamanan terkirim di halaman publik.
 * M3: role tidak bisa di-mass-assignment, hanya lewat forceFill.
 */
class SecurityPatchesTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_seeder_refuses_to_run_in_production(): void
    {
        $this->app['env'] = 'production';

        try {
            $this->expectException(RuntimeException::class);
            (new UserSeeder)->run();
        } finally {
            $this->app['env'] = 'testing';
        }
    }

    public function test_database_seeder_refuses_to_run_in_production(): void
    {
        $this->app['env'] = 'production';

        try {
            $this->expectException(RuntimeException::class);
            (new DatabaseSeeder)->run();
        } finally {
            $this->app['env'] = 'testing';
        }
    }

    public function test_demo_credentials_box_is_hidden_in_production(): void
    {
        $this->app['env'] = 'production';

        try {
            $html = $this->get('/login')->assertOk()->getContent();
        } finally {
            $this->app['env'] = 'testing';
        }

        $this->assertStringNotContainsString('perawat123', $html);
        $this->assertStringNotContainsString('Akun demo pilot', $html);
    }

    public function test_demo_credentials_box_still_shows_outside_production(): void
    {
        $html = $this->get('/login')->assertOk()->getContent();

        $this->assertStringContainsString('perawat', $html);
    }

    public function test_unknown_username_and_wrong_password_give_identical_responses(): void
    {
        User::factory()->dokter()->create(['username' => 'dokter', 'password' => Hash::make('rahasia')]);

        $noUser = $this->from('/login')->post('/login', [
            'username' => 'tidak-ada-12345',
            'password' => 'salah',
        ]);

        $wrongPass = $this->from('/login')->post('/login', [
            'username' => 'dokter',
            'password' => 'salah',
        ]);

        $noUser->assertRedirect('/login');
        $wrongPass->assertRedirect('/login');
        $noUser->assertSessionHasErrors('username');
        $wrongPass->assertSessionHasErrors('username');

        $this->assertSame(
            $noUser->getSession()->get('errors')->get('username'),
            $wrongPass->getSession()->get('errors')->get('username')
        );
    }

    public function test_security_headers_are_present_on_the_login_page(): void
    {
        $response = $this->get('/login');

        $response->assertOk();
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');

        $csp = $response->headers->get('Content-Security-Policy');
        $this->assertNotNull($csp);
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);

        // Font + ikon CDN yang dipakai layout harus diizinkan, kalau tidak
        // halaman rusak (putih / tanpa ikon) di production.
        $this->assertStringContainsString('https://fonts.googleapis.com', $csp);
        $this->assertStringContainsString('https://cdnjs.cloudflare.com', $csp);
    }

    public function test_security_headers_are_present_on_a_404_page(): void
    {
        $response = $this->get('/tidak-ada-'.uniqid());

        $response->assertNotFound();
        $response->assertHeader('X-Frame-Options', 'DENY');
    }

    public function test_role_cannot_be_mass_assigned(): void
    {
        $user = User::factory()->create(['role' => UserRole::PERAWAT->value]);

        $this->assertSame(UserRole::PERAWAT, $user->fresh()->role);

        // Mass-assignment diam-diam mengabaikan role (perilaku fillable),
        // bukan melempar — yang penting nilainya tidak berubah.
        $user->update(['role' => UserRole::DOKTER->value, 'name' => 'Diubah']);

        $this->assertSame('Diubah', $user->fresh()->name);
        $this->assertSame(
            UserRole::PERAWAT,
            $user->fresh()->role,
            'role tidak boleh berubah lewat mass-assignment.'
        );
    }

    public function test_role_can_be_set_explicitly_via_force_fill(): void
    {
        $user = User::factory()->create(['role' => UserRole::PERAWAT->value]);

        $user->forceFill(['role' => UserRole::DOKTER->value])->save();

        $this->assertSame(UserRole::DOKTER, $user->fresh()->role);
    }

    public function test_seeded_accounts_keep_their_roles(): void
    {
        $this->seed(UserSeeder::class);

        $this->assertSame(UserRole::PERAWAT, User::where('username', 'perawat')->firstOrFail()->role);
        $this->assertSame(UserRole::BIDAN, User::where('username', 'bidan')->firstOrFail()->role);
        $this->assertSame(UserRole::DOKTER, User::where('username', 'dokter')->firstOrFail()->role);
    }
}

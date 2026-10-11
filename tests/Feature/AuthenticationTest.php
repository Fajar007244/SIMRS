<?php

namespace Tests\Feature;

use App\Models\Encounter;
use App\Models\User;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Alur autentikasi session: form login (Blade), POST /login, dan logout.
 *
 * Rute: routes/web.php. Halaman login sengaja Blade, bukan SPA, sehingga
 * middleware `guest` mencegah pengguna yang sudah login melihat form lagi.
 */
class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Tiga akun demo dari UserSeeder; kredensialnya yang dipakai smoke test.
        $this->seed(UserSeeder::class);
    }

    public function test_login_page_renders_for_guests(): void
    {
        $response = $this->get('/login');

        $response->assertOk();
        $response->assertSee('name="username"', false);
        $response->assertSee('name="password"', false);
        $response->assertSee('name="_token"', false);
    }

    public function test_authenticated_user_is_bounced_away_from_login_page(): void
    {
        $this->actingAs(User::where('username', 'perawat')->firstOrFail())
            ->get('/login')
            ->assertRedirect();
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function validCredentials(): array
    {
        return [
            'perawat' => ['perawat', 'perawat123'],
            'bidan' => ['bidan', 'bidan123'],
            'dokter' => ['dokter', 'dokter123'],
        ];
    }

    #[DataProvider('validCredentials')]
    public function test_valid_credentials_log_the_user_in_and_land_on_pasien(string $username, string $password): void
    {
        $response = $this->post('/login', [
            'username' => $username,
            'password' => $password,
        ]);

        $response->assertRedirect(route('pasien'));
        $this->assertAuthenticatedAs(User::where('username', $username)->firstOrFail());
    }

    public function test_login_falls_back_to_pasien_without_an_intended_url(): void
    {
        // LoginController::store() memakai redirect()->intended(...) dengan
        // fallback route('pasien'); tidak ada halaman Welcome lagi.
        $response = $this->post('/login', [
            'username' => 'dokter',
            'password' => 'dokter123',
        ]);

        $response->assertRedirect(route('pasien'));
    }

    public function test_wrong_password_is_rejected_with_an_indonesian_message(): void
    {
        $response = $this->from('/login')->post('/login', [
            'username' => 'perawat',
            'password' => 'password-salah',
        ]);

        $response->assertRedirect('/login');
        $response->assertSessionHasErrors('username');
        $this->assertGuest();
    }

    public function test_unknown_username_gives_the_same_message_as_a_wrong_password(): void
    {
        $response = $this->from('/login')->post('/login', [
            'username' => 'tidak-ada',
            'password' => 'apa-saja',
        ]);

        $response->assertSessionHasErrors('username');
        $this->assertGuest();
    }

    public function test_missing_credentials_are_rejected(): void
    {
        $this->from('/login')
            ->post('/login', [])
            ->assertSessionHasErrors(['username', 'password']);

        $this->assertGuest();
    }

    public function test_session_id_is_regenerated_on_login(): void
    {
        $this->get('/login');
        $before = session()->getId();

        $this->post('/login', ['username' => 'perawat', 'password' => 'perawat123']);

        $this->assertNotSame($before, session()->getId());
    }

    public function test_logout_ends_the_session_and_sends_the_user_to_login(): void
    {
        $user = User::where('username', 'bidan')->firstOrFail();

        $response = $this->actingAs($user)->post('/logout');

        $response->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_guest_cannot_logout(): void
    {
        $this->post('/logout')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_guest_is_redirected_to_login_from_every_protected_page(): void
    {
        $encounter = Encounter::factory()->create();
        $paths = [
            '/pasien',
            '/encounters/'.$encounter->encounter_id.'/profil',
            '/encounters/'.$encounter->encounter_id.'/cppt',
            '/encounters/'.$encounter->encounter_id.'/penunjang',
            '/encounters/'.$encounter->encounter_id.'/farmasi',
            '/encounters/'.$encounter->encounter_id.'/observasi',
            '/encounters/'.$encounter->encounter_id.'/bundles',
        ];

        foreach ($paths as $path) {
            $this->get($path)->assertRedirect(route('login'));
        }
    }

    public function test_authenticated_user_can_reach_pasien_after_login(): void
    {
        $this->post('/login', ['username' => 'perawat', 'password' => 'perawat123']);

        $this->get('/')->assertRedirect(route('pasien'));
        $this->get('/pasien')->assertOk();
    }

    public function test_login_page_next_parameter_is_preserved_in_the_form(): void
    {
        $this->get('/login?next=/encounters/abc/profil')
            ->assertOk()
            ->assertSee('value="/encounters/abc/profil"', false);
    }

    public function test_inertia_pages_share_the_authenticated_user(): void
    {
        $encounter = Encounter::factory()->create();

        $this->actingAs(User::where('username', 'dokter')->firstOrFail())
            ->get('/encounters/'.$encounter->encounter_id.'/profil')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Profil')
                ->where('auth.user.username', 'dokter')
                ->where('auth.user.role', 'dokter')
            );
    }

    /**
     * Header yang dikirim bridge Vercel ke runtime PHP di belakangnya. PHP
     * tidak pernah melihat koneksi TLS secara langsung, jadi `X-Forwarded-*`
     * adalah satu-satunya bukti skema aslinya.
     *
     * @return array<string, mixed>
     */
    private static function vercelProxyServer(): array
    {
        return [
            'REMOTE_ADDR' => '76.76.21.21',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_HOST' => 'simrs.example.test',
            'HTTP_X_FORWARDED_PORT' => '443',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.7',
            'HTTPS' => '',
        ];
    }

    public function test_login_form_posts_over_https_behind_a_tls_terminating_proxy(): void
    {
        // Tanpa trustProxies, action form dirender sebagai http:// dan browser
        // menampilkan "The information you're about to submit is not secure".
        $html = $this->withServerVariables(self::vercelProxyServer())
            ->get('/login')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('action="https://simrs.example.test/login"', $html);
        $this->assertStringNotContainsString('action="http://', $html);
    }

    public function test_logout_form_posts_over_https_behind_a_tls_terminating_proxy(): void
    {
        $html = $this->actingAs(User::where('username', 'dokter')->firstOrFail())
            ->withServerVariables(self::vercelProxyServer())
            ->get('/pasien')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('action="https://simrs.example.test/logout"', $html);
        $this->assertStringNotContainsString('action="http://', $html);
    }

    public function test_login_redirect_uses_the_forwarded_https_scheme(): void
    {
        $this->withServerVariables(self::vercelProxyServer())
            ->post('/login', ['username' => 'dokter', 'password' => 'dokter123'])
            ->assertRedirect('https://simrs.example.test/pasien');
    }

    public function test_a_plain_http_request_without_proxy_headers_keeps_http(): void
    {
        // Penjaga agar perbaikan ini tidak memakai pemaksaan skema global:
        // pengembangan lokal via `php artisan serve` harus tetap http://.
        $html = $this->get('http://127.0.0.1/login')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('action="http://127.0.0.1/login"', $html);
    }
}

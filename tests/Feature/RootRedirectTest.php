<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `/` tidak punya halaman sendiri: rute tersebut hanya pintu masuk.
 *
 * Tamu -> 302 /login, pengguna yang sudah login -> 302 /pasien. Tes ini
 * menggantikan skeleton lama yang mengharapkan 200 (halaman Welcome sudah
 * dihapus).
 */
class RootRedirectTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/')->assertRedirect(route('login'));
    }

    public function test_authenticated_user_is_redirected_to_pasien(): void
    {
        $this->actingAs(User::factory()->perawat()->create())
            ->get('/')
            ->assertRedirect(route('pasien'));
    }

    public function test_redirects_are_temporary_not_permanent(): void
    {
        // 302, bukan 301/308: tujuan pengalihan masih boleh berubah tanpa
        // harus membersihkan cache peramban yang sudah menyimpan 301.
        $this->get('/')->assertStatus(302);

        $this->actingAs(User::factory()->dokter()->create())->get('/')->assertStatus(302);
    }

    public function test_each_role_reaches_pasien_from_root(): void
    {
        foreach (['perawat', 'bidan', 'dokter'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))
                ->get('/')
                ->assertRedirect(route('pasien'));
        }
    }

    public function test_root_never_returns_a_200_page(): void
    {
        // Guardian dari regressi "Welcome.vue dihapus": kalau suatu saat rute `/`
        // diubah lagi jadi halaman penuh, kedua assert ini menangkapnya.
        $guest = $this->get('/');
        $guest->assertStatus(302);
        $guest->assertHeader('location', url('/login'));

        $authed = $this->actingAs(User::factory()->bidan()->create())->get('/');
        $authed->assertStatus(302);
        $authed->assertHeader('location', url('/pasien'));
    }
}

<?php

namespace Tests\Feature;

use App\Models\User;
use Filament\Pages\Auth\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminPanelTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_sent_to_the_login_page(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
        $this->get('/admin/login')->assertOk();
    }

    public function test_a_local_user_can_log_in_and_open_the_dashboard(): void
    {
        $user = User::factory()->create(['password' => 'test-password']);

        Livewire::test(Login::class)
            ->fillForm(['email' => $user->email, 'password' => 'test-password'])
            ->call('authenticate')
            ->assertHasNoFormErrors();

        $this->assertAuthenticatedAs($user);
        config(['app.env' => 'local']);
        $this->get('/admin')->assertOk();
    }
}

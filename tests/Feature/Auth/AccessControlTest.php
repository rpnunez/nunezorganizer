<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Database\Seeders\DevUsersSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccessControlTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_from_protected_pages(): void
    {
        foreach (['dashboard', 'profile.edit', 'security.edit', 'appearance.edit'] as $route) {
            $this->get(route($route))->assertRedirect(route('login'));
        }
    }

    public function test_unverified_users_cannot_reach_the_dashboard(): void
    {
        $this->actingAs(User::factory()->unverified()->create())
            ->get(route('dashboard'))
            ->assertRedirect(route('verification.notice'));
    }

    public function test_unapproved_users_are_signed_out(): void
    {
        $this->actingAs(User::factory()->unapproved()->create())
            ->getJson(route('dashboard'))
            ->assertForbidden();

        $this->assertGuest();
    }

    public function test_dev_seeder_creates_two_distinct_users_who_can_each_log_in(): void
    {
        $this->seed(DevUsersSeeder::class);

        foreach (['alice@example.test', 'bob@example.test'] as $email) {
            $this->post(route('login.store'), ['email' => $email, 'password' => 'password'])
                ->assertRedirect(route('dashboard', absolute: false));

            $this->assertAuthenticatedAs(User::where('email', $email)->firstOrFail());

            auth()->guard('web')->logout();
        }

        $this->assertDatabaseCount('users', 2);
    }
}

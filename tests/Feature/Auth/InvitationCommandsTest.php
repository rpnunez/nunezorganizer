<?php

namespace Tests\Feature\Auth;

use App\Models\Invitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvitationCommandsTest extends TestCase
{
    use RefreshDatabase;

    public function test_invite_command_creates_a_hashed_single_use_invitation(): void
    {
        $this->artisan('nunez:invite', ['email' => ' New.Person@Example.test '])
            ->expectsOutputToContain('Invitation created for new.person@example.test')
            ->expectsOutputToContain('?invite=')
            ->assertSuccessful();

        $invitation = Invitation::sole();

        $this->assertSame('new.person@example.test', $invitation->email);
        $this->assertSame(64, strlen($invitation->token_hash));
        $this->assertTrue($invitation->expires_at->between(now()->addDays(7)->subMinute(), now()->addDays(7)->addMinute()));
    }

    public function test_invite_command_honours_custom_expiry(): void
    {
        $this->artisan('nunez:invite', ['email' => 'short@example.test', '--days' => 2])->assertSuccessful();

        $this->assertTrue(Invitation::sole()->expires_at->lessThan(now()->addDays(3)));
    }

    public function test_invite_command_rejects_invalid_and_existing_emails(): void
    {
        User::factory()->create(['email' => 'exists@example.test']);

        $this->artisan('nunez:invite', ['email' => 'not-an-email'])->assertFailed();
        $this->artisan('nunez:invite', ['email' => 'exists@example.test'])->assertFailed();

        $this->assertDatabaseCount('invitations', 0);
    }

    public function test_approve_command_approves_pending_users(): void
    {
        $user = User::factory()->unapproved()->create(['email' => 'pending@example.test']);

        $this->artisan('nunez:user:approve', ['email' => 'pending@example.test'])
            ->expectsOutputToContain('has been approved')
            ->assertSuccessful();

        $this->assertTrue($user->refresh()->isApproved());
    }

    public function test_approve_command_fails_for_unknown_user(): void
    {
        $this->artisan('nunez:user:approve', ['email' => 'nobody@example.test'])->assertFailed();
    }
}

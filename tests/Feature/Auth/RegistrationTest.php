<?php

namespace Tests\Feature\Auth;

use App\Models\Invitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Fortify\Features;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessFortifyHas(Features::registration());
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function payload(string $email = 'invitee@example.test', array $overrides = []): array
    {
        return array_merge([
            'name' => 'Invited Person',
            'email' => $email,
            'password' => 'password',
            'password_confirmation' => 'password',
        ], $overrides);
    }

    // ---- invite mode (default) ----------------------------------------------

    public function test_registration_is_invite_only_by_default(): void
    {
        $this->assertSame('invite', config('nunezorganizer.registration.mode'));

        $this->get(route('register'))->assertNotFound();
    }

    public function test_registration_screen_rejects_unknown_tokens(): void
    {
        $this->get(route('register', ['invite' => 'not-a-real-token']))->assertNotFound();
    }

    public function test_registration_screen_renders_for_a_valid_invitation(): void
    {
        [, $token] = Invitation::issue('invitee@example.test');

        $this->get(route('register', ['invite' => $token]))
            ->assertOk()
            ->assertSee('invitee@example.test');
    }

    public function test_expired_or_used_invitations_cannot_open_the_registration_screen(): void
    {
        [$expired, $expiredToken] = Invitation::issue('expired@example.test');
        $expired->forceFill(['expires_at' => now()->subMinute()])->save();

        [$used, $usedToken] = Invitation::issue('used@example.test');
        $used->forceFill(['accepted_at' => now()])->save();

        $this->get(route('register', ['invite' => $expiredToken]))->assertNotFound();
        $this->get(route('register', ['invite' => $usedToken]))->assertNotFound();
    }

    public function test_invited_user_can_register_once(): void
    {
        [$invitation, $token] = Invitation::issue('invitee@example.test');

        $this->post(route('register.store'), $this->payload(overrides: ['invitation' => $token]))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticated();

        $user = User::where('email', 'invitee@example.test')->firstOrFail();
        $invitation->refresh();

        $this->assertTrue($user->isApproved());
        $this->assertNotNull($invitation->accepted_at);
        $this->assertSame($user->id, $invitation->accepted_user_id);
    }

    public function test_invitation_cannot_be_reused(): void
    {
        [, $token] = Invitation::issue('invitee@example.test');

        $this->post(route('register.store'), $this->payload(overrides: ['invitation' => $token]));
        auth()->guard('web')->logout();

        $this->post(route('register.store'), $this->payload('second@example.test', ['invitation' => $token]))
            ->assertSessionHasErrors('email');

        $this->assertDatabaseMissing('users', ['email' => 'second@example.test']);
    }

    public function test_invitation_is_bound_to_its_email_address(): void
    {
        [, $token] = Invitation::issue('invitee@example.test');

        $this->post(route('register.store'), $this->payload('someone-else@example.test', ['invitation' => $token]))
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_registration_without_invitation_is_rejected(): void
    {
        $this->post(route('register.store'), $this->payload())
            ->assertSessionHasErrors('invitation');

        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_invitation_tokens_are_stored_hashed(): void
    {
        [$invitation, $token] = Invitation::issue('invitee@example.test');

        $this->assertSame(hash('sha256', $token), $invitation->token_hash);
        $this->assertDatabaseMissing('invitations', ['token_hash' => $token]);
    }

    public function test_registration_is_rate_limited(): void
    {
        foreach (range(1, 5) as $i) {
            $this->post(route('register.store'), $this->payload("spam{$i}@example.test"));
        }

        $this->post(route('register.store'), $this->payload('spam6@example.test'))
            ->assertSessionHasErrors('email');

        $this->assertStringContainsString(
            'Too many registration attempts',
            (string) session('errors')?->first('email'),
        );
    }

    public function test_login_page_hides_sign_up_link_in_invite_mode(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertDontSee('data-test="signup-link"', false);
    }

    // ---- open mode -----------------------------------------------------------

    public function test_open_mode_allows_self_registration(): void
    {
        config(['nunezorganizer.registration.mode' => 'open']);

        $this->get(route('login'))->assertSee('data-test="signup-link"', false);
        $this->get(route('register'))->assertOk();

        $this->post(route('register.store'), $this->payload('open@example.test'))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertTrue(User::where('email', 'open@example.test')->firstOrFail()->isApproved());
    }

    // ---- approval mode -------------------------------------------------------

    public function test_approval_mode_blocks_access_until_approved(): void
    {
        config(['nunezorganizer.registration.mode' => 'approval']);

        $this->post(route('register.store'), $this->payload('pending@example.test'))
            ->assertSessionHasNoErrors();

        $this->assertFalse(User::where('email', 'pending@example.test')->firstOrFail()->isApproved());

        $this->get(route('dashboard'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('status', __('Your account is awaiting approval.'));

        $this->assertGuest();
    }

    public function test_unknown_mode_falls_back_to_invite_only(): void
    {
        config(['nunezorganizer.registration.mode' => 'something-else']);

        $this->get(route('register'))->assertNotFound();
    }
}

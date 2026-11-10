<?php

namespace Tests\Feature\Security;

use App\Http\Middleware\SecurityHeaders;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class HttpControlsTest extends TestCase
{
    use RefreshDatabase;

    public function test_security_headers_are_present_on_web_and_api_responses(): void
    {
        $this->get('/')->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');

        config()->set('app.env', 'production');
        $request = Request::create('https://example.test/healthz');
        $response = (new SecurityHeaders)->handle($request, fn () => new Response('ok'));
        $this->assertSame('max-age=31536000; includeSubDomains', $response->headers->get('Strict-Transport-Security'));
        $this->assertNotNull($response->headers->get('Content-Security-Policy'));
    }

    public function test_password_reset_request_is_rate_limited(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post(route('password.email'), ['email' => $user->email])->assertRedirect();
        }

        $this->post(route('password.email'), ['email' => $user->email])->assertStatus(429);
    }

    public function test_telegram_pairing_api_has_a_tighter_limit_than_general_api(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['tasks:write']);
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/v1/telegram/pairing')->assertOk();
        }
        $this->postJson('/api/v1/telegram/pairing')->assertStatus(429);
    }
}

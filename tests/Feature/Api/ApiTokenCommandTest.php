<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class ApiTokenCommandTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    public function test_creates_token_with_requested_abilities(): void
    {
        $alice = $this->alice();

        $this->artisan('nunez:token', ['email' => 'alice@example.test', '--ability' => ['tasks:read', 'tasks:write']])
            ->expectsOutputToContain('shown only once')
            ->assertSuccessful();

        $token = $alice->tokens()->sole();
        $this->assertSame(['tasks:read', 'tasks:write'], $token->abilities);
    }

    public function test_rejects_unknown_abilities(): void
    {
        $this->alice();

        $this->artisan('nunez:token', ['email' => 'alice@example.test', '--ability' => ['*']])->assertFailed();
        $this->assertSame(0, $this->alice()->tokens()->count());
    }

    public function test_revoke_only_affects_the_owners_tokens(): void
    {
        $bobTokenId = $this->bob()->createToken('bob')->accessToken->getKey();
        $this->alice();

        $this->artisan('nunez:token', ['email' => 'alice@example.test', '--revoke' => (string) $bobTokenId])->assertFailed();
        $this->assertSame(1, $this->bob()->tokens()->count());
    }
}

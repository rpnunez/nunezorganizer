<?php

namespace Tests\Feature\Security;

use App\Models\Task;
use App\Models\TaskOccurrence;
use App\Models\TelegramLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class UserDataCommandsTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    public function test_export_omits_credentials_and_never_includes_another_user(): void
    {
        $alice = $this->alice();
        $bob = $this->bob();
        $this->asTenant($alice, function () use ($alice): void {
            $task = Task::factory()->create(['title' => 'Alice private task']);
            TaskOccurrence::factory()->forTask($task)->create();
            TelegramLink::create(['user_id' => $alice->id, 'telegram_user_id' => 111,
                'telegram_chat_id' => 111, 'status' => 'active', 'linked_at' => now()]);
        });
        $this->asTenant($bob, fn () => Task::factory()->create(['title' => 'Bob private task']));
        $alice->createToken('private-token', ['tasks:read']);

        $path = tempnam(sys_get_temp_dir(), 'nunez-export-');
        unlink($path);
        try {
            $this->artisan('nunez:user:export', ['email' => $alice->email, '--output' => $path])->assertSuccessful();
            $json = file_get_contents($path);
            $this->assertIsString($json);
            $this->assertStringContainsString('Alice private task', $json);
            $this->assertStringNotContainsString('Bob private task', $json);
            $this->assertStringNotContainsString('private-token', $json);
            $this->assertStringNotContainsString('password', $json);
            $this->assertStringNotContainsString('token_hash', $json);
        } finally {
            @unlink($path);
        }
    }

    public function test_delete_requires_exact_confirmation_and_preserves_other_user(): void
    {
        $alice = $this->alice();
        $bob = $this->bob();
        $aliceTask = $this->asTenant($alice, fn () => Task::factory()->create(['title' => 'Alice private task']));
        $bobTask = $this->asTenant($bob, fn () => Task::factory()->create(['title' => 'Bob private task']));
        $aliceAccount = $this->accountOf($alice);
        $bobAccount = $this->accountOf($bob);
        $alice->createToken('delete-me', ['tasks:read']);

        $this->artisan('nunez:user:delete', ['email' => $alice->email, '--confirm-email' => 'wrong@example.test'])
            ->assertFailed();
        $this->assertDatabaseHas('users', ['id' => $alice->id]);

        $this->artisan('nunez:user:delete', ['email' => $alice->email, '--confirm-email' => $alice->email])
            ->assertSuccessful();
        $this->assertDatabaseMissing('users', ['id' => $alice->id]);
        $this->assertDatabaseMissing('accounts', ['id' => $aliceAccount->id]);
        $this->assertDatabaseMissing('tasks', ['id' => $aliceTask->id]);
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $alice->id]);
        $this->assertDatabaseHas('users', ['id' => $bob->id]);
        $this->assertDatabaseHas('accounts', ['id' => $bobAccount->id]);
        $this->assertDatabaseHas('tasks', ['id' => $bobTask->id]);
        $this->assertDatabaseHas('audit_events', ['action' => 'account.created',
            'actor_user_id' => null, 'account_id' => null, 'subject_id' => null]);
    }

    public function test_export_never_overwrites_an_existing_file(): void
    {
        $alice = $this->alice();
        $path = tempnam(sys_get_temp_dir(), 'nunez-export-');
        $this->assertIsString($path);
        try {
            file_put_contents($path, 'keep this content');
            $this->artisan('nunez:user:export', ['email' => $alice->email, '--output' => $path])->assertFailed();
            $this->assertSame('keep this content', file_get_contents($path));
        } finally {
            @unlink($path);
        }
    }
}

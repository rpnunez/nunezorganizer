<?php

namespace Tests\Feature\Api;

use App\Enums\OccurrenceState;
use App\Enums\TaskStatus;
use App\Models\AuditEvent;
use App\Models\Task;
use App\Models\TaskOccurrence;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * #8: scoped task CRUD over /api/v1 with Sanctum abilities.
 */
class TaskApiTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private function actAs(User $user, array $abilities = ['tasks:read', 'tasks:write']): void
    {
        Sanctum::actingAs($user, $abilities);
    }

    private function taskFor(User $user, array $state = []): Task
    {
        return $this->asTenant($user, fn () => Task::factory()->state($state)->create());
    }

    // ---- authentication & abilities --------------------------------------------------

    public function test_unauthenticated_requests_get_401(): void
    {
        $this->getJson('/api/v1/tasks')->assertUnauthorized();
        $this->postJson('/api/v1/tasks', ['title' => 'x'])->assertUnauthorized();
    }

    public function test_read_only_token_cannot_write(): void
    {
        $this->actAs($this->alice(), ['tasks:read']);

        $this->getJson('/api/v1/tasks')->assertOk();
        $this->postJson('/api/v1/tasks', ['title' => 'x'])->assertForbidden();
    }

    public function test_token_without_read_ability_cannot_list(): void
    {
        $this->actAs($this->alice(), ['tasks:write']);

        $this->getJson('/api/v1/tasks')->assertForbidden();
    }

    public function test_real_bearer_token_works_end_to_end(): void
    {
        $token = $this->alice()->createToken('test', ['tasks:read'])->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('user.email', 'alice@example.test')
            ->assertJsonPath('account.id', $this->accountOf($this->alice())->id)
            ->assertJsonPath('user.timezone', 'UTC');
    }

    // ---- isolation (T01 / T02) ----------------------------------------------------------

    public function test_users_only_see_their_own_tasks(): void
    {
        $this->taskFor($this->alice(), ['title' => 'Alice task']);
        $this->taskFor($this->bob(), ['title' => 'Bob task']);

        $this->actAs($this->alice());

        $this->getJson('/api/v1/tasks')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Alice task');
    }

    public function test_foreign_task_returns_404_on_every_endpoint_without_side_effects(): void
    {
        $bobTask = $this->taskFor($this->bob());
        $this->actAs($this->alice());

        $this->getJson("/api/v1/tasks/{$bobTask->id}")->assertNotFound()->assertExactJson(['message' => 'Not found.']);
        $this->patchJson("/api/v1/tasks/{$bobTask->id}", ['title' => 'pwned'])->assertNotFound();
        $this->postJson("/api/v1/tasks/{$bobTask->id}/cancel")->assertNotFound();

        $fresh = $this->withoutTenancy(fn () => $bobTask->fresh());
        $this->assertNotSame('pwned', $fresh?->title);
        $this->assertSame(TaskStatus::Open, $fresh?->status);
    }

    public function test_forged_ownership_fields_are_rejected(): void
    {
        $this->actAs($this->alice());
        $bobAccountId = $this->accountOf($this->bob())->id;

        $this->postJson('/api/v1/tasks', ['title' => 'x', 'account_id' => $bobAccountId])
            ->assertUnprocessable()->assertJsonValidationErrors('account_id');
        $this->postJson('/api/v1/tasks', ['title' => 'x', 'owner_user_id' => $this->bob()->id])
            ->assertUnprocessable()->assertJsonValidationErrors('owner_user_id');
        $this->postJson('/api/v1/tasks', ['title' => 'x', 'status' => 'completed'])
            ->assertUnprocessable()->assertJsonValidationErrors('status');

        $aliceTask = $this->taskFor($this->alice());
        $this->patchJson("/api/v1/tasks/{$aliceTask->id}", ['owner_user_id' => $this->bob()->id])
            ->assertUnprocessable();

        $this->assertSame(0, $this->asTenant($this->bob(), fn () => Task::count()));
    }

    // ---- create -----------------------------------------------------------------------

    public function test_create_task_without_occurrence(): void
    {
        $this->actAs($this->alice());

        $response = $this->postJson('/api/v1/tasks', ['title' => 'Organize my desk', 'duration_minutes' => 15])
            ->assertCreated()
            ->assertJsonPath('task.title', 'Organize my desk')
            ->assertJsonPath('task.status', 'open')
            ->assertJsonPath('occurrence', null);

        $id = $response->json('task.id');
        $task = $this->asTenant($this->alice(), fn () => Task::findOrFail($id));
        $this->assertSame($this->alice()->id, $task->owner_user_id);
        $this->assertTrue(AuditEvent::where('action', 'task.created')->where('subject_id', $id)->exists());
    }

    public function test_create_task_with_initial_occurrence_stores_utc(): void
    {
        $this->actAs($this->alice());

        $this->postJson('/api/v1/tasks', [
            'title' => 'Clean or organize one area',
            'duration_minutes' => 15,
            'occurrence' => [
                'starts_at' => '2026-11-09T19:00:00-05:00',
                'ends_at' => '2026-11-09T19:15:00-05:00',
                'timezone' => 'America/New_York',
            ],
        ])
            ->assertCreated()
            ->assertJsonPath('occurrence.state', 'scheduled')
            ->assertJsonPath('occurrence.starts_at', '2026-11-10T00:00:00Z')
            ->assertJsonPath('occurrence.ends_at', '2026-11-10T00:15:00Z')
            ->assertJsonPath('occurrence.local.starts_at', '2026-11-09T19:00:00-05:00')
            ->assertJsonPath('occurrence.timezone', 'America/New_York');
    }

    public function test_occurrence_without_offset_is_interpreted_in_its_timezone(): void
    {
        $this->actAs($this->alice());

        $this->postJson('/api/v1/tasks', [
            'title' => 'Madrid block',
            'occurrence' => ['starts_at' => '2026-07-01 09:00', 'ends_at' => '2026-07-01 09:30', 'timezone' => 'Europe/Madrid'],
        ])->assertCreated()->assertJsonPath('occurrence.starts_at', '2026-07-01T07:00:00Z');
    }

    public function test_invalid_occurrence_is_rejected_and_nothing_is_created(): void
    {
        $this->actAs($this->alice());

        $this->postJson('/api/v1/tasks', [
            'title' => 'Backwards',
            'occurrence' => ['starts_at' => '2026-11-09T19:15:00Z', 'ends_at' => '2026-11-09T19:00:00Z', 'timezone' => 'UTC'],
        ])->assertUnprocessable()->assertJsonValidationErrors('occurrence.ends_at');

        $this->postJson('/api/v1/tasks', [
            'title' => 'Bad zone',
            'occurrence' => ['starts_at' => '2026-11-09T19:00:00Z', 'ends_at' => '2026-11-09T19:15:00Z', 'timezone' => 'Mars/Base'],
        ])->assertUnprocessable()->assertJsonValidationErrors('occurrence.timezone');

        // The transaction rolled back: the task from the first request does not exist.
        $this->assertSame(0, $this->asTenant($this->alice(), fn () => Task::count()));
    }

    public function test_validation_errors(): void
    {
        $this->actAs($this->alice());

        $this->postJson('/api/v1/tasks', [])->assertUnprocessable()->assertJsonValidationErrors('title');
        $this->postJson('/api/v1/tasks', ['title' => 'x', 'duration_minutes' => 0])->assertJsonValidationErrors('duration_minutes');
        $this->postJson('/api/v1/tasks', ['title' => str_repeat('a', 201)])->assertJsonValidationErrors('title');
    }

    // ---- read / list ------------------------------------------------------------------

    public function test_show_includes_occurrence_history(): void
    {
        $alice = $this->alice();
        $task = $this->asTenant($alice, function () {
            $task = Task::factory()->create();
            TaskOccurrence::factory()->forTask($task)->at('2026-11-09 15:00:00')->missed()->create();
            TaskOccurrence::factory()->forTask($task)->at('2026-11-10 15:00:00')->create();

            return $task;
        });

        $this->actAs($alice);

        $this->getJson("/api/v1/tasks/{$task->id}")
            ->assertOk()
            ->assertJsonCount(2, 'data.occurrences')
            ->assertJsonPath('data.occurrences.0.state', OccurrenceState::Missed->value);
    }

    public function test_list_filters_by_status_and_paginates_with_cursor(): void
    {
        $alice = $this->alice();
        $this->asTenant($alice, function () {
            Task::factory()->count(3)->create();
            Task::factory()->completed()->create();
        });

        $this->actAs($alice);

        $this->getJson('/api/v1/tasks?status=open')->assertJsonCount(3, 'data');
        $this->getJson('/api/v1/tasks?status=completed')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/tasks?status=bogus')->assertUnprocessable();

        $page = $this->getJson('/api/v1/tasks?per_page=2')->assertJsonCount(2, 'data');
        $next = $page->json('meta.next_cursor');
        $this->assertNotNull($next);
        $this->getJson('/api/v1/tasks?per_page=2&cursor='.$next)->assertJsonCount(2, 'data');
    }

    // ---- update / cancel ---------------------------------------------------------------

    public function test_update_open_task(): void
    {
        $task = $this->taskFor($this->alice());
        $this->actAs($this->alice());

        $this->patchJson("/api/v1/tasks/{$task->id}", ['title' => 'Renamed', 'duration_minutes' => 30])
            ->assertOk()
            ->assertJsonPath('data.title', 'Renamed')
            ->assertJsonPath('data.duration_minutes', 30);
    }

    public function test_completed_task_cannot_be_edited_or_cancelled(): void
    {
        $task = $this->taskFor($this->alice(), ['status' => TaskStatus::Completed, 'completed_at' => now()]);
        $this->actAs($this->alice());

        $this->patchJson("/api/v1/tasks/{$task->id}", ['title' => 'x'])->assertStatus(409);
        $this->postJson("/api/v1/tasks/{$task->id}/cancel")->assertStatus(409);
    }

    public function test_cancel_is_explicit_idempotent_and_cancels_pending_occurrences(): void
    {
        $alice = $this->alice();
        $task = $this->asTenant($alice, function () {
            $task = Task::factory()->create();
            TaskOccurrence::factory()->forTask($task)->create();
            TaskOccurrence::factory()->forTask($task)->at('2026-11-01 10:00:00')->missed()->create();

            return $task;
        });
        $this->actAs($alice);

        $this->postJson("/api/v1/tasks/{$task->id}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->postJson("/api/v1/tasks/{$task->id}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');

        $states = $this->asTenant($alice, fn () => $task->occurrences()->pluck('state')->map->value->sort()->values()->all());
        $this->assertSame(['cancelled', 'missed'], $states, 'Pending occurrence cancelled; past history untouched.');
        $this->assertSame(1, AuditEvent::where('action', 'task.cancelled')->count());
    }

    // ---- idempotency ---------------------------------------------------------------------

    public function test_idempotency_key_replays_the_first_response_instead_of_creating_twice(): void
    {
        $this->actAs($this->alice());
        $headers = ['Idempotency-Key' => 'create-desk-1'];

        $first = $this->postJson('/api/v1/tasks', ['title' => 'Desk'], $headers)->assertCreated();
        $second = $this->postJson('/api/v1/tasks', ['title' => 'Desk'], $headers)->assertCreated()->assertHeader('Idempotent-Replayed', 'true');

        $this->assertSame($first->json('task.id'), $second->json('task.id'));
        $this->assertSame(1, $this->asTenant($this->alice(), fn () => Task::count()));
    }

    public function test_reusing_a_key_with_a_different_payload_is_rejected(): void
    {
        $this->actAs($this->alice());
        $headers = ['Idempotency-Key' => 'k-1'];

        $this->postJson('/api/v1/tasks', ['title' => 'One'], $headers)->assertCreated();
        $this->postJson('/api/v1/tasks', ['title' => 'Two'], $headers)->assertUnprocessable();
    }

    public function test_idempotency_keys_are_namespaced_per_user(): void
    {
        $headers = ['Idempotency-Key' => 'same-key'];

        $this->actAs($this->alice());
        $aliceId = $this->postJson('/api/v1/tasks', ['title' => 'Same'], $headers)->json('task.id');

        $this->actAs($this->bob());
        $bobResponse = $this->postJson('/api/v1/tasks', ['title' => 'Same'], $headers)->assertCreated();

        $this->assertNotSame($aliceId, $bobResponse->json('task.id'), 'Bob must never receive Alice\'s replayed response.');
        $bobResponse->assertHeaderMissing('Idempotent-Replayed');
    }

    public function test_malformed_idempotency_key_is_rejected(): void
    {
        $this->actAs($this->alice());

        $this->postJson('/api/v1/tasks', ['title' => 'x'], ['Idempotency-Key' => 'bad key with spaces'])->assertStatus(400);
    }
}

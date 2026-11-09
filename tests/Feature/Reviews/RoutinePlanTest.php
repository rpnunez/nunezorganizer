<?php

namespace Tests\Feature\Reviews;

use App\Domain\Reviews\DailyReviewContent;
use App\Domain\Reviews\RoutinePlanService;
use App\Domain\Scheduling\Actions\RecordResponse;
use App\Enums\ResponseChannel;
use App\Enums\ResponseType;
use App\Models\DailyReview;
use App\Models\RoutinePlan;
use App\Models\Task;
use App\Models\TaskOccurrence;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class RoutinePlanTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    public function test_acceptance_is_opt_in_idempotent_and_creates_no_slots(): void
    {
        $this->travelTo('2026-11-09 12:00:00 UTC');
        $alice = $this->alice();
        $bob = $this->bob();
        $service = app(RoutinePlanService::class);
        $settings = ['target_days' => 5, 'preferred_time' => '19:00', 'max_backlog' => 2];
        $plan = $this->asTenant($alice, fn () => $service->start($alice, 'four-week-ramp', $settings));
        $bobPlan = $this->asTenant($bob, fn () => $service->start($bob, 'start-small',
            ['target_days' => 3, 'preferred_time' => '08:00', 'max_backlog' => 5]));

        $this->asTenant($alice, function () use ($alice): void {
            for ($day = 9; $day <= 13; $day++) {
                $task = Task::factory()->create();
                $block = TaskOccurrence::factory()->forTask($task)->at("2026-11-{$day} 13:00:00")->create();
                app(RecordResponse::class)->handle($task, $block, ResponseType::Yes, $alice,
                    ResponseChannel::Web, "routine-{$day}");
            }
        });
        $this->assertSame(5, $this->asTenant($alice, fn () => TaskOccurrence::count()));
        $this->assertSame(1, $this->asTenant($alice, fn () => RoutinePlan::findOrFail($plan->id)->current_week));

        $this->travelTo('2026-11-16 01:00:00 UTC'); // Sunday evening in New York would also qualify; UTC Sunday is past week-end.
        $this->asTenant($alice, fn () => $service->accept($alice, $plan->id, 'accept-week-one'));
        $this->asTenant($alice, fn () => $service->accept($alice, $plan->id, 'accept-week-one'));
        $this->assertSame(2, $this->asTenant($alice, fn () => RoutinePlan::findOrFail($plan->id)->current_week));
        $this->assertSame(1, $this->asTenant($bob, fn () => RoutinePlan::findOrFail($bobPlan->id)->current_week));
        $this->assertSame(5, $this->asTenant($alice, fn () => TaskOccurrence::count()));
        $this->assertDatabaseCount('routine_plan_advancements', 1);
    }

    public function test_weak_week_cannot_advance_and_foreign_plan_is_hidden(): void
    {
        $alice = $this->alice();
        $bob = $this->bob();
        $service = app(RoutinePlanService::class);
        $this->travelTo('2026-11-09 12:00:00 UTC');
        $plan = $this->asTenant($alice, fn () => $service->start($alice, 'start-small',
            ['target_days' => 5, 'preferred_time' => '18:00', 'max_backlog' => 2]));
        $this->travelTo('2026-11-16 01:00:00 UTC');

        $this->expectException(ValidationException::class);
        try {
            $this->asTenant($alice, fn () => $service->accept($alice, $plan->id, 'too-early'));
        } finally {
            $this->assertSame(1, $this->asTenant($alice, fn () => RoutinePlan::findOrFail($plan->id)->current_week));
            try {
                $this->asTenant($bob, fn () => $service->accept($bob, $plan->id, 'foreign'));
                $this->fail('Foreign plan was visible.');
            } catch (ModelNotFoundException) {
                // Tenant-scoped lookup is hidden as 404 in HTTP.
            }
        }
    }

    public function test_progress_page_starts_a_custom_plan_without_scheduling_work(): void
    {
        $alice = $this->alice();
        $this->actingAs($alice);
        Livewire::test('pages::progress')
            ->set('templateKey', 'four-week-ramp')
            ->set('targetDays', 3)
            ->set('preferredTime', '08:30')
            ->set('maxBacklog', 4)
            ->call('startPlan')->assertHasNoErrors()
            ->assertSee('Week 1 of 4');

        $plan = $this->asTenant($alice, fn () => RoutinePlan::where('user_id', $alice->id)->sole());
        $this->assertSame('four-week-ramp', $plan->template_key);
        $this->assertSame(3, $plan->settings['target_days']);
        $this->assertSame('08:30', $plan->settings['preferred_time']);
        $this->assertDatabaseCount('task_occurrences', 0);
    }

    public function test_sunday_evening_review_includes_a_reviewed_suggestion(): void
    {
        $this->travelTo('2026-11-09 12:00:00 UTC');
        $alice = $this->alice();
        $text = $this->asTenant($alice, function () use ($alice): string {
            app(RoutinePlanService::class)->start($alice, 'start-small',
                ['target_days' => 5, 'preferred_time' => '19:00', 'max_backlog' => 2]);
            $review = DailyReview::create(['user_id' => $alice->id, 'local_date' => '2026-11-15',
                'kind' => 'evening', 'status' => 'pending']);

            return app(DailyReviewContent::class)->build($alice, $review, 'UTC');
        });

        $this->assertStringContainsString('Keep this pace', $text);
        $this->assertStringContainsString(route('progress'), $text);
        $this->assertDatabaseCount('task_occurrences', 0);
    }
}

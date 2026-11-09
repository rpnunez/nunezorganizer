<?php

namespace Tests\Feature\Settings;

use App\Models\Task;
use App\Models\UserPreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class RoutineSettingsTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    public function test_settings_save_local_preferences_for_only_the_current_user(): void
    {
        $alice = $this->alice();
        $bob = $this->bob();
        $this->actingAs($alice);

        Livewire::test('pages::settings.routine')
            ->set('timezone', 'Europe/Madrid')
            ->set('preferredActivityStart', '19:00')
            ->set('graceMinutes', 20)
            ->set('unansweredAfterMinutes', 90)
            ->set('workWindows', ['mon' => [['start' => '19:00', 'end' => '19:15']]])
            ->set('quietEnabled', true)
            ->set('quietStart', '22:00')
            ->set('quietEnd', '07:00')
            ->set('reviewEnabled.morning', true)
            ->set('reviewTime.morning', '10:45')
            ->set('dailyReviews', true)
            ->call('save')
            ->assertHasNoErrors();

        $alicePrefs = $this->withoutTenancy(fn () => UserPreference::where('user_id', $alice->id)->firstOrFail());
        $bobPrefs = $this->withoutTenancy(fn () => UserPreference::where('user_id', $bob->id)->firstOrFail());

        $this->assertSame('Europe/Madrid', $alicePrefs->timezone);
        $this->assertSame('19:00', $alicePrefs->preferred_activity_start);
        $this->assertSame(20, $alicePrefs->grace_minutes);
        $this->assertSame(90, $alicePrefs->unanswered_after_minutes);
        $this->assertSame(['mon' => [['start' => '19:00', 'end' => '19:15']]], $alicePrefs->work_windows);
        $this->assertSame(['start' => '22:00', 'end' => '07:00'], $alicePrefs->quiet_hours);
        $this->assertSame('10:45', $alicePrefs->review_times['morning']);
        $this->assertTrue($alicePrefs->notification_preferences['daily_reviews']);
        $this->assertSame('UTC', $bobPrefs->timezone);
        $this->assertNull($bobPrefs->work_windows);
    }

    public function test_template_only_prefills_until_saved(): void
    {
        $alice = $this->alice();
        $this->actingAs($alice);

        Livewire::test('pages::settings.routine')
            ->call('applyTemplate', 'nunez-week1')
            ->assertSet('preferredActivityStart', '19:00')
            ->assertSet('reviewTime.morning', '10:45');

        $before = $this->withoutTenancy(fn () => UserPreference::where('user_id', $alice->id)->firstOrFail());
        $this->assertNull($before->preferred_activity_start);
        $this->assertNull($before->review_times);

        Livewire::test('pages::settings.routine')->call('applyTemplate', 'nunez-week1')->call('save')->assertHasNoErrors();
        $after = $this->withoutTenancy(fn () => UserPreference::where('user_id', $alice->id)->firstOrFail());
        $this->assertSame('19:00', $after->preferred_activity_start);
        $this->assertSame('19:15', $after->work_windows['mon'][0]['end']);
    }

    public function test_invalid_timezone_and_full_day_quiet_hours_are_rejected(): void
    {
        $this->actingAs($this->alice());

        Livewire::test('pages::settings.routine')->set('timezone', 'Mars/Base')->call('save')->assertHasErrors(['timezone']);
        Livewire::test('pages::settings.routine')
            ->set('quietEnabled', true)->set('quietStart', '22:00')->set('quietEnd', '22:00')
            ->call('save')->assertHasErrors(['quiet_hours.end']);
    }

    public function test_onboarding_saves_choices_then_creates_one_first_task(): void
    {
        $alice = $this->alice();
        $this->actingAs($alice);

        Livewire::test('pages::onboarding')
            ->set('timezone', 'Europe/Madrid')
            ->call('next')->assertSet('step', 2)
            ->call('applyTemplate', 'nunez-week1')
            ->call('saveRoutine')->assertSet('step', 3)
            ->set('firstTask', 'Organize my desk')
            ->call('finish')->assertRedirect()
            ->call('finish');

        $prefs = $this->withoutTenancy(fn () => UserPreference::where('user_id', $alice->id)->firstOrFail());
        $this->assertSame('Europe/Madrid', $prefs->timezone);
        $this->assertNotNull($prefs->onboarding_completed_at);
        $this->assertSame(1, $this->asTenant($alice, fn () => Task::where('title', 'Organize my desk')->count()));
    }

    public function test_settings_and_onboarding_require_login(): void
    {
        $this->get(route('routine.edit'))->assertRedirect(route('login'));
        $this->get(route('onboarding'))->assertRedirect(route('login'));
    }

    public function test_authenticated_pages_render_with_the_onboarding_entry_point(): void
    {
        $this->actingAs($this->alice());

        $this->get(route('dashboard'))->assertOk()->assertSee('Set up your routine');
        $this->get(route('routine.edit'))->assertOk()->assertSee('Mark unanswered after');
        $this->get(route('onboarding'))->assertOk()->assertSee('Step 1 of 3');
    }
}

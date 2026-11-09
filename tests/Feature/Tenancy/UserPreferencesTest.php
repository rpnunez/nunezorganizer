<?php

namespace Tests\Feature\Tenancy;

use App\Domain\Accounts\Actions\UpdateUserPreferences;
use App\Models\AuditEvent;
use App\Models\UserPreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class UserPreferencesTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    /**
     * @param  array<string, mixed>  $input
     */
    private function update(array $input): UserPreference
    {
        $alice = $this->alice();

        return $this->asTenant($alice, fn () => app(UpdateUserPreferences::class)->handle($alice, $input));
    }

    public function test_valid_preferences_persist(): void
    {
        $preference = $this->update([
            'timezone' => 'America/New_York',
            'grace_minutes' => 20,
            'quiet_hours' => ['start' => '22:00', 'end' => '07:00'],
            'review_times' => ['morning' => '10:45', 'afternoon' => '14:00', 'evening' => '20:00'],
            'work_windows' => ['mon' => [['start' => '10:00', 'end' => '18:00']]],
        ]);

        $fresh = $this->withoutTenancy(fn () => $preference->fresh());

        $this->assertSame('America/New_York', $fresh?->timezone);
        $this->assertSame(20, $fresh?->grace_minutes);
        $this->assertSame(['start' => '22:00', 'end' => '07:00'], $fresh?->quiet_hours);
        $this->assertSame('10:45', $fresh?->review_times['morning'] ?? null);
        $this->assertTrue(AuditEvent::where('action', 'preferences.updated')->exists());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidTimezones(): array
    {
        return [
            'made up' => ['Mars/Base'],
            'missing underscore' => ['America/NewYork'],
            'offset string' => ['GMT+5'],
            'empty' => [''],
        ];
    }

    #[DataProvider('invalidTimezones')]
    public function test_invalid_timezones_are_rejected(string $timezone): void
    {
        $this->expectException(ValidationException::class);

        $this->update(['timezone' => $timezone]);
    }

    public function test_invalid_time_formats_are_rejected(): void
    {
        $this->expectException(ValidationException::class);

        $this->update(['review_times' => ['morning' => '25:99']]);
    }

    public function test_work_window_must_end_after_it_starts(): void
    {
        $this->expectException(ValidationException::class);

        $this->update(['work_windows' => ['tue' => [['start' => '18:00', 'end' => '10:00']]]]);
    }

    public function test_forged_ownership_fields_are_ignored(): void
    {
        $bobAccountId = $this->accountOf($this->bob())->id;

        $preference = $this->update([
            'timezone' => 'Europe/Madrid',
            'account_id' => $bobAccountId,
            'user_id' => $this->bob()->id,
        ]);

        $this->assertSame($this->accountOf($this->alice())->id, $preference->account_id);
        $this->assertSame($this->alice()->id, $preference->user_id);
    }

    public function test_cannot_update_another_users_preferences(): void
    {
        $alice = $this->alice();
        $bob = $this->bob();

        $this->expectException(ValidationException::class);

        $this->asTenant($alice, fn () => app(UpdateUserPreferences::class)->handle($bob, ['timezone' => 'UTC']));
    }
}

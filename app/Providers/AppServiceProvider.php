<?php

namespace App\Providers;

use App\Domain\Accounts\TenantContext;
use App\Domain\Notifications\Listeners\InvalidatePendingDelivery;
use App\Domain\Notifications\NotificationChannel;
use App\Domain\Notifications\NullChannel;
use App\Domain\Scheduling\Events\OccurrenceChanged;
use App\Integrations\Telegram\TelegramChannel;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Scoped: reset between HTTP requests and between queued jobs.
        $this->app->scoped(TenantContext::class);
        $this->app->bind(NotificationChannel::class, config('services.telegram.token') ? TelegramChannel::class : NullChannel::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureRateLimiting();
        Event::listen(OccurrenceChanged::class, InvalidatePendingDelivery::class);
    }

    /**
     * API: 120 requests/minute per authenticated user (per IP for guests).
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by(
            $request->user()?->getAuthIdentifier() ?? $request->ip(),
        ));
        RateLimiter::for('telegram-webhook', fn (Request $request) => Limit::perMinute(120)->by($request->ip()));
        RateLimiter::for('telegram-pairing', fn (Request $request) => Limit::perMinute(5)->by(
            $request->user()?->getAuthIdentifier() ?? $request->ip(),
        ));
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}

<?php

use App\Http\Controllers\HealthController;
use App\Http\Controllers\TelegramWebhookController;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::get('healthz', [HealthController::class, 'live'])->name('health.live');
Route::get('readyz', [HealthController::class, 'ready'])->name('health.ready');

Route::post('webhooks/telegram/{route_key}', TelegramWebhookController::class)
    ->withoutMiddleware(ValidateCsrfToken::class)
    ->middleware('throttle:telegram-webhook')
    ->name('telegram.webhook');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('dashboard', 'pages::today')->name('dashboard');
    Route::livewire('onboarding', 'pages::onboarding')->name('onboarding');
    Route::livewire('backlog', 'pages::backlog')->name('backlog');
    Route::livewire('tasks', 'pages::tasks.index')->name('tasks.index');
    Route::livewire('tasks/create', 'pages::tasks.create')->name('tasks.create');
    Route::livewire('tasks/{task}', 'pages::tasks.show')->name('tasks.show');
    Route::livewire('tasks/{task}/edit', 'pages::tasks.edit')->name('tasks.edit');
});

require __DIR__.'/settings.php';

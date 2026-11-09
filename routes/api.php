<?php

use App\Http\Controllers\Api\V1\DayController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\OccurrenceController;
use App\Http\Controllers\Api\V1\TaskController;
use App\Http\Middleware\EnforceIdempotency;
use App\Integrations\Telegram\CreatePairingCode;
use App\Integrations\Telegram\UnlinkTelegram;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
| API v1 — token (Sanctum) authenticated, tenant-scoped, rate limited.
| Token abilities: tasks:read, tasks:write. First-party session requests have all abilities.
*/
Route::prefix('v1')
    ->name('api.v1.')
    ->middleware(['auth:sanctum', 'verified', 'throttle:api', EnforceIdempotency::class])
    ->group(function () {
        Route::get('me', MeController::class)->name('me');

        Route::middleware('abilities:tasks:read')->group(function () {
            Route::get('tasks', [TaskController::class, 'index'])->name('tasks.index');
            Route::get('tasks/{task}', [TaskController::class, 'show'])->name('tasks.show');
            Route::get('today', [DayController::class, 'today'])->name('today');
            Route::get('backlog', [DayController::class, 'backlog'])->name('backlog');
        });

        Route::middleware('abilities:tasks:write')->group(function () {
            Route::post('telegram/pairing', function (Request $request, CreatePairingCode $create) {
                $user = $request->user();
                abort_unless($user instanceof User, 401);
                $code = $create->handle($user);
                $username = (string) config('services.telegram.username');

                return response()->json(['code' => $code, 'expires_in_seconds' => 600,
                    'deep_link' => $username === '' ? null : 'https://t.me/'.$username.'?start='.$code]);
            })->name('telegram.pairing');

            Route::delete('telegram/link', function (Request $request, UnlinkTelegram $unlink) {
                $user = $request->user();
                abort_unless($user instanceof User, 401);
                $unlink->handle($user);

                return response()->noContent();
            })->name('telegram.unlink');

            Route::post('tasks', [TaskController::class, 'store'])->name('tasks.store');
            Route::patch('tasks/{task}', [TaskController::class, 'update'])->name('tasks.update');
            Route::post('tasks/{task}/cancel', [TaskController::class, 'cancel'])->name('tasks.cancel');
            Route::post('tasks/{task}/complete', [TaskController::class, 'complete'])->name('tasks.complete');
            Route::post('tasks/{task}/completion-time', [TaskController::class, 'correctCompletion'])->name('tasks.completion-time');

            Route::post('tasks/{task}/occurrences', [OccurrenceController::class, 'store'])->name('occurrences.store');
            Route::patch('occurrences/{occurrence}', [OccurrenceController::class, 'update'])->name('occurrences.update');
            Route::post('occurrences/{occurrence}/cancel', [OccurrenceController::class, 'cancel'])->name('occurrences.cancel');
            Route::post('occurrences/{occurrence}/responses', [OccurrenceController::class, 'respond'])->name('occurrences.responses.store');
        });
    });

<?php

use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\TaskController;
use App\Http\Middleware\EnforceIdempotency;
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
        });

        Route::middleware('abilities:tasks:write')->group(function () {
            Route::post('tasks', [TaskController::class, 'store'])->name('tasks.store');
            Route::patch('tasks/{task}', [TaskController::class, 'update'])->name('tasks.update');
            Route::post('tasks/{task}/cancel', [TaskController::class, 'cancel'])->name('tasks.cancel');
        });
    });

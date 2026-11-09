<?php

namespace App\Domain\Tasks\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * The requested change is not allowed in the task's current state
 * (e.g. editing or cancelling a completed task). Rendered as HTTP 409.
 */
class TaskStateConflict extends RuntimeException
{
    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 409);
    }
}

<?php

namespace App\Domain\Scheduling;

use App\Models\CompletionResponse;
use App\Models\Task;
use App\Models\TaskOccurrence;

/**
 * Result of recording a response. `replayed` is true when the idempotency key had
 * already been processed: nothing changed and the original response is returned.
 * (Responses that cannot apply, e.g. No on a completed task, throw TaskStateConflict.)
 */
final readonly class ResponseOutcome
{
    public function __construct(
        public Task $task,
        public ?TaskOccurrence $occurrence,
        public CompletionResponse $response,
        public bool $replayed,
    ) {}
}

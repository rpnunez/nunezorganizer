<?php

namespace App\Http\Resources;

use App\Models\Task;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One logical task in the backlog with its attempt history.
 *
 * @mixin Task
 */
class BacklogItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $overdueSince = $this->toIso($this->resource->getAttribute('overdue_since'));
        $nextAttempt = $this->toIso($this->resource->getAttribute('next_attempt_at'));

        return [
            'task' => [
                'id' => $this->id,
                'title' => $this->title,
                'duration_minutes' => $this->duration_minutes,
                'status' => $this->status->value,
            ],
            'overdue_since' => $overdueSince,
            'attempt_count' => (int) $this->resource->getAttribute('attempt_count'),
            'rescheduled' => $nextAttempt !== null,
            'next_attempt_at' => $nextAttempt,
            'attempts' => OccurrenceResource::collection($this->whenLoaded('occurrences')),
        ];
    }

    private function toIso(mixed $value): ?string
    {
        return $value === null ? null : CarbonImmutable::parse((string) $value, 'UTC')->toIso8601ZuluString();
    }
}

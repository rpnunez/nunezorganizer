<?php

namespace App\Http\Resources;

use App\Models\TaskOccurrence;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Times are returned as UTC instants plus the planning timezone and its local rendering.
 *
 * @mixin TaskOccurrence
 */
class OccurrenceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'task_id' => $this->task_id,
            'state' => $this->state->value,
            'starts_at' => $this->scheduled_start_at->toIso8601ZuluString(),
            'ends_at' => $this->scheduled_end_at->toIso8601ZuluString(),
            'timezone' => $this->timezone,
            'local' => [
                'starts_at' => $this->scheduled_start_at->setTimezone($this->timezone)->toIso8601String(),
                'ends_at' => $this->scheduled_end_at->setTimezone($this->timezone)->toIso8601String(),
            ],
            'version' => $this->version,
            'closed_at' => $this->closed_at?->toIso8601ZuluString(),
        ];
    }
}

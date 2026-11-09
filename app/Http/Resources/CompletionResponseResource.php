<?php

namespace App\Http\Resources;

use App\Models\CompletionResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CompletionResponse
 */
class CompletionResponseResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'task_id' => $this->task_id,
            'occurrence_id' => $this->occurrence_id,
            'response' => $this->response->value,
            'response_at' => $this->response_at->toIso8601ZuluString(),
            'completed_at' => $this->completed_at?->toIso8601ZuluString(),
            'punctuality' => $this->punctuality?->value,
            'channel' => $this->channel->value,
            'notes' => $this->notes,
        ];
    }
}

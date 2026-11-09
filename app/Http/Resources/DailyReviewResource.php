<?php

namespace App\Http\Resources;

use App\Models\DailyReview;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DailyReview */
class DailyReviewResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'kind' => $this->kind, 'status' => $this->status,
            'sent_at' => $this->sent_at?->toIso8601String()];
    }
}

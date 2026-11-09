<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Reviews\WeeklyStats;
use App\Domain\Scheduling\LocalTime;
use App\Http\Controllers\Controller;
use App\Http\Requests\WeeklyProgressRequest;
use App\Http\Resources\DailyReviewResource;
use App\Models\DailyReview;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReviewController extends Controller
{
    use ResolvesApiUser;

    public function today(Request $request): JsonResponse
    {
        $user = $this->apiUser($request);
        $timezone = $this->userTimezone($user);
        $date = LocalTime::localDate(now(), $timezone);
        $reviews = DailyReview::query()->where('user_id', $user->id)
            ->whereDate('local_date', $date)->orderBy('kind')->get();

        return response()->json(['date' => $date, 'timezone' => $timezone,
            'reviews' => DailyReviewResource::collection($reviews)->resolve($request)]);
    }

    public function weekly(WeeklyProgressRequest $request, WeeklyStats $stats): JsonResponse
    {
        $user = $this->apiUser($request);
        $timezone = $this->userTimezone($user);
        $week = $request->validated('week') ?? now()->setTimezone($timezone)->format('Y-m-d');

        return response()->json($stats->forUser($user, $week, $timezone));
    }
}

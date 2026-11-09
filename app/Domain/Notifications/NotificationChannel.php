<?php

namespace App\Domain\Notifications;

use App\Models\DailyReview;
use App\Models\NotificationDelivery;
use App\Models\TaskOccurrence;
use App\Models\User;

interface NotificationChannel
{
    public function availableFor(User $user): bool;

    public function sendCompletionPrompt(User $user, TaskOccurrence $occurrence, NotificationDelivery $delivery): DeliveryResult;

    public function sendDailyReview(User $user, DailyReview $review, NotificationDelivery $delivery): DeliveryResult;
}

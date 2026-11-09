<?php

namespace App\Domain\Notifications;

use App\Models\DailyReview;
use App\Models\NotificationDelivery;
use App\Models\TaskOccurrence;
use App\Models\User;

/** No delivery when the user has no connected notification channel. */
final class NullChannel implements NotificationChannel
{
    public function availableFor(User $user): bool
    {
        return false;
    }

    public function sendCompletionPrompt(User $user, TaskOccurrence $occurrence, NotificationDelivery $delivery): DeliveryResult
    {
        return DeliveryResult::skipped();
    }

    public function sendDailyReview(User $user, DailyReview $review, NotificationDelivery $delivery): DeliveryResult
    {
        return DeliveryResult::skipped();
    }
}

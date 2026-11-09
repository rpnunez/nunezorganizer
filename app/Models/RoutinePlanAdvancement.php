<?php

namespace App\Models;

use App\Domain\Accounts\BelongsToAccount;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class RoutinePlanAdvancement extends Model
{
    use BelongsToAccount, HasUlids;

    protected $fillable = ['routine_plan_id', 'user_id', 'from_week', 'idempotency_key', 'accepted_at'];

    protected function casts(): array
    {
        return ['from_week' => 'integer', 'accepted_at' => 'datetime'];
    }
}

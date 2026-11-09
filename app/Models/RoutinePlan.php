<?php

namespace App\Models;

use App\Domain\Accounts\BelongsToAccount;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $account_id
 * @property int $user_id
 * @property string $template_key
 * @property int $current_week
 * @property CarbonInterface $started_on
 * @property string $status
 * @property array<string, mixed> $settings
 */
class RoutinePlan extends Model
{
    use BelongsToAccount, HasUlids;

    protected $fillable = ['user_id', 'template_key', 'current_week', 'started_on', 'status', 'settings'];

    protected function casts(): array
    {
        return ['current_week' => 'integer', 'started_on' => 'date', 'settings' => 'array'];
    }
}

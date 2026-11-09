<?php

namespace App\Livewire;

use App\Livewire\Concerns\SchedulesBlocks;

/**
 * Pages that plan blocks with local date/start/end fields (new task, task page).
 */
abstract class SchedulingPage extends TaskActionsPage
{
    use SchedulesBlocks;
}

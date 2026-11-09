<?php

namespace App\Livewire;

use App\Livewire\Concerns\TaskActions;

/**
 * Pages with quick Yes/No/Done/Cancel-block actions (Today, Backlog, task page).
 */
abstract class TaskActionsPage extends AppPage
{
    use TaskActions;
}

<?php

namespace App\Livewire;

use App\Livewire\Concerns\RoutinePreferencesForm;

/** Shared, statically analysed routine form for Settings and onboarding. */
abstract class RoutinePage extends AppPage
{
    use RoutinePreferencesForm;
}

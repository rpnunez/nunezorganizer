<?php

namespace App\Livewire;

use App\Livewire\Concerns\InTenant;
use Livewire\Component;

/**
 * Base for authenticated app pages: resolves the tenant on every request.
 */
abstract class AppPage extends Component
{
    use InTenant;
}

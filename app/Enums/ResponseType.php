<?php

namespace App\Enums;

enum ResponseType: string
{
    case Yes = 'yes';
    case No = 'no';
    case Partial = 'partial';
    case Snooze = 'snooze';
}

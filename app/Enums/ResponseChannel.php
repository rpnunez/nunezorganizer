<?php

namespace App\Enums;

enum ResponseChannel: string
{
    case Web = 'web';
    case Api = 'api';
    case Telegram = 'telegram';
}

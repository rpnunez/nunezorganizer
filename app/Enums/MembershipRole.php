<?php

namespace App\Enums;

enum MembershipRole: string
{
    case Owner = 'owner';
    case Member = 'member';
}

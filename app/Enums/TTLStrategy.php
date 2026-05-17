<?php

namespace App\Enums;

enum TTLStrategy: string
{
    case MIN = 'min';
    case MAX = 'max';
    case DYNAMIC = 'dynamic';
    case FIXED = 'fixed';
}
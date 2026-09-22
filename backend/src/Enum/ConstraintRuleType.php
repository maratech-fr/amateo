<?php

declare(strict_types=1);

namespace App\Enum;

enum ConstraintRuleType: string
{
    use HasValues;

    case HARD = 'HARD';
    case PREFERRED = 'PREFERRED';
    case LOCK = 'LOCK';
}

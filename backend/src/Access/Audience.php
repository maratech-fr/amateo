<?php

declare(strict_types=1);

namespace App\Access;

/**
 * À qui une fonction de club est ouverte : tout membre actif du club, ou le seul gestionnaire.
 */
enum Audience
{
    case MEMBER;
    case MANAGER;
}

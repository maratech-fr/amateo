<?php

declare(strict_types=1);

namespace App\Access;

/**
 * Le module d'une fonction de club : l'unité qu'un club possède ou non. Les doléances des coachs
 * sont du Planning.
 */
enum Module: string
{
    case CLUB = 'club';
    case PLANNING = 'planning';
    case MATCHES = 'matches';
}

<?php

declare(strict_types=1);

namespace App\Access;

/**
 * Le module d'une fonction de club : l'unité qu'un club possède ou non. Les doléances des coachs
 * sont du Planning.
 */
enum Module: string
{
    /**
     * Le module est fermé tant que la saison n'a pas de planning de saison en vigueur : seul Matchs
     * (les matchs se placent dans les créneaux du socle). Une fonction fermée avec lui le déclare
     * (`ClubFunction::$refusedWhenModuleClosed`).
     */
    public function requiresSocleInForce(): bool
    {
        return self::MATCHES === $this;
    }

    case CLUB = 'club';
    case PLANNING = 'planning';
    case MATCHES = 'matches';
}

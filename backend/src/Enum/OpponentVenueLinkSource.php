<?php

declare(strict_types=1);

namespace App\Enum;

use App\Entity\OpponentVenueSuggestion;
use App\Service\Geo\OpponentVenueAutoLocator;

/**
 * D'où vient l'appariement « libellé de salle FBI → gymnase fédéral » d'un adversaire,
 * POUR UN CLUB précis (grain club-scoped, jamais par saison — un libellé désigne le
 * même gymnase d'une saison à l'autre).
 *
 * `AUTO` : posé par {@see OpponentVenueAutoLocator} depuis le libellé
 * du fichier FBI (égalité stricte avec une salle fédérale unique) — une localisation
 * DEVINÉE, jamais un choix, ne compte JAMAIS au partagé. `MANUAL` : le gestionnaire a
 * choisi lui-même le gymnase (ref fédérale ou coordonnées) — sa correction terrain, qui
 * incrémente le compteur communautaire ({@see OpponentVenueSuggestion}). Le
 * cœur du patron : un appariement MANUAL n'est JAMAIS écrasé par une passe AUTO.
 */
enum OpponentVenueLinkSource: string
{
    use HasValues;

    case AUTO = 'AUTO';

    case MANUAL = 'MANUAL';
}

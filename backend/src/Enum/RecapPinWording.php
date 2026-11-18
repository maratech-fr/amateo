<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Les mots des phrases de groupe du Récap pour les cases épinglées. Hors comblement, une case épinglée est une
 * réservation. Au comblement, ce sont surtout les placements de la version source : on dit « placé » et le remède
 * nommé est de déplacer une séance de cette version ; s'il reste des réservations, les deux remèdes sont dits.
 */
enum RecapPinWording
{
    case RESERVED;
    case RESERVED_OR_PLACED;
    case PLACED;
}

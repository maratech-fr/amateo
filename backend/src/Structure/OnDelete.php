<?php

declare(strict_types=1);

namespace App\Structure;

/**
 * Ce que devient une ligne quand l'objet que sa colonne désigne disparaît.
 *
 *  - DELETE : la ligne meurt avec sa cible (seule relation qui ordonne une purge : l'enfant avant
 *    le parent) ;
 *  - CLEAR : la colonne est remise à null, la ligne survit ;
 *  - CUSTOM : un code dédié décide (raison obligatoire, qui le nomme) ;
 *  - KEEP : la colonne garde l'identifiant (raison obligatoire).
 */
enum OnDelete
{
    case DELETE;
    case CLEAR;
    case CUSTOM;
    case KEEP;
}

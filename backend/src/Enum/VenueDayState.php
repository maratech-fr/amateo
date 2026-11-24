<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Un état de gymnase POUR UN JOUR précis d'une période — le masque manuel tri-état.
 *
 * Le masque (`PlanVenue::$dayOverrides`) est SPARSE : un jour absent hérite
 * du défaut (dérivé de l'incident `venue_closed`), un jour PRÉSENT est forcé par le
 * gestionnaire. Il n'y a donc que deux valeurs stockées — l'absence de clé EST le 3e état
 * (« hériter »), jamais matérialisée, comme INHERIT l'est pour {@see VenuePeriodMode}.
 *
 * OPEN   = ce jour est ouvert quoi qu'en dise l'incident (le gestionnaire rouvre).
 * CLOSED = ce jour est fermé même si l'incident ne le fermait pas (fermeture manuelle).
 */
enum VenueDayState: string
{
    use HasValues;

    case OPEN = 'OPEN';
    case CLOSED = 'CLOSED';
}

<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Chantier H — l'entrée du PATCH `merge-patch+json` de la tolérance d'arrivée tardive. Le rail est
 * limité à CE SEUL champ : une réservation ne se DÉPLACE jamais par ce PATCH (équipe/gymnase/jour
 * ne sont pas des propriétés de ce DTO). Plafond métier 0..30 (fondateur) → 422 hors bornes ; un
 * créneau LIBRE (sans équipe) n'a pas de coach à faire arriver en retard → 422 nommé côté processor.
 */
class ReservationToleranceInput
{
    #[Assert\NotNull]
    #[Assert\Range(min: 0, max: 30)]
    #[Groups(['write'])]
    public ?int $coachLateToleranceMinutes = null;
}

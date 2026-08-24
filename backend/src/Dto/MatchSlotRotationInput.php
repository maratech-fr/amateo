<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * RMM-5 (P2-49) — saisie d'un créneau de match partagé : le triplet physique (gymnase, jour,
 * heure) + les équipes qui l'occupent en alternance, DANS L'ORDRE d'affichage A/B/C.
 *
 * Les invariants de FORME vivent ici (≥ 2 équipes, aucun doublon, formats) ; ceux qui exigent
 * la base — équipe/gymnase étrangers, doublon de créneau — vivent dans le processor.
 */
class MatchSlotRotationInput
{
    #[Assert\NotBlank]
    #[Assert\Uuid]
    #[Groups(['write'])]
    public ?string $venueId = null;

    #[Assert\NotBlank]
    #[Assert\Range(min: 1, max: 7)]
    #[Groups(['write'])]
    public ?int $dayOfWeek = null;

    #[Assert\NotBlank]
    #[Assert\Regex(pattern: '/^([01]\d|2[0-3]):[0-5]\d$/')]
    #[Groups(['write'])]
    public ?string $kickoffTime = null;

    /**
     * De 2 (minimum métier — un créneau à une équipe n'alterne pas) à 10 (cap technique).
     * L'ORDRE de la liste EST l'ordre d'affichage (position). Chaque id UUID, aucun doublon.
     *
     * @var list<string>
     */
    #[Assert\Count(min: 2, max: 10, minMessage: 'Un créneau de match partagé compte au moins {{ limit }} équipes.', maxMessage: 'Un créneau de match partagé compte au plus {{ limit }} équipes.')]
    #[Assert\Unique(message: 'Une équipe ne peut figurer qu\'une fois dans le créneau partagé.')]
    #[Assert\All([new Assert\NotBlank, new Assert\Uuid])]
    #[Groups(['write'])]
    public array $teamIds = [];
}

<?php

declare(strict_types=1);

namespace App\Dto;

use App\Entity\SharedTrainingBlock;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Déclaration d'un bloc de mutualisation : un ensemble d'équipes se comportant comme UNE équipe,
 * avec ``commonSessions`` séances qui LUI appartiennent. Ici les invariants purement de FORME ;
 * les bornes métier (Σ des séances communes ≤ séances de l'équipe, ensemble déjà déclaré, équipe
 * inconnue/inactive — elles exigent la base) vivent dans le processor.
 */
class SharedTrainingBlockInput
{
    /** NULL = socle saison ; un UUID = plan de période. */
    #[Assert\Uuid]
    #[Groups(['write'])]
    public ?string $schedulePlanId = null;

    /**
     * De 2 (minimum métier) à 10 équipes (cap technique fondateur). Chaque id UUID, aucun doublon
     * DANS le bloc (la multi-appartenance ENTRE blocs, elle, est permise — vérifiée côté base).
     *
     * @var list<string>
     */
    #[Assert\Count(min: 2, max: 10, minMessage: 'Un bloc de mutualisation compte au moins {{ limit }} équipes.', maxMessage: 'Un bloc de mutualisation compte au plus {{ limit }} équipes.')]
    #[Assert\Unique(message: 'Une équipe ne peut figurer qu\'une fois dans le bloc.')]
    #[Assert\All([new Assert\NotBlank, new Assert\Uuid])]
    #[Groups(['write'])]
    public array $teamIds = [];

    /** Nombre de séances communes du bloc (≥ 1 ; la garde Σ côté processor borne le cumul par équipe). */
    #[Assert\NotNull]
    #[Assert\Positive]
    #[Groups(['write'])]
    public ?int $commonSessions = null;

    /**
     * E12 (essai fondateur 30/10) — « Nom du groupe » FACULTATIF, persisté en POST/PUT ; vide = null
     * (la lecture retombe sur l'affichage par les équipes). Borné à la longueur de la colonne.
     */
    #[Assert\Length(max: SharedTrainingBlock::LABEL_MAX_LENGTH, maxMessage: 'Le nom du groupe fait au plus {{ limit }} caractères.')]
    #[Groups(['write'])]
    public ?string $label = null;
}

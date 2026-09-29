<?php

declare(strict_types=1);

namespace App\Dto;

use App\Enum\ConstraintScope;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Saisie d'une RÈGLE DE MATCH (écran unique des contraintes de match). Deux formes
 * selon le `scope`, tranchées par le processeur (les asserts ci-dessous ne portent
 * QUE le format, pas la cohérence par-scope) :
 *  - CLUB (③, section Club) : une fourchette de coup d'envoi sur des jours (« pas
 *    après 21h le samedi »). `ruleType` HARD | PREFERRED, `daysOfWeek` ≥ 1 jour,
 *    au moins une borne et min ≤ max si les deux ; `scopeTargetId`/`venueId` nuls.
 *  - TEAM (④, section Équipes) : une INTERDICTION de gymnase pour une équipe
 *    (« l'équipe X ne joue jamais au gymnase Y »). `scopeTargetId` = l'équipe,
 *    `venueId` = le gymnase, tous deux OBLIGATOIRES et DU CLUB (validation tenant) ;
 *    `ruleType` HARD seulement (PREFERRED refusé — la préférence de gymnase reste
 *    l'habitude de la semaine type) ; `daysOfWeek`/`kickoff` NON PERTINENTS (une
 *    interdiction vaut tous les jours) donc null/refusés.
 * COACH/FACILITY (⑤ plus tard) restent refusés. `daysOfWeek` n'est donc plus
 * requis À L'ENTRÉE (une règle TEAM n'en porte pas) : la présence pour le scope CLUB
 * est exigée par le processeur (refus nommé), qui connaît le scope — pas ici.
 */
class MatchConstraintInput
{
    #[Assert\Choice(callback: [ConstraintScope::class, 'values'])]
    #[Groups(['write'])]
    public ?string $scope = ConstraintScope::CLUB->value;

    // Uuid tolère null (borne format seulement) : une chaîne non-UUID → 422 lisible
    // plutôt qu'une 500 à l'écriture (colonne guid). La NULLITÉ (CLUB) ou la présence
    // (TEAM) est exigée par le processeur (refuse), qui connaît le scope — pas ici.
    #[Assert\Uuid]
    #[Groups(['write'])]
    public ?string $scopeTargetId = null;

    #[Assert\NotBlank]
    #[Assert\Choice(choices: ['HARD', 'PREFERRED'])]
    #[Groups(['write'])]
    public ?string $ruleType = null;

    /**
     * Jours ISO couverts (1=lundi..7=dimanche). Requis pour une règle CLUB (le
     * processeur le refuse si vide), non pertinent — donc null/refusé — pour une
     * interdiction de gymnase TEAM. Seul le FORMAT est gardé ici (bornes 1..7).
     *
     * @var list<int>|null
     */
    #[Assert\All([new Assert\Type('integer'), new Assert\Range(min: 1, max: 7)])]
    #[Groups(['write'])]
    public ?array $daysOfWeek = null;

    #[Assert\Regex(pattern: '/^([01]\d|2[0-3]):[0-5]\d$/')]
    #[Groups(['write'])]
    public ?string $kickoffMin = null;

    #[Assert\Regex(pattern: '/^([01]\d|2[0-3]):[0-5]\d$/')]
    #[Groups(['write'])]
    public ?string $kickoffMax = null;

    #[Assert\Uuid]
    #[Groups(['write'])]
    public ?string $venueId = null;
}

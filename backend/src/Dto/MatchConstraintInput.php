<?php

declare(strict_types=1);

namespace App\Dto;

use App\Enum\ConstraintScope;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Saisie d'une RÈGLE DE MATCH du club (section Club de l'écran des contraintes de
 * match). `scope` par défaut CLUB (seul saisi en ③ ; ④/⑤ ouvriront TEAM/COACH).
 * `ruleType` HARD | PREFERRED (jamais LOCK). `daysOfWeek` = jours ISO couverts
 * (1=lundi..7=dimanche), au moins un. `kickoffMin`/`kickoffMax` bornent le coup
 * d'envoi (HH:MM), chacune facultative (« pas après 21h » = max seul), au moins une
 * et min ≤ max si les deux (validé côté serveur, message clair). `scopeTargetId` et
 * `venueId` restent nuls en ③.
 */
class MatchConstraintInput
{
    #[Assert\Choice(callback: [ConstraintScope::class, 'values'])]
    #[Groups(['write'])]
    public ?string $scope = ConstraintScope::CLUB->value;

    #[Groups(['write'])]
    public ?string $scopeTargetId = null;

    #[Assert\NotBlank]
    #[Assert\Choice(choices: ['HARD', 'PREFERRED'])]
    #[Groups(['write'])]
    public ?string $ruleType = null;

    /**
     * @var list<int>|null
     */
    #[Assert\NotNull]
    #[Assert\Count(min: 1)]
    #[Assert\All([new Assert\Type('integer'), new Assert\Range(min: 1, max: 7)])]
    #[Groups(['write'])]
    public ?array $daysOfWeek = null;

    #[Assert\Regex(pattern: '/^([01]\d|2[0-3]):[0-5]\d$/')]
    #[Groups(['write'])]
    public ?string $kickoffMin = null;

    #[Assert\Regex(pattern: '/^([01]\d|2[0-3]):[0-5]\d$/')]
    #[Groups(['write'])]
    public ?string $kickoffMax = null;

    #[Groups(['write'])]
    public ?string $venueId = null;
}

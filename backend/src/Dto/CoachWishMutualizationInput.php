<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Écriture d'une demande de mutualisation coach (feature #10, lot D2). L'ancre
 * (calendarEntryId, teamId) identifie la ligne : au PUT elle n'est pas remappée, seuls le
 * coach, les partenaires, le nombre de séances et la coche bougent (cf. le processor).
 */
class CoachWishMutualizationInput
{
    #[Assert\NotBlank]
    #[Assert\Uuid]
    #[Groups(['write'])]
    public ?string $calendarEntryId = null;

    #[Assert\NotBlank]
    #[Assert\Uuid]
    #[Groups(['write'])]
    public ?string $teamId = null;

    /**
     * Requis à la CRÉATION (« au nom d'un coach », imposé par le processor), mais NULLABLE en
     * écriture : une demande dé-attribuée (coach supprimé, coachId=null) doit pouvoir être
     * re-cochée « traitée » ou éditée sans qu'un NotBlank la bloque (parité CoachWish).
     */
    #[Assert\Uuid]
    #[Groups(['write'])]
    public ?string $coachId = null;

    /**
     * Équipes partenaires pressenties (uuid), sans doublon. Au moins une (le processor refuse
     * une demande vide — supprimez-la plutôt).
     *
     * @var list<string>
     */
    #[Assert\All([new Assert\Uuid])]
    #[Assert\Unique]
    #[Assert\Count(max: 50)]
    #[Groups(['write'])]
    public array $partnerTeamIds = [];

    #[Assert\NotNull]
    #[Assert\Range(min: 1, max: 7)]
    #[Groups(['write'])]
    public ?int $sharedSlots = 1;

    #[Groups(['write'])]
    public bool $done = false;
}

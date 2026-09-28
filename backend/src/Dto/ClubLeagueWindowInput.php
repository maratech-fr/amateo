<?php

declare(strict_types=1);

namespace App\Dto;

use App\Entity\LeagueMatchWindow;
use App\Enum\Gender;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * P4-272 ① — saisie d'une fenêtre de ligue de la COPIE club (section Ligue de
 * l'écran des contraintes). `level` = palier fédéral (DEPARTEMENTAL | REGIONAL),
 * pas le niveau d'équipe. `gender` null = tous genres. `kickoffMin`/`kickoffMax`
 * bornent le coup d'envoi (HH:MM), min ≤ max (validé côté processor, message FR).
 */
class ClubLeagueWindowInput
{
    #[Assert\NotBlank]
    #[Assert\Length(max: 40)]
    #[Groups(['write'])]
    public ?string $category = null;

    #[Assert\NotBlank]
    #[Assert\Choice(choices: [LeagueMatchWindow::LEVEL_DEPARTEMENTAL, LeagueMatchWindow::LEVEL_REGIONAL])]
    #[Groups(['write'])]
    public ?string $level = null;

    #[Assert\Choice(callback: [Gender::class, 'values'])]
    #[Groups(['write'])]
    public ?string $gender = null;

    #[Assert\NotBlank]
    #[Assert\Range(min: 1, max: 7)]
    #[Groups(['write'])]
    public ?int $dayOfWeek = null;

    #[Assert\NotBlank]
    #[Assert\Regex(pattern: '/^([01]\d|2[0-3]):[0-5]\d$/')]
    #[Groups(['write'])]
    public ?string $kickoffMin = null;

    #[Assert\NotBlank]
    #[Assert\Regex(pattern: '/^([01]\d|2[0-3]):[0-5]\d$/')]
    #[Groups(['write'])]
    public ?string $kickoffMax = null;
}

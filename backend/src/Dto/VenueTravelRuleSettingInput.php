<?php

declare(strict_types=1);

namespace App\Dto;

use App\Enum\TeamLinkIntensity;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Corps d'un upsert du levier de trajet (PUT). Le seul champ réglable est l'intensité :
 * PREFERRED (préférence souple) ou MANDATORY (obligatoire). HARD/OFF n'ont pas de sens ici
 * (la règle de trajet ne parle pas le vocabulaire bien-être) : `Assert\Choice` sur
 * `TeamLinkIntensity::values()` les refuse en 422 sans liste en dur (EnumChoicesAreDerivedTest).
 */
class VenueTravelRuleSettingInput
{
    #[Assert\NotBlank]
    #[Assert\Choice(callback: [TeamLinkIntensity::class, 'values'])]
    #[Groups(['write'])]
    public ?string $intensity = null;
}

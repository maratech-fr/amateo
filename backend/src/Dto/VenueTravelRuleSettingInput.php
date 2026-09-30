<?php

declare(strict_types=1);

namespace App\Dto;

use App\Enum\VenueTravelRuleIntensity;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Corps d'un upsert du levier de trajet (PUT). Trois réglages : l'intensité — OFF (Inactive),
 * PREFERRED (préférence souple) ou MANDATORY (obligatoire) — validée sur
 * {@see VenueTravelRuleIntensity::values()} (aucune liste en dur, `EnumChoicesAreDerivedTest`) ; le
 * battement toléré (0-60 min) et le temps par défaut d'un couple sans temps (1-120 min).
 */
class VenueTravelRuleSettingInput
{
    #[Assert\NotBlank]
    #[Assert\Choice(callback: [VenueTravelRuleIntensity::class, 'values'])]
    #[Groups(['write'])]
    public ?string $intensity = null;

    /** Battement toléré, retranché du barème pour l'écart exigé. Défaut 20 côté serveur si absent. */
    #[Assert\Type('integer')]
    #[Assert\Range(min: 0, max: 60)]
    #[Groups(['write'])]
    public ?int $toleranceMinutes = null;

    /** Barème d'un couple de gymnases sans temps saisi. Défaut 20 côté serveur si absent. */
    #[Assert\Type('integer')]
    #[Assert\Range(min: 1, max: 120)]
    #[Groups(['write'])]
    public ?int $defaultMinutes = null;
}

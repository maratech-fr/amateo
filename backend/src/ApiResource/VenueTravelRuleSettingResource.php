<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Put;
use App\Dto\VenueTravelRuleSettingInput;
use App\Enum\VenueTravelRuleIntensity;
use App\State\Processor\VenueTravelRuleSettingStateProcessor;
use App\State\Provider\VenueTravelRuleSettingStateProvider;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Le levier de la règle « Trajet entre gymnases ».
 *
 * SINGLETON par club+saison — un seul réglage, pas une collection. L'identifiant est le nom de la
 * règle gouvernée (`travelTime`), fixe : le front lit/écrit `…/venue_travel_rule_settings/travelTime`
 * sans connaître de ligne en base. Le GET résout (stocké OU défauts), le PUT UPSERTE. L'écriture
 * n'accepte que OFF|PREFERRED|MANDATORY ({@see VenueTravelRuleIntensity}) — toute autre valeur est
 * refusée en 422 (le DTO valide sur `VenueTravelRuleIntensity::values()`).
 */
#[ApiResource(shortName: 'VenueTravelRuleSetting', operations: [
    new Get,
    new Put,
], input: VenueTravelRuleSettingInput::class, paginationEnabled: false, provider: VenueTravelRuleSettingStateProvider::class, processor: VenueTravelRuleSettingStateProcessor::class)]
class VenueTravelRuleSettingResource
{
    /** L'identifiant fixe — le nom de la règle gouvernée (contrat moteur `travelTime`). */
    public const RULE_KEY = 'travelTime';

    #[ApiProperty(identifier: true)]
    #[Groups(['read'])]
    public string $ruleKey = self::RULE_KEY;

    #[Groups(['read'])]
    public string $intensity = VenueTravelRuleIntensity::PREFERRED->value;

    /** Battement toléré (minutes), retranché du barème pour l'écart exigé. */
    #[Groups(['read'])]
    public int $toleranceMinutes = 20;

    /** Barème d'un couple de gymnases sans temps saisi (minutes). */
    #[Groups(['read'])]
    public int $defaultMinutes = 20;

    /** true tant que la règle est au défaut (aucune ligne stockée) — le front sait quoi montrer. */
    #[Groups(['read'])]
    public bool $isDefault = true;

    public static function from(VenueTravelRuleIntensity $intensity, int $toleranceMinutes, int $defaultMinutes, bool $isDefault): self
    {
        $dto = new self;
        $dto->intensity = $intensity->value;
        $dto->toleranceMinutes = $toleranceMinutes;
        $dto->defaultMinutes = $defaultMinutes;
        $dto->isDefault = $isDefault;

        return $dto;
    }
}

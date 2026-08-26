<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Put;
use App\Dto\VenueTravelRuleSettingInput;
use App\Enum\TeamLinkIntensity;
use App\State\Processor\VenueTravelRuleSettingStateProcessor;
use App\State\Provider\VenueTravelRuleSettingStateProvider;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Le levier d'intensité de la règle « Trajet entre gymnases » (P2-53 RMM-8 PR-4).
 *
 * SINGLETON par club+saison — un seul réglage, pas une collection. L'identifiant est le nom de la
 * règle gouvernée (`travelTime`), fixe : le front lit/écrit `…/venue_travel_rule_settings/travelTime`
 * sans connaître de ligne en base. Le GET résout (stocké OU défaut PREFERRED), le PUT UPSERTE.
 * L'écriture n'accepte que PREFERRED|MANDATORY ({@see TeamLinkIntensity}) — HARD/OFF sont refusés
 * en 422 (le DTO valide sur `TeamLinkIntensity::values()`).
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
    public string $intensity = TeamLinkIntensity::PREFERRED->value;

    /** true tant que la règle est au défaut (aucune ligne stockée) — le front sait quoi montrer. */
    #[Groups(['read'])]
    public bool $isDefault = true;

    public static function from(TeamLinkIntensity $intensity, bool $isDefault): self
    {
        $dto = new self;
        $dto->intensity = $intensity->value;
        $dto->isDefault = $isDefault;

        return $dto;
    }
}

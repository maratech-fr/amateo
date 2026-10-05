<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Put;
use App\Dto\ImplicitRuleSettingInput;
use App\Enum\ImplicitRuleKey;
use App\State\Processor\ImplicitRuleSettingStateProcessor;
use App\State\Provider\ImplicitRuleSettingStateProvider;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Les 4 règles implicites « bien-être », RÉGLABLES par club/saison.
 *
 * L'identifiant EST `ruleKey` (la clé camelCase du contrat), pas un uuid : le front règle une
 * règle par son nom, sans connaître de ligne en base. La collection est RÉSOLUE — toujours 4
 * entrées, défauts inclus, pour que le front n'invente rien. Le PUT UPSERTE (crée la ligne au
 * premier réglage), le DELETE RÉINITIALISE (supprime la ligne → retour au défaut).
 */
#[ApiResource(shortName: 'ImplicitRuleSetting', operations: [
    new GetCollection,
    new Get,
    new Put,
    new Delete,
], input: ImplicitRuleSettingInput::class, paginationEnabled: false, provider: ImplicitRuleSettingStateProvider::class, processor: ImplicitRuleSettingStateProcessor::class)]
class ImplicitRuleSettingResource
{
    #[ApiProperty(identifier: true)]
    #[Groups(['read'])]
    public string $ruleKey = '';

    #[Groups(['read'])]
    public string $intensity = '';

    /** Seuil de repos coach — présent pour coachRestDay uniquement, sinon null. */
    #[Groups(['read'])]
    public ?int $minRestDays = null;

    /** Plafond de créneaux consécutifs — présent pour maxConsecutiveSessions uniquement, sinon null. */
    #[Groups(['read'])]
    public ?int $maxConsecutive = null;

    /** Plafond de JOURS consécutifs d'entraînement — présent pour maxConsecutiveDays uniquement, sinon null. */
    #[Groups(['read'])]
    public ?int $maxConsecutiveDays = null;

    /** true tant que la règle est au défaut (aucune ligne stockée) — le front sait quoi mettre en avant. */
    #[Groups(['read'])]
    public bool $isDefault = true;

    /**
     * Construit la ressource d'UNE règle à partir de son entrée résolue (bloc payload).
     *
     * @param array<string, mixed> $resolvedEntry
     */
    public static function fromResolved(ImplicitRuleKey $ruleKey, array $resolvedEntry, bool $isDefault): self
    {
        $dto = new self;
        $dto->ruleKey = $ruleKey->value;
        $dto->intensity = (string) ($resolvedEntry['intensity'] ?? '');
        $dto->minRestDays = ImplicitRuleKey::COACH_REST_DAY === $ruleKey ? (int) ($resolvedEntry['minRestDays'] ?? 0) : null;
        $dto->maxConsecutive = ImplicitRuleKey::MAX_CONSECUTIVE_SESSIONS === $ruleKey ? (int) ($resolvedEntry['maxConsecutive'] ?? 0) : null;
        $dto->maxConsecutiveDays = ImplicitRuleKey::MAX_CONSECUTIVE_DAYS === $ruleKey ? (int) ($resolvedEntry['maxConsecutiveDays'] ?? 0) : null;
        $dto->isDefault = $isDefault;

        return $dto;
    }
}

<?php

declare(strict_types=1);

namespace App\Dto;

use App\Enum\VenueDayState;
use App\Enum\VenuePeriodMode;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

class VenuePeriodOverrideInput
{
    #[Assert\NotBlank]
    #[Assert\Uuid]
    #[Groups(['write'])]
    public ?string $schedulePlanId = null;

    #[Assert\NotBlank]
    #[Assert\Uuid]
    #[Groups(['write'])]
    public ?string $venueId = null;

    /**
     * Le mode est FACULTATIF (une ligne peut n'exister que pour son masque) : plus de
     * `NotBlank`. INHERIT n'est pas une valeur acceptée — c'est le défaut (DELETE pour y
     * revenir), ou ici un `mode` nul.
     */
    #[Assert\Choice(callback: [VenuePeriodMode::class, 'values'], message: 'Mode invalide : seuls DISABLED et BLANK se stockent. INHERIT est le défaut — supprimez l\'override pour y revenir.')]
    #[Groups(['write'])]
    public ?string $mode = null;

    /**
     * Masque manuel SPARSE : jour ISO (1..7) → OPEN|CLOSED. Entrée BRUTE non validée (la colonne
     * JSON est inscriptible) : les valeurs sont `mixed` jusqu'à {@see validateMask}, qui refuse
     * toute clé hors 1..7 et toute valeur hors OPEN|CLOSED. NULL/[] = aucun jour forcé.
     *
     * @var array<array-key, mixed>|null
     */
    #[Groups(['write'])]
    public ?array $dayOverrides = null;

    /**
     * Au MOINS l'un des deux réglages doit être fourni : une ligne totalement vide
     * n'exprime rien (et ne se distingue pas de l'absence de ligne = hériter).
     */
    #[Assert\Callback]
    public function validateAtLeastOneSetting(ExecutionContextInterface $context): void
    {
        if (null === $this->mode && (null === $this->dayOverrides || [] === $this->dayOverrides)) {
            $context->buildViolation('Indiquez un mode (DISABLED/BLANK) ou au moins un jour dans le masque : une ligne vide reviendrait à hériter (supprimez plutôt l\'override).')
                ->atPath('mode')
                ->addViolation();
        }
    }

    /**
     * Le masque n'accepte QUE des clés jour ISO 1..7 et des valeurs OPEN|CLOSED. Toute
     * clé ou valeur étrangère est refusée en 422 — la colonne JSON n'est jamais un fourre-tout.
     */
    #[Assert\Callback]
    public function validateMask(ExecutionContextInterface $context): void
    {
        if (null === $this->dayOverrides) {
            return;
        }
        $allowedStates = VenueDayState::values();
        foreach ($this->dayOverrides as $day => $state) {
            $dayInt = filter_var($day, \FILTER_VALIDATE_INT);
            if (false === $dayInt || $dayInt < 1 || $dayInt > 7) {
                $context->buildViolation('Masque de jours invalide : les clés doivent être des jours ISO de 1 (lundi) à 7 (dimanche).')
                    ->atPath('dayOverrides')
                    ->addViolation();

                continue;
            }
            if (!\is_string($state) || !\in_array($state, $allowedStates, true)) {
                $context->buildViolation('Masque de jours invalide : chaque jour doit valoir OPEN ou CLOSED.')
                    ->atPath('dayOverrides')
                    ->addViolation();
            }
        }
    }
}

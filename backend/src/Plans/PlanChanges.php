<?php

declare(strict_types=1);

namespace App\Plans;

/**
 * Ce qui diffère entre deux copies du froid d'un plan ({@see PlanState::changesTo}) : une ligne par famille qui
 * change, avec le nombre de lignes ajoutées, retirées ou modifiées. Un champ froid qui diffère supprimerait les
 * plans de période pas commencés (`destroys`).
 */
final readonly class PlanChanges
{
    /** @param list<array{family: string, count: int, destroys: bool}> $lines */
    public function __construct(private array $lines) {}

    public function isEmpty(): bool
    {
        return [] === $this->lines;
    }

    public function destroys(): bool
    {
        foreach ($this->lines as $line) {
            if ($line['destroys']) {
                return true;
            }
        }

        return false;
    }

    /** L'empreinte de la liste : une confirmation porte le jeton de la liste vue. */
    public function token(): string
    {
        return hash('sha256', json_encode($this->lines, \JSON_THROW_ON_ERROR));
    }

    /** @return list<array{family: string, count: int, destroys: bool}> */
    public function lines(): array
    {
        return $this->lines;
    }
}

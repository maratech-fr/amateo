<?php

declare(strict_types=1);

namespace App\Plans;

use App\Structure\Family;

/**
 * La copie du froid d'un plan : famille (nom court) => id de ligne => champs froids ({@see Family::$cold}), valeurs
 * normalisées (heure `H:i:s`, date `Y-m-d`, énumération par sa valeur). Prise par {@see PlanStates::capture}, gardée
 * par version dans `schedule_state`. Rien de libre n'y entre : ni nom, ni contact, ni libellé.
 */
final readonly class PlanState
{
    /** @param array<string, array<string, array<string, mixed>>> $families */
    public function __construct(private array $families) {}

    /** @return array<string, array<string, array<string, mixed>>> */
    public function toArray(): array
    {
        return $this->families;
    }

    /**
     * Ce qui diffère d'ici à l'autre copie, famille par famille : une ligne présente d'un seul côté, ou dont un champ
     * froid diffère, compte une fois. Tout écart porte sur du froid : chaque ligne supprimerait.
     */
    public function changesTo(self $other): PlanChanges
    {
        $theirs = $other->toArray();
        $families = array_unique([...array_keys($this->families), ...array_keys($theirs)]);
        sort($families);

        $lines = [];
        foreach ($families as $family) {
            $mine = $this->families[$family] ?? [];
            $their = $theirs[$family] ?? [];
            $count = 0;
            foreach (array_unique([...array_keys($mine), ...array_keys($their)]) as $rowId) {
                if (!isset($mine[$rowId], $their[$rowId]) || $mine[$rowId] !== $their[$rowId]) {
                    ++$count;
                }
            }
            if ($count > 0) {
                $lines[] = ['family' => $family, 'count' => $count, 'destroys' => true];
            }
        }

        return new PlanChanges($lines);
    }
}

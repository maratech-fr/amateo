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
    /**
     * Les familles dont le compte se dit par une cible, pas par ligne : changer le coach principal d'une équipe retire
     * un lien et en ajoute un, c'est une équipe qui change.
     */
    private const array COUNTED_BY = ['TeamCoach' => 'teamId'];

    /** @param array<string, array<string, array<string, mixed>>> $families */
    public function __construct(private array $families) {}

    /** @return array<string, array<string, array<string, mixed>>> */
    public function toArray(): array
    {
        return $this->families;
    }

    /**
     * Ce qui diffère d'ici à l'autre copie, famille par famille : une ligne présente d'un seul côté, ou dont un champ
     * froid diffère, compte une fois (le coach principal, une fois par équipe). Tout écart porte sur du froid : chaque
     * ligne supprimerait.
     */
    public function changesTo(self $other): PlanChanges
    {
        $theirs = $other->toArray();
        $families = array_unique([...array_keys($this->families), ...array_keys($theirs)]);
        sort($families);

        $lines = [];
        $rows = [];
        foreach ($families as $family) {
            $mine = $this->families[$family] ?? [];
            $their = $theirs[$family] ?? [];
            $counted = [];
            foreach (array_unique([...array_keys($mine), ...array_keys($their)]) as $rowId) {
                if (!isset($mine[$rowId], $their[$rowId]) || $mine[$rowId] !== $their[$rowId]) {
                    $rows[$family][(string) $rowId] = [$mine[$rowId] ?? null, $their[$rowId] ?? null];
                    $by = self::COUNTED_BY[$family] ?? null;
                    $target = null === $by ? null : ($mine[$rowId][$by] ?? $their[$rowId][$by] ?? null);
                    $counted[\is_string($target) ? $target : (string) $rowId] = true;
                }
            }
            if ([] !== $counted) {
                $lines[] = ['family' => $family, 'count' => \count($counted), 'destroys' => true];
            }
        }

        return new PlanChanges($lines, $rows);
    }
}

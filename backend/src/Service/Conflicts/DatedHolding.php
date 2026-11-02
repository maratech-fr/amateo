<?php

declare(strict_types=1);

namespace App\Service\Conflicts;

/**
 * L'affectation d'UNE date : chaque séance ou match a UN tenant parmi ses candidats, ou personne.
 * Pure, et le coach stocké sur un créneau n'en sort jamais modifié : la règle est ponctuelle.
 *
 * Les candidats arrivent déjà filtrés par l'appelant, chacun selon son rail d'indisponibilité, sans
 * repli. Une personne ne tient pas deux besoins qui se chevauchent, ni un besoin qui chevauche un
 * besoin où elle joue. Intervalles demi-ouverts.
 *
 * Ordre stable : les matchs par coup d'envoi puis id, puis les séances par début puis id ; les
 * candidats dans l'ordre donné, « personne » en dernier ; la PREMIÈRE solution qui tient le plus de
 * besoins gagne. Au-delà de {@see self::BUDGET} pas, un glouton dans le même ordre prend le relais :
 * une journée très chargée ne bloque pas l'écran et le résultat reste le même d'un calcul à l'autre.
 *
 * Cas partagés avec le miroir du moteur : `tests/Fixtures/datedHolding.parity.json`.
 *
 * @phpstan-type Need array{id: string, kind: string, teamId: string, startMinutes: int, endMinutes: int, candidates: list<string>, players: list<string>}
 */
final class DatedHolding
{
    private const int BUDGET = 10000;

    /** @var list<Need> */
    private array $needs = [];

    /** @var array<int, array<int, true>> besoin → besoins qui le chevauchent */
    private array $overlaps = [];

    /** @var array<int, string> besoin → tenant, pendant la recherche */
    private array $current = [];

    /** @var array<int, string> */
    private array $best = [];

    private int $bestCount = -1;

    private int $steps = 0;

    /** @param Need $need */
    private static function kindRank(array $need): int
    {
        return 'match' === $need['kind'] ? 0 : 1;
    }

    /**
     * @param list<Need> $needs
     */
    public function assign(array $needs): DatedAssignment
    {
        usort($needs, static fn (array $a, array $b): int => [self::kindRank($a), $a['startMinutes'], $a['id']] <=> [self::kindRank($b), $b['startMinutes'], $b['id']]);
        $this->needs = $needs;
        $this->overlaps = [];
        foreach ($needs as $i => $need) {
            foreach ($needs as $j => $other) {
                if ($i !== $j && $need['startMinutes'] < $other['endMinutes'] && $other['startMinutes'] < $need['endMinutes']) {
                    $this->overlaps[$i][$j] = true;
                }
            }
        }
        $this->current = [];
        $this->best = [];
        $this->bestCount = -1;
        $this->steps = 0;

        $this->search(0, 0);
        if ($this->steps > self::BUDGET) {
            $this->best = $this->greedy();
        }

        $holders = [];
        $unheld = [];
        foreach ($needs as $i => $need) {
            if (isset($this->best[$i])) {
                $holders[$need['id']] = $this->best[$i];
            } else {
                $unheld[$need['id']] = true;
            }
        }

        return new DatedAssignment($holders, $unheld, $this->steps);
    }

    private function search(int $index, int $held): void
    {
        $count = \count($this->needs);
        // Le budget est dépassé, ou la solution déjà trouvée tient autant que ce qui reste possible.
        if ($this->steps > self::BUDGET || $this->bestCount === $count || $held + $count - $index <= $this->bestCount) {
            return;
        }
        if ($index === $count) {
            $this->bestCount = $held;
            $this->best = $this->current;

            return;
        }

        foreach ($this->needs[$index]['candidates'] as $candidate) {
            if ($this->canHold($index, $candidate, $this->current)) {
                ++$this->steps;
                $this->current[$index] = $candidate;
                $this->search($index + 1, $held + 1);
                unset($this->current[$index]);
            }
        }

        ++$this->steps;
        $this->search($index + 1, $held);
    }

    /** @return array<int, string> */
    private function greedy(): array
    {
        $holders = [];
        foreach ($this->needs as $index => $need) {
            foreach ($need['candidates'] as $candidate) {
                if ($this->canHold($index, $candidate, $holders)) {
                    $holders[$index] = $candidate;

                    break;
                }
            }
        }

        return $holders;
    }

    /** @param array<int, string> $holders */
    private function canHold(int $index, string $person, array $holders): bool
    {
        foreach (array_keys($this->overlaps[$index] ?? []) as $other) {
            if (($holders[$other] ?? null) === $person || \in_array($person, $this->needs[$other]['players'], true)) {
                return false;
            }
        }

        return true;
    }
}

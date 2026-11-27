<?php

declare(strict_types=1);

namespace App\Matches;

use App\Entity\Fixture;

/**
 * Les écarts source⇄app encore ouverts sur un match, rendus par {@see FederalFollowUp::deviations}.
 * Lit et écrit encore la colonne du match ; une entrée par champ divergent, ordre canonique date →
 * kickoff → venue.
 *
 * @phpstan-import-type PendingDeviation from Fixture
 */
final readonly class FederalDeviations
{
    public function __construct(private Fixture $fixture) {}

    /** @return list<PendingDeviation> */
    public function all(): array
    {
        return $this->fixture->getPendingDeviations();
    }

    public function any(): bool
    {
        return $this->fixture->hasPendingDeviations();
    }

    /**
     * Vrai si au moins une entrée est à TRANCHER (pas appliquée d'office) : aucun geste en masse ne
     * l'acquitte.
     */
    public function anyArbitrable(): bool
    {
        return $this->fixture->hasArbitrableDeviations();
    }

    /** @return PendingDeviation|null */
    public function get(string $field): ?array
    {
        return $this->fixture->getPendingDeviation($field);
    }

    /**
     * Remplace l'entrée du champ si elle existe, l'ajoute sinon.
     *
     * @param PendingDeviation $entry
     */
    public function put(array $entry): void
    {
        $this->fixture->putPendingDeviation($entry);
    }

    public function remove(string $field): void
    {
        $this->fixture->removePendingDeviation($field);
    }

    public function clear(): void
    {
        $this->fixture->clearPendingDeviations();
    }
}

<?php

declare(strict_types=1);

namespace App\Matches;

use App\Entity\Fixture;

/**
 * Les écarts source⇄app encore ouverts sur un match, rendus par {@see FederalFollowUp::deviations}. Lus et écrits
 * dans sa ligne de suivi fédéral (créée à la première écriture) ; une entrée par champ divergent, ordre canonique
 * date → kickoff → venue.
 *
 * @phpstan-import-type PendingDeviation from Fixture
 */
final readonly class FederalDeviations
{
    public function __construct(private FederalFollowUp $followUp, private Fixture $fixture) {}

    /** @return list<PendingDeviation> */
    public function all(): array
    {
        return $this->followUp->trackingOf($this->fixture)?->getPendingDeviations() ?? [];
    }

    public function any(): bool
    {
        return $this->followUp->trackingOf($this->fixture)?->hasPendingDeviations() ?? false;
    }

    /**
     * Vrai si au moins une entrée est à TRANCHER (pas appliquée d'office) : aucun geste en masse ne
     * l'acquitte.
     */
    public function anyArbitrable(): bool
    {
        return $this->followUp->trackingOf($this->fixture)?->hasArbitrableDeviations() ?? false;
    }

    /** @return PendingDeviation|null */
    public function get(string $field): ?array
    {
        return $this->followUp->trackingOf($this->fixture)?->getPendingDeviation($field);
    }

    /**
     * Remplace l'entrée du champ si elle existe, l'ajoute sinon.
     *
     * @param PendingDeviation $entry
     */
    public function put(array $entry): void
    {
        $this->followUp->trackingFor($this->fixture)->putPendingDeviation($entry);
    }

    public function remove(string $field): void
    {
        $this->followUp->trackingOf($this->fixture)?->removePendingDeviation($field);
    }

    public function clear(): void
    {
        $this->followUp->trackingOf($this->fixture)?->clearPendingDeviations();
    }
}

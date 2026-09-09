<?php

declare(strict_types=1);

namespace App\Service\Basketball;

use App\Entity\Venue;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Résout un libellé de salle FBI/FFBB vers un gymnase du club (P4-187a D3).
 *
 * Deux niveaux, jamais un placement :
 *  - {@see resolveConfirmed} — égalité STRICTE (normalisée) avec un alias CONFIRMÉ
 *    du gymnase. C'est ce qui pose automatiquement `venueId` à l'intégration d'un
 *    domicile (import xlsx ou canal API) — la rencontre reste UNPLACED, on ne fait
 *    que la rendre visible de la collision de gymnase et de la fermeture.
 *  - {@see suggest} — proposition FLOUE (nom + alias) rendue en lecture
 *    (`Fixture.suggestedVenueId`). Une ambiguïté (≥ 2 gymnases candidats) rend
 *    `null` : on ne devine jamais entre deux salles.
 *
 * Les gymnases INACTIFS sont ignorés des deux côtés. La liste des gymnases du club
 * (filtrée tenant/saison par Doctrine) est mémoïsée par requête — le provider la
 * traverse par rencontre, sans N+1. {@see ResetInterface} : le conteneur vide la
 * mémo entre deux requêtes d'un runtime long (worker, tests) — jamais les gymnases
 * d'un autre club en cache.
 */
final class VenueAliasResolver implements ResetInterface
{
    /** @var list<Venue>|null the active club venues, memoized per request */
    private ?array $activeVenues = null;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly VenueLabelNormalizer $normalizer,
    ) {}

    /**
     * Le gymnase dont un alias confirmé égale EXACTEMENT (normalisé) le libellé,
     * ou null. Un libellé vide (après normalisation) ne résout rien.
     */
    public function resolveConfirmed(?string $label): ?string
    {
        if (null === $label) {
            return null;
        }
        $key = $this->normalizer->normalize($label);
        if ('' === $key) {
            return null;
        }

        foreach ($this->venues() as $venue) {
            if (\in_array($key, $venue->getExternalLabels(), true)) {
                return $venue->getId();
            }
        }

        return null;
    }

    /**
     * Une proposition floue : le SEUL gymnase dont le nom ou un alias ressemble au
     * libellé. Zéro candidat → null ; deux gymnases distincts ou plus → null
     * (ambiguïté, jamais un pari).
     */
    public function suggest(?string $label): ?string
    {
        if (null === $label) {
            return null;
        }
        $key = $this->normalizer->normalize($label);
        if ('' === $key) {
            return null;
        }

        $candidates = [];
        foreach ($this->venues() as $venue) {
            if ($this->looselyMatches($venue, $label, $key)) {
                $candidates[$venue->getId()] = true;
            }
        }

        return 1 === \count($candidates) ? array_key_first($candidates) : null;
    }

    public function reset(): void
    {
        $this->activeVenues = null;
    }

    private function looselyMatches(Venue $venue, string $rawLabel, string $normalizedLabel): bool
    {
        if ($this->normalizer->fuzzyMatches($venue->getName(), $rawLabel)) {
            return true;
        }
        foreach ($venue->getExternalLabels() as $alias) {
            if ($alias === $normalizedLabel || $this->normalizer->fuzzyMatches($alias, $rawLabel)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<Venue>
     */
    private function venues(): array
    {
        if (null === $this->activeVenues) {
            $this->activeVenues = array_values(array_filter(
                $this->entityManager->getRepository(Venue::class)->findAll(),
                static fn (Venue $venue): bool => $venue->getIsActive(),
            ));
        }

        return $this->activeVenues;
    }
}

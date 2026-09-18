<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Fixture;
use App\Enum\FixtureHomeAway;
use App\Repository\OpponentTravelRepository;
use App\Service\Basketball\VenueLabelNormalizer;

/**
 * P2-54 RMM-9 PR-3 — la MAISON UNIQUE de la projection « trajet aller-retour par
 * rencontre AWAY ». Une rencontre à l'extérieur voit son empreinte grossir du
 * trajet aller-retour (2 × aller simple) vers le gymnase de l'adversaire, lu depuis
 * le `opponent_travel` tenant via le code organisme estampillé. Grain ÉQUIPE
 * (P2-54 PR-1) : chaque rencontre résout son trajet par (code, libellé normalisé)
 * → override équipe, sinon défaut club. Une rencontre sans code / sans trajet reste
 * absente (aucun conflit spatial, dit franchement).
 *
 * Deux consommateurs en dérivent EXACTEMENT la même chose et doivent le faire de la
 * MÊME façon : le radar de conflits ({@see ConflictRadarLoader}) qui étend l'empreinte
 * AWAY servie à l'écran, et le payload de placement ({@see MatchPlacementPayloadBuilder})
 * qui envoie le trajet au solveur (D3) pour qu'il protège le coach pendant son
 * déplacement. Un seul chargeur supprime la divergence à la racine.
 */
final class OpponentTravelProjection
{
    public function __construct(
        private readonly OpponentTravelRepository $opponentTravelRepository,
        private readonly VenueLabelNormalizer $labelNormalizer,
    ) {}

    /**
     * fixtureId → trajet aller-retour (minutes, 2 × aller simple), pour les seules
     * rencontres AWAY dont l'adversaire a un trajet connu. Une saison nulle ou sans
     * trajet rend un tableau vide (rien de modélisé → aucune extension d'empreinte).
     *
     * @param list<Fixture> $fixtures
     *
     * @return array<string, int>
     */
    public function roundTripByFixtureId(?string $seasonId, array $fixtures): array
    {
        $roundTripByFixtureId = [];
        if (null === $seasonId) {
            return $roundTripByFixtureId;
        }
        $travelBySeason = $this->opponentTravelRepository->travelMinutesBySeason($seasonId);
        if ([] === $travelBySeason) {
            return $roundTripByFixtureId;
        }
        foreach ($fixtures as $fixture) {
            $code = $fixture->getOpponentOrganismeCode();
            if (FixtureHomeAway::AWAY !== $fixture->getHomeAway() || null === $code || !isset($travelBySeason[$code])) {
                continue;
            }
            $teamKey = $this->labelNormalizer->normalize(trim($fixture->getOpponentLabel()));
            $entry = $travelBySeason[$code];
            $oneWay = $entry['teams'][$teamKey] ?? $entry['club'];
            if (null !== $oneWay) {
                $roundTripByFixtureId[$fixture->getId()] = 2 * $oneWay;
            }
        }

        return $roundTripByFixtureId;
    }
}

<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Club;
use App\Entity\Fixture;
use App\Entity\OpponentVenueLink;
use App\Enum\FixtureHomeAway;
use App\Repository\ClubRepository;
use App\Repository\OpponentDirectoryEntryRepository;
use App\Repository\OpponentVenueLinkRepository;
use App\Service\Basketball\VenueLabelNormalizer;
use App\Service\Geo\IgnRoutingClient;
use App\Service\Geo\TravelTimeCache;

/**
 * P2-54 — la MAISON UNIQUE de la projection « trajet aller-retour par rencontre AWAY ».
 * Amendement 2026-09-20 : le gymnase se rattache au CLUB adverse et au LIBELLÉ de salle
 * ({@see OpponentVenueLink}), le trajet est une CONSTANTE lue depuis {@see ClubTravelCache}
 * (siège du club → gymnase), jamais stockée par rencontre.
 *
 * Résolution d'une rencontre AWAY :
 *   1. son lien `(code, libellé FBI normalisé)` → gymnase → trajet en cache (EXACT) ;
 *   2. repli si la rencontre n'a pas de libellé ou pas de lien : le gymnase le PLUS
 *      FRÉQUENT de ce club adverse (parmi ses liens dont le trajet est déjà en cache,
 *      compté sur les rencontres AWAY) → trajet approché (`approximated`) ;
 *   3. repli VILLE : les coordonnées d'annuaire fédéral de l'adversaire → trajet approché ;
 *   4. sinon absente (aucun conflit spatial, dit franchement).
 *
 * `roundTripByFixtureId` (fixtureId → 2 × aller simple) garde EXACTEMENT sa forme
 * `array<string, int>` : ses deux consommateurs de contrat — le radar
 * ({@see ConflictRadarLoader}) et le payload de placement D3
 * ({@see MatchPlacementPayloadBuilder}) — n'ont pas bougé (aucun bump de CONTRACT_VERSION).
 * {@see roundTripDetailByFixtureId} sert le même trajet PLUS le drapeau `approximated`
 * (calculé SERVEUR) aux consommateurs qui l'affichent (API adversaires).
 */
final class OpponentTravelProjection
{
    public function __construct(
        private readonly OpponentVenueLinkRepository $linkRepository,
        private readonly OpponentDirectoryEntryRepository $directory,
        private readonly ClubRepository $clubRepository,
        private readonly TravelTimeCache $travelCache,
        private readonly VenueLabelNormalizer $labelNormalizer,
    ) {}

    /**
     * fixtureId → trajet aller-retour (minutes, 2 × aller simple). Forme INCHANGÉE.
     *
     * @param list<Fixture> $fixtures
     *
     * @return array<string, int>
     */
    public function roundTripByFixtureId(?string $seasonId, array $fixtures): array
    {
        $minutes = [];
        foreach ($this->roundTripDetailByFixtureId($seasonId, $fixtures) as $fixtureId => $detail) {
            $minutes[$fixtureId] = $detail['minutes'];
        }

        return $minutes;
    }

    /**
     * fixtureId → { minutes: aller-retour, approximated: le trajet vient d'un repli
     * (gymnase supposé ou ville seule), pas du lien exact de la rencontre }.
     *
     * @param list<Fixture> $fixtures
     *
     * @return array<string, array{minutes: int, approximated: bool}>
     */
    public function roundTripDetailByFixtureId(?string $seasonId, array $fixtures): array
    {
        $result = [];
        if (null === $seasonId || [] === $fixtures) {
            return $result;
        }

        $clubId = $fixtures[0]->getClubId();
        $club = $this->clubRepository->find($clubId);
        if (!$club instanceof Club || null === $club->getLatitude() || null === $club->getLongitude()) {
            return $result; // siège non localisé → aucun trajet modélisé
        }
        $cacheByDest = $this->travelCache->lookupAllFromOrigin(
            $clubId,
            IgnRoutingClient::PROFILE_CAR,
            (float) $club->getLatitude(),
            (float) $club->getLongitude(),
        );

        // Liens indexés par (code, libellé normalisé).
        /** @var array<string, OpponentVenueLink> $linkByKey */
        $linkByKey = [];
        foreach ($this->linkRepository->findByClub($clubId) as $link) {
            $linkByKey[$link->getOpponentOrganismeCode() . '|' . $link->getFbiLabelNorm()] = $link;
        }

        $away = array_values(array_filter(
            $fixtures,
            static fn (Fixture $f): bool => FixtureHomeAway::AWAY === $f->getHomeAway() && null !== $f->getOpponentOrganismeCode(),
        ));

        $mostFrequentGym = $this->mostFrequentResolvedGymByCode($away, $linkByKey, $cacheByDest);
        $directoryByCode = $this->directoryCoordsByCode($away);

        foreach ($away as $fixture) {
            $code = (string) $fixture->getOpponentOrganismeCode();
            $norm = $this->normalizedLabel($fixture);
            $link = null !== $norm ? ($linkByKey[$code . '|' . $norm] ?? null) : null;

            // 1. Le lien EXACT de la rencontre.
            if ($link instanceof OpponentVenueLink) {
                $oneWay = $cacheByDest[$this->travelCache->destKey($link->getLatitude(), $link->getLongitude())] ?? null;
                if (null !== $oneWay) {
                    $result[$fixture->getId()] = ['minutes' => 2 * $oneWay, 'approximated' => false];
                }

                continue; // lien présent mais trajet non calculé (pending) → absent, jamais un repli
            }

            // 2. Repli gymnase le plus fréquent de ce club adverse.
            $fallback = $mostFrequentGym[$code] ?? null;
            if (null !== $fallback) {
                $result[$fixture->getId()] = ['minutes' => 2 * $fallback, 'approximated' => true];

                continue;
            }

            // 3. Repli VILLE (coordonnées d'annuaire).
            $dir = $directoryByCode[$code] ?? null;
            if (null !== $dir) {
                $oneWay = $cacheByDest[$this->travelCache->destKey($dir[0], $dir[1])] ?? null;
                if (null !== $oneWay) {
                    $result[$fixture->getId()] = ['minutes' => 2 * $oneWay, 'approximated' => true];
                }
            }
        }

        return $result;
    }

    /**
     * Par code adverse, le trajet aller simple (en cache) du gymnase le PLUS FRÉQUENT parmi
     * les rencontres AWAY qui portent un lien résolu (trajet déjà calculé). Sert de repli aux
     * rencontres du même adversaire sans libellé/lien.
     *
     * @param list<Fixture>                    $away
     * @param array<string, OpponentVenueLink> $linkByKey
     * @param array<string, int>               $cacheByDest
     *
     * @return array<string, int> code → aller simple (minutes) du gymnase dominant
     */
    private function mostFrequentResolvedGymByCode(array $away, array $linkByKey, array $cacheByDest): array
    {
        /** @var array<string, array<string, array{count: int, oneWay: int}>> $byCode */
        $byCode = [];
        foreach ($away as $fixture) {
            $norm = $this->normalizedLabel($fixture);
            if (null === $norm) {
                continue;
            }
            $code = (string) $fixture->getOpponentOrganismeCode();
            $link = $linkByKey[$code . '|' . $norm] ?? null;
            if (!$link instanceof OpponentVenueLink) {
                continue;
            }
            $destKey = $this->travelCache->destKey($link->getLatitude(), $link->getLongitude());
            $oneWay = $cacheByDest[$destKey] ?? null;
            if (null === $oneWay) {
                continue; // gymnase non encore routé : ne peut servir de repli
            }
            $byCode[$code][$destKey] ??= ['count' => 0, 'oneWay' => $oneWay];
            ++$byCode[$code][$destKey]['count'];
        }

        $result = [];
        foreach ($byCode as $code => $gyms) {
            $best = null;
            foreach ($gyms as $gym) {
                if (null === $best || $gym['count'] > $best['count']) {
                    $best = $gym;
                }
            }
            if (null !== $best) {
                $result[$code] = $best['oneWay'];
            }
        }

        return $result;
    }

    /**
     * @param list<Fixture> $away
     *
     * @return array<string, array{0: float, 1: float}> code → [lat, lon] de l'annuaire
     */
    private function directoryCoordsByCode(array $away): array
    {
        $codes = [];
        foreach ($away as $fixture) {
            $code = $fixture->getOpponentOrganismeCode();
            if (null !== $code) {
                $codes[] = $code;
            }
        }
        $byCode = [];
        foreach ($this->directory->findByFfbbOrganismeCodes(array_values(array_unique($codes))) as $entry) {
            $lat = $entry->getLatitude();
            $lon = $entry->getLongitude();
            if (null !== $lat && null !== $lon) {
                $byCode[$entry->getFfbbOrganismeCode()] = [$lat, $lon];
            }
        }

        return $byCode;
    }

    /** Le libellé de salle FBI de la rencontre, normalisé — ou null si elle n'en porte pas. */
    private function normalizedLabel(Fixture $fixture): ?string
    {
        $label = $fixture->getFbiVenueLabel();
        if (null === $label || '' === trim($label)) {
            return null;
        }
        $norm = $this->labelNormalizer->normalize(trim($label));

        return '' === $norm ? null : $norm;
    }
}

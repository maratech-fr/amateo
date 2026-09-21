<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Club;
use App\Entity\Fixture;
use App\Entity\OpponentDirectoryEntry;
use App\Entity\OpponentVenueLink;
use App\Enum\FixtureHomeAway;
use App\Repository\ClubRepository;
use App\Repository\OpponentDirectoryEntryRepository;
use App\Repository\OpponentVenueLinkRepository;
use App\Service\Basketball\VenueLabelNormalizer;
use App\Service\Geo\IgnRoutingClient;
use App\Service\Geo\TravelTimeCache;

/**
 * P2-54 — la MAISON UNIQUE du trajet d'une rencontre AWAY. Amendement 2026-09-20 : le
 * gymnase se rattache au CLUB adverse et au LIBELLÉ de salle ({@see OpponentVenueLink}),
 * le trajet est une CONSTANTE lue depuis {@see ClubTravelCache} (siège du club → gymnase),
 * jamais stockée par rencontre. Le trajet EST une propriété DÉRIVÉE de la rencontre (le
 * besoin fondateur), pas du club — c'est ce service qui la dérive, en un seul endroit.
 *
 * Résolution d'une rencontre AWAY, avec sa CAUSE (`basis`) :
 *   1. `linked` — son lien `(code, libellé FBI normalisé)` → gymnase EXACT ;
 *   2. `most_frequent` — pas de libellé/lien : le gymnase le PLUS FRÉQUENT de ce club
 *      adverse (parmi ses liens au trajet déjà en cache) → « gymnase supposé », approché ;
 *   3. `city` — repli VILLE : l'annuaire fédéral de l'adversaire → « ville seule », approché ;
 *   4. sinon absente (rien de connu).
 *
 * Trois vues, UNE source :
 *   - {@see roundTripByFixtureId} (fixtureId → 2 × aller simple) — forme `array<string,int>`
 *     INCHANGÉE, ses deux consommateurs de contrat (radar {@see ConflictRadarLoader}, payload
 *     de placement D3 {@see MatchPlacementPayloadBuilder}) n'ont pas bougé (aucun bump) ;
 *   - {@see roundTripDetailByFixtureId} (+ `approximated`) ;
 *   - {@see awayTravelByFixtureId} — le DÉTAIL complet (lieu + aller simple + cause) que
 *     `FixtureResource.awayTravel` expose au calendrier (chip par rencontre).
 */
final class OpponentTravelProjection
{
    public function __construct(
        private readonly OpponentVenueLinkRepository $linkRepository,
        private readonly OpponentDirectoryEntryRepository $directory,
        private readonly ClubRepository $clubRepository,
        private readonly TravelTimeCache $travelCache,
        private readonly VenueLabelNormalizer $labelNormalizer,
        private readonly OpponentPairingKey $pairingKey,
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
        foreach ($this->detailByFixtureId($seasonId, $fixtures) as $fixtureId => $detail) {
            if (null !== $detail['oneWayMinutes']) {
                $minutes[$fixtureId] = 2 * $detail['oneWayMinutes'];
            }
        }

        return $minutes;
    }

    /**
     * fixtureId → { minutes: aller-retour, approximated }. Seules les rencontres au trajet
     * connu (aller simple en cache) y figurent.
     *
     * @param list<Fixture> $fixtures
     *
     * @return array<string, array{minutes: int, approximated: bool}>
     */
    public function roundTripDetailByFixtureId(?string $seasonId, array $fixtures): array
    {
        $result = [];
        foreach ($this->detailByFixtureId($seasonId, $fixtures) as $fixtureId => $detail) {
            if (null !== $detail['oneWayMinutes']) {
                $result[$fixtureId] = ['minutes' => 2 * $detail['oneWayMinutes'], 'approximated' => $detail['approximated']];
            }
        }

        return $result;
    }

    /**
     * fixtureId → le DÉTAIL du trajet d'une rencontre AWAY (lieu, aller simple, cause),
     * pour `FixtureResource.awayTravel`. Une rencontre dont RIEN n'est connu est ABSENTE
     * (le champ vaut alors null). `oneWayMinutes` peut être null même avec un lieu connu
     * (trajet pas encore calculé) — le chip montre le lieu sans les minutes.
     *
     * @param list<Fixture> $fixtures
     *
     * @return array<string, array{venueLabel: string|null, city: string|null, precision: string|null, oneWayMinutes: int|null, approximated: bool, basis: string}>
     */
    public function awayTravelByFixtureId(?string $seasonId, array $fixtures): array
    {
        return $this->detailByFixtureId($seasonId, $fixtures);
    }

    /**
     * Le foyer de résolution : fixtureId → détail complet, pour les seules rencontres AWAY
     * dont au moins un LIEU est connu (lien, gymnase supposé, ou ville).
     *
     * @param list<Fixture> $fixtures
     *
     * @return array<string, array{venueLabel: string|null, city: string|null, precision: string|null, oneWayMinutes: int|null, approximated: bool, basis: string}>
     */
    private function detailByFixtureId(?string $seasonId, array $fixtures): array
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

        // Toutes les rencontres AWAY, code fédéral OU non : un adversaire sans code est keyé
        // par sa clé SENTINELLE (dérivée du libellé) — le lien manuel posé sous cette clé rend
        // le trajet `linked`/`most_frequent`, ce qui débloque les rencontres sans code.
        $away = array_values(array_filter(
            $fixtures,
            static fn (Fixture $f): bool => FixtureHomeAway::AWAY === $f->getHomeAway(),
        ));

        $mostFrequentGym = $this->mostFrequentResolvedGymByCode($away, $linkByKey, $cacheByDest);
        $directoryByCode = $this->directoryByCode($away);

        foreach ($away as $fixture) {
            $code = $this->pairingKey->fromOpponent($fixture->getOpponentOrganismeCode(), trim($fixture->getOpponentLabel()));
            $norm = $this->normalizedLabel($fixture);
            $link = null !== $norm ? ($linkByKey[$code . '|' . $norm] ?? null) : null;

            // 1. `linked` — le lien EXACT de la rencontre (lieu toujours connu ; trajet peut
            // n'être pas encore en cache → oneWay null, le chip montre le lieu sans minutes).
            if ($link instanceof OpponentVenueLink) {
                $result[$fixture->getId()] = [
                    'venueLabel' => $link->getVenueLabel(),
                    'city' => null,
                    'precision' => 'VENUE',
                    'oneWayMinutes' => $cacheByDest[$this->travelCache->destKey($link->getLatitude(), $link->getLongitude())] ?? null,
                    'approximated' => false,
                    'basis' => 'linked',
                ];

                continue;
            }

            // 2. `most_frequent` — le gymnase dominant de ce club adverse (toujours en cache).
            $fallback = $mostFrequentGym[$code] ?? null;
            if (null !== $fallback) {
                $result[$fixture->getId()] = [
                    'venueLabel' => $fallback['label'],
                    'city' => null,
                    'precision' => 'VENUE',
                    'oneWayMinutes' => $fallback['oneWay'],
                    'approximated' => true,
                    'basis' => 'most_frequent',
                ];

                continue;
            }

            // 3. `city` — l'annuaire fédéral (ville seule). Trajet depuis les coordonnées ville
            // si en cache, sinon absent (le chip montre « ville seule » sans minutes).
            $entry = $directoryByCode[$code] ?? null;
            if ($entry instanceof OpponentDirectoryEntry && (null !== $entry->getCity() || (null !== $entry->getLatitude() && null !== $entry->getLongitude()))) {
                $lat = $entry->getLatitude();
                $lon = $entry->getLongitude();
                $oneWay = null !== $lat && null !== $lon ? ($cacheByDest[$this->travelCache->destKey($lat, $lon)] ?? null) : null;
                $result[$fixture->getId()] = [
                    'venueLabel' => null,
                    'city' => $entry->getCity(),
                    'precision' => $entry->getPrecision()->value,
                    'oneWayMinutes' => $oneWay,
                    'approximated' => true,
                    'basis' => 'city',
                ];
            }
        }

        return $result;
    }

    /**
     * Par code adverse, le gymnase le PLUS FRÉQUENT (libellé + aller simple en cache) parmi
     * les rencontres AWAY qui portent un lien résolu. Repli des rencontres sans libellé/lien.
     *
     * @param list<Fixture>                    $away
     * @param array<string, OpponentVenueLink> $linkByKey
     * @param array<string, int>               $cacheByDest
     *
     * @return array<string, array{oneWay: int, label: string}>
     */
    private function mostFrequentResolvedGymByCode(array $away, array $linkByKey, array $cacheByDest): array
    {
        /** @var array<string, array<string, array{count: int, oneWay: int, label: string}>> $byCode */
        $byCode = [];
        foreach ($away as $fixture) {
            $norm = $this->normalizedLabel($fixture);
            if (null === $norm) {
                continue;
            }
            $code = $this->pairingKey->fromOpponent($fixture->getOpponentOrganismeCode(), trim($fixture->getOpponentLabel()));
            $link = $linkByKey[$code . '|' . $norm] ?? null;
            if (!$link instanceof OpponentVenueLink) {
                continue;
            }
            $destKey = $this->travelCache->destKey($link->getLatitude(), $link->getLongitude());
            $oneWay = $cacheByDest[$destKey] ?? null;
            if (null === $oneWay) {
                continue; // gymnase non encore routé : ne peut servir de repli
            }
            $byCode[$code][$destKey] ??= ['count' => 0, 'oneWay' => $oneWay, 'label' => $link->getVenueLabel()];
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
                $result[$code] = ['oneWay' => $best['oneWay'], 'label' => $best['label']];
            }
        }

        return $result;
    }

    /**
     * @param list<Fixture> $away
     *
     * @return array<string, OpponentDirectoryEntry> code → entrée d'annuaire fédéral
     */
    private function directoryByCode(array $away): array
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
            $byCode[$entry->getFfbbOrganismeCode()] = $entry;
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

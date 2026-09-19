<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Fixture;
use App\Entity\OpponentVenueLink;
use App\Repository\OpponentDirectoryEntryRepository;
use App\Repository\OpponentVenueLinkRepository;
use App\Repository\OpponentVenueSuggestionRepository;
use App\Service\Basketball\VenueLabelNormalizer;

/**
 * Résout OÙ joue un adversaire extérieur — une VILLE — pour le détail par côté du radar
 * (« extérieur à … »). Amendement 2026-09-20 : la salle CHOISIE est portée par le lien
 * ({@see OpponentVenueLink}, grain `(code, libellé FBI normalisé)`), non plus par une
 * ligne opponent_travel season+team.
 *
 * Chargement en BATCH (aucun N+1) : les liens du club, les suggestions fédérales et
 * l'annuaire, tous keyés sur le code organisme. Résolution PURE ensuite.
 *
 * Ordre (le gymnase apparié décide, on sert SA ville — jamais un libellé de gymnase) :
 *   1. le lien de la rencontre `(code, libellé FBI normalisé)` → sa référence de salle
 *      fédérale → la ville de la SUGGESTION `(code, ref)` ({@see OpponentVenueSuggestion}) ;
 *   2. sinon (pas de lien, pas de ref, ou suggestion sans ville) → la ville de l'ANNUAIRE
 *      fédéral ({@see OpponentDirectoryEntry}, global, lecture seule), keyé sur le code ;
 *   3. null — rien de connu (l'UI dit « lieu inconnu »).
 *
 * Le libellé de gymnase n'est JAMAIS servi (c'est une salle, pas une ville). La ville est
 * servie TELLE QUELLE (aucune transformation de casse).
 */
final class OpponentPlaceResolver
{
    public function __construct(
        private readonly OpponentVenueLinkRepository $linkRepository,
        private readonly OpponentVenueSuggestionRepository $suggestionRepository,
        private readonly OpponentDirectoryEntryRepository $directoryRepository,
        private readonly VenueLabelNormalizer $labelNormalizer,
    ) {}

    /**
     * @param list<Fixture> $awayFixtures the AWAY fixtures whose place to resolve (already scoped)
     *
     * @return array<string, string> fixtureId → resolved city; a fixture with NO
     *                               resolvable city is ABSENT from the map (null place)
     */
    public function resolveByFixture(string $seasonId, array $awayFixtures): array
    {
        unset($seasonId); // le lien est club-scoped (sans saison) ; le club vient des fixtures
        if ([] === $awayFixtures) {
            return [];
        }
        $clubId = $awayFixtures[0]->getClubId();

        // Batch 1 — les liens du club, indexés `code|libellé normalisé`, dont on ne garde
        // que la référence de salle fédérale (le libellé de gymnase n'est jamais servi).
        /** @var array<string, string|null> $refByLinkKey */
        $refByLinkKey = [];
        foreach ($this->linkRepository->findByClub($clubId) as $link) {
            $refByLinkKey[$link->getOpponentOrganismeCode() . '|' . $link->getFbiLabelNorm()] = $link->getVenueExternalRef();
        }

        $codes = [];
        foreach ($awayFixtures as $fixture) {
            $code = $fixture->getOpponentOrganismeCode();
            if (null !== $code) {
                $codes[] = $code;
            }
        }

        // Batch 2 — la ville de la SUGGESTION fédérale par (code, ref). Les lignes FFBB_API
        // (ref null) ne peuvent jamais matcher une ref de lien : ignorées.
        /** @var array<string, array<string, string|null>> $suggestionCityByCodeRef */
        $suggestionCityByCodeRef = [];
        foreach ($this->suggestionRepository->findByFfbbOrganismeCodes($codes) as $suggestion) {
            $ref = $suggestion->getVenueExternalRef();
            if (null === $ref) {
                continue;
            }
            $suggestionCityByCodeRef[$suggestion->getFfbbOrganismeCode()][$ref] = $suggestion->getCity();
        }

        // Batch 3 — la ville de l'annuaire fédéral par code.
        $cityByCode = [];
        foreach ($this->directoryRepository->findByFfbbOrganismeCodes($codes) as $entry) {
            $cityByCode[$entry->getFfbbOrganismeCode()] = $entry->getCity();
        }

        $places = [];
        foreach ($awayFixtures as $fixture) {
            $place = $this->resolveOne($fixture, $refByLinkKey, $suggestionCityByCodeRef, $cityByCode);
            if (null !== $place) {
                $places[$fixture->getId()] = $place;
            }
        }

        return $places;
    }

    /**
     * @param array<string, string|null>                $refByLinkKey
     * @param array<string, array<string, string|null>> $suggestionCityByCodeRef
     * @param array<string, string|null>                $cityByCode
     */
    private function resolveOne(Fixture $fixture, array $refByLinkKey, array $suggestionCityByCodeRef, array $cityByCode): ?string
    {
        $code = $fixture->getOpponentOrganismeCode();
        if (null === $code) {
            return null;
        }

        // 1. Le lien de la rencontre → la ville de son gymnase apparié (via la ref fédérale).
        $label = $fixture->getFbiVenueLabel();
        if (null !== $label && '' !== trim($label)) {
            $norm = $this->labelNormalizer->normalize(trim($label));
            $ref = '' === $norm ? null : ($refByLinkKey[$code . '|' . $norm] ?? null);
            if (null !== $ref) {
                $city = $this->firstNonBlank([$suggestionCityByCodeRef[$code][$ref] ?? null]);
                if (null !== $city) {
                    return $city;
                }
            }
        }

        // 2. Ville de l'annuaire fédéral.
        return $this->firstNonBlank([$cityByCode[$code] ?? null]);
    }

    /**
     * @param list<string|null> $candidates
     */
    private function firstNonBlank(array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (null !== $candidate && '' !== trim($candidate)) {
                return trim($candidate);
            }
        }

        return null;
    }
}

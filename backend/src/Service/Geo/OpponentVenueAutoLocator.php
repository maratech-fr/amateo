<?php

declare(strict_types=1);

namespace App\Service\Geo;

use App\Entity\OpponentDirectoryEntry;
use App\Entity\OpponentVenueLink;
use App\Enum\FixtureHomeAway;
use App\Enum\OpponentVenueLinkSource;
use App\Message\ComputeTravelTimesMessage;
use App\Repository\FixtureRepository;
use App\Repository\OpponentDirectoryEntryRepository;
use App\Repository\OpponentVenueLinkRepository;
use App\Service\Basketball\FfbbApiClient;
use App\Service\Basketball\VenueLabelNormalizer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Throwable;

/**
 * P2-54 « adversaire multi-gymnases » — auto-apparie un LIBELLÉ de salle FBI vers un
 * gymnase FÉDÉRAL, pose un {@see OpponentVenueLink} (grain `(club, code, libellé FBI
 * normalisé)`, `source = AUTO`). Pour chaque libellé de salle DISTINCT écrit dans les
 * fixtures AWAY (`Fixture::getFbiVenueLabel()`), on cherche parmi les salles fédérales
 * de la commune de l'adversaire (index `ffbbserver_salles`) celle dont le libellé égale
 * STRICTEMENT le libellé du fichier, et on épingle ce gymnase — jamais le texte du
 * fichier (patron {@see OpponentLocationResolver}, revue sécurité).
 *
 * ⚠ N'écrit QUE la table TENANT `opponent_venue_link` — JAMAIS le partagé
 * `opponent_venue_suggestion` : une localisation AUTOMATIQUE (devinée d'un libellé de
 * fichier saisi par le club) n'est pas un CHOIX et ne doit pas alimenter le compteur
 * communautaire (revue sécurité — « un compte, jamais un qui », données fédérales
 * seules au partagé). Un lien AUTO ne compte donc jamais comme un choix.
 *
 * ⚠ Ne calcule PLUS de trajet ici (amendement 2026-09-20). Le trajet est une CONSTANTE
 * servie depuis {@see ClubTravelCache} ; son calcul quitte le rail d'import pour le
 * worker — les hooks d'import dispatchent {@see ComputeTravelTimesMessage}
 * (scope OPPONENTS) après cette passe.
 *
 * Sémantique par (code, libellé FBI normalisé) :
 *   - lien déjà **MANUAL** → laissé intact (le choix du gestionnaire est souverain),
 *     compté `skipped` ;
 *   - pas de lien, ou lien **AUTO** existant → on (re)tente : libellé re-vérifié contre
 *     l'index fédéral. Salle unique et stricte → lien posé/actualisé (`located`) ;
 *     0/≥2 hits → rien (best-effort, jamais destructeur — un lien AUTO déjà là survit).
 *
 * Best-effort intégral : FFBB muet / réseau en panne → le libellé reste « à apparier »,
 * jamais une exception (les hooks l'appellent en try/catch, le service se protège en plus).
 */
final class OpponentVenueAutoLocator
{
    /** Rayon de secours quand l'annuaire n'a pas de code postal (coordonnées seules). */
    private const int SEARCH_RADIUS_METERS = 10_000;

    /**
     * Borne DURE de fan-out fédéral par passe — la même que le cap de l'orchestrateur
     * ({@see OpponentRefreshController}::MAX_DISTINCT). Les hooks d'import (xlsx / canal
     * API) appellent ce service SANS passer par ce cap : un simple upload ne doit jamais
     * déclencher un fan-out illimité vers la fédération. Au-delà, les groupes en excès
     * sont comptés `skipped` sans le moindre appel réseau (journalisé une fois).
     */
    private const int MAX_GROUPS = 200;

    /** Longueur des colonnes `opponent_venue_link.fbi_label*` (VARCHAR(180)). */
    private const int LABEL_MAX_LENGTH = 180;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly FixtureRepository $fixtures,
        private readonly OpponentVenueLinkRepository $linkRepository,
        private readonly OpponentDirectoryEntryRepository $directory,
        private readonly FfbbApiClient $apiClient,
        private readonly VenueLabelNormalizer $labelNormalizer,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Auto-apparie chaque libellé de salle FBI DISTINCT des fixtures AWAY du club vers un
     * gymnase fédéral. Best-effort.
     *
     * BCK-32 — `$deadline` (epoch flottant absolu, optionnel) : une fois franchi, les
     * groupes restants sont comptés `skipped` sans aucun appel réseau — même canal que le
     * cap {@see MAX_GROUPS}. Null (hooks d'import) = aucune borne de mur.
     *
     * @return array{located: int, ambiguous: int, unmatched: int, skipped: int}
     */
    public function locate(string $clubId, string $seasonId, ?float $deadline = null): array
    {
        $groups = $this->groupAwayFixtures($clubId, $seasonId);
        $existingLinks = $this->existingLinks($clubId);

        // Un seul appel réseau salle par CODE organisme (sa commune ne bouge pas d'un
        // libellé à l'autre) : cache local par run. `false` = déjà tenté, aucune salle.
        /** @var array<string, list<array{numero: string, label: string, lat: float, lon: float}>|false> $candidateCache */
        $candidateCache = [];
        // Repli par NOM (plein-texte fédéral), caché par libellé NORMALISÉ (indépendant du code).
        /** @var array<string, list<array{numero: string, label: string, lat: float, lon: float}>|false> $nameCache */
        $nameCache = [];

        $located = 0;
        $ambiguous = 0;
        $unmatched = 0;
        $skipped = 0;
        $wrote = false;
        $processed = 0;
        $capNoted = false;
        $deadlineNoted = false;

        foreach ($groups as $group) {
            $existing = $existingLinks[$group['code'] . '|' . $group['norm']] ?? null;
            if ($existing instanceof OpponentVenueLink && OpponentVenueLinkSource::MANUAL === $existing->getSource()) {
                // Un choix manuel du gestionnaire est souverain : jamais recalculé (aucun réseau).
                ++$skipped;

                continue;
            }

            // BCK-32 — budget de mur épuisé : les groupes restants sautés SANS réseau.
            if (null !== $deadline && (float) $this->clock->now()->format('U.u') >= $deadline) {
                if (!$deadlineNoted) {
                    $this->logger->warning('Opponent venue auto-locate: wall-clock budget spent, remaining labels skipped');
                    $deadlineNoted = true;
                }
                ++$skipped;

                continue;
            }

            if ($processed >= self::MAX_GROUPS) {
                if (!$capNoted) {
                    $this->logger->warning('Opponent venue auto-locate: group cap reached, remaining labels skipped', ['cap' => self::MAX_GROUPS, 'groups' => \count($groups)]);
                    $capNoted = true;
                }
                ++$skipped;

                continue;
            }
            ++$processed;

            $candidates = $this->candidates($group['code'], $candidateCache);
            $matches = [] === $candidates ? [] : $this->strictMatches($group['label'], $candidates);

            // Repli par NOM quand la voie commune (commune / rayon) ne rend PAS un match unique
            // (0 candidat, ou 0/≥2 matches stricts) : on cherche la salle par son libellé en
            // plein-texte fédéral et on ne retient qu'une égalité STRICTE UNIQUE. Ambigu (≥2) ou
            // 0 → on laisse à la main : le comptage `ambiguous`/`unmatched` de la voie commune ne
            // change pas.
            if (1 !== \count($matches)) {
                $byName = $this->strictMatches($group['label'], $this->candidatesByName($group['label'], $nameCache));
                if (1 === \count($byName)) {
                    $matches = $byName;
                }
            }

            if (1 !== \count($matches)) {
                // 0 hit = à apparier ; ≥ 2 hits = ambigu (le libellé n'apparie rien de sûr).
                $this->logger->debug('Opponent venue auto-locate: label not uniquely matched', ['code' => $group['code'], 'label' => $group['label'], 'hits' => \count($matches)]);
                if (\count($matches) > 1) {
                    ++$ambiguous;
                } else {
                    ++$unmatched;
                }

                continue;
            }

            if ($this->writeAutoLink($existing, $clubId, $group['code'], $group['label'], $group['norm'], $matches[0])) {
                ++$located;
                $wrote = true;
            }
        }

        if ($wrote) {
            $this->entityManager->flush();
        }

        return ['located' => $located, 'ambiguous' => $ambiguous, 'unmatched' => $unmatched, 'skipped' => $skipped];
    }

    /**
     * Les groupes AWAY `(code, libellé FBI normalisé)` de la saison porteurs d'un libellé
     * de salle FBI ET d'un code organisme résolu — chacun avec son libellé BRUT (gardé
     * pour la comparaison stricte). Un libellé = un groupe (le grain du lien).
     *
     * @return list<array{code: string, label: string, norm: string}>
     */
    private function groupAwayFixtures(string $clubId, string $seasonId): array
    {
        unset($clubId); // la saison suffit à borner (RLS + filtre tenant scopent le club).
        /** @var array<string, array{code: string, label: string, norm: string}> $groups */
        $groups = [];
        foreach ($this->fixtures->findAwayBySeason($seasonId) as $fixture) {
            if (FixtureHomeAway::AWAY !== $fixture->getHomeAway()) {
                continue;
            }
            $code = $fixture->getOpponentOrganismeCode();
            $fbiLabel = $fixture->getFbiVenueLabel();
            if (null === $code || '' === $code || null === $fbiLabel || '' === trim($fbiLabel)) {
                continue; // sans code fédéral OU sans salle de fichier : rien à apparier
            }
            $rawLabel = trim($fbiLabel);
            $norm = $this->labelNormalizer->normalize($rawLabel);
            if ('' === $norm) {
                continue;
            }
            $norm = mb_substr($norm, 0, self::LABEL_MAX_LENGTH);
            $key = $code . '|' . $norm;
            // Un même libellé normalisé peut arriver avec des casses/espacements différents :
            // le premier libellé brut vu fait foi (stable, déterministe).
            $groups[$key] ??= ['code' => $code, 'label' => mb_substr($rawLabel, 0, self::LABEL_MAX_LENGTH), 'norm' => $norm];
        }

        return array_values($groups);
    }

    /**
     * Les liens `opponent_venue_link` du club, indexés `code|libellé normalisé` — le grain
     * exact que ce service pose ou re-vérifie.
     *
     * @return array<string, OpponentVenueLink>
     */
    private function existingLinks(string $clubId): array
    {
        $map = [];
        foreach ($this->linkRepository->findByClub($clubId) as $link) {
            $map[$link->getOpponentOrganismeCode() . '|' . $link->getFbiLabelNorm()] = $link;
        }

        return $map;
    }

    /**
     * Les salles fédérales candidates pour un code organisme : par le CP de l'annuaire si
     * connu, sinon par un rayon autour de ses coordonnées, sinon aucune. Cache par run (un
     * seul appel réseau par code). Best-effort : FFBB muet → [] (mémorisé).
     *
     * @param array<string, list<array{numero: string, label: string, lat: float, lon: float}>|false> $cache
     *
     * @return list<array{numero: string, label: string, lat: float, lon: float}>
     */
    private function candidates(string $code, array &$cache): array
    {
        if (\array_key_exists($code, $cache)) {
            return false === $cache[$code] ? [] : $cache[$code];
        }

        $entry = $this->directory->findOneByFfbbOrganismeCode($code);
        $hits = $this->fetchSalleHits($entry);
        $candidates = [];
        foreach ($hits as $hit) {
            $salle = $this->salleFromHit($hit);
            if (null !== $salle) {
                $candidates[] = $salle;
            }
        }
        $cache[$code] = [] === $candidates ? false : $candidates;

        return $candidates;
    }

    /**
     * Les salles candidates par NOM (plein-texte fédéral) pour un libellé de fichier — le repli
     * quand la commune/rayon ne tranche pas. Caché par libellé NORMALISÉ (un même libellé, quel
     * que soit le code, ne relance pas la recherche). Best-effort : FFBB muet → [] (mémorisé).
     *
     * @param array<string, list<array{numero: string, label: string, lat: float, lon: float}>|false> $cache
     *
     * @return list<array{numero: string, label: string, lat: float, lon: float}>
     */
    private function candidatesByName(string $label, array &$cache): array
    {
        $key = $this->labelNormalizer->normalize($label);
        if (\array_key_exists($key, $cache)) {
            return false === $cache[$key] ? [] : $cache[$key];
        }

        $candidates = [];
        try {
            foreach ($this->apiClient->searchSallesByName($label) as $hit) {
                $salle = $this->salleFromHit($hit);
                if (null !== $salle) {
                    $candidates[] = $salle;
                }
            }
        } catch (Throwable $e) {
            // Best-effort : FFBB muet / réseau en panne → aucune salle, jamais une erreur.
            $this->logger->debug('Opponent venue auto-locate: salle name search failed', ['label' => $label, 'error' => $e->getMessage()]);
        }
        $cache[$key] = [] === $candidates ? false : $candidates;

        return $candidates;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchSalleHits(?OpponentDirectoryEntry $entry): array
    {
        if (!$entry instanceof OpponentDirectoryEntry) {
            return [];
        }
        try {
            $postalCode = $entry->getPostalCode();
            if (null !== $postalCode && 1 === preg_match('/^\d{5}$/', $postalCode)) {
                return $this->apiClient->searchSalles($postalCode);
            }
            $lat = $entry->getLatitude();
            $lon = $entry->getLongitude();
            if (null !== $lat && null !== $lon) {
                return $this->apiClient->searchSallesNearby($lat, $lon, self::SEARCH_RADIUS_METERS);
            }
        } catch (Throwable $e) {
            // Best-effort : FFBB muet / réseau en panne → aucune salle, jamais une erreur.
            $this->logger->debug('Opponent venue auto-locate: salle search failed', ['code' => $entry->getFfbbOrganismeCode(), 'error' => $e->getMessage()]);
        }

        return [];
    }

    /**
     * @param list<array{numero: string, label: string, lat: float, lon: float}> $candidates
     *
     * @return list<array{numero: string, label: string, lat: float, lon: float}>
     */
    private function strictMatches(string $label, array $candidates): array
    {
        $needle = $this->labelNormalizer->normalize($label);

        return array_values(array_filter(
            $candidates,
            fn (array $salle): bool => $this->labelNormalizer->normalize($salle['label']) === $needle,
        ));
    }

    /**
     * Pose ou actualise le lien AUTO. Retourne true si le lien a été créé ou si son gymnase
     * a changé (un lien AUTO déjà pointé sur cette salle n'est pas réécrit — idempotent).
     *
     * @param array{numero: string, label: string, lat: float, lon: float} $salle
     */
    private function writeAutoLink(?OpponentVenueLink $existing, string $clubId, string $code, string $label, string $norm, array $salle): bool
    {
        $ref = mb_substr($salle['numero'], 0, 64);
        if ($existing instanceof OpponentVenueLink && $existing->getVenueExternalRef() === $ref) {
            return false; // déjà apparié à cette salle : rien à écrire
        }

        $link = $existing ?? (new OpponentVenueLink)
            ->setClubId($clubId)
            ->setOpponentOrganismeCode(mb_substr($code, 0, 64))
            ->setFbiLabel($label)
            ->setFbiLabelNorm($norm);
        $link->setVenueExternalRef($ref)
            ->setVenueLabel(mb_substr($salle['label'], 0, 180))
            ->setLatitude($salle['lat'])
            ->setLongitude($salle['lon'])
            ->setSource(OpponentVenueLinkSource::AUTO);
        if (!$existing instanceof OpponentVenueLink) {
            $this->entityManager->persist($link);
        }

        return true;
    }

    /**
     * Extrait une salle fédérale exploitable d'un hit `ffbbserver_salles` : numéro et
     * libellé non vides, coordonnées numériques (sans coordonnées, aucun lien routable).
     *
     * @param array<string, mixed> $hit
     *
     * @return array{numero: string, label: string, lat: float, lon: float}|null
     */
    private function salleFromHit(array $hit): ?array
    {
        $numero = isset($hit['numero']) && (\is_string($hit['numero']) || is_numeric($hit['numero'])) ? trim((string) $hit['numero']) : '';
        $label = \is_string($hit['libelle'] ?? null) ? trim($hit['libelle']) : '';
        $carto = \is_array($hit['cartographie'] ?? null) ? $hit['cartographie'] : [];
        $lat = $carto['latitude'] ?? null;
        $lon = $carto['longitude'] ?? null;
        if ('' === $numero || '' === $label || !is_numeric($lat) || !is_numeric($lon)) {
            return null;
        }

        return ['numero' => $numero, 'label' => $label, 'lat' => (float) $lat, 'lon' => (float) $lon];
    }
}

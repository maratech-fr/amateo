<?php

declare(strict_types=1);

namespace App\Service\Geo;

use App\Entity\Club;
use App\Entity\Fixture;
use App\Entity\OpponentDirectoryEntry;
use App\Entity\OpponentTravel;
use App\Enum\FixtureHomeAway;
use App\Enum\OpponentTravelSource;
use App\Repository\ClubRepository;
use App\Repository\FixtureRepository;
use App\Repository\OpponentDirectoryEntryRepository;
use App\Repository\OpponentTravelRepository;
use App\Service\Basketball\FfbbApiClient;
use App\Service\Basketball\VenueLabelNormalizer;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Throwable;

/**
 * P2-54 PR-2b — auto-localise un adversaire à partir du gymnase de salle ÉCRIT dans
 * le fichier FBI (`Fixture::getFbiVenueLabel()`) : pour chaque équipe adverse jouée à
 * l'extérieur, on cherche parmi les salles FÉDÉRALES de sa commune (index
 * `ffbbserver_salles`) celle dont le libellé égale STRICTEMENT le libellé du fichier,
 * et on pose une surcharge de trajet TENANT ({@see OpponentTravel}) au grain ÉQUIPE,
 * `source = AUTO`, avec le gymnase FÉDÉRAL (libellé, numéro, coordonnées) — jamais le
 * texte du fichier (patron {@see OpponentLocationResolver}, revue sécurité).
 *
 * ⚠ N'écrit QUE la table TENANT `opponent_travel` — JAMAIS le partagé
 * `opponent_venue_suggestion` : une localisation AUTOMATIQUE (devinée d'un libellé de
 * fichier saisi par le club) n'est pas un CHOIX et ne doit pas alimenter le compteur
 * communautaire (revue sécurité — « un compte, jamais un qui », données fédérales
 * seules au partagé). Une ligne TEAM AUTO ne compte donc jamais comme un choix.
 *
 * Sémantique par groupe AWAY `(code organisme, teamKey = libellé normalisé)` :
 *   - une ligne `opponent_travel` TEAM déjà **MANUAL** → laissée intacte (le choix du
 *     gestionnaire est souverain), comptée `skipped` ;
 *   - pas de ligne TEAM, ou une ligne TEAM **AUTO** existante → on (re)tente
 *     l'auto-localisation : libellé re-vérifié, minutes recalculées. Une ligne AUTO
 *     n'est mise à jour QUE si une salle unique est trouvée — un échec (0/≥2 hits,
 *     FFBB muet) laisse la valeur en place (best-effort, jamais destructeur).
 *
 * Résolution d'un groupe :
 *   - candidats = `searchSalles(CP de l'annuaire)` si l'annuaire porte un CP, sinon
 *     `searchSallesNearby(coords de l'annuaire, {@see SEARCH_RADIUS_METERS})`, sinon
 *     rien (pas d'ancre de recherche → non localisable) ;
 *   - **égalité STRICTE** `normalize(fbiVenueLabel) === normalize(salle.libelle)` ;
 *   - un groupe peut porter plusieurs libellés de fichier distincts : on tente chacun,
 *     0 ou ≥ 2 hits pour un libellé = ce libellé n'apporte rien (debug) ; la salle
 *     retenue est l'UNIQUE salle fédérale sur laquelle convergent les libellés qui ont
 *     un hit unique. Deux libellés → deux salles différentes = AMBIGU, rien n'est posé.
 *
 * Best-effort intégral : FFBB muet / réseau en panne → le groupe reste non localisé,
 * jamais une exception (les hooks d'import l'appellent en try/catch, mais le service
 * se protège lui-même en plus).
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

    /** Longueur de la colonne `opponent_travel.opponent_team_key` (VARCHAR(180)). */
    private const int TEAM_KEY_MAX_LENGTH = 180;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly FixtureRepository $fixtures,
        private readonly OpponentTravelRepository $travelRepository,
        private readonly OpponentDirectoryEntryRepository $directory,
        private readonly FfbbApiClient $apiClient,
        private readonly IgnRoutingClient $routingClient,
        private readonly VenueLabelNormalizer $labelNormalizer,
        private readonly ClubRepository $clubRepository,
        private readonly LoggerInterface $logger,
        private readonly ClockInterface $clock,
        // Cache-first (C4) : optionnel (défaut null) pour ne pas casser les sites de test ;
        // en prod, le conteneur l'autowire.
        private readonly ?TravelTimeCache $travelCache = null,
    ) {}

    /**
     * Auto-localise chaque équipe adverse AWAY du club+saison depuis le libellé de
     * salle de son fichier FBI. Best-effort.
     *
     * BCK-32 — `$deadline` (epoch flottant absolu, optionnel) : une fois franchi, les
     * groupes restants sont comptés `skipped` sans aucun appel réseau — même canal que
     * le cap {@see MAX_GROUPS}. Null (appel hors orchestrateur, hooks d'import) = aucune
     * borne de mur, inchangé.
     *
     * @return array{located: int, ambiguous: int, unmatched: int, skipped: int}
     */
    public function locate(string $clubId, string $seasonId, ?float $deadline = null): array
    {
        $club = $this->clubRepository->find($clubId);
        $clubLat = $club instanceof Club ? $club->getLatitude() : null;
        $clubLon = $club instanceof Club ? $club->getLongitude() : null;

        $groups = $this->groupAwayFixtures($seasonId);
        $existingTeamRows = $this->existingTeamRows($seasonId);

        // Un seul appel réseau salle par CODE organisme (sa commune ne bouge pas d'une
        // équipe à l'autre) : cache local par run. `false` = déjà tenté, aucune salle.
        /** @var array<string, list<array{numero: string, label: string, lat: float, lon: float}>|false> $candidateCache */
        $candidateCache = [];

        $located = 0;
        $ambiguous = 0;
        $unmatched = 0;
        $skipped = 0;
        $wrote = false;
        // Budget de fan-out fédéral : seuls les groupes qui DÉCLENCHENT du réseau (ni
        // MANUAL souverain, ni déjà sous la borne) le consomment.
        $processed = 0;
        $capNoted = false;
        $deadlineNoted = false;

        foreach ($groups as $group) {
            $existing = $existingTeamRows[$group['key']] ?? null;
            if ($existing instanceof OpponentTravel && OpponentTravelSource::MANUAL === $existing->getSource()) {
                // Un choix manuel du gestionnaire est souverain : jamais recalculé (aucun réseau).
                ++$skipped;

                continue;
            }

            // BCK-32 — budget de mur épuisé : les groupes restants sont sautés SANS
            // réseau, même canal que le cap ci-dessous (best-effort, relance pour finir).
            if (null !== $deadline && (float) $this->clock->now()->format('U.u') >= $deadline) {
                if (!$deadlineNoted) {
                    $this->logger->warning('Opponent venue auto-locate: wall-clock budget spent, remaining opponents skipped');
                    $deadlineNoted = true;
                }
                ++$skipped;

                continue;
            }

            if ($processed >= self::MAX_GROUPS) {
                // Cap atteint : les groupes en excès sont sautés SANS aucun appel réseau.
                if (!$capNoted) {
                    $this->logger->warning('Opponent venue auto-locate: group cap reached, remaining opponents skipped', ['cap' => self::MAX_GROUPS, 'groups' => \count($groups)]);
                    $capNoted = true;
                }
                ++$skipped;

                continue;
            }
            ++$processed;

            $candidates = $this->candidates($group['code'], $candidateCache);
            if ([] === $candidates) {
                ++$unmatched;

                continue;
            }

            $salle = $this->uniqueFederalSalle($group['code'], $group['labels'], $candidates, $ambiguous, $unmatched);
            if (null === $salle) {
                continue; // le compteur ambiguous/unmatched a déjà été incrémenté
            }

            $this->writeAutoTeamRow($existing, $clubId, $seasonId, $group['code'], $group['teamKey'], $salle, $clubLat, $clubLon);
            ++$located;
            $wrote = true;
        }

        if ($wrote) {
            $this->entityManager->flush();
        }

        return ['located' => $located, 'ambiguous' => $ambiguous, 'unmatched' => $unmatched, 'skipped' => $skipped];
    }

    /**
     * Les groupes AWAY `(code, teamKey)` de la saison porteurs d'un libellé de salle
     * FBI ET d'un code organisme résolu — chacun avec ses libellés de fichier
     * DISTINCTS (dédupliqués par forme normalisée, ordre d'apparition conservé, libellé
     * BRUT gardé pour la comparaison stricte).
     *
     * @return list<array{key: string, code: string, teamKey: string, labels: list<string>}>
     */
    private function groupAwayFixtures(string $seasonId): array
    {
        /** @var array<string, array{key: string, code: string, teamKey: string, labels: list<string>, seen: array<string, true>}> $groups */
        $groups = [];
        foreach ($this->fixtures->findAwayBySeason($seasonId) as $fixture) {
            if (FixtureHomeAway::AWAY !== $fixture->getHomeAway()) {
                continue;
            }
            $code = $fixture->getOpponentOrganismeCode();
            $fbiLabel = $fixture->getFbiVenueLabel();
            if (null === $code || '' === $code || null === $fbiLabel || '' === trim($fbiLabel)) {
                continue; // sans code fédéral OU sans salle de fichier : rien à localiser
            }
            $teamKey = $this->labelNormalizer->normalize(trim($fixture->getOpponentLabel()));
            if ('' === $teamKey) {
                continue;
            }
            // Colonne VARCHAR(180) : on tronque (comme le fait `cleanTeamKey` du contrôleur
            // travel) — sinon un libellé très long lèverait une erreur DB avalée par le
            // best-effort, qui masquerait la ligne. La clé de groupe ET la clé de recherche
            // des lignes existantes restent alors cohérentes (elles sont déjà ≤ 180 en base).
            $teamKey = mb_substr($teamKey, 0, self::TEAM_KEY_MAX_LENGTH);
            $normalizedFbi = $this->labelNormalizer->normalize(trim($fbiLabel));
            if ('' === $normalizedFbi) {
                continue;
            }
            $key = $code . '|' . $teamKey;
            $groups[$key] ??= ['key' => $key, 'code' => $code, 'teamKey' => $teamKey, 'labels' => [], 'seen' => []];
            if (!isset($groups[$key]['seen'][$normalizedFbi])) {
                $groups[$key]['seen'][$normalizedFbi] = true;
                $groups[$key]['labels'][] = trim($fbiLabel);
            }
        }

        return array_values(array_map(
            static fn (array $g): array => ['key' => $g['key'], 'code' => $g['code'], 'teamKey' => $g['teamKey'], 'labels' => $g['labels']],
            $groups,
        ));
    }

    /**
     * Les lignes `opponent_travel` de grain ÉQUIPE (teamKey non nul) du club+saison,
     * indexées `code|teamKey` — le grain que ce service crée ou re-localise.
     *
     * @return array<string, OpponentTravel>
     */
    private function existingTeamRows(string $seasonId): array
    {
        $map = [];
        foreach ($this->travelRepository->findBySeason($seasonId) as $row) {
            $teamKey = $row->getOpponentTeamKey();
            if (null !== $teamKey) {
                $map[$row->getOpponentOrganismeCode() . '|' . $teamKey] = $row;
            }
        }

        return $map;
    }

    /**
     * Les salles fédérales candidates pour un code organisme : par le CP de l'annuaire
     * si connu, sinon par un rayon autour de ses coordonnées, sinon aucune. Cache par
     * run (un seul appel réseau par code). Best-effort : FFBB muet → [] (mémorisé).
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
     * L'UNIQUE salle fédérale sur laquelle convergent les libellés de fichier du groupe
     * (égalité stricte normalisée). null si aucun libellé n'a de hit unique (unmatched),
     * ou si deux libellés désignent deux salles différentes (ambiguous) — le compteur
     * correspondant est incrémenté par référence.
     *
     * @param list<string>                                                       $labels
     * @param list<array{numero: string, label: string, lat: float, lon: float}> $candidates
     *
     * @return array{numero: string, label: string, lat: float, lon: float}|null
     */
    private function uniqueFederalSalle(string $code, array $labels, array $candidates, int &$ambiguous, int &$unmatched): ?array
    {
        /** @var array<string, array{numero: string, label: string, lat: float, lon: float}> $foundByNumero */
        $foundByNumero = [];
        foreach ($labels as $label) {
            $needle = $this->labelNormalizer->normalize($label);
            $matches = array_values(array_filter(
                $candidates,
                fn (array $salle): bool => $this->labelNormalizer->normalize($salle['label']) === $needle,
            ));
            if (1 !== \count($matches)) {
                // 0 ou ≥ 2 hits pour ce libellé : il n'apporte rien (journalisé).
                $this->logger->debug('Opponent venue auto-locate: label not uniquely matched', ['code' => $code, 'label' => $label, 'hits' => \count($matches)]);

                continue;
            }
            $foundByNumero[$matches[0]['numero']] = $matches[0];
        }

        if ([] === $foundByNumero) {
            ++$unmatched;

            return null;
        }
        if (\count($foundByNumero) > 1) {
            // Deux libellés convergent vers deux salles fédérales différentes : ambigu.
            $this->logger->debug('Opponent venue auto-locate: ambiguous federal salles', ['code' => $code, 'numeros' => array_keys($foundByNumero)]);
            ++$ambiguous;

            return null;
        }

        return array_values($foundByNumero)[0];
    }

    /** Car minutes club siège → point, cache-first (C4) : le cache avant le réseau, mémorise le neuf. */
    private function carMinutesCached(string $clubId, float $clubLat, float $clubLon, float $destLat, float $destLon): ?int
    {
        $cached = $this->travelCache?->lookup($clubId, IgnRoutingClient::PROFILE_CAR, $clubLat, $clubLon, $destLat, $destLon);
        if (null !== $cached) {
            return $cached;
        }
        $minutes = $this->routingClient->travelMinutes(IgnRoutingClient::PROFILE_CAR, $clubLat, $clubLon, $destLat, $destLon);
        if (null !== $minutes) {
            $this->travelCache?->store($clubId, IgnRoutingClient::PROFILE_CAR, $clubLat, $clubLon, $destLat, $destLon, $minutes);
        }

        return $minutes;
    }

    /**
     * @param array{numero: string, label: string, lat: float, lon: float} $salle
     */
    private function writeAutoTeamRow(?OpponentTravel $existing, string $clubId, string $seasonId, string $code, string $teamKey, array $salle, ?float $clubLat, ?float $clubLon): void
    {
        $minutes = null === $clubLat || null === $clubLon
            ? null
            : $this->carMinutesCached($clubId, $clubLat, $clubLon, $salle['lat'], $salle['lon']);

        $row = $existing ?? (new OpponentTravel)
            ->setClubId($clubId)
            ->setSeasonId($seasonId)
            ->setOpponentOrganismeCode($code)
            ->setOpponentTeamKey($teamKey);
        $row->setOverrideVenueExternalRef(mb_substr($salle['numero'], 0, 64))
            ->setOverrideVenueLabel(mb_substr($salle['label'], 0, 180))
            ->setOverrideLatitude($salle['lat'])
            ->setOverrideLongitude($salle['lon'])
            ->setTravelMinutes($minutes)
            ->setSource(OpponentTravelSource::AUTO)
            ->setResolvedAt(new DateTimeImmutable);
        if (!$existing instanceof OpponentTravel) {
            $this->entityManager->persist($row);
        }
    }

    /**
     * Extrait une salle fédérale exploitable d'un hit `ffbbserver_salles` : numéro et
     * libellé non vides, coordonnées numériques (sans coordonnées, aucun trajet ni
     * surcharge de lieu possible).
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

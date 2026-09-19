<?php

declare(strict_types=1);

namespace App\Service\Geo;

use App\Entity\Club;
use App\Entity\OpponentDirectoryEntry;
use App\Entity\OpponentTravel;
use App\Enum\OpponentTravelSource;
use App\Repository\ClubRepository;
use App\Repository\FixtureRepository;
use App\Repository\OpponentDirectoryEntryRepository;
use App\Repository\OpponentTravelRepository;
use App\Repository\OpponentVenueSuggestionRepository;
use App\Service\Basketball\FfbbSalleResolver;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * P2-54 RMM-9 PR-3 — computes the AUTO car travel time from a club's siège to
 * each of its AWAY opponents' venues, and upserts it into the tenant
 * `opponent_travel`. Patron {@see VenueTravelTimeAutofillService} (best-effort,
 * MANUAL never overwritten by AUTO).
 *
 * The location of an opponent is:
 *   - the MANUAL override on its `opponent_travel` row when the manager pinned a
 *     gym — but a MANUAL row is NEVER recomputed here (its travel was fixed when
 *     the manager set it) ;
 *   - otherwise the opponent's entry in the GLOBAL `opponent_directory` (public
 *     federal coordinates).
 *
 * Grain (P2-54 « adversaire multi-gymnases » PR-1) : {@see resolve()} — la passe AUTO
 * des TRAJETS — ne touche QUE les lignes CLUB (le défaut, `opponentTeamKey` NULL) ;
 * comportement historique inchangé. Une ligne ÉQUIPE peut naître de DEUX sources :
 *   - un choix manuel du gestionnaire ({@see applyManualOverride} portée ÉQUIPE), ou
 *   - l'auto-localisation du gymnase depuis le libellé du fichier FBI (PR-2b,
 *     {@see OpponentVenueAutoLocator}) — `source = AUTO`, portant un ref de salle
 *     fédéral mais ne comptant JAMAIS comme un choix au partagé.
 * « Rétablir l'automatique » sur une équipe = SUPPRIMER la ligne ({@see deleteTeamOverride},
 * décision A3) pour retomber sur la ligne club puis l'annuaire ; une ligne équipe AUTO
 * remplacée par un choix manuel devient MANUAL (+1 au partagé, sans −1). Le décrément
 * du compteur partagé ne joue donc QUE quand la ligne remplacée/supprimée était MANUAL.
 *
 * Best-effort intégral : IGN en panne → `travelMinutes` null (jamais une erreur
 * bloquante) ; un adversaire sans lieu connu (ni override ni directory géolocalisé)
 * est laissé « non localisé », sans ligne AUTO.
 */
final class OpponentTravelResolver
{
    public const int MAX_OPPONENTS = 60;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly IgnRoutingClient $routingClient,
        private readonly OpponentTravelRepository $travelRepository,
        private readonly OpponentDirectoryEntryRepository $directory,
        private readonly OpponentVenueSuggestionRepository $suggestions,
        private readonly FfbbSalleResolver $salleResolver,
        private readonly ClubRepository $clubRepository,
        private readonly FixtureRepository $fixtures,
        private readonly LoggerInterface $logger,
        // Cache-first (C4) : optionnel (défaut null) pour ne pas casser les sites de test
        // qui n'instancient pas le cache ; en prod, le conteneur l'autowire.
        private readonly ?TravelTimeCache $travelCache = null,
    ) {}

    /**
     * The DISTINCT opponent organisme codes stamped on the season's AWAY fixtures
     * — the work set. Exposed so the catch-up route can enforce its hard cap
     * BEFORE any network call.
     *
     * @return list<string>
     */
    public function distinctOpponentCodes(string $seasonId): array
    {
        $codes = [];
        foreach ($this->fixtures->findAwayBySeason($seasonId) as $fixture) {
            $code = $fixture->getOpponentOrganismeCode();
            if (null !== $code && '' !== $code) {
                $codes[$code] = true;
            }
        }

        return array_keys($codes);
    }

    /**
     * Resolve the AUTO travel for every AWAY opponent of the club+season. A MANUAL
     * row is left untouched. Best-effort per opponent.
     *
     * BCK-32 — deux bornes contre une passe qui traînerait : (1) un cap dur de
     * {@see MAX_OPPONENTS} codes géolocalisés à router (l'excès part en `unresolved`
     * SANS aucun appel réseau) ; (2) un budget de mur `$budgetSeconds` threadé vers
     * {@see IgnRoutingClient::travelMinutesBatch} (les codes non tentés reviennent en
     * `unresolved`). `$budgetSeconds` null (route dédiée `/travel/resolve`) = budget de
     * lot par défaut, comportement inchangé.
     *
     * `$onProgress`, si fourni, est appelé avec (cibles traitées, total) au fil du lot
     * (C6 — le worker asynchrone publie l'avancement).
     *
     * @param (callable(int, int): void)|null $onProgress
     *
     * @return array{resolved: int, unresolved: list<string>, skippedManual: int}
     */
    public function resolve(string $clubId, string $seasonId, ?float $budgetSeconds = null, ?callable $onProgress = null): array
    {
        $club = $this->clubRepository->find($clubId);
        $clubLat = $club instanceof Club ? $club->getLatitude() : null;
        $clubLon = $club instanceof Club ? $club->getLongitude() : null;

        $codes = $this->distinctOpponentCodes($seasonId);
        $existing = $this->existingByCode($seasonId);

        $unresolved = [];
        $skippedManual = 0;
        /** @var list<array{key: string, code: string, teamKey: string|null, destLat: float, destLon: float, row: OpponentTravel|null}> $targets */
        $targets = [];

        // ── Passe CLUB : un code par ligne club (défaut, `opponentTeamKey` NULL) ──────
        foreach ($codes as $code) {
            $row = $existing[$code] ?? null;
            if (null !== $row && OpponentTravelSource::MANUAL === $row->getSource()) {
                // Une ligne MANUAL est normalement laissée intacte par la passe AUTO.
                // Exception : une ligne MANUAL dont le trajet n'a JAMAIS pu être calculé
                // (IGN muet au moment du choix) mais dont l'override porte des coordonnées
                // est RE-ROUTÉE — seul le trajet changera, le gymnase épinglé reste souverain.
                $overrideLat = $row->getOverrideLatitude();
                $overrideLon = $row->getOverrideLongitude();
                if (null === $row->getTravelMinutes() && null !== $overrideLat && null !== $overrideLon) {
                    $targets[] = ['key' => $code, 'code' => $code, 'teamKey' => null, 'destLat' => $overrideLat, 'destLon' => $overrideLon, 'row' => $row];

                    continue;
                }
                ++$skippedManual;

                continue;
            }

            // C5 — un trajet est une CONSTANTE : une ligne AUTO qui porte DÉJÀ un trajet n'est
            // JAMAIS re-routée (fini le « reroute TOUT » qui pouvait l'écraser). Seuls les
            // MANQUES partent : un code sans ligne, ou une ligne AUTO au trajet null.
            if (null !== $row && null !== $row->getTravelMinutes()) {
                continue;
            }

            $location = $this->directoryLocation($code);
            if (null === $location) {
                $unresolved[] = $code;

                continue;
            }
            $targets[] = ['key' => $code, 'code' => $code, 'teamKey' => null, 'destLat' => $location[0], 'destLon' => $location[1], 'row' => $row];
        }

        // ── C5-bis — Passe ÉQUIPE : une ligne ÉQUIPE (`opponentTeamKey` non NULL) AUTO au
        // trajet null MAIS portant des coordonnées d'override (posée par l'auto-localisateur
        // pendant un IGN dégradé) est enfin ROUTÉE. On n'écrira QUE le trajet — le gymnase
        // épinglé et la source AUTO restent souverains (jamais l'écrasement du bloc override
        // de la passe club).
        foreach ($this->travelRepository->findBySeason($seasonId) as $teamRow) {
            $teamKey = $teamRow->getOpponentTeamKey();
            if (null === $teamKey || OpponentTravelSource::AUTO !== $teamRow->getSource() || null !== $teamRow->getTravelMinutes()) {
                continue;
            }
            $overrideLat = $teamRow->getOverrideLatitude();
            $overrideLon = $teamRow->getOverrideLongitude();
            if (null === $overrideLat || null === $overrideLon) {
                continue; // pas de lieu à router (une ligne équipe naît d'un override)
            }
            $code = $teamRow->getOpponentOrganismeCode();
            $targets[] = ['key' => $code . '|team|' . $teamKey, 'code' => $code, 'teamKey' => $teamKey, 'destLat' => $overrideLat, 'destLon' => $overrideLon, 'row' => $teamRow];
        }

        // BCK-32 — cap dur : au-delà de MAX_OPPONENTS cibles à router (club + équipe),
        // l'excès part en `unresolved` SANS aucun appel réseau (le rattrapage se fera à
        // la prochaine passe). La borne était jusqu'ici tenue par la seule route dédiée ;
        // l'orchestrateur /refresh l'atteint désormais aussi.
        if (\count($targets) > self::MAX_OPPONENTS) {
            foreach (\array_slice($targets, self::MAX_OPPONENTS) as $excess) {
                $unresolved[] = $excess['code'];
            }
            $targets = \array_slice($targets, 0, self::MAX_OPPONENTS);
        }

        // No usable origin → nothing computable, every geolocated opponent is
        // unresolved (best-effort, no exception).
        if (null === $clubLat || null === $clubLon) {
            foreach ($targets as $target) {
                $unresolved[] = $target['code'];
            }

            return ['resolved' => 0, 'unresolved' => $unresolved, 'skippedManual' => $skippedManual];
        }

        // Cache-first (C4) : un trajet est une CONSTANTE. On regarde le cache club avant le
        // réseau ; seules les cibles en MANQUE partent dans UN lot IGN (club + équipe partagent
        // le MÊME budget de mur, sinon deux lots séquentiels doubleraient le temps mural).
        $clubLatF = (float) $clubLat;
        $clubLonF = (float) $clubLon;
        $targetByKey = [];
        $cachedMinutes = [];
        $jobs = [];
        foreach ($targets as $target) {
            $targetByKey[$target['key']] = $target;
            $cached = $this->travelCache?->lookup($clubId, IgnRoutingClient::PROFILE_CAR, $clubLatF, $clubLonF, $target['destLat'], $target['destLon']);
            if (null !== $cached) {
                $cachedMinutes[$target['key']] = $cached;

                continue;
            }
            $jobs[] = [
                'key' => $target['key'],
                'profile' => IgnRoutingClient::PROFILE_CAR,
                'startLat' => $clubLatF,
                'startLon' => $clubLonF,
                'endLat' => $target['destLat'],
                'endLon' => $target['destLon'],
            ];
        }

        // C6 — progression : les hits cache sont déjà « faits » (offset), le lot IGN ajoute
        // ses jobs traités. Le total est l'ensemble des cibles (cache + réseau).
        $totalTargets = \count($targets);
        $cacheHitCount = \count($cachedMinutes);
        if (null !== $onProgress && $totalTargets > 0) {
            $onProgress($cacheHitCount, $totalTargets);
        }

        $batch = $this->routingClient->travelMinutesBatch(
            $jobs,
            // C6 — le budget de mur est celui que l'appelant passe (null ⇒ défaut du lot IGN,
            // 30 s). Depuis C6 `resolve()` est joué par le WORKER (rail async, pas de plafond
            // HTTP) qui passe son budget large ; l'ancien clamp à BATCH_BUDGET_SECONDS, qui
            // protégeait le plafond synchrone, n'a plus lieu d'être.
            budgetSeconds: $budgetSeconds,
            onProgress: null === $onProgress ? null : static function (int $done) use ($onProgress, $cacheHitCount, $totalTargets): void {
                $onProgress($cacheHitCount + $done, $totalTargets);
            },
        );
        // Un hit cache est résolu (jamais budget_exceeded) ; on fusionne avec les résultats IGN.
        $minutes = $cachedMinutes + $batch['minutes'];
        $budgetExceeded = array_fill_keys($batch['budgetExceededKeys'], true);

        // Mémorise les trajets FRAÎCHEMENT calculés (jamais un null, jamais un hit cache).
        foreach ($batch['minutes'] as $key => $freshMinutes) {
            if (null !== $freshMinutes && isset($targetByKey[$key])) {
                $this->travelCache?->store($clubId, IgnRoutingClient::PROFILE_CAR, $clubLatF, $clubLonF, $targetByKey[$key]['destLat'], $targetByKey[$key]['destLon'], $freshMinutes);
            }
        }

        $resolved = 0;
        $wrote = false;
        foreach ($targets as $target) {
            $key = $target['key'];
            // The budget stopped BEFORE this target was even tried: NOT the same as an
            // IGN-mute answer. Leave the row untouched — no write, no creation — so a
            // good value already in base survives, and a re-run resolves it.
            if (isset($budgetExceeded[$key])) {
                $unresolved[] = $target['code'];

                continue;
            }
            $value = $minutes[$key] ?? null;
            if (null === $value) {
                // C5 — IGN muet : on ne fabrique ni n'altère RIEN. Jamais `setTravelMinutes(null)`
                // (une valeur ne se perd pas), jamais une ligne vide créée. La cible reste « non
                // résolue » et repartira au prochain passage.
                $unresolved[] = $target['code'];

                continue;
            }
            $existingRow = $target['row'];
            $row = $existingRow ?? $this->newRow($clubId, $seasonId, $target['code']);
            if (null !== $target['teamKey'] || OpponentTravelSource::MANUAL === $row->getSource()) {
                // Ligne ÉQUIPE (override souverain, C5-bis) OU ligne club MANUAL re-routée :
                // on ne touche QUE le trajet (et resolvedAt). Le bloc override (gymnase épinglé)
                // et la source restent intacts.
                $row->setTravelMinutes($value)
                    ->setResolvedAt(new DateTimeImmutable);
            } else {
                // AUTO club row: the location is the global directory's, no manual override.
                $row->setTravelMinutes($value)
                    ->setSource(OpponentTravelSource::AUTO)
                    ->setOverrideVenueExternalRef(null)
                    ->setOverrideVenueLabel(null)
                    ->setOverrideLatitude(null)
                    ->setOverrideLongitude(null)
                    ->setResolvedAt(new DateTimeImmutable);
            }
            if (null === $existingRow) {
                $this->entityManager->persist($row);
            }
            $wrote = true;
            ++$resolved;
        }

        if ($wrote) {
            $this->entityManager->flush();
        }

        return ['resolved' => $resolved, 'unresolved' => $unresolved, 'skippedManual' => $skippedManual];
    }

    /**
     * The manager pins a specific gym for an opponent (MANUAL override). The
     * travel is recomputed from that gym; a MANUAL row is never touched by the
     * AUTO pass afterwards. Best-effort: IGN muet → `travelMinutes` null.
     *
     * `$teamKey` null → the CLUB row (the default for every rencontre of this code) ;
     * `$teamKey` renseigné → the override of that single opponent TEAM. La portée CLUB
     * ne remplit QUE la ligne club : les équipes sans ligne propre héritent du club via
     * la résolution team → club → annuaire, donc il n'y a jamais de « ligne équipe vide »
     * à remplir (elle n'existe que si un manuel l'a créée, auquel cas c'est un MANUAL
     * qu'on n'écrase pas). Équivalence confirmée : la portée CLUB = la seule ligne club.
     */
    public function applyManualOverride(string $clubId, string $seasonId, string $code, ?string $teamKey, ?string $venueRef, string $venueLabel, float $lat, float $lon): OpponentTravel
    {
        $minutes = $this->carMinutesFromClub($clubId, $lat, $lon);
        $existing = $this->travelRepository->findOneByCode($seasonId, $code, $teamKey);
        // Le ref que la ligne portait AVANT ce choix (null si nouvelle) — pour le
        // comptage partagé (changement de choix = −1 ancien +1 nouveau). Le −1 ne vaut
        // QUE si l'ancienne ligne était MANUAL : une ligne AUTO (grain équipe posé par
        // {@see OpponentVenueAutoLocator}) porte un ref fédéral mais n'a JAMAIS compté
        // comme un choix — la décrémenter volerait le compte d'un autre club (P4-209(a)).
        $previousRef = $existing?->getOverrideVenueExternalRef();
        $previousWasManual = $existing instanceof OpponentTravel && OpponentTravelSource::MANUAL === $existing->getSource();
        $row = $existing ?? $this->newRow($clubId, $seasonId, $code, $teamKey);
        $row->setOverrideVenueExternalRef($venueRef)
            ->setOverrideVenueLabel($venueLabel)
            ->setOverrideLatitude($lat)
            ->setOverrideLongitude($lon)
            ->setTravelMinutes($minutes)
            ->setSource(OpponentTravelSource::MANUAL)
            ->setResolvedAt(new DateTimeImmutable);
        $this->accountManualChoice($code, $previousWasManual ? $previousRef : null, $venueRef, $lat, $lon);
        $this->entityManager->persist($row);
        $this->entityManager->flush();

        return $row;
    }

    /**
     * Return the CLUB row of an opponent to AUTO: drop the manual override and
     * recompute the travel from the GLOBAL directory location. Null when there is
     * no club row to revert (nothing was overridden). A TEAM override is reverted
     * by DELETING it ({@see deleteTeamOverride}, décision A3), not by this method.
     */
    public function revertToAuto(string $clubId, string $seasonId, string $code): ?OpponentTravel
    {
        $row = $this->travelRepository->findOneByCode($seasonId, $code, null);
        if (!$row instanceof OpponentTravel) {
            return null;
        }
        $previousRef = $row->getOverrideVenueExternalRef();
        $wasManual = OpponentTravelSource::MANUAL === $row->getSource();
        $location = $this->directoryLocation($code);
        $minutes = null === $location ? null : $this->carMinutesFromClub($clubId, $location[0], $location[1]);
        $row->setOverrideVenueExternalRef(null)
            ->setOverrideVenueLabel(null)
            ->setOverrideLatitude(null)
            ->setOverrideLongitude(null)
            ->setTravelMinutes($minutes)
            ->setSource(OpponentTravelSource::AUTO)
            ->setResolvedAt(new DateTimeImmutable);
        // Ce club ne choisit plus ce gymnase : la suggestion partagée recule — SEULEMENT
        // si la ligne club était MANUAL (une ligne AUTO n'a jamais compté). Idempotent,
        // ref effectif seul (une ligne AUTO club ne porte de toute façon pas de ref).
        if ($wasManual && null !== $previousRef) {
            $this->suggestions->decrement($code, $previousRef);
        }
        $this->entityManager->flush();

        return $row;
    }

    /**
     * Rétablir l'AUTO d'une ligne ÉQUIPE = la SUPPRIMER (décision A3) : la rencontre
     * retombe alors sur la ligne club puis l'annuaire. True si une ligne a été
     * supprimée, false s'il n'y en avait aucune (rien à rétablir).
     */
    public function deleteTeamOverride(string $seasonId, string $code, string $teamKey): bool
    {
        $row = $this->travelRepository->findOneByCode($seasonId, $code, $teamKey);
        if (!$row instanceof OpponentTravel) {
            return false;
        }
        $previousRef = $row->getOverrideVenueExternalRef();
        $wasManual = OpponentTravelSource::MANUAL === $row->getSource();
        $this->entityManager->remove($row);
        // Ce club ne choisit plus ce gymnase pour cette équipe : la suggestion recule —
        // MAIS uniquement si la ligne supprimée était MANUAL. Une ligne AUTO (posée par
        // {@see OpponentVenueAutoLocator} depuis le libellé du fichier) porte un ref
        // fédéral sans avoir jamais incrémenté le partagé : la décrémenter volerait le
        // compte d'un autre club (P4-209(a)). Décrément idempotent, ref effectif seul.
        if ($wasManual && null !== $previousRef) {
            $this->suggestions->decrement($code, $previousRef);
        }
        $this->entityManager->flush();

        return true;
    }

    /**
     * Comptabilise un choix manuel dans les suggestions PARTAGÉES de gymnases de
     * l'adversaire (« un compte, jamais un qui »). Changement de choix = −1 sur
     * l'ancien ref, +1 sur le nouveau ; re-choisir le même = neutre. `$previousRef`
     * n'est transmis QUE lorsque la ligne remplacée était MANUAL : remplacer une ligne
     * AUTO (grain équipe, {@see OpponentVenueAutoLocator}) par un choix manuel = +1 sur
     * le nouveau, JAMAIS de −1 (l'AUTO n'avait jamais compté — P4-209(a)).
     *
     * 🔴 SÉCURITÉ (revue 2026-09-15) : le partagé ne reçoit QUE des données FÉDÉRALES.
     * Le numéro de salle du corps est RE-RÉSOLU côté serveur contre l'index FFBB
     * ({@see FfbbSalleResolver}, les coordonnées du corps ne sont qu'une graine de
     * recherche) : le libellé/ville/CP/coordonnées écrits viennent du HIT fédéral,
     * jamais du texte du client. Si le numéro ne résout pas (inconnu) ou si FFBB est
     * muet : le choix reste TENANT seul, AUCUNE écriture ni incrément dans le partagé.
     */
    private function accountManualChoice(string $code, ?string $previousRef, ?string $newRef, float $lat, float $lon): void
    {
        if ($previousRef === $newRef) {
            return; // re-choisir exactement le même gymnase : rien ne bouge
        }
        if (null !== $previousRef) {
            $this->suggestions->decrement($code, $previousRef);
        }
        if (null === $newRef) {
            return; // un gymnase sans référence fédérale ne partage rien (tenant seul)
        }

        $federal = $this->salleResolver->resolveByExternalRef($newRef, $lat, $lon);
        if (null === $federal) {
            // Référence inconnue ou FFBB muet → le choix reste tenant, rien au partagé.
            $this->logger->warning('Opponent venue suggestion: federal salle unresolved, shared feed skipped', ['ref' => $newRef]);

            return;
        }
        $this->suggestions->upsertManual($code, $newRef, $federal['label'], $federal['city'], $federal['postalCode'], $federal['latitude'], $federal['longitude']);
        $this->suggestions->increment($code, $newRef);
    }

    /** Car minutes from the club siège to a point, or null (no club geo / IGN muet). Cache-first (C4). */
    private function carMinutesFromClub(string $clubId, float $lat, float $lon): ?int
    {
        $club = $this->clubRepository->find($clubId);
        if (!$club instanceof Club || null === $club->getLatitude() || null === $club->getLongitude()) {
            return null;
        }
        $clubLat = (float) $club->getLatitude();
        $clubLon = (float) $club->getLongitude();

        $cached = $this->travelCache?->lookup($clubId, IgnRoutingClient::PROFILE_CAR, $clubLat, $clubLon, $lat, $lon);
        if (null !== $cached) {
            return $cached;
        }
        $minutes = $this->routingClient->travelMinutes(IgnRoutingClient::PROFILE_CAR, $clubLat, $clubLon, $lat, $lon);
        if (null !== $minutes) {
            $this->travelCache?->store($clubId, IgnRoutingClient::PROFILE_CAR, $clubLat, $clubLon, $lat, $lon, $minutes);
        }

        return $minutes;
    }

    /**
     * The opponent's location from the GLOBAL directory (coordinates), or null
     * when unknown / not geolocated.
     *
     * @return array{0: float, 1: float}|null [latitude, longitude]
     */
    private function directoryLocation(string $code): ?array
    {
        $entry = $this->directory->findOneByFfbbOrganismeCode($code);
        if (!$entry instanceof OpponentDirectoryEntry) {
            return null;
        }
        $lat = $entry->getLatitude();
        $lon = $entry->getLongitude();

        return null === $lat || null === $lon ? null : [$lat, $lon];
    }

    /**
     * The CLUB rows (opponentTeamKey NULL) keyed by opponent organisme code — the
     * travel AUTO pass only ever recomputes the club default. Team overrides (MANUAL,
     * or AUTO posé par {@see OpponentVenueAutoLocator}) are left entirely untouched
     * here — the venue auto-locator owns the team grain.
     *
     * @return array<string, OpponentTravel> keyed by opponent organisme code
     */
    private function existingByCode(string $seasonId): array
    {
        $map = [];
        foreach ($this->travelRepository->findBySeason($seasonId) as $row) {
            if (null === $row->getOpponentTeamKey()) {
                $map[$row->getOpponentOrganismeCode()] = $row;
            }
        }

        return $map;
    }

    private function newRow(string $clubId, string $seasonId, string $code, ?string $teamKey = null): OpponentTravel
    {
        return (new OpponentTravel)
            ->setClubId($clubId)
            ->setSeasonId($seasonId)
            ->setOpponentOrganismeCode($code)
            ->setOpponentTeamKey($teamKey);
    }
}

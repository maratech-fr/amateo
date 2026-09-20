<?php

declare(strict_types=1);

namespace App\Service\Geo;

use App\Entity\Club;
use App\Entity\OpponentDirectoryEntry;
use App\Entity\OpponentVenueLink;
use App\Repository\ClubRepository;
use App\Repository\FixtureRepository;
use App\Repository\OpponentDirectoryEntryRepository;
use App\Repository\OpponentVenueLinkRepository;
use App\Repository\OpponentVenueSuggestionRepository;
use App\Service\Basketball\FfbbSalleResolver;
use Psr\Log\LoggerInterface;

/**
 * P2-54 — calcule le temps de trajet VOITURE du siège du club vers le gymnase de chaque
 * adversaire apparié ({@see OpponentVenueLink}) et le range dans le cache CONSTANT
 * {@see ClubTravelCache} (via {@see TravelTimeCache}). Amendement 2026-09-20 : le trajet
 * n'appartient plus à une ligne season+team (`opponent_travel` a disparu) — c'est une
 * fonction du siège du club et des coordonnées d'un gymnase, une CONSTANTE mise en cache,
 * jamais recalculée. La cible de {@see resolve()} n'est donc plus « les lignes » mais
 * « les PAIRES siège→gymnase manquantes du cache ».
 *
 * Ce service porte aussi la comptabilité du compteur PARTAGÉ de gymnases d'un adversaire
 * ({@see OpponentVenueSuggestion}, « un COMPTE, jamais un QUI ») : {@see accountManualChoice}
 * est appelé par les gestes d'appariement MANUAL du contrôleur (ajout / ré-appariement /
 * retrait d'un lien) — un lien AUTO ne compte jamais.
 *
 * Best-effort intégral : IGN en panne / siège non localisé → aucune écriture au cache
 * (jamais une valeur fausse, jamais une exception bloquante).
 */
final class OpponentTravelResolver
{
    /** Cap dur de paires siège→gymnase routées par passe (BCK-32). */
    public const int MAX_OPPONENTS = 60;

    public function __construct(
        private readonly IgnRoutingClient $routingClient,
        private readonly OpponentVenueLinkRepository $linkRepository,
        private readonly OpponentDirectoryEntryRepository $directory,
        private readonly OpponentVenueSuggestionRepository $suggestions,
        private readonly FfbbSalleResolver $salleResolver,
        private readonly ClubRepository $clubRepository,
        private readonly FixtureRepository $fixtures,
        private readonly TravelTimeCache $travelCache,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Les codes organisme DISTINCTS estampillés sur les fixtures AWAY de la saison — le
     * périmètre pertinent (les adversaires réellement joués cette saison). Exposé pour
     * que le contrôleur borne son travail avant tout réseau.
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
     * Calcule et met en cache le trajet siège→gymnase de chaque paire MANQUANTE — les
     * gymnases des adversaires joués cette saison ({@see OpponentVenueLink}) dont le trajet
     * n'est pas déjà dans {@see ClubTravelCache}. Une paire déjà en cache n'est jamais
     * retouchée (constante). Best-effort par paire.
     *
     * BCK-32 — deux bornes : (1) cap dur {@see MAX_OPPONENTS} de paires routées (l'excès
     * part en `unresolved` SANS réseau) ; (2) budget de mur `$budgetSeconds` threadé vers
     * {@see IgnRoutingClient::travelMinutesBatch}. `$budgetSeconds` null = budget du lot par
     * défaut. `$onProgress(traitées, total)` est appelé au fil du lot (C6 — le worker publie).
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

        $pairs = $this->pairsToRoute($clubId, $seasonId);
        $unresolved = [];

        // Sans siège localisé, rien n'est calculable : toutes les paires restent non
        // résolues (best-effort, jamais une erreur).
        if (null === $clubLat || null === $clubLon) {
            foreach ($pairs as $pair) {
                $unresolved[] = $pair['code'];
            }

            return ['resolved' => 0, 'unresolved' => $unresolved, 'skippedManual' => 0];
        }
        $clubLatF = (float) $clubLat;
        $clubLonF = (float) $clubLon;

        // Cap dur : l'excès part en `unresolved` sans aucun appel réseau.
        if (\count($pairs) > self::MAX_OPPONENTS) {
            foreach (\array_slice($pairs, self::MAX_OPPONENTS) as $excess) {
                $unresolved[] = $excess['code'];
            }
            $pairs = \array_slice($pairs, 0, self::MAX_OPPONENTS);
        }

        // Cache-first : un trajet est une CONSTANTE. Une paire DÉJÀ en cache est résolue (elle
        // n'est jamais re-routée) ; seuls les vrais manques partent en lot IGN.
        $jobs = [];
        $cached = [];
        $resolved = 0;
        foreach ($pairs as $pair) {
            if (null !== $this->travelCache->lookup($clubId, IgnRoutingClient::PROFILE_CAR, $clubLatF, $clubLonF, $pair['lat'], $pair['lon'])) {
                $cached[$pair['key']] = true;
                ++$resolved;

                continue;
            }
            $jobs[] = [
                'key' => $pair['key'],
                'profile' => IgnRoutingClient::PROFILE_CAR,
                'startLat' => $clubLatF,
                'startLon' => $clubLonF,
                'endLat' => $pair['lat'],
                'endLon' => $pair['lon'],
            ];
        }

        $total = \count($pairs);
        $cacheHits = \count($cached);
        if (null !== $onProgress && $total > 0) {
            $onProgress($cacheHits, $total);
        }

        $batch = $this->routingClient->travelMinutesBatch(
            $jobs,
            budgetSeconds: $budgetSeconds,
            onProgress: null === $onProgress ? null : static function (int $done) use ($onProgress, $cacheHits, $total): void {
                $onProgress($cacheHits + $done, $total);
            },
        );
        $budgetExceeded = array_fill_keys($batch['budgetExceededKeys'], true);

        foreach ($pairs as $pair) {
            $key = $pair['key'];
            if (isset($cached[$key])) {
                continue; // déjà compté résolu
            }
            if (isset($budgetExceeded[$key])) {
                // Le budget s'est arrêté AVANT cette paire : non tentée, relance pour finir.
                $unresolved[] = $pair['code'];

                continue;
            }
            $minutes = $batch['minutes'][$key] ?? null;
            if (null === $minutes) {
                // IGN muet : jamais mis en cache (il pourrait réussir plus tard).
                $unresolved[] = $pair['code'];

                continue;
            }
            $this->travelCache->store($clubId, IgnRoutingClient::PROFILE_CAR, $clubLatF, $clubLonF, $pair['lat'], $pair['lon'], $minutes);
            ++$resolved;
        }

        return ['resolved' => $resolved, 'unresolved' => $unresolved, 'skippedManual' => 0];
    }

    /**
     * Reste-t-il, pour ce club+saison, au moins une paire siège→gymnase à calculer ? Sert
     * de garde aux hooks d'import : ne dispatcher un calcul asynchrone que s'il a du travail
     * (sinon un simple re-dépôt lancerait un worker pour rien, faisant clignoter « calcul en
     * cours » à l'écran). Faux si le siège n'est pas localisé (rien n'est calculable).
     */
    public function hasUncomputedTravel(string $clubId, string $seasonId): bool
    {
        $club = $this->clubRepository->find($clubId);
        if (!$club instanceof Club || null === $club->getLatitude() || null === $club->getLongitude()) {
            return false;
        }
        $clubLat = (float) $club->getLatitude();
        $clubLon = (float) $club->getLongitude();
        foreach ($this->pairsToRoute($clubId, $seasonId) as $pair) {
            if (null === $this->travelCache->lookup($clubId, IgnRoutingClient::PROFILE_CAR, $clubLat, $clubLon, $pair['lat'], $pair['lon'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Comptabilise un choix MANUEL dans les suggestions PARTAGÉES de gymnases de
     * l'adversaire (« un compte, jamais un qui »). Changement de choix = −1 sur l'ancien
     * ref, +1 sur le nouveau ; re-choisir le même = neutre ; retrait = −1 seul (newRef null).
     * `$previousRef` n'est transmis QUE si le lien remplacé/retiré était MANUAL (un lien
     * AUTO n'a jamais compté — le décrémenter volerait le compte d'un autre club, P4-209a).
     *
     * 🔴 SÉCURITÉ (revue 2026-09-15) : le partagé ne reçoit QUE des données FÉDÉRALES. Le
     * numéro de salle est RE-RÉSOLU côté serveur contre l'index FFBB ({@see FfbbSalleResolver} —
     * les coordonnées ne sont qu'une graine de recherche) : libellé/ville/CP/coordonnées
     * écrits viennent du HIT fédéral, jamais du corps client. Ref non résolue ou FFBB muet :
     * le choix reste TENANT seul, AUCUNE écriture ni incrément au partagé.
     */
    public function accountManualChoice(string $code, ?string $previousRef, ?string $newRef, float $lat, float $lon): void
    {
        if ($previousRef === $newRef) {
            return; // re-choisir exactement le même gymnase : rien ne bouge
        }
        if (null !== $previousRef) {
            $this->debitSharedVenue($code, $previousRef);
        }
        if (null === $newRef) {
            return; // un gymnase sans référence fédérale ne partage rien (tenant seul)
        }

        $federal = $this->resolveFederalVenue($newRef, $lat, $lon);
        if (null === $federal) {
            $this->logger->warning('Opponent venue suggestion: federal salle unresolved, shared feed skipped', ['ref' => $newRef]);

            return;
        }
        $this->creditSharedVenue($code, $newRef, $federal);
    }

    /**
     * Re-résout une ref fédérale contre l'index FFBB (best-effort, off-network en test) — la
     * SEULE source du libellé/coordonnées écrits dans le partagé. Null = inconnue.
     *
     * @return array{label: string, city: ?string, postalCode: ?string, latitude: ?float, longitude: ?float}|null
     */
    public function resolveFederalVenue(string $ref, float $lat, float $lon): ?array
    {
        return $this->salleResolver->resolveByExternalRef($ref, $lat, $lon);
    }

    /**
     * +1 sur le catalogue partagé pour `(code, ref)` : upsert du libellé FÉDÉRAL (jamais le
     * texte du client) puis incrément. L'appelant garantit l'idempotence (un crédit par club).
     *
     * @param array{label: string, city: ?string, postalCode: ?string, latitude: ?float, longitude: ?float} $federal
     */
    public function creditSharedVenue(string $code, string $ref, array $federal): void
    {
        $this->suggestions->upsertManual($code, $ref, $federal['label'], $federal['city'], $federal['postalCode'], $federal['latitude'], $federal['longitude']);
        $this->suggestions->increment($code, $ref);
    }

    /**
     * −1 sur le catalogue partagé pour `(code, ref)` (jamais sous 0, cf. repo). L'appelant
     * garantit la symétrie (décrément au retrait du DERNIER lien du club sur ce gymnase).
     */
    public function debitSharedVenue(string $code, string $ref): void
    {
        $this->suggestions->decrement($code, $ref);
    }

    /**
     * Calcule (best-effort) le trajet voiture du siège du club vers un point et le range au
     * cache. Cache-first. Null = siège non localisé ou IGN muet. Sert aux gestes MANUELS du
     * contrôleur (un seul gymnase ajouté / ré-apparié) pour chauffer le cache tout de suite.
     */
    public function warmTravel(string $clubId, float $lat, float $lon): ?int
    {
        $club = $this->clubRepository->find($clubId);
        if (!$club instanceof Club || null === $club->getLatitude() || null === $club->getLongitude()) {
            return null;
        }
        $clubLat = (float) $club->getLatitude();
        $clubLon = (float) $club->getLongitude();
        $cached = $this->travelCache->lookup($clubId, IgnRoutingClient::PROFILE_CAR, $clubLat, $clubLon, $lat, $lon);
        if (null !== $cached) {
            return $cached;
        }
        $minutes = $this->routingClient->travelMinutes(IgnRoutingClient::PROFILE_CAR, $clubLat, $clubLon, $lat, $lon);
        if (null !== $minutes) {
            $this->travelCache->store($clubId, IgnRoutingClient::PROFILE_CAR, $clubLat, $clubLon, $lat, $lon, $minutes);
        }

        return $minutes;
    }

    /**
     * Les paires siège→lieu à router pour ce club+saison : (1) un point par lien dont le
     * code adverse est joué cette saison — le gymnase apparié ; (2) pour un code SANS lien,
     * les coordonnées VILLE de l'annuaire fédéral (le repli « ville seule » de la projection
     * a besoin de ce trajet approché). Dédupliquées par coordonnées arrondies (deux libellés
     * du même gymnase = une paire). Le `code` est gardé pour reporter un `unresolved` parlant.
     *
     * @return list<array{key: string, code: string, lat: float, lon: float}>
     */
    private function pairsToRoute(string $clubId, string $seasonId): array
    {
        $codes = array_flip($this->distinctOpponentCodes($seasonId));
        $pairs = [];
        $seen = [];
        $codesWithLink = [];
        foreach ($this->linkRepository->findByClub($clubId) as $link) {
            $code = $link->getOpponentOrganismeCode();
            if (!isset($codes[$code])) {
                continue;
            }
            $codesWithLink[$code] = true;
            $key = $this->travelCache->destKey($link->getLatitude(), $link->getLongitude());
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $pairs[] = ['key' => $key, 'code' => $code, 'lat' => $link->getLatitude(), 'lon' => $link->getLongitude()];
        }

        // (2) Repli VILLE : les codes joués cette saison SANS aucun lien apparié — on route
        // leur point d'annuaire pour que « ville seule » porte un trajet approché.
        foreach (array_keys($codes) as $code) {
            if (isset($codesWithLink[$code])) {
                continue;
            }
            $entry = $this->directory->findOneByFfbbOrganismeCode((string) $code);
            if (!$entry instanceof OpponentDirectoryEntry) {
                continue;
            }
            $lat = $entry->getLatitude();
            $lon = $entry->getLongitude();
            if (null === $lat || null === $lon) {
                continue;
            }
            $key = $this->travelCache->destKey($lat, $lon);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $pairs[] = ['key' => $key, 'code' => (string) $code, 'lat' => $lat, 'lon' => $lon];
        }

        return $pairs;
    }
}

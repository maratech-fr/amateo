<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ClubTravelCache;
use App\Entity\OpponentDirectoryEntry;
use App\Entity\OpponentVenueLink;
use App\Entity\OpponentVenueSuggestion;
use App\Entity\Season;
use App\Entity\User;
use App\Enum\FixtureHomeAway;
use App\Enum\TravelComputeScope;
use App\Message\ComputeTravelTimesMessage;
use App\Repository\ClubRepository;
use App\Repository\FixtureRepository;
use App\Repository\OpponentDirectoryEntryRepository;
use App\Repository\OpponentVenueLinkRepository;
use App\Repository\OpponentVenueSuggestionRepository;
use App\Service\Basketball\VenueLabelNormalizer;
use App\Service\Geo\IgnRoutingClient;
use App\Service\Geo\OpponentTravelResolver;
use App\Service\Geo\OpponentVenueLinkManager;
use App\Service\Geo\TravelTimeCache;
use App\Service\ManagementAccessGuard;
use App\Service\SeasonResolver;
use App\Service\TravelComputeLock;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * P2-54 (amendement 2026-09-20) — lecture et correction de l'APPARIEMENT « libellé de
 * salle → gymnase » d'un adversaire ({@see OpponentVenueLink}, tenant, club-scoped SANS
 * saison). Le TRAJET est une CONSTANTE lue depuis {@see ClubTravelCache} (siège
 * du club → gymnase), jamais stockée par ligne.
 *
 * GET  /api/opponents/travel                 — affichage (membre) : par CLUB adverse, ses
 *   gymnases appariés (trajet, statut, source, n rencontres, gymnase de repli si retiré) et
 *   ses libellés « à apparier ». DTO dédiés, jamais l'entité brute.
 * POST /api/opponents/{code}/venues          — (management) ajouter un gymnase (ref fédérale
 *   ou coordonnées choisies) — lien MANUAL, partagé re-résolu serveur.
 * POST /api/opponents/{code}/venue-links     — (management) apparier un LIBELLÉ orphelin à
 *   un gymnase.
 * PUT  /api/opponents/venue-links/{id}       — (management) ré-apparier / fusionner : re-
 *   pointer le lien vers un autre gymnase.
 * DELETE /api/opponents/venue-links/{id}     — (management) retirer l'appariement LOCAL
 *   (jamais le catalogue fédéral) ; décrémente le partagé si MANUAL.
 * POST /api/opponents/travel/resolve         — (management) DISPATCHE le calcul asynchrone
 *   des trajets manquants (worker). Cap dur + rate-limit dédié.
 * GET  /api/opponents/{code}/venue-suggestions — (management) les gymnases connus de
 *   l'adversaire (partagé, « un compte, jamais un qui »).
 */
#[AsController]
final class OpponentTravelController extends AbstractController
{
    use ResolvesCurrentClubTrait;

    /** Les codes organisme fédéraux sont alphanumériques : borne le {code} et exclut « venue-links » (tiret). */
    private const string CODE_RE = '[A-Za-z0-9]+';

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly SeasonResolver $seasonResolver,
        private readonly FixtureRepository $fixtures,
        private readonly ClubRepository $clubRepository,
        private readonly OpponentVenueLinkRepository $linkRepository,
        private readonly OpponentDirectoryEntryRepository $directory,
        private readonly OpponentVenueSuggestionRepository $suggestions,
        private readonly ManagementAccessGuard $managementAccessGuard,
        private readonly OpponentTravelResolver $resolver,
        private readonly OpponentVenueLinkManager $linkManager,
        private readonly VenueLabelNormalizer $labelNormalizer,
        private readonly TravelTimeCache $travelCache,
        private readonly RateLimiterFactory $opponentTravelResolveLimiter,
        private readonly RateLimiterFactory $opponentTravelManualLimiter,
        private readonly TravelComputeLock $travelComputeLock,
        private readonly MessageBusInterface $messageBus,
    ) {}

    #[Route('/api/opponents/travel', name: 'api_opponents_travel_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        [$clubId, $season, $error] = $this->context($request);
        if ($error instanceof JsonResponse) {
            return $error;
        }
        \assert(null !== $clubId && $season instanceof Season);

        $club = $this->clubRepository->find($clubId);
        $clubLat = $club?->getLatitude();
        $clubLon = $club?->getLongitude();
        $clubGeolocated = null !== $clubLat && null !== $clubLon;
        // Le cache des trajets siège→gymnase, chargé UNE fois (aucun N+1 sur les gymnases).
        $cacheByDest = $clubGeolocated
            ? $this->travelCache->lookupAllFromOrigin($clubId, IgnRoutingClient::PROFILE_CAR, (float) $clubLat, (float) $clubLon)
            : [];
        // pending = un calcul de trajets tourne pour ce club (C6) — alimente `travelStatus`.
        $computePending = $this->travelComputeLock->isHeld($clubId);

        return $this->json([
            'clubId' => $clubId,
            'seasonId' => $season->getId(),
            'clubGeolocated' => $clubGeolocated,
            'opponents' => $this->buildOpponents($clubId, $season->getId(), $cacheByDest, $computePending),
        ]);
    }

    #[Route('/api/opponents/{code}/venues', name: 'api_opponents_venue_add', requirements: ['code' => self::CODE_RE], methods: ['POST'])]
    public function addVenue(Request $request, string $code): JsonResponse
    {
        return $this->writeLink($request, $code, requireFbiLabel: false);
    }

    #[Route('/api/opponents/{code}/venue-links', name: 'api_opponents_venue_link_add', requirements: ['code' => self::CODE_RE], methods: ['POST'])]
    public function appairLabel(Request $request, string $code): JsonResponse
    {
        return $this->writeLink($request, $code, requireFbiLabel: true);
    }

    #[Route('/api/opponents/venue-links/{id}', name: 'api_opponents_venue_link_update', methods: ['PUT'])]
    public function repointLink(Request $request, string $id): JsonResponse
    {
        $this->managementAccessGuard->assertManager(); // SEC-07

        [$clubId, $season, $error] = $this->context($request);
        if ($error instanceof JsonResponse) {
            return $error;
        }
        \assert(null !== $clubId && $season instanceof Season);

        $payload = $this->payload($request);
        $gym = $this->parseGym($payload);
        if (null === $gym) {
            return $this->json(['error' => 'Gymnase invalide.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if (null === $this->linkRepository->find($id)) {
            // 404 byte-identique : un lien d'un autre club est invisible (RLS) → même 404.
            return $this->json(['error' => 'Appariement introuvable.'], Response::HTTP_NOT_FOUND);
        }
        if (!$this->consumeManualLimiter()) {
            return $this->json(['error' => 'Trop de gymnases épinglés — réessayez plus tard.'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $link = $this->linkManager->repoint($clubId, $id, $gym['label'], $gym['ref'], $gym['lat'], $gym['lon']);
        if (!$link instanceof OpponentVenueLink) {
            return $this->json(['error' => 'Appariement introuvable.'], Response::HTTP_NOT_FOUND);
        }

        // La fusion (deux libellés vers le même gymnase) veut le compte RÉSULTANT de la cible :
        // toutes les rencontres AWAY du club dont le libellé résout désormais vers ce gymnase.
        return $this->json($this->linkView($link, $this->targetFixtureCount($clubId, $season->getId(), $link)), Response::HTTP_OK);
    }

    #[Route('/api/opponents/venue-links/{id}', name: 'api_opponents_venue_link_delete', methods: ['DELETE'])]
    public function deleteLink(Request $request, string $id): JsonResponse
    {
        $this->managementAccessGuard->assertManager(); // SEC-07

        [$clubId, $season, $error] = $this->context($request);
        if ($error instanceof JsonResponse) {
            return $error;
        }
        \assert(null !== $clubId && $season instanceof Season);

        if (!$this->linkManager->delete($clubId, $id)) {
            return $this->json(['error' => 'Appariement introuvable.'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/api/opponents/travel/resolve', name: 'api_opponents_travel_resolve', methods: ['POST'])]
    public function resolve(Request $request): JsonResponse
    {
        $this->managementAccessGuard->assertManager(); // SEC-07 first, so 403 wins.

        [$clubId, $season, $error] = $this->context($request);
        if ($error instanceof JsonResponse) {
            return $error;
        }
        \assert(null !== $clubId && $season instanceof Season);

        // Cap dur AVANT tout réseau (aucun jeton brûlé sur un 422-cap).
        $codes = $this->resolver->distinctOpponentCodes($season->getId());
        if (\count($codes) > OpponentTravelResolver::MAX_OPPONENTS) {
            return $this->json([
                'error' => \sprintf(
                    'Trop d\'adversaires à traiter en une fois (%d, maximum %d). Réessayez après avoir réduit le nombre de rencontres.',
                    \count($codes),
                    OpponentTravelResolver::MAX_OPPONENTS,
                ),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $user = $this->getUser();
        if ($user instanceof User && !$this->opponentTravelResolveLimiter->create($user->getId())->consume(1)->isAccepted()) {
            return $this->json(['error' => 'Trop de calculs de trajet — réessayez plus tard.'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        if ($this->travelComputeLock->isHeld($clubId)) {
            return $this->json(['queued' => false, 'alreadyRunning' => true]);
        }
        // C6 — le calcul quitte le rail synchrone. « Réessayer les manquants » = ce POST
        // (resolve() ne route déjà QUE les paires manquantes du cache).
        $this->messageBus->dispatch(new ComputeTravelTimesMessage($clubId, $season->getId(), TravelComputeScope::OPPONENTS));

        return $this->json(['queued' => true, 'alreadyRunning' => false]);
    }

    /**
     * The PARTAGÉES venue suggestions for one away opponent (management, A6) : les gymnases
     * connus de l'adversaire — vus dans le calendrier fédéral (FFBB_API) ou choisis par des
     * clubs (MANUAL) — avec un COMPTE, jamais un « qui ».
     */
    #[Route('/api/opponents/{code}/venue-suggestions', name: 'api_opponents_venue_suggestions', requirements: ['code' => self::CODE_RE], methods: ['GET'])]
    public function venueSuggestions(Request $request, string $code): JsonResponse
    {
        $this->managementAccessGuard->assertManager(); // SEC-07 first, so 403 wins (A6).

        [$clubId, $season, $error] = $this->context($request);
        if ($error instanceof JsonResponse) {
            return $error;
        }
        \assert(null !== $clubId && $season instanceof Season);

        $clean = $this->cleanCode($code);
        if (null === $clean || !$this->isAwayCode($season->getId(), $clean)) {
            return $this->json(['error' => 'Cet adversaire n\'a aucune rencontre à l\'extérieur cette saison.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json([
            'code' => $clean,
            'suggestions' => array_map($this->suggestionView(...), $this->suggestions->findByCode($clean)),
        ]);
    }

    /**
     * Foyer commun des deux POST d'appariement MANUEL : ajouter un gymnase (`/venues`, le
     * libellé de fichier est optionnel — on retombe sur le libellé du gymnase) et apparier
     * un libellé orphelin (`/venue-links`, le libellé est obligatoire et doit être une salle
     * réellement jouée à l'extérieur).
     */
    private function writeLink(Request $request, string $code, bool $requireFbiLabel): JsonResponse
    {
        $this->managementAccessGuard->assertManager(); // SEC-07

        [$clubId, $season, $error] = $this->context($request);
        if ($error instanceof JsonResponse) {
            return $error;
        }
        \assert(null !== $clubId && $season instanceof Season);

        $clean = $this->cleanCode($code);
        if (null === $clean || !$this->isAwayCode($season->getId(), $clean)) {
            return $this->json(['error' => 'Cet adversaire n\'a aucune rencontre à l\'extérieur cette saison.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $payload = $this->payload($request);
        $gym = $this->parseGym($payload);
        $rawFbiLabel = \is_string($payload['fbiLabel'] ?? null) ? trim($payload['fbiLabel']) : '';
        $fbiLabel = '' !== $rawFbiLabel ? $rawFbiLabel : ($requireFbiLabel ? '' : $gym['label'] ?? '');

        if (null === $gym || '' === $fbiLabel) {
            return $this->json(['error' => 'Gymnase ou libellé invalide.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        // Apparier un ORPHELIN : le libellé doit être une salle réellement jouée à l'extérieur
        // (jamais un libellé arbitraire imposé au partagé par une requête forgée).
        if ($requireFbiLabel && !$this->isAwayFbiLabel($season->getId(), $clean, $fbiLabel)) {
            return $this->json(['error' => 'Ce libellé de salle n\'apparaît sur aucune rencontre à l\'extérieur de cet adversaire.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Borne le nombre de gymnases par (club, adversaire) : l'ajout d'une NOUVELLE clé de
        // libellé est refusé au-delà du plafond (l'actualisation d'un libellé déjà apparié
        // reste permise). Empêche l'inflation d'un compteur communautaire par des libellés forgés.
        $existingLinks = $this->linkRepository->findByCode($clubId, $clean);
        $norm = $this->labelNormalizer->normalize(trim($fbiLabel));
        $isNewKey = true;
        foreach ($existingLinks as $existingLink) {
            if ($existingLink->getFbiLabelNorm() === $norm) {
                $isNewKey = false;

                break;
            }
        }
        if ($isNewKey && \count($existingLinks) >= OpponentVenueLinkManager::MAX_VENUES_PER_OPPONENT) {
            return $this->json(['error' => 'Trop de gymnases pour cet adversaire.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // SEC-19 — borne PAR UTILISATEUR consommée APRÈS les 422 : le geste chauffe un
        // itinéraire IGN et peut écrire dans le partagé.
        if (!$this->consumeManualLimiter()) {
            return $this->json(['error' => 'Trop de gymnases épinglés — réessayez plus tard.'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $link = $this->linkManager->addOrUpdate($clubId, $clean, $fbiLabel, $gym['label'], $gym['ref'], $gym['lat'], $gym['lon']);

        return $this->json($this->linkView($link, $this->targetFixtureCount($clubId, $season->getId(), $link)), Response::HTTP_OK);
    }

    /**
     * Par CLUB adverse joué cette saison : ses gymnases appariés (`venues`) et ses libellés
     * « à apparier » (`unmatchedLabels`). Groupé par code fédéral (les rencontres sans code
     * sont regroupées au libellé, non appariables).
     *
     * @param array<string, int> $cacheByDest destKey → aller simple (minutes)
     *
     * @return list<array<string, mixed>>
     */
    private function buildOpponents(string $clubId, string $seasonId, array $cacheByDest, bool $computePending): array
    {
        $away = $this->fixtures->findAwayBySeason($seasonId);
        $linksByCode = $this->linksByCode($clubId);

        // Regroupe les rencontres AWAY par code (ou par libellé si code absent) et compte, par
        // code, les rencontres par libellé de salle normalisé.
        /** @var array<string, array{code: string|null, name: string, labelCounts: array<string, array{label: string, count: int}>, total: int}> $groups */
        $groups = [];
        foreach ($away as $fixture) {
            $rawTeamLabel = trim($fixture->getOpponentLabel());
            $code = $fixture->getOpponentOrganismeCode();
            $code = null !== $code && '' !== $code ? $code : null;
            $key = null === $code ? 'label:' . mb_strtolower($rawTeamLabel) : 'code:' . $code;
            $groups[$key] ??= ['code' => $code, 'name' => $rawTeamLabel, 'labelCounts' => [], 'total' => 0];
            ++$groups[$key]['total'];

            $fbiLabel = $fixture->getFbiVenueLabel();
            if (null === $fbiLabel || '' === trim($fbiLabel)) {
                continue; // rencontre sans salle de fichier : compte au total, jamais un libellé
            }
            $norm = $this->labelNormalizer->normalize(trim($fbiLabel));
            if ('' === $norm) {
                continue;
            }
            $groups[$key]['labelCounts'][$norm] ??= ['label' => trim($fbiLabel), 'count' => 0];
            ++$groups[$key]['labelCounts'][$norm]['count'];
        }

        $opponents = [];
        foreach ($groups as $group) {
            $code = $group['code'];
            $entry = null === $code ? null : $this->directory->findOneByFfbbOrganismeCode($code);
            $links = null === $code ? [] : ($linksByCode[$code] ?? []);
            $opponents[] = $this->opponentView($group, $entry, $links, $cacheByDest, $computePending);
        }
        usort($opponents, static function (array $a, array $b): int {
            // Sans gymnase d'abord (aucun venue), puis alphabétique (fr, insensible à la casse).
            $aEmpty = [] === $a['venues'] ? 0 : 1;
            $bEmpty = [] === $b['venues'] ? 0 : 1;

            return $aEmpty <=> $bEmpty ?: strcasecmp((string) $a['name'], (string) $b['name']);
        });

        return $opponents;
    }

    /**
     * @param array{code: string|null, name: string, labelCounts: array<string, array{label: string, count: int}>, total: int} $group
     * @param list<OpponentVenueLink>                                                                                          $links
     * @param array<string, int>                                                                                               $cacheByDest
     *
     * @return array<string, mixed>
     */
    private function opponentView(array $group, ?OpponentDirectoryEntry $entry, array $links, array $cacheByDest, bool $computePending): array
    {
        // Les gymnases appariés (une entrée par lien), avec leur compte de rencontres et le
        // gymnase de repli si on le retirait.
        $venues = [];
        foreach ($links as $link) {
            $fixtureCount = $group['labelCounts'][$link->getFbiLabelNorm()]['count'] ?? 0;
            $venues[] = $this->venueView($link, $fixtureCount, $links, $group['labelCounts'], $cacheByDest, $computePending);
        }

        // Les libellés SANS lien = « à apparier ».
        $unmatched = [];
        foreach ($group['labelCounts'] as $norm => $entryLabel) {
            if ($this->hasLink($links, $norm)) {
                continue;
            }
            $unmatched[] = ['label' => $entryLabel['label'], 'fixtureCount' => $entryLabel['count']];
        }

        return [
            'code' => $group['code'],
            'name' => $entry instanceof OpponentDirectoryEntry ? $entry->getName() : $group['name'],
            'city' => $entry?->getCity(),
            // Le code postal fédéral (données publiques) — préfiltre la recherche de gymnase.
            'postalCode' => $entry?->getPostalCode(),
            // La précision fédérale (VENUE|CITY|null) — le front distingue « ville seule »
            // (CITY) de « aucun gymnase connu » (null) quand il n'y a aucun lien.
            'precision' => $entry?->getPrecision()?->value,
            'hasLogo' => null !== $entry?->getLogoId(),
            'fixtureCount' => $group['total'],
            'venues' => $venues,
            'unmatchedLabels' => $unmatched,
        ];
    }

    /**
     * @param list<OpponentVenueLink>                         $siblings
     * @param array<string, array{label: string, count: int}> $labelCounts
     * @param array<string, int>                              $cacheByDest
     *
     * @return array<string, mixed>
     */
    private function venueView(OpponentVenueLink $link, int $fixtureCount, array $siblings, array $labelCounts, array $cacheByDest, bool $computePending): array
    {
        $oneWay = $cacheByDest[$this->travelCache->destKey($link->getLatitude(), $link->getLongitude())] ?? null;

        return [
            'id' => $link->getId(),
            'label' => $link->getVenueLabel(),
            'externalRef' => $link->getVenueExternalRef(),
            // Coordonnées fédérales du gymnase (données publiques) — le front les repasse tel
            // quel au geste de FUSION (ré-appariement vers CE gymnase). Jamais le siège du club.
            'latitude' => $link->getLatitude(),
            'longitude' => $link->getLongitude(),
            'source' => $link->getSource()->value,
            'travelMinutes' => $oneWay,
            'travelStatus' => null !== $oneWay ? 'done' : ($computePending ? 'pending' : 'unavailable'),
            // Un lien = un gymnase exact → jamais « approché » (le repli approché vit dans la
            // projection par rencontre, pas ici).
            'approximated' => false,
            'fixtureCount' => $fixtureCount,
            'fallbackVenueName' => $this->fallbackVenueName($link, $siblings, $labelCounts),
        ];
    }

    /**
     * Le gymnase qui recueillerait les rencontres de CE lien s'il était retiré : le plus
     * fréquent des AUTRES liens de l'adversaire (compte des rencontres), null si c'est le
     * dernier. Sert au dialogue de retrait (le front ne le dérive jamais).
     *
     * @param list<OpponentVenueLink>                         $siblings
     * @param array<string, array{label: string, count: int}> $labelCounts
     */
    private function fallbackVenueName(OpponentVenueLink $link, array $siblings, array $labelCounts): ?string
    {
        $best = null;
        $bestCount = -1;
        foreach ($siblings as $sibling) {
            if ($sibling->getId() === $link->getId()) {
                continue;
            }
            $count = $labelCounts[$sibling->getFbiLabelNorm()]['count'] ?? 0;
            if ($count > $bestCount) {
                $bestCount = $count;
                $best = $sibling->getVenueLabel();
            }
        }

        return $best;
    }

    /**
     * Le compte RÉSULTANT d'un gymnase cible après un ajout/ré-appariement : toutes les
     * rencontres AWAY du club (ce code) dont le libellé résout désormais vers CE gymnase (ses
     * coordonnées) — la somme des libellés qui pointent le même lieu (fusion). Sert la réponse
     * d'écriture (« qui en portera 8 »).
     */
    private function targetFixtureCount(string $clubId, string $seasonId, OpponentVenueLink $link): int
    {
        $destKey = $this->travelCache->destKey($link->getLatitude(), $link->getLongitude());
        // Les libellés normalisés du club (ce code) qui pointent le même gymnase (coordonnées).
        $normsAtGym = [];
        foreach ($this->linkRepository->findByCode($clubId, $link->getOpponentOrganismeCode()) as $sibling) {
            if ($this->travelCache->destKey($sibling->getLatitude(), $sibling->getLongitude()) === $destKey) {
                $normsAtGym[$sibling->getFbiLabelNorm()] = true;
            }
        }
        $count = 0;
        foreach ($this->fixtures->findAwayBySeason($seasonId) as $fixture) {
            if ($fixture->getOpponentOrganismeCode() !== $link->getOpponentOrganismeCode()) {
                continue;
            }
            $label = $fixture->getFbiVenueLabel();
            if (null === $label || '' === trim($label)) {
                continue;
            }
            if (isset($normsAtGym[$this->labelNormalizer->normalize(trim($label))])) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * The write response for one link + the resulting target fixture count (merge « en
     * portera N »).
     *
     * @return array<string, mixed>
     */
    private function linkView(OpponentVenueLink $link, int $targetFixtureCount): array
    {
        $club = $this->clubRepository->find($link->getClubId());
        $oneWay = null;
        if (null !== $club?->getLatitude() && null !== $club->getLongitude()) {
            $oneWay = $this->travelCache->lookup(
                $link->getClubId(),
                IgnRoutingClient::PROFILE_CAR,
                (float) $club->getLatitude(),
                (float) $club->getLongitude(),
                $link->getLatitude(),
                $link->getLongitude(),
            );
        }

        return [
            'id' => $link->getId(),
            'opponentOrganismeCode' => $link->getOpponentOrganismeCode(),
            'fbiLabel' => $link->getFbiLabel(),
            'label' => $link->getVenueLabel(),
            'externalRef' => $link->getVenueExternalRef(),
            'source' => $link->getSource()->value,
            'travelMinutes' => $oneWay,
            'targetFixtureCount' => $targetFixtureCount,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function suggestionView(OpponentVenueSuggestion $suggestion): array
    {
        return [
            'externalRef' => $suggestion->getVenueExternalRef(),
            'label' => $suggestion->getVenueLabel(),
            'city' => $suggestion->getCity(),
            'postalCode' => $suggestion->getPostalCode(),
            'latitude' => $suggestion->getLatitude(),
            'longitude' => $suggestion->getLongitude(),
            'source' => $suggestion->getSource()->value,
            'chosenByCount' => $suggestion->getChosenByCount(),
            // Date SEULE (jamais l'heure) — « un compte, jamais un qui ».
            'lastChosenAt' => $suggestion->getLastChosenAt()?->format('Y-m-d'),
        ];
    }

    /**
     * @return array<string, list<OpponentVenueLink>> code → its links
     */
    private function linksByCode(string $clubId): array
    {
        $map = [];
        foreach ($this->linkRepository->findByClub($clubId) as $link) {
            $map[$link->getOpponentOrganismeCode()][] = $link;
        }

        return $map;
    }

    /**
     * @param list<OpponentVenueLink> $links
     */
    private function hasLink(array $links, string $fbiLabelNorm): bool
    {
        foreach ($links as $link) {
            if ($link->getFbiLabelNorm() === $fbiLabelNorm) {
                return true;
            }
        }

        return false;
    }

    /** True when `$code` is a real AWAY opponent of the club+season. */
    private function isAwayCode(string $seasonId, string $code): bool
    {
        return \in_array($code, $this->resolver->distinctOpponentCodes($seasonId), true);
    }

    /** True when `$fbiLabel` normalises to a salle actually played AWAY under `$code` this season. */
    private function isAwayFbiLabel(string $seasonId, string $code, string $fbiLabel): bool
    {
        $needle = $this->labelNormalizer->normalize(trim($fbiLabel));
        if ('' === $needle) {
            return false;
        }
        foreach ($this->fixtures->findAwayBySeason($seasonId) as $fixture) {
            if (FixtureHomeAway::AWAY !== $fixture->getHomeAway() || $fixture->getOpponentOrganismeCode() !== $code) {
                continue;
            }
            $label = $fixture->getFbiVenueLabel();
            if (null !== $label && '' !== trim($label) && $this->labelNormalizer->normalize(trim($label)) === $needle) {
                return true;
            }
        }

        return false;
    }

    private function consumeManualLimiter(): bool
    {
        $user = $this->getUser();

        return !$user instanceof User || $this->opponentTravelManualLimiter->create($user->getId())->consume(1)->isAccepted();
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{label: string, ref: string|null, lat: float, lon: float}|null
     */
    private function parseGym(array $payload): ?array
    {
        $label = \is_string($payload['venueLabel'] ?? null) ? trim($payload['venueLabel']) : '';
        $ref = \is_string($payload['venueExternalRef'] ?? null) && '' !== trim($payload['venueExternalRef'])
            ? mb_substr(trim($payload['venueExternalRef']), 0, 64)
            : null;
        $lat = $this->coordinate($payload['latitude'] ?? null, -90.0, 90.0);
        $lon = $this->coordinate($payload['longitude'] ?? null, -180.0, 180.0);
        if ('' === $label || null === $lat || null === $lon) {
            return null;
        }

        return ['label' => mb_substr($label, 0, 180), 'ref' => $ref, 'lat' => $lat, 'lon' => $lon];
    }

    /** @return array<string, mixed> */
    private function payload(Request $request): array
    {
        /** @var mixed $payload */
        $payload = json_decode($request->getContent(), true);

        return \is_array($payload) ? $payload : [];
    }

    private function cleanCode(mixed $value): ?string
    {
        if (!\is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return '' === $trimmed || mb_strlen($trimmed) > 64 ? null : $trimmed;
    }

    private function coordinate(mixed $value, float $min, float $max): ?float
    {
        if (!is_numeric($value)) {
            return null;
        }
        $float = (float) $value;

        return $float >= $min && $float <= $max ? $float : null;
    }

    /** @return array{0: string|null, 1: Season|null, 2: JsonResponse|null} */
    private function context(Request $request): array
    {
        $clubId = $this->resolveCurrentClubId($this->requestStack);
        $season = null === $clubId ? null : $this->seasonResolver->selectedOrCurrent($request, $clubId);
        if (null === $clubId || !$season instanceof Season) {
            return [null, null, $this->json(['error' => 'Club ou saison introuvable dans le contexte.'], Response::HTTP_BAD_REQUEST)];
        }

        return [$clubId, $season, null];
    }
}

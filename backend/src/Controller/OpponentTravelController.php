<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Fixture;
use App\Entity\OpponentDirectoryEntry;
use App\Entity\OpponentTravel;
use App\Entity\OpponentVenueSuggestion;
use App\Entity\Season;
use App\Entity\User;
use App\Enum\OpponentLocationPrecision;
use App\Repository\ClubRepository;
use App\Repository\FixtureRepository;
use App\Repository\OpponentDirectoryEntryRepository;
use App\Repository\OpponentTravelRepository;
use App\Repository\OpponentVenueSuggestionRepository;
use App\Service\Basketball\VenueLabelNormalizer;
use App\Service\Geo\OpponentTravelResolver;
use App\Service\ManagementAccessGuard;
use App\Service\SeasonResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * P2-54 RMM-9 PR-3 — la lecture et la correction du TRAJET adverse (tenant).
 *
 * GET  /api/opponents/travel          — pour l'affichage (membre) : par adversaire
 *   AWAY distinct, la précision du lieu (VENUE|CITY|absent), le nom du lieu, le
 *   trajet aller simple (nullable), le flag « approché » (= CITY, calculé serveur),
 *   la source (AUTO|MANUAL) et l'éventuelle surcharge de gymnase. DTO dédiés, jamais
 *   l'entité brute.
 * POST /api/opponents/travel/manual   — (management) le gestionnaire épingle un
 *   gymnase (choisi via /api/ffbb/salles) pour un adversaire → surcharge MANUAL +
 *   recalcul du trajet depuis ce lieu.
 * POST /api/opponents/travel/auto     — (management) retour à l'AUTO : la surcharge
 *   tombe, le trajet est recalculé depuis le lieu du global.
 * POST /api/opponents/travel/resolve  — (management) recalcule TOUS les trajets AUTO
 *   du club+saison. Cap dur AVANT réseau + rate-limit dédié par utilisateur.
 *
 * Écrit UNIQUEMENT la table tenant `opponent_travel` (donnée club-spécifique, RLS).
 * Le trajet dépend du siège du club — jamais dans le global partagé.
 */
#[AsController]
final class OpponentTravelController extends AbstractController
{
    use ResolvesCurrentClubTrait;

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly SeasonResolver $seasonResolver,
        private readonly FixtureRepository $fixtures,
        private readonly ClubRepository $clubRepository,
        private readonly OpponentTravelRepository $travelRepository,
        private readonly OpponentDirectoryEntryRepository $directory,
        private readonly OpponentVenueSuggestionRepository $suggestions,
        private readonly ManagementAccessGuard $managementAccessGuard,
        private readonly OpponentTravelResolver $resolver,
        private readonly VenueLabelNormalizer $labelNormalizer,
        private readonly RateLimiterFactory $opponentTravelResolveLimiter,
        private readonly RateLimiterFactory $opponentTravelManualLimiter,
    ) {}

    #[Route('/api/opponents/travel', name: 'api_opponents_travel_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        [$clubId, $season, $error] = $this->context($request);
        if ($error instanceof JsonResponse) {
            return $error;
        }
        \assert(null !== $clubId && $season instanceof Season);

        $awayFixtures = $this->fixtures->findAwayBySeason($season->getId());
        $travelIndex = $this->indexTravel($season->getId());
        // Champ ADDITIF : le siège du club est-il localisé ? (sans quoi aucun trajet ne
        // s'estime.) Un booléen SEUL — jamais les coordonnées brutes du club.
        $club = $this->clubRepository->find($clubId);
        $clubLat = $club?->getLatitude();
        $clubLon = $club?->getLongitude();
        $clubGeolocated = null !== $clubLat && null !== $clubLon;

        return $this->json([
            'clubId' => $clubId,
            'seasonId' => $season->getId(),
            'clubGeolocated' => $clubGeolocated,
            'opponents' => $this->buildOpponents($awayFixtures, $travelIndex),
        ]);
    }

    #[Route('/api/opponents/travel/manual', name: 'api_opponents_travel_manual', methods: ['POST'])]
    public function manual(Request $request): JsonResponse
    {
        $this->managementAccessGuard->assertManager(); // SEC-07

        [$clubId, $season, $error] = $this->context($request);
        if ($error instanceof JsonResponse) {
            return $error;
        }
        \assert(null !== $clubId && $season instanceof Season);

        /** @var mixed $payload */
        $payload = json_decode($request->getContent(), true);
        $payload = \is_array($payload) ? $payload : [];

        $code = $this->cleanCode($payload['opponentOrganismeCode'] ?? null);
        $label = \is_string($payload['venueLabel'] ?? null) ? trim($payload['venueLabel']) : '';
        $ref = \is_string($payload['venueExternalRef'] ?? null) && '' !== trim($payload['venueExternalRef']) ? mb_substr(trim($payload['venueExternalRef']), 0, 64) : null;
        $lat = $this->coordinate($payload['latitude'] ?? null, -90.0, 90.0);
        $lon = $this->coordinate($payload['longitude'] ?? null, -180.0, 180.0);
        // Portée : TEAM dès qu'un teamKey est fourni, CLUB sinon — un `scope` explicite peut
        // forcer CLUB (on ignore alors le teamKey). Un `scope=TEAM` sans teamKey est invalide.
        $rawTeamKey = $this->cleanTeamKey($payload['opponentTeamKey'] ?? null);
        $scope = $this->cleanScope($payload['scope'] ?? null) ?? (null !== $rawTeamKey ? 'TEAM' : 'CLUB');
        $teamKey = 'CLUB' === $scope ? null : $rawTeamKey;

        if (null === $code || '' === $label || null === $lat || null === $lon || ('TEAM' === $scope && null === $teamKey)) {
            return $this->json(['error' => 'Adversaire ou gymnase invalide.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if (!$this->isAwayOpponent($season->getId(), $code, $teamKey)) {
            return $this->json(['error' => 'Cet adversaire n\'a aucune rencontre à l\'extérieur cette saison.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // SEC-19 — borne PAR UTILISATEUR consommée APRÈS les 422 (aucun jeton brûlé sur un
        // refus) : l'override recalcule un itinéraire IGN et peut écrire dans le partagé.
        $user = $this->getUser();
        if ($user instanceof User && !$this->opponentTravelManualLimiter->create($user->getId())->consume(1)->isAccepted()) {
            return $this->json(['error' => 'Trop de gymnases épinglés — réessayez plus tard.'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $row = $this->resolver->applyManualOverride($clubId, $season->getId(), $code, $teamKey, $ref, mb_substr($label, 0, 180), $lat, $lon);

        return $this->json($this->travelView($row), Response::HTTP_OK);
    }

    #[Route('/api/opponents/travel/auto', name: 'api_opponents_travel_auto', methods: ['POST'])]
    public function auto(Request $request): JsonResponse
    {
        $this->managementAccessGuard->assertManager(); // SEC-07

        [$clubId, $season, $error] = $this->context($request);
        if ($error instanceof JsonResponse) {
            return $error;
        }
        \assert(null !== $clubId && $season instanceof Season);

        /** @var mixed $payload */
        $payload = json_decode($request->getContent(), true);
        $payload = \is_array($payload) ? $payload : [];
        $code = $this->cleanCode($payload['opponentOrganismeCode'] ?? null);
        if (null === $code) {
            return $this->json(['error' => 'Adversaire invalide.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $teamKey = $this->cleanTeamKey($payload['opponentTeamKey'] ?? null);

        // Grain ÉQUIPE (A3) : rétablir l'automatique = SUPPRIMER la ligne équipe ; la
        // rencontre retombe sur la ligne club puis l'annuaire (vue résolue renvoyée).
        if (null !== $teamKey) {
            if (!$this->resolver->deleteTeamOverride($season->getId(), $code, $teamKey)) {
                return $this->json(['error' => 'Aucune localisation manuelle à rétablir pour cet adversaire.'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $fallback = $this->travelRepository->findEffective($season->getId(), $code, $teamKey);

            return $this->json(
                $fallback instanceof OpponentTravel ? $this->travelView($fallback) : $this->emptyTravelView($code, $teamKey),
                Response::HTTP_OK,
            );
        }

        $row = $this->resolver->revertToAuto($clubId, $season->getId(), $code);
        if (!$row instanceof OpponentTravel) {
            return $this->json(['error' => 'Aucune localisation manuelle à rétablir pour cet adversaire.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json($this->travelView($row), Response::HTTP_OK);
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

        // Cap dur AVANT tout appel réseau (aucun jeton brûlé sur un 422-cap) : la lecture
        // ne touche que la base (fixtures AWAY du club+saison).
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

        return $this->json($this->resolver->resolve($clubId, $season->getId()));
    }

    /**
     * The PARTAGÉES venue suggestions for one away opponent (management, A6) : les
     * gymnases connus de l'adversaire — vus dans le calendrier fédéral (FFBB_API) ou
     * choisis par des clubs (MANUAL) — avec un COMPTE, jamais un « qui ». FFBB_API
     * d'abord, puis MANUAL par compte décroissant. Le `{code}` doit être un adversaire
     * AWAY de la saison courante du club (422 sinon), jamais un code arbitraire.
     */
    #[Route('/api/opponents/{code}/venue-suggestions', name: 'api_opponents_venue_suggestions', methods: ['GET'])]
    public function venueSuggestions(Request $request, string $code): JsonResponse
    {
        $this->managementAccessGuard->assertManager(); // SEC-07 first, so 403 wins (A6).

        [$clubId, $season, $error] = $this->context($request);
        if ($error instanceof JsonResponse) {
            return $error;
        }
        \assert(null !== $clubId && $season instanceof Season);

        $clean = $this->cleanCode($code);
        if (null === $clean || !$this->isAwayOpponent($season->getId(), $clean, null)) {
            return $this->json(['error' => 'Cet adversaire n\'a aucune rencontre à l\'extérieur cette saison.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json([
            'code' => $clean,
            'suggestions' => array_map($this->suggestionView(...), $this->suggestions->findByCode($clean)),
        ]);
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
            // Date SEULE (jamais l'heure) : la seconde exacte serait un canal auxiliaire
            // temporel permettant de corréler un choix à un club (« un compte, jamais un qui »).
            'lastChosenAt' => $suggestion->getLastChosenAt()?->format('Y-m-d'),
        ];
    }

    /**
     * Group the AWAY fixtures by opponent TEAM — `(organisme code, normalized label)`
     * when stamped, else the normalized label alone — keeping the RAW rencontre label
     * for display (P2-54 « adversaire multi-gymnases » : two teams of the same
     * organisme, « BASKET 5EME - 1 » vs « - 2 », are two distinct entries). Each entry
     * resolves its travel team → club → directory and says which grain won (`scope`).
     *
     * @param list<Fixture>                                                                         $awayFixtures
     * @param array<string, array{club: OpponentTravel|null, teams: array<string, OpponentTravel>}> $travelIndex
     *
     * @return list<array<string, mixed>>
     */
    private function buildOpponents(array $awayFixtures, array $travelIndex): array
    {
        /** @var array<string, array{code: string|null, teamKey: string|null, label: string}> $groups */
        $groups = [];
        foreach ($awayFixtures as $fixture) {
            $label = trim($fixture->getOpponentLabel());
            $code = $fixture->getOpponentOrganismeCode();
            $code = null !== $code && '' !== $code ? $code : null;
            if (null === $code) {
                // Code fédéral non résolu : regroupé au libellé, jamais localisé (inchangé).
                $key = 'label:' . mb_strtolower($label);
                $groups[$key] ??= ['code' => null, 'teamKey' => null, 'label' => $label];

                continue;
            }
            $teamKey = $this->labelNormalizer->normalize($label);
            $teamKey = '' === $teamKey ? null : $teamKey;
            $key = 'code:' . $code . '|team:' . ($teamKey ?? '');
            $groups[$key] ??= ['code' => $code, 'teamKey' => $teamKey, 'label' => $label];
        }

        $opponents = [];
        foreach ($groups as $group) {
            $code = $group['code'];
            $teamKey = $group['teamKey'];
            $entry = null === $code ? null : $this->directory->findOneByFfbbOrganismeCode($code);
            $codeTravel = null === $code ? null : ($travelIndex[$code] ?? null);
            $teamRow = null !== $teamKey && null !== $codeTravel ? ($codeTravel['teams'][$teamKey] ?? null) : null;
            $clubRow = $codeTravel['club'] ?? null;
            $travel = $teamRow ?? $clubRow;
            $scope = null !== $teamRow ? 'TEAM' : (null !== $clubRow ? 'CLUB' : null);
            $opponents[] = $this->opponentView($group['label'], $code, $teamKey, $entry, $travel, $scope);
        }
        usort($opponents, static fn (array $a, array $b): int => strcasecmp((string) $a['opponentLabel'], (string) $b['opponentLabel']));

        return $opponents;
    }

    /**
     * @return array<string, mixed>
     */
    private function opponentView(string $label, ?string $code, ?string $teamKey, ?OpponentDirectoryEntry $entry, ?OpponentTravel $travel, ?string $scope): array
    {
        $hasOverride = $travel instanceof OpponentTravel && $travel->hasOverride();
        $precision = $entry?->getPrecision()?->value;
        // « approché » = ville seulement (calculé SERVEUR, le front ne re-dérive rien).
        // Une surcharge manuelle est un gymnase précis → jamais approché.
        $approximated = !$hasOverride && OpponentLocationPrecision::CITY === $entry?->getPrecision();
        $located = null !== $code && ($entry instanceof OpponentDirectoryEntry || $hasOverride);

        return [
            'opponentOrganismeCode' => $code,
            'opponentTeamKey' => $teamKey,
            'opponentLabel' => $label,
            'located' => $located,
            'precision' => $hasOverride ? OpponentLocationPrecision::VENUE->value : $precision,
            'locationName' => $this->locationName($entry, $travel),
            'city' => $entry?->getCity(),
            'postalCode' => $entry?->getPostalCode(),
            'travelMinutes' => $travel?->getTravelMinutes(),
            'approximated' => $approximated,
            'source' => $travel?->getSource()->value,
            'scope' => $scope,
            'overrideVenueLabel' => $hasOverride ? $travel->getOverrideVenueLabel() : null,
        ];
    }

    private function locationName(?OpponentDirectoryEntry $entry, ?OpponentTravel $travel): ?string
    {
        if ($travel instanceof OpponentTravel && $travel->hasOverride()) {
            return $travel->getOverrideVenueLabel();
        }
        if (!$entry instanceof OpponentDirectoryEntry) {
            return null;
        }

        return OpponentLocationPrecision::VENUE === $entry->getPrecision()
            ? ($entry->getVenueLabel() ?? $entry->getName())
            : $entry->getCity();
    }

    /**
     * The write response for one travel row (manual/auto) — additive `opponentTeamKey`
     * + `scope` (TEAM|CLUB from the row's grain), the rest unchanged.
     *
     * @return array<string, mixed>
     */
    private function travelView(OpponentTravel $row): array
    {
        return [
            'opponentOrganismeCode' => $row->getOpponentOrganismeCode(),
            'opponentTeamKey' => $row->getOpponentTeamKey(),
            'scope' => $row->isTeamScoped() ? 'TEAM' : 'CLUB',
            'travelMinutes' => $row->getTravelMinutes(),
            'source' => $row->getSource()->value,
            'overrideVenueLabel' => $row->hasOverride() ? $row->getOverrideVenueLabel() : null,
        ];
    }

    /**
     * The write response when a TEAM override was reverted (A3 deletion) and nothing
     * governs the team any more — no club row either: « retour à l'automatique, rien
     * de connu ».
     *
     * @return array<string, mixed>
     */
    private function emptyTravelView(string $code, ?string $teamKey): array
    {
        return [
            'opponentOrganismeCode' => $code,
            'opponentTeamKey' => $teamKey,
            'scope' => null,
            'travelMinutes' => null,
            'source' => null,
            'overrideVenueLabel' => null,
        ];
    }

    /**
     * The travel rows of the club+season, indexed for a team → club resolution:
     * per opponent organisme code, the club default row (teamKey NULL) and the
     * per-team override rows.
     *
     * @return array<string, array{club: OpponentTravel|null, teams: array<string, OpponentTravel>}>
     */
    private function indexTravel(string $seasonId): array
    {
        $map = [];
        foreach ($this->travelRepository->findBySeason($seasonId) as $row) {
            $code = $row->getOpponentOrganismeCode();
            $map[$code] ??= ['club' => null, 'teams' => []];
            $teamKey = $row->getOpponentTeamKey();
            if (null === $teamKey) {
                $map[$code]['club'] = $row;
            } else {
                $map[$code]['teams'][$teamKey] = $row;
            }
        }

        return $map;
    }

    /**
     * True when `(code, teamKey)` names a real AWAY opponent of the club+season: for a
     * CLUB write (teamKey null) the code must have an away fixture; for a TEAM write the
     * teamKey must equal a stamped fixture's normalized label under that code.
     */
    private function isAwayOpponent(string $seasonId, string $code, ?string $teamKey): bool
    {
        if (null === $teamKey) {
            return \in_array($code, $this->resolver->distinctOpponentCodes($seasonId), true);
        }
        foreach ($this->fixtures->findAwayBySeason($seasonId) as $fixture) {
            $fixtureCode = $fixture->getOpponentOrganismeCode();
            if (null !== $fixtureCode && '' !== $fixtureCode && $fixtureCode === $code
                && $this->labelNormalizer->normalize(trim($fixture->getOpponentLabel())) === $teamKey) {
                return true;
            }
        }

        return false;
    }

    private function cleanTeamKey(mixed $value): ?string
    {
        if (!\is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return '' === $trimmed ? null : mb_substr($trimmed, 0, 180);
    }

    /** @return 'TEAM'|'CLUB'|null */
    private function cleanScope(mixed $value): ?string
    {
        return \in_array($value, ['TEAM', 'CLUB'], true) ? $value : null;
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

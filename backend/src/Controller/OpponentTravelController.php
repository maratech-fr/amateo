<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Fixture;
use App\Entity\OpponentDirectoryEntry;
use App\Entity\OpponentTravel;
use App\Entity\Season;
use App\Entity\User;
use App\Enum\OpponentLocationPrecision;
use App\Repository\FixtureRepository;
use App\Repository\OpponentDirectoryEntryRepository;
use App\Repository\OpponentTravelRepository;
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
        private readonly OpponentTravelRepository $travelRepository,
        private readonly OpponentDirectoryEntryRepository $directory,
        private readonly ManagementAccessGuard $managementAccessGuard,
        private readonly OpponentTravelResolver $resolver,
        private readonly RateLimiterFactory $opponentTravelResolveLimiter,
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
        $travelByCode = $this->indexTravel($season->getId());

        return $this->json([
            'clubId' => $clubId,
            'seasonId' => $season->getId(),
            'opponents' => $this->buildOpponents($awayFixtures, $travelByCode),
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

        if (null === $code || '' === $label || null === $lat || null === $lon) {
            return $this->json(['error' => 'Adversaire ou gymnase invalide.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if (!\in_array($code, $this->resolver->distinctOpponentCodes($season->getId()), true)) {
            return $this->json(['error' => 'Cet adversaire n\'a aucune rencontre à l\'extérieur cette saison.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $row = $this->resolver->applyManualOverride($clubId, $season->getId(), $code, $ref, mb_substr($label, 0, 180), $lat, $lon);

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
        $code = $this->cleanCode(\is_array($payload) ? ($payload['opponentOrganismeCode'] ?? null) : null);
        if (null === $code) {
            return $this->json(['error' => 'Adversaire invalide.'], Response::HTTP_UNPROCESSABLE_ENTITY);
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
     * Group the AWAY fixtures by opponent (organisme code when stamped, else the
     * normalized label) and shape each one for display.
     *
     * @param list<Fixture>                 $awayFixtures
     * @param array<string, OpponentTravel> $travelByCode
     *
     * @return list<array<string, mixed>>
     */
    private function buildOpponents(array $awayFixtures, array $travelByCode): array
    {
        /** @var array<string, array{code: string|null, label: string}> $groups */
        $groups = [];
        foreach ($awayFixtures as $fixture) {
            $code = $fixture->getOpponentOrganismeCode();
            $label = trim($fixture->getOpponentLabel());
            $key = null !== $code && '' !== $code ? 'code:' . $code : 'label:' . mb_strtolower($label);
            if (!isset($groups[$key])) {
                $groups[$key] = ['code' => null !== $code && '' !== $code ? $code : null, 'label' => $label];
            }
        }

        $opponents = [];
        foreach ($groups as $group) {
            $code = $group['code'];
            $entry = null === $code ? null : $this->directory->findOneByFfbbOrganismeCode($code);
            $travel = null === $code ? null : ($travelByCode[$code] ?? null);
            $opponents[] = $this->opponentView($group['label'], $code, $entry, $travel);
        }
        usort($opponents, static fn (array $a, array $b): int => strcasecmp((string) $a['opponentLabel'], (string) $b['opponentLabel']));

        return $opponents;
    }

    /**
     * @return array<string, mixed>
     */
    private function opponentView(string $label, ?string $code, ?OpponentDirectoryEntry $entry, ?OpponentTravel $travel): array
    {
        $hasOverride = $travel instanceof OpponentTravel && $travel->hasOverride();
        $precision = $entry?->getPrecision()?->value;
        // « approché » = ville seulement (calculé SERVEUR, le front ne re-dérive rien).
        // Une surcharge manuelle est un gymnase précis → jamais approché.
        $approximated = !$hasOverride && OpponentLocationPrecision::CITY === $entry?->getPrecision();
        $located = null !== $code && ($entry instanceof OpponentDirectoryEntry || $hasOverride);

        return [
            'opponentOrganismeCode' => $code,
            'opponentLabel' => $label,
            'located' => $located,
            'precision' => $hasOverride ? OpponentLocationPrecision::VENUE->value : $precision,
            'locationName' => $this->locationName($entry, $travel),
            'travelMinutes' => $travel?->getTravelMinutes(),
            'approximated' => $approximated,
            'source' => $travel?->getSource()->value,
            'overrideVenueLabel' => $travel instanceof OpponentTravel && $travel->hasOverride() ? $travel->getOverrideVenueLabel() : null,
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
     * @return array<string, mixed>
     */
    private function travelView(OpponentTravel $row): array
    {
        return [
            'opponentOrganismeCode' => $row->getOpponentOrganismeCode(),
            'travelMinutes' => $row->getTravelMinutes(),
            'source' => $row->getSource()->value,
            'overrideVenueLabel' => $row->hasOverride() ? $row->getOverrideVenueLabel() : null,
        ];
    }

    /**
     * @return array<string, OpponentTravel> keyed by opponent organisme code
     */
    private function indexTravel(string $seasonId): array
    {
        $map = [];
        foreach ($this->travelRepository->findBySeason($seasonId) as $row) {
            $map[$row->getOpponentOrganismeCode()] = $row;
        }

        return $map;
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

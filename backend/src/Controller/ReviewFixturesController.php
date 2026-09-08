<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Fixture;
use App\Service\ManagementAccessGuard;
use App\Service\SeasonAccessGuard;
use App\Service\SocleGuard;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * POST /api/fixtures/review — « traiter » des rencontres importées (PR-3a, espace
 * Importer). Deux gestes, exactement un par appel :
 *   - `fixtureIds` (geste LIGNE) : chaque rencontre est traitée, écarts pendants
 *     vidés (« garder l'app » implicite — D4) → REVIEWED.
 *   - `teamId` (geste MASSE) : toutes les rencontres de l'équipe sont traitées,
 *     SAUF celles à écarts pendants, SAUTÉES et nommées (on ne tranche pas un
 *     écart en masse — D4).
 *
 * Patron PlaceMatchesController : management + saison écrivable + socle pointé.
 * Le scope tenant/saison est automatique (filtres Doctrine) — une rencontre ou une
 * équipe d'un autre club est invisible, donc ignorée (jamais d'écriture cross-club).
 */
#[AsController]
final class ReviewFixturesController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ManagementAccessGuard $managementAccessGuard,
        private readonly SeasonAccessGuard $seasonAccessGuard,
        private readonly SocleGuard $socleGuard,
        private readonly ClockInterface $clock,
    ) {}

    // priority > 0: the static path must win over API Platform's /api/fixtures/{id}.
    #[Route('/api/fixtures/review', name: 'api_fixtures_review', methods: ['POST'], priority: 10)]
    public function __invoke(Request $request): JsonResponse
    {
        // SEC-07 first so 403 wins over the 409s (import idiom).
        $this->managementAccessGuard->assertManager();
        $this->seasonAccessGuard->assertWritable($request);
        $this->socleGuard->assertSeasonPlanChosen($request->attributes->get('_season_id') ?? $request->headers->get('X-Season-Id'));

        $body = json_decode($request->getContent(), true);
        if (!\is_array($body)) {
            return $this->json(['error' => 'Corps JSON attendu.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $fixtureIds = $this->stringList($body['fixtureIds'] ?? null);
        $teamId = \is_string($body['teamId'] ?? null) && '' !== $body['teamId'] ? $body['teamId'] : null;

        // Exactement un des deux gestes.
        if ((null !== $fixtureIds) === (null !== $teamId)) {
            return $this->json(
                ['error' => 'Fournissez soit une liste de rencontres, soit une équipe — pas les deux, pas aucune.'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $now = DateTimeImmutable::createFromInterface($this->clock->now());
        $repository = $this->entityManager->getRepository(Fixture::class);

        $reviewed = 0;
        /** @var list<array{fixtureId: string, reason: string}> $skipped */
        $skipped = [];

        if (null !== $fixtureIds) {
            // Geste LIGNE : traiter chaque rencontre, écarts vidés (garder l'app implicite).
            foreach ($repository->findBy(['id' => $fixtureIds]) as $fixture) {
                $fixture->clearPendingDeviations();
                $fixture->markReviewed($now);
                ++$reviewed;
            }
        } else {
            // Geste MASSE : traiter l'équipe, sauter les rencontres à écarts pendants.
            foreach ($repository->findBy(['teamId' => $teamId]) as $fixture) {
                if ($fixture->hasPendingDeviations()) {
                    $skipped[] = ['fixtureId' => $fixture->getId(), 'reason' => 'pending_deviations'];

                    continue;
                }
                $fixture->markReviewed($now);
                ++$reviewed;
            }
        }

        $this->entityManager->flush();

        return $this->json(['reviewed' => $reviewed, 'skipped' => $skipped]);
    }

    /**
     * A non-empty list of non-empty strings, or null when the field is absent /
     * not a usable list (a forged value can never touch another club — the query
     * is tenant+season filtered anyway).
     *
     * @return list<string>|null
     */
    private function stringList(mixed $value): ?array
    {
        if (!\is_array($value) || !array_is_list($value)) {
            return null;
        }
        $ids = [];
        foreach ($value as $entry) {
            if (\is_string($entry) && '' !== $entry) {
                $ids[] = $entry;
            }
        }

        return [] === $ids ? null : $ids;
    }
}

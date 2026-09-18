<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\FbiCorrection;
use App\Entity\Season;
use App\Enum\FbiCorrectionCloseSource;
use App\Repository\FbiCorrectionRepository;
use App\Service\FbiCorrectionLedger;
use App\Service\ManagementAccessGuard;
use App\Service\SeasonAccessGuard;
use App\Service\SeasonResolver;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Le registre « à corriger dans FBI » : ce que le gestionnaire a gardé côté appli et
 * doit reporter à la main dans FBI. Trois routes custom (patron
 * {@see FixtureConflictsController}) :
 *   - GET  /api/fixtures/fbi-corrections            (lecture MEMBRE : entrées OUVERTES du club+saison)
 *   - POST /api/fixtures/fbi-corrections/{id}/close (GESTIONNAIRE + saison écrivable : « corrigé dans FBI »)
 *   - POST /api/fixtures/fbi-corrections/{id}/reopen (annulation d'un « corrigé » MANUEL récent, < 24 h).
 *
 * Une entrée d'un autre club est invisible (filtres tenant) → 404 byte-identique.
 */
final class FbiCorrectionController extends AbstractController
{
    use ResolvesCurrentClubTrait;

    private const string UUID_REQUIREMENT = '[0-9a-fA-F-]{36}';

    /** Au-delà de ce délai, un « corrigé dans FBI » manuel ne s'annule plus (409). */
    private const int REOPEN_GRACE_HOURS = 24;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RequestStack $requestStack,
        private readonly SeasonResolver $seasonResolver,
        private readonly FbiCorrectionRepository $repository,
        private readonly FbiCorrectionLedger $ledger,
        private readonly ManagementAccessGuard $managementAccessGuard,
        private readonly SeasonAccessGuard $seasonAccessGuard,
        private readonly ClockInterface $clock,
    ) {}

    // priority > 0: the static path must win over API Platform's /api/fixtures/{id}.
    #[Route('/api/fixtures/fbi-corrections', name: 'api_fixture_fbi_corrections', methods: ['GET'], priority: 10)]
    public function list(): JsonResponse
    {
        $clubId = $this->resolveCurrentClubId($this->requestStack);
        if (null === $clubId) {
            return $this->json(['error' => 'No club in context.'], Response::HTTP_BAD_REQUEST);
        }

        $season = $this->seasonResolver->selectedOrCurrent($this->requestStack->getCurrentRequest(), $clubId);
        $entries = $season instanceof Season ? $this->repository->findOpenBySeason($season->getId()) : [];

        // Ordre stable par « décidé le » ; le front trie sur la date de match (qu'il
        // possède déjà) — le registre ne joint pas les fixtures.
        usort($entries, static fn (FbiCorrection $a, FbiCorrection $b): int => [$a->getDecidedAt(), $a->getId()] <=> [$b->getDecidedAt(), $b->getId()]);

        return $this->json([
            'corrections' => array_map($this->view(...), $entries),
        ]);
    }

    #[Route('/api/fixtures/fbi-corrections/{id}/close', name: 'api_fixture_fbi_correction_close', requirements: ['id' => self::UUID_REQUIREMENT], methods: ['POST'], priority: 10)]
    public function close(Request $request, string $id): JsonResponse
    {
        $this->managementAccessGuard->assertManager(); // SEC-07 first, so 403 wins.
        $this->seasonAccessGuard->assertWritable($request);

        // Tenant/season filtered — une entrée étrangère est invisible → 404.
        $entry = $this->repository->findOneBy(['id' => $id]);
        if (!$entry instanceof FbiCorrection || !$entry->isOpen()) {
            return $this->json(['error' => 'Correction introuvable.'], Response::HTTP_NOT_FOUND);
        }

        $this->ledger->closeManually($entry, DateTimeImmutable::createFromInterface($this->clock->now()));
        $this->entityManager->flush();

        return $this->json($this->view($entry));
    }

    #[Route('/api/fixtures/fbi-corrections/{id}/reopen', name: 'api_fixture_fbi_correction_reopen', requirements: ['id' => self::UUID_REQUIREMENT], methods: ['POST'], priority: 10)]
    public function reopen(Request $request, string $id): JsonResponse
    {
        $this->managementAccessGuard->assertManager();
        $this->seasonAccessGuard->assertWritable($request);

        $entry = $this->repository->findOneBy(['id' => $id]);
        if (!$entry instanceof FbiCorrection) {
            return $this->json(['error' => 'Correction introuvable.'], Response::HTTP_NOT_FOUND);
        }

        // On n'annule QUE le geste du gestionnaire (fermeture MANUELLE) et seulement s'il
        // est récent : une entrée déjà ouverte, fermée par un dépôt, ou cochée il y a plus
        // de 24 h ne se rouvre pas (409, jamais 500 ni un état incohérent).
        $closedAt = $entry->getClosedAt();
        if ($entry->isOpen()
            || FbiCorrectionCloseSource::MANUAL !== $entry->getClosedBy()
            || null === $closedAt
            || $closedAt < DateTimeImmutable::createFromInterface($this->clock->now())->modify(\sprintf('-%d hours', self::REOPEN_GRACE_HOURS))) {
            return $this->json(['error' => 'Cette correction ne peut plus être rouverte.'], Response::HTTP_CONFLICT);
        }

        $entry->setClosedAt(null);
        $entry->setClosedBy(null);
        $this->entityManager->flush();

        return $this->json($this->view($entry));
    }

    /**
     * @return array{id: string, fixtureId: string, field: string, appValue: string|null, fbiValue: string|null, venueFbiLabel: string|null, decidedAt: string, lastSeenInFbiAt: string|null}
     */
    private function view(FbiCorrection $entry): array
    {
        return [
            'id' => $entry->getId(),
            'fixtureId' => $entry->getFixtureId(),
            'field' => $entry->getField()->value,
            'appValue' => $entry->getAppValue(),
            'fbiValue' => $entry->getFbiValue(),
            'venueFbiLabel' => $entry->getVenueFbiLabel(),
            'decidedAt' => $entry->getDecidedAt()->format(DateTimeInterface::ATOM),
            'lastSeenInFbiAt' => $entry->getLastSeenInFbiAt()?->format(DateTimeInterface::ATOM),
        ];
    }
}

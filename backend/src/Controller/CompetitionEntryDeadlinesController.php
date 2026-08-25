<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Competition;
use App\Entity\SharedCompetitionDeadline;
use App\Repository\SharedCompetitionDeadlineRepository;
use App\Service\ManagementAccessGuard;
use App\Service\SeasonAccessGuard;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Bulk entry-deadline write for the match module (RMM-6). The league/committee
 * mails the manager several concurrent deadlines by group of teams (region on
 * Sept 2, department on the 10th…), so the granularity is the competition, never
 * a single club-wide date — the manager selects a set of competitions and stamps
 * ONE deadline (or clears it) on all of them.
 *
 * THE single home of the community upsert: when the club sets/updates a deadline
 * on a competition it has PAIRED to the federation (ffbbCompetitionId non null),
 * the same value is written to shared_competition_deadline (last write wins), so
 * the next club engaged in the same federation competition inherits it as an
 * overridable default. Clearing the club value (deadline: null) does NOT erase
 * the shared row — a club's own value stays sovereign, the shared one is only
 * read back when the club has none. An unpaired competition can carry a club
 * value but writes nothing to the shared table.
 *
 * Management-gated (SEC-07) + season writable (archived → 409). Foreign/unknown
 * competition ids → 422 with NOTHING written (the whole gesture is one
 * transaction).
 */
#[AsController]
final class CompetitionEntryDeadlinesController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ManagementAccessGuard $managementAccessGuard,
        private readonly SeasonAccessGuard $seasonAccessGuard,
        private readonly SharedCompetitionDeadlineRepository $sharedDeadlineRepository,
    ) {}

    #[Route('/api/competitions/entry-deadlines', name: 'api_competition_entry_deadlines', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        // SEC-07 first so 403 wins over the 422/409s.
        $this->managementAccessGuard->assertManager();
        $this->seasonAccessGuard->assertWritable($request);

        /** @var mixed $payload */
        $payload = json_decode($request->getContent(), true);
        if (!\is_array($payload)) {
            return $this->json(['error' => 'Corps de requête invalide.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $rawIds = $payload['competitionIds'] ?? null;
        if (!\is_array($rawIds) || [] === $rawIds) {
            return $this->json(['error' => 'Aucune compétition fournie.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $competitionIds = [];
        foreach ($rawIds as $id) {
            if (!\is_string($id) || '' === $id) {
                return $this->json(['error' => 'Identifiant de compétition invalide.'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $competitionIds[$id] = true;
        }
        $competitionIds = array_keys($competitionIds);

        $deadline = $this->parseDeadline($payload['deadline'] ?? null);
        if (false === $deadline) {
            return $this->json(['error' => 'Date d\'échéance invalide (format attendu : AAAA-MM-JJ).'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Tenant + season filters make a foreign/other-season competition invisible:
        // if any requested id is not found, the whole gesture is rejected untouched.
        $repository = $this->entityManager->getRepository(Competition::class);
        /** @var list<Competition> $competitions */
        $competitions = $repository->findBy(['id' => $competitionIds]);
        if (\count($competitions) !== \count($competitionIds)) {
            return $this->json(
                ['error' => 'Une ou plusieurs compétitions sont inconnues pour ce club/cette saison.'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $this->entityManager->wrapInTransaction(function () use ($competitions, $deadline): void {
            foreach ($competitions as $competition) {
                $competition->setEntryDeadline($deadline);

                // Le défaut communautaire ne bouge QUE pour une compétition appariée,
                // et seulement quand on POSE une date (l'effacer n'efface pas le partagé).
                $ffbbCompetitionId = $competition->getFfbbCompetitionId();
                if ($deadline instanceof DateTimeImmutable && null !== $ffbbCompetitionId) {
                    $this->upsertShared($ffbbCompetitionId, $deadline);
                }
            }
        });

        return $this->json([
            'updated' => array_map(static fn (Competition $c): string => $c->getId(), $competitions),
            'deadline' => $deadline?->format('Y-m-d'),
        ]);
    }

    private function upsertShared(string $ffbbCompetitionId, DateTimeImmutable $deadline): void
    {
        $shared = $this->sharedDeadlineRepository->findOneByFfbbCompetitionId($ffbbCompetitionId);
        if ($shared instanceof SharedCompetitionDeadline) {
            $shared->setEntryDeadline($deadline); // last write wins
        } else {
            $this->entityManager->persist(new SharedCompetitionDeadline($ffbbCompetitionId, $deadline));
        }
    }

    /**
     * null → clear the deadline. A Y-m-d string → the parsed date. Anything else
     * (bad type, malformed/rolled-over date) → false = 422.
     */
    private function parseDeadline(mixed $raw): DateTimeImmutable|false|null
    {
        if (null === $raw) {
            return null;
        }
        if (!\is_string($raw)) {
            return false;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
        $errors = DateTimeImmutable::getLastErrors();
        if (!$date instanceof DateTimeImmutable || (\is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return false;
        }

        return $date;
    }
}

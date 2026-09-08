<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Fixture;
use App\Enum\FixtureHomeAway;
use App\Service\FbiFixtureImporter;
use App\Service\ManagementAccessGuard;
use App\Service\SeasonAccessGuard;
use App\Service\SocleGuard;
use DateMalformedStringException;
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
 * POST /api/fixtures/review/deviations — trancher UN écart pendant d'une rencontre
 * (PR-3a). Corps `{fixtureId, field: date|kickoff|venue, choice: keep_app|take_source}`.
 *   - `keep_app`     : on garde la valeur de l'app → l'écart est retiré.
 *   - `take_source`  : on adopte la valeur de la SOURCE — rejouée depuis l'entrée
 *     PERSISTÉE (valeurs serveur, rien de forgeable côté client) via le moteur de
 *     réconciliation partagé ({@see FbiFixtureImporter::applyFieldTakeFile}), puis
 *     l'écart est retiré.
 * Dernier écart retiré → REVIEWED + horodaté.
 *
 * Patron PlaceMatchesController : management + saison écrivable + socle pointé.
 * Une rencontre d'un autre club est invisible (filtres tenant) → 404 byte-identique.
 */
#[AsController]
final class ReviewFixtureDeviationController extends AbstractController
{
    private const FIELDS = ['date', 'kickoff', 'venue'];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ManagementAccessGuard $managementAccessGuard,
        private readonly SeasonAccessGuard $seasonAccessGuard,
        private readonly SocleGuard $socleGuard,
        private readonly FbiFixtureImporter $importer,
        private readonly ClockInterface $clock,
    ) {}

    // priority > 0: the static path must win over API Platform's /api/fixtures/{id}.
    #[Route('/api/fixtures/review/deviations', name: 'api_fixtures_review_deviations', methods: ['POST'], priority: 10)]
    public function __invoke(Request $request): JsonResponse
    {
        $this->managementAccessGuard->assertManager();
        $this->seasonAccessGuard->assertWritable($request);
        $this->socleGuard->assertSeasonPlanChosen($request->attributes->get('_season_id') ?? $request->headers->get('X-Season-Id'));

        $body = json_decode($request->getContent(), true);
        if (!\is_array($body)) {
            return $this->json(['error' => 'Corps JSON attendu.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $fixtureId = \is_string($body['fixtureId'] ?? null) ? $body['fixtureId'] : '';
        $field = \is_string($body['field'] ?? null) ? $body['field'] : '';
        $choice = \is_string($body['choice'] ?? null) ? $body['choice'] : '';
        if ('' === $fixtureId || !\in_array($field, self::FIELDS, true) || !\in_array($choice, ['keep_app', 'take_source'], true)) {
            return $this->json(
                ['error' => 'Requête invalide : {fixtureId, field: date|kickoff|venue, choice: keep_app|take_source} attendu.'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        // Tenant/season filtered — a foreign fixture is invisible → 404.
        $fixture = $this->entityManager->getRepository(Fixture::class)->findOneBy(['id' => $fixtureId]);
        if (!$fixture instanceof Fixture) {
            return $this->json(['error' => 'Rencontre introuvable.'], Response::HTTP_NOT_FOUND);
        }

        $entry = $fixture->getPendingDeviation($field);
        if (null === $entry) {
            return $this->json(['error' => 'Aucun écart en attente sur ce champ.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $now = DateTimeImmutable::createFromInterface($this->clock->now());
        if ('take_source' === $choice) {
            // Rejoue le moteur partagé À PARTIR DE LA VALEUR PERSISTÉE (jamais du client).
            try {
                $row = $this->rowFromEntry($fixture, $field, $entry);
            } catch (DateMalformedStringException) {
                // La valeur persistée vient de l'import (Y-m-d / H:i) ; illisible = donnée
                // corrompue, jamais un 500 (revue sécurité PR-3a).
                return $this->json(['error' => 'La valeur de la source pour cet écart est illisible.'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $this->importer->applyFieldTakeFile($fixture, $field, $row, $now);
        }
        $fixture->removePendingDeviation($field);

        // Dernier écart retiré → la rencontre est traitée (D5).
        if (!$fixture->hasPendingDeviations()) {
            $fixture->markReviewed($now);
        }

        $this->entityManager->flush();

        return $this->json([
            'fixtureId' => $fixture->getId(),
            'reviewState' => $fixture->getReviewState()->value,
            'reviewedAt' => $fixture->getReviewedAt()?->format(DateTimeImmutable::ATOM),
            'pendingDeviations' => $fixture->getPendingDeviations(),
        ]);
    }

    /**
     * Reconstructs the minimal file « row » the shared take_file engine reads, from
     * the PERSISTED source value of the écart (never a client value). Only the
     * target field is fed from the source; the rest mirror the fixture so the row
     * type is satisfied.
     *
     * @param array{field: string, appValue: string|null, sourceValue: string|null, channel: string, seenAt: string, autoApplied: bool} $entry
     *
     * @return array{numero: string, matchDate: DateTimeImmutable, homeAway: FixtureHomeAway, opponentLabel: string, kickoffTime: DateTimeImmutable|null, venueLabel: string|null}
     */
    private function rowFromEntry(Fixture $fixture, string $field, array $entry): array
    {
        $source = $entry['sourceValue'];

        return [
            'numero' => (string) $fixture->getExternalRef(),
            'matchDate' => 'date' === $field && null !== $source
                ? new DateTimeImmutable($source)
                : $fixture->getMatchDate(),
            'homeAway' => $fixture->getHomeAway(),
            'opponentLabel' => $fixture->getOpponentLabel(),
            'kickoffTime' => 'kickoff' === $field
                ? (null !== $source ? new DateTimeImmutable($source) : null)
                : $fixture->getKickoffTime(),
            'venueLabel' => 'venue' === $field ? $source : $fixture->getFbiVenueLabel(),
        ];
    }
}

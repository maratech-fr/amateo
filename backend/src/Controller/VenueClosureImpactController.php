<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\CalendarEntry;
use App\Entity\Season;
use App\Entity\Venue;
use App\Service\SeasonResolver;
use App\Service\VenueClosureImpact;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

/**
 * Ce qu'une fermeture de gymnase touche (chantier « Fermetures de gymnase », C3/C4/C5) — lecture
 * seule, recalculée à chaque appel, LE MÊME calcul ({@see VenueClosureImpact}) pour :
 *  - `GET /api/venue-closure-impact?venueId&startDate&endDate` — l'aperçu du formulaire AVANT
 *    « Enregistrer » (une fermeture pas encore saisie) ;
 *  - `GET /api/calendar-entries/{id}/impact` — l'impact d'une fermeture ENREGISTRÉE (carte, radar,
 *    écran du plan), sa propre famille écartée : même chiffre qu'avant l'enregistrement.
 *
 * Tenant : le gymnase et l'entrée se lisent sous le filtre du club courant — un gymnase ou une
 * entrée d'un autre club est INCONNU (404), jamais un chiffre sur ses données.
 */
final class VenueClosureImpactController extends AbstractController
{
    use ResolvesCurrentClubTrait;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RequestStack $requestStack,
        private readonly SeasonResolver $seasonResolver,
        private readonly VenueClosureImpact $impact,
    ) {}

    #[Route('/api/venue-closure-impact', name: 'api_venue_closure_impact', methods: ['GET'])]
    public function preview(Request $request): JsonResponse
    {
        $clubId = $this->resolveCurrentClubId($this->requestStack);
        if (null === $clubId) {
            return $this->json(['error' => 'No club in context.'], Response::HTTP_BAD_REQUEST);
        }
        $venueId = $request->query->get('venueId');
        $start = $this->parseDate($request->query->get('startDate'));
        $end = $this->parseDate($request->query->get('endDate'));
        if (!\is_string($venueId) || '' === $venueId || !$start instanceof DateTimeImmutable || !$end instanceof DateTimeImmutable) {
            return $this->json(['error' => 'Gymnase et dates (AAAA-MM-JJ) requis.'], Response::HTTP_BAD_REQUEST);
        }
        try {
            $venue = $this->entityManager->getRepository(Venue::class)->find($venueId);
        } catch (Throwable) {
            $venue = null;
        }
        if (!$venue instanceof Venue || $venue->getClubId() !== $clubId) {
            return $this->json(['error' => 'Gymnase introuvable.'], Response::HTTP_NOT_FOUND);
        }
        if ($end < $start) {
            return $this->json(['error' => 'La date de fin précède la date de début.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $season = $this->seasonResolver->selectedOrCurrent($request, $clubId);
        if (!$season instanceof Season) {
            return $this->json(['error' => 'Aucune saison.'], Response::HTTP_NOT_FOUND);
        }

        return $this->json($this->impact->compute([$venueId], $start, $end, $clubId, $season->getId(), null));
    }

    #[Route('/api/calendar-entries/{id}/impact', name: 'api_calendar_entry_impact', methods: ['GET'])]
    public function entry(string $id): JsonResponse
    {
        $clubId = $this->resolveCurrentClubId($this->requestStack);
        if (null === $clubId) {
            return $this->json(['error' => 'No club in context.'], Response::HTTP_BAD_REQUEST);
        }
        try {
            $entry = $this->entityManager->getRepository(CalendarEntry::class)->find($id);
        } catch (Throwable) {
            $entry = null;
        }
        if (!$entry instanceof CalendarEntry) {
            return $this->json(['error' => 'Période introuvable.'], Response::HTTP_NOT_FOUND);
        }
        if ($entry->getClubId() !== $clubId) {
            return $this->json(['error' => 'Accès refusé.'], Response::HTTP_FORBIDDEN);
        }

        return $this->json($this->impact->compute(
            $this->impact->venueIdsOfEntry($entry->getId()),
            $entry->getStartDate(),
            $entry->getEndDate(),
            $clubId,
            (string) $entry->getSeasonId(),
            $entry->getId(),
        ));
    }

    private function parseDate(mixed $value): ?DateTimeImmutable
    {
        if (!\is_string($value) || 1 !== preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return false === $date ? null : $date;
    }
}

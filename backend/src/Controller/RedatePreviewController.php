<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\CalendarEntry;
use App\Service\CalendarEntryRedatability;
use App\Service\ManagementAccessGuard;
use App\Service\SplitMotherRedatePlanner;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

/**
 * D3 v2 (P4-174, décision fondateur 2026-09-05) — L'APERÇU des effets du re-datage d'une
 * indisponibilité DÉCOUPÉE : on annonce AVANT de confirmer. LECTURE PURE (aucun persist, aucun
 * flush), management (patron {@see RegenerateController}). Rend la liste d'effets en français
 * (glissement, absorption, disparition, naissance, « les vacances ont la main ») — dates en clair,
 * aucun identifiant interne — et un `token` d'état que le PUT confirmera (recalcul sous verrous ;
 * différent → 409). Le calcul vit dans le foyer UNIQUE {@see SplitMotherRedatePlanner}, partagé
 * avec l'apply.
 *
 * Mêmes 422 de FORME que le PUT : fin avant début, fenêtre hors saison, dates absentes/malformées.
 * 422 aussi si l'entrée n'est PAS une mère découpée (le seul cas où l'aperçu a un sens).
 */
#[AsController]
final class RedatePreviewController extends AbstractController
{
    use ResolvesCurrentClubTrait;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RequestStack $requestStack,
        private readonly ManagementAccessGuard $managementAccessGuard,
        private readonly CalendarEntryRedatability $redatability,
        private readonly SplitMotherRedatePlanner $planner,
    ) {}

    #[Route('/api/calendar_entries/{id}/redate-preview', name: 'api_calendar_entry_redate_preview', methods: ['POST'])]
    public function __invoke(string $id, Request $request): JsonResponse
    {
        $this->managementAccessGuard->assertManager();

        try {
            $entry = $this->entityManager->getRepository(CalendarEntry::class)->find($id);
        } catch (Throwable) {
            $entry = null;
        }
        if (!$entry instanceof CalendarEntry) {
            return $this->json(['error' => 'Période introuvable.'], Response::HTTP_NOT_FOUND);
        }

        $currentClubId = $this->resolveCurrentClubId($this->requestStack);
        if (null !== $currentClubId && $entry->getClubId() !== $currentClubId) {
            return $this->json(['error' => 'Accès refusé.'], Response::HTTP_FORBIDDEN);
        }

        if (!$this->redatability->redateNeedsPreview($entry)) {
            return $this->json(['error' => 'Seule une indisponibilité découpée en semaines a un aperçu de re-datage.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $request->getContent(), true) ?: [];
        $newStart = $this->parseDate($body['startDate'] ?? null);
        $newEnd = $this->parseDate($body['endDate'] ?? null);
        if (!$newStart instanceof DateTimeImmutable || !$newEnd instanceof DateTimeImmutable) {
            return $this->json(['error' => 'Dates manquantes ou mal formées (AAAA-MM-JJ).'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if ($newEnd->format('Y-m-d') < $newStart->format('Y-m-d')) {
            return $this->json(['error' => 'La date de fin doit être postérieure ou égale à la date de début.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if (!$this->withinSeason($entry->getSeasonId(), $newStart, $newEnd)) {
            return $this->json(['error' => 'Ces dates sortent de la saison : une période reste dans la fenêtre de la saison.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $plan = $this->planner->plan($entry, $newStart, $newEnd);
        } catch (UnprocessableEntityHttpException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json(['effects' => $plan->effects, 'token' => $plan->token]);
    }

    private function parseDate(mixed $value): ?DateTimeImmutable
    {
        if (!\is_string($value) || 1 !== preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return false === $date ? null : $date;
    }

    /** La fenêtre reste-t-elle DANS la saison ? SQL brut (season_filter épinglerait la saison active), RLS scope le club. */
    private function withinSeason(string $seasonId, DateTimeImmutable $start, DateTimeImmutable $end): bool
    {
        $row = $this->entityManager->getConnection()->fetchAssociative(
            'SELECT start_date, end_date FROM season WHERE id = :sid',
            ['sid' => $seasonId],
        );
        if (false === $row) {
            return true; // saison introuvable → on ne bloque pas (parité assertWindowWithinSeason)
        }

        return $start->format('Y-m-d') >= mb_substr((string) $row['start_date'], 0, 10)
            && $end->format('Y-m-d') <= mb_substr((string) $row['end_date'], 0, 10);
    }
}

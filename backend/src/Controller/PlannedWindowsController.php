<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\CalendarEntry;
use App\Entity\Season;
use App\Service\PeriodWindowUniquenessGuard;
use App\Service\SchedulePlanProvisioner;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

/**
 * P2-38 (prévention) — LES FENÊTRES DÉJÀ PLANIFIÉES, servies pour que l'écran ne propose plus une
 * semaine dont la création de plan serait refusée en 409 `window_already_planned`.
 *
 * La modale « Quelles semaines ajuster ? » offrait des semaines qu'un AUTRE plan de période gouverne
 * déjà : les cocher heurtait le mur du 409. Décision fondateur : le BACKEND sert le verdict, le front
 * restitue sans redériver. Cette route de LECTURE re-appelable rend, pour une fenêtre visée, les
 * plages qu'un autre plan gouverne déjà — le MÊME prédicat que la garde d'écriture, par construction
 * (le foyer unique {@see PeriodWindowUniquenessGuard::governingWindows}). Le refus 409 reste
 * intégralement en place : ceci AJOUTE la prévention, ne remplace pas la guérison.
 *
 * ── Deux chemins d'appel ─────────────────────────────────────────────────────────────────────────
 *  - `entryId` (la mère est déjà matérialisée) : la saison = celle de l'entrée, la famille exclue =
 *    son ancêtre racine (`COALESCE(parentEntryId, id)`) — la mère et ses semaines sœurs sont
 *    légitimes, jamais servies ni refusées.
 *  - `seasonId` (« mère pas encore créée ») : aucune famille à exclure (racine `null`), la fenêtre
 *    candidate voit TOUS les plans de période de la saison qui la recoupent.
 *
 * ── LECTURE, ouverte au Membre ─────────────────────────────────────────────────────────────────
 * PAS de garde management (patron {@see SocleDeviationController} et {@see CalendarEntryConflictsController}),
 * AUCUN persist, AUCUN flush.
 *
 * ── Refus nommés ───────────────────────────────────────────────────────────────────────────────
 *  - 400 : aucun club en contexte ;
 *  - 422 : `start`/`end` absents ou malformés (Y-m-d), ou ni `entryId` ni `seasonId` fourni ;
 *  - 404 : `entryId`/`seasonId` inconnu ou d'un autre club — byte-identique, jamais un oracle
 *    d'existence.
 *
 * ⚠ Route SOUS `/api/planned-windows` et non `/api/calendar-entries/...` : cette dernière entrerait
 * en collision avec la route item générée par API Platform pour `CalendarEntry`.
 */
#[AsController]
final class PlannedWindowsController extends AbstractController
{
    use ResolvesCurrentClubTrait;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RequestStack $requestStack,
        private readonly PeriodWindowUniquenessGuard $windowUniquenessGuard,
        private readonly SchedulePlanProvisioner $schedulePlanProvisioner,
    ) {}

    #[Route('/api/planned-windows', name: 'api_planned_windows', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        $request = $this->requestStack->getCurrentRequest();
        $currentClubId = $this->resolveCurrentClubId($this->requestStack);
        if (null === $currentClubId || !$request instanceof Request) {
            return $this->json(['error' => 'Aucun club en contexte.'], Response::HTTP_BAD_REQUEST);
        }

        $start = $this->normalizeDate($request->query->get('start'));
        $end = $this->normalizeDate($request->query->get('end'));
        if (null === $start || null === $end) {
            return $this->json(['error' => 'Les dates de début et de fin sont requises au format AAAA-MM-JJ.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $entryId = $request->query->get('entryId');
        $seasonId = $request->query->get('seasonId');

        if (\is_string($entryId) && '' !== $entryId) {
            $entry = $this->find(CalendarEntry::class, $entryId);
            if (!$entry instanceof CalendarEntry || $entry->getClubId() !== $currentClubId) {
                return $this->json(['error' => 'Période introuvable.'], Response::HTTP_NOT_FOUND);
            }
            $resolvedSeasonId = $entry->getSeasonId();
            $excludedRootEntryId = $entry->getParentEntryId() ?? $entry->getId();
        } elseif (\is_string($seasonId) && '' !== $seasonId) {
            $season = $this->find(Season::class, $seasonId);
            if (!$season instanceof Season || $season->getClubId() !== $currentClubId) {
                return $this->json(['error' => 'Saison introuvable.'], Response::HTTP_NOT_FOUND);
            }
            $resolvedSeasonId = $season->getId();
            $excludedRootEntryId = null; // aucune famille à exclure : la fenêtre candidate n'a pas encore d'entrée.
        } else {
            return $this->json(['error' => 'Précisez la période ou la saison concernée.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $windows = [];
        foreach ($this->windowUniquenessGuard->governingWindows($currentClubId, $resolvedSeasonId, $excludedRootEntryId, $start, $end) as $row) {
            $startDate = new DateTimeImmutable($row['start_date']);
            $endDate = new DateTimeImmutable($row['end_date']);
            $windows[] = [
                'entryId' => $row['entry_id'],
                'title' => $row['entry_title'],
                'startDate' => $startDate->format('Y-m-d'),
                'endDate' => $endDate->format('Y-m-d'),
                // Repère composé SERVEUR, par le MÊME helper que le message du 409 — le front l'affiche tel quel.
                'label' => $this->schedulePlanProvisioner->windowLabel($startDate, $endDate),
            ];
        }

        return $this->json(['windows' => $windows]);
    }

    /**
     * `Y-m-d` STRICT (createFromFormat + aller-retour) : « 2025-13-40 » ou « 2025-1-1 » sont refusés,
     * pas silencieusement corrigés. Rend null si absent/malformé — l'appelant traduit en 422.
     */
    private function normalizeDate(mixed $value): ?string
    {
        if (!\is_string($value) || '' === $value) {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date instanceof DateTimeImmutable && $date->format('Y-m-d') === $value ? $value : null;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T|null
     */
    private function find(string $class, string $id): ?object
    {
        try {
            return $this->entityManager->getRepository($class)->find($id);
        } catch (Throwable) {
            return null; // un id malformé ne doit pas fuir en 500 — il se lit « introuvable ».
        }
    }
}

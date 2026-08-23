<?php

declare(strict_types=1);

namespace App\State\Processor;

use App\ApiResource\CoachWishResource;
use App\Dto\CoachWishInput;
use App\Entity\CalendarEntry;
use App\Entity\Coach;
use App\Entity\CoachWish;
use App\Entity\Team;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
use DateTimeImmutable;

/**
 * Doléance coach (feature #10, lot C1). Écriture MANAGEMENT-ONLY (SEC-07) : une doléance
 * pilote la négociation du plan de vacances, pas un coach lambda.
 *
 * Une doléance est un SOUHAIT, jamais une contrainte : aucun effet solveur, aucun
 * pré-remplissage. Son ancre (entrée MÈRE des vacances + lundi de la semaine) identifie la
 * ligne ; le PUT ne la remappe jamais.
 *
 * @extends AbstractStateProcessor<CoachWish, CoachWishInput, CoachWishResource>
 */
class CoachWishStateProcessor extends AbstractStateProcessor
{
    protected function getEntityClass(): string
    {
        return CoachWish::class;
    }

    protected function requiresManagementRole(): bool
    {
        return true; // SEC-07
    }

    /**
     * @param CoachWishInput $input
     */
    protected function createEntityFromInput(object $input): CoachWish
    {
        $weekStart = $this->parseWeekStart((string) $input->weekStart);
        $this->assertValidAnchor($input, $weekStart);

        // À la CRÉATION la doléance est saisie « au nom d'un coach » : coachId requis ici
        // (le DTO l'a rendu nullable pour l'ÉDITION d'une doléance dé-attribuée — revue #10 C1).
        if (null === $input->coachId) {
            $this->refuse('Une doléance se saisit au nom d’un coach.');
        }

        // Une seule doléance par (période, équipe, semaine) — l'index unique remonterait
        // sinon en 500 sur un double-submit ; on rend un 422 propre (l'édition passe par PUT).
        if (null !== $this->entityManager->getRepository(CoachWish::class)->findOneBy([
            'calendarEntryId' => $input->calendarEntryId,
            'teamId' => $input->teamId,
            'weekStart' => $weekStart,
        ])) {
            $this->refuse('Une doléance existe déjà pour cette équipe et cette semaine — modifiez-la.');
        }

        $entity = new CoachWish;
        $entity->setCalendarEntryId((string) $input->calendarEntryId);
        $entity->setWeekStart($weekStart);
        $entity->setTeamId((string) $input->teamId);
        $this->applyEditableFields($entity, $input);

        return $entity;
    }

    /**
     * @param CoachWish      $entity
     * @param CoachWishInput $input
     */
    protected function updateEntityFromInput(object $entity, object $input): void
    {
        // calendarEntryId + teamId + weekStart identifient la ligne — jamais remappés.
        $this->applyEditableFields($entity, $input);
        // PUT défensif : un coachId qui ne désigne plus aucun coach (supprimé entre-temps,
        // cache client périmé re-poussé par un toggle) → DÉ-ATTRIBUTION, jamais une
        // résurrection d'un coach mort (revue #10 C1 round 2).
        if (null !== $input->coachId && null === $this->entityManager->getRepository(Coach::class)->find($input->coachId)) {
            $entity->setCoachId(null);
        }
    }

    /**
     * @param CoachWish $entity
     */
    protected function mapEntityToOutput(object $entity): CoachWishResource
    {
        return CoachWishResource::fromEntity($entity);
    }

    private function applyEditableFields(CoachWish $entity, CoachWishInput $input): void
    {
        $entity->setCoachId($input->coachId);
        $entity->setSlotsWanted($input->slotsWanted ?? 0);
        $entity->setUnavailableDays(array_map('intval', $input->unavailableDays));
        $entity->setComment(null === $input->comment || '' === trim($input->comment) ? null : $input->comment);
        $entity->setDone($input->done);
    }

    /**
     * La doléance s'ancre à l'entrée MÈRE des vacances + un lundi de sa fenêtre. Tenant
     * scoppe déjà l'entrée au club (tenant_filter) : introuvable = 422.
     */
    private function assertValidAnchor(CoachWishInput $input, DateTimeImmutable $weekStart): void
    {
        $entry = null === $input->calendarEntryId
            ? null
            : $this->entityManager->getRepository(CalendarEntry::class)->find($input->calendarEntryId);
        if (!$entry instanceof CalendarEntry) {
            $this->refuse('Période introuvable.');
        }
        if (CalendarEntryKind::PERIOD !== $entry->getKind() || CalendarEntryPeriodType::HOLIDAY !== $entry->getPeriodType()) {
            $this->refuse('Les doléances ne concernent que les périodes de vacances.');
        }
        if (null !== $entry->getParentEntryId()) {
            $this->refuse('Adressez la doléance à la période mère, pas à une semaine isolée.');
        }

        if ('1' !== $weekStart->format('N')) {
            $this->refuse('La semaine doit commencer un lundi.');
        }
        // Fenêtre élargie : la semaine (lundi→dimanche) doit INTERSECTER la période — c'est
        // l'ensemble que `weeksCovering` produit côté front (une semaine peut déborder avant
        // le début des vacances tout en les touchant). Comparaison DATE À DATE (weekStart et
        // les bornes sont tous à minuit) : un weekStart ÉGAL à endDate recoupe encore — les
        // ancrer à midi rejetait à tort une semaine que l'UI offre (revue #10 C1).
        $weekEnd = $weekStart->modify('+6 days');
        if ($weekStart > $entry->getEndDate() || $weekEnd < $entry->getStartDate()) {
            $this->refuse('Cette semaine ne recoupe pas la période de vacances.');
        }

        if (null === $this->entityManager->getRepository(Team::class)->find($input->teamId)) {
            $this->refuse('Équipe introuvable.');
        }
        // coachId est optionnel côté DTO (édition d'une doléance dé-attribuée) ; à la
        // création il est déjà exigé plus haut. Ne vérifier l'existence que s'il est fourni.
        if (null !== $input->coachId && null === $this->entityManager->getRepository(Coach::class)->find($input->coachId)) {
            $this->refuse('Coach introuvable.');
        }
    }

    private function parseWeekStart(string $value): DateTimeImmutable
    {
        // À MINUIT, comme les dates d'une CalendarEntry (date_immutable) : la comparaison de
        // fenêtre reste date-à-date, symétrique — sans dérive de jour ni décalage d'heure.
        return new DateTimeImmutable($value . ' 00:00:00');
    }
}

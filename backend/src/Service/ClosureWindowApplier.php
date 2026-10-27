<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CalendarEntry;
use App\Entity\Constraint;
use App\Entity\SchedulePlan;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\CalendarEntryStatus;
use App\State\Processor\CalendarEntryStateProcessor;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * L'APPLICATION (écriture) d'une modification de fenêtre de FERMETURE, calculée par
 * {@see ClosureWindowPlanner} (l'aperçu == l'appliqué). Appelé SOUS les verrous `lockClubWindows`
 * puis `lockPlanScope` et DANS la transaction pris par le contrôleur.
 *
 * Deux régimes, dictés par le plan :
 *  - `simpleRedate` : une seule fenêtre résultante → le plan « d'un bloc » rétrécit/glisse (la
 *    fenêtre de l'entrée racine et du plan suivent, la contrainte datée suit, la version survit) ;
 *  - sinon : conversion NON DESTRUCTIVE racine-bloc → mère découpée. La racine devient une mère ;
 *    le PREMIER segment reçoit un enfant sur lequel le plan existant est RÉ-ANCRÉ (version pointée
 *    intacte) ; chaque autre segment naît en enfant+plan, rempli en COPIE validée du milieu
 *    ({@see PeriodPlanSiblingCopier}). La contrainte datée court jusqu'au nouveau bout, son début figé.
 */
final class ClosureWindowApplier
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SchedulePlanProvisioner $schedulePlanProvisioner,
        private readonly PeriodPlanSiblingCopier $siblingCopier,
    ) {}

    public function apply(SchedulePlan $plan, ClosureWindowPlan $computed): void
    {
        $root = $this->entityManager->getRepository(CalendarEntry::class)->find($computed->rootEntryId);
        if (!$root instanceof CalendarEntry) {
            throw new UnprocessableEntityHttpException('La fermeture a disparu pendant sa mise à jour.');
        }
        $newStart = new DateTimeImmutable($computed->newStart);
        $newEnd = new DateTimeImmutable($computed->newEnd);

        if ($computed->simpleRedate) {
            $root->setStartDate($newStart);
            $root->setEndDate($newEnd);
            $this->schedulePlanProvisioner->resyncPeriodPlanWindow($computed->rootEntryId, $newStart, $newEnd);
            $this->extendDatedConstraints($computed);
            $this->recaleTitleSuffix($root, $computed);
            $this->entityManager->flush();

            return;
        }

        // Conversion : la racine devient une mère couvrant toute la nouvelle fenêtre.
        $root->setStartDate($newStart);
        $root->setEndDate($newEnd);
        $this->entityManager->flush();

        // 1. Le MILIEU : un enfant sur le premier segment, le plan existant RÉ-ANCRÉ dessus (version intacte).
        $convert = $computed->convertSegment;
        if (null === $convert) {
            throw new UnprocessableEntityHttpException('Segment de reprise manquant.');
        }
        $middleChild = $this->createChild($root, $convert['startDate'], $convert['endDate']);
        $this->entityManager->flush();
        $this->schedulePlanProvisioner->reanchorPeriodPlan(
            $plan->getId(),
            $middleChild->getId(),
            new DateTimeImmutable($convert['startDate']),
            new DateTimeImmutable($convert['endDate']),
        );

        // 2. Les autres runs : enfant + plan neufs, remplis en COPIE validée du milieu (vide si non pointé).
        foreach ($computed->copySegments as $segment) {
            $child = $this->createChild($root, $segment['startDate'], $segment['endDate']);
            $this->entityManager->flush();
            $this->schedulePlanProvisioner->provisionPeriodPlan($child->getId());
            $this->siblingCopier->copyInto($plan->getId(), $child->getId());
        }

        // 3. La contrainte datée court jusqu'au nouveau bout (son début figé = plancher).
        $this->extendDatedConstraints($computed);
        $this->recaleTitleSuffix($root, $computed);
        $this->entityManager->flush();
    }

    private function createChild(CalendarEntry $mother, string $start, string $end): CalendarEntry
    {
        $startDate = new DateTimeImmutable($start);
        $endDate = new DateTimeImmutable($end);

        $child = new CalendarEntry;
        $child->setClubId($mother->getClubId());
        $child->setSeasonId($mother->getSeasonId());
        $child->setKind(CalendarEntryKind::PERIOD);
        $child->setPeriodType(CalendarEntryPeriodType::CLOSURE);
        // La copie doit nommer sa fermeture dans « Tous les plannings » : « {fermeture} — {fenêtre} ».
        $label = $this->schedulePlanProvisioner->windowLabel($startDate, $endDate);
        $motherTitle = $mother->getTitle();
        $base = preg_replace('/\s+—\s+du\s.+$/u', '', $motherTitle) ?? $motherTitle;
        $suffix = ' — ' . $label;
        $base = mb_substr($base, 0, max(0, 180 - mb_strlen($suffix)));
        $child->setTitle($base . $suffix);
        $child->setStartDate($startDate);
        $child->setEndDate($endDate);
        $child->setIsDisruptive(false);
        $child->setParentEntryId($mother->getId());
        $child->setStatus(CalendarEntryStatus::ACTIVE);
        $this->entityManager->persist($child);

        return $child;
    }

    /**
     * Les contraintes datées de la racine dont le COUPLE config.startDate/endDate == l'ANCIENNE
     * fenêtre (le venue_closed né du même geste) courent jusqu'au nouveau bout — début figé.
     * Miroir de {@see CalendarEntryStateProcessor} (redateEntryPairedConstraints).
     * Per-row (jamais un UPDATE DQL en masse : `constraint` est un mot réservé, alias non quoté).
     */
    private function extendDatedConstraints(ClosureWindowPlan $computed): void
    {
        foreach ($this->entityManager->getRepository(Constraint::class)->findBy(['calendarEntryId' => $computed->rootEntryId]) as $constraint) {
            $config = $constraint->getConfig();
            if (($config['startDate'] ?? null) === $computed->oldStart && ($config['endDate'] ?? null) === $computed->oldEnd) {
                $config['startDate'] = $computed->newStart;
                $config['endDate'] = $computed->newEnd;
                $constraint->setConfig($config);
            }
        }
    }

    /**
     * Recale le suffixe de fenêtre du titre de la racine (« — du … au … »), s'il y était ; sinon le
     * titre reste souverain (inv. 12). No-op quand les deux suffixes coïncident.
     */
    private function recaleTitleSuffix(CalendarEntry $root, ClosureWindowPlan $computed): void
    {
        $oldSuffix = $this->schedulePlanProvisioner->windowLabel(new DateTimeImmutable($computed->oldStart), new DateTimeImmutable($computed->oldEnd));
        $newSuffix = $this->schedulePlanProvisioner->windowLabel(new DateTimeImmutable($computed->newStart), new DateTimeImmutable($computed->newEnd));
        if ($oldSuffix === $newSuffix) {
            return;
        }
        $title = $root->getTitle();
        if (str_ends_with($title, $oldSuffix)) {
            $root->setTitle(substr($title, 0, -\strlen($oldSuffix)) . $newSuffix);
        }
    }
}

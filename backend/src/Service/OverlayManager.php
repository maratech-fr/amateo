<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CalendarEntry;
use App\Entity\ConstraintConflict;
use App\Entity\ConstraintPeriodOverride;
use App\Entity\ImplicitRuleSetting;
use App\Entity\Reservation;
use App\Entity\Schedule;
use App\Entity\ScheduleDiagnostic;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\ScheduleStructureSnapshot;
use App\Entity\SharedTrainingBlock;
use App\Entity\SharedTrainingBlockTeam;
use App\Entity\TeamPeriodOverride;
use App\Entity\VenuePeriodOverride;
use App\Entity\VenueTrainingSlot;
use App\Enum\ScheduleStatus;
use App\Repository\CalendarEntryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Centralises overlay-schedule deletion (palier B). An overlay schedule's slots
 * and diagnostics are plain guid columns with NO FK cascade — removing the
 * schedule alone would orphan them, so every deletion path goes through here.
 *
 * The period entry and its dated constraints are NOT removed: after its overlay
 * is deleted the period falls back to "signalée, non adaptée" (spec §6).
 */
final class OverlayManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SchedulePlanProvisioner $schedulePlanProvisioner,
        private readonly CalendarEntryRepository $calendarEntryRepository,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * Les périodes de la saison dont le planning serait invalidé si le calendrier de
     * base bougeait — la portée de la destruction, et donc de la confirmation.
     *
     * « Le planning de saison est notre base, donc on supprime TOUS les plannings
     * overlay ou holidays qui sont à venir » (décision fondateur 2026-07-24) :
     *  - toute période PORTANT UN PLAN, validée ou non. Depuis #8 le plan naît du geste
     *    « Adapter » et possède déjà sa grille (copie du modèle de saison) : ne compter
     *    que les périodes validées laissait derrière des grilles copiées d'un socle qui
     *    n'existe plus, invisibles pour le gestionnaire ;
     *  - sauf celles DÉJÀ COMMENCÉES : « rien du passé, rien de ce qui est en cours »
     *    (décision fondateur 2026-07-16, specs/evolution/reprise-perimetre-engage.md §4).
     *    Une période en cours est déjà annoncée aux coachs et à moitié jouée ; la
     *    détruire au milieu coûterait plus que de la laisser finir sur l'ancien socle.
     * Le pivot est donc la date de DÉBUT : seules les périodes ENTIÈREMENT à venir.
     *
     * @return list<CalendarEntry>
     */
    public function periodPlansInvalidatedBySeasonChange(string $clubId, string $seasonId): array
    {
        return $this->calendarEntryRepository->findWithPlanNotStarted($clubId, $seasonId, $this->clock->now());
    }

    /**
     * Détruit le planning d'une période DE BOUT EN BOUT — ses versions, son plan, et
     * les réglages ancrés à ce plan (grille copiée, réservations, modes gymnase,
     * overrides d'équipes et de contraintes). L'ENTRÉE de calendrier survit : la
     * période reste au calendrier et retombe « à traiter » au radar, le gestionnaire
     * la refait quand il veut.
     *
     * Ne supprimer que les versions (ce que fait deleteOverlayForEntry) laissait le
     * plan et sa grille : « je supprime les plannings et donc les versions liées »
     * (décision fondateur 2026-07-24).
     */
    public function deletePeriodPlanForEntry(CalendarEntry $entry, bool $force = false): void
    {
        // AVANT toute lecture qui décide : deleteOverlayForEntry balaie les versions
        // juste en dessous, et le prendre après ne sérialiserait rien (même idiome que
        // la suppression d'une période).
        $this->schedulePlanProvisioner->lockPlanScope($entry->getId());
        $planId = $this->schedulePlanProvisioner->periodPlanId($entry->getId());
        $this->deleteOverlayForEntry($entry, $force);
        if (null === $planId) {
            return;
        }

        $this->entityManager->wrapInTransaction(function () use ($entry, $planId): void {
            $this->schedulePlanProvisioner->deletePeriodPlan($entry->getId());
            $this->purgePlanAnchoredSettings($planId);
            $this->entityManager->flush();
        });
    }

    /**
     * Les réglages ancrés à un plan de période (inv. 5, lots C2-C3) : overrides
     * d'équipes et de contraintes, créneaux du plan, réservations, modes gymnase, copie des
     * règles implicites bien-être. Partagé par la suppression d'une période, la découpe en
     * semaines (qui emporte le plan-bloc de la mère) et la reprise du socle. L'appelant flush.
     */
    public function purgePlanAnchoredSettings(string $schedulePlanId): void
    {
        // P2-51 — SharedTrainingBlockTeam (membres) avant SharedTrainingBlock (parent) : les deux
        // portent schedulePlanId (dénormalisé côté membre), la déclaration de période part avec le plan.
        // ImplicitRuleSetting : la copie des 4 règles bien-être matérialisée à la naissance du plan.
        foreach ([TeamPeriodOverride::class, ConstraintPeriodOverride::class, VenueTrainingSlot::class, Reservation::class, VenuePeriodOverride::class, SharedTrainingBlockTeam::class, SharedTrainingBlock::class, ImplicitRuleSetting::class] as $class) {
            foreach ($this->entityManager->getRepository($class)->findBy(['schedulePlanId' => $schedulePlanId]) as $row) {
                $this->entityManager->remove($row);
            }
        }
    }

    /**
     * Purge les artefacts d'une version et la supprime. Utilisé par la validation
     * (ADR-0002 inv. 1 : choisir une version supprime ses sœurs). Si la version
     * détruite était celle que pointe son plan, purgeArtifacts (→ releaseSchedule)
     * a déjà relâché le pointeur — plus de pointeur inverse à nettoyer (lot D-b).
     */
    public function deleteVersion(Schedule $schedule): void
    {
        $this->assertNotGenerating($schedule);
        $this->purgeArtifacts($schedule->getId());
        $this->entityManager->remove($schedule);
    }

    /**
     * Delete every overlay version of a period entry. Refuses (409) while an overlay
     * is mid-generation — deleting it out from under the worker would orphan the slots
     * it is about to import — and, unless $force, while its plan POINTS at one (en
     * vigueur = read-only ; the entry-delete path must not bypass the guard DELETE
     * /api/schedules enforces). The destructive reopen passes $force: the user
     * explicitly confirmed destruction.
     */
    /** @return int the number of overlay versions removed */
    public function deleteOverlayForEntry(CalendarEntry $entry, bool $force = false): int
    {
        // planning-versions: a period may hold SEVERAL overlay versions — delete
        // them ALL. Guard every version first so a refusal never leaves the period
        // half-cleared. ADR-0002 : les versions d'une période sont celles de SON PLAN
        // (schedulePlanId via periodPlanId). Une entrée sans plan (cutoff/mutualisation,
        // inv. 9) → aucun overlay.
        $overlays = [];
        $planId = $this->schedulePlanProvisioner->periodPlanId($entry->getId());
        if (null !== $planId) {
            foreach ($this->entityManager->getRepository(Schedule::class)->findBy(['schedulePlanId' => $planId]) as $schedule) {
                $overlays[$schedule->getId()] = $schedule;
            }
        }
        foreach ($overlays as $schedule) {
            $this->assertNotGenerating($schedule);
            if (!$force && $this->schedulePlanProvisioner->isChosen($schedule->getId())) {
                throw new ConflictHttpException('Le planning de cette période est en vigueur (lecture seule). Rouvrez-le avant de supprimer la période.');
            }
        }
        // purgeArtifacts (→ releaseSchedule) relâche le pointeur du plan pour la version
        // choisie : plus de pointeur inverse d'entrée à vider (lot D-b).
        $this->entityManager->wrapInTransaction(function () use ($overlays): void {
            foreach ($overlays as $schedule) {
                $this->purgeArtifacts($schedule->getId());
                $this->entityManager->remove($schedule);
            }

            $this->entityManager->flush();
        });

        return \count($overlays);
    }

    /**
     * Purge a schedule's slots + diagnostics + conflicts. Used when a Schedule is
     * deleted directly (DELETE /api/schedules). Si cette version était celle que
     * pointe son plan, purgeArtifacts (→ releaseSchedule) a déjà relâché le pointeur :
     * plus d'auto-promotion ni de pointeur inverse d'entrée (lot D-b — « actif » =
     * plan.chosenScheduleId, dérivé, et supprimer le choisi rend le plan non validé).
     */
    public function purgeScheduleArtifacts(Schedule $schedule): void
    {
        $this->assertNotGenerating($schedule);
        $this->purgeArtifacts($schedule->getId());
    }

    /**
     * Une version en cours de solve ne peut pas être supprimée sous les pieds du worker
     * (il importerait ses créneaux dans le vide). Le message NOMME la période : depuis
     * que la reprise du socle emporte aussi les périodes non validées (#8), ce refus
     * tombe au milieu d'une validation de saison que le gestionnaire vient de confirmer
     * — un « wait for it to finish » anonyme et en anglais ne lui disait pas quoi
     * attendre (revue #8, round 4).
     */
    private function assertNotGenerating(Schedule $schedule): void
    {
        if (\in_array($schedule->getStatus(), [ScheduleStatus::PENDING, ScheduleStatus::GENERATING], true)) {
            throw new ConflictHttpException(\sprintf('Le planning « %s » est en cours de génération : attendez qu\'il se termine avant de reprendre le planning de la saison.', $this->periodLabelOf($schedule)));
        }
    }

    /**
     * P3-18 — le nom que le gestionnaire voit est celui du PLAN (même source que
     * les exports, `displayNameOf`), plus le titre de la CalendarEntry : l'ADR-0002
     * distingue le FAIT (« Gymnase A — fermé ») de la RÉPONSE qu'est le plan, et
     * deux dialogues qui nomment le même planning autrement font deux vérités.
     * (La popup `overlays_exist` de la réouverture, elle, liste volontairement les
     * TITRES de périodes : on y choisit de détruire des périodes, le fait est le
     * bon repère — écart assumé, tracé en décision fermée.).
     */
    private function periodLabelOf(Schedule $schedule): string
    {
        return $this->schedulePlanProvisioner->displayNameOf($schedule);
    }

    private function purgeArtifacts(string $scheduleId): void
    {
        // ADR-0002 lot B1 (ADDITIF) : un pointeur ne doit jamais nommer une version
        // supprimée. Couvre les chemins ORM (DELETE /api/schedules, suppression de
        // période, reopen destructeur). PAS SeasonDataPurger, qui supprime en DQL de
        // masse — mais lui supprime aussi les plans, donc aucun pointeur ne survit.
        $this->schedulePlanProvisioner->releaseSchedule($scheduleId);

        // Per-row remove (not bulk DQL): keeps UnitOfWork + RLS consistent, same
        // reason CalendarEntryStateProcessor avoids bulk DELETE on `constraint`.
        foreach ($this->entityManager->getRepository(ScheduleSlotTemplate::class)->findBy(['scheduleId' => $scheduleId]) as $slot) {
            $this->entityManager->remove($slot);
        }
        foreach ($this->entityManager->getRepository(ScheduleDiagnostic::class)->findBy(['scheduleId' => $scheduleId]) as $diagnostic) {
            $this->entityManager->remove($diagnostic);
        }
        foreach ($this->entityManager->getRepository(ConstraintConflict::class)->findBy(['scheduleId' => $scheduleId]) as $conflict) {
            $this->entityManager->remove($conflict);
        }
        foreach ($this->entityManager->getRepository(ScheduleStructureSnapshot::class)->findBy(['scheduleId' => $scheduleId]) as $snapshot) {
            $this->entityManager->remove($snapshot);
        }
        // Les métriques du solveur (SolverMetric) ne sont VOLONTAIREMENT pas purgées :
        // télémétrie append-only (décision fondateur 2026-07-18) — l'historique des
        // tentatives est la stat d'usage superadmin, il survit à la suppression de la
        // version (les dimensions d'analyse sont dénormalisées à la capture). Seule
        // porte de sortie : l'effacement RGPD du club (ErasedClubPurger).
    }
}

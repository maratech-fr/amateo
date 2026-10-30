<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CalendarEntry;
use App\Entity\ConstraintPeriodOverride;
use App\Entity\ImplicitRuleSetting;
use App\Entity\Reservation;
use App\Entity\Schedule;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\SharedTrainingBlock;
use App\Entity\SharedTrainingBlockTeam;
use App\Entity\TeamPeriodOverride;
use App\Entity\VenuePeriodOverride;
use App\Entity\VenueTrainingSlot;
use App\Enum\ScheduleStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * La COPIE SŒUR d'un plan de période (fondateur 2026-10-27, axe planning lifecycle) — amende
 * la décision D3 v2 « un segment neuf naît VIDE ».
 *
 * Quand une fermeture prolongée enjambe des vacances, chaque run qui n'est PAS le « milieu »
 * déjà travaillé (fin entamée avant la pause, reprise d'après-vacances, fin entamée finale)
 * n'est PAS une génération neuve : c'est une COPIE VALIDÉE d'office de la version EN VIGUEUR du
 * plan SOURCE (le milieu). Deux runs ne partagent jamais un plan (ADR-0002 inv. 4/C1), donc
 * « même planning » = copie.
 *
 * Patron {@see PeriodPlanTranscriber} (copie SANS solve), mais la source est un plan de PÉRIODE
 * sœur (pas le socle) et la copie emporte EN PLUS les 8 familles ancrées-plan
 * ({@see OverlayManager::purgePlanAnchoredSettings}), car la sœur n'hérite pas de la saison : elle
 * reprend la grille et les réglages du PLAN source, pas ceux du socle. La V1 née est COMPLETED
 * (jamais un solve), pointée (validée d'office), source de verrous posée, et porte un MARQUEUR de
 * provenance distinct ({@see self::COPY_MARKER}) — aucune colonne neuve.
 *
 * Source NON pointée (espace de travail) → naissance VIDE : le plan cible reste sans version, le
 * gestionnaire régénère. Les verrous gardent leur ORIGINE vraie (RESERVATION/MANUAL), jamais devinée.
 */
final class PeriodPlanSiblingCopier
{
    /**
     * Marqueur PRODUIT (distinct du seed et de la transcription du socle) posé dans `solverVersion` :
     * « version née d'une copie d'un plan de période sœur, pas d'un solve ».
     */
    public const string COPY_MARKER = 'sibling-copy';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SchedulePlanProvisioner $schedulePlanProvisioner,
        private readonly OverlayManager $overlayManager,
        private readonly ScheduleConstraintBuilder $constraintBuilder,
    ) {}

    /**
     * Copie la version POINTÉE du plan SOURCE dans le plan de l'entrée CIBLE — EN DUR, aucun solve.
     * Atomique (verrou de portée du plan cible + une seule transaction). RÉSISTE au double-submit :
     * la garde « plan cible vierge » est relue SOUS le verrou.
     *
     * @throws UnprocessableEntityHttpException l'entrée cible ne porte pas de plan (inv. 9)
     * @throws ConflictHttpException            le plan cible porte déjà une version (copie = plan vierge)
     */
    public function copyInto(string $sourcePlanId, string $targetEntryId): void
    {
        $this->entityManager->wrapInTransaction(function () use ($sourcePlanId, $targetEntryId): void {
            // Même verrou que la transcription/validation : deux copies concurrentes ne créent pas
            // deux V1 (check-then-insert sérialisé), et `linkSchedule` numérote dessous.
            $this->schedulePlanProvisioner->lockPlanScope($targetEntryId);

            $targetPlanId = $this->schedulePlanProvisioner->periodPlanId($targetEntryId);
            if (null === $targetPlanId) {
                throw new UnprocessableEntityHttpException('La période cible ne porte pas de plan.');
            }

            // Garde « plan vierge » RELUE sous le verrou : un plan déjà versionné ne se re-copie pas.
            $existing = $this->entityManager->getConnection()->fetchOne(
                'SELECT 1 FROM schedule WHERE schedule_plan_id = :pid LIMIT 1',
                ['pid' => $targetPlanId],
            );
            if (false !== $existing) {
                throw new ConflictHttpException('Ce plan porte déjà une version : la copie ne s\'applique qu\'à un plan sans version.');
            }

            // Source non pointée (espace de travail) → naissance VIDE : aucune version ne descend.
            $chosenSourceId = $this->schedulePlanProvisioner->chosenScheduleIdOfPlan($sourcePlanId);
            if (null === $chosenSourceId) {
                return;
            }

            $context = $this->schedulePlanProvisioner->fetchPlanContext($targetPlanId);
            if (null === $context) {
                throw new UnprocessableEntityHttpException('Le plan cible a disparu.');
            }
            $clubId = $context['clubId'];
            $seasonId = $context['seasonId'];

            // La cible porte une grille et des règles bien-être copiées de la saison à sa naissance :
            // on les PURGE avant de poser celles du PLAN source (la sœur reprend la source, jamais la
            // saison — sinon la grille se doublerait et divergerait du milieu).
            $this->overlayManager->purgePlanAnchoredSettings($targetPlanId);
            $this->entityManager->flush();
            $this->copyAnchoredFamilies($sourcePlanId, $targetPlanId, $clubId, $seasonId);
            $this->entityManager->flush();

            // La V1 copiée : COMPLETED (sans solve), numérotée, marqueur de provenance dédié.
            $version = (new Schedule)
                ->setClubId($clubId)
                ->setSeasonId($seasonId)
                ->setSchedulePlanId($targetPlanId)
                ->setName($this->schedulePlanProvisioner->versionNameFor($targetPlanId))
                ->setStatus(ScheduleStatus::COMPLETED)
                ->setSolverVersion(self::COPY_MARKER);
            $this->entityManager->persist($version);
            $this->schedulePlanProvisioner->linkSchedule($version);

            $this->copySlots($chosenSourceId, $version, $clubId, $seasonId);
            $this->entityManager->flush();

            // Snapshot APRÈS la pose des verrous (patron transcription) : cette version est désormais
            // la source des verrous du plan, donc `buildForPeriodPlan` greffe ses HARD en forme
            // canonique — exactement ce que `structureHashOfPlan` recalculera (« Régénérer » honnête).
            $entry = $this->entityManager->getRepository(CalendarEntry::class)->find($targetEntryId);
            if ($entry instanceof CalendarEntry) {
                $payload = $this->constraintBuilder->buildForPeriodPlan($clubId, $seasonId, $targetPlanId, $entry);
                $version->setSnapshotData($payload);
                $version->setSnapshotHash(ScheduleConstraintBuilder::structureHash($payload));
            }
            $version->setManuallyEditedSinceGeneration(false);
            $this->entityManager->flush();

            // Validée d'office (pointée) + source des verrous = la V1 née (patron auto-★).
            $this->schedulePlanProvisioner->choose($version);
            $this->schedulePlanProvisioner->pointLockSource($targetPlanId, $version->getId());
        });
    }

    /**
     * Copie À L'IDENTIQUE les placements de la version source vers la version née — verrou (niveau
     * ET origine) VERBATIM, jamais deviné : les verrous MANUAL/RESERVATION du milieu suivent la copie.
     */
    private function copySlots(string $sourceScheduleId, Schedule $target, string $clubId, string $seasonId): void
    {
        foreach ($this->entityManager->getRepository(ScheduleSlotTemplate::class)->findBy(['scheduleId' => $sourceScheduleId], ['id' => 'ASC']) as $slot) {
            $copy = (new ScheduleSlotTemplate)
                ->setClubId($clubId)
                ->setSeasonId($seasonId)
                ->setScheduleId($target->getId())
                ->setTeamId($slot->getTeamId())
                ->setVenueId($slot->getVenueId())
                ->setCoachId($slot->getCoachId())
                ->setDayOfWeek($slot->getDayOfWeek())
                ->setStartTime($slot->getStartTime())
                ->setDurationMinutes($slot->getDurationMinutes())
                ->setCoachLateToleranceMinutes($slot->getCoachLateToleranceMinutes())
                ->setLockLevel($slot->getLockLevel())
                ->setLockOrigin($slot->getLockOrigin());
            $this->entityManager->persist($copy);
        }
    }

    /**
     * Copie les 8 familles ancrées-plan ({@see OverlayManager::purgePlanAnchoredSettings}) du plan
     * source vers le plan cible — nouvel UUID par ligne, dénormalisation club/saison/plan. Les blocs
     * de mutualisation remappent leurs membres sur le bloc copié (patron {@see
     * SchedulePlanProvisioner::copySocleSharedBlocks}). L'appelant flush.
     */
    private function copyAnchoredFamilies(string $src, string $dst, string $clubId, string $seasonId): void
    {
        foreach ($this->entityManager->getRepository(TeamPeriodOverride::class)->findBy(['schedulePlanId' => $src]) as $row) {
            $this->entityManager->persist(
                (new TeamPeriodOverride)->setClubId($clubId)->setSeasonId($seasonId)->setSchedulePlanId($dst)
                    ->setTeamId($row->getTeamId())->setIsActive($row->isActive())->setSessionsPerWeek($row->getSessionsPerWeek())->setSource($row->getSource()),
            );
        }
        foreach ($this->entityManager->getRepository(ConstraintPeriodOverride::class)->findBy(['schedulePlanId' => $src]) as $row) {
            $this->entityManager->persist(
                (new ConstraintPeriodOverride)->setClubId($clubId)->setSeasonId($seasonId)->setSchedulePlanId($dst)
                    ->setConstraintId($row->getConstraintId())->setIsActive($row->isActive()),
            );
        }
        foreach ($this->entityManager->getRepository(VenueTrainingSlot::class)->findBy(['schedulePlanId' => $src]) as $row) {
            $this->entityManager->persist(
                (new VenueTrainingSlot)->setClubId($clubId)->setSeasonId($seasonId)->setSchedulePlanId($dst)
                    ->setVenueId($row->getVenueId())->setDayOfWeek($row->getDayOfWeek())->setStartTime($row->getStartTime())
                    ->setDurationMinutes($row->getDurationMinutes())->setCapacity($row->getCapacity())->setGroupLabel($row->getGroupLabel()),
            );
        }
        foreach ($this->entityManager->getRepository(Reservation::class)->findBy(['schedulePlanId' => $src]) as $row) {
            $this->entityManager->persist(
                (new Reservation)->setClubId($clubId)->setSeasonId($seasonId)->setSchedulePlanId($dst)
                    ->setTeamId($row->getTeamId())->setLabel($row->getLabel())->setVenueId($row->getVenueId())
                    ->setDayOfWeek($row->getDayOfWeek())->setStartTime($row->getStartTime())->setDurationMinutes($row->getDurationMinutes())
                    ->setCoachLateToleranceMinutes($row->getCoachLateToleranceMinutes()),
            );
        }
        foreach ($this->entityManager->getRepository(VenuePeriodOverride::class)->findBy(['schedulePlanId' => $src]) as $row) {
            $this->entityManager->persist(
                (new VenuePeriodOverride)->setClubId($clubId)->setSeasonId($seasonId)->setSchedulePlanId($dst)
                    ->setVenueId($row->getVenueId())->setMode($row->getMode())->setDayOverrides($row->getDayOverrides()),
            );
        }

        // Blocs de mutualisation : copier le parent puis ses membres sur le bloc copié (remap d'id).
        $blockMap = [];
        foreach ($this->entityManager->getRepository(SharedTrainingBlock::class)->findBy(['schedulePlanId' => $src]) as $block) {
            $copy = (new SharedTrainingBlock)->setClubId($clubId)->setSeasonId($seasonId)->setSchedulePlanId($dst)
                ->setCommonSessions($block->getCommonSessions())->setLabel($block->getLabel());
            $this->entityManager->persist($copy);
            $blockMap[$block->getId()] = $copy->getId();
        }
        foreach ($this->entityManager->getRepository(SharedTrainingBlockTeam::class)->findBy(['schedulePlanId' => $src]) as $member) {
            $newBlockId = $blockMap[$member->getBlockId()] ?? null;
            if (null === $newBlockId) {
                continue;
            }
            $this->entityManager->persist(
                (new SharedTrainingBlockTeam)->setClubId($clubId)->setSeasonId($seasonId)->setSchedulePlanId($dst)
                    ->setBlockId($newBlockId)->setTeamId($member->getTeamId()),
            );
        }

        foreach ($this->entityManager->getRepository(ImplicitRuleSetting::class)->findBy(['schedulePlanId' => $src]) as $row) {
            $this->entityManager->persist(
                (new ImplicitRuleSetting)->setClubId($clubId)->setSeasonId($seasonId)->setSchedulePlanId($dst)
                    ->setRuleKey($row->getRuleKey())->setIntensity($row->getIntensity())->setParams($row->getParams()),
            );
        }
    }
}

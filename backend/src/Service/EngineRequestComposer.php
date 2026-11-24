<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CalendarEntry;
use App\Entity\Schedule;
use App\Entity\ScheduleSlotTemplate;
use App\Enum\ScheduleStatus;
use App\Exception\EngineRequestNotComposable;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;

/**
 * Compose la demande envoyée au moteur pour une version (générer, régénérer, combler) : l'entrée
 * figée (snapshot, hachée) puis les greffes de convergence. Lecture seule : ne modifie ni la
 * version ni la base ; `GenerateScheduleHandler` fige le snapshot, son empreinte et la greffe.
 */
final readonly class EngineRequestComposer
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ScheduleConstraintBuilder $constraintBuilder,
        private SchedulePlanProvisioner $schedulePlanProvisioner,
    ) {}

    /** @throws EngineRequestNotComposable période disparue ou version de départ introuvable */
    public function compose(Schedule $schedule, ?string $sourceScheduleId, ?string $fillSourceScheduleId): ComposedEngineRequest
    {
        // Overlay (palier B): build from the period entry instead of the base plan.
        // ADR-0002 C4 : le type de build se dérive du PLAN (plan.type), plus de
        // Schedule.calendarEntryId. Un schedule sans plan LÈVE (periodEntryIdOf) —
        // une version sans plan ne doit pas exister (ruling 2026-07-17) ; l'exception
        // remonte à handleUnexpectedFailure → échec terminal, jamais un build socle muet.
        $overlayEntry = null;
        $overlayEntryId = $this->schedulePlanProvisioner->periodEntryIdOf($schedule);
        if (null !== $overlayEntryId) {
            $overlayEntry = $this->entityManager->getRepository(CalendarEntry::class)->find($overlayEntryId);
            if (!$overlayEntry instanceof CalendarEntry) {
                // The period was deleted between queueing and running — fail terminally.
                throw new EngineRequestNotComposable(EngineRequestNotComposable::OVERLAY_ENTRY_MISSING);
            }
        }

        // Plan de saison : on repart de la version que la régénération a nommée. Si elle a disparu
        // (supprimée entre la mise en file et le solve), repartir d'une autre serait un mensonge : échec
        // nommé. Sans source explicite (première génération), le builder lit la version chargée.
        $sourceId = $sourceScheduleId;
        if (!$overlayEntry instanceof CalendarEntry && null !== $sourceId) {
            $source = $this->findSchedule($sourceId);
            if (!$source instanceof Schedule || $source->getSchedulePlanId() !== $schedule->getSchedulePlanId()) {
                throw new EngineRequestNotComposable(EngineRequestNotComposable::SOURCE_VERSION_MISSING);
            }
        }

        $scheduleInput = $overlayEntry instanceof CalendarEntry
            ? $this->constraintBuilder->buildForPlan($schedule->getSchedulePlanId(), $schedule->getSolverSeed(), $schedule->getId())
            : $this->constraintBuilder->buildForPlan($schedule->getSchedulePlanId(), versionId: $sourceId);

        // P2-44 PR-3 (comblement) — solve PARTIEL : les placements de la version SOURCE de la
        // période sont épinglés HARD dans le payload (jamais persistés en base), seuls les trous
        // (équipes sous leur volume de séances) restent à placer. Injecté AVANT le hash de
        // snapshot : contrairement à `previousAssignments` (préférence de convergence), ces
        // épingles SONT l'entrée figée du solve — le snapshot doit les porter, comme il porte les
        // verrous d'un overlay ordinaire. N'a lieu que sur un plan de période (overlayEntry).
        $fillSourceId = $fillSourceScheduleId;
        $isFill = null !== $fillSourceId && $overlayEntry instanceof CalendarEntry;
        if ($isFill) {
            $scheduleInput = $this->constraintBuilder->withPinnedAssignments(
                $scheduleInput,
                $this->pinnedSourceSlots((string) $fillSourceId, $schedule),
            );
        }

        // L'entrée figée : ce que le handler pose en `snapshotData` et hache en `snapshotHash`.
        $snapshot = $scheduleInput;

        // P3-21 — le placement PRÉCÉDENT (terme de stabilité moteur) est greffé sur l'entrée
        // du solveur APRÈS le snapshot (data + hash), jamais avant : le précédent est une
        // préférence de CONVERGENCE, pas une donnée de STRUCTURE. L'inclure dans
        // `snapshotHash` (ou dans `snapshotData`, gardée alignée sur le hash) le ferait
        // diverger de l'empreinte de structure (`SchedulePlanProvisioner::structureHashOfPlan`,
        // recalculée SANS lui) à CHAQUE régénération, cassant en silence le garde « structure inchangée »
        // (bouton Régénérer grisé, signal « structure modifiée » du cockpit). Seul
        // `$scheduleInput`, l'entrée réelle envoyée au moteur, le porte.
        //
        // P2-44 PR-3 — sauté en mode comblement : le fill épingle DÉJÀ tout le placé en HARD
        // (ci-dessus), et un HARD n'a pas de variable côté moteur — le terme de stabilité serait
        // un no-op sur les épingles et n'a aucun placement « précédent » à faire converger pour
        // les trous (jamais placés). L'ajouter ne ferait que doubler les mêmes placements.
        if (!$isFill) {
            $scheduleInput = $this->constraintBuilder->withPreviousAssignments(
                $scheduleInput,
                $this->resolvePreviousAssignmentSlots($schedule, $sourceScheduleId),
            );

            // PR 6 (lot 8) — « Garder ses créneaux de saison » : en génération PLEINE d'un plan de
            // PÉRIODE, les équipes qui portent la contrainte SEASON_SLOTS reçoivent, en keep, les
            // placements de la version pointée du socle (jour+heure+gymnase) — le moteur garde alors
            // leur créneau de saison (préférence très forte). Comme `previousAssignments` : APRÈS le
            // hash (convergence, pas structure). Les équipes « garder » sont lues de la sélection déjà
            // résolue par `buildForPlan` (zéro requête de plus). Aucune SEASON_SLOTS, ou plan SEASON
            // (socle lui-même, jamais de keep) ⇒ clé absente, chemin byte-identique.
            if ($overlayEntry instanceof CalendarEntry) {
                $keepTeamIds = $this->constraintBuilder->lastSeasonSlotKeepTeamIds();
                if ([] !== $keepTeamIds) {
                    $scheduleInput = $this->constraintBuilder->withSeasonSlotKeepReferences(
                        $scheduleInput,
                        $this->socleReferenceSlots($schedule),
                        $keepTeamIds,
                    );
                }
            }
        } else {
            // PR-3 (comblement) — RÉFÉRENCE de comblement : les placements de la version POINTÉE du
            // socle (plan SEASON) sont émis pour que le solveur GARDE le jour+heure de référence des
            // séances comblées (gymnase libre). Comme `previousAssignments` : APRÈS le hash (une
            // préférence de convergence, pas une donnée de structure — sinon `snapshotHash`
            // divergerait de l'empreinte de structure). Distinct des épingles HARD (`withPinnedAssignments`,
            // AVANT le hash) : celles-ci FIGENT le déjà-placé, la référence ORIENTE les trous.
            $scheduleInput = $this->constraintBuilder->withSocleReferenceAssignments(
                $scheduleInput,
                $this->socleReferenceSlots($schedule),
            );
        }

        return new ComposedEngineRequest($snapshot, $scheduleInput, !$overlayEntry instanceof CalendarEntry);
    }

    private function findSchedule(string $scheduleId): ?Schedule
    {
        $schedule = $this->entityManager->getRepository(Schedule::class)->find($scheduleId);

        return $schedule instanceof Schedule ? $schedule : null;
    }

    /**
     * P3-21 — les placements de la version « précédente » à faire converger (terme de
     * stabilité). Source EXPLICITE d'abord (`sourceScheduleId` = la version chargée d'où
     * part la régénération) ; à défaut, repli sur la dernière version
     * COMPLETED du MÊME plan. Aucune ⇒ première génération : bloc vide.
     *
     * La source est TOUJOURS de la MÊME lignée (le même `schedulePlanId`) : ADR-0002 —
     * jamais le socle pour un overlay, ni l'inverse. Pour une source explicite, on le VÉRIFIE
     * (défense en profondeur contre un id incohérent) ; pour le repli, la requête est déjà
     * bornée au plan.
     *
     * @return array<ScheduleSlotTemplate>
     */
    private function resolvePreviousAssignmentSlots(Schedule $schedule, ?string $sourceScheduleId): array
    {
        $sourceId = $sourceScheduleId;
        if (null === $sourceId) {
            $source = $this->latestCompletedOfPlan($schedule);
        } else {
            $source = $this->findSchedule($sourceId);
            // Même lignée obligatoire : une source d'un autre plan (le socle sous un overlay,
            // p.ex.) donnerait un précédent hors sujet. Incohérence ⇒ pas de précédent.
            if (!$source instanceof Schedule || $source->getSchedulePlanId() !== $schedule->getSchedulePlanId()) {
                $source = null;
            }
        }

        if (!$source instanceof Schedule) {
            return [];
        }

        return $this->entityManager->getRepository(ScheduleSlotTemplate::class)->findBy(
            ['scheduleId' => $source->getId()],
            ['id' => 'ASC'],
        );
    }

    /**
     * P2-44 PR-3 (comblement) — les placements de la version SOURCE à épingler HARD dans le
     * payload du solve partiel. Même lignée obligatoire (même `schedulePlanId`) : le contrôleur
     * l'a déjà garanti, on le RE-vérifie en défense — une source d'un autre plan (le socle sous
     * un overlay) épinglerait le mauvais planning ; incohérence ⇒ aucune épingle (le solve
     * repartirait de zéro plutôt que de figer des placements étrangers).
     *
     * @return array<ScheduleSlotTemplate>
     */
    private function pinnedSourceSlots(string $sourceScheduleId, Schedule $target): array
    {
        $source = $this->findSchedule($sourceScheduleId);
        if (!$source instanceof Schedule || $source->getSchedulePlanId() !== $target->getSchedulePlanId()) {
            return [];
        }

        return $this->entityManager->getRepository(ScheduleSlotTemplate::class)->findBy(
            ['scheduleId' => $source->getId()],
            ['id' => 'ASC'],
        );
    }

    /**
     * PR-3 (comblement) — les placements de la version POINTÉE du socle (plan SEASON de la saison),
     * RÉFÉRENCE du comblement émise au moteur en `socleReferenceAssignments`. En comblement le socle
     * est GARANTI en vigueur (SocleGuard, `FillPeriodPlanController`) : le pointeur est non-null.
     * « Et si le socle n'a pas de version ? » est un cas IMPOSSIBLE en fill (CLAUDE.md §6) — pas un
     * repli à écrire : on échoue BRUYAMMENT (le plan passe FAILED avec ce message) plutôt que de
     * combler en silence sans référence.
     *
     * @return array<ScheduleSlotTemplate>
     */
    private function socleReferenceSlots(Schedule $schedule): array
    {
        $socleScheduleId = $this->schedulePlanProvisioner->chosenOfSeasonPlan($schedule->getSeasonId());
        if (null === $socleScheduleId) {
            throw new LogicException(\sprintf('Comblement du plan %s sans version pointée du socle : SocleGuard aurait dû refuser le fill en amont.', $schedule->getId()));
        }

        return $this->entityManager->getRepository(ScheduleSlotTemplate::class)->findBy(
            ['scheduleId' => $socleScheduleId],
            ['id' => 'ASC'],
        );
    }

    /**
     * La dernière version COMPLETED du plan de `$schedule`, hors la version en cours de
     * génération (numérotation linéaire `versionNumber` — la V la plus haute finie).
     */
    private function latestCompletedOfPlan(Schedule $schedule): ?Schedule
    {
        $candidates = $this->entityManager->getRepository(Schedule::class)->findBy(
            ['schedulePlanId' => $schedule->getSchedulePlanId(), 'status' => ScheduleStatus::COMPLETED],
            ['versionNumber' => 'DESC'],
        );

        foreach ($candidates as $candidate) {
            if ($candidate->getId() !== $schedule->getId()) {
                return $candidate;
            }
        }

        return null;
    }
}

<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CalendarEntry;
use App\Entity\Reservation;
use App\Entity\Schedule;
use App\Entity\ScheduleSlotTemplate;
use App\Enum\LockLevel;
use App\Enum\LockOrigin;
use App\Enum\SchedulePlanType;
use App\Enum\ScheduleStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * « L'adaptation naît comme une COPIE du socle » — PR-1.
 *
 * Un plan de PÉRIODE (adaptation/reprise) reçoit une V1 qui TRANSCRIT la version pointée du plan
 * SEASON — le planning de saison à l'identique, MOINS ce qui ne passe plus pour cette période —
 * au lieu d'un solve complet qui redistribuerait tout. C'est l'industrialisation, côté produit,
 * du patron de transcription du seed (`BcclSeeder::pointPeriodPlanAtReprise`) : version COMPLETED
 * sans solveur, `linkSchedule` pour la numéroter, snapshot par `buildForPeriodPlan`, 3 marqueurs
 * de péremption à false. Le seed n'est PAS refactoré : ce service copie son patron, il ne l'appelle
 * pas.
 *
 * Le filtre de « ce qui ne passe plus » est la sélection de période EXISTANTE
 * ({@see PeriodConstraintSelector} / {@see PlanVenueClosures}), la MÊME que le gate pré-solve et
 * le payload — jamais un second calcul :
 *  - équipe désactivée pour la période → ses séances ne sont PAS copiées (elle ne joue pas) ;
 *  - gymnase désactivé → non copiée, « à replacer » (raison venue_disabled) ;
 *  - séance sur un couple (gymnase, jour) fermé EFFECTIF → non copiée, « à replacer » (venue_closed) ;
 *  - équipe réduite (sessionsPerWeek de l'override < séances du socle) → on retire LES DERNIÈRES de
 *    la semaine (tri jour puis heure décroissants — règle déterministe), « à replacer » (team_reduced) ;
 *    le gestionnaire échangera par move.
 *
 * Les séances copiées sont VERROUILLÉES en base (HARD) — « des verrous qu'on décide de bouger
 * volontairement après » (décision fondateur). L'origine du verrou est VRAIE, jamais devinée
 * (cf. `LockOriginProvenanceTest`) : une séance qui coïncide avec une `Reservation` du plan suit le
 * patron seed (HARD/RESERVATION) ; toutes les autres sont un ÉPINGLAGE de gestion — le geste
 * « transcrire » est management-déclenché et révocable — donc HARD/MANUAL. Aucune origine nouvelle.
 *
 * Statut COMPLETED d'emblée ; le pointeur N'EST PAS posé (décision fondateur : PAS de `choose()`
 * automatique — le gestionnaire valide comme aujourd'hui, via la route de validation existante).
 *
 * Provenance : `solverVersion` porte un marqueur PRODUIT dédié (`self::TRANSCRIPTION_MARKER`),
 * distinct du marqueur du seed — aucune migration, aucune colonne neuve. Il DIT « cette version
 * n'est pas née d'un solve » sans détourner un champ métier.
 *
 * ── Q6 (affirmation fondateur, VÉRIFIÉE dans le code) ──────────────────────────────────────────
 * « Revalider une AUTRE version de saison détruit tous les plans futurs (périodes) » est VRAI :
 * `ValidateScheduleController::__invoke` (l. 155-180) calcule
 * `OverlayManager::periodPlansInvalidatedBySeasonChange` puis `deletePeriodPlanForEntry(force: true)`
 * — le PLAN ENTIER de chaque période non commencée (grille copiée comprise), pas seulement ses
 * versions. Aucun traitement de dérive socle↔copie n'est donc nécessaire ici : déplacer le socle
 * détruit d'abord les copies de période, elles ne peuvent pas survivre périmées.
 */
final class PeriodPlanTranscriber
{
    /**
     * Marqueur PRODUIT (distinct de celui du seed) posé dans `solverVersion` : « version née d'une
     * transcription du socle, pas d'un solve ». Voir le docblock de classe (provenance).
     */
    public const string TRANSCRIPTION_MARKER = 'socle-transcription';

    /** Sortie « à replacer » : la séance nomme un gymnase désactivé pour la période. */
    public const string SKIP_VENUE_DISABLED = 'venue_disabled';

    /** Sortie « à replacer » : la séance tombe un jour EFFECTIVEMENT fermé du gymnase. */
    public const string SKIP_VENUE_CLOSED = 'venue_closed';

    /** Sortie « à replacer » : la dernière de la semaine, retirée par la réduction de séances. */
    public const string SKIP_TEAM_REDUCED = 'team_reduced';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ScheduleConstraintBuilder $constraintBuilder,
        private readonly SchedulePlanProvisioner $schedulePlanProvisioner,
        private readonly PeriodConstraintSelector $periodConstraintSelector,
    ) {}

    /**
     * Transcrit la version pointée du socle en V1 du plan de période — atomique (verrou de portée
     * du plan + une seule transaction). RÉSISTE au double-submit : la garde « aucune version » est
     * relue SOUS le verrou.
     *
     * @throws UnprocessableEntityHttpException le plan n'est pas un plan de période, ou son entrée a disparu
     * @throws ConflictHttpException            la période a déjà une version, ou le socle n'est pas pointé
     */
    public function transcribe(string $schedulePlanId): PeriodTranscriptionResult
    {
        return $this->entityManager->wrapInTransaction(function () use ($schedulePlanId): PeriodTranscriptionResult {
            $context = $this->schedulePlanProvisioner->fetchPlanContext($schedulePlanId);
            if (null === $context) {
                throw new UnprocessableEntityHttpException('Ce plan n\'existe plus.');
            }
            $entryId = $context['calendarEntryId'];
            if (SchedulePlanType::SEASON === $context['type'] || null === $entryId) {
                throw new UnprocessableEntityHttpException('La transcription depuis le socle ne s\'applique qu\'à un plan de période.');
            }

            // TOUT ce qui décide vit sous le verrou de portée du plan (même clé que linkSchedule /
            // validation) : sans lui, deux « transcrire » concurrents créeraient deux V1.
            $this->schedulePlanProvisioner->lockPlanScope($entryId);

            // Garde « plan vierge » RELUE sous le verrou : un plan déjà versionné ne se re-copie pas.
            $existing = $this->entityManager->getConnection()->fetchOne(
                'SELECT 1 FROM schedule WHERE schedule_plan_id = :pid LIMIT 1',
                ['pid' => $schedulePlanId],
            );
            if (false !== $existing) {
                throw new ConflictHttpException('Cette période porte déjà une version : la transcription ne s\'applique qu\'à un plan sans version.');
            }

            $clubId = $context['clubId'];
            $seasonId = $context['seasonId'];

            // Le socle DOIT être pointé (on en copie la version choisie). Le contrôleur l'exige déjà
            // via SocleGuard ; défense en profondeur ici sous le verrou.
            $chosenSocleId = $this->schedulePlanProvisioner->chosenOfSeasonPlan($seasonId);
            if (null === $chosenSocleId) {
                throw new ConflictHttpException('Validez le planning de la saison avant de transcrire une période depuis le socle.');
            }

            $entry = $this->entityManager->getRepository(CalendarEntry::class)->find($entryId);
            if (!$entry instanceof CalendarEntry) {
                throw new UnprocessableEntityHttpException('La période n\'existe plus.');
            }

            return $this->copyFromSocle($clubId, $seasonId, $schedulePlanId, $entry, $chosenSocleId);
        });
    }

    private function copyFromSocle(string $clubId, string $seasonId, string $schedulePlanId, CalendarEntry $entry, string $chosenSocleId): PeriodTranscriptionResult
    {
        // La sélection de période EXISTANTE : le filtre partagé (gate + payload). Calculée UNE fois,
        // passée au builder pour le snapshot (cohérence + économie).
        $selection = $this->periodConstraintSelector->selectForPeriodPlan($clubId, $seasonId, $schedulePlanId, $entry);
        $deactivatedTeamIds = $selection->deactivatedTeamIds;
        $disabledVenueIds = $selection->disabledVenueIds;
        $closedWeekdaysByVenue = $selection->effectiveClosedWeekdaysByVenue;
        $sessionOverrides = $selection->sessionOverrides;

        // Les placements de la version POINTÉE du socle — l'ordre (id ASC) fixe la sortie.
        /** @var list<ScheduleSlotTemplate> $socleSlots */
        $socleSlots = $this->entityManager->getRepository(ScheduleSlotTemplate::class)->findBy(['scheduleId' => $chosenSocleId], ['id' => 'ASC']);

        // Réservations DE CE PLAN → clés de placement (dérivation des verrous, comme l'import).
        $reservationPlacements = [];
        foreach ($this->entityManager->getRepository(Reservation::class)->findBy(['schedulePlanId' => $schedulePlanId]) as $reservation) {
            // Lot 4bis — un créneau LIBRE (teamId null) n'est pas un placement d'équipe : ignoré.
            if (null === $reservation->getTeamId()) {
                continue;
            }
            $reservationPlacements[$this->placementKey($reservation->getTeamId(), $reservation->getVenueId(), $reservation->getDayOfWeek(), $reservation->getStartTime()->format('H:i'))] = true;
        }

        // Réduction d'équipe : quelles séances du socle SONT retirées (les dernières de la semaine).
        // Calculée sur les séances du socle de l'équipe (décision fondateur : « < séances du socle »).
        $reducedAwaySlotIds = $this->slotsRemovedByReduction($socleSlots, $deactivatedTeamIds, $sessionOverrides);

        // La version transcrite : COMPLETED, numérotée V1, NON pointée (le gestionnaire valide).
        $schedule = (new Schedule)
            ->setClubId($clubId)
            ->setSeasonId($seasonId)
            ->setSchedulePlanId($schedulePlanId)
            ->setName($this->schedulePlanProvisioner->versionNameFor($schedulePlanId))
            ->setStatus(ScheduleStatus::COMPLETED)
            ->setSolverVersion(self::TRANSCRIPTION_MARKER);
        $this->entityManager->persist($schedule);
        // Primitive de l'app : NUMÉROTE la version dans son plan (V1), sous verrou. Flushe l'INSERT.
        $this->schedulePlanProvisioner->linkSchedule($schedule);

        // Snapshot = la structure COURANTE du plan de période (scheduleId omis = pré-génération, les
        // Reservation portées par le plan tiennent lieu de verrous durables) — même recette que le
        // seed, pour que « Régénérer » soit honnêtement grisé tant que rien n'a bougé.
        $payload = $this->constraintBuilder->buildForPeriodPlan($clubId, $seasonId, $schedulePlanId, $entry, selection: $selection);
        $schedule->setSnapshotData($payload);
        $schedule->setSnapshotHash(hash('sha256', json_encode($payload, \JSON_THROW_ON_ERROR)));
        $this->entityManager->flush();

        $toReplace = [];
        $copied = 0;
        foreach ($socleSlots as $slot) {
            $teamId = $slot->getTeamId();

            // Équipe désactivée pour la période : elle ne joue pas — ses séances DISPARAISSENT, elles
            // ne sont pas « à replacer » (rien à replacer pour une équipe en pause).
            if (isset($deactivatedTeamIds[$teamId])) {
                continue;
            }

            $venueId = $slot->getVenueId();
            $day = $slot->getDayOfWeek();

            // Précédence : la réduction (choix explicite de l'équipe) prime sur le filtre de gymnase.
            if (isset($reducedAwaySlotIds[$slot->getId()])) {
                $toReplace[] = $this->replacementEntry($slot, self::SKIP_TEAM_REDUCED);

                continue;
            }
            if (isset($disabledVenueIds[$venueId])) {
                $toReplace[] = $this->replacementEntry($slot, self::SKIP_VENUE_DISABLED);

                continue;
            }
            if (isset($closedWeekdaysByVenue[$venueId][$day])) {
                $toReplace[] = $this->replacementEntry($slot, self::SKIP_VENUE_CLOSED);

                continue;
            }

            // La séance passe : copie À L'IDENTIQUE (coach compris), verrou HARD dont l'origine est
            // VRAIE — RESERVATION si une réservation du plan coïncide, sinon MANUAL (épinglage de
            // gestion, révocable). Jamais devinée (cf. LockOriginProvenanceTest).
            $isReserved = isset($reservationPlacements[$this->placementKey($teamId, $venueId, $day, $slot->getStartTime()->format('H:i'))]);
            $copy = (new ScheduleSlotTemplate)
                ->setClubId($clubId)
                ->setSeasonId($seasonId)
                ->setScheduleId($schedule->getId())
                ->setTeamId($teamId)
                ->setVenueId($venueId)
                ->setCoachId($slot->getCoachId())
                ->setDayOfWeek($day)
                ->setStartTime($slot->getStartTime())
                ->setDurationMinutes($slot->getDurationMinutes())
                ->setLockLevel(LockLevel::HARD)
                ->setLockOrigin($isReserved ? LockOrigin::RESERVATION : LockOrigin::MANUAL);
            $this->entityManager->persist($copy);
            ++$copied;
        }
        $this->entityManager->flush();

        // Miroir de ScheduleResultImporter : une version fraîchement transcrite n'est pas périmée.
        $schedule->setManuallyEditedSinceGeneration(false);
        $this->entityManager->flush();

        return new PeriodTranscriptionResult($schedule->getId(), $schedule->getVersionNumber(), $copied, $toReplace);
    }

    /**
     * Les ids des séances du socle RETIRÉES par la réduction de séances d'une équipe : les DERNIÈRES
     * de la semaine (tri jour puis heure DÉCROISSANTS — déterministe). Une équipe sans surcharge, ou
     * dont la surcharge est ≥ à ses séances du socle, ne perd rien. Une équipe désactivée est ignorée
     * ici (ses séances disparaissent en amont, jamais « à replacer »).
     *
     * @param list<ScheduleSlotTemplate> $socleSlots
     * @param array<string, true>        $deactivatedTeamIds
     * @param array<string, int>         $sessionOverrides
     *
     * @return array<string, true> ids de créneaux retirés
     */
    private function slotsRemovedByReduction(array $socleSlots, array $deactivatedTeamIds, array $sessionOverrides): array
    {
        // Grouper les séances du socle par équipe (dans l'ordre id ASC de la sélection).
        $byTeam = [];
        foreach ($socleSlots as $slot) {
            $teamId = $slot->getTeamId();
            if (isset($deactivatedTeamIds[$teamId]) || !isset($sessionOverrides[$teamId])) {
                continue; // pas de réduction possible sans surcharge de séances
            }
            $byTeam[$teamId][] = $slot;
        }

        $removed = [];
        foreach ($byTeam as $teamId => $slots) {
            $target = $sessionOverrides[$teamId];
            $removeCount = \count($slots) - $target;
            if ($removeCount <= 0) {
                continue; // la surcharge ne réduit pas (≥ séances du socle)
            }

            // Tri jour puis heure DÉCROISSANTS : les DERNIÈRES de la semaine passent en tête, on en
            // retire `removeCount`. Règle déterministe validée par le fondateur.
            usort($slots, static fn (ScheduleSlotTemplate $a, ScheduleSlotTemplate $b): int => [$b->getDayOfWeek(), $b->getStartTime()->format('H:i')] <=> [$a->getDayOfWeek(), $a->getStartTime()->format('H:i')]);
            foreach (\array_slice($slots, 0, $removeCount) as $slot) {
                $removed[$slot->getId()] = true;
            }
        }

        return $removed;
    }

    /**
     * Une ligne de la liste « à replacer » (sous-produit servi au front) : équipe + jour + heure +
     * gymnase d'origine + raison. Le front ne redérive rien.
     *
     * @return array{teamId: string, dayOfWeek: int, startTime: string, venueId: string, reason: string}
     */
    private function replacementEntry(ScheduleSlotTemplate $slot, string $reason): array
    {
        return [
            'teamId' => $slot->getTeamId(),
            'dayOfWeek' => $slot->getDayOfWeek(),
            'startTime' => $slot->getStartTime()->format('H:i'),
            'venueId' => $slot->getVenueId(),
            'reason' => $reason,
        ];
    }

    /**
     * Clé de placement d'un créneau (équipe:gymnase:jour:HH:MM) — même identité que l'importer et le
     * seed, pour dériver les verrous des réservations.
     */
    private function placementKey(string $teamId, string $venueId, int $day, string $start): string
    {
        return \sprintf('%s:%s:%d:%s', $teamId, $venueId, $day, substr($start, 0, 5));
    }
}

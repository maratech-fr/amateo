<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Coach;
use App\Entity\CoachWish;
use App\Entity\CoachWishCampaign;
use App\Entity\CoachWishToken;
use App\Entity\Constraint;
use App\Entity\Team;
use App\Entity\TeamCoach;
use App\Entity\TeamPeriodOverride;
use App\Enum\ConstraintFamily;
use App\Enum\ConstraintRuleType;
use App\Enum\ConstraintScope;
use App\Exception\ScheduleGenerationInProgressException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * P2-63 PR 5 (volets E + F) — « TRANSFÉRER AU PLANNING » : convertit en MASSE des doléances
 * COCHÉES par le gestionnaire en réglages STANDARDS du plan de leur semaine, pour ne plus
 * convertir « 25 doléances à la main » (mots du fondateur). Geste GESTIONNAIRE explicite,
 * jamais la page publique à jeton, jamais automatique sur re-soumission (SEC §3bis).
 *
 * Ce qu'un transfert écrit, par doléance, DANS une transaction (tout-ou-rien) :
 *  - `slotsWanted > 0` → {@see TeamPeriodOverride} de la semaine : actif, `sessionsPerWeek`,
 *    `source = coach_wish` (F — réactive une équipe hors périmètre, trace « via doléances ») ;
 *  - `slotsWanted = 0` → override `isActive = false` (décision 12) ;
 *  - `unavailableDays` → UNE contrainte DATÉE HARD (ci-dessous), commentaire en description ;
 *  - `wishedDays` → UNE contrainte DATÉE TEAM/DAY `preferredDays` PREFERRED (décision 6) ;
 *  - la doléance passe `done = true` (Q7).
 *
 * Les contraintes sont DATÉES sur l'entrée qui PORTE le plan de la semaine — enfant si le
 * planning est scindé, MÈRE si c'est un bloc ({@see SchedulePlanProvisioner::periodWeekPlanTarget}).
 * Elles restent donc actives MÊME en vacances, malgré le défaut OFF des contraintes de saison
 * permanentes (lot 3b C) : une datée est un geste explicite POUR cette période.
 *
 * INDISPONIBILITÉ COACH — Q-A (CONFIRMÉE fondateur 2026-10-10), qui AMENDE D-a/Q2 :
 *  - doléance SANS coach (saisie manuelle, Q5) → `forbiddenDays` TEAM HARD : « l'équipe ne
 *    s'entraîne pas ces jours-là » ;
 *  - équipe à UN SEUL coach requis → `COACH_AVAILABILITY` HARD du coach (comportement du plan) ;
 *  - équipe à PLUSIEURS coachs requis (ex. U11M1 = Maxime + Julie) :
 *      • si TOUS les coachs requis ont répondu à la collecte → `COACH_AVAILABILITY` HARD du
 *        coach de la doléance ; le moteur applique « un coach suffit » (lot 3b A : le jour n'est
 *        fermé que si TOUS les coachs requis sont indisponibles) — Maxime indispo jeudi, Julie
 *        libre ⇒ l'équipe peut s'entraîner le jeudi ;
 *      • si UN SEUL a répondu → on ne suppose rien sur l'autre : le jour déclaré est FERMÉ pour
 *        l'ÉQUIPE (`forbiddenDays` TEAM HARD).
 *  « A répondu » = le jeton du coach dans la campagne de la période porte un `respondedAt`
 *  ({@see CoachWishToken::$respondedAt}, horodatage de sa dernière soumission).
 *
 * IDEMPOTENCE (décision 7) : `source = coach_wish` + `sourceOccurrenceId = id de la doléance`.
 * Re-transférer EFFACE d'abord les contraintes du précédent transfert de CETTE doléance puis
 * recrée — jamais une 2ᵉ ligne. Modifier/supprimer une doléance ne touche PAS le plan
 * (décision 8) : seul un nouveau transfert recale. Une `slotsWanted = 0` + `unavailableDays`
 * crée une contrainte INERTE (l'équipe est désactivée) — ce n'est pas une erreur (assumé).
 */
final class CoachWishTransferService
{
    /** Marqueur de provenance « vient des doléances » — override ET contrainte (F, idempotence). */
    public const string SOURCE = 'coach_wish';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ClubGenerationLock $clubGenerationLock,
        private readonly SchedulePlanProvisioner $schedulePlanProvisioner,
        private readonly PlanTeamSelectionSeeder $planTeamSelectionSeeder,
    ) {}

    /**
     * @param list<string> $wishIds les doléances COCHÉES par le gestionnaire (Q7)
     *
     * @throws ScheduleGenerationInProgressException une génération tourne pour ce club (→ 409)
     *
     * @return array{converted: int, ignored: int, ignoredReasons: array<string, int>}
     */
    public function transfer(string $clubId, array $wishIds): array
    {
        // Q-B = A — refus 409 pendant une génération : elle réécrit le planning, transférer
        // maintenant écraserait son résultat. Simple LECTURE du verrou (patron
        // MutualizeSlotService), jamais une prise de verrou.
        if ($this->clubGenerationLock->isGenerating($clubId)) {
            throw new ScheduleGenerationInProgressException;
        }

        return $this->entityManager->wrapInTransaction(function () use ($clubId, $wishIds): array {
            $converted = 0;
            $ignored = 0;
            $reasons = [];
            $wishRepository = $this->entityManager->getRepository(CoachWish::class);

            foreach (array_unique($wishIds) as $wishId) {
                $wish = $wishRepository->find($wishId);
                // `find()` n'applique PAS les filtres Doctrine (seule RLS scope le club en base) :
                // le contrôle de club explicite est LA garde — une doléance d'un autre club n'est
                // jamais convertie (abus « autre club », §3bis #5).
                if (!$wish instanceof CoachWish || $wish->getClubId() !== $clubId) {
                    ++$ignored;
                    $reasons['introuvable'] = ($reasons['introuvable'] ?? 0) + 1;

                    continue;
                }

                $target = $this->schedulePlanProvisioner->periodWeekPlanTarget($wish->getCalendarEntryId(), $wish->getWeekStart());
                if (null === $target) {
                    // Semaine sans plan : on ne fait JAMAIS naître un plan (décision 10) — on
                    // ignore et on le dit. Q8 rend ce cas improbable (collecte après planning).
                    ++$ignored;
                    $reasons['semaine_sans_plan'] = ($reasons['semaine_sans_plan'] ?? 0) + 1;

                    continue;
                }

                $this->convertOne($wish, $target['planId'], $target['entryId'], $clubId);
                $wish->setDone(true); // Q7 — une doléance transférée est traitée.
                ++$converted;
            }

            $this->entityManager->flush();

            return ['converted' => $converted, 'ignored' => $ignored, 'ignoredReasons' => $reasons];
        });
    }

    private function convertOne(CoachWish $wish, string $planId, string $entryId, string $clubId): void
    {
        $seasonId = $wish->getSeasonId();
        $teamId = $wish->getTeamId();

        // D-e — matérialiser le seed « Fanion + importantes » AVANT la 1re écriture d'override,
        // sinon elle désarmerait le tri par défaut en silence. Les doléances n'existent que
        // pour une période de VACANCES (holiday) : le seed de reprise (isClosure = false).
        $this->planTeamSelectionSeeder->seed($planId, $clubId, $seasonId, false);

        // Volume d'équipe de la semaine. slotsWanted > 0 : active + volume + provenance (F).
        // slotsWanted = 0 : désactive l'équipe pour la semaine (décision 12).
        $this->upsertOverride($planId, $teamId, $clubId, $seasonId, $wish->getSlotsWanted());

        // Idempotence : on efface d'abord TOUTES les contraintes du précédent transfert de
        // cette doléance, puis on recrée le jeu courant (décision 7).
        $this->removePriorTransferConstraints($clubId, $wish->getId());

        $comment = $wish->getComment();

        if ([] !== $wish->getUnavailableDays()) {
            $this->createUnavailabilityConstraint($wish, $entryId, $clubId, $seasonId, $comment);
            $comment = null; // le commentaire vit sur UNE contrainte, pas dupliqué.
        }
        if ([] !== $wish->getWishedDays()) {
            $this->createPreferredDaysConstraint($wish, $entryId, $clubId, $seasonId, $comment);
        }
    }

    /**
     * Override de période de la semaine (clé {schedulePlanId, teamId}) : créé s'il manque,
     * relevé s'il existe (p.ex. désactivé par le seed — F réactive une Vétéran via doléances).
     */
    private function upsertOverride(string $planId, string $teamId, string $clubId, string $seasonId, int $slotsWanted): void
    {
        $override = $this->entityManager->getRepository(TeamPeriodOverride::class)
            ->findOneBy(['schedulePlanId' => $planId, 'teamId' => $teamId]);
        if (!$override instanceof TeamPeriodOverride) {
            $override = (new TeamPeriodOverride)
                ->setClubId($clubId)
                ->setSeasonId($seasonId)
                ->setSchedulePlanId($planId)
                ->setTeamId($teamId);
            $this->entityManager->persist($override);
        }

        if ($slotsWanted > 0) {
            $override->setIsActive(true)->setSessionsPerWeek($slotsWanted);
        } else {
            // slotsWanted = 0 : l'équipe ne s'entraîne pas cette semaine ; le volume saison
            // (null) est sans effet puisqu'inactive.
            $override->setIsActive(false)->setSessionsPerWeek(null);
        }
        $override->setSource(self::SOURCE);
    }

    /**
     * Q-A — l'indisponibilité : COACH_AVAILABILITY HARD du coach, ou `forbiddenDays` TEAM HARD
     * fermant le jour pour l'équipe (sans coach, ou co-coachée dont un seul a répondu).
     */
    private function createUnavailabilityConstraint(CoachWish $wish, string $entryId, string $clubId, string $seasonId, ?string $comment): void
    {
        $coachId = $wish->getCoachId();
        $days = $wish->getUnavailableDays();

        if (null === $coachId) {
            // Q5/D-a — doléance sans coach : « l'équipe ne s'entraîne pas ces jours-là ».
            $this->createForbiddenDaysConstraint($wish, $entryId, $clubId, $seasonId, $days, $comment);

            return;
        }

        $requiredCoachIds = $this->requiredCoachIds($wish->getTeamId());
        if (\count($requiredCoachIds) >= 2 && !$this->allRequiredCoachesResponded($wish->getCalendarEntryId(), $requiredCoachIds)) {
            // Équipe co-coachée, un seul a répondu : on ne suppose rien sur l'autre → jour FERMÉ.
            $this->createForbiddenDaysConstraint($wish, $entryId, $clubId, $seasonId, $days, $comment);

            return;
        }

        // Mono-coach, OU co-coachée tous répondants : indispo du coach (le moteur applique
        // « un coach suffit » quand l'équipe a plusieurs coachs, lot 3b A).
        $constraint = $this->newTransferConstraint($wish, $clubId, $seasonId, $entryId, $comment);
        $constraint
            ->setName($this->coachAvailabilityName($coachId))
            ->setScope(ConstraintScope::COACH)
            ->setScopeTargetId($coachId)
            ->setFamily(ConstraintFamily::COACH_AVAILABILITY)
            ->setRuleType(ConstraintRuleType::HARD)
            ->setConfig(['unavailableDays' => $days]);
        $this->entityManager->persist($constraint);
    }

    /** @param list<int> $days */
    private function createForbiddenDaysConstraint(CoachWish $wish, string $entryId, string $clubId, string $seasonId, array $days, ?string $comment): void
    {
        $constraint = $this->newTransferConstraint($wish, $clubId, $seasonId, $entryId, $comment);
        $constraint
            ->setName($this->teamDayName('Jours interdits', $wish->getTeamId()))
            ->setScope(ConstraintScope::TEAM)
            ->setScopeTargetId($wish->getTeamId())
            ->setFamily(ConstraintFamily::DAY)
            ->setRuleType(ConstraintRuleType::HARD)
            ->setConfig(['forbiddenDays' => $days]);
        $this->entityManager->persist($constraint);
    }

    private function createPreferredDaysConstraint(CoachWish $wish, string $entryId, string $clubId, string $seasonId, ?string $comment): void
    {
        $constraint = $this->newTransferConstraint($wish, $clubId, $seasonId, $entryId, $comment);
        $constraint
            ->setName($this->teamDayName('Jours à privilégier', $wish->getTeamId()))
            ->setScope(ConstraintScope::TEAM)
            ->setScopeTargetId($wish->getTeamId())
            ->setFamily(ConstraintFamily::DAY)
            ->setRuleType(ConstraintRuleType::PREFERRED)
            ->setConfig(['preferredDays' => $wish->getWishedDays()]);
        $this->entityManager->persist($constraint);
    }

    /** Une contrainte DATÉE tracée « via doléances », pendue à l'entrée de la semaine/du bloc. */
    private function newTransferConstraint(CoachWish $wish, string $clubId, string $seasonId, string $entryId, ?string $comment): Constraint
    {
        return (new Constraint)
            ->setClubId($clubId)
            ->setSeasonId($seasonId)
            ->setCalendarEntryId($entryId)
            ->setSource(self::SOURCE)
            ->setSourceOccurrenceId($wish->getId())
            ->setDescription(null === $comment || '' === trim($comment) ? null : $comment);
    }

    /** Efface les contraintes d'un transfert précédent de cette doléance (idempotence). */
    private function removePriorTransferConstraints(string $clubId, string $wishId): void
    {
        $prior = $this->entityManager->getRepository(Constraint::class)->findBy([
            'clubId' => $clubId,
            'source' => self::SOURCE,
            'sourceOccurrenceId' => $wishId,
        ]);
        foreach ($prior as $constraint) {
            $this->entityManager->remove($constraint);
        }
    }

    /**
     * Les ids DISTINCTS des coachs REQUIS d'une équipe (TeamCoach.isRequired) — base de la
     * règle Q-A (mono vs multi-coach).
     *
     * @return list<string>
     */
    private function requiredCoachIds(string $teamId): array
    {
        $ids = [];
        foreach ($this->entityManager->getRepository(TeamCoach::class)->findBy(['teamId' => $teamId]) as $link) {
            if ($link->getIsRequired()) {
                $ids[$link->getCoachId()] = true;
            }
        }

        return array_keys($ids);
    }

    /**
     * Tous les coachs requis ont-ils répondu à la collecte de la période ? « A répondu » =
     * le jeton du coach dans la campagne porte un `respondedAt`. Pas de campagne (saisie
     * purement manuelle) → personne n'a « répondu » → false.
     *
     * @param list<string> $requiredCoachIds
     */
    private function allRequiredCoachesResponded(string $motherEntryId, array $requiredCoachIds): bool
    {
        $campaign = $this->entityManager->getRepository(CoachWishCampaign::class)
            ->findOneBy(['calendarEntryId' => $motherEntryId]);
        if (!$campaign instanceof CoachWishCampaign) {
            return false;
        }

        $responded = [];
        foreach ($this->entityManager->getRepository(CoachWishToken::class)->findBy(['campaignId' => $campaign->getId()]) as $token) {
            if (null !== $token->getRespondedAt()) {
                $responded[$token->getCoachId()] = true;
            }
        }

        foreach ($requiredCoachIds as $coachId) {
            if (!isset($responded[$coachId])) {
                return false;
            }
        }

        return true;
    }

    private function coachAvailabilityName(string $coachId): string
    {
        $coach = $this->entityManager->getRepository(Coach::class)->find($coachId);
        $name = $coach instanceof Coach ? trim($coach->getFirstName() . ' ' . $coach->getLastName()) : '';

        return $this->cap('' !== $name ? \sprintf('Indisponibilité de %s (doléances)', $name) : 'Indisponibilité du coach (doléances)');
    }

    private function teamDayName(string $prefix, string $teamId): string
    {
        $team = $this->entityManager->getRepository(Team::class)->find($teamId);
        $name = $team instanceof Team ? $team->getName() : '';

        return $this->cap('' !== $name ? \sprintf('%s %s (doléances)', $prefix, $name) : \sprintf('%s (doléances)', $prefix));
    }

    /** `Constraint.name` fait 180 — un nom construit ne doit jamais le dépasser. */
    private function cap(string $name): string
    {
        return mb_substr($name, 0, 180);
    }
}

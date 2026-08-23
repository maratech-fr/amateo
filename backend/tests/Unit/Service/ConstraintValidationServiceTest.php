<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Constraint;
use App\Enum\ConstraintFamily;
use App\Enum\ConstraintRuleType;
use App\Enum\ConstraintScope;
use App\Service\ConstraintConfigValidator;
use App\Service\ConstraintValidationService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class ConstraintValidationServiceTest extends TestCase
{
    /** SEC-13 : le validateur type les identifiants — un gymnase est un uuid, pas « v ». */
    private const string VENUE = '11111111-1111-4111-8111-111111111111';

    private ConstraintValidationService $service;

    public function testTeamScopeRequiresScopeTargetId(): void
    {
        $constraint = new Constraint;
        $constraint->setScope(ConstraintScope::TEAM);
        $constraint->setScopeTargetId(null);
        $constraint->setFamily(ConstraintFamily::TIME);
        $constraint->setRuleType(ConstraintRuleType::HARD);
        $constraint->setConfig(['maxStartTime' => '20:00']);

        $errors = $this->service->validate($constraint);

        self::assertContains('Cette contrainte doit cibler une équipe, un coach ou un gymnase précis.', $errors);
    }

    public function testCoachScopeRequiresScopeTargetId(): void
    {
        $constraint = new Constraint;
        $constraint->setScope(ConstraintScope::COACH);
        $constraint->setScopeTargetId(null);
        $constraint->setFamily(ConstraintFamily::COACH_AVAILABILITY);
        $constraint->setRuleType(ConstraintRuleType::HARD);
        $constraint->setConfig([]); // SEC-13 : la cible est le SCOPE, plus une clé du config

        $errors = $this->service->validate($constraint);

        self::assertContains('Cette contrainte doit cibler une équipe, un coach ou un gymnase précis.', $errors);
    }

    /**
     * SEC-13 — la cible d'une contrainte de disponibilité est le SCOPE, et lui seul.
     *
     * Le validateur exigeait `config.coachId`, qui valait exactement
     * `scope_target_id` (6 lignes sur 6, mesuré avant migration). Deux endroits
     * pour la même vérité : le jour où ils divergent, personne ne sait lequel fait
     * foi — et le solveur, lui, n'a jamais lu que le scope. Ce test épingle que
     * la clé n'est plus réclamée : la remettre en condition fait rougir.
     */
    public function testCoachAvailabilityTargetsThroughTheScopeAloneWithoutAConfigKey(): void
    {
        $constraint = new Constraint;
        $constraint->setScope(ConstraintScope::COACH);
        $constraint->setScopeTargetId('coach-1');
        $constraint->setFamily(ConstraintFamily::COACH_AVAILABILITY);
        $constraint->setRuleType(ConstraintRuleType::HARD);
        $constraint->setConfig(['unavailableDays' => [5]]);

        $errors = $this->service->validate($constraint);

        self::assertSame([], $errors, 'une disponibilité ciblée par le scope se suffit — aucun coachId dans le config');
    }

    public function testCoachAvailabilityAcceptsAValidTimeWindow(): void
    {
        $constraint = new Constraint;
        $constraint->setScope(ConstraintScope::COACH);
        $constraint->setScopeTargetId('coach-1');
        $constraint->setFamily(ConstraintFamily::COACH_AVAILABILITY);
        $constraint->setRuleType(ConstraintRuleType::HARD);
        $constraint->setConfig(['unavailableDays' => [2], 'fromTime' => '20:00']);

        self::assertSame([], $this->service->validate($constraint));
    }

    public function testCoachAvailabilityRejectsAMalformedOrInvertedWindow(): void
    {
        $constraint = new Constraint;
        $constraint->setScope(ConstraintScope::COACH);
        $constraint->setScopeTargetId('coach-1');
        $constraint->setFamily(ConstraintFamily::COACH_AVAILABILITY);
        $constraint->setRuleType(ConstraintRuleType::HARD);
        $constraint->setConfig(['unavailableDays' => [2], 'fromTime' => '25:99', 'untilTime' => '20:00']);

        $errors = $this->service->validate($constraint);
        self::assertContains('L\'heure « à partir de » doit être au format HH:MM.', $errors);
        // A malformed bound must NOT also trigger the "before" comparison — that
        // would emit a second, misleading error for one bad field (Lot C review).
        self::assertNotContains('L\'heure de début doit précéder l\'heure de fin.', $errors);

        $constraint->setConfig(['fromTime' => '20:00', 'untilTime' => '18:00']);
        self::assertContains('L\'heure de début doit précéder l\'heure de fin.', $this->service->validate($constraint));
    }

    public function testFacilityScopeRequiresScopeTargetId(): void
    {
        $constraint = new Constraint;
        $constraint->setScope(ConstraintScope::FACILITY);
        $constraint->setScopeTargetId(null);
        $constraint->setFamily(ConstraintFamily::FACILITY);
        $constraint->setRuleType(ConstraintRuleType::HARD);
        $constraint->setConfig(['venueId' => 'venue-1']);

        $errors = $this->service->validate($constraint);

        self::assertContains('Cette contrainte doit cibler une équipe, un coach ou un gymnase précis.', $errors);
    }

    public function testClubScopeShouldNotHaveScopeTargetId(): void
    {
        $constraint = new Constraint;
        $constraint->setScope(ConstraintScope::CLUB);
        $constraint->setScopeTargetId('team-1');
        $constraint->setFamily(ConstraintFamily::TIME);
        $constraint->setRuleType(ConstraintRuleType::HARD);
        $constraint->setConfig(['maxStartTime' => '20:00']);

        $errors = $this->service->validate($constraint);

        self::assertContains('Une contrainte « toutes les équipes » ne doit pas cibler une équipe précise.', $errors);
    }

    public function testValidTeamScopeWithTargetIdHasNoScopeError(): void
    {
        $constraint = new Constraint;
        $constraint->setScope(ConstraintScope::TEAM);
        $constraint->setScopeTargetId('team-1');
        $constraint->setFamily(ConstraintFamily::TIME);
        $constraint->setRuleType(ConstraintRuleType::HARD);
        $constraint->setConfig(['maxStartTime' => '20:00']);

        $errors = $this->service->validate($constraint);

        self::assertNotContains('Cette contrainte doit cibler une équipe, un coach ou un gymnase précis.', $errors);
        self::assertNotContains('Une contrainte « toutes les équipes » ne doit pas cibler une équipe précise.', $errors);
    }

    public function testTimeFamilyRequiresMaxOrMinStartTime(): void
    {
        $constraint = new Constraint;
        $constraint->setScope(ConstraintScope::CLUB);
        $constraint->setFamily(ConstraintFamily::TIME);
        $constraint->setRuleType(ConstraintRuleType::HARD);
        $constraint->setConfig([]);

        $errors = $this->service->validate($constraint);

        self::assertContains('Une contrainte d\'horaire doit préciser au moins une heure (début au plus tôt, au plus tard, ou fin).', $errors);
    }

    public function testDayFamilyRequiresAllowedOrForbiddenDays(): void
    {
        $constraint = new Constraint;
        $constraint->setScope(ConstraintScope::CLUB);
        $constraint->setFamily(ConstraintFamily::DAY);
        $constraint->setRuleType(ConstraintRuleType::HARD);
        $constraint->setConfig([]);

        $errors = $this->service->validate($constraint);

        self::assertContains('Une contrainte de jour doit préciser au moins un jour (autorisé, à éviter ou imposé).', $errors);
    }

    public function testForcedDaysAtPreferredIsRejected(): void
    {
        // ALIGN-09 — le moteur n'honore forcedDays que sur HARD/LOCK (constraints.py) ; un
        // forcedDays PREFERRED serait un placebo muet (objective.py ne lit que preferredDays).
        $constraint = (new Constraint)->setScope(ConstraintScope::CLUB)->setFamily(ConstraintFamily::DAY)->setRuleType(ConstraintRuleType::PREFERRED)->setConfig(['forcedDays' => [1]]);
        self::assertContains('La règle « au moins une séance » n\'existe qu\'en règle obligatoire.', $this->service->validate($constraint));
    }

    public function testForcedDaysAtHardIsAccepted(): void
    {
        $constraint = (new Constraint)->setScope(ConstraintScope::CLUB)->setFamily(ConstraintFamily::DAY)->setRuleType(ConstraintRuleType::HARD)->setConfig(['forcedDays' => [1]]);
        self::assertNotContains('La règle « au moins une séance » n\'existe qu\'en règle obligatoire.', $this->service->validate($constraint));
    }

    public function testFacilityFamilyRequiresAVenueKey(): void
    {
        $constraint = new Constraint;
        $constraint->setScope(ConstraintScope::CLUB);
        $constraint->setFamily(ConstraintFamily::FACILITY);
        $constraint->setRuleType(ConstraintRuleType::HARD);
        $constraint->setConfig([]);

        $errors = $this->service->validate($constraint);

        self::assertContains('Une contrainte de gymnase doit désigner un gymnase.', $errors);
    }

    public function testFacilityFamilyAcceptsTheThreeEngineHonoredKeys(): void
    {
        foreach (['forcedVenueId', 'forbiddenVenueId', 'preferredVenueId'] as $key) {
            $constraint = new Constraint;
            $constraint->setScope(ConstraintScope::CLUB);
            $constraint->setFamily(ConstraintFamily::FACILITY);
            $constraint->setRuleType(ConstraintRuleType::HARD);
            // SEC-13 : la valeur compte désormais autant que la clé — 42 n'est pas un gymnase.
            $constraint->setConfig([$key => self::VENUE]);

            self::assertSame([], $this->service->validate($constraint), \sprintf('%s should be a valid FACILITY key', $key));
        }
    }

    public function testFacilityFamilyRejectsBareVenueIdWhichTheEngineIgnores(): void
    {
        $constraint = new Constraint;
        $constraint->setScope(ConstraintScope::TEAM);
        $constraint->setScopeTargetId('team-1');
        $constraint->setFamily(ConstraintFamily::FACILITY);
        $constraint->setRuleType(ConstraintRuleType::HARD);
        $constraint->setConfig(['venueId' => 42]);

        self::assertContains('Une contrainte de gymnase doit désigner un gymnase.', $this->service->validate($constraint));
    }

    /**
     * BUG fondateur (2026-08-19) — une INDISPONIBILITÉ datée de gymnase (`venue_closed`)
     * faisait rougir le gate « À corriger avant de générer » avec « Une contrainte de
     * gymnase doit désigner un gymnase », BLOQUANT le bouton Générer. Faux bloqueur : le
     * gymnase fermé vit dans `scopeTargetId` (pas dans le config), et une fermeture datée ne
     * produit AUCUNE ligne moteur (elle ferme des jours, `VenueClosureDays`) — le gate ne peut
     * donc pas bloquer la génération pour elle (parité gate == payload).
     */
    public function testDatedVenueClosureIsNotFlaggedForMissingVenueKey(): void
    {
        $constraint = new Constraint;
        $constraint->setScope(ConstraintScope::FACILITY);
        $constraint->setScopeTargetId(self::VENUE);
        $constraint->setFamily(ConstraintFamily::FACILITY);
        $constraint->setRuleType(ConstraintRuleType::HARD);
        $constraint->setConfig(['type' => 'venue_closed', 'startDate' => '2026-08-18', 'endDate' => '2026-09-30']);

        $errors = $this->service->validate($constraint);

        self::assertNotContains('Une contrainte de gymnase doit désigner un gymnase.', $errors, 'une fermeture datée porte son gymnase dans le scope, pas dans le config — jamais ce faux bloqueur');
        self::assertSame([], $errors, 'la fermeture datée du fondateur (gymnase + dates cohérentes) est irréprochable pour le gate');
    }

    /** Un config nu (legacy, sans dates) reste valide : VenueClosureDays ferme alors toute la fenêtre. */
    public function testDatedVenueClosureWithoutDatesStaysValid(): void
    {
        $constraint = (new Constraint)
            ->setScope(ConstraintScope::FACILITY)
            ->setScopeTargetId(self::VENUE)
            ->setFamily(ConstraintFamily::FACILITY)
            ->setRuleType(ConstraintRuleType::HARD)
            ->setConfig(['type' => 'venue_closed']);

        self::assertSame([], $this->service->validate($constraint));
    }

    /** Une fermeture SANS gymnase (scopeTargetId nul) est une vraie erreur — message dédié, sans doublon générique. */
    public function testVenueClosureWithoutTargetVenueIsRejectedWithADedicatedMessage(): void
    {
        $constraint = (new Constraint)
            ->setScope(ConstraintScope::FACILITY)
            ->setScopeTargetId(null)
            ->setFamily(ConstraintFamily::FACILITY)
            ->setRuleType(ConstraintRuleType::HARD)
            ->setConfig(['type' => 'venue_closed', 'startDate' => '2026-08-18', 'endDate' => '2026-09-30']);

        $errors = $this->service->validate($constraint);

        self::assertContains('Une fermeture de gymnase doit désigner le gymnase concerné.', $errors);
        self::assertNotContains('Cette contrainte doit cibler une équipe, un coach ou un gymnase précis.', $errors, 'le message générique cède la place au message dédié de la fermeture');
    }

    /** Deux dates inversées ferment zéro jour (no-op silencieux dans VenueClosureDays) : on le signale. */
    public function testVenueClosureWithInvertedDatesIsRejected(): void
    {
        $constraint = (new Constraint)
            ->setScope(ConstraintScope::FACILITY)
            ->setScopeTargetId(self::VENUE)
            ->setFamily(ConstraintFamily::FACILITY)
            ->setRuleType(ConstraintRuleType::HARD)
            ->setConfig(['type' => 'venue_closed', 'startDate' => '2026-09-30', 'endDate' => '2026-08-18']);

        self::assertContains('La date de début de la fermeture doit précéder sa date de fin.', $this->service->validate($constraint));
    }

    public function testCoachAvailabilityFamilyRequiresCoachIdOrTargetTag(): void
    {
        $constraint = new Constraint;
        $constraint->setScope(ConstraintScope::CLUB);
        $constraint->setFamily(ConstraintFamily::COACH_AVAILABILITY);
        $constraint->setRuleType(ConstraintRuleType::HARD);
        $constraint->setConfig([]);

        $errors = $this->service->validate($constraint);

        self::assertContains('Une contrainte de disponibilité doit cibler un coach.', $errors);
    }

    public function testLockRuleTypeOnlyValidForTimeOrDay(): void
    {
        $constraint = new Constraint;
        $constraint->setScope(ConstraintScope::CLUB);
        $constraint->setFamily(ConstraintFamily::FACILITY);
        $constraint->setRuleType(ConstraintRuleType::LOCK);
        $constraint->setConfig(['venueId' => 'venue-1']);

        $errors = $this->service->validate($constraint);

        self::assertContains('Le verrouillage n\'est possible que sur une contrainte d\'horaire ou de jour.', $errors);
    }

    public function testLockRuleTypeValidForTimeFamily(): void
    {
        $constraint = new Constraint;
        $constraint->setScope(ConstraintScope::CLUB);
        $constraint->setFamily(ConstraintFamily::TIME);
        $constraint->setRuleType(ConstraintRuleType::LOCK);
        $constraint->setConfig(['maxStartTime' => '20:00']);

        $errors = $this->service->validate($constraint);

        self::assertNotContains('Le verrouillage n\'est possible que sur une contrainte d\'horaire ou de jour.', $errors);
    }

    public function testLockRuleTypeValidForDayFamily(): void
    {
        $constraint = new Constraint;
        $constraint->setScope(ConstraintScope::CLUB);
        $constraint->setFamily(ConstraintFamily::DAY);
        $constraint->setRuleType(ConstraintRuleType::LOCK);
        $constraint->setConfig(['allowedDays' => [1, 2]]);

        $errors = $this->service->validate($constraint);

        self::assertNotContains('Le verrouillage n\'est possible que sur une contrainte d\'horaire ou de jour.', $errors);
    }

    public function testTimeFamilyAcceptsMaxEndTime(): void
    {
        $constraint = (new Constraint)->setScope(ConstraintScope::CLUB)->setFamily(ConstraintFamily::TIME)->setRuleType(ConstraintRuleType::HARD)->setConfig(['maxEndTime' => '20:30']);
        self::assertSame([], $this->service->validate($constraint));
    }

    public function testFacilityFamilyAcceptsMinAtVenueId(): void
    {
        $constraint = (new Constraint)->setScope(ConstraintScope::TEAM)->setScopeTargetId('t')->setFamily(ConstraintFamily::FACILITY)->setRuleType(ConstraintRuleType::HARD)->setConfig(['minAtVenueId' => self::VENUE, 'minAtVenueCount' => 1]);
        self::assertSame([], $this->service->validate($constraint));
    }

    public function testMaxEndTimeAtPreferredIsRejected(): void
    {
        // The engine only honors maxEndTime on HARD/LOCK — a PREFERRED end-bound is a placebo (C4).
        $constraint = (new Constraint)->setScope(ConstraintScope::CLUB)->setFamily(ConstraintFamily::TIME)->setRuleType(ConstraintRuleType::PREFERRED)->setConfig(['maxEndTime' => '20:30']);
        self::assertContains('« Fini avant » n\'existe qu\'en règle OBLIGATOIRE — passez la contrainte en obligatoire, sinon elle serait ignorée.', $this->service->validate($constraint));
    }

    public function testMinAtVenueIdAtPreferredIsRejected(): void
    {
        $constraint = (new Constraint)->setScope(ConstraintScope::TEAM)->setScopeTargetId('t')->setFamily(ConstraintFamily::FACILITY)->setRuleType(ConstraintRuleType::PREFERRED)->setConfig(['minAtVenueId' => self::VENUE]);
        self::assertContains('« Au moins N séances dans ce gymnase » n\'existe qu\'en règle OBLIGATOIRE — passez la contrainte en obligatoire.', $this->service->validate($constraint));
    }

    public function testMinAtVenueIdAtClubScopeIsRejected(): void
    {
        // CLUB-scoped minAtVenueId is dropped by parse_v2_constraints (TEAM-only) — reject it (C3).
        $constraint = (new Constraint)->setScope(ConstraintScope::CLUB)->setFamily(ConstraintFamily::FACILITY)->setRuleType(ConstraintRuleType::HARD)->setConfig(['minAtVenueId' => self::VENUE]);
        self::assertContains('« Au moins N séances dans ce gymnase » se pose sur une équipe ou un groupe — pas sur « toutes les équipes ».', $this->service->validate($constraint));
    }

    public function testVenueMinimumErrorWhenCountExceedsSessions(): void
    {
        $constraint = (new Constraint)->setFamily(ConstraintFamily::FACILITY)->setConfig(['minAtVenueId' => self::VENUE, 'minAtVenueCount' => 3]);
        self::assertNotNull($this->service->venueMinimumError($constraint, 2), 'min 3 > 2 sessions/week must error');
    }

    public function testVenueMinimumErrorNullWhenWithinSessions(): void
    {
        $constraint = (new Constraint)->setFamily(ConstraintFamily::FACILITY)->setConfig(['minAtVenueId' => self::VENUE, 'minAtVenueCount' => 1]);
        self::assertNull($this->service->venueMinimumError($constraint, 2));
        // Non venue-minimum → always null.
        $other = (new Constraint)->setFamily(ConstraintFamily::FACILITY)->setConfig(['forcedVenueId' => self::VENUE]);
        self::assertNull($this->service->venueMinimumError($other, 1));
    }

    protected function setUp(): void
    {
        // SEC-13 : le service délègue la FORME du config au validateur partagé.
        $this->service = new ConstraintValidationService(new ConstraintConfigValidator);
    }
}

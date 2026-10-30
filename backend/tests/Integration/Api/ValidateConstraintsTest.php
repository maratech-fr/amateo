<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Coach;
use App\Entity\Constraint;
use App\Entity\Reservation;
use App\Entity\Season;
use App\Entity\Team;
use App\Entity\TeamCoach;
use App\Entity\User;
use App\Entity\Venue;
use App\Entity\VenuePeriodOverride;
use App\Entity\VenueTrainingSlot;
use App\Entity\VenueTravelTime;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\ConstraintFamily;
use App\Enum\ConstraintRuleType;
use App\Enum\ConstraintScope;
use App\Enum\SeasonStatus;
use App\Enum\TeamCoachRole;
use App\Enum\VenuePeriodMode;
use App\Tests\ProvisionsPeriodPlanTrait;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * BW3 — the pre-solve gate surfaces gross constraint errors before generation.
 */
#[Group('integration')]
#[Group('phase1')]
final class ValidateConstraintsTest extends WebTestCase
{
    use ProvisionsPeriodPlanTrait;

    use TenantGucTrait;

    private EntityManagerInterface $em;

    private KernelBrowser $client;

    private Club $club;

    private User $user;

    private Season $season;

    public function testCleanConstraintsAreValid(): void
    {
        $this->client->loginUser($this->user);
        $this->client->request('POST', '/api/constraints/validate', [], [], []);

        self::assertResponseStatusCodeSame(200);
        self::assertTrue(json_decode((string) $this->client->getResponse()->getContent(), true)['valid']);
    }

    public function testContradictoryHardTimeConstraintsAreRejected(): void
    {
        // Two CLUB HARD TIME rules: "not after 10:00" vs "not before 12:00" — impossible.
        $this->constraint(['maxStartTime' => '10:00']);
        $this->constraint(['minStartTime' => '12:00']);

        $this->client->loginUser($this->user);
        $this->client->request('POST', '/api/constraints/validate', [], [], []);

        self::assertResponseStatusCodeSame(422);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertFalse($data['valid']);
        self::assertNotEmpty($data['conflicts']);
    }

    public function testVenueMinimumExceedingTeamSessionsIsRejectedBeforeGeneration(): void
    {
        // ALIGN-05 fail-fast: "au moins 2 séances à ce gymnase" for a 1-session/week
        // team is provably impossible — surface it as an ERROR before generating.
        $teamId = $this->team(sessionsPerWeek: 1);
        $constraint = new Constraint;
        $constraint->setClubId($this->club->getId());
        $constraint->setSeasonId($this->season->getId());
        $constraint->setName('min venue');
        $constraint->setScope(ConstraintScope::TEAM);
        $constraint->setScopeTargetId($teamId);
        $constraint->setFamily(ConstraintFamily::FACILITY);
        $constraint->setRuleType(ConstraintRuleType::HARD);
        $constraint->setConfig(['minAtVenueId' => 'venue-x', 'minAtVenueCount' => 2]);
        $constraint->setIsActive(true);
        $this->em->persist($constraint);
        $this->em->flush();

        $this->client->loginUser($this->user);
        $this->client->request('POST', '/api/constraints/validate', [], [], []);

        self::assertResponseStatusCodeSame(422);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertFalse($data['valid']);
        self::assertNotEmpty($data['errors'], 'the impossible venue minimum must surface as an error');
    }

    /**
     * PR A (2026-08-06) — saturation des « au moins » par gymnase. Deux équipes exigent
     * chacune 1 séance à Matéo (2 places demandées) ; le gymnase a 2 créneaux mais une
     * TROISIÈME équipe (sans minimum) en verrouille un par réservation → 1 place libre
     * pour 2 demandées. AMENDÉ le 2026-10-30 (GO fondateur, ADR-0001 amendée « la réservation
     * gagne ») : c'était un BLOQUEUR ; la réservation étant en cause, le récap AVERTIT et on génère,
     * le moteur sort `completed` + `blocked_by_reservation`.
     *
     * ⚠ P4-97 — l'occupant DOIT être une équipe SANS minimum : un pin de teamA ou teamB
     * CRÉDITE désormais le minimum de sa propre équipe (une équipe déjà servie par sa
     * réservation ne demande plus rien), donc un pin de l'une des deux ne saturerait plus
     * (cf. `testVenueMinimumSatisfiedByOwnReservationsDoesNotBlock`). C'est l'occupation par
     * un TIERS non demandeur qui vole la place et rend la saturation réelle.
     */
    public function testVenueMinimumsSaturatedByReservationsWarnWithoutBlocking(): void
    {
        $venueId = $this->venue('Matéo');
        $this->trainingSlot($venueId, dayOfWeek: 1, start: '18:00');
        $this->trainingSlot($venueId, dayOfWeek: 2, start: '18:00');
        $teamA = $this->namedTeam('Poussins');
        $teamB = $this->namedTeam('Benjamins');
        $occupier = $this->namedTeam('Cadets'); // aucun minimum : il vole une place sans rien satisfaire
        $this->minAtVenue($teamA, $venueId);
        $this->minAtVenue($teamB, $venueId);
        $this->reservation($occupier, $venueId, dayOfWeek: 1, start: '18:00');

        $this->client->loginUser($this->user);
        $this->client->request('POST', '/api/constraints/validate', [], [], []);

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertTrue($data['valid'], 'on génère : la réservation gagne');
        self::assertSame([], $data['blockers']);
        $warnings = implode(' | ', $data['warnings']);
        self::assertStringContainsString('Matéo', $warnings, 'l\'avertissement doit nommer le gymnase saturé');
        self::assertStringContainsString('Poussins', $warnings, 'les équipes qui réclament sont nommées');
        self::assertStringContainsString('Benjamins', $warnings, 'les équipes qui réclament sont nommées');
        self::assertStringContainsString('Cadets', $warnings, 'la réservation en cause est nommée');
        self::assertStringContainsString('au moins 1 séance', $warnings, 'la demande de chaque équipe est dite');
        self::assertStringContainsString('une place', $warnings, 'la place restante est dite');
        self::assertStringContainsString('Une équipe restera sans séance', $warnings, 'le manque (2 demandées, 1 libre) est dit');
        self::assertStringNotContainsString('la génération échouera', $warnings, 'elle n\'échoue plus');
    }

    /**
     * Les réservations d'une équipe elle-même demandeuse sont bien celles qui occupent le gymnase : le récap
     * la nomme, jamais une phrase trouée (« les réservations de  occupent »). L'équipe qui réclame garde une
     * séance sur la place restante : le pronostic « sans séance » serait faux.
     */
    public function testAWarningNamesTheReservingTeamEvenWhenItHasAMinimumItself(): void
    {
        $venueId = $this->venue('Matéo');
        foreach ([1, 2, 3] as $day) {
            $this->trainingSlot($venueId, dayOfWeek: $day, start: '18:00');
        }
        $reserving = $this->namedTeam('Seniors');
        $asking = $this->namedTeam('Cadets');
        $this->minAtVenue($reserving, $venueId);
        $this->minAtVenue($asking, $venueId, count: 2);
        $this->reservation($reserving, $venueId, dayOfWeek: 1, start: '18:00');
        $this->reservation($reserving, $venueId, dayOfWeek: 2, start: '18:00');

        $this->client->loginUser($this->user);
        $this->client->request('POST', '/api/constraints/validate', [], [], []);

        self::assertResponseIsSuccessful();
        $warnings = implode(' | ', json_decode((string) $this->client->getResponse()->getContent(), true)['warnings']);
        self::assertStringContainsString('Cadets', $warnings, 'témoin : l\'avertissement de saturation existe');
        self::assertStringContainsString('Seniors', $warnings, 'l\'équipe qui occupe le gymnase est nommée');
        self::assertStringNotContainsString('de  ', $warnings, 'jamais de phrase trouée');
        self::assertStringNotContainsString('sans séance', $warnings, 'Cadets garde une séance sur la place restante');
    }

    /**
     * Le pronostic du récap suit la décision « une équipe garde ses séances » : un autre gymnase a de la
     * place, l'équipe joue toutes ses séances, seul le minimum manque. « Sans séance » serait faux.
     */
    public function testAWarningDoesNotPredictNoSessionWhenAnotherVenueHasRoom(): void
    {
        $venueId = $this->venue('Matéo');
        $otherId = $this->venue('Platane');
        $this->trainingSlot($venueId, dayOfWeek: 1, start: '18:00');
        $this->trainingSlot($otherId, dayOfWeek: 2, start: '18:00');
        $asking = $this->namedTeam('Poussins');
        $occupier = $this->namedTeam('Cadets');
        $this->minAtVenue($asking, $venueId);
        $this->reservation($occupier, $venueId, dayOfWeek: 1, start: '18:00');

        $this->client->loginUser($this->user);
        $this->client->request('POST', '/api/constraints/validate', [], [], []);

        self::assertResponseIsSuccessful();
        $warnings = implode(' | ', json_decode((string) $this->client->getResponse()->getContent(), true)['warnings']);
        self::assertStringContainsString('Matéo', $warnings, 'témoin : l\'avertissement de saturation existe');
        self::assertStringNotContainsString('sans séance', $warnings, 'Platane a de la place');
    }

    /**
     * Récap ⇄ moteur : une équipe servie par ses propres réservations ne transforme pas en BLOCAGE ce que
     * le moteur sait produire (la réservation prive l'autre équipe). Jumeau parité :
     * `CapacityMirrorParityTest::testFloorServedByOwnLocksStaysAWarningOnBothSides`.
     */
    public function testAFloorServedByOwnReservationsLeavesTheOtherTeamAsAWarningNotABlocker(): void
    {
        $venueId = $this->venue('Matéo');
        $this->trainingSlot($venueId, dayOfWeek: 1, start: '18:00');
        $this->trainingSlot($venueId, dayOfWeek: 2, start: '18:00');
        $served = $this->namedTeam('Seniors');
        $asking = $this->namedTeam('Cadets');
        $this->minAtVenue($served, $venueId, count: 2);
        $this->minAtVenue($asking, $venueId);
        $this->reservation($served, $venueId, dayOfWeek: 1, start: '18:00');
        $this->reservation($served, $venueId, dayOfWeek: 2, start: '18:00');

        $this->client->loginUser($this->user);
        $this->client->request('POST', '/api/constraints/validate', [], [], []);

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame([], $data['blockers']);
        self::assertStringContainsString('Cadets', implode(' | ', $data['warnings']));
    }

    /** Sans réservation en cause, 3 « au moins » pour 2 places : le moteur échoue, le récap bloque toujours. */
    public function testVenueMinimumsExceedingTheGridStillBlockWithoutAnyReservation(): void
    {
        $this->seedThreeMinimumsForTwoPlaces('Matéo');

        $this->client->loginUser($this->user);
        $this->client->request('POST', '/api/constraints/validate', [], [], []);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Matéo', implode(' | ', json_decode((string) $this->client->getResponse()->getContent(), true)['blockers']));
    }

    /** Une réservation ailleurs n'excuse rien : elle n'est pas la cause de la saturation. */
    public function testVenueMinimumsExceedingTheGridStillBlockWithAReservationElsewhere(): void
    {
        $this->seedThreeMinimumsForTwoPlaces('Matéo');
        $elsewhere = $this->venue('Platane');
        $this->trainingSlot($elsewhere, dayOfWeek: 3, start: '18:00');
        $this->reservation($this->team(sessionsPerWeek: 1), $elsewhere, dayOfWeek: 3, start: '18:00');

        $this->client->loginUser($this->user);
        $this->client->request('POST', '/api/constraints/validate', [], [], []);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Matéo', implode(' | ', json_decode((string) $this->client->getResponse()->getContent(), true)['blockers']));
    }

    /**
     * NR — P4-97 : une équipe DÉJÀ SERVIE par ses PROPRES réservations au gymnase ne
     * déclenche AUCUN faux bloqueur. Matéo n'a que 2 créneaux, teamA exige 2 séances là
     * et y est réservée les 2 jours → son minimum est saturé par ses verrous
     * (effective_min = 2 − 2 = 0). Avant le fix, le miroir comptait demande=2 pour 0 place
     * libre (les 2 créneaux verrouillés) → faux « 0 place, la génération échouera »,
     * c'était LE rouge e2e de la PR #569.
     */
    public function testVenueMinimumSatisfiedByOwnReservationsDoesNotBlock(): void
    {
        $venueId = $this->venue('Matéo');
        $this->trainingSlot($venueId, dayOfWeek: 1, start: '18:00');
        $this->trainingSlot($venueId, dayOfWeek: 2, start: '18:00');
        $teamA = $this->team(sessionsPerWeek: 2);
        $this->minAtVenue($teamA, $venueId, count: 2);
        $this->reservation($teamA, $venueId, dayOfWeek: 1, start: '18:00');
        $this->reservation($teamA, $venueId, dayOfWeek: 2, start: '18:00');

        $this->client->loginUser($this->user);
        $this->client->request('POST', '/api/constraints/validate', [], [], []);

        self::assertResponseStatusCodeSame(200);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertTrue($data['valid'], 'une équipe servie par ses propres réservations ne doit pas bloquer');
        self::assertSame([], $data['blockers']);
    }

    /** Témoin du cas ci-dessus : sans la réservation, 2 places libres pour 2 demandées — rien ne bloque. */
    public function testVenueMinimumsWithinFreeCapacityDoNotBlock(): void
    {
        $venueId = $this->venue('Matéo');
        $this->trainingSlot($venueId, dayOfWeek: 1, start: '18:00');
        $this->trainingSlot($venueId, dayOfWeek: 2, start: '18:00');
        $this->minAtVenue($this->team(sessionsPerWeek: 1), $venueId);
        $this->minAtVenue($this->team(sessionsPerWeek: 1), $venueId);

        $this->client->loginUser($this->user);
        $this->client->request('POST', '/api/constraints/validate', [], [], []);

        self::assertResponseStatusCodeSame(200);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertTrue($data['valid']);
        self::assertSame([], $data['blockers']);
    }

    /**
     * PR A (2026-08-06) — coach indisponible × créneau réservé en dur : AVERTISSEMENT
     * avant génération (le verrou est souverain, la séance se posera quand même — mais
     * on le dit AVANT, pas en INFO post-solve). N'invalide rien : `valid` reste vrai.
     */
    public function testReservationOnCoachUnavailableDayWarnsButStaysValid(): void
    {
        $venueId = $this->venue('Matéo');
        $teamId = $this->team(sessionsPerWeek: 1);
        $coachId = $this->mainCoach($teamId, 'Emerick');
        // P4-44 : une réservation repose sur un créneau RÉEL — sans lui elle serait
        // orpheline et bloquerait, ce qui n'est pas ce que ce cas mesure.
        $this->trainingSlot($venueId, dayOfWeek: 2, start: '17:00');
        $this->reservation($teamId, $venueId, dayOfWeek: 2, start: '17:00');

        $constraint = new Constraint;
        $constraint->setClubId($this->club->getId());
        $constraint->setSeasonId($this->season->getId());
        $constraint->setName('Emerick indisponible le mardi');
        $constraint->setScope(ConstraintScope::COACH);
        $constraint->setScopeTargetId($coachId);
        $constraint->setFamily(ConstraintFamily::COACH_AVAILABILITY);
        $constraint->setRuleType(ConstraintRuleType::HARD);
        // SEC-13 PR B : la cible du coach est le scope (l. 174), plus une clé du config.
        $constraint->setConfig(['unavailableDays' => [2]]);
        $constraint->setIsActive(true);
        $this->em->persist($constraint);
        $this->em->flush();

        $this->client->loginUser($this->user);
        $this->client->request('POST', '/api/constraints/validate', [], [], []);

        self::assertResponseStatusCodeSame(200);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertTrue($data['valid'], 'un avertissement n\'invalide rien — le verrou est souverain');
        $warnings = implode(' | ', $data['warnings']);
        self::assertStringContainsString('Emerick', $warnings);
        self::assertStringContainsString('mardi', $warnings);
        self::assertStringContainsString('Matéo', $warnings);
    }

    /**
     * NR (P4-319, axe constraint semantics) — « contrainte de vacances OFF par défaut ».
     *
     * Une COACH_AVAILABILITY de SAISON est OFF par défaut en période de VACANCES
     * (PeriodConstraintSelector, décision fondateur 2026-10-10) : elle ne part pas au solveur
     * pour cette période. Le gate calcule donc son avertissement « coach indisponible » sur les
     * contraintes EN VIGUEUR (la sélection de période), pas sur la saison brute — sinon il
     * crierait un conflit qui ne se produira jamais pour ces vacances.
     *
     * NON VACANT par son TÉMOIN {@see testClosurePeriodKeepsSeasonCoachUnavailabilityAndWarns} :
     * même scénario en FERMETURE (tout hérité) → la MÊME indisponibilité est gardée et l'avertissement
     * RESSORT. Un gate qui lirait la saison brute avertirait dans les deux cas — ici on exige le silence.
     */
    public function testHolidaySeasonCoachUnavailabilityIsOffByDefaultAndDoesNotWarn(): void
    {
        $warnings = implode(' | ', $this->validateSeasonCoachUnavailabilityForPeriod(CalendarEntryPeriodType::HOLIDAY)['warnings']);

        self::assertStringNotContainsString(
            'malgré son indisponibilité',
            $warnings,
            'en vacances, la dispo coach de saison est OFF par défaut : le gate ne doit pas en avertir',
        );
    }

    /** Témoin de P4-319 — en FERMETURE, tout est hérité : la même indisponibilité de saison AVERTIT bien. */
    public function testClosurePeriodKeepsSeasonCoachUnavailabilityAndWarns(): void
    {
        $warnings = implode(' | ', $this->validateSeasonCoachUnavailabilityForPeriod(CalendarEntryPeriodType::CLOSURE)['warnings']);

        self::assertStringContainsString('malgré son indisponibilité', $warnings, 'en fermeture, la dispo coach héritée AVERTIT');
        self::assertStringContainsString('Emerick', $warnings);
    }

    /**
     * NR — P3-20 (décision fondateur 2026-08-06) : « si j'ai déclaré 3 réservations alors
     * que l'équipe ne veut que 2 créneaux, on BLOQUE — c'est une incohérence gestionnaire ».
     * Ce n'est pas une préférence bafouée : un verrou étant pré-placé hors modèle
     * (ALIGN-07), les trois s'imposeraient et l'équipe jouerait plus que déclaré, en
     * silence. Le cas naît du geste de désactivation (les réservations d'un gymnase
     * désactivé sont conservées ; déplacer la séance ailleurs en fabrique une de trop).
     */
    public function testMoreReservationsThanSessionsPerWeekBlocksTheGeneration(): void
    {
        $venueId = $this->venue('Matéo');
        $teamId = $this->team(sessionsPerWeek: 2);
        $this->reservation($teamId, $venueId, dayOfWeek: 1, start: '18:00');
        $this->reservation($teamId, $venueId, dayOfWeek: 2, start: '18:00');
        $this->reservation($teamId, $venueId, dayOfWeek: 3, start: '18:00');

        $this->client->loginUser($this->user);
        $this->client->request('POST', '/api/constraints/validate', [], [], []);

        self::assertResponseStatusCodeSame(422);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertFalse($data['valid']);
        $blockers = implode(' | ', $data['blockers']);
        self::assertStringContainsString('3 réservations', $blockers);
        self::assertStringContainsString('2 séances', $blockers);
        // Le message doit dire QUOI FAIRE : un bloqueur sans recours enferme le gestionnaire.
        self::assertStringContainsString('retirez 1 réservation', $blockers);
    }

    /** Témoin : autant de réservations que de séances déclarées — rien à signaler. */
    public function testExactlyAsManyReservationsAsSessionsIsFine(): void
    {
        $venueId = $this->venue('Matéo');
        $teamId = $this->team(sessionsPerWeek: 2);
        $this->trainingSlot($venueId, dayOfWeek: 1, start: '18:00');
        $this->trainingSlot($venueId, dayOfWeek: 2, start: '18:00');
        $this->reservation($teamId, $venueId, dayOfWeek: 1, start: '18:00');
        $this->reservation($teamId, $venueId, dayOfWeek: 2, start: '18:00');

        $this->client->loginUser($this->user);
        $this->client->request('POST', '/api/constraints/validate', [], [], []);

        self::assertResponseStatusCodeSame(200);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame([], $data['blockers']);
    }

    /**
     * NR — P4-44 (décision fondateur 2026-08-07 : « je veux bloquer »). Une réservation
     * qui ne retombe sur AUCUN créneau bloque la génération, et le message la NOMME
     * avec son heure — sans quoi le gestionnaire ne saurait pas laquelle retirer
     * (l'écran « Réserver » ne peut pas l'afficher : sa grille boucle sur les créneaux).
     *
     * Ce que ça évite : sur le SOCLE, le moteur PLACE l'épinglage hors grille et rend
     * `completed` (mesuré) — le planning distribué envoyait les équipes devant une
     * porte fermée, sans une alerte.
     */
    public function testAReservationWithoutAMatchingSlotBlocksAndNamesIt(): void
    {
        $venueId = $this->venue('Matéo');
        $this->trainingSlot($venueId, dayOfWeek: 2, start: '18:30'); // la grille a BOUGÉ
        $teamId = $this->team(sessionsPerWeek: 1);
        $this->reservation($teamId, $venueId, dayOfWeek: 2, start: '18:00'); // l'ancien horaire

        $this->client->loginUser($this->user);
        $this->client->request('POST', '/api/constraints/validate', [], [], []);

        self::assertResponseStatusCodeSame(422);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertFalse($data['valid']);
        $blockers = implode(' | ', $data['blockers']);
        self::assertStringContainsString('Matéo', $blockers);
        self::assertStringContainsString('mardi', $blockers, 'le jour, pour retrouver la ligne');
        self::assertStringContainsString('18:00', $blockers, 'et l\'HEURE — le message d\'OrphanPinGuard ne la donnait pas');
    }

    /** Témoin : la réservation retombe sur son créneau — rien à signaler. */
    public function testAReservationOnAnExistingSlotDoesNotBlock(): void
    {
        $venueId = $this->venue('Matéo');
        $this->trainingSlot($venueId, dayOfWeek: 2, start: '18:00');
        $this->reservation($this->team(sessionsPerWeek: 1), $venueId, dayOfWeek: 2, start: '18:00');

        $this->client->loginUser($this->user);
        $this->client->request('POST', '/api/constraints/validate', [], [], []);

        self::assertResponseStatusCodeSame(200);
        self::assertSame([], json_decode((string) $this->client->getResponse()->getContent(), true)['blockers']);
    }

    public function testOverlayValidationIncludesInheritedPermanentConstraints(): void
    {
        $this->constraint(['maxStartTime' => '10:00']);
        $this->constraint(['minStartTime' => '12:00']);
        $entry = $this->period(CalendarEntryPeriodType::CLOSURE);

        $this->client->loginUser($this->user);
        $this->client->request(
            'POST',
            '/api/constraints/validate',
            [],
            [],
            [],
            json_encode(['calendarEntryId' => $entry->getId()], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(422);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertFalse($data['valid']);
        self::assertNotEmpty($data['conflicts'], 'overlay validation must include inherited permanent constraints');
    }

    /**
     * #8 — le gymnase est DÉSACTIVÉ pour la période : le solveur ne recevra pas les
     * contraintes qui le nomment. Le récap ne doit donc pas les valider en silence — il
     * les retire ET les annonce. Ici par le SCOPE (FACILITY + scopeTargetId).
     */
    public function testPermanentConstraintsNamingADisabledVenueAreWarnedAndDropped(): void
    {
        $venueId = $this->venue('Barros');
        $entry = $this->period(CalendarEntryPeriodType::CLOSURE);
        $this->disableVenueForPeriod($this->planIdOf($entry), $venueId);

        // Deux HARD TIME sur CE gymnase, contradictoires entre elles : si le filtre
        // disparaissait, le conflit ressortirait en 422 — l'assertion n'est pas vacante.
        $this->venueScopedConstraint('SM1 impose Barros', $venueId, ['maxStartTime' => '10:00']);
        $this->venueScopedConstraint('SM2 impose Barros', $venueId, ['minStartTime' => '12:00']);

        $data = $this->validateForPeriod($entry);

        self::assertResponseStatusCodeSame(200);
        self::assertTrue($data['valid'], 'un avertissement n’invalide rien');
        self::assertSame([], $data['conflicts'], 'les contraintes du gymnase désactivé sont retirées du jeu validé');
        // P4-57 — on compte les avertissements DE CE SUJET, plus la totalité de la réponse.
        // Compter tout faisait de ce test l'otage de n'importe quel warning voisin : il a
        // rougi le jour où la prévention « gymnase sans créneau » est arrivée, alors qu'elle
        // disait vrai et ne le concernait pas. Un test doit échouer sur SON sujet.
        $aboutDisabledVenue = array_values(array_filter(
            $data['warnings'],
            static fn (string $warning): bool => str_contains($warning, 'désactivé pour cette période'),
        ));
        self::assertCount(2, $aboutDisabledVenue);
        self::assertStringContainsString(
            '« SM1 impose Barros » vise le gymnase Barros',
            implode(' | ', $aboutDisabledVenue),
            'l’avertissement doit nommer LA CONTRAINTE et LE GYMNASE',
        );
    }

    /**
     * #8 — le même filtre s’applique aux contraintes DATÉES de la période. Ne filtrer que
     * les permanentes recréerait la désynchronisation gate/payload (buildForOverlay filtre
     * les deux). Ici le gymnase est nommé par la CONFIG (`minAtVenueId`).
     */
    public function testDatedConstraintNamingADisabledVenueIsWarnedAndDropped(): void
    {
        $venueId = $this->venue('Barros');
        $entry = $this->period(CalendarEntryPeriodType::CLOSURE);
        $this->disableVenueForPeriod($this->planIdOf($entry), $venueId);

        // « au moins 2 séances à Barros » pour une équipe à 1 séance/semaine : ERREUR
        // fail-fast si la contrainte reste dans le jeu validé. Elle nomme un gymnase
        // désactivé ⇒ elle doit sortir (et être annoncée), donc plus d’erreur.
        $constraint = new Constraint;
        $constraint->setClubId($this->club->getId());
        $constraint->setSeasonId($this->season->getId());
        $constraint->setName('SM1 exige 2 séances à Barros');
        $constraint->setScope(ConstraintScope::TEAM);
        $constraint->setScopeTargetId($this->team(sessionsPerWeek: 1));
        $constraint->setFamily(ConstraintFamily::FACILITY);
        $constraint->setRuleType(ConstraintRuleType::HARD);
        $constraint->setConfig(['minAtVenueId' => $venueId, 'minAtVenueCount' => 2]);
        $constraint->setCalendarEntryId($entry->getId());
        $constraint->setIsActive(true);
        $this->em->persist($constraint);
        $this->em->flush();

        $data = $this->validateForPeriod($entry);

        self::assertResponseStatusCodeSame(200);
        self::assertTrue($data['valid']);
        self::assertSame([], $data['errors'], 'la datée visant le gymnase désactivé est retirée, donc plus rien à valider');
        // Ce test garde son assertion EXACTE sur SES warnings (revue #341 round 2 —
        // `assertContains` avait relâché la garantie « un seul warning », qui est précisément
        // ce que ce test épingle).
        //
        // ⚑ P4-57 — le filtre est devenu POSITIF. Il excluait « ce qui parle de gymnases »,
        // donc il fallait l'allonger à chaque famille de warning nouvelle : il a rougi à
        // l'arrivée des préventions pré-solve, qui disaient vrai et ne le concernaient pas.
        // Retenir ce qui porte SA marque (« désactivé pour cette période ») garde la même
        // garantie sans hériter du bruit des voisins.
        self::assertSame(
            ['« SM1 exige 2 séances à Barros » vise le gymnase Barros, désactivé pour cette période : elle ne sera pas appliquée.'],
            array_values(array_filter($data['warnings'], static fn (string $w): bool => str_contains($w, 'désactivé pour cette période'))),
            'exactement UN warning de SÉLECTION ; les autres familles ont leurs propres NR',
        );
    }

    public function testNoWarningWhenTheVenueStaysEnabledForThePeriod(): void
    {
        $venueId = $this->venue('Barros');
        $entry = $this->period(CalendarEntryPeriodType::CLOSURE);
        $this->venueScopedConstraint('SM1 impose Barros', $venueId, ['maxStartTime' => '10:00']);

        $data = $this->validateForPeriod($entry);

        self::assertResponseStatusCodeSame(200);
        self::assertTrue($data['valid']);
        // P4-57 — l'assertion porte sur SON sujet (aucune contrainte écartée), plus sur le
        // vide global : le message le disait déjà, l'assertion ne le suivait pas.
        self::assertSame(
            [],
            array_values(array_filter($data['warnings'], static fn (string $w): bool => str_contains($w, 'désactivé pour cette période'))),
            'sans désactivation, aucune contrainte n’est écartée',
        );
    }

    /**
     * SEC-13 — LE 422 À L'ÉCRITURE, sur la vraie route.
     *
     * ⚠ Ce test existe parce que la falsification l'a réclamé : retirer l'appel au
     * validateur dans `ConstraintStateProcessor` laissait TOUTE la suite verte. Le
     * validateur avait ses 26 cas unitaires, mais son CÂBLAGE — le seul endroit
     * qui empêche la donnée fautive d'entrer — n'était gardé par personne.
     *
     * Le cas mesuré sur l'API réelle le 2026-08-07 : `{"maxStartTme":"19:00"}`
     * rendait 201, la contrainte s'affichait « HARD · active », et le solveur
     * plaçait la séance à 20:00.
     */
    public function testWritingAnUnknownConfigKeyIsRefusedWithFourTwentyTwo(): void
    {
        $this->client->loginUser($this->user);

        $this->client->request('POST', '/api/constraints', [], [], [
            'CONTENT_TYPE' => 'application/ld+json',
        ], json_encode([
            'scope' => 'CLUB', 'family' => 'TIME', 'ruleType' => 'HARD',
            'name' => 'Rien après 19h (faute de frappe)',
            'config' => ['maxStartTme' => '19:00'],
            'isActive' => true, 'sortOrder' => 0,
        ], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString(
            'maxStartTme',
            (string) $this->client->getResponse()->getContent(),
            'le refus doit NOMMER la clé fautive — sinon le gestionnaire ne peut pas corriger',
        );
    }

    public function testWritingAnAberrantValueIsRefusedEvenWithAKnownKey(): void
    {
        $this->client->loginUser($this->user);

        $this->client->request('POST', '/api/constraints', [], [], [
            'CONTENT_TYPE' => 'application/ld+json',
        ], json_encode([
            'scope' => 'CLUB', 'family' => 'TIME', 'ruleType' => 'HARD',
            'name' => 'Heure impossible',
            'config' => ['maxStartTime' => '25:99'],
            'isActive' => true, 'sortOrder' => 0,
        ], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(422);
    }

    public function testAValidConfigStillWritesFine(): void
    {
        // TÉMOIN : sans lui, un validateur qui refuserait TOUT passerait les deux
        // tests ci-dessus au vert en rendant l'application inutilisable.
        $this->client->loginUser($this->user);

        $this->client->request('POST', '/api/constraints', [], [], [
            'CONTENT_TYPE' => 'application/ld+json',
        ], json_encode([
            'scope' => 'CLUB', 'family' => 'TIME', 'ruleType' => 'PREFERRED',
            'name' => 'Rien après 19h',
            'config' => ['maxStartTime' => '19:00'],
            'isActive' => true, 'sortOrder' => 0,
        ], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(201);
    }

    // ── Chantier H : le relais de l'adjoint tolère un retard au RÉCAP (ValidateConstraintsController) ──

    public function testAToleratedLateArrivalWithAnAvailableAdjointWarnsInsteadOfBlocking(): void
    {
        // Rillieux : Sébastien finit à 20:15 au Mont Blanc (U21 19:00-20:15), rejoint le Louison
        // Bobet (SENIORS 20:00) ; trajet 15 min à pied (non véhiculé), chevauchement 15 min →
        // retard 30 = la tolérance posée. Jules (adjoint) ouvre la séance → le récap AVERTIT, il ne
        // bloque PAS la paire dédoublante.
        $ctx = $this->seedRelayScenario(tolerance: 30, bareme: 15, adjoint: 'available');
        $data = $this->validate();

        self::assertStringNotContainsString('deux créneaux en même temps', implode(' | ', $data['blockers']), 'un retard toléré + adjoint dispo ne bloque plus la paire');
        $warnings = implode(' | ', $data['warnings']);
        self::assertStringContainsString('Sébastien', $warnings);
        self::assertStringContainsString('en retard', $warnings);
        self::assertStringContainsString('Jules', $warnings);
        self::assertStringContainsString('adjoint', $warnings);
        self::assertStringContainsString($ctx['seniorsName'], $warnings, 'l\'avertissement nomme la séance rejointe');
        unset($ctx);
    }

    public function testAToleranceWithoutAnyAssistantStillBlocks(): void
    {
        // Abus : tolérance posée SANS aucun lien ASSISTANT sur la séance rejointe → personne ne
        // l'ouvre, le dédoublement reste un BLOQUEUR (l'adjoint est obligatoire).
        $this->seedRelayScenario(tolerance: 30, bareme: 15, adjoint: 'none');
        self::assertStringContainsString('deux créneaux en même temps', implode(' | ', $this->validate()['blockers']));
    }

    public function testAToleranceWithAnUnavailableAdjointStillBlocks(): void
    {
        // Abus : l'adjoint existe mais est DÉCLARÉ indisponible sur la fenêtre d'ouverture → il ne
        // peut pas ouvrir, le dédoublement reste bloqué (la dispo de l'adjoint est VÉRIFIÉE).
        $this->seedRelayScenario(tolerance: 30, bareme: 15, adjoint: 'declared_unavailable');
        self::assertStringContainsString('deux créneaux en même temps', implode(' | ', $this->validate()['blockers']));
    }

    public function testAToleranceWithTheAdjointBookedElsewhereStillBlocks(): void
    {
        // Abus : l'adjoint est lui-même coach MAIN d'une 3ᵉ séance qui chevauche, dans un AUTRE
        // gymnase → il n'est pas libre d'ouvrir, le dédoublement reste bloqué.
        $this->seedRelayScenario(tolerance: 30, bareme: 15, adjoint: 'booked_elsewhere');
        self::assertStringContainsString('deux créneaux en même temps', implode(' | ', $this->validate()['blockers']));
    }

    public function testADelayOfThirtyOneViaTravelStillBlocks(): void
    {
        // Abus : chevauchement 15 + barème 16 → retard 31 > plafond 30 → au-delà de la tolérance,
        // le dédoublement reste un bloqueur même avec un adjoint disponible.
        $this->seedRelayScenario(tolerance: 30, bareme: 16, adjoint: 'available');
        self::assertStringContainsString('deux créneaux en même temps', implode(' | ', $this->validate()['blockers']));
    }

    public function testAZeroToleranceStillBlocks(): void
    {
        // Rétro-compat : tolérance 0 (défaut) = aucune tolérance → le dédoublement bloque comme
        // avant le chantier H, adjoint ou pas.
        $this->seedRelayScenario(tolerance: 0, bareme: 15, adjoint: 'available');
        self::assertStringContainsString('deux créneaux en même temps', implode(' | ', $this->validate()['blockers']));
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get('security.user_password_hasher');

        $uid = uniqid('', true);

        $this->club = new Club;
        $this->club->setName('Constraint Test Club');
        $this->club->setSlug('constraint-test-' . $uid);
        $this->club->setTimezone('Europe/Paris');
        $this->club->setLocale('fr');
        $this->club->setOnboardingCompleted(true);
        $this->club->setFfbbClubCode('CST' . strtoupper(substr(md5($uid), 0, 10)));
        $this->em->persist($this->club);

        $this->user = new User;
        $this->user->setEmail('cst' . $uid . '@test.com');
        $this->user->setFirstName('Cst');
        $this->user->setLastName('Tester');
        $this->user->setPasswordHash($hasher->hashPassword($this->user, 'pass'));
        $this->em->persist($this->user);

        $this->em->flush();

        $this->scopeGucToClub($this->club->getId());

        $cu = new ClubUser;
        $cu->setClubId($this->club->getId());
        $cu->setUserId($this->user->getId());
        $cu->setRole('admin');
        $cu->setIsActive(true);
        $this->em->persist($cu);

        $this->season = new Season;
        $this->season->setClubId($this->club->getId());
        $this->season->setName('2025-2026');
        $this->season->setStartDate(new DateTimeImmutable('2025-09-01'));
        $this->season->setEndDate(new DateTimeImmutable('2026-06-30'));
        $this->season->setStatus(SeasonStatus::ACTIVE);
        $this->em->persist($this->season);

        $this->em->flush();
    }

    private function seedThreeMinimumsForTwoPlaces(string $venueName): void
    {
        $venueId = $this->venue($venueName);
        $this->trainingSlot($venueId, dayOfWeek: 1, start: '18:00');
        $this->trainingSlot($venueId, dayOfWeek: 2, start: '18:00');
        foreach ([1, 2, 3] as $ignored) {
            $this->minAtVenue($this->team(sessionsPerWeek: 1), $venueId);
        }
    }

    /** @param array<string, mixed> $config */
    private function constraint(array $config): void
    {
        $constraint = new Constraint;
        $constraint->setClubId($this->club->getId());
        $constraint->setSeasonId($this->season->getId());
        $constraint->setName('rule');
        $constraint->setScope(ConstraintScope::CLUB);
        $constraint->setFamily(ConstraintFamily::TIME);
        $constraint->setRuleType(ConstraintRuleType::HARD);
        $constraint->setConfig($config);
        $constraint->setIsActive(true);
        $this->em->persist($constraint);
        $this->em->flush();
    }

    /** Persist a minimal venue scoped to the test club/season, return its id. */
    private function venue(string $name): string
    {
        $venue = new Venue;
        $venue->setClubId($this->club->getId());
        $venue->setSeasonId($this->season->getId());
        $venue->setName($name);
        $venue->setCanSplit(false);
        $venue->setSource('manual');
        $this->em->persist($venue);
        $this->em->flush();

        return $venue->getId();
    }

    /** Un créneau SAISONNIER (schedulePlanId null) — celui que `buildForClubSeason` sérialise. */
    private function trainingSlot(string $venueId, int $dayOfWeek, string $start): void
    {
        $slot = new VenueTrainingSlot;
        $slot->setClubId($this->club->getId());
        $slot->setSeasonId($this->season->getId());
        $slot->setVenueId($venueId);
        $slot->setDayOfWeek($dayOfWeek);
        $slot->setStartTime(new DateTimeImmutable($start));
        $slot->setDurationMinutes(90);
        $slot->setCapacity(1);
        $this->em->persist($slot);
        $this->em->flush();
    }

    /** « Au moins 1 séance dans ce gymnase » pour l'équipe — la contrainte du scénario BCCL. */
    private function minAtVenue(string $teamId, string $venueId, int $count = 1): void
    {
        $constraint = new Constraint;
        $constraint->setClubId($this->club->getId());
        $constraint->setSeasonId($this->season->getId());
        $constraint->setName(\sprintf('Au moins %d à Matéo', $count));
        $constraint->setScope(ConstraintScope::TEAM);
        $constraint->setScopeTargetId($teamId);
        $constraint->setFamily(ConstraintFamily::FACILITY);
        $constraint->setRuleType(ConstraintRuleType::HARD);
        $constraint->setConfig(['minAtVenueId' => $venueId, 'minAtVenueCount' => $count]);
        $constraint->setIsActive(true);
        $this->em->persist($constraint);
        $this->em->flush();
    }

    /**
     * Une indisponibilité coach de SAISON (permanente, scope COACH) — plein-jour sur ``$days``.
     *
     * @param list<int> $days
     */
    private function seasonCoachUnavailability(string $coachId, string $name, array $days): void
    {
        $constraint = new Constraint;
        $constraint->setClubId($this->club->getId());
        $constraint->setSeasonId($this->season->getId());
        $constraint->setName($name);
        $constraint->setScope(ConstraintScope::COACH);
        $constraint->setScopeTargetId($coachId);
        $constraint->setFamily(ConstraintFamily::COACH_AVAILABILITY);
        $constraint->setRuleType(ConstraintRuleType::HARD);
        $constraint->setConfig(['unavailableDays' => $days]);
        $constraint->setIsActive(true);
        $this->em->persist($constraint);
        $this->em->flush();
    }

    /** Une réservation ancrée à un PLAN de période (schedulePlanId non null) — verrou de la période. */
    private function planScopedReservation(string $teamId, string $venueId, string $schedulePlanId, int $dayOfWeek, string $start): void
    {
        $reservation = new Reservation;
        $reservation->setClubId($this->club->getId());
        $reservation->setSeasonId($this->season->getId());
        $reservation->setTeamId($teamId);
        $reservation->setVenueId($venueId);
        $reservation->setDayOfWeek($dayOfWeek);
        $reservation->setStartTime(new DateTimeImmutable($start));
        $reservation->setDurationMinutes(90);
        $reservation->setSchedulePlanId($schedulePlanId);
        $this->em->persist($reservation);
        $this->em->flush();
    }

    /** Une réservation du socle (schedulePlanId null) — un verrou HARD pour le solveur. */
    private function reservation(string $teamId, string $venueId, int $dayOfWeek, string $start): void
    {
        $reservation = new Reservation;
        $reservation->setClubId($this->club->getId());
        $reservation->setSeasonId($this->season->getId());
        $reservation->setTeamId($teamId);
        $reservation->setVenueId($venueId);
        $reservation->setDayOfWeek($dayOfWeek);
        $reservation->setStartTime(new DateTimeImmutable($start));
        $reservation->setDurationMinutes(90);
        $this->em->persist($reservation);
        $this->em->flush();
    }

    /**
     * @return array{blockers: list<string>, warnings: list<string>}
     */
    private function validate(): array
    {
        // Le récap répond 200 (valide) OU 422 (invalide) — on lit le corps dans les deux cas.
        $this->client->loginUser($this->user);
        $this->client->request('POST', '/api/constraints/validate', [], [], []);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        \assert(\is_array($data));

        return ['blockers' => $data['blockers'] ?? [], 'warnings' => $data['warnings'] ?? []];
    }

    /**
     * Décor du relais : U21 (Mont Blanc, mardi 19:00-20:15) + SENIORS (Louison Bobet, mardi 20:00),
     * les DEUX réservées en dur, Sébastien principal des deux (non véhiculé → barème à pied). La
     * séance des SENIORS porte la tolérance. `adjoint` règle Jules : dispo, absent, indispo déclarée
     * ou occupé ailleurs. `bareme` = trajet à pied Mont Blanc↔Louison Bobet.
     *
     * @return array{u21: string, seniors: string, seniorsName: string, sebastien: string, jules: string}
     */
    private function seedRelayScenario(int $tolerance, int $bareme, string $adjoint): array
    {
        $gymMB = $this->venue('Mont Blanc');
        $gymLB = $this->venue('Louison Bobet');
        $u21 = $this->namedTeam('U21');
        $seniors = $this->namedTeam('SENIORS');

        $sebastien = $this->coachOnly('Sébastien');
        $this->linkCoach($u21, $sebastien, TeamCoachRole::MAIN);
        $this->linkCoach($seniors, $sebastien, TeamCoachRole::MAIN);

        $jules = $this->coachOnly('Jules');
        if ('none' !== $adjoint) {
            $this->linkCoach($seniors, $jules, TeamCoachRole::ASSISTANT);
        }
        if ('declared_unavailable' === $adjoint) {
            $this->coachUnavailable($jules, 2, '20:00', '21:30');
        }
        if ('booked_elsewhere' === $adjoint) {
            // Jules principal d'une 3ᵉ équipe réservée en même temps dans un 3ᵉ gymnase.
            $gym3 = $this->venue('Gymnase C');
            $third = $this->namedTeam('U15');
            $this->linkCoach($third, $jules, TeamCoachRole::MAIN);
            $this->reservationWith($third, $gym3, 2, '20:00', 90, 0);
        }

        // Barème de trajet à pied Mont Blanc ↔ Louison Bobet (couple trié).
        [$a, $b] = $gymMB < $gymLB ? [$gymMB, $gymLB] : [$gymLB, $gymMB];
        $this->travel($a, $b, $bareme);

        // U21 19:00-20:15 (75 min) ; SENIORS 20:00 porteuse de la tolérance.
        $this->reservationWith($u21, $gymMB, 2, '19:00', 75, 0);
        $this->reservationWith($seniors, $gymLB, 2, '20:00', 90, $tolerance);

        return ['u21' => $u21, 'seniors' => $seniors, 'seniorsName' => 'SENIORS', 'sebastien' => $sebastien, 'jules' => $jules];
    }

    private function namedTeam(string $name): string
    {
        $team = new Team;
        $team->setClubId($this->club->getId())->setSeasonId($this->season->getId());
        $team->setSportCategoryId($this->club->getId())->setPriorityTierId(3)->setName($name)->setSessionsPerWeek(2)->setIsActive(true);
        $this->em->persist($team);
        $this->em->flush();

        return $team->getId();
    }

    private function coachOnly(string $firstName, bool $vehicled = false): string
    {
        $coach = new Coach;
        $coach->setClubId($this->club->getId())->setSeasonId($this->season->getId());
        $coach->setFirstName($firstName)->setLastName('Dupont')->setIsVehicled($vehicled);
        $this->em->persist($coach);
        $this->em->flush();

        return $coach->getId();
    }

    private function linkCoach(string $teamId, string $coachId, TeamCoachRole $role): void
    {
        $link = new TeamCoach;
        $link->setClubId($this->club->getId())->setSeasonId($this->season->getId());
        $link->setTeamId($teamId)->setCoachId($coachId)->setRole($role);
        $this->em->persist($link);
        $this->em->flush();
    }

    private function travel(string $venueIdA, string $venueIdB, int $walkingMinutes): void
    {
        $row = new VenueTravelTime;
        $row->setClubId($this->club->getId())->setSeasonId($this->season->getId());
        $row->setVenueAId($venueIdA)->setVenueBId($venueIdB)->setWalkingMinutes($walkingMinutes);
        $this->em->persist($row);
        $this->em->flush();
    }

    private function reservationWith(string $teamId, string $venueId, int $dayOfWeek, string $start, int $duration, int $tolerance): void
    {
        // Le créneau qui PORTE la réservation (sinon « ne correspond à aucun créneau » bloque pour
        // une autre raison que le dédoublement qu'on veut isoler).
        $slot = new VenueTrainingSlot;
        $slot->setClubId($this->club->getId())->setSeasonId($this->season->getId());
        $slot->setVenueId($venueId)->setDayOfWeek($dayOfWeek)->setStartTime(new DateTimeImmutable($start))->setDurationMinutes($duration)->setCapacity(1);
        $this->em->persist($slot);

        $reservation = new Reservation;
        $reservation->setClubId($this->club->getId())->setSeasonId($this->season->getId());
        $reservation->setTeamId($teamId)->setVenueId($venueId)->setDayOfWeek($dayOfWeek)->setStartTime(new DateTimeImmutable($start))->setDurationMinutes($duration);
        $reservation->setCoachLateToleranceMinutes($tolerance);
        $this->em->persist($reservation);
        $this->em->flush();
    }

    private function coachUnavailable(string $coachId, int $dayOfWeek, string $from, string $until): void
    {
        $constraint = new Constraint;
        $constraint->setClubId($this->club->getId())->setSeasonId($this->season->getId());
        $constraint->setName('indispo')->setScope(ConstraintScope::COACH)->setScopeTargetId($coachId);
        $constraint->setFamily(ConstraintFamily::COACH_AVAILABILITY)->setRuleType(ConstraintRuleType::HARD);
        $constraint->setConfig(['unavailableDays' => [$dayOfWeek], 'fromTime' => $from, 'untilTime' => $until]);
        $constraint->setIsActive(true);
        $this->em->persist($constraint);
        $this->em->flush();
    }

    /** Un coach MAIN pour l'équipe, retourne son id. */
    private function mainCoach(string $teamId, string $firstName): string
    {
        $coach = new Coach;
        $coach->setClubId($this->club->getId());
        $coach->setSeasonId($this->season->getId());
        $coach->setFirstName($firstName);
        $coach->setLastName('Dupont');
        $this->em->persist($coach);
        $this->em->flush();

        $link = new TeamCoach;
        $link->setClubId($this->club->getId());
        $link->setSeasonId($this->season->getId());
        $link->setTeamId($teamId);
        $link->setCoachId($coach->getId());
        $link->setRole(TeamCoachRole::MAIN);
        $this->em->persist($link);
        $this->em->flush();

        return $coach->getId();
    }

    /** Le geste « ce gymnase ne sert pas cette période » (réglage sparse ancré au plan). */
    private function disableVenueForPeriod(string $schedulePlanId, string $venueId): void
    {
        $override = new VenuePeriodOverride;
        $override->setClubId($this->club->getId());
        $override->setSeasonId($this->season->getId());
        $override->setSchedulePlanId($schedulePlanId);
        $override->setVenueId($venueId);
        $override->setMode(VenuePeriodMode::DISABLED);
        $this->em->persist($override);
        $this->em->flush();
    }

    /**
     * Une permanente HARD TIME qui NOMME un gymnase par son scope (FACILITY).
     *
     * @param array<string, mixed> $config
     */
    private function venueScopedConstraint(string $name, string $venueId, array $config): void
    {
        $constraint = new Constraint;
        $constraint->setClubId($this->club->getId());
        $constraint->setSeasonId($this->season->getId());
        $constraint->setName($name);
        $constraint->setScope(ConstraintScope::FACILITY);
        $constraint->setScopeTargetId($venueId);
        $constraint->setFamily(ConstraintFamily::TIME);
        $constraint->setRuleType(ConstraintRuleType::HARD);
        $constraint->setConfig($config);
        $constraint->setIsActive(true);
        $this->em->persist($constraint);
        $this->em->flush();
    }

    /**
     * Scénario P4-319 : un coach principal indisponible le mardi, une réservation le mardi ancrée
     * au plan de la PÉRIODE, une grille de saison qui porte ce créneau (copiée au plan → la
     * réservation n'est pas orpheline). Rend le corps de la validation du gate pour cette période.
     *
     * @return array<string, mixed>
     */
    private function validateSeasonCoachUnavailabilityForPeriod(CalendarEntryPeriodType $type): array
    {
        $venueId = $this->venue('Matéo');
        $teamId = $this->team(sessionsPerWeek: 1);
        $coachId = $this->mainCoach($teamId, 'Emerick');
        $this->trainingSlot($venueId, dayOfWeek: 2, start: '17:00');
        $this->seasonCoachUnavailability($coachId, 'Emerick indisponible le mardi', [2]);

        $entry = $this->period($type);
        $this->planScopedReservation($teamId, $venueId, $this->planIdOf($entry), dayOfWeek: 2, start: '17:00');

        return $this->validateForPeriod($entry);
    }

    /** @return array<string, mixed> */
    private function validateForPeriod(CalendarEntry $entry): array
    {
        $this->client->loginUser($this->user);
        $this->client->request(
            'POST',
            '/api/constraints/validate',
            [],
            [],
            [],
            json_encode(['calendarEntryId' => $entry->getId()], \JSON_THROW_ON_ERROR),
        );

        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
    }

    /** Persist a minimal team scoped to the test club/season, return its id. */
    private function team(int $sessionsPerWeek): string
    {
        $team = new Team;
        $team->setClubId($this->club->getId());
        $team->setSeasonId($this->season->getId());
        $team->setSportCategoryId($this->club->getId()); // any guid — unused by the gate
        $team->setPriorityTierId(3);
        $team->setName('Test Team');
        $team->setSessionsPerWeek($sessionsPerWeek);
        $team->setIsActive(true);
        $this->em->persist($team);
        $this->em->flush();

        return $team->getId();
    }

    private function period(CalendarEntryPeriodType $type): CalendarEntry
    {
        $entry = new CalendarEntry;
        $entry->setClubId($this->club->getId());
        $entry->setSeasonId($this->season->getId());
        $entry->setKind(CalendarEntryKind::PERIOD);
        $entry->setPeriodType($type);
        $entry->setTitle('Period ' . $type->value);
        $entry->setStartDate(new DateTimeImmutable('2026-05-04'));
        $entry->setEndDate(new DateTimeImmutable('2026-05-10'));
        $this->em->persist($entry);
        $this->em->flush();
        // Le geste : en prod le POST crée le plan avec la période. Les réglages y sont
        // ancrés (lot C2), donc sans plan le contrôleur n'a rien à hériter.
        if (\in_array($type, [CalendarEntryPeriodType::CLOSURE, CalendarEntryPeriodType::HOLIDAY], true)) {
            $this->planIdOf($entry);
        }

        return $entry;
    }
}

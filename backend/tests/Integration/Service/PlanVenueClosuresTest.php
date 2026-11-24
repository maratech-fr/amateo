<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Constraint;
use App\Entity\PlanVenue;
use App\Entity\Season;
use App\Entity\User;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\ConstraintFamily;
use App\Enum\ConstraintRuleType;
use App\Enum\ConstraintScope;
use App\Enum\SeasonStatus;
use App\Enum\VenuePeriodMode;
use App\Service\PlanVenueClosures;
use App\Service\SchedulePlanProvisioner;
use App\Tests\ProvisionsPeriodPlanTrait;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * P2-38 (axe §7.1 constraint semantics) — UNE FERMETURE DE GYMNASE EST UN FAIT TRANSVERSAL.
 *
 * `PlanVenueClosures` est la MAISON UNIQUE de « les fermetures qui s'appliquent à CE plan ».
 * Décision fondateur R4, bornée aux FERMETURES : une `venue_closed` s'applique à tout plan de
 * PÉRIODE dont la fenêtre recoupe ses dates, quelle que soit l'entrée qui la porte. Le défaut
 * corrigé (terrain BCCL) : « Matéo en travaux » déclarée sur la période racine n'était pas
 * appliquée à la semaine de reprise qui la recoupait — le solveur y plaçait des séances.
 *
 * Ce fichier épingle la MAISON directement (`forPlan`) : la transversalité, ses bornes (repli
 * legacy jamais élargi à la fenêtre du plan), la granularité partielle, et R1 (le socle intact).
 */
#[Group('phase1')]
#[Group('integration')]
final class PlanVenueClosuresTest extends KernelTestCase
{
    use ProvisionsPeriodPlanTrait;
    use TenantGucTrait;

    private const VENUE = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    private EntityManagerInterface $em;

    private PlanVenueClosures $closures;

    public function testAClosureCarriedByAnotherEntryClosesThisPlansVenue(): void
    {
        // Plan B = la semaine de reprise (lun 2026-05-04 → dim 2026-05-10).
        [$club, $season] = $this->seed();
        $planB = $this->planIdOf($this->period($club, $season, '2026-05-04', '2026-05-10'));

        // Entrée A = une AUTRE période (la racine), qui PORTE la fermeture jeu→dim.
        $entryA = $this->period($club, $season, '2026-05-01', '2026-05-31');
        $this->closure($club, $season, $entryA, '2026-05-07', '2026-05-10');
        $this->em->flush();

        $bundle = $this->closures->forPlan($planB);

        // Le gym est fermé jeu-dim (4..7) dans la fenêtre de B, ouvert lun-mer.
        $weekdays = array_keys($bundle['closedWeekdaysByVenue'][self::VENUE] ?? []);
        sort($weekdays);
        self::assertSame([4, 5, 6, 7], $weekdays, 'la fermeture d’une AUTRE entrée ferme le gymnase de CE plan aux bons jours');
    }

    public function testAClosureOutsideThisPlansWindowHasNoEffect(): void
    {
        // Falsification : même mécanisme, mais la fermeture d'A tombe en JUIN, hors de B.
        [$club, $season] = $this->seed();
        $planB = $this->planIdOf($this->period($club, $season, '2026-05-04', '2026-05-10'));

        $entryA = $this->period($club, $season, '2026-06-01', '2026-06-30');
        $this->closure($club, $season, $entryA, '2026-06-10', '2026-06-20');
        $this->em->flush();

        $bundle = $this->closures->forPlan($planB);

        self::assertSame([], $bundle['closedWeekdaysByVenue'], 'une fermeture hors de la fenêtre du plan ne ferme rien');
        self::assertSame([], $bundle['fullyClosedVenueIds']);
    }

    public function testAPartialClosureFromAnotherEntryClosesOnlyItsDays(): void
    {
        // Fermeture d'A sur le SEUL mardi 2026-05-05 → gymnase fermé PARTIELLEMENT (un jour),
        // jamais fermé-total : l'épinglage d'un jour fermé partiel reste bloquant (doctrine P2-37).
        [$club, $season] = $this->seed();
        $planB = $this->planIdOf($this->period($club, $season, '2026-05-04', '2026-05-10'));

        $entryA = $this->period($club, $season, '2026-05-01', '2026-05-31');
        $this->closure($club, $season, $entryA, '2026-05-05', '2026-05-05'); // mardi seul
        $this->em->flush();

        $bundle = $this->closures->forPlan($planB);

        self::assertSame([2], array_keys($bundle['closedWeekdaysByVenue'][self::VENUE] ?? []), 'seul le mardi est fermé');
        self::assertSame([], $bundle['fullyClosedVenueIds'], 'un seul jour fermé n’est PAS une indisponibilité totale');
    }

    public function testALegacyClosureOfAnotherEntryDoesNotCloseThisPlan(): void
    {
        // CAS FRONTIÈRE du repli legacy : une fermeture SANS dates dans son config est bornée à
        // SA PROPRE entrée (A, en juin) — jamais à la fenêtre du plan consommateur (B, en mai).
        // Sinon le repli « config sans dates » fermerait TOUT le plan d'une autre période.
        [$club, $season] = $this->seed();
        $planB = $this->planIdOf($this->period($club, $season, '2026-05-04', '2026-05-10'));

        $entryA = $this->period($club, $season, '2026-06-01', '2026-06-30');
        $this->closureWithoutDates($club, $season, $entryA); // config nu → repli = fenêtre de A (juin)
        $this->em->flush();

        $bundle = $this->closures->forPlan($planB);

        self::assertSame([], $bundle['closedWeekdaysByVenue'], 'un repli legacy borné à SON entrée ne franchit pas la fenêtre d’un autre plan');
        self::assertSame([], $bundle['fullyClosedVenueIds']);
    }

    public function testALegacyClosureOfTheSameWindowStillFullyClosesIt(): void
    {
        // Témoin du cas précédent : un repli legacy dont l'entrée COUVRE la fenêtre de B ferme
        // bien tout le gymnase — la borne du repli est l'entrée porteuse, pas « rien ».
        [$club, $season] = $this->seed();
        $entryB = $this->period($club, $season, '2026-05-04', '2026-05-10');
        $planB = $this->planIdOf($entryB);
        $this->closureWithoutDates($club, $season, $entryB); // portée par B elle-même
        $this->em->flush();

        $bundle = $this->closures->forPlan($planB);

        self::assertSame([self::VENUE], array_keys($bundle['fullyClosedVenueIds']), 'une fermeture legacy de la fenêtre ferme tout le gymnase');
    }

    public function testTheSeasonPlanIsNeverTouchedByADatedClosure(): void
    {
        // R1 — une fermeture datée ne s'applique JAMAIS au plan SEASON : le socle n'a pas de
        // fenêtre datée, `forPlan` y rend le vide même si une fermeture existe dans la saison.
        [$club, $season] = $this->seed();
        $seasonPlanId = $this->seasonPlanIdOf($season);

        $entryA = $this->period($club, $season, '2026-05-01', '2026-05-31');
        $this->closure($club, $season, $entryA, '2026-05-07', '2026-05-10');
        $this->em->flush();

        $bundle = $this->closures->forPlan($seasonPlanId);

        self::assertSame(
            ['fullyClosedVenueIds' => [], 'closedWeekdaysByVenue' => [], 'closedDatesByVenue' => [], 'summaries' => []],
            $bundle,
            'le plan SEASON n’a pas de fenêtre datée : aucune fermeture ne le touche (R1)',
        );
    }

    // ── Indispo INFORMATIVE (fondateur 2026-08-18) : la composition incident × masque ──
    //
    // `effectiveStateForPlan` est la MAISON UNIQUE : elle compose le défaut vivant (dérivé de
    // l'incident) avec les réglages du plan (mode + masque jour). Chaque exemple normatif de la
    // décision fondateur est épinglé, avec sa contre-épreuve (ensemble EXACT des jours fermés).
    //
    // Fenêtre commune : semaine pleine lun 2026-05-04 → dim 2026-05-10 (tous les jours ISO
    // présents). Mercredi = 2026-05-06 (jour 3), samedi = 2026-05-09 (jour 6).

    public function testIncidentPlusManualClosedMaskAddDayByDay(): void
    {
        // Exemple normatif : incident mercredi + masque {samedi: CLOSED} → fermés = {mer, sam}.
        [$club, $season] = $this->seed();
        $entry = $this->period($club, $season, '2026-05-04', '2026-05-10');
        $planId = $this->planIdOf($entry);
        $this->closure($club, $season, $entry, '2026-05-06', '2026-05-06'); // mercredi
        $this->override($club, $season, $planId, null, [6 => 'CLOSED']);
        $this->em->flush();

        $state = $this->closures->effectiveStateForPlan($planId);

        self::assertSame([3, 6], $this->closedDays($state['effectiveClosedWeekdaysByVenue']), 'le masque CLOSED s’AJOUTE au défaut, jour par jour');
        self::assertSame([3], $this->closedDays($state['defaultClosedWeekdaysByVenue']), 'le défaut vivant reste l’incident seul (mercredi)');
        self::assertSame([6], $this->closedDays($state['manualClosedWeekdaysByVenue']), 'le samedi est fermé À LA MAIN (provenance manuelle)');
    }

    public function testAnOpenMaskReopensAnIncidentDay(): void
    {
        // Exemple normatif : masque {mercredi: OPEN} sur un incident mercredi → fermés = ∅.
        [$club, $season] = $this->seed();
        $entry = $this->period($club, $season, '2026-05-04', '2026-05-10');
        $planId = $this->planIdOf($entry);
        $this->closure($club, $season, $entry, '2026-05-06', '2026-05-06'); // mercredi
        $this->override($club, $season, $planId, null, [3 => 'OPEN']);
        $this->em->flush();

        $state = $this->closures->effectiveStateForPlan($planId);

        self::assertSame([], $state['effectiveClosedWeekdaysByVenue'], 'un jour rouvert OPEN annule la fermeture de l’incident');
        self::assertSame([], $state['fullyClosedVenueIds']);
    }

    public function testReactivatingAFullyClosedVenueWithSevenOpenDays(): void
    {
        // « Réactiver le gymnase entier malgré l'indispo » = OPEN × 7. L'incident ferme toute la
        // semaine ; le masque OPEN sur chaque jour rend le gymnase entièrement ouvert.
        [$club, $season] = $this->seed();
        $entry = $this->period($club, $season, '2026-05-04', '2026-05-10');
        $planId = $this->planIdOf($entry);
        $this->closure($club, $season, $entry, '2026-05-04', '2026-05-10'); // toute la fenêtre
        $this->override($club, $season, $planId, null, [1 => 'OPEN', 2 => 'OPEN', 3 => 'OPEN', 4 => 'OPEN', 5 => 'OPEN', 6 => 'OPEN', 7 => 'OPEN']);
        $this->em->flush();

        $state = $this->closures->effectiveStateForPlan($planId);

        self::assertSame([], $state['effectiveClosedWeekdaysByVenue'], 'OPEN × 7 rouvre entièrement le gymnase malgré l’indispo');
        self::assertSame([], $state['fullyClosedVenueIds'], 'et il n’est donc plus fermé-total');
        // Contre-épreuve : sans le masque, l'incident total le rendrait bien fermé-total.
        self::assertArrayHasKey(self::VENUE, $this->closures->forPlan($planId)['fullyClosedVenueIds']);
    }

    public function testDisabledKeepsTheMaskDormant(): void
    {
        // DISABLED + masque → gymnase hors service, masque DORMANT (conservé, sans effet).
        [$club, $season] = $this->seed();
        $entry = $this->period($club, $season, '2026-05-04', '2026-05-10');
        $planId = $this->planIdOf($entry);
        $override = $this->override($club, $season, $planId, VenuePeriodMode::DISABLED, [6 => 'CLOSED']);
        $this->em->flush();
        $overrideId = $override->getId();

        $state = $this->closures->effectiveStateForPlan($planId);

        self::assertArrayHasKey(self::VENUE, $state['disabledVenueIds'], 'le gymnase désactivé est hors service');
        self::assertArrayNotHasKey(self::VENUE, $state['effectiveClosedWeekdaysByVenue'], 'le masque est DORMANT tant que le mode est DISABLED');

        // Le masque reste STOCKÉ : la réactivation le rendra tel quel.
        $this->em->clear();
        self::assertSame([6 => 'CLOSED'], $this->em->getRepository(PlanVenue::class)->find($overrideId)?->getDayOverrides());
    }

    public function testBlankIgnoresTheIncidentButKeepsTheMask(): void
    {
        // Exemple normatif : BLANK ressaisit la grille (incident informatif) ; le masque, lui,
        // s'applique quand même. Incident mercredi + BLANK + masque {samedi: CLOSED} → fermés = {sam}.
        [$club, $season] = $this->seed();
        $entry = $this->period($club, $season, '2026-05-04', '2026-05-10');
        $planId = $this->planIdOf($entry);
        $this->closure($club, $season, $entry, '2026-05-06', '2026-05-06'); // mercredi
        $this->override($club, $season, $planId, VenuePeriodMode::BLANK, [6 => 'CLOSED']);
        $this->em->flush();

        $state = $this->closures->effectiveStateForPlan($planId);

        self::assertSame([6], $this->closedDays($state['effectiveClosedWeekdaysByVenue']), 'BLANK oublie l’incident (mercredi ouvert), le masque ferme le samedi');
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->closures = self::getContainer()->get(PlanVenueClosures::class);
    }

    /**
     * Les jours ISO fermés de self::VENUE, triés — pour des assertions d'ensemble EXACT.
     *
     * @param array<string, array<int, true>> $closedWeekdaysByVenue
     *
     * @return list<int>
     */
    private function closedDays(array $closedWeekdaysByVenue): array
    {
        $days = array_keys($closedWeekdaysByVenue[self::VENUE] ?? []);
        sort($days);

        return $days;
    }

    /**
     * Un réglage de période pour self::VENUE : mode (nullable) et/ou masque jour.
     *
     * @param array<int, string> $dayOverrides
     */
    private function override(Club $club, Season $season, string $schedulePlanId, ?VenuePeriodMode $mode, array $dayOverrides): PlanVenue
    {
        $override = (new PlanVenue)
            ->setClubId($club->getId())->setSeasonId($season->getId())
            ->setSchedulePlanId($schedulePlanId)->setVenueId(self::VENUE)
            ->setParticipating(VenuePeriodMode::DISABLED !== $mode)->setClosureLifted(VenuePeriodMode::BLANK === $mode)
            ->setDayOverrides([] === $dayOverrides ? null : $dayOverrides);
        $this->em->persist($override);

        return $override;
    }

    private function period(Club $club, Season $season, string $start, string $end): CalendarEntry
    {
        $entry = (new CalendarEntry)
            ->setClubId($club->getId())->setSeasonId($season->getId())
            ->setKind(CalendarEntryKind::PERIOD)->setPeriodType(CalendarEntryPeriodType::HOLIDAY)
            ->setTitle('Période ' . $start)
            ->setStartDate(new DateTimeImmutable($start))->setEndDate(new DateTimeImmutable($end));
        $this->em->persist($entry);
        $this->em->flush();

        return $entry;
    }

    private function closure(Club $club, Season $season, CalendarEntry $carrier, string $start, string $end): void
    {
        $constraint = (new Constraint)
            ->setClubId($club->getId())->setSeasonId($season->getId())
            ->setName('Gym en travaux')
            ->setScope(ConstraintScope::FACILITY)->setScopeTargetId(self::VENUE)
            ->setFamily(ConstraintFamily::FACILITY)->setRuleType(ConstraintRuleType::HARD)
            ->setCalendarEntryId($carrier->getId());
        $constraint->setConfig(['type' => 'venue_closed', 'startDate' => $start, 'endDate' => $end]);
        $this->em->persist($constraint);
    }

    private function closureWithoutDates(Club $club, Season $season, CalendarEntry $carrier): void
    {
        $constraint = (new Constraint)
            ->setClubId($club->getId())->setSeasonId($season->getId())
            ->setName('Gym fermé (config nu)')
            ->setScope(ConstraintScope::FACILITY)->setScopeTargetId(self::VENUE)
            ->setFamily(ConstraintFamily::FACILITY)->setRuleType(ConstraintRuleType::HARD)
            ->setCalendarEntryId($carrier->getId());
        $constraint->setConfig(['type' => 'venue_closed']); // AUCUNE date → repli legacy
        $this->em->persist($constraint);
    }

    /** @return array{0: Club, 1: Season} */
    private function seed(): array
    {
        $uid = uniqid('', true);
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $club = (new Club)->setName('PVC ' . $uid)->setSlug('pvc-' . $uid)
            ->setTimezone('Europe/Paris')->setLocale('fr')->setOnboardingCompleted(true)
            ->setFfbbClubCode('PVC' . strtoupper(substr(md5($uid), 0, 9)));
        $this->em->persist($club);

        $user = (new User)->setEmail('pvc-' . $uid . '@test.com')->setFirstName('P')->setLastName('V');
        $user->setPasswordHash($hasher->hashPassword($user, 'pass'));
        $this->em->persist($user);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());

        $this->em->persist((new ClubUser)->setClubId($club->getId())->setUserId($user->getId())->setRole('admin')->setIsActive(true));

        $season = (new Season)->setClubId($club->getId())->setName('2025-2026')
            ->setStartDate(new DateTimeImmutable('2025-09-01'))->setEndDate(new DateTimeImmutable('2026-06-30'))
            ->setStatus(SeasonStatus::ACTIVE);
        $this->em->persist($season);
        $this->em->flush();

        // Le plan SEASON qu'une vraie saison possède (source des tests R1 / seasonPlanIdOf).
        self::getContainer()->get(SchedulePlanProvisioner::class)->ensureSeasonPlan($season);
        $this->em->flush();

        return [$club, $season];
    }
}

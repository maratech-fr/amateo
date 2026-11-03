<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\Constraint;
use App\Entity\Schedule;
use App\Entity\Season;
use App\Entity\Venue;
use App\Entity\VenuePeriodOverride;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\ConstraintFamily;
use App\Enum\ConstraintRuleType;
use App\Enum\ConstraintScope;
use App\Enum\ScheduleStatus;
use App\Enum\SeasonStatus;
use App\Service\TrainingCalendarContext;
use App\Tests\ChoosesPlanVersionTrait;
use App\Tests\ProvisionsPeriodPlanTrait;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Fermetures de gymnase (C2) — la maison unique du contexte de capture charge les FERMETURES par
 * date (`closedDatesByVenue`, l'union de toutes les fermetures déclarées du club, chargée une fois),
 * porte sur chaque période son type et les jours que son plan pointé ROUVRE, et sait écarter la
 * fermeture interrogée — avec toute sa FAMILLE (racine `COALESCE(parent_entry_id, id)`, même
 * prédicat que `PeriodWindowUniquenessGuard`) — pour que l'aperçu avant « Enregistrer » et l'impact
 * relu après disent le même chiffre (correction finale 4). Le résolveur pur qui consomme ce
 * contexte est gardé par `Unit/Service/EffectiveScheduleResolverTest`.
 */
#[Group('integration')]
final class TrainingCalendarContextTest extends KernelTestCase
{
    use ChoosesPlanVersionTrait;
    use ProvisionsPeriodPlanTrait;
    use TenantGucTrait;

    private EntityManagerInterface $em;

    private TrainingCalendarContext $context;

    private Club $club;

    private Season $season;

    private Venue $mistral;

    private Venue $brel;

    /** Toutes les fermetures déclarées du club se cumulent, gymnase par gymnase, date par date. */
    public function testClosedDatesAreTheUnionOfEveryDeclaredClosure(): void
    {
        $this->closure('Mistral 1', $this->mistral, '2025-11-04', '2025-11-05');
        $this->closure('Mistral 2', $this->mistral, '2025-11-05', '2025-11-06');
        $this->closure('Brel', $this->brel, '2025-11-10', '2025-11-10');

        $closed = $this->context->load($this->season->getId())['closedDatesByVenue'];

        self::assertSame(['2025-11-04', '2025-11-05', '2025-11-06'], $this->sortedKeys($closed[$this->mistral->getId()] ?? []));
        self::assertSame(['2025-11-10'], $this->sortedKeys($closed[$this->brel->getId()] ?? []));
    }

    /**
     * Chaque période porte son type et les jours ROUVERTS par son plan pointé (masque OPEN) : c'est ce
     * que le résolveur retranche des gymnases fermés (« la réouverture fait foi »).
     */
    public function testAPointedPeriodCarriesItsTypeAndTheWeekdaysItsPlanReopened(): void
    {
        $holiday = $this->entry('Toussaint', CalendarEntryPeriodType::HOLIDAY, '2025-10-20', '2025-10-26');
        $planId = $this->planIdOf($holiday);
        $version = (new Schedule)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setName('V1')->setStatus(ScheduleStatus::COMPLETED);
        $this->linkSeededSchedule($version, $holiday->getId());
        $this->choosePlanVersion($version);
        $this->em->persist((new VenuePeriodOverride)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setSchedulePlanId($planId)->setVenueId($this->mistral->getId())->setDayOverrides([2 => 'OPEN']));
        $this->em->flush();

        $periods = $this->context->load($this->season->getId())['activePeriods'];
        $period = null;
        foreach ($periods as $candidate) {
            if ($candidate['scheduleId'] === $version->getId()) {
                $period = $candidate;
            }
        }

        self::assertNotNull($period, 'la période pointée est servie avec sa version');
        self::assertSame(CalendarEntryPeriodType::HOLIDAY, $period['periodType']);
        self::assertTrue($period['reopenedWeekdaysByVenue'][$this->mistral->getId()][2] ?? false, 'le mardi rouvert de Mistral est porté');
        self::assertArrayNotHasKey($this->brel->getId(), $period['reopenedWeekdaysByVenue'], 'aucun jour rouvert sur Brel');
    }

    /**
     * Correction finale 4 — `ignoredEntryId` écarte la FAMILLE : interroger une semaine-enfant écarte
     * aussi les fermetures de sa mère (et réciproquement) ; une fermeture sans lien reste.
     */
    public function testTheIgnoredEntryDropsItsWholeFamilyButNothingElse(): void
    {
        $mother = $this->closure('Mistral en travaux', $this->mistral, '2025-11-03', '2025-11-16');
        $child = $this->entry('Mistral — semaine 1', CalendarEntryPeriodType::CLOSURE, '2025-11-03', '2025-11-09');
        $child->setParentEntryId($mother->getId());
        $this->attachClosure($child, $this->mistral, '2025-11-03', '2025-11-09');
        $this->closure('Brel', $this->brel, '2025-11-05', '2025-11-05');
        $this->em->flush();

        $all = $this->context->load($this->season->getId())['closedDatesByVenue'];
        self::assertArrayHasKey('2025-11-04', $all[$this->mistral->getId()] ?? [], 'témoin : sans entrée écartée, Mistral est fermé');

        foreach (['enfant interrogé' => $child->getId(), 'mère interrogée' => $mother->getId()] as $case => $ignored) {
            $closed = $this->context->load($this->season->getId(), $ignored)['closedDatesByVenue'];
            self::assertSame([], $closed[$this->mistral->getId()] ?? [], $case . ' : toute la famille est écartée');
            self::assertSame(['2025-11-05'], $this->sortedKeys($closed[$this->brel->getId()] ?? []), $case . ' : la fermeture sans lien reste');
        }
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->context = $container->get(TrainingCalendarContext::class);
        $uid = uniqid('', true);

        $this->club = (new Club)->setName('TCC ' . $uid)->setSlug('tcc-' . $uid)
            ->setTimezone('Europe/Paris')->setLocale('fr')->setOnboardingCompleted(true);
        $this->em->persist($this->club);
        $this->em->flush();
        $this->scopeGucToClub($this->club->getId());

        $this->season = (new Season)->setClubId($this->club->getId())->setName('2025-2026')
            ->setStartDate(new DateTimeImmutable('2025-09-01'))->setEndDate(new DateTimeImmutable('2026-06-30'))->setStatus(SeasonStatus::ACTIVE);
        $this->em->persist($this->season);
        $this->em->flush();

        $this->mistral = $this->venue('Mistral');
        $this->brel = $this->venue('Brel');
        $this->settleSeasonPlan($this->season);
    }

    private function venue(string $name): Venue
    {
        $venue = (new Venue)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setName($name)->setSource('manual')->setIsActive(true);
        $this->em->persist($venue);
        $this->em->flush();

        return $venue;
    }

    private function entry(string $title, CalendarEntryPeriodType $type, string $start, string $end): CalendarEntry
    {
        $entry = (new CalendarEntry)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setKind(CalendarEntryKind::PERIOD)->setPeriodType($type)->setTitle($title)
            ->setStartDate(new DateTimeImmutable($start))->setEndDate(new DateTimeImmutable($end));
        $this->em->persist($entry);
        $this->em->flush();

        return $entry;
    }

    /** Une fermeture déclarée comme au cockpit : l'entrée `closure` + sa contrainte datée `venue_closed`. */
    private function closure(string $title, Venue $venue, string $start, string $end): CalendarEntry
    {
        $entry = $this->entry($title, CalendarEntryPeriodType::CLOSURE, $start, $end);
        $this->attachClosure($entry, $venue, $start, $end);

        return $entry;
    }

    private function attachClosure(CalendarEntry $entry, Venue $venue, string $start, string $end): void
    {
        $constraint = (new Constraint)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setName($entry->getTitle())->setScope(ConstraintScope::FACILITY)->setScopeTargetId($venue->getId())
            ->setFamily(ConstraintFamily::FACILITY)->setRuleType(ConstraintRuleType::HARD)
            ->setCalendarEntryId($entry->getId());
        $constraint->setConfig(['type' => 'venue_closed', 'startDate' => $start, 'endDate' => $end]);
        $this->em->persist($constraint);
        $this->em->flush();
    }

    /**
     * @param array<string, true> $dates
     *
     * @return list<string>
     */
    private function sortedKeys(array $dates): array
    {
        $keys = array_map(strval(...), array_keys($dates));
        sort($keys);

        return $keys;
    }
}

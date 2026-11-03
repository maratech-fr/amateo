<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\CalendarEntry;
use App\Entity\Constraint;
use App\Entity\Fixture;
use App\Entity\VenuePeriodOverride;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\CalendarEntryStatus;
use App\Enum\ConstraintFamily;
use App\Enum\ConstraintRuleType;
use App\Enum\ConstraintScope;
use App\Enum\FixtureHomeAway;
use App\Enum\FixtureStatus;
use App\Enum\VenuePeriodMode;
use App\Service\ClosurePinRemover;
use App\Service\SchedulePlanProvisioner;
use App\Service\VenueClosureImpact;
use App\Tests\ClosurePinDecorTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * L'aperçu d'une fermeture de gymnase (plan fermetures C3/C5/C8) dit, AVANT et APRÈS
 * « Enregistrer », ce qu'elle touche : les MATCHS à déplacer nommés (domicile, au gymnase fermé,
 * pas passés, placés, triés par date), les plans de période qui gouvernent ces dates (JAMAIS le
 * socle) avec l'annonce EXACTE de ce que `ClosurePinRemover` retirerait, et — après une
 * régénération sans le gymnase — `regeneratedWithout`, borné par la correction finale 2 (version
 * née APRÈS la fermeture, gymnase non désactivé).
 *
 * Jumeau GATE par l'API (même chiffre partout) : `Security/ClosureImpactSaysTheSameEverywhereTest`.
 * Décor : {@see ClosurePinDecorTrait}.
 */
#[Group('phase1')]
#[Group('integration')]
final class VenueClosureImpactTest extends WebTestCase
{
    use ClosurePinDecorTrait;

    private EntityManagerInterface $em;

    public function testMatchesToMoveAreTheFuturePlacedHomeMatchesAtTheClosedVenueInDateOrder(): void
    {
        $d = $this->seedClosurePinDecor('VCA');
        // Insérés dans le DÉSORDRE : le tri par date est à la charge de l'impact.
        $this->fixture($d, 'u13', 'Villeurbanne', $this->decorDay(8, 7), FixtureHomeAway::HOME, FixtureStatus::VALIDATED, 'mistral', null);
        $this->fixture($d, 'sm1', 'Les Loups', $this->decorDay(8, 6), FixtureHomeAway::HOME, FixtureStatus::PLACED, 'mistral', '18:00');
        $this->fixture($d, 'u13', 'À l’extérieur', $this->decorDay(8, 6), FixtureHomeAway::AWAY, FixtureStatus::PLACED, 'mistral', '15:00');
        $this->fixture($d, 'sm1', 'Ailleurs', $this->decorDay(8, 6), FixtureHomeAway::HOME, FixtureStatus::PLACED, 'brel', '20:00');
        $this->fixture($d, 'sm1', 'Pas encore placé', $this->decorDay(8, 6), FixtureHomeAway::HOME, FixtureStatus::UNPLACED, 'mistral', null);
        $this->fixture($d, 'sm1', 'Déjà joué', $this->decorDay(-1, 6), FixtureHomeAway::HOME, FixtureStatus::VALIDATED, 'mistral', '18:00');

        $impact = $this->impact()->compute([$d['mistral']], new DateTimeImmutable($this->decorDay(-1, 1)), new DateTimeImmutable($this->decorDay(8, 7)), $d['club']->getId(), $d['season']->getId(), null);

        $named = array_map(static fn (array $m): array => [$m['teamName'], $m['opponentLabel'], $m['matchDate'], $m['kickoffTime']], $impact['matchesToMove']);
        self::assertSame([
            ['SM1', 'Les Loups', $this->decorDay(8, 6), '18:00'],
            ['U13', 'Villeurbanne', $this->decorDay(8, 7), null],
        ], $named, 'domicile, gymnase fermé, à venir, placé — triés par date ; ni l’extérieur, ni un autre gymnase, ni le non placé, ni le passé');
    }

    /** `governingPlans` : jamais le socle ; le plan des vacances, ses séances et l'annonce EXACTE du retrait (E3). */
    public function testGoverningPlansAnnounceExactlyWhatTheRemoverWouldRemove(): void
    {
        $d = $this->seedClosurePinDecor('VCB');
        $tuesday = $d['closedTuesday'];

        $impact = $this->impact()->compute([$d['mistral']], new DateTimeImmutable($tuesday), new DateTimeImmutable($tuesday), $d['club']->getId(), $d['season']->getId(), null);

        self::assertSame([$d['holidayEntryId']], array_column($impact['governingPlans'], 'entryId'), 'seul le plan des vacances gouverne le mardi ; jamais le socle');
        $plan = $impact['governingPlans'][0];
        self::assertTrue($plan['hasVersions']);
        self::assertEqualsCanonicalizing(['SM1', 'U13'], array_column($plan['sessions'], 'teamName'), 'les séances du mardi à Mistral dans la version en vigueur');
        $removerLine = null;
        foreach (self::getContainer()->get(ClosurePinRemover::class)->preview($d['club']->getId(), $d['season']->getId(), $d['mistral'], [$tuesday => true], null) as $line) {
            if ($line['entryId'] === $d['holidayEntryId']) {
                $removerLine = $line;
            }
        }
        self::assertIsArray($removerLine);
        self::assertSame($removerLine['locks'], $plan['toRemove']['locks']);
        self::assertSame(
            array_map(static fn (array $r): array => ['teamId' => $r['teamId'], 'teamName' => $r['teamName'], 'dayOfWeek' => $r['dayOfWeek']], $removerLine['reservations']),
            $plan['toRemove']['reservations'],
            'l’aperçu annonce ce que le retrait ferait — même service, mode lecture',
        );
        self::assertTrue($impact['fullyGoverned'], 'le mardi tient entier dans les vacances : « Ouvrir le plan » remplace « Adapter »');
    }

    /** Socle non pointé : l'aperçu le dit (« impact sur les entraînements non évalué »). */
    public function testAnUnvalidatedSocleIsSaid(): void
    {
        $d = $this->seedClosurePinDecor('VCC');
        self::assertTrue(self::getContainer()->get(SchedulePlanProvisioner::class)->releaseSchedule($d['socleVersionId']));
        $this->em->flush();

        $impact = $this->impact()->compute([$d['mistral']], new DateTimeImmutable($this->decorDay(10, 2)), new DateTimeImmutable($this->decorDay(10, 2)), $d['club']->getId(), $d['season']->getId(), null);

        self::assertFalse($impact['seasonPlanChosen']);
    }

    /**
     * C8 + correction finale 2 — la version pointée, née APRÈS la fermeture, n'a plus de séance à
     * Mistral le mardi là où le socle en avait : le plan a été « régénéré sans Mistral ».
     */
    public function testAPlanRegeneratedAfterTheClosureWithoutTheVenueIsSaid(): void
    {
        $d = $this->seedClosurePinDecor('VCD');
        $closureEntryId = $this->savedClosure($d);
        $this->regenerateHolidayWithoutMistralTuesday($d, versionBornAfterClosure: true);

        $line = $this->holidayLine($d, $closureEntryId);
        self::assertTrue($line['regeneratedWithout']);
        // Correction finale 2 + défaut SWITCH : la confirmation de suppression NOMME les jours à
        // retrouver (« … pour retrouver le mardi ») — les jours ISO où le socle avait une séance à
        // Mistral et que la version régénérée n'a plus, SERVIS (jamais recalculés par le front).
        self::assertSame([2], $line['regeneratedWithoutWeekdays'] ?? null, 'le mardi est le jour à retrouver');
    }

    /** Correction finale 2 (b) — une version née AVANT la fermeture n'a pas été « régénérée sans » : pas de ligne. */
    public function testAVersionOlderThanTheClosureIsNotSaidRegeneratedWithout(): void
    {
        $d = $this->seedClosurePinDecor('VCE');
        $closureEntryId = $this->savedClosure($d);
        $this->regenerateHolidayWithoutMistralTuesday($d, versionBornAfterClosure: false);

        self::assertFalse($this->holidayLine($d, $closureEntryId)['regeneratedWithout']);
    }

    /** Correction finale 2 (c) — Mistral DÉSACTIVÉ dans le plan : son absence ne vient pas de la fermeture, pas de ligne. */
    public function testADisabledVenueIsNotSaidRegeneratedWithout(): void
    {
        $d = $this->seedClosurePinDecor('VCF');
        $closureEntryId = $this->savedClosure($d);
        $this->regenerateHolidayWithoutMistralTuesday($d, versionBornAfterClosure: true);
        $this->em->persist((new VenuePeriodOverride)->setClubId($d['club']->getId())->setSeasonId($d['season']->getId())
            ->setSchedulePlanId($d['holidayPlanId'])->setVenueId($d['mistral'])->setMode(VenuePeriodMode::DISABLED));
        $this->em->flush();

        self::assertFalse($this->holidayLine($d, $closureEntryId)['regeneratedWithout']);
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->pinDecorClock();
    }

    protected function tearDown(): void
    {
        $this->unpinDecorClock();
        parent::tearDown();
    }

    private function impact(): VenueClosureImpact
    {
        return self::getContainer()->get(VenueClosureImpact::class);
    }

    /** @param array<string, mixed> $d */
    private function fixture(array $d, string $team, string $opponent, string $date, FixtureHomeAway $homeAway, FixtureStatus $status, string $venue, ?string $kickoff): void
    {
        $fixture = new Fixture;
        $fixture->setClubId($d['club']->getId());
        $fixture->setSeasonId($d['season']->getId());
        $fixture->setTeamId((string) $d[$team]);
        $fixture->setMatchDate(new DateTimeImmutable($date));
        $fixture->setHomeAway($homeAway);
        $fixture->setOpponentLabel($opponent);
        $fixture->setStatus($status, new DateTimeImmutable(self::$decorToday));
        $fixture->setVenueId((string) $d[$venue]);
        $fixture->setKickoffTime(null === $kickoff ? null : (DateTimeImmutable::createFromFormat('!H:i', $kickoff) ?: null));
        $this->em->persist($fixture);
        $this->em->flush();
    }

    /**
     * La fermeture ENREGISTRÉE (entrée + contrainte), datée d'il y a une heure : Mistral le mardi des vacances.
     *
     * @param array<string, mixed> $d
     */
    private function savedClosure(array $d): string
    {
        $this->scopeGucToClub($d['club']->getId());
        $entry = (new CalendarEntry)->setClubId($d['club']->getId())->setSeasonId($d['season']->getId())
            ->setKind(CalendarEntryKind::PERIOD)->setPeriodType(CalendarEntryPeriodType::CLOSURE)->setTitle('Mistral indisponible')
            ->setStartDate(new DateTimeImmutable($d['closedTuesday']))->setEndDate(new DateTimeImmutable($d['closedTuesday']))
            ->setIsDisruptive(true)->setStatus(CalendarEntryStatus::ACTIVE);
        $this->em->persist($entry);
        $constraint = (new Constraint)->setClubId($d['club']->getId())->setSeasonId($d['season']->getId())->setName('Mistral indisponible')
            ->setScope(ConstraintScope::FACILITY)->setScopeTargetId((string) $d['mistral'])
            ->setFamily(ConstraintFamily::FACILITY)->setRuleType(ConstraintRuleType::HARD)->setCalendarEntryId($entry->getId());
        $constraint->setConfig(['type' => 'venue_closed', 'startDate' => $d['closedTuesday'], 'endDate' => $d['closedTuesday']]);
        $constraint->setCreatedAt(new DateTimeImmutable('-1 hour'));
        $this->em->persist($constraint);
        $this->em->flush();

        return $entry->getId();
    }

    /**
     * La V1 pointée perd ses séances du mardi à Mistral (comme une régénération sans Mistral), et
     * naît après — ou avant — la fermeture.
     *
     * @param array<string, mixed> $d
     */
    private function regenerateHolidayWithoutMistralTuesday(array $d, bool $versionBornAfterClosure): void
    {
        $connection = $this->em->getConnection();
        $connection->executeStatement(
            'DELETE FROM schedule_slot_template WHERE schedule_id = :sid AND venue_id = :vid AND day_of_week = 2',
            ['sid' => $d['holidayVersionId'], 'vid' => $d['mistral']],
        );
        $connection->executeStatement('DELETE FROM reservation WHERE id = :id', ['id' => $d['reservationTueMistral']]);
        $connection->executeStatement('UPDATE schedule SET created_at = :at WHERE id = :sid', [
            'at' => new DateTimeImmutable($versionBornAfterClosure ? 'now' : '-2 hours')->format('Y-m-d H:i:sP'),
            'sid' => $d['holidayVersionId'],
        ]);
        $this->em->clear();
    }

    /**
     * @param array<string, mixed> $d
     *
     * @return array<string, mixed>
     */
    private function holidayLine(array $d, string $closureEntryId): array
    {
        $impact = $this->impact()->compute([$d['mistral']], new DateTimeImmutable($d['closedTuesday']), new DateTimeImmutable($d['closedTuesday']), $d['club']->getId(), $d['season']->getId(), $closureEntryId);
        foreach ($impact['governingPlans'] as $plan) {
            if ($plan['entryId'] === $d['holidayEntryId']) {
                return $plan;
            }
        }
        self::fail('le plan des vacances gouverne le mardi fermé');
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Clock\ClubClock;
use App\Command\PeriodReminderCommand;
use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Schedule;
use App\Entity\SchoolHolidayPeriod;
use App\Entity\Season;
use App\Entity\User;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\CalendarEntryStatus;
use App\Enum\ScheduleStatus;
use App\Enum\SeasonStatus;
use App\Repository\CalendarEntryRepository;
use App\Repository\ClubMailboxMessageRepository;
use App\Repository\ClubUserRepository;
use App\Repository\HolidayReminderLogRepository;
use App\Repository\PeriodReminderLogRepository;
use App\Repository\SchoolHolidayPeriodRepository;
use App\Service\ClubDay;
use App\Service\HolidayReminderStateResolver;
use App\Service\PeriodReminderMailBuilder;
use App\Service\SchedulePlanProvisioner;
use App\Service\SeasonDataPurger;
use App\Service\SeasonResolver;
use App\Service\TenantConnectionContext;
use App\Tests\ChoosesPlanVersionTrait;
use App\Tests\TenantGucTrait;
use Closure;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

/**
 * The reminder cron emails a club's managers about periods without an overlay
 * plan, once per milestone (14/7/3-day bucket) via a sent-log — catch-up-safe,
 * tenant-isolated, and red (exit non-zero) when the mailer is down.
 *
 * Rappel des vacances scolaires (même cron) : une vacance scolaire de la zone du club, contenue
 * ENTIÈRE dans une de ses saisons, qui n'a ni planning de période, ni ignorance, ni coupure/fermeture
 * couvrant chacune de ses semaines de vacances (jours ouvrés, `HolidayWorkweekRule`), est rappelée
 * aux gestionnaires à J-30 / J-14 / J-7 (rouge au dernier) — jamais l'été, jamais une démo sans
 * horloge — sans doublon avec le rappel « par entrée » de sa mère adaptée. La base de test ne porte
 * aucune vacance : chaque test sème la sienne dans SA zone (zone unique → aucun autre club ne la
 * voit). Les tests « rien ne part » portent un TÉMOIN (club jumeau sans le fermeur, qui reçoit).
 * La falsification d'isolation des ceintures vit dans `Security/HolidayReminderStateIsolationTest`.
 */
#[Group('phase1')]
#[Group('integration')]
final class PeriodReminderCommandTest extends KernelTestCase
{
    use ChoosesPlanVersionTrait;
    use TenantGucTrait;

    private const TODAY = '2026-01-15';

    /** Saison du club qui contient Noël 2026. */
    private const SEASON_START = '2026-07-15';

    private const SEASON_END = '2027-07-14';

    /** Noël 2026 : samedi 19/12/2026 → dimanche 03/01/2027. */
    private const NOEL_START = '2026-12-19';

    private const NOEL_END = '2027-01-03';

    private const NOEL = 'Vacances de Noël';

    private const NOEL_J30 = '2026-11-19';

    private const NOEL_J14 = '2026-12-05';

    private const NOEL_J7 = '2026-12-12';

    private EntityManagerInterface $em;

    /** @var list<string> */
    private array $sentTo = [];

    /** @var list<string> */
    private array $sentSubjects = [];

    /** @var list<array{0: string, 1: string}> [destinataire, sujet] dans l'ordre d'envoi */
    private array $sent = [];

    private string $throwForRecipient = '';

    /** @var (Closure(): void)|null appelé à chaque envoi, avant d'enregistrer le mail */
    private ?Closure $onSend = null;

    public function testRemindsManagersInEachBucket(): void
    {
        foreach ([14, 7, 3] as $days) {
            $this->reset();
            [, $season, $admin] = $this->seedClub('T' . $days);
            $this->period($season, $this->plus($days));
            $this->em->flush();

            $this->runCommand();

            self::assertContains($admin, $this->sentTo, "a period at J-{$days} must email the manager");
        }
    }

    public function testJ3SubjectIsRed(): void
    {
        [, $season] = $this->seedClub('RED');
        $this->period($season, $this->plus(2));
        $this->em->flush();

        $this->runCommand();

        self::assertNotEmpty($this->sentSubjects);
        self::assertStringStartsWith('🔴', $this->sentSubjects[0]);
    }

    public function testExcludedEntriesNeverRemind(): void
    {
        [, $season, $admin] = $this->seedClub('EXC');
        $this->period($season, $this->plus(14), overlay: true); // has plan
        $this->period($season, $this->plus(7), status: CalendarEntryStatus::IGNORED); // ignored
        $this->period($season, $this->plus(-1)); // already started
        $this->period($season, $this->plus(20)); // beyond horizon
        $this->em->flush();

        $this->runCommand();

        self::assertNotContains($admin, $this->sentTo);
        self::assertSame([], $this->sentTo);
    }

    public function testDryRunSendsNothing(): void
    {
        [, $season] = $this->seedClub('DRY');
        $this->period($season, $this->plus(14));
        $this->em->flush();

        $tester = $this->runCommand(dryRun: true);

        self::assertSame([], $this->sentTo);
        self::assertStringContainsString('would remind', $tester->getDisplay());
        $this->em->clear();
    }

    public function testTenantIsolationBetweenClubs(): void
    {
        [, $seasonA, $adminA] = $this->seedClub('ISOA');
        [, $seasonB, $adminB] = $this->seedClub('ISOB');
        $this->seedClub('ISOC'); // no period → gets nothing
        $this->period($seasonA, $this->plus(14));
        $this->period($seasonB, $this->plus(7));
        $this->em->flush();

        $this->runCommand();

        self::assertContains($adminA, $this->sentTo);
        self::assertContains($adminB, $this->sentTo);
        self::assertCount(2, $this->sentTo);
    }

    public function testResilientToAFailingClubAndReturnsFailure(): void
    {
        [, $seasonA, $adminA] = $this->seedClub('FAILA');
        [, $seasonB, $adminB] = $this->seedClub('FAILB');
        $this->period($seasonA, $this->plus(14));
        $this->period($seasonB, $this->plus(14));
        $this->em->flush();

        $this->throwForRecipient = $adminA;
        $tester = $this->runCommand(expectSuccess: false);

        self::assertSame(Command::FAILURE, $tester->getStatusCode(), 'a send failure must exit non-zero for cron monitors');
        self::assertNotContains($adminA, $this->sentTo);
        self::assertContains($adminB, $this->sentTo);
    }

    public function testMailerOutageExitsFailure(): void
    {
        [, $season, $admin] = $this->seedClub('OUT');
        $this->period($season, $this->plus(14));
        $this->em->flush();

        $this->throwForRecipient = $admin;
        $tester = $this->runCommand(expectSuccess: false);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertSame([], $this->sentTo);
    }

    /**
     * Seuls les gestionnaires actifs (owner|admin) reçoivent un rappel — celui d'une période ET,
     * depuis le rappel des vacances, celui d'une vacance scolaire (même liste de destinataires).
     */
    public function testOnlyManagementRolesAreEmailed(): void
    {
        $zone = $this->newZone();
        // Vacances d'hiver à J-30 de TODAY (15/01/2026), dans la saison 2025-2026 du club.
        $this->holiday($zone, '2026-02-14', '2026-03-01', 'Vacances d\'Hiver', 'hiver', '2025-2026');
        [$club, $season, $admin] = $this->seedClub('ROLE', $zone);
        $this->addMember($club, 'editor@club.fr', 'editor', true);
        $this->addMember($club, 'viewer@club.fr', 'viewer', true);
        $this->addMember($club, 'inactive@club.fr', 'admin', false);
        $this->period($season, $this->plus(14));
        $this->em->flush();

        $this->runCommand();

        self::assertSame([$admin, $admin], $this->sentTo, 'un rappel de période ET un rappel de vacances, au seul gestionnaire actif');
        self::assertStringContainsString('Vacances d\'Hiver', $this->sentSubjects[1]);
    }

    public function testIdempotentViaLog(): void
    {
        [, $season, $admin] = $this->seedClub('IDEM');
        $this->period($season, $this->plus(14));
        $this->em->flush();

        $this->runCommand(); // logs bucket 14
        self::assertSame([$admin], $this->sentTo);

        $this->reset();
        $this->runCommand(); // same bucket already logged → nothing
        self::assertSame([], $this->sentTo);
    }

    public function testCatchUpAfterAMissedRun(): void
    {
        [, $season, $admin] = $this->seedClub('CATCH');
        // Period starts in 10 days; the J-14 run never happened. A run today must
        // still emit the (unsent) bucket-14 reminder — no reminder is lost.
        $this->period($season, $this->plus(10));
        $this->em->flush();

        $this->runCommand();

        self::assertContains($admin, $this->sentTo);
    }

    public function testInvalidDateExitsFailureWithoutSending(): void
    {
        [, $season] = $this->seedClub('BADDATE');
        $this->period($season, $this->plus(14));
        $this->em->flush();

        $tester = $this->runCommand(date: '2026-02-30', expectSuccess: false);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertSame([], $this->sentTo);
    }

    /**
     * P4-16 — chaque club suit SON horloge dans une MÊME exécution, SANS `--date`.
     *
     * Club A à horloge simulée au 15 janvier, club B à l'heure réelle. Le cron calcule J-N
     * club par club via ClubDay : A est rappelé pour sa période du 29 janvier (J-14 sous SA
     * date) et jamais pour une période calée sur le jour RÉEL (hors horizon sous janvier) ;
     * B est rappelé pour sa période à J-14 sous le jour réel. La preuve que la date simulée
     * d'un club n'écrase pas celle d'un autre, et que l'absence d'horloge reste le jour réel.
     */
    public function testEachClubFollowsItsOwnClockInASingleRunWithoutForcedDate(): void
    {
        $simulatedToday = new DateTimeImmutable('2026-01-15');
        $realToday = $this->clubDayToday(); // le jour réel tel que ClubDay le voit (fuseau club)

        [$clubA, $seasonA, $adminA] = $this->seedClub('CLKA');
        // SEC-30 — la date simulée n'est honorée (et persistable) que pour un club démo.
        $clubA->setIsDemo(true);
        $clubA->setSimulatedToday($simulatedToday);
        $this->em->flush();
        // A n'a QU'UNE période, calée à J-14 SOUS son horloge simulée (29 janvier). Sous le
        // jour RÉEL (des mois plus tard), cette même date est dans le passé → aucun rappel :
        // le rappel de A PROUVE qu'il lit sa date simulée, pas le jour réel.
        $this->period($seasonA, $simulatedToday->modify('+14 days'));

        [, $seasonB, $adminB] = $this->seedClub('CLKB'); // pas d'horloge → jour réel
        $this->period($seasonB, $realToday->modify('+14 days'));
        $this->em->flush();

        // Rappel des vacances : A et B partagent une zone dont les vacances d'hiver commencent à
        // J-30 sous l'horloge de A (14/02/2026). B, au jour réel, les a déjà passées : seul A est
        // rappelé — la branche vacances lit, elle aussi, le jour de CHAQUE club.
        $zone = $this->newZone();
        $this->holiday($zone, '2026-02-14', '2026-03-01', 'Vacances d\'Hiver', 'hiver', '2025-2026');
        $this->scopeGucToClub($clubA->getId());
        $clubA = $this->em->find(Club::class, $clubA->getId());
        self::assertInstanceOf(Club::class, $clubA);
        $clubA->setSchoolZone($zone);
        $clubB = $this->em->find(Club::class, $seasonB->getClubId());
        self::assertInstanceOf(Club::class, $clubB);
        $clubB->setSchoolZone($zone);
        $this->em->flush();

        $this->runCommandLive();

        self::assertContains($adminA, $this->sentTo, 'A suit SA date simulée : la période du 29 janvier tombe à J-14 (red si ClubDay ignore simulated_today)');
        self::assertContains($adminB, $this->sentTo, 'B sans horloge suit le jour réel dans la MÊME exécution : sa période à J-14 est rappelée');
        self::assertSame(1, $this->mailsTo($adminA, 'Vacances d\'Hiver'), 'A, sous son horloge, est à J-30 des vacances d\'hiver');
        self::assertSame(0, $this->mailsTo($adminB, 'Vacances d\'Hiver'), 'B, au jour réel, a ces vacances derrière lui');

        // Idempotence à date figée : un second passage à la même horloge ne renvoie RIEN
        // (le ledger PeriodReminderLog est clé par entrée + palier, indépendant de l'horloge).
        $this->reset();
        $this->runCommandLive();
        self::assertSame([], $this->sentTo, 'pas de double envoi : le palier déjà journalisé est tenu quelle que soit l\'horloge');
    }

    // ---- Rappel des vacances scolaires — D1 source, D3 paliers ----

    /**
     * PROMESSE (D1, D3, D4) : une vacance de la zone du club, sans aucune entrée au calendrier, est
     * rappelée à J-30, J-14 et J-7 (rouge) — une fois chacun, jamais à J-31, jamais à J-3, jamais
     * après son début. Angle C19 dans le même test : ce club n'a AUCUNE entrée dans l'horizon des
     * périodes — la sortie anticipée « rien à rappeler » ne doit pas sauter ses vacances.
     */
    public function testSchoolHolidayWithoutEntryRemindsAtJ30J14J7(): void
    {
        $zone = $this->newZone();
        $this->holiday($zone);
        [, , $admin] = $this->seedHolidayClub('HD1', $zone);

        $byDay = $this->mailsByDay('2026-11-15', '2027-01-04', $admin);

        self::assertSame([self::NOEL_J30, self::NOEL_J14, self::NOEL_J7], array_keys($byDay), 'J-30, J-14, J-7 : ni J-31, ni J-3, ni après le début');
        self::assertSame(['⏳ Vacances de Noël dans 30 j — planning de saison à valider'], $byDay[self::NOEL_J30]);
        self::assertSame(['⏳ Vacances de Noël dans 14 j — planning de saison à valider'], $byDay[self::NOEL_J14]);
        self::assertSame(['🔴 Vacances de Noël dans 7 j — planning de saison à valider'], $byDay[self::NOEL_J7]);
    }

    /** PROMESSE (D1) : un club sans zone scolaire ne reçoit aucun rappel de vacances. */
    public function testClubWithoutSchoolZoneGetsNoHolidayReminder(): void
    {
        $zone = $this->newZone();
        $this->holiday($zone);
        [, , $noZone] = $this->seedClub('NOZ', null, self::SEASON_START, self::SEASON_END);
        [, , $witness] = $this->seedHolidayClub('NOZW', $zone);

        $this->runCommand(date: self::NOEL_J30);

        self::assertSame(0, $this->mailsTo($noZone, self::NOEL));
        self::assertSame(1, $this->mailsTo($witness, self::NOEL), 'témoin : le club de la zone est rappelé');
    }

    /** PROMESSE (D3) : un premier passage en retard (J-26) rattrape le palier 30 dans sa tranche, une seule fois. */
    public function testHolidayCatchUpWithinBucket(): void
    {
        $zone = $this->newZone();
        $this->holiday($zone);
        [, , $admin] = $this->seedHolidayClub('CUW', $zone);

        $byDay = $this->mailsOn(['2026-11-23', '2026-11-24'], $admin);

        self::assertSame(['2026-11-23' => ['⏳ Vacances de Noël dans 26 j — planning de saison à valider']], $byDay);
    }

    /** PROMESSE (D3) : un palier dont la tranche est passée n'est jamais rejoué — premier passage à J-10 : le 14 part, le 30 jamais, puis le 7. */
    public function testHolidayCatchUpNeverReplaysAnElapsedBucket(): void
    {
        $zone = $this->newZone();
        $this->holiday($zone);
        [, , $admin] = $this->seedHolidayClub('CUE', $zone);

        $byDay = $this->mailsByDay('2026-12-09', '2026-12-19', $admin);

        self::assertSame(['2026-12-09', self::NOEL_J7], array_keys($byDay));
        self::assertSame(['⏳ Vacances de Noël dans 10 j — planning de saison à valider'], $byDay['2026-12-09']);
    }

    // ---- D2 — saison contenant la vacance entière ----

    /**
     * PROMESSE (D2) : une vacance qui DÉBORDE la saison du club n'est jamais rappelée (Noël sur une
     * saison qui s'arrête au 31/12), et l'été — qui déborde toujours une saison 15/07 → 14/07 — non plus.
     */
    public function testHolidayOverflowingTheSeasonIsNeverReminded(): void
    {
        $zone = $this->newZone();
        $this->holiday($zone);
        $this->holiday($zone, '2027-07-03', '2027-08-31', 'Vacances d\'Été', 'ete');
        [, , $short] = $this->seedClub('OVF', $zone, self::SEASON_START, '2026-12-31');
        [, , $witness] = $this->seedHolidayClub('OVFW', $zone);

        $this->runCommand(date: self::NOEL_J30);
        self::assertSame(0, $this->mailsTo($short, self::NOEL), 'Noël déborde la saison 15/07 → 31/12 : jamais rappelé');
        self::assertSame(1, $this->mailsTo($witness, self::NOEL), 'témoin : la saison qui contient Noël entier le fait rappeler');

        $this->reset();
        $this->runCommand(date: '2027-06-15');
        self::assertSame(0, $this->mailsTo($witness, 'Vacances d\'Été'), 'l\'été (03/07 → 31/08) déborde la saison 15/07 → 14/07 : jamais rappelé');
    }

    /** PROMESSE (D2) : une vacance qu'aucune saison du club ne contient (Toussaint 2027 sans saison 2027-2028) n'est jamais rappelée. */
    public function testHolidayWithoutAContainingSeasonIsNeverReminded(): void
    {
        $zone = $this->newZone();
        $this->holiday($zone, '2027-10-16', '2027-11-01', 'Vacances de la Toussaint', 'toussaint', '2027-2028');
        [, , $admin] = $this->seedHolidayClub('NOS', $zone);
        [$witnessClub, , $witness] = $this->seedHolidayClub('NOSW', $zone);
        $this->addSeason($witnessClub->getId(), '2027-07-15', '2028-07-14');

        $this->runCommand(date: '2027-09-20');

        self::assertSame(0, $this->mailsTo($admin, 'Toussaint'));
        self::assertSame(1, $this->mailsTo($witness, 'Toussaint'), 'témoin : le club qui a déjà sa saison 2027-2028 est rappelé');
    }

    /** PROMESSE (D2 + D7) : le texte suit l'état du planning de la saison qui CONTIENT la vacance, pas d'une autre saison du club. */
    public function testSeasonPlanCheckUsesTheContainingSeason(): void
    {
        $zone = $this->newZone();
        $this->holiday($zone);
        // P : la saison PASSÉE est validée, celle de Noël non → « à valider ».
        [$clubP, $pastP, $adminP] = $this->seedClub('SCP', $zone, '2025-07-15', '2026-07-14');
        $this->addSeason($clubP->getId(), self::SEASON_START, self::SEASON_END);
        $this->settle($pastP);
        // Q : la saison passée n'est pas validée, celle de Noël l'est → « pas de planning de période ».
        [$clubQ, , $adminQ] = $this->seedClub('SCQ', $zone, '2025-07-15', '2026-07-14');
        $this->settle($this->addSeason($clubQ->getId(), self::SEASON_START, self::SEASON_END));

        $this->runCommand(date: self::NOEL_J30);

        self::assertSame(1, $this->mailsTo($adminP, '⏳ Vacances de Noël dans 30 j — planning de saison à valider'));
        self::assertSame(1, $this->mailsTo($adminQ, '⏳ Vacances de Noël dans 30 j — pas de planning de période'));
    }

    // ---- D5 (a)(b) — ignorée, planifiée ----

    /** PROMESSE (D5 a) : une vacance ignorée par le club (entrée HOLIDAY `ignored` liée) n'est jamais rappelée. */
    public function testIgnoredHolidayIsNeverReminded(): void
    {
        $zone = $this->newZone();
        $noel = $this->holiday($zone);
        [, $season, $admin] = $this->seedHolidayClub('IGN', $zone);
        $this->entry($season, CalendarEntryPeriodType::HOLIDAY, self::NOEL_START, self::NOEL_END, self::NOEL, $noel->getId(), CalendarEntryStatus::IGNORED);
        [, , $witness] = $this->seedHolidayClub('IGNW', $zone);

        $this->runCommand(date: self::NOEL_J30);

        self::assertSame(0, $this->mailsTo($admin, self::NOEL));
        self::assertSame(1, $this->mailsTo($witness, self::NOEL), 'témoin');
    }

    /** PROMESSE (D5 b) : une version générée sur la mère « Adaptée » arrête le rappel de la vacance. */
    public function testVersionOnTheLinkedMotherStopsReminding(): void
    {
        $zone = $this->newZone();
        $noel = $this->holiday($zone);
        [, $season, $admin] = $this->seedHolidayClub('VMO', $zone);
        $this->version($this->entry($season, CalendarEntryPeriodType::HOLIDAY, self::NOEL_START, self::NOEL_END, self::NOEL, $noel->getId()));
        [, , $witness] = $this->seedHolidayClub('VMOW', $zone);

        $this->runCommand(date: self::NOEL_J30);

        self::assertSame(0, $this->mailsTo($admin, self::NOEL));
        self::assertSame(1, $this->mailsTo($witness, self::NOEL), 'témoin');
    }

    /** PROMESSE (D5 b) : une version sur UNE semaine-enfant de la mère découpée arrête le rappel de la vacance. */
    public function testVersionOnAWeekChildStopsReminding(): void
    {
        $zone = $this->newZone();
        $noel = $this->holiday($zone);
        [, $season, $admin] = $this->seedHolidayClub('VWK', $zone);
        [, , $s2] = $this->splitNoelMother($season, $noel);
        $this->version($s2);
        [, , $witness] = $this->seedHolidayClub('VWKW', $zone);

        $this->runCommand(date: self::NOEL_J30);

        self::assertSame(0, $this->mailsTo($admin, self::NOEL));
        self::assertSame(1, $this->mailsTo($witness, self::NOEL), 'témoin');
    }

    /** PROMESSE (D5 b) : une génération EN ÉCHEC compte comme un planning (comme pour le rappel par entrée) — le rappel s'arrête. */
    public function testFailedVersionCountsAsAPlanning(): void
    {
        $zone = $this->newZone();
        $noel = $this->holiday($zone);
        [, $season, $admin] = $this->seedHolidayClub('VFA', $zone);
        $this->version($this->entry($season, CalendarEntryPeriodType::HOLIDAY, self::NOEL_START, self::NOEL_END, self::NOEL, $noel->getId()), ScheduleStatus::FAILED);
        [, , $witness] = $this->seedHolidayClub('VFAW', $zone);

        $this->runCommand(date: self::NOEL_J30);

        self::assertSame(0, $this->mailsTo($admin, self::NOEL));
        self::assertSame(1, $this->mailsTo($witness, self::NOEL), 'témoin');
    }

    /** PROMESSE (D5 b) : une période HOLIDAY saisie à la main (non liée au référentiel) qui chevauche la vacance et porte une version arrête le rappel. */
    public function testUnlinkedHolidayEntryWithVersionStopsReminding(): void
    {
        $zone = $this->newZone();
        $this->holiday($zone);
        [, $season, $admin] = $this->seedHolidayClub('VUL', $zone);
        $this->version($this->entry($season, CalendarEntryPeriodType::HOLIDAY, '2026-12-21', '2026-12-27', 'Stage de Noël'));
        [, , $witness] = $this->seedHolidayClub('VULW', $zone);

        $this->runCommand(date: self::NOEL_J30);

        self::assertSame(0, $this->mailsTo($admin, self::NOEL));
        self::assertSame(1, $this->mailsTo($witness, self::NOEL), 'témoin');
    }

    /** PROMESSE (D5 b) : « Adapter » sans générer ne suffit pas — la mère liée SANS version laisse le rappel courir. */
    public function testMaterializedHolidayWithoutVersionKeepsReminding(): void
    {
        $zone = $this->newZone();
        $noel = $this->holiday($zone);
        [, $season, $admin] = $this->seedHolidayClub('VNO', $zone);
        $this->entry($season, CalendarEntryPeriodType::HOLIDAY, self::NOEL_START, self::NOEL_END, self::NOEL, $noel->getId());

        $this->runCommand(date: self::NOEL_J30);

        self::assertSame(1, $this->mailsTo($admin, '⏳ Vacances de Noël dans 30 j — planning de saison à valider'));
    }

    // ---- D5 (c) = c1 — coupure/fermeture couvrant les jours ouvrés de chaque semaine de vacances ----

    /** PROMESSE (D5 c) : une vacance d'une semaine (lun 21 → dim 27/12) sous une coupure lundi→dimanche identique n'est jamais rappelée. */
    public function testCutoffMondayToSundayInsideTheHoliday(): void
    {
        $zone = $this->newZone();
        $this->holiday($zone, '2026-12-21', '2026-12-27');
        [, $season, $admin] = $this->seedHolidayClub('CMS', $zone);
        $this->entry($season, CalendarEntryPeriodType::CUTOFF, '2026-12-21', '2026-12-27', 'Coupure de Noël');
        [, , $witness] = $this->seedHolidayClub('CMSW', $zone);

        $this->runCommand(date: '2026-11-21');

        self::assertSame(0, $this->mailsTo($admin, self::NOEL));
        self::assertSame(1, $this->mailsTo($witness, self::NOEL), 'témoin');
    }

    /**
     * PROMESSE (D5 c) : la couverture se lit sur la RÉUNION des coupures ET fermetures racines,
     * semaine de vacances par semaine, jours ouvrés seulement — coupure lun 21 → ven 25/12 et
     * fermeture lun 28/12 → ven 01/01, week-ends découverts : la vacance est couverte.
     */
    public function testCutoffCoveringEveryWorkweekStops(): void
    {
        $zone = $this->newZone();
        $this->holiday($zone);
        [, $season, $admin] = $this->seedHolidayClub('CEW', $zone);
        $this->entry($season, CalendarEntryPeriodType::CUTOFF, '2026-12-21', '2026-12-25', 'Coupure S1');
        $this->entry($season, CalendarEntryPeriodType::CLOSURE, '2026-12-28', '2027-01-01', 'Gymnase fermé S2');
        [, , $witness] = $this->seedHolidayClub('CEWW', $zone);

        $this->runCommand(date: self::NOEL_J30);

        self::assertSame(0, $this->mailsTo($admin, self::NOEL));
        self::assertSame(1, $this->mailsTo($witness, self::NOEL), 'témoin');
    }

    /** PROMESSE (D5 c) : une coupure 21/12 → vendredi 01/01 couvre Noël (le week-end final ne compte pas). */
    public function testCutoffEndingFridayStops(): void
    {
        $zone = $this->newZone();
        $this->holiday($zone);
        [, $season, $admin] = $this->seedHolidayClub('CEF', $zone);
        $this->entry($season, CalendarEntryPeriodType::CUTOFF, '2026-12-21', '2027-01-01', 'Coupure de Noël');
        [, , $witness] = $this->seedHolidayClub('CEFW', $zone);

        $this->runCommand(date: self::NOEL_J30);

        self::assertSame(0, $this->mailsTo($admin, self::NOEL));
        self::assertSame(1, $this->mailsTo($witness, self::NOEL), 'témoin');
    }

    /** PROMESSE (D5 c) : un seul jour ouvré découvert (lundi 21/12, coupure 22/12 → 03/01) et la vacance reste rappelée — ses trois paliers. */
    public function testCutoffMissingOneWorkdayDoesNotStop(): void
    {
        $zone = $this->newZone();
        $this->holiday($zone);
        [, $season, $admin] = $this->seedHolidayClub('CMW', $zone);
        $this->entry($season, CalendarEntryPeriodType::CUTOFF, '2026-12-22', self::NOEL_END, 'Coupure de Noël');

        $byDay = $this->mailsOn([self::NOEL_J30, self::NOEL_J14, self::NOEL_J7], $admin);

        self::assertSame([self::NOEL_J30, self::NOEL_J14, self::NOEL_J7], array_keys($byDay));
    }

    /** PROMESSE (D5 c, réunion) : deux coupures qui se partagent UNE semaine (lun→mer + jeu→ven) la couvrent ensemble. */
    public function testTwoCutoffsSplitInsideAWeekStillCover(): void
    {
        $zone = $this->newZone();
        $this->holiday($zone, '2026-12-21', '2026-12-27');
        [, $season, $admin] = $this->seedHolidayClub('CSW', $zone);
        $this->entry($season, CalendarEntryPeriodType::CUTOFF, '2026-12-21', '2026-12-23', 'Coupure début');
        $this->entry($season, CalendarEntryPeriodType::CUTOFF, '2026-12-24', '2026-12-25', 'Coupure fin');
        [, , $witness] = $this->seedHolidayClub('CSWW', $zone);

        $this->runCommand(date: '2026-11-21');

        self::assertSame(0, $this->mailsTo($admin, self::NOEL));
        self::assertSame(1, $this->mailsTo($witness, self::NOEL), 'témoin');
    }

    /** PROMESSE (D5 c) : une fermeture PARTIELLE (gymnase 2, 21 → 23/12) n'arrête jamais le rappel de la vacance. */
    public function testPartialClosureInsideTheHolidayDoesNotStop(): void
    {
        $zone = $this->newZone();
        $this->holiday($zone);
        [, $season, $admin] = $this->seedHolidayClub('CPC', $zone);
        $this->entry($season, CalendarEntryPeriodType::CLOSURE, '2026-12-21', '2026-12-23', 'Gym 2 fermé');

        $this->runCommand(date: self::NOEL_J30);

        self::assertSame(1, $this->mailsTo($admin, self::NOEL));
    }

    /**
     * PROMESSE (D5 c, C21) : une vacance sans AUCUNE semaine lundi→vendredi entière (mer 10/02 →
     * mar 16/02/2027) n'est jamais fermée par une coupure, même identique — « aucune semaine à
     * couvrir » ne vaut pas « couverte ».
     */
    public function testHolidayWithoutAnyFullWorkweekIsNeverClosedByCutoff(): void
    {
        $zone = $this->newZone();
        $this->holiday($zone, '2027-02-10', '2027-02-16', 'Vacances d\'Hiver', 'hiver');
        [, $season, $admin] = $this->seedHolidayClub('CNW', $zone);
        $this->entry($season, CalendarEntryPeriodType::CUTOFF, '2027-02-10', '2027-02-16', 'Coupure d\'hiver');

        $this->runCommand(date: '2027-01-11');

        self::assertSame(1, $this->mailsTo($admin, 'Vacances d\'Hiver'));
    }

    // ---- D6 — pas de doublon avec le rappel « par entrée » ----

    /** PROMESSE (D6) : la mère « Adaptée » sans version ne double pas le rappel — un seul mail par palier de la vacance, plus aucun J-3 d'entrée. */
    public function testMaterializedFeedHolidayGetsExactlyOneMailPerMilestone(): void
    {
        $zone = $this->newZone();
        $noel = $this->holiday($zone);
        [, $season, $admin] = $this->seedHolidayClub('DUP', $zone);
        $this->entry($season, CalendarEntryPeriodType::HOLIDAY, self::NOEL_START, self::NOEL_END, self::NOEL, $noel->getId());

        $byDay = $this->mailsByDay('2026-11-15', '2027-01-04', $admin);

        self::assertSame([
            self::NOEL_J30 => ['⏳ Vacances de Noël dans 30 j — planning de saison à valider'],
            self::NOEL_J14 => ['⏳ Vacances de Noël dans 14 j — planning de saison à valider'],
            self::NOEL_J7 => ['🔴 Vacances de Noël dans 7 j — planning de saison à valider'],
        ], $byDay);
    }

    /** PROMESSE (D6) : Noël découpé en deux semaines, rien de généré → les trois mails de la vacance, et plus aucun rappel des semaines (6 aujourd'hui). */
    public function testSplitMotherWithoutVersionGetsOnlyTheThreeHolidayMails(): void
    {
        $zone = $this->newZone();
        $noel = $this->holiday($zone);
        [, $season, $admin] = $this->seedHolidayClub('SPL', $zone);
        $this->splitNoelMother($season, $noel);

        $byDay = $this->mailsByDay('2026-11-15', '2027-01-04', $admin);

        self::assertSame([self::NOEL_J30, self::NOEL_J14, self::NOEL_J7], array_keys($byDay));
        foreach ($byDay as $subjects) {
            self::assertCount(1, $subjects);
            self::assertStringContainsString(self::NOEL, $subjects[0]);
        }
    }

    /** PROMESSE (D6) : une semaine générée ferme la vacance (planifiée) — sa semaine sœur sans version retrouve SES rappels J-14 / J-7 / J-3, comme aujourd'hui. */
    public function testWeekWithoutVersionKeepsJ14J7J3WhenASisterWeekIsGenerated(): void
    {
        $zone = $this->newZone();
        $noel = $this->holiday($zone);
        [, $season, $admin] = $this->seedHolidayClub('SIS', $zone);
        [, $s1] = $this->splitNoelMother($season, $noel);
        $this->version($s1);

        $byDay = $this->mailsByDay('2026-11-15', '2027-01-04', $admin);

        self::assertSame([
            '2026-12-14' => ['⏳ Noël S2 dans 14 j — pas de planning de période'],
            '2026-12-21' => ['⏳ Noël S2 dans 7 j — pas de planning de période'],
            '2026-12-25' => ['🔴 Noël S2 dans 3 j — pas de planning de période'],
        ], $byDay);
    }

    /**
     * PROMESSE (D6, priorité COVERED > PLANNED) : une coupure couvrante tient la promesse « arrêt »
     * même quand une semaine est déjà générée — la semaine sœur sans version se tait aussi. Jumeau
     * sans la coupure : {@see testWeekWithoutVersionKeepsJ14J7J3WhenASisterWeekIsGenerated}.
     */
    public function testSisterWeekGeneratedPlusCoveringCutoffMutesTheWholeHoliday(): void
    {
        $zone = $this->newZone();
        $noel = $this->holiday($zone);
        [, $season, $admin] = $this->seedHolidayClub('SIC', $zone);
        [, $s1] = $this->splitNoelMother($season, $noel);
        $this->version($s1);
        $this->entry($season, CalendarEntryPeriodType::CUTOFF, '2026-12-21', self::NOEL_END, 'Coupure de Noël');

        self::assertSame([], $this->mailsByDay('2026-11-15', '2027-01-04', $admin));
    }

    /** PROMESSE (D6, M1) : la mère « Adaptée » sans version sous une coupure couvrante ne reçoit RIEN — ni mail de vacance, ni rappel par entrée (05, 12, 16/12 aujourd'hui). */
    public function testAdaptedMotherWithoutVersionUnderACoveringCutoffGetsNoMail(): void
    {
        $zone = $this->newZone();
        $noel = $this->holiday($zone);
        [, $season, $admin] = $this->seedHolidayClub('MCV', $zone);
        $this->entry($season, CalendarEntryPeriodType::HOLIDAY, self::NOEL_START, self::NOEL_END, self::NOEL, $noel->getId());
        $this->entry($season, CalendarEntryPeriodType::CUTOFF, '2026-12-21', self::NOEL_END, 'Coupure de Noël');

        self::assertSame([], $this->mailsByDay('2026-11-15', '2026-12-20', $admin));
    }

    /**
     * PROMESSE (D6, m2) : le silence d'une vacance couverte ne s'étend qu'aux entrées HOLIDAY
     * ENTIÈREMENT dans ses dates — un stage HOLIDAY manuel du 02 au 10/01 (déborde Noël) garde ses
     * rappels J-14 / J-7 / J-3.
     */
    public function testManualHolidayExtendingBeyondTheCoveredVacationKeepsItsReminder(): void
    {
        $zone = $this->newZone();
        $this->holiday($zone);
        [, $season, $admin] = $this->seedHolidayClub('MXT', $zone);
        $this->entry($season, CalendarEntryPeriodType::CUTOFF, '2026-12-21', self::NOEL_END, 'Coupure de Noël');
        $this->entry($season, CalendarEntryPeriodType::HOLIDAY, '2027-01-02', '2027-01-10', 'Stage de janvier');

        $byDay = $this->mailsByDay('2026-11-15', '2027-01-02', $admin);

        self::assertSame([
            '2026-12-19' => ['⏳ Stage de janvier dans 14 j — pas de planning de période'],
            '2026-12-26' => ['⏳ Stage de janvier dans 7 j — pas de planning de période'],
            '2026-12-30' => ['🔴 Stage de janvier dans 3 j — pas de planning de période'],
        ], $byDay);
    }

    /**
     * PROMESSE (D2 + D6, m1) : l'été n'est jamais traité — sa mère de reprise liée (17 → 30/08/2026,
     * semaines 17–23/08 et 24–30/08, sans version) garde les rappels J-14 / J-7 / J-3 de ses semaines,
     * et aucun mail « Vacances d'Été » ne part.
     */
    public function testLinkedSummerMotherKeepsEntryReminders(): void
    {
        $zone = $this->newZone();
        $summer = $this->holiday($zone, '2026-07-04', '2026-08-31', 'Vacances d\'Été', 'ete', '2025-2026');
        [, $season, $admin] = $this->seedHolidayClub('ETR', $zone);
        $mother = $this->entry($season, CalendarEntryPeriodType::HOLIDAY, '2026-08-17', '2026-08-30', 'Reprise', $summer->getId());
        $this->entry($season, CalendarEntryPeriodType::HOLIDAY, '2026-08-17', '2026-08-23', 'Reprise S1', parent: $mother);
        $this->entry($season, CalendarEntryPeriodType::HOLIDAY, '2026-08-24', '2026-08-30', 'Reprise S2', parent: $mother);

        $byDay = $this->mailsByDay('2026-07-20', '2026-08-31', $admin);

        self::assertSame([
            '2026-08-03' => ['⏳ Reprise S1 dans 14 j — pas de planning de période'],
            '2026-08-10' => ['⏳ Reprise S1 dans 7 j — pas de planning de période', '⏳ Reprise S2 dans 14 j — pas de planning de période'],
            '2026-08-14' => ['🔴 Reprise S1 dans 3 j — pas de planning de période'],
            '2026-08-17' => ['⏳ Reprise S2 dans 7 j — pas de planning de période'],
            '2026-08-21' => ['🔴 Reprise S2 dans 3 j — pas de planning de période'],
        ], $byDay);
    }

    /** PROMESSE (D6) : une mère liée à une vacance d'une AUTRE zone (zone du club changée) n'est pas traitée — elle garde son rappel par entrée. */
    public function testLinkedMotherOfAnotherZoneKeepsEntryReminder(): void
    {
        $otherZone = $this->newZone();
        $foreignNoel = $this->holiday($otherZone);
        [, $season, $admin] = $this->seedHolidayClub('OZN', $this->newZone());
        $this->entry($season, CalendarEntryPeriodType::HOLIDAY, self::NOEL_START, self::NOEL_END, self::NOEL, $foreignNoel->getId());

        $byDay = $this->mailsByDay('2026-11-15', '2026-12-20', $admin);

        self::assertSame([
            self::NOEL_J14 => ['⏳ Vacances de Noël dans 14 j — pas de planning de période'],
            self::NOEL_J7 => ['⏳ Vacances de Noël dans 7 j — pas de planning de période'],
            '2026-12-16' => ['🔴 Vacances de Noël dans 3 j — pas de planning de période'],
        ], $byDay);
    }

    /** PROMESSE (D6) : un club sans zone garde le rappel par entrée de ses périodes HOLIDAY saisies à la main. */
    public function testManualHolidayInAClubWithoutZoneKeepsEntryReminder(): void
    {
        [, $season, $admin] = $this->seedClub('MNZ', null, self::SEASON_START, self::SEASON_END);
        $this->entry($season, CalendarEntryPeriodType::HOLIDAY, self::NOEL_START, self::NOEL_END, 'Vacances du club');

        $byDay = $this->mailsOn([self::NOEL_J14], $admin);

        self::assertSame([self::NOEL_J14 => ['⏳ Vacances du club dans 14 j — pas de planning de période']], $byDay);
    }

    /**
     * PROMESSE (D6 + D10) : une démo SANS horloge n'a aucune vacance traitée — sa mère « Adaptée »
     * garde son rappel par entrée. Témoin : le club réel jumeau reçoit le mail de VACANCE à la place.
     */
    public function testDemoWithoutClockKeepsItsHolidayEntryReminders(): void
    {
        $zone = $this->newZone();
        $noel = $this->holiday($zone);
        [$demo, $demoSeason, $demoAdmin] = $this->seedHolidayClub('DMH', $zone);
        $demo->setIsDemo(true);
        $this->em->flush();
        $this->entry($demoSeason, CalendarEntryPeriodType::HOLIDAY, self::NOEL_START, self::NOEL_END, self::NOEL, $noel->getId());
        [, $realSeason, $realAdmin] = $this->seedHolidayClub('DMHW', $zone);
        $this->entry($realSeason, CalendarEntryPeriodType::HOLIDAY, self::NOEL_START, self::NOEL_END, self::NOEL, $noel->getId());

        $this->runCommand(date: self::NOEL_J14);

        self::assertSame(1, $this->mailsTo($demoAdmin, '⏳ Vacances de Noël dans 14 j — pas de planning de période'), 'la démo sans horloge garde le rappel de son entrée');
        self::assertSame(0, $this->mailsTo($demoAdmin, 'planning de saison à valider'), 'aucun mail de vacance pour une démo sans horloge');
        self::assertSame(1, $this->mailsTo($realAdmin, '⏳ Vacances de Noël dans 14 j — planning de saison à valider'), 'témoin : le club réel reçoit le mail de vacance');
        self::assertSame(0, $this->mailsTo($realAdmin, 'pas de planning de période'), 'témoin : sans doublon par entrée');
    }

    // ---- D7 — variante de texte selon le planning de saison ----

    /** PROMESSE (D7) : planning de saison non validé → « planning de saison à valider » ; validé → « pas de planning de période ». */
    public function testHolidayMailSaysValidateSeasonPlanWhenSocleNotChosen(): void
    {
        $zone = $this->newZone();
        $this->holiday($zone);
        [, , $notChosen] = $this->seedHolidayClub('SNC', $zone);
        [, $season, $chosen] = $this->seedHolidayClub('SCH', $zone);
        $this->settle($season);

        $this->runCommand(date: self::NOEL_J30);

        self::assertSame(1, $this->mailsTo($notChosen, '⏳ Vacances de Noël dans 30 j — planning de saison à valider'));
        self::assertSame(1, $this->mailsTo($chosen, '⏳ Vacances de Noël dans 30 j — pas de planning de période'));
    }

    // ---- D8 — journal des paliers ----

    /** PROMESSE (D8, NR tenant) : le palier envoyé au club A ne marque jamais le club B de la même zone. */
    public function testHolidayMilestoneSentToClubADoesNotMarkClubB(): void
    {
        $zone = $this->newZone();
        $this->holiday($zone);
        [, , $adminA] = $this->seedHolidayClub('LDA', $zone);
        $this->runCommand(date: self::NOEL_J30);
        self::assertSame(1, $this->mailsTo($adminA, self::NOEL));

        [, , $adminB] = $this->seedHolidayClub('LDB', $zone);
        $this->reset();
        $this->runCommand(date: self::NOEL_J30);

        self::assertSame(0, $this->mailsTo($adminA, self::NOEL), 'A : palier déjà journalisé');
        self::assertSame(1, $this->mailsTo($adminB, self::NOEL), 'B : le palier de A ne le marque pas');
    }

    /** PROMESSE (D8) : purger la saison (reset, reset démo, RGPD) vide son journal de vacances — le palier repart. */
    public function testHolidayLedgerIsWipedWithTheSeason(): void
    {
        $zone = $this->newZone();
        $this->holiday($zone);
        [, $season, $admin] = $this->seedHolidayClub('WIP', $zone);
        $this->runCommand(date: self::NOEL_J30);
        self::assertSame(1, $this->mailsTo($admin, self::NOEL));
        $this->reset();
        $this->runCommand(date: self::NOEL_J30);
        self::assertSame(0, $this->mailsTo($admin, self::NOEL), 'palier journalisé');

        $this->scopeGucToClub($season->getClubId());
        self::getContainer()->get(SeasonDataPurger::class)->purge($season->getClubId(), $season->getId());
        $this->reset();
        $this->runCommand(date: self::NOEL_J30);

        self::assertSame(1, $this->mailsTo($admin, self::NOEL), 'journal vidé avec la saison : le palier repart');
    }

    /**
     * PROMESSE (D8) : une ligne de journal posée par un passage concurrent entre « déjà envoyé ? » et
     * l'écriture ne fait ni sauter le club, ni fermer l'EntityManager (écriture ON CONFLICT DO NOTHING) :
     * le passage suivant rappelle normalement le palier 14.
     */
    public function testRunSurvivesAConcurrentLedgerRow(): void
    {
        $zone = $this->newZone();
        $noel = $this->holiday($zone);
        [, $season, $admin] = $this->seedHolidayClub('RAC', $zone);
        $seasonId = $season->getId();
        $holidayId = $noel->getId();
        $this->onSend = function () use ($seasonId, $holidayId): void {
            $this->onSend = null;
            $this->em->getConnection()->executeStatement(
                'INSERT INTO holiday_reminder_log (id, season_id, school_holiday_id, threshold, sent_at) VALUES (gen_random_uuid(), ?, ?, 30, now())',
                [$seasonId, $holidayId],
            );
        };

        $tester = $this->runCommand(date: self::NOEL_J30);

        self::assertSame(1, $this->mailsTo($admin, self::NOEL));
        self::assertStringNotContainsString('skipped', $tester->getDisplay(), 'la ligne concurrente ne fait pas sauter le club');
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne(
            'SELECT count(*) FROM holiday_reminder_log WHERE season_id = ? AND school_holiday_id = ? AND threshold = 30',
            [$seasonId, $holidayId],
        ));

        $this->reset();
        $this->runCommand(date: self::NOEL_J14);
        self::assertSame(1, $this->mailsTo($admin, self::NOEL), 'l\'EntityManager est resté ouvert : le palier 14 part au passage suivant');
    }

    // ---- D10 — démos ----

    /** PROMESSE (D10) : une démo sans horloge simulée ne reçoit aucun rappel de vacance, mais garde ses rappels de fermeture. */
    public function testDemoWithoutClockGetsNoHolidayReminderButKeepsClosureReminder(): void
    {
        $zone = $this->newZone();
        $this->holiday($zone);
        [$demo, $season, $demoAdmin] = $this->seedHolidayClub('DMC', $zone);
        $demo->setIsDemo(true);
        $this->em->flush();
        $this->entry($season, CalendarEntryPeriodType::CLOSURE, '2026-12-03', '2026-12-09', 'Gym fermé');
        [, , $witness] = $this->seedHolidayClub('DMCW', $zone);

        $this->runCommand(date: self::NOEL_J30);

        self::assertSame(0, $this->mailsTo($demoAdmin, self::NOEL));
        self::assertSame(1, $this->mailsTo($demoAdmin, 'Gym fermé'), 'le rappel de fermeture de la démo est inchangé');
        self::assertSame(1, $this->mailsTo($witness, self::NOEL), 'témoin : le club réel de la zone est rappelé');
    }

    /**
     * PROMESSE (D10) : une démo à horloge simulée à J-30 reçoit le rappel de vacance dans SA BOÎTE —
     * jamais un vrai envoi. Mailer RÉEL (patron `ClockedClubMailInterceptTest`), sans `--date`.
     */
    public function testDemoClubWithSimulatedClockAtJ30GetsTheHolidayMailBoxedNotSent(): void
    {
        $zone = $this->newZone();
        $this->holiday($zone);
        [$demo, , $admin] = $this->seedHolidayClub('BOX', $zone);
        $demo->setIsDemo(true);
        $demo->setSimulatedToday(new DateTimeImmutable(self::NOEL_J30));
        $this->em->flush();
        $clubId = $demo->getId();

        $this->runCommandLive(self::getContainer()->get(MailerInterface::class));

        $transport = self::getContainer()->get('messenger.transport.mailer_in_memory');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        foreach ($transport->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if (method_exists($message, 'getMessage') && $message->getMessage() instanceof Email) {
                self::assertNotSame($admin, $message->getMessage()->getTo()[0]->getAddress(), 'aucun vrai envoi pour une démo à horloge');
            }
        }
        $this->scopeGucToClub($clubId);
        $boxed = array_filter(
            self::getContainer()->get(ClubMailboxMessageRepository::class)->findForClubNewestFirst($clubId),
            static fn ($message): bool => str_contains($message->getSubject(), self::NOEL),
        );
        self::assertCount(1, $boxed, 'le rappel de vacance est rangé dans la boîte de la démo');
    }

    // ---- D11 — ordre, dry-run ----

    /** PROMESSE (D11) : `--dry-run` liste la vacance (jour, palier, libellé, club, gestionnaires) sans rien envoyer ni journaliser. */
    public function testDryRunListsHolidaysWithoutSending(): void
    {
        $zone = $this->newZone();
        $this->holiday($zone);
        [$club, , $admin] = $this->seedHolidayClub('HDR', $zone);
        $clubId = $club->getId();

        $tester = $this->runCommand(date: self::NOEL_J30, dryRun: true);

        self::assertSame([], $this->sentTo);
        self::assertStringContainsString(\sprintf('would remind J-30 (bucket 30) · Vacances de Noël (club %s) → 1 manager(s)', $clubId), $tester->getDisplay());

        $this->reset();
        $this->runCommand(date: self::NOEL_J30);
        self::assertSame(1, $this->mailsTo($admin, self::NOEL), 'le dry-run n\'a rien journalisé');
    }

    /** PROMESSE (D11) : dans un même passage, les rappels d'entrées partent avant ceux des vacances. */
    public function testEntriesAreRemindedBeforeHolidaysInTheSameRun(): void
    {
        $zone = $this->newZone();
        $this->holiday($zone);
        [, $season, $admin] = $this->seedHolidayClub('ORD', $zone);
        $this->entry($season, CalendarEntryPeriodType::CLOSURE, '2026-12-03', '2026-12-09', 'Gym fermé');

        $this->runCommand(date: self::NOEL_J30);

        self::assertSame([$admin, $admin], $this->sentTo);
        self::assertStringContainsString('Gym fermé', $this->sentSubjects[0]);
        self::assertStringContainsString(self::NOEL, $this->sentSubjects[1]);
    }

    // ---- Abus — isolation entre clubs (comportement) ----

    /**
     * PROMESSE (abus i) : ce qui ferme la vacance pour B (ignorée, coupure couvrante, racine HOLIDAY
     * versionnée) ne ferme jamais celle de A. Test de COMPORTEMENT, vert si l'une des deux gardes
     * (RLS, ceinture club_id) tient ; la falsification de chaque ceinture vit dans
     * `Security/HolidayReminderStateIsolationTest`.
     */
    public function testHolidayReminderOfClubANeverReadsClubBData(): void
    {
        $zone = $this->newZone();
        $noel = $this->holiday($zone);
        [, , $adminA] = $this->seedHolidayClub('ISA', $zone);
        [, $seasonB, $adminB] = $this->seedHolidayClub('ISB', $zone);
        $this->entry($seasonB, CalendarEntryPeriodType::HOLIDAY, self::NOEL_START, self::NOEL_END, self::NOEL, $noel->getId(), CalendarEntryStatus::IGNORED);
        $this->entry($seasonB, CalendarEntryPeriodType::CUTOFF, '2026-12-21', self::NOEL_END, 'Coupure de Noël');
        $this->version($this->entry($seasonB, CalendarEntryPeriodType::HOLIDAY, '2026-12-21', '2026-12-27', 'Stage de Noël'));

        $this->runCommand(date: self::NOEL_J30);

        self::assertSame(1, $this->mailsTo($adminA, self::NOEL), 'A n\'a fermé sa vacance d\'aucune façon');
        self::assertSame(0, $this->mailsTo($adminB, self::NOEL));
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    /** Le jour réel vu par ClubDay pour un club de fuseau Europe/Paris (aucune horloge). */
    private function clubDayToday(): DateTimeImmutable
    {
        $probe = (new Club)->setTimezone('Europe/Paris');

        return self::getContainer()->get(ClubDay::class)->todayFor($probe);
    }

    /** Sans `--date` : chaque club suit son horloge. `$realMailer` = le mailer du conteneur (interception démo comprise). */
    private function runCommandLive(?MailerInterface $realMailer = null): CommandTester
    {
        $container = self::getContainer();
        $mailer = $realMailer;
        if (!$mailer instanceof MailerInterface) {
            $mailer = $this->createMock(MailerInterface::class);
            $mailer->method('send')->willReturnCallback(function (RawMessage $message): void {
                if ($message instanceof Email) {
                    $this->record($message);
                }
            });
        }

        $command = new PeriodReminderCommand(
            $this->em,
            $container->get(TenantConnectionContext::class),
            $mailer,
            $container->get(CalendarEntryRepository::class),
            $container->get(ClubUserRepository::class),
            $container->get(SeasonResolver::class),
            $container->get(PeriodReminderLogRepository::class),
            new PeriodReminderMailBuilder('http://localhost:5173'),
            $container->get(ClubDay::class),
            $container->get(SchoolHolidayPeriodRepository::class),
            $container->get(HolidayReminderLogRepository::class),
            $container->get(HolidayReminderStateResolver::class),
            $container->get(SchedulePlanProvisioner::class),
            $container->get(ClubClock::class),
        );

        $tester = new CommandTester($command);
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();

        return $tester;
    }

    private function reset(): void
    {
        $this->sentTo = [];
        $this->sentSubjects = [];
        $this->sent = [];
    }

    private function record(Email $message): void
    {
        $to = $message->getTo()[0]->getAddress();
        $subject = (string) $message->getSubject();
        $this->sentTo[] = $to;
        $this->sentSubjects[] = $subject;
        $this->sent[] = [$to, $subject];
    }

    /** Nombre de mails reçus par `$to` dont le sujet contient `$needle`, sur le dernier passage. */
    private function mailsTo(string $to, string $needle): int
    {
        return \count(array_filter($this->sent, static fn (array $mail): bool => $mail[0] === $to && str_contains($mail[1], $needle)));
    }

    /**
     * Rejoue le cron à chacune de ces dates et rend, pour `$to`, les sujets reçus par jour (jours
     * sans mail omis). Le journal des paliers persiste d'un jour à l'autre, comme en production.
     *
     * @param list<string> $dates
     *
     * @return array<string, list<string>>
     */
    private function mailsOn(array $dates, string $to): array
    {
        $byDay = [];
        foreach ($dates as $date) {
            $this->reset();
            $this->runCommand(date: $date);
            $subjects = array_values(array_map(static fn (array $mail): string => $mail[1], array_filter($this->sent, static fn (array $mail): bool => $mail[0] === $to)));
            if ([] !== $subjects) {
                $byDay[$date] = $subjects;
            }
        }
        $this->reset();

        return $byDay;
    }

    /**
     * Rejoue le cron CHAQUE jour de `$from` à `$to` inclus (voir {@see mailsOn}).
     *
     * @return array<string, list<string>>
     */
    private function mailsByDay(string $from, string $to, string $recipient): array
    {
        $dates = [];
        for ($day = new DateTimeImmutable($from); $day <= new DateTimeImmutable($to); $day = $day->modify('+1 day')) {
            $dates[] = $day->format('Y-m-d');
        }

        return $this->mailsOn($dates, $recipient);
    }

    /** Une zone scolaire propre au test : aucun autre club de la base n'en reçoit les vacances. */
    private function newZone(): string
    {
        return 'T' . strtoupper(substr(md5(uniqid('', true)), 0, 12));
    }

    /** Sème une vacance du référentiel GLOBAL (la base de test n'en porte aucune). */
    private function holiday(string $zone, string $start = self::NOEL_START, string $end = self::NOEL_END, string $label = self::NOEL, string $type = 'noel', string $schoolYear = '2026-2027'): SchoolHolidayPeriod
    {
        $holiday = (new SchoolHolidayPeriod)
            ->setZone($zone)
            ->setLabel($label)
            ->setHolidayType($type)
            ->setStartDate(new DateTimeImmutable($start))
            ->setEndDate(new DateTimeImmutable($end))
            ->setSchoolYear($schoolYear);
        $this->em->persist($holiday);
        $this->em->flush();

        return $holiday;
    }

    /**
     * Un club de la zone, saison 15/07/2026 → 14/07/2027 (contient Noël 2026).
     *
     * @return array{0: Club, 1: Season, 2: string}
     */
    private function seedHolidayClub(string $tag, string $zone): array
    {
        return $this->seedClub($tag, $zone, self::SEASON_START, self::SEASON_END);
    }

    private function entry(Season $season, CalendarEntryPeriodType $type, string $start, string $end, string $title, ?string $schoolHolidayId = null, CalendarEntryStatus $status = CalendarEntryStatus::ACTIVE, ?CalendarEntry $parent = null): CalendarEntry
    {
        $this->scopeGucToClub($season->getClubId());
        $entry = new CalendarEntry;
        $entry->setClubId($season->getClubId());
        $entry->setSeasonId($season->getId());
        $entry->setKind(CalendarEntryKind::PERIOD);
        $entry->setPeriodType($type);
        $entry->setStatus($status);
        $entry->setTitle($title);
        $entry->setStartDate(new DateTimeImmutable($start));
        $entry->setEndDate(new DateTimeImmutable($end));
        $entry->setSchoolHolidayId($schoolHolidayId);
        $entry->setParentEntryId($parent?->getId());
        $this->em->persist($entry);
        $this->em->flush();

        return $entry;
    }

    /**
     * La mère HOLIDAY « Adaptée » (liée au référentiel) et ses deux semaines lundi→dimanche, sans version.
     *
     * @return array{0: CalendarEntry, 1: CalendarEntry, 2: CalendarEntry}
     */
    private function splitNoelMother(Season $season, SchoolHolidayPeriod $noel): array
    {
        $mother = $this->entry($season, CalendarEntryPeriodType::HOLIDAY, self::NOEL_START, self::NOEL_END, self::NOEL, $noel->getId());
        $s1 = $this->entry($season, CalendarEntryPeriodType::HOLIDAY, '2026-12-21', '2026-12-27', 'Noël S1', parent: $mother);
        $s2 = $this->entry($season, CalendarEntryPeriodType::HOLIDAY, '2026-12-28', '2027-01-03', 'Noël S2', parent: $mother);

        return [$mother, $s1, $s2];
    }

    /** Une version (générée, ou en échec) sur le plan de période de l'entrée. */
    private function version(CalendarEntry $entry, ScheduleStatus $status = ScheduleStatus::COMPLETED): void
    {
        $this->scopeGucToClub($entry->getClubId());
        $provisioner = self::getContainer()->get(SchedulePlanProvisioner::class);
        $planId = $provisioner->provisionPeriodPlan($entry->getId());
        self::assertIsString($planId);
        $schedule = (new Schedule)
            ->setClubId($entry->getClubId())
            ->setSeasonId($entry->getSeasonId())
            ->setName('Version')
            ->setStatus($status)
            ->setSchedulePlanId($planId);
        $this->em->persist($schedule);
        $provisioner->linkSchedule($schedule);
        $this->em->flush();
    }

    /** Le planning de saison est validé (le plan SEASON pointe une version terminée). */
    private function settle(Season $season): void
    {
        $this->scopeGucToClub($season->getClubId());
        $this->settleSeasonPlan($season);
    }

    private function plus(int $days): DateTimeImmutable
    {
        return new DateTimeImmutable(self::TODAY)->modify(\sprintf('%+d days', $days));
    }

    private function runCommand(string $date = self::TODAY, bool $expectSuccess = true, bool $dryRun = false): CommandTester
    {
        $container = self::getContainer();
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->method('send')->willReturnCallback(function (RawMessage $message): void {
            if ($message instanceof Email) {
                $to = $message->getTo()[0]->getAddress();
                if ('' !== $this->throwForRecipient && $to === $this->throwForRecipient) {
                    throw new RuntimeException('smtp down');
                }
                if ($this->onSend instanceof Closure) {
                    ($this->onSend)();
                }
                $this->record($message);
            }
        });

        $command = new PeriodReminderCommand(
            $this->em,
            $container->get(TenantConnectionContext::class),
            $mailer,
            $container->get(CalendarEntryRepository::class),
            $container->get(ClubUserRepository::class),
            $container->get(SeasonResolver::class),
            $container->get(PeriodReminderLogRepository::class),
            new PeriodReminderMailBuilder('http://localhost:5173'),
            $container->get(ClubDay::class),
            $container->get(SchoolHolidayPeriodRepository::class),
            $container->get(HolidayReminderLogRepository::class),
            $container->get(HolidayReminderStateResolver::class),
            $container->get(SchedulePlanProvisioner::class),
            $container->get(ClubClock::class),
        );

        $tester = new CommandTester($command);
        $tester->execute($dryRun ? ['--date' => $date, '--dry-run' => true] : ['--date' => $date]);
        if ($expectSuccess) {
            $tester->assertCommandIsSuccessful();
        }

        return $tester;
    }

    private function period(Season $season, DateTimeImmutable $start, bool $overlay = false, CalendarEntryStatus $status = CalendarEntryStatus::ACTIVE): void
    {
        $this->scopeGucToClub($season->getClubId());
        $entry = new CalendarEntry;
        $entry->setClubId($season->getClubId());
        $entry->setSeasonId($season->getId());
        $entry->setKind(CalendarEntryKind::PERIOD);
        $entry->setPeriodType(CalendarEntryPeriodType::CLOSURE);
        $entry->setStatus($status);
        $entry->setTitle('Gym fermé');
        $entry->setStartDate($start);
        $entry->setEndDate($start->modify('+6 days'));
        $this->em->persist($entry);
        $this->em->flush();

        if ($overlay) {
            // lot D-b : « a un overlay » = le plan de la période porte ≥ 1 version (générée),
            // plus un pointeur sur l'entrée — le rappel s'arrête dès la 1re génération.
            $provisioner = self::getContainer()->get(SchedulePlanProvisioner::class);
            $planId = $provisioner->provisionPeriodPlan($entry->getId());
            self::assertIsString($planId);
            $schedule = (new Schedule)
                ->setClubId($season->getClubId())
                ->setSeasonId($season->getId())
                ->setName('Overlay')
                ->setStatus(ScheduleStatus::COMPLETED)
                ->setSchedulePlanId($planId);
            $this->em->persist($schedule);
            $provisioner->linkSchedule($schedule);
            $this->em->flush();
        }
    }

    private function addMember(Club $club, string $email, string $role, bool $active): void
    {
        $hasher = self::getContainer()->get('security.user_password_hasher');
        $user = new User;
        $user->setEmail($email);
        $user->setFirstName('X');
        $user->setLastName('Y');
        $user->setPasswordHash($hasher->hashPassword($user, 'pass'));
        $this->em->persist($user);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());
        $cu = new ClubUser;
        $cu->setClubId($club->getId());
        $cu->setUserId($user->getId());
        $cu->setRole($role);
        $cu->setIsActive($active);
        $this->em->persist($cu);
        $this->em->flush();
    }

    /**
     * @return array{0: Club, 1: Season, 2: string}
     */
    private function seedClub(string $tag, ?string $schoolZone = null, string $seasonStart = '2025-09-01', string $seasonEnd = '2026-06-30'): array
    {
        $uid = uniqid('', true);

        $club = new Club;
        $club->setName('Club ' . $tag);
        $club->setSlug('club-' . $tag . '-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode($tag . strtoupper(substr(md5($uid), 0, 8)));
        $club->setSchoolZone($schoolZone);
        $this->em->persist($club);
        $this->em->flush();

        $adminEmail = 'admin-' . $tag . '-' . $uid . '@club.fr';
        $this->addMember($club, $adminEmail, 'admin', true);

        $season = $this->addSeason($club->getId(), $seasonStart, $seasonEnd);

        return [$club, $season, $adminEmail];
    }

    private function addSeason(string $clubId, string $start, string $end): Season
    {
        $this->scopeGucToClub($clubId);
        $season = new Season;
        $season->setClubId($clubId);
        $season->setName(substr($start, 0, 4) . '-' . substr($end, 0, 4));
        $season->setStartDate(new DateTimeImmutable($start));
        $season->setEndDate(new DateTimeImmutable($end));
        $season->setStatus(SeasonStatus::ACTIVE);
        $this->em->persist($season);
        $this->em->flush();

        return $season;
    }
}

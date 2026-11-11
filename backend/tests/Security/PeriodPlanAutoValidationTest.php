<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Club;
use App\Entity\Schedule;
use App\Entity\Season;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\CalendarEntryStatus;
use App\Enum\ScheduleStatus;
use App\Service\PeriodPlanAlertResolver;
use App\Service\PeriodPlanAutoValidator;
use App\Service\PeriodPlanSweepReport;
use App\Service\SchedulePlanProvisioner;
use App\Tests\PeriodPlanAutoValidationDecorTrait;
use App\Tests\StartsFreshBrowserSession;
use DateTimeImmutable;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * NR — axes §7.1 *planning lifecycle* + *tenant isolation* (ADR-0002) : un planning de période
 * qu'aucun gestionnaire n'a validé 7 JOURS après le début de sa période se valide seul (décision
 * fondateur 2026-11-03) — la version chargée est pointée, ses sœurs sont gardées, rien d'autre ne
 * bouge ; jamais le socle, jamais un plan déjà validé une fois, jamais un plan d'une saison
 * archivée, jamais hors de son club.
 *
 * Le service est appelé comme la production l'appelle : `PeriodPlanAutoValidator::sweepClub()`
 * sous le GUC du club (posé par le listener en HTTP, par la commande en cron), avec le jour du
 * club donné explicitement ; la commande multi-clubs pour les promesses « un passage, plusieurs
 * clubs » ; `SchedulePlanProvisioner::chooseAutomatically()` APRÈS une mutation concurrente pour
 * les gardes de l'UPDATE sous verrou (D6 — on ne simule pas deux connexions sous DAMA, on rejoue
 * l'écriture après que le concurrent a bougé la base).
 *
 * Seuil (B1) : `schedule_plan.start_date` est une DATE CIVILE portée à minuit UTC ; le seuil
 * compare la date civile, jamais une conversion dans le fuseau du club ni dans celui de la
 * session Postgres. Prouvé à l'ouest ET à l'est d'UTC, sous trois `TimeZone` de session.
 *
 * Jumeau API : `Integration/Api/SchedulePlanAlertSeenTest` (champs servis, « J'ai vu ») ; jumeau
 * horloge démo : `Integration/Api/ClubClockSweepTest` ; jumeau CLI : `Command/PeriodPlanAutoValidateCommandTest` ;
 * enchaînement de bout en bout : `features/un-planning-de-periode-commence-se-valide-seul.feature`.
 */
#[Group('phase1')]
#[Group('integration')]
final class PeriodPlanAutoValidationTest extends WebTestCase
{
    use PeriodPlanAutoValidationDecorTrait;
    use StartsFreshBrowserSession;

    /** Vacances de Noël du lundi 21/12/2026 au dimanche 03/01/2027 ; seuil J+7 = lundi 28/12. */
    private const string NOEL_START = '2026-12-21';

    private const string NOEL_END = '2027-01-03';

    private const string EVE_OF_THRESHOLD = '2026-12-27';

    private const string THRESHOLD = '2026-12-28';

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    /**
     * B1 — la validation tombe le 7ᵉ jour après la date CIVILE de début, ni un jour avant ni un
     * jour après, quels que soient le fuseau du club (ouest ou est d'UTC) et la `TimeZone` de la
     * session Postgres : veille du seuil → rien ; jour du seuil → pointé, marqué du jour du club.
     *
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function timezones(): iterable
    {
        foreach (['UTC', 'Europe/Paris', 'America/Guadeloupe'] as $session) {
            yield 'club Guadeloupe (UTC−4), session ' . $session => ['America/Guadeloupe', $session];
            yield 'club Réunion (UTC+4), session ' . $session => ['Indian/Reunion', $session];
        }
    }

    #[DataProvider('timezones')]
    public function testValidationFallsOnTheSeventhDayAfterTheCivilStartWhateverTheTimezones(string $clubTimezone, string $sessionTimezone): void
    {
        ['club' => $club, 'seasons' => [$season]] = $this->seedDecorClub('TZ', $clubTimezone);
        ['entryId' => $entryId, 'planId' => $planId] = $this->seedDecorPeriod($season, self::NOEL_START, self::NOEL_END);
        $version = $this->seedDecorVersion($season, $entryId);

        $this->em->getConnection()->executeStatement(\sprintf('SET LOCAL TimeZone = \'%s\'', $sessionTimezone));

        $eve = $this->sweep($club, self::EVE_OF_THRESHOLD);
        self::assertSame([], $eve->validated, 'veille du seuil : rien n\'est validé');
        self::assertNull($this->decorChosenOf($club->getId(), $planId), 'veille du seuil : le plan reste ouvert');

        $day = $this->sweep($club, self::THRESHOLD);
        self::assertCount(1, $day->validated, 'jour du seuil : le plan est validé');
        self::assertSame($version, $this->decorChosenOf($club->getId(), $planId), 'jour du seuil : la seule version terminée est pointée');
        self::assertSame(self::THRESHOLD, $this->autoValidatedDateOf($club->getId(), $planId), 'la marque porte le jour du club de la bascule');
        self::assertNull($this->decorFirstChosenAtOf($club->getId(), $planId), 'l\'auto ne pose jamais la première validation humaine');
    }

    /**
     * B1 (même foyer, côté alerte) — la carte « La période a commencé sans planning » s'allume le
     * jour CIVIL du début, pas la veille, quels que soient le fuseau du club et celui de la session.
     */
    #[DataProvider('timezones')]
    public function testStartedWithoutPlanAlertLightsUpOnTheCivilStartDayWhateverTheTimezones(string $clubTimezone, string $sessionTimezone): void
    {
        ['club' => $club, 'seasons' => [$season]] = $this->seedDecorClub('TZA', $clubTimezone);
        ['planId' => $planId] = $this->seedDecorPeriod($season, self::NOEL_START, self::NOEL_END);

        $this->em->getConnection()->executeStatement(\sprintf('SET LOCAL TimeZone = \'%s\'', $sessionTimezone));

        self::assertNull($this->alertOf($club, $planId, '2026-12-20'), 'la veille du début : aucune alerte');
        self::assertSame(
            PeriodPlanAlertResolver::KIND_STARTED_WITHOUT_PLAN,
            $this->alertOf($club, $planId, self::NOEL_START)['kind'] ?? null,
            'le jour du début : « commencée sans planning »',
        );
    }

    /**
     * D2 / abus 1 — un passage de la commande juge CHAQUE club à SON jour : la démo dont l'horloge
     * est posée au-delà du seuil est validée, un club réel portant la même période (pas encore
     * commencée à son jour réel) reste intact.
     */
    public function testOnePassJudgesEachClubOnItsOwnDay(): void
    {
        $start = $this->decorDay(30);
        $end = $this->decorDay(43);

        ['club' => $demo, 'seasons' => [$demoSeason]] = $this->seedDecorClub('DEMO', simulatedToday: $this->decorDay(40), seasons: [[$this->decorDay(-200), $this->decorDay(200)]]);
        ['entryId' => $demoEntry, 'planId' => $demoPlan] = $this->seedDecorPeriod($demoSeason, $start, $end);
        $demoVersion = $this->seedDecorVersion($demoSeason, $demoEntry);

        ['club' => $real, 'seasons' => [$realSeason]] = $this->seedDecorClub('REEL', seasons: [[$this->decorDay(-200), $this->decorDay(200)]]);
        ['entryId' => $realEntry, 'planId' => $realPlan] = $this->seedDecorPeriod($realSeason, $start, $end);
        $this->seedDecorVersion($realSeason, $realEntry);

        $tester = $this->runSweepCommand();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertSame($demoVersion, $this->decorChosenOf($demo->getId(), $demoPlan), 'la démo, à son jour simulé, passe le seuil : validée');
        self::assertNull($this->decorChosenOf($real->getId(), $realPlan), 'le club réel, à son jour réel, n\'a pas commencé : intact');
    }

    /**
     * Abus 2 — un club qui lève PENDANT sa transaction n'empêche jamais les suivants, le passage
     * sort en échec (moniteur cron) et le GUC est relâché. L'échec est provoqué au bon endroit :
     * une seconde session Postgres tient le verrou de plan-scope de l'entrée du club A
     * (`lockPlanScope(entryId)`, D6) et `lock_timeout` transforme l'attente en exception DANS la
     * transaction du club A (décor du club A semé sans prendre ce verrou, voir
     * `seedDecorPeriodWithoutPlanScopeLock`). Sous DAMA, sans transaction propre au club (savepoint),
     * l'erreur avorterait la transaction du test et le club B échouerait aussi : le test exige donc
     * la transaction DBAL par club des corrections finales.
     */
    public function testAClubThatThrowsNeverStopsTheOthersAndTheGucIsReleased(): void
    {
        $start = $this->decorDay(-10);
        $end = $this->decorDay(3);

        ['club' => $clubA, 'seasons' => [$seasonA]] = $this->seedDecorClub('AAFAIL', seasons: [[$this->decorDay(-200), $this->decorDay(200)]]);
        ['entryId' => $entryA, 'planId' => $planA] = $this->seedDecorPeriodWithoutPlanScopeLock($seasonA, $start, $end);

        ['club' => $clubB, 'seasons' => [$seasonB]] = $this->seedDecorClub('ZZSAIN', seasons: [[$this->decorDay(-200), $this->decorDay(200)]]);
        ['entryId' => $entryB, 'planId' => $planB] = $this->seedDecorPeriod($seasonB, $start, $end);
        $versionB = $this->seedDecorVersion($seasonB, $entryB);

        $conn = $this->em->getConnection();
        $blocker = DriverManager::getConnection($conn->getParams());
        try {
            $blocker->executeStatement('SELECT pg_advisory_lock(hashtext(:k))', ['k' => 'schedule-plan-link:' . $entryA]);
            $conn->executeStatement('SET lock_timeout = \'700ms\'');

            $tester = $this->runSweepCommand(expectSuccess: false);
        } finally {
            $conn->executeStatement('RESET lock_timeout');
            $blocker->executeStatement('SELECT pg_advisory_unlock(hashtext(:k))', ['k' => 'schedule-plan-link:' . $entryA]);
            $blocker->close();
        }

        self::assertSame(Command::FAILURE, $tester->getStatusCode(), 'un club en échec fait sortir le passage en échec');
        self::assertSame('', (string) $conn->fetchOne('SELECT COALESCE(current_setting(\'app.club_id\', true), \'\')'), 'le GUC est relâché après le passage');
        self::assertTrue($this->em->isOpen(), 'l\'EntityManager reste utilisable après l\'échec d\'un club');
        self::assertNull($this->decorChosenOf($clubA->getId(), $planA), 'le club A, en échec, n\'a rien écrit');
        self::assertSame($versionB, $this->decorChosenOf($clubB->getId(), $planB), 'le club B est traité malgré l\'échec du club A');
    }

    /**
     * D3 / abus 4 / C9 — la saison archivée se juge au JOUR DU CLUB : une démo projetée sur la
     * saison suivante ne valide pas une période de sa saison passée (que l'horloge réelle dirait
     * encore courante), et valide celle de sa saison courante.
     */
    public function testArchivedSeasonIsJudgedOnTheClubDay(): void
    {
        ['club' => $club, 'seasons' => [$past, $current]] = $this->seedDecorClub(
            'ARCH',
            simulatedToday: '2027-09-20',
            seasons: [['2026-07-15', '2027-07-14'], ['2027-07-15', '2028-07-14']],
        );
        ['entryId' => $pastEntry, 'planId' => $pastPlan] = $this->seedDecorPeriod($past, '2027-03-01', '2027-03-07', CalendarEntryPeriodType::CLOSURE, title: 'Gymnase fermé en mars');
        $this->seedDecorVersion($past, $pastEntry);
        ['entryId' => $currentEntry, 'planId' => $currentPlan] = $this->seedDecorPeriod($current, '2027-09-06', '2027-09-12', CalendarEntryPeriodType::CLOSURE, title: 'Gymnase fermé en septembre');
        $currentVersion = $this->seedDecorVersion($current, $currentEntry);

        $this->sweep($club, '2027-09-20');

        self::assertNull($this->decorChosenOf($club->getId(), $pastPlan), 'saison archivée au jour du club : intacte');
        self::assertSame($currentVersion, $this->decorChosenOf($club->getId(), $currentPlan), 'témoin : la période de la saison courante du club est validée');
    }

    /** D3 — une période dont le seuil n'est pas atteint, une entrée ignorée, un plan de saison : jamais validés (témoin validé dans le même passage). */
    public function testOnlyStartedActivePeriodPlansAreValidated(): void
    {
        ['club' => $club, 'seasons' => [$season]] = $this->seedDecorClub('ELIG');
        ['entryId' => $okEntry, 'planId' => $okPlan] = $this->seedDecorPeriod($season, self::NOEL_START, self::NOEL_END);
        $okVersion = $this->seedDecorVersion($season, $okEntry);
        ['entryId' => $soonEntry, 'planId' => $soonPlan] = $this->seedDecorPeriod($season, '2026-12-22', '2026-12-31', CalendarEntryPeriodType::CLOSURE, title: 'Fermeture qui commence le 22');
        $this->seedDecorVersion($season, $soonEntry);
        ['entryId' => $ignoredEntry, 'planId' => $ignoredPlan] = $this->seedDecorPeriod($season, '2026-12-07', '2026-12-13', CalendarEntryPeriodType::CLOSURE, CalendarEntryStatus::IGNORED, title: 'Fermeture ignorée');
        $this->seedDecorVersion($season, $ignoredEntry);
        $seasonPlanVersion = $this->seedSeasonVersionNotChosen($season);

        $this->sweep($club, self::THRESHOLD);

        self::assertSame($okVersion, $this->decorChosenOf($club->getId(), $okPlan), 'témoin : la période commencée depuis 7 jours est validée');
        self::assertNull($this->decorChosenOf($club->getId(), $soonPlan), 'seuil non atteint (J+6) : intacte');
        self::assertNull($this->decorChosenOf($club->getId(), $ignoredPlan), 'entrée ignorée : intacte');
        self::assertNull($this->provisioner()->chosenOfSeasonPlan($season->getId()), 'le plan de saison n\'est jamais validé automatiquement');
        self::assertTrue($this->decorVersionExists($club->getId(), $seasonPlanVersion));
    }

    /** D3 — une mère découpée n'est jamais validée ; chacune de ses semaines l'est à sa propre date. */
    public function testASplitMotherStaysOpenWhileEachWeekIsValidatedOnItsOwnDate(): void
    {
        ['club' => $club, 'seasons' => [$season]] = $this->seedDecorClub('SPLIT');
        ['entryId' => $mother, 'planId' => $motherPlan] = $this->seedDecorPeriod($season, '2026-11-30', '2026-12-13', CalendarEntryPeriodType::CLOSURE, title: 'Indisponibilité découpée');
        $this->seedDecorVersion($season, $mother);
        ['entryId' => $week1, 'planId' => $week1Plan] = $this->seedDecorPeriod($season, '2026-11-30', '2026-12-06', CalendarEntryPeriodType::CLOSURE, parentEntryId: $mother, title: 'Semaine 1');
        $week1Version = $this->seedDecorVersion($season, $week1);
        ['entryId' => $week2, 'planId' => $week2Plan] = $this->seedDecorPeriod($season, '2026-12-07', '2026-12-13', CalendarEntryPeriodType::CLOSURE, parentEntryId: $mother, title: 'Semaine 2');
        $this->seedDecorVersion($season, $week2);

        $this->sweep($club, '2026-12-07');

        self::assertNull($this->decorChosenOf($club->getId(), $motherPlan), 'la mère découpée n\'est jamais validée');
        self::assertSame($week1Version, $this->decorChosenOf($club->getId(), $week1Plan), 'la semaine 1 (J+7) est validée à sa date');
        self::assertNull($this->decorChosenOf($club->getId(), $week2Plan), 'la semaine 2 (J0) attend sa propre date');
    }

    /** D3 — un plan déjà validé une fois (même rouvert depuis) n'est jamais revalidé ; un plan déjà auto-validé puis rouvert non plus. */
    public function testAPlanValidatedOnceIsNeverValidatedAgain(): void
    {
        ['club' => $club, 'seasons' => [$season]] = $this->seedDecorClub('ONCE');
        ['entryId' => $humanEntry, 'planId' => $humanPlan] = $this->seedDecorPeriod($season, self::NOEL_START, self::NOEL_END);
        $humanVersion = $this->seedDecorVersion($season, $humanEntry);
        $this->chooseThenReopen($club, $humanVersion);

        ['entryId' => $autoEntry, 'planId' => $autoPlan] = $this->seedDecorPeriod($season, '2026-12-14', '2026-12-20', CalendarEntryPeriodType::CLOSURE, title: 'Fermeture déjà auto-validée');
        $autoVersion = $this->seedDecorVersion($season, $autoEntry);
        $this->sweep($club, self::THRESHOLD);
        self::assertSame($autoVersion, $this->decorChosenOf($club->getId(), $autoPlan), 'décor : premier passage, la fermeture est auto-validée');
        $this->scopeGucToClub($club->getId());
        $this->provisioner()->releaseSchedule($autoVersion);

        $this->sweep($club, '2026-12-29');

        self::assertNull($this->decorChosenOf($club->getId(), $humanPlan), 'validé à la main puis rouvert : jamais revalidé');
        self::assertNull($this->decorChosenOf($club->getId(), $autoPlan), 'auto-validé puis rouvert : jamais revalidé');
    }

    /** D4 — plusieurs versions : la version CHARGÉE est pointée, les autres sont gardées et comptées. */
    public function testTheLoadedVersionIsPointedAndItsSiblingsAreKept(): void
    {
        ['club' => $club, 'seasons' => [$season]] = $this->seedDecorClub('LOAD');
        ['entryId' => $entryId, 'planId' => $planId] = $this->seedDecorPeriod($season, self::NOEL_START, self::NOEL_END);
        $v1 = $this->seedDecorVersion($season, $entryId);
        $v2 = $this->seedDecorVersion($season, $entryId);
        $v3 = $this->seedDecorVersion($season, $entryId);
        $this->scopeGucToClub($club->getId());
        $this->provisioner()->pointLockSource($planId, $v1);

        $report = $this->sweep($club, self::THRESHOLD);

        self::assertSame($v1, $this->decorChosenOf($club->getId(), $planId), 'la version chargée (V1) est pointée, pas la plus récente');
        self::assertTrue($this->decorVersionExists($club->getId(), $v2), 'V2 gardée');
        self::assertTrue($this->decorVersionExists($club->getId(), $v3), 'V3 gardée');
        self::assertSame([['planId' => $planId, 'scheduleId' => $v1, 'keptVersions' => 2]], $report->validated);

        $alert = $this->alertOf($club, $planId, self::THRESHOLD);
        self::assertSame(['kind' => PeriodPlanAlertResolver::KIND_AUTO_VALIDATED, 'chosenVersionNumber' => 1, 'keptVersionCount' => 2], $alert);
    }

    /** D4 — un pointeur « chargé » incohérent (version échouée) retombe sur la dernière version terminée. */
    public function testAStaleLoadedPointerFallsBackToTheLatestFinishedVersion(): void
    {
        ['club' => $club, 'seasons' => [$season]] = $this->seedDecorClub('STALE');
        ['entryId' => $entryId, 'planId' => $planId] = $this->seedDecorPeriod($season, self::NOEL_START, self::NOEL_END);
        $this->seedDecorVersion($season, $entryId);
        $latest = $this->seedDecorVersion($season, $entryId);
        $failed = $this->seedDecorVersion($season, $entryId, ScheduleStatus::FAILED);
        $this->scopeGucToClub($club->getId());
        $this->provisioner()->pointLockSource($planId, $failed);

        $this->sweep($club, self::THRESHOLD);

        self::assertSame($latest, $this->decorChosenOf($club->getId(), $planId), 'pointeur périmé : la dernière version COMPLETED est pointée');
    }

    /** D4 — aucune version terminée (une seule échouée) : rien n'est écrit, l'alerte « commencée sans planning » est due. */
    public function testWithoutAFinishedVersionNothingIsWrittenAndTheStartedWithoutPlanAlertIsDue(): void
    {
        ['club' => $club, 'seasons' => [$season]] = $this->seedDecorClub('NOFIN');
        ['entryId' => $entryId, 'planId' => $planId] = $this->seedDecorPeriod($season, self::NOEL_START, self::NOEL_END);
        $this->seedDecorVersion($season, $entryId, ScheduleStatus::FAILED);

        $report = $this->sweep($club, self::THRESHOLD);

        self::assertNull($this->decorChosenOf($club->getId(), $planId));
        self::assertSame([], $report->validated);
        self::assertSame([$planId], $report->startedWithoutPlan);
        self::assertSame(['kind' => PeriodPlanAlertResolver::KIND_STARTED_WITHOUT_PLAN], $this->alertOf($club, $planId, self::THRESHOLD));
    }

    /** D5 — une version en cours de génération : le plan est sauté en silence (ni écriture, ni alerte), repris au passage suivant. */
    public function testAGenerationInFlightSkipsThePlanSilently(): void
    {
        ['club' => $club, 'seasons' => [$season]] = $this->seedDecorClub('FLIGHT');
        ['entryId' => $entryId, 'planId' => $planId] = $this->seedDecorPeriod($season, self::NOEL_START, self::NOEL_END);
        $this->seedDecorVersion($season, $entryId);
        $this->seedDecorVersion($season, $entryId, ScheduleStatus::GENERATING);

        $report = $this->sweep($club, self::THRESHOLD);

        self::assertNull($this->decorChosenOf($club->getId(), $planId), 'génération en vol : aucune écriture');
        self::assertSame([$planId], $report->skippedInFlight);
        self::assertNull($this->alertOf($club, $planId, self::THRESHOLD), 'génération en vol : aucune alerte');
    }

    /** D6 / B1 — la version retenue est supprimée entre la lecture et l'écriture : 0 ligne, jamais de pointeur mort. */
    public function testAVersionDeletedBeforeTheWriteLeavesThePointerNull(): void
    {
        ['club' => $club, 'seasons' => [$season]] = $this->seedDecorClub('RACE1');
        ['entryId' => $entryId, 'planId' => $planId] = $this->seedDecorPeriod($season, self::NOEL_START, self::NOEL_END);
        $version = $this->seedDecorVersion($season, $entryId);
        ['entryId' => $witnessEntry, 'planId' => $witnessPlan] = $this->seedDecorPeriod($season, '2026-12-14', '2026-12-20', CalendarEntryPeriodType::CLOSURE, title: 'Témoin');
        $witnessVersion = $this->seedDecorVersion($season, $witnessEntry);

        $this->scopeGucToClub($club->getId());
        $this->em->getConnection()->executeStatement('DELETE FROM schedule WHERE id = :id', ['id' => $version]);

        self::assertFalse($this->provisioner()->chooseAutomatically($planId, $version, new DateTimeImmutable(self::THRESHOLD)), 'version disparue : 0 ligne');
        self::assertNull($this->decorChosenOf($club->getId(), $planId), 'aucun pointeur vers une version supprimée');
        self::assertTrue($this->provisioner()->chooseAutomatically($witnessPlan, $witnessVersion, new DateTimeImmutable(self::THRESHOLD)), 'témoin : une écriture saine passe');
    }

    /** D6 — la fenêtre est re-datée dans le futur entre la lecture et l'écriture : 0 ligne. */
    public function testAWindowRedatedIntoTheFutureBeforeTheWriteIsNotValidated(): void
    {
        ['club' => $club, 'seasons' => [$season]] = $this->seedDecorClub('RACE2');
        ['entryId' => $entryId, 'planId' => $planId] = $this->seedDecorPeriod($season, self::NOEL_START, self::NOEL_END, CalendarEntryPeriodType::CLOSURE, title: 'Fermeture re-datée');
        $version = $this->seedDecorVersion($season, $entryId);

        $this->scopeGucToClub($club->getId());
        $conn = $this->em->getConnection();
        $conn->executeStatement('UPDATE calendar_entry SET start_date = \'2027-02-01\', end_date = \'2027-02-07\' WHERE id = :id', ['id' => $entryId]);
        $conn->executeStatement('UPDATE schedule_plan SET start_date = \'2027-02-01 00:00:00+00\', end_date = \'2027-02-07 00:00:00+00\' WHERE id = :id', ['id' => $planId]);

        self::assertFalse($this->provisioner()->chooseAutomatically($planId, $version, new DateTimeImmutable(self::THRESHOLD)), 'fenêtre re-datée au futur : 0 ligne');
        self::assertNull($this->decorChosenOf($club->getId(), $planId));
    }

    /** D6 — la période est découpée (un enfant naît) entre la lecture et l'écriture : 0 ligne. */
    public function testAPeriodSplitBeforeTheWriteIsNotValidated(): void
    {
        ['club' => $club, 'seasons' => [$season]] = $this->seedDecorClub('RACE3');
        ['entryId' => $entryId, 'planId' => $planId] = $this->seedDecorPeriod($season, '2026-12-07', '2026-12-27', CalendarEntryPeriodType::CLOSURE, title: 'Fermeture bientôt découpée');
        $version = $this->seedDecorVersion($season, $entryId);

        $this->seedDecorPeriod($season, '2026-12-07', '2026-12-13', CalendarEntryPeriodType::CLOSURE, parentEntryId: $entryId, title: 'Semaine 1');

        self::assertFalse($this->provisioner()->chooseAutomatically($planId, $version, new DateTimeImmutable(self::THRESHOLD)), 'mère découpée entre-temps : 0 ligne');
        self::assertNull($this->decorChosenOf($club->getId(), $planId));
    }

    /** D6 / abus 5 — un gestionnaire valide à la main entre la lecture et l'écriture : 0 ligne, son choix et les sœurs restent. */
    public function testAManualValidationBeforeTheWriteWins(): void
    {
        ['club' => $club, 'seasons' => [$season]] = $this->seedDecorClub('RACE4');
        ['entryId' => $entryId, 'planId' => $planId] = $this->seedDecorPeriod($season, self::NOEL_START, self::NOEL_END);
        $v1 = $this->seedDecorVersion($season, $entryId);
        $v2 = $this->seedDecorVersion($season, $entryId);

        $this->scopeGucToClub($club->getId());
        $this->em->getConnection()->executeStatement(
            'UPDATE schedule_plan SET chosen_schedule_id = :sid, first_chosen_at = now() WHERE id = :pid',
            ['sid' => $v2, 'pid' => $planId],
        );

        self::assertFalse($this->provisioner()->chooseAutomatically($planId, $v1, new DateTimeImmutable(self::THRESHOLD)), 'validé à la main entre-temps : 0 ligne');
        self::assertSame($v2, $this->decorChosenOf($club->getId(), $planId), 'le choix du gestionnaire reste');
        self::assertTrue($this->decorVersionExists($club->getId(), $v1), 'les sœurs restent');
    }

    /**
     * D7 — cycle de vie : l'auto ne pose jamais `first_chosen_at` et sert `autoValidated` ;
     * « Rouvrir » fait tomber la marque et l'auto ne revalide plus ; une validation manuelle
     * ensuite pose `first_chosen_at`, supprime les sœurs, et la marque ne revient pas.
     */
    public function testReopenThenManualValidationAfterAnAutoValidation(): void
    {
        ['club' => $club, 'seasons' => [$season], 'manager' => $manager] = $this->seedDecorClub('LIFE');
        ['entryId' => $entryId, 'planId' => $planId] = $this->seedDecorPeriod($season, self::NOEL_START, self::NOEL_END);
        $v1 = $this->seedDecorVersion($season, $entryId);
        $v2 = $this->seedDecorVersion($season, $entryId);
        $this->scopeGucToClub($club->getId());
        $this->provisioner()->pointLockSource($planId, $v1);
        $token = $this->decorToken($manager);

        $this->sweep($club, self::THRESHOLD);
        self::assertSame($v1, $this->decorChosenOf($club->getId(), $planId));
        self::assertNull($this->decorFirstChosenAtOf($club->getId(), $planId), 'l\'auto ne pose pas first_chosen_at');
        self::assertTrue($this->getPlan($token, $planId)['autoValidated'] ?? null, 'GET sert autoValidated=true');

        $this->apiPost($token, \sprintf('/api/schedules/%s/reopen', $v1));
        self::assertResponseIsSuccessful('Rouvrir un plan auto-validé');
        $reopened = $this->getPlan($token, $planId);
        self::assertFalse($reopened['autoValidated'] ?? null, 'rouvert : plus de marque');
        self::assertNull($reopened['alert'] ?? 'absent', 'rouvert : plus d\'alerte');

        $this->sweep($club, '2026-12-29');
        self::assertNull($this->decorChosenOf($club->getId(), $planId), 'rouvert : l\'auto ne revalide jamais');

        $this->apiPost($token, \sprintf('/api/schedules/%s/validate', $v1));
        self::assertResponseIsSuccessful('Valider à la main après réouverture');
        self::assertNotNull($this->decorFirstChosenAtOf($club->getId(), $planId), 'la validation manuelle pose first_chosen_at');
        self::assertFalse($this->getPlan($token, $planId)['autoValidated'] ?? null, 'validé à la main : « Validé », pas « Validé automatiquement »');
        self::assertFalse($this->decorVersionExists($club->getId(), $v2), 'la validation manuelle supprime les sœurs gardées par l\'auto');
    }

    /** D10 — une version périmée (empreinte de structure différente) est validée quand même. */
    public function testAStaleVersionIsValidatedAnyway(): void
    {
        ['club' => $club, 'seasons' => [$season]] = $this->seedDecorClub('HASH');
        ['entryId' => $entryId, 'planId' => $planId] = $this->seedDecorPeriod($season, self::NOEL_START, self::NOEL_END);
        $version = $this->seedDecorVersion($season, $entryId, snapshotHash: 'empreinte-perimee');

        $this->sweep($club, self::THRESHOLD);

        self::assertSame($version, $this->decorChosenOf($club->getId(), $planId), 'périmée : validée quand même');
    }

    /** D11 — la validation automatique ne touche jamais aux matchs : un match à domicile dont le gymnase a disparu reste placé. */
    public function testMatchesAreNeverTouched(): void
    {
        ['club' => $club, 'seasons' => [$season]] = $this->seedDecorClub('MATCH');
        ['entryId' => $entryId, 'planId' => $planId] = $this->seedDecorPeriod($season, self::NOEL_START, self::NOEL_END);
        $version = $this->seedDecorVersion($season, $entryId);

        $this->scopeGucToClub($club->getId());
        $fixtureId = $this->uuid();
        $this->em->getConnection()->executeStatement(
            'INSERT INTO fixture (id, version, created_at, updated_at, club_id, season_id, team_id, match_date, home_away, opponent_label, status, venue_id, kickoff_time) '
            . 'VALUES (:id, 1, now(), now(), :club, :season, :team, \'2026-12-27\', \'HOME\', \'Adversaire\', \'PLACED\', :venue, \'15:00\')',
            ['id' => $fixtureId, 'club' => $club->getId(), 'season' => $season->getId(), 'team' => $this->uuid(), 'venue' => $this->uuid()],
        );

        $this->sweep($club, self::THRESHOLD);

        self::assertSame($version, $this->decorChosenOf($club->getId(), $planId), 'témoin : le plan est validé');
        $this->scopeGucToClub($club->getId());
        $row = $this->em->getConnection()->fetchAssociative('SELECT status, venue_id FROM fixture WHERE id = :id', ['id' => $fixtureId]);
        self::assertIsArray($row);
        self::assertSame('PLACED', $row['status'], 'le match à la salle disparue reste placé');
        self::assertNotNull($row['venue_id'], 'le match garde sa salle');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    private function sweep(Club $club, string $today): PeriodPlanSweepReport
    {
        $this->scopeGucToClub($club->getId());
        $validator = self::getContainer()->get(PeriodPlanAutoValidator::class);
        \assert($validator instanceof PeriodPlanAutoValidator);

        return $validator->sweepClub($club, new DateTimeImmutable($today));
    }

    /**
     * L'alerte servie pour ce plan au jour donné : faits en lot puis résolution pure — le chemin
     * du provider. Null = pas de carte.
     *
     * @return array<string, mixed>|null
     */
    private function alertOf(Club $club, string $planId, string $today): ?array
    {
        $this->scopeGucToClub($club->getId());
        $facts = $this->provisioner()->periodPlanFacts([$planId]);
        $resolved = new PeriodPlanAlertResolver()->forPlans($facts, new DateTimeImmutable($today));
        self::assertArrayHasKey($planId, $resolved);

        return $resolved[$planId]['alert'];
    }

    private function runSweepCommand(bool $expectSuccess = true): CommandTester
    {
        $this->clearGuc();
        $application = new Application(self::$kernel);
        $tester = new CommandTester($application->find('app:period-plans:auto-validate'));
        $tester->execute([]);
        if ($expectSuccess) {
            self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        }

        return $tester;
    }

    private function chooseThenReopen(Club $club, string $scheduleId): void
    {
        $this->scopeGucToClub($club->getId());
        $this->em->getConnection()->executeStatement(
            'UPDATE schedule_plan SET chosen_schedule_id = :sid, first_chosen_at = now() WHERE id = (SELECT schedule_plan_id FROM schedule WHERE id = :sid)',
            ['sid' => $scheduleId],
        );
        self::assertTrue($this->provisioner()->releaseSchedule($scheduleId), 'décor : le plan validé à la main est rouvert');
    }

    /** Une version COMPLETED du plan de saison, laissée NON pointée (le socle en chantier). */
    private function seedSeasonVersionNotChosen(Season $season): string
    {
        $this->scopeGucToClub($season->getClubId());
        $schedule = new Schedule()
            ->setClubId($season->getClubId())
            ->setSeasonId($season->getId())
            ->setName('Socle en chantier')
            ->setStatus(ScheduleStatus::COMPLETED);
        $this->linkSeededSchedule($schedule);
        $this->em->flush();

        return $schedule->getId();
    }

    private function provisioner(): SchedulePlanProvisioner
    {
        return self::getContainer()->get(SchedulePlanProvisioner::class);
    }

    private function autoValidatedDateOf(string $clubId, string $planId): ?string
    {
        $this->scopeGucToClub($clubId);
        $value = $this->em->getConnection()->fetchOne('SELECT auto_validated_date FROM schedule_plan WHERE id = :id', ['id' => $planId]);

        return \is_string($value) ? substr($value, 0, 10) : null;
    }

    /** @return array<string, mixed> */
    private function getPlan(string $token, string $planId): array
    {
        $this->startFreshBrowserSession($this->client);
        $this->client->request('GET', '/api/schedule_plans/' . $planId, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'HTTP_ACCEPT' => 'application/json',
        ]);
        self::assertResponseIsSuccessful();
        $body = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);

        return $body;
    }

    private function apiPost(string $token, string $path): void
    {
        $this->startFreshBrowserSession($this->client);
        $this->client->request('POST', $path, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], '{}');
    }

    private function uuid(): string
    {
        $hex = bin2hex(random_bytes(16));

        return \sprintf('%s-%s-4%s-a%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 13, 3), substr($hex, 17, 3), substr($hex, 20, 12));
    }
}

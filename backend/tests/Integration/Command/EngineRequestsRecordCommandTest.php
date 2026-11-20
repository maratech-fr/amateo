<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Command\EngineRequestsRecordCommand;
use App\Entity\Club;
use App\Entity\Competition;
use App\Entity\Fixture;
use App\Entity\Schedule;
use App\Entity\SchedulePlan;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\Season;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Entity\Venue;
use App\Entity\VenueMatchWindow;
use App\Entity\VenueTrainingSlot;
use App\Enum\CompetitionType;
use App\Enum\FixtureHomeAway;
use App\Enum\LockLevel;
use App\Enum\LockOrigin;
use App\Enum\ScheduleStatus;
use App\Enum\SeasonStatus;
use App\Service\EngineRequestFingerprint;
use App\Service\EngineRequestRecorder;
use App\Service\ScheduleConstraintBuilder;
use App\Service\SchedulePlanProvisioner;
use App\Tests\EngineRequestDecorTrait;
use DateTimeImmutable;
use FilesystemIterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Psr\Cache\CacheItemPoolInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionNamedType;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

/**
 * Promesse : `app:engine-requests:record` écrit, pour un club, TOUTES les demandes que le moteur
 * recevrait par les guichets — et seulement elles —, à l'identique d'un passage à l'autre, sans
 * rien écrire en base, sans lire un cache périmé, sans jamais mêler deux clubs, et sans laisser un
 * nom en clair sur le disque. C'est le filet d'une migration : enregistrer avant, migrer, enregistrer
 * après, `diff -r` ; une différence voulue se voit et se nomme, un geste annulé redevient identique.
 *
 * Arbre écrit sous `--out=<dossier>` :
 *   - `requests/club-<clubId>/<clé>` : un fichier par demande, au format d'empreintes
 *     ({@see EngineRequestFingerprint}, prouvé par `Unit/Service/EngineRequestFingerprintTest`) ;
 *   - `manifest.json`, HORS de l'arbre comparé : `{"clubs": [{"clubId", "requests": [clés],
 *     "skipped": [{"scope", "reason"}]}]}`.
 *
 * Les clés (une par demande atteignable, gardes d'ÉTAT ignorées) :
 *   - `season-<s>/plan-<p>/regenerate` : plan de socle qui a une version terminée ou en échec ;
 *   - `season-<s>/plan-<p>/version-<v>.generate` : socle, chaque version brouillon ou en échec ;
 *     période, la version chargée, à défaut chacune de ses versions non terminées ;
 *   - `season-<s>/plan-<p>/fill` : plan de période qui a une version chargée ;
 *   - `season-<s>/placement/all` et `season-<s>/placement/<lundi>_<dimanche>` : le club entier, puis
 *     chaque semaine ISO qui porte un match à placer (fenêtre lundi→dimanche, celle de l'écran).
 *   Sans socle en vigueur, les demandes de période sont sautées et annoncées au manifeste
 *   (`reason` = `socle_not_in_force`, `scope` = `season-<s>/plan-<p>`).
 *
 * Sorties : 0 ; 1 une demande atteignable n'a pas pu être composée (nommée) ; 2 `--club` n'est pas
 * un UUID, ou `--out` n'est pas un dossier absent ou vide ; 3 connexion sans sécurité par club
 * (jumeau : `EngineRequestsRecordConnectionGuardTest`) ; 4 environnement de production. Les refus
 * précèdent toute écriture, dans l'ordre 4, 2, 3.
 *
 * Décor : `EngineRequestDecorTrait` (identifiants fixes, horloge pinnée au lundi 2026-01-05). La
 * preuve que le fichier EST la demande du rail réel : il vaut l'empreinte des six corps de référence
 * de `tests/Fixtures/engine-requests/`, captés sur le moteur simulé par `EngineRequestReferenceTest`.
 * Pas de purge du cache ici avant d'enregistrer : la purge est le travail de l'outil.
 */
#[Group('integration')]
final class EngineRequestsRecordCommandTest extends KernelTestCase
{
    use EngineRequestDecorTrait;

    private const string REFERENCE_DIR = __DIR__ . '/../../Fixtures/engine-requests';

    private const string ER_SOCLE_V3 = '5c4e0000-0000-4000-8000-000000000003';
    private const string ER_TEAM_U17 = '7ea00000-0000-4000-8000-000000000004';

    // Un second club, ses propres identifiants fixes.
    private const string B_CLUB = 'c10b0000-0000-4000-8000-000000000002';
    private const string B_SEASON = '5ea50000-0000-4000-8000-000000000002';
    private const string B_CATEGORY = 'ca7e0000-0000-4000-8000-000000000002';
    private const string B_VENUE = 'be0e0000-0000-4000-8000-000000000009';
    private const string B_TEAM = '7ea00000-0000-4000-8000-000000000009';
    private const string B_SOCLE = '5c4e0000-0000-4000-8000-000000000009';
    private const string B_FIXTURE = 'f1c70000-0000-4000-8000-000000000009';
    private const string B_TEAM_NAME = 'Équipe secrète du club B';

    // Une seconde saison du club du décor : son id la fait enregistrer APRÈS la première.
    private const string ER_SEASON_2 = '5ea50000-0000-4000-8000-000000000003';
    private const string ER_SEASON_2_V1 = '5c4e0000-0000-4000-8000-000000000031';

    /** Tables qu'un enregistrement ne doit jamais toucher (lues sous la RLS du club du décor). */
    private const array TRACE_TABLES = [
        'club', 'season', 'schedule', 'schedule_plan', 'schedule_slot_template', 'schedule_diagnostic',
        'schedule_structure_snapshot', 'calendar_entry', 'reservation', 'team', '"constraint"', 'fixture',
        'match_placement_run', 'audit_log',
    ];

    /** @var list<string> */
    private array $outDirs = [];

    /** @return iterable<string, array{string}> */
    public static function referenceCases(): iterable
    {
        yield 'socle, première génération d\'un brouillon' => ['socle-1'];
        yield 'socle régénéré depuis la version chargée' => ['socle-2'];
        yield 'période générée sur place' => ['periode-3'];
        yield 'période comblée' => ['periode-4'];
        yield 'placement, club entier et semaine' => ['placement'];
    }

    /** @return iterable<string, array{callable, callable, callable}> */
    public static function deliberateChanges(): iterable
    {
        yield 'U13 passe de 2 à 3 séances' => [
            static function (self $t): void { $t->setSessionsPerWeek(self::ER_TEAM_U13, 3); },
            static function (self $t, array $diff, array $plans): void {
                $regenerate = 'season-' . self::ER_SEASON . '/plan-' . $plans['socle'] . '/regenerate';
                self::assertSame([], $diff['added']);
                self::assertSame([], $diff['removed']);
                self::assertSame([
                    'minus' => ['teams[' . self::ER_TEAM_U13 . "].sessionsPerWeek\t2"],
                    'plus' => ['teams[' . self::ER_TEAM_U13 . "].sessionsPerWeek\t3"],
                ], $diff['changed'][$regenerate] ?? null, 'la régénération change, sur la seule ligne des séances de U13');
                foreach ($diff['changed'] as $file => $lines) {
                    foreach ([...$lines['minus'], ...$lines['plus']] as $line) {
                        self::assertStringStartsWith('teams[' . self::ER_TEAM_U13 . '].sessionsPerWeek', $line, $file . ' : seule la ligne des séances de U13 change');
                    }
                }
            },
            static function (self $t): void { $t->setSessionsPerWeek(self::ER_TEAM_U13, 2); },
        ];

        yield 'une équipe ajoutée' => [
            static function (self $t): void { $t->addTeamU17(); },
            static function (self $t, array $diff, array $plans): void {
                $regenerate = 'season-' . self::ER_SEASON . '/plan-' . $plans['socle'] . '/regenerate';
                self::assertSame([], $diff['added']);
                self::assertSame([], $diff['removed']);
                self::assertArrayHasKey($regenerate, $diff['changed'], 'la régénération voit la nouvelle équipe');
                self::assertContains('teams[' . self::ER_TEAM_U17 . '].sessionsPerWeek' . "\t2", $diff['changed'][$regenerate]['plus']);
                self::assertCount(1, array_filter($diff['changed'][$regenerate]['minus'], static fn (string $line): bool => str_starts_with($line, "teams#order\t")), 'l\'ordre des équipes change');
                foreach ($diff['changed'] as $file => $lines) {
                    self::assertEachChangedLineNames(self::ER_TEAM_U17, $lines, $file . ' : chaque ligne changée nomme la nouvelle équipe (ses lignes, ou l\'ordre qui l\'inclut)');
                }
            },
            static function (self $t): void { $t->removeTeamU17(); },
        ];

        yield 'le verrou de U13 retiré' => [
            static function (self $t): void { $t->setLockLevel(self::erId('5107', 1), LockLevel::NONE); },
            static function (self $t, array $diff, array $plans): void {
                $regenerate = 'season-' . self::ER_SEASON . '/plan-' . $plans['socle'] . '/regenerate';
                self::assertSame([
                    'minus' => ['slotTemplates[' . self::erId('5107', 1) . "].lockLevel\tHARD"],
                    'plus' => ['slotTemplates[' . self::erId('5107', 1) . "].lockLevel\tNONE"],
                ], $diff['changed'][$regenerate] ?? null, 'la régénération perd le verrou, et rien d\'autre');
            },
            static function (self $t): void { $t->setLockLevel(self::erId('5107', 1), LockLevel::HARD); },
        ];

        yield 'un match supprimé' => [
            static function (self $t): void { $t->removeFixture(self::erId('f1c7', 2)); },
            static function (self $t, array $diff, array $plans): void {
                $placement = 'season-' . self::ER_SEASON . '/placement';
                self::assertContains($placement . '/2026-01-19_2026-01-25', $diff['removed'], 'sa semaine n\'a plus rien à placer : sa demande disparaît');
                self::assertArrayHasKey($placement . '/all', $diff['changed'], 'le placement du club entier change');
                self::assertEachChangedLineNames(self::erId('f1c7', 2), $diff['changed'][$placement . '/all'], 'chaque ligne changée du placement nomme le match supprimé');
            },
            static function (self $t): void { $t->restoreFixture2(); },
        ];
    }

    /** @return iterable<string, array{callable(string): void}> */
    public static function unusableOutFolders(): iterable
    {
        yield 'dossier non vide (reste d\'un enregistrement)' => [static function (string $out): void {
            mkdir($out, 0o775, true);
            file_put_contents($out . '/manifest.json', "reste\n");
        }];
        yield 'fichier au lieu d\'un dossier' => [static function (string $out): void {
            mkdir(\dirname($out), 0o775, true);
            file_put_contents($out, "reste\n");
        }];
    }

    /** @return iterable<string, array{string}> */
    public static function invalidClubs(): iterable
    {
        yield 'remontée de dossier' => ['../x'];
        yield 'pas un uuid' => ['club-a'];
        yield 'uuid suivi d\'un chemin' => [self::ER_CLUB . '/../../etc'];
    }

    /** @return iterable<string, array{callable(string, string): void}> */
    public static function symlinkedOutFolders(): iterable
    {
        yield 'lien vers un dossier vide' => [static function (string $link, string $target): void {
            mkdir($target, 0o775, true);
            symlink($target, $link);
        }];
        yield 'lien qui ne mène nulle part' => [static function (string $link, string $target): void {
            symlink($target, $link);
        }];
    }

    /**
     * Chaque ligne changée nomme `$id` : elle le contient, OU c'est la ligne d'ordre d'une liste
     * (`<liste>#order\t…`) dont la ligne d'ordre de l'AUTRE côté du diff le contient — l'ordre d'avant
     * un ajout ne peut pas citer l'élément qui n'existe pas encore, ni l'ordre d'après une suppression
     * l'élément disparu. Toute autre ligne qui ne le nomme pas fait échouer.
     *
     * @param array{minus: list<string>, plus: list<string>} $lines
     */
    private static function assertEachChangedLineNames(string $id, array $lines, string $message): void
    {
        foreach (['minus' => 'plus', 'plus' => 'minus'] as $side => $other) {
            foreach ($lines[$side] as $line) {
                if (str_contains($line, $id)) {
                    continue;
                }
                $orderPrefix = 1 === preg_match('/\A[^\t]*#order\t/', $line, $match) ? $match[0] : null;
                $named = null !== $orderPrefix && [] !== array_filter(
                    $lines[$other],
                    static fn (string $otherLine): bool => str_starts_with($otherLine, $orderPrefix) && str_contains($otherLine, $id),
                );
                self::assertTrue($named, \sprintf('%s — ligne « %s » (%s) : ni elle ni sa ligne d\'ordre de l\'autre côté ne nomment %s', $message, $line, $side, $id));
            }
        }
    }

    /**
     * La demande enregistrée EST celle que le moteur reçoit : chacun des six corps captés sur le rail
     * réel (handler + `EngineClient` + moteur simulé) a pour empreinte le fichier de sa clé.
     */
    #[DataProvider('referenceCases')]
    public function testEachRecordedRequestIsTheBodyTheEngineReceives(string $case): void
    {
        $references = $this->seedReferenceCase($case);

        $files = $this->clubFiles($this->recordOk([self::ER_CLUB]), self::ER_CLUB);

        foreach ($references as $key => $reference) {
            self::assertArrayHasKey($key, $files, \sprintf('%s : la demande %s doit être enregistrée', $case, $key));
            self::assertSame(
                EngineRequestFingerprint::render($this->reference($reference)),
                $files[$key],
                \sprintf('%s : le fichier %s doit être l\'empreinte du corps reçu par le moteur (%s.json)', $case, $key, $reference),
            );
        }
    }

    public function testOnlyReachableRequestsAreRecorded(): void
    {
        $plans = $this->seedReachableDecor();
        $socle = 'season-' . self::ER_SEASON . '/plan-' . $plans['socle'];
        $period = 'season-' . self::ER_SEASON . '/plan-' . $plans['period'];
        $placement = 'season-' . self::ER_SEASON . '/placement';

        $out = $this->recordOk([self::ER_CLUB]);

        $expected = [
            $socle . '/regenerate',
            $socle . '/version-' . self::ER_SOCLE_V2 . '.generate',
            $socle . '/version-' . self::ER_SOCLE_V3 . '.generate',
            $period . '/version-' . self::ER_PERIOD_P1 . '.generate',
            $period . '/fill',
            $placement . '/all',
            $placement . '/2026-01-12_2026-01-18',
            $placement . '/2026-01-19_2026-01-25',
            $placement . '/2026-01-26_2026-02-01',
        ];
        sort($expected);
        $recorded = array_keys($this->clubFiles($out, self::ER_CLUB));
        sort($recorded);

        // Absents à dessein : V1 terminée (le socle ne se génère pas sur place une fois terminé), P2
        // brouillon de la période (une version chargée existe : c'est elle qu'on génère).
        self::assertSame($expected, $recorded, 'exactement les demandes atteignables, aucune autre');
        $manifest = $this->manifestOf($out, self::ER_CLUB);
        $listed = $manifest['requests'];
        sort($listed);
        self::assertSame($expected, $listed, 'le manifeste liste les demandes écrites');
        self::assertSame([], $manifest['skipped'], 'socle en vigueur : rien n\'est sauté');
    }

    public function testAPeriodWithoutLoadedVersionRecordsEachUnfinishedVersion(): void
    {
        $this->seedSocleInForce();
        $planId = $this->seedEngineRequestPeriod();
        $this->seedPeriodVersion(self::ER_PERIOD_P1, ScheduleStatus::DRAFT);
        $this->seedPeriodVersion(self::ER_PERIOD_P2, ScheduleStatus::FAILED);
        $period = 'season-' . self::ER_SEASON . '/plan-' . $planId;

        $periodKeys = array_values(array_filter(
            array_keys($this->clubFiles($this->recordOk([self::ER_CLUB]), self::ER_CLUB)),
            static fn (string $key): bool => str_starts_with($key, $period . '/'),
        ));
        sort($periodKeys);

        self::assertSame([
            $period . '/version-' . self::ER_PERIOD_P1 . '.generate',
            $period . '/version-' . self::ER_PERIOD_P2 . '.generate',
        ], $periodKeys, 'sans version chargée : chaque version non terminée, et pas de comblement');
    }

    public function testWithoutSocleInForceThePeriodRequestsAreSkippedAndAnnounced(): void
    {
        // Socle terminé et chargé, mais pas validé : aucune demande de période n'est composable.
        $this->seedSocleVersion(self::ER_SOCLE_V1, ScheduleStatus::COMPLETED, [
            [1, self::ER_TEAM_U13, self::ER_VENUE_NORD, 1, '18:00', LockLevel::HARD],
        ]);
        $this->loadSocle(self::ER_SOCLE_V1);
        $planId = $this->seedEngineRequestPeriod();
        $this->seedPeriodVersion(self::ER_PERIOD_P1, ScheduleStatus::DRAFT);
        $period = 'season-' . self::ER_SEASON . '/plan-' . $planId;

        $out = $this->recordOk([self::ER_CLUB]);

        $keys = array_keys($this->clubFiles($out, self::ER_CLUB));
        self::assertContains('season-' . self::ER_SEASON . '/plan-' . $this->erSeasonPlanId() . '/regenerate', $keys, 'le socle, lui, s\'enregistre');
        self::assertSame([], array_values(array_filter($keys, static fn (string $key): bool => str_starts_with($key, $period . '/'))), 'aucune demande de période sans socle en vigueur');
        self::assertContains(
            ['scope' => $period, 'reason' => 'socle_not_in_force'],
            $this->manifestOf($out, self::ER_CLUB)['skipped'],
            'la période sautée est annoncée au manifeste, jamais tue',
        );
    }

    public function testTwoRecordingsAreIdenticalAndLeaveNoTrace(): void
    {
        $this->seedReachableDecor();
        $before = $this->traceDigest();

        $first = $this->allFiles($this->recordOk([self::ER_CLUB]));
        $second = $this->allFiles($this->recordOk([self::ER_CLUB]));

        self::assertNotSame([], $first);
        self::assertSame($first, $second, 'deux enregistrements de la même base sont identiques à l\'octet');
        // Une entité laissée dans l'unité de travail par l'outil serait écrite par ce flush.
        $this->erEm()->flush();
        self::assertSame($before, $this->traceDigest(), 'l\'enregistrement n\'écrit rien en base (ni version, ni numéro, ni run)');
    }

    /**
     * Le filet d'une migration : un geste sur les données se voit dans `diff -r`, ne change que les
     * lignes qui le nomment, et le geste inverse rend un enregistrement identique au premier.
     *
     * @param callable(self): void                                                                                                                                                                  $gesture
     * @param callable(self): void                                                                                                                                                                  $inverse
     * @param callable(self, array{added: list<string>, removed: list<string>, changed: array<string, array{minus: list<string>, plus: list<string>}>}, array{socle: string, period: string}): void $expect
     */
    #[DataProvider('deliberateChanges')]
    public function testADeliberateChangeIsDetectedNamedAndUndone(callable $gesture, callable $expect, callable $inverse): void
    {
        $plans = $this->seedReachableDecor();
        $before = $this->clubFiles($this->recordOk([self::ER_CLUB]), self::ER_CLUB);

        $gesture($this);
        $after = $this->clubFiles($this->recordOk([self::ER_CLUB]), self::ER_CLUB);
        $diff = $this->treeDiff($before, $after);
        self::assertNotSame(['added' => [], 'removed' => [], 'changed' => []], $diff, 'le geste doit se voir');
        $expect($this, $diff, $plans);

        $inverse($this);
        self::assertSame($before, $this->clubFiles($this->recordOk([self::ER_CLUB]), self::ER_CLUB), 'le geste inverse rend l\'enregistrement d\'avant');
    }

    /**
     * Le payload du socle est servi 4 h par le cache (`cache.schedule`), qu'une écriture SQL brute
     * n'invalide pas. L'outil purge le tag du club avant chaque construction : une valeur amorcée
     * n'atteint jamais le disque, et un `UPDATE` brut fait après un premier enregistrement se voit.
     */
    public function testAPrimedCacheAndARawSqlWriteAreSeen(): void
    {
        $plans = $this->seedReachableDecor();
        $regenerate = 'season-' . self::ER_SEASON . '/plan-' . $plans['socle'] . '/regenerate';
        $primed = ['amorce' => 'valeur qui n\'est pas la demande'];

        // Le piège est réel : sans purge, le constructeur sert la valeur amorcée.
        $this->primeSocleCache($primed);
        self::assertSame(
            $primed,
            self::getContainer()->get(ScheduleConstraintBuilder::class)->buildForClubSeason(self::ER_CLUB, self::ER_SEASON, lockVersionId: self::ER_SOCLE_V1),
            'sans purge, le constructeur sert la valeur amorcée',
        );

        $this->primeSocleCache($primed);
        $first = $this->clubFiles($this->recordOk([self::ER_CLUB]), self::ER_CLUB)[$regenerate] ?? '';
        self::assertStringNotContainsString('amorce', $first, 'la valeur amorcée n\'atteint jamais le disque');
        self::assertStringContainsString("clubId\t" . self::ER_CLUB . "\n", $first, 'la vraie demande est enregistrée');
        self::assertStringContainsString('teams[' . self::ER_TEAM_U13 . "].sessionsPerWeek\t2\n", $first);

        // Écriture SQL brute : aucun listener Doctrine ne purge le cache rempli par le 1er enregistrement.
        $this->erEm()->getConnection()->executeStatement('UPDATE team SET sessions_per_week = 3 WHERE id = ?', [self::ER_TEAM_U13]);
        $second = $this->clubFiles($this->recordOk([self::ER_CLUB]), self::ER_CLUB)[$regenerate] ?? '';

        self::assertStringContainsString('teams[' . self::ER_TEAM_U13 . "].sessionsPerWeek\t3\n", $second, 'l\'UPDATE brut se voit : le cache a été purgé avant de composer');
    }

    /**
     * Le placement lit ses tables SANS filtre de club (`findBy([])`) : seule la RLS de la connexion
     * `amateo_app`, posée sur le club comme le fait le worker, borne ce qu'il voit. Deux clubs : aucun
     * identifiant ni nom de l'un dans les demandes de l'autre, et l'ordre des clubs ne change rien
     * (un club enregistré seul = le même club enregistré après l'autre).
     */
    public function testTwoClubsStayIsolatedUnderTheApplicationRole(): void
    {
        $connection = $this->erEm()->getConnection();
        self::assertSame('amateo_app', $connection->fetchOne('SELECT current_user'), 'la preuve se fait sous le rôle de l\'application');
        self::assertFalse((bool) $connection->fetchOne('SELECT rolsuper OR rolbypassrls FROM pg_roles WHERE rolname = current_user'), 'un rôle qui contourne la RLS ne prouverait rien');

        $this->seedSocleInForce();
        $this->seedEngineRequestMatches();
        $this->seedClubB();

        $aAlone = $this->clubFiles($this->recordOk([self::ER_CLUB]), self::ER_CLUB);
        $bAlone = $this->clubFiles($this->recordOk([self::B_CLUB]), self::B_CLUB);
        $bothOut = $this->recordOk([self::ER_CLUB, self::B_CLUB]);
        $reverseOut = $this->recordOk([self::B_CLUB, self::ER_CLUB]);

        self::assertContains('season-' . self::ER_SEASON . '/placement/all', array_keys($aAlone), 'A a sa demande de placement');
        self::assertContains('season-' . self::B_SEASON . '/placement/all', array_keys($bAlone), 'B a sa demande de placement');
        $this->assertNothingOf(['club B' => [self::B_CLUB, self::B_SEASON, self::B_TEAM, self::B_VENUE, self::B_FIXTURE, hash('sha256', self::B_TEAM_NAME)]], $aAlone);
        $this->assertNothingOf(['club A' => [self::ER_CLUB, self::ER_SEASON, self::ER_TEAM_U13, self::ER_VENUE_NORD, self::erId('f1c7', 1), hash('sha256', 'U13 F')]], $bAlone);

        self::assertSame($bAlone, $this->clubFiles($bothOut, self::B_CLUB), 'B enregistré après A = B seul : rien ne reste du club précédent');
        self::assertSame($aAlone, $this->clubFiles($reverseOut, self::ER_CLUB), 'A enregistré après B = A seul');
        self::assertSame($aAlone, $this->clubFiles($bothOut, self::ER_CLUB));
    }

    public function testNoNameIsWrittenInClear(): void
    {
        $this->seedReachableDecor();

        $files = $this->allFiles($this->recordOk([self::ER_CLUB]));
        self::assertArrayHasKey('manifest.json', $files, 'le manifeste est écrit à la racine, hors de requests/');

        foreach ($files as $path => $content) {
            foreach (['U13 F', 'U15 M', 'SM1', 'Alix', 'Bao', 'Entraîneur', 'Gymnase Nord', 'Gymnase Sud', 'Club de référence', 'Adversaire 1', 'Contrainte 1', 'Fermeture du gymnase Sud', 'Championnat 1'] as $name) {
                self::assertStringNotContainsString($name, $content, \sprintf('%s : « %s » écrit en clair', $path, $name));
            }
        }
    }

    #[DataProvider('invalidClubs')]
    public function testAClubOptionThatIsNotAUuidIsRefusedBeforeAnyWrite(string $club): void
    {
        $out = $this->newOutDir();

        $tester = $this->tester();
        $code = $tester->execute(['--out' => $out, '--club' => [$club]]);

        self::assertSame(2, $code, '--club non UUID : sortie 2. ' . $tester->getDisplay());
        self::assertSame([], $this->allFiles($out), 'rien n\'est écrit');
        self::assertDirectoryDoesNotExist(\dirname($out) . '/x', 'aucun chemin hors du dossier de sortie');
    }

    public function testARequestThatCannotBeComposedFailsAndIsNamed(): void
    {
        $this->seedSocleInForce();
        $planId = $this->seedEngineRequestPeriod();
        $this->seedPeriodVersion(self::ER_PERIOD_P1, ScheduleStatus::DRAFT);
        // L'entrée de calendrier disparaît sous son plan : la version reste là, le guichet l'accepte,
        // la composition échoue (période introuvable). L'outil ne doit pas la taire.
        $this->erEm()->flush();
        $this->erEm()->getConnection()->executeStatement('DELETE FROM calendar_entry WHERE id = ?', [self::ER_ENTRY]);
        $this->erEm()->clear();

        $tester = $this->tester();
        $code = $tester->execute(['--out' => $this->newOutDir(), '--club' => [self::ER_CLUB]]);

        self::assertSame(1, $code, 'une demande atteignable non composée : sortie 1. ' . $tester->getDisplay());
        self::assertStringContainsString('plan-' . $planId, $tester->getDisplay(), 'la demande en échec est nommée');
    }

    /**
     * « Jamais en production » : le refus précède tout. Limite (écrite dans la spec du lot) : il ne
     * protège pas d'un processus de développement branché par `DATABASE_URL` sur la base de prod.
     * La commande est construite telle que le conteneur la construit, l'environnement seul changé
     * (paramètre de constructeur `$environment`).
     */
    public function testRefusesToRunInProduction(): void
    {
        $this->seedReachableDecor();
        $out = $this->newOutDir();

        $tester = new CommandTester($this->commandIn('prod'));
        $code = $tester->execute(['--out' => $out, '--club' => [self::ER_CLUB]]);

        self::assertSame(4, $code, 'environnement de production : sortie 4. ' . $tester->getDisplay());
        self::assertSame([], $this->allFiles($out), 'rien n\'est écrit');

        $control = new CommandTester($this->commandIn('test'));
        self::assertSame(0, $control->execute(['--out' => $this->newOutDir(), '--club' => [self::ER_CLUB]]), 'témoin : la même construction hors production enregistre. ' . $control->getDisplay());
    }

    /**
     * Le refus de production passe avant tout autre refus : un `--club` invalide et un `--out` non vide
     * en production rendent 4, pas 2 (ordre des refus : 4, puis 2, puis 3 — le 2 avant le 3 est prouvé
     * par `EngineRequestsRecordConnectionGuardTest::testAnInvalidOptionIsRefusedBeforeTheConnectionIsJudged`).
     */
    public function testProductionIsRefusedBeforeEveryOtherRefusal(): void
    {
        $out = $this->newOutDir();
        mkdir($out, 0o775, true);
        file_put_contents($out . '/reste', "x\n");

        $tester = new CommandTester($this->commandIn('prod'));
        $code = $tester->execute(['--out' => $out, '--club' => ['../x']]);

        self::assertSame(4, $code, 'production d\'abord, quelles que soient les options. ' . $tester->getDisplay());
        self::assertSame(['reste' => "x\n"], $this->allFiles($out), 'rien n\'est écrit');
    }

    /**
     * `--out` doit désigner un dossier absent ou vide : un reste d'enregistrement précédent fausserait
     * `diff -r` (un fichier d'avant passerait pour une demande d'après). Refus en sortie 2, avant toute
     * écriture ; ce qui était là reste à l'identique.
     *
     * @param callable(string): void $prepare
     */
    #[DataProvider('unusableOutFolders')]
    public function testAnOutThatIsNotAnEmptyFolderIsRefusedBeforeAnyWrite(callable $prepare): void
    {
        $this->seedReachableDecor();
        $out = $this->newOutDir();
        $prepare($out);
        $before = $this->allFiles(\dirname($out));

        $tester = $this->tester();
        $code = $tester->execute(['--out' => $out, '--club' => [self::ER_CLUB]]);

        self::assertSame(2, $code, '--out inutilisable : sortie 2. ' . $tester->getDisplay());
        self::assertSame($before, $this->allFiles(\dirname($out)), 'rien n\'est écrit, ce qui était là reste');
    }

    public function testAnEmptyOrAbsentOutFolderIsAccepted(): void
    {
        $this->seedReachableDecor();
        $empty = $this->newOutDir();
        mkdir($empty, 0o775, true);

        $tester = $this->tester();
        self::assertSame(0, $tester->execute(['--out' => $empty, '--club' => [self::ER_CLUB]]), 'dossier vide accepté. ' . $tester->getDisplay());
        self::assertFileExists($empty . '/manifest.json');

        // `recordOk` part d'un dossier qui n'existe pas encore (`newOutDir`).
        self::assertFileExists($this->recordOk([self::ER_CLUB]) . '/manifest.json', 'dossier absent accepté, et créé');
    }

    /**
     * Le cache `cache.schedule` ne garde pas la dernière demande composée d'un club : l'outil purge le
     * tag du club à la FIN de chaque club, y compris quand une de ses demandes échoue (sortie 1). La
     * dernière composition du club est la régénération de sa seconde saison (enregistrée après la
     * première) : aucune purge d'avant-composition ne la suit. Témoin : la clé vérifiée est bien celle
     * que le constructeur remplit.
     */
    public function testTheClubCacheIsPurgedAtTheEndOfTheClubEvenWhenOneOfItsRequestsFails(): void
    {
        $this->seedSocleInForce();
        $periodPlanId = $this->seedEngineRequestPeriod();
        $this->seedPeriodVersion(self::ER_PERIOD_P1, ScheduleStatus::DRAFT);
        $secondPlanId = $this->seedSecondSeasonWithLoadedSocle();
        // La période perd son entrée de calendrier : sa demande échoue (patron de
        // `testARequestThatCannotBeComposedFailsAndIsNamed`).
        $this->erEm()->flush();
        $this->erEm()->getConnection()->executeStatement('DELETE FROM calendar_entry WHERE id = ?', [self::ER_ENTRY]);
        $this->erEm()->clear();
        $this->purgeEngineRequestCache();
        $lastKey = ScheduleConstraintBuilder::cacheKey(self::ER_CLUB, self::ER_SEASON_2, self::ER_SEASON_2_V1);

        $out = $this->newOutDir();
        $tester = $this->tester();
        $code = $tester->execute(['--out' => $out, '--club' => [self::ER_CLUB]]);

        self::assertSame(1, $code, 'une demande du club échoue : sortie 1. ' . $tester->getDisplay());
        self::assertStringContainsString('plan-' . $periodPlanId, $tester->getDisplay());
        self::assertArrayHasKey('season-' . self::ER_SEASON_2 . '/plan-' . $secondPlanId . '/regenerate', $this->clubFiles($out, self::ER_CLUB), 'la seconde saison est composée, après l\'échec');
        self::assertFalse($this->scheduleCachePool()->getItem($lastKey)->isHit(), 'la dernière demande composée du club ne reste pas dans le cache');

        // Témoin : cette clé est celle que le constructeur remplit.
        self::getContainer()->get(ScheduleConstraintBuilder::class)->buildForClubSeason(self::ER_CLUB, self::ER_SEASON_2, lockVersionId: self::ER_SEASON_2_V1);
        self::assertTrue($this->scheduleCachePool()->getItem($lastKey)->isHit(), 'témoin : un build direct remplit la clé vérifiée');
        $this->purgeEngineRequestCache();
    }

    /**
     * Une purge du cache qui échoue (Redis tombé) en fin de club ne laisse pas le club posé sur la
     * connexion ni ses entités dans l'EntityManager : l'échec remonte, le relâchement a lieu quand même.
     * Le recorder est construit comme le conteneur le construit, seul son cache remplacé par un cache
     * dont `invalidateTags` lève.
     */
    public function testAFailingCachePurgeStillReleasesTheClubAndClearsTheEntityManager(): void
    {
        $em = $this->erEm();
        $team = $em->getRepository(Team::class)->find(self::ER_TEAM_U13);
        self::assertInstanceOf(Team::class, $team);
        self::assertTrue($em->contains($team), 'témoin : une entité du club est gérée avant l\'enregistrement');
        $this->clearGuc();

        $recorder = $this->recorderWithCache($this->cacheThatCannotPurge());
        try {
            $recorder->requestsOfClub(self::ER_CLUB);
            self::fail('l\'échec de la purge du cache doit remonter');
        } catch (RuntimeException $failure) {
            self::assertSame('Redis tombé', $failure->getMessage());
        }

        self::assertSame('', (string) $em->getConnection()->fetchOne('SELECT COALESCE(current_setting(\'app.club_id\', true), \'\')'), 'le club n\'est plus posé sur la connexion');
        self::assertFalse($em->contains($team), 'l\'EntityManager est vidé : rien du club ne reste pour le suivant');
    }

    /**
     * La racine `--out` naît en 0700 : les empreintes de prénoms se retrouvent par dictionnaire, les
     * fichiers se traitent comme la base dont ils viennent (lisibles du seul propriétaire).
     */
    public function testTheOutFolderIsCreatedReadableByItsOwnerOnly(): void
    {
        $this->seedReachableDecor();

        $out = $this->recordOk([self::ER_CLUB]);

        clearstatcache();
        self::assertSame('0700', \sprintf('%04o', fileperms($out) & 0o777), '--out créé en 0700');
    }

    /**
     * Un `--out` qui est un lien symbolique est refusé (sortie 2) avant toute écriture : l'outil
     * n'écrit jamais là où un lien le mènerait.
     *
     * @param callable(string, string): void $prepare
     */
    #[DataProvider('symlinkedOutFolders')]
    public function testAnOutThatIsASymbolicLinkIsRefusedBeforeAnyWrite(callable $prepare): void
    {
        $this->seedReachableDecor();
        $base = \dirname($this->newOutDir());
        mkdir($base, 0o775, true);
        $link = $base . '/out';
        $target = $base . '/cible';
        $prepare($link, $target);

        $tester = $this->tester();
        $code = $tester->execute(['--out' => $link, '--club' => [self::ER_CLUB]]);

        self::assertSame(2, $code, '--out lien symbolique : sortie 2. ' . $tester->getDisplay());
        self::assertTrue(is_link($link), 'le lien reste tel quel');
        if (is_dir($target)) {
            self::assertSame([], $this->allFiles($target), 'rien n\'est écrit au bout du lien');
        } else {
            self::assertFileDoesNotExist($target, 'le lien qui ne menait nulle part ne mène toujours nulle part');
        }
    }

    // ---- gestes (appelés par les fournisseurs de données) --------------------------------------

    public function setSessionsPerWeek(string $teamId, int $sessions): void
    {
        $this->scopeGucToClub(self::ER_CLUB);
        $team = $this->erEm()->getRepository(Team::class)->find($teamId);
        self::assertInstanceOf(Team::class, $team);
        $team->setSessionsPerWeek($sessions);
        $this->erEm()->flush();
    }

    public function addTeamU17(): void
    {
        $this->scopeGucToClub(self::ER_CLUB);
        $this->erEm()->persist((new Team)->setId(self::ER_TEAM_U17)->setClubId(self::ER_CLUB)->setSeasonId(self::ER_SEASON)
            ->setSportCategoryId(self::ER_CATEGORY)->setPriorityTierId(2)->setName('U17 M')
            ->setSessionsPerWeek(2)->setIsActive(true));
        $this->erEm()->flush();
    }

    public function removeTeamU17(): void
    {
        $this->scopeGucToClub(self::ER_CLUB);
        $team = $this->erEm()->getRepository(Team::class)->find(self::ER_TEAM_U17);
        self::assertInstanceOf(Team::class, $team);
        $this->erEm()->remove($team);
        $this->erEm()->flush();
    }

    public function setLockLevel(string $slotId, LockLevel $level): void
    {
        $this->scopeGucToClub(self::ER_CLUB);
        $slot = $this->erEm()->getRepository(ScheduleSlotTemplate::class)->find($slotId);
        self::assertInstanceOf(ScheduleSlotTemplate::class, $slot);
        $slot->setLockLevel($level)->setLockOrigin(LockLevel::HARD === $level ? LockOrigin::MANUAL : null);
        $this->erEm()->flush();
    }

    public function removeFixture(string $fixtureId): void
    {
        $this->scopeGucToClub(self::ER_CLUB);
        $fixture = $this->erEm()->getRepository(Fixture::class)->find($fixtureId);
        self::assertInstanceOf(Fixture::class, $fixture);
        $this->erEm()->remove($fixture);
        $this->erEm()->flush();
    }

    /** Recrée le match 2 du décor (`seedEngineRequestMatches`), à l'identique. */
    public function restoreFixture2(): void
    {
        $this->scopeGucToClub(self::ER_CLUB);
        $fixture = (new Fixture)->setId(self::erId('f1c7', 2));
        $fixture->setClubId(self::ER_CLUB);
        $fixture->setSeasonId(self::ER_SEASON);
        $fixture->setTeamId(self::ER_TEAM_U15);
        $fixture->setCompetitionId(self::erId('c0e9', 2));
        $fixture->setMatchDate(new DateTimeImmutable('2026-01-24'));
        $fixture->setHomeAway(FixtureHomeAway::HOME);
        $fixture->setOpponentLabel('Adversaire 2');
        $this->erEm()->persist($fixture);
        $this->erEm()->flush();
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->pinEngineRequestClock();
        $this->seedEngineRequestBase();
    }

    protected function tearDown(): void
    {
        $this->unpinEngineRequestClock();
        foreach ($this->outDirs as $dir) {
            $this->removeTree($dir);
        }
        $this->outDirs = [];
        parent::tearDown();
    }

    // ---- décors ---------------------------------------------------------------------------------

    /** Le socle en vigueur : V1 terminée, pointée et chargée, un verrou HARD sur U13. */
    private function seedSocleInForce(): void
    {
        $this->seedSocleVersion(self::ER_SOCLE_V1, ScheduleStatus::COMPLETED, [
            [1, self::ER_TEAM_U13, self::ER_VENUE_NORD, 1, '18:00', LockLevel::HARD],
            [2, self::ER_TEAM_U15, self::ER_VENUE_NORD, 3, '18:00', LockLevel::NONE],
            [3, self::ER_TEAM_SM1, self::ER_VENUE_NORD, 4, '18:00', LockLevel::NONE],
        ]);
        $this->chooseAndLoadSocle(self::ER_SOCLE_V1);
    }

    /**
     * Toutes les sortes de demandes à la fois : socle en vigueur (V1) avec une version en échec (V2)
     * et un brouillon (V3), une période à version chargée (P1) et un brouillon (P2), les matchs.
     *
     * @return array{socle: string, period: string}
     */
    private function seedReachableDecor(): array
    {
        $this->seedSocleInForce();
        $this->seedSocleVersion(self::ER_SOCLE_V2, ScheduleStatus::FAILED);
        $this->seedSocleVersion(self::ER_SOCLE_V3, ScheduleStatus::DRAFT);
        $periodPlanId = $this->seedEngineRequestPeriod();
        $this->seedPeriodVersion(self::ER_PERIOD_P1, ScheduleStatus::COMPLETED, [
            [11, self::ER_TEAM_U15, self::ER_VENUE_NORD, 1, '19:30', LockLevel::HARD],
            [12, self::ER_TEAM_SM1, self::ER_VENUE_NORD, 4, '18:00', LockLevel::NONE],
        ]);
        self::getContainer()->get(SchedulePlanProvisioner::class)->pointLockSource($periodPlanId, self::ER_PERIOD_P1);
        $this->seedPeriodVersion(self::ER_PERIOD_P2, ScheduleStatus::DRAFT);
        $this->seedEngineRequestMatches();
        $this->erEm()->flush();

        return ['socle' => $this->erSeasonPlanId(), 'period' => $periodPlanId];
    }

    /**
     * Le décor de chaque cas de `EngineRequestReferenceTest`, à l'identique ; rend clé → référence.
     *
     * @return array<string, string>
     */
    private function seedReferenceCase(string $case): array
    {
        $socle = fn (): string => 'season-' . self::ER_SEASON . '/plan-' . $this->erSeasonPlanId();

        switch ($case) {
            case 'socle-1':
                $this->seedSocleVersion(self::ER_SOCLE_V1, ScheduleStatus::DRAFT);

                return [$socle() . '/version-' . self::ER_SOCLE_V1 . '.generate' => 'socle-1-premiere-generation-brouillon'];
            case 'socle-2':
                $this->seedSocleVersion(self::ER_SOCLE_V1, ScheduleStatus::COMPLETED, [
                    [1, self::ER_TEAM_U13, self::ER_VENUE_NORD, 1, '18:00', LockLevel::HARD],
                    [2, self::ER_TEAM_U15, self::ER_VENUE_NORD, 3, '18:00', LockLevel::NONE],
                    [3, self::ER_TEAM_SM1, self::ER_VENUE_NORD, 4, '18:00', LockLevel::NONE],
                ]);
                $this->loadSocle(self::ER_SOCLE_V1);
                $this->seedSocleVersion(self::ER_SOCLE_V2, ScheduleStatus::PENDING);

                return [$socle() . '/regenerate' => 'socle-2-regeneration-version-chargee-verrou'];
            case 'periode-3':
                $this->seedSocleInForce();
                $planId = $this->seedEngineRequestPeriod();
                $this->seedPeriodVersion(self::ER_PERIOD_P1, ScheduleStatus::DRAFT, [
                    [11, self::ER_TEAM_U15, self::ER_VENUE_NORD, 1, '19:30', LockLevel::HARD],
                ]);

                return ['season-' . self::ER_SEASON . '/plan-' . $planId . '/version-' . self::ER_PERIOD_P1 . '.generate' => 'periode-3-generation-sur-place-verrou-garder-creneaux'];
            case 'periode-4':
                $this->seedSocleInForce();
                $planId = $this->seedEngineRequestPeriod();
                $this->seedPeriodVersion(self::ER_PERIOD_P1, ScheduleStatus::COMPLETED, [
                    [11, self::ER_TEAM_U15, self::ER_VENUE_NORD, 1, '19:30', LockLevel::HARD],
                    [12, self::ER_TEAM_SM1, self::ER_VENUE_NORD, 4, '18:00', LockLevel::NONE],
                ]);
                self::getContainer()->get(SchedulePlanProvisioner::class)->pointLockSource($planId, self::ER_PERIOD_P1);
                $this->seedPeriodVersion(self::ER_PERIOD_P2, ScheduleStatus::PENDING);

                return ['season-' . self::ER_SEASON . '/plan-' . $planId . '/fill' => 'periode-4-comblement'];
            case 'placement':
                $this->seedSocleInForce();
                $this->seedEngineRequestMatches();

                return [
                    'season-' . self::ER_SEASON . '/placement/all' => 'placement-5-club-entier',
                    'season-' . self::ER_SEASON . '/placement/2026-01-12_2026-01-18' => 'placement-6-fenetre',
                ];
        }

        self::fail('cas inconnu : ' . $case);
    }

    /**
     * Le club B : une équipe au nom à ne jamais voir ailleurs, un gymnase, un créneau, un socle en
     * vigueur et un match à domicile à placer. Graine écrite sous la RLS de B, puis retour sur A.
     */
    private function seedClubB(): void
    {
        $em = $this->erEm();
        $em->persist((new Club)->setId(self::B_CLUB)->setName('Club B')->setSlug('engine-request-club-b')
            ->setTimezone('Europe/Paris')->setLocale('fr')->setOnboardingCompleted(true));
        $em->flush();
        $this->scopeGucToClub(self::B_CLUB);

        $season = (new Season)->setId(self::B_SEASON)->setClubId(self::B_CLUB)->setName('2025-2026')
            ->setStartDate(new DateTimeImmutable('2025-08-01'))->setEndDate(new DateTimeImmutable('2026-07-15'))
            ->setStatus(SeasonStatus::ACTIVE)->setTransitionData([]);
        $em->persist($season);
        $em->flush();
        $provisioner = self::getContainer()->get(SchedulePlanProvisioner::class);
        $provisioner->ensureSeasonPlan($season);
        $em->flush();

        $categoryA = $em->getRepository(SportCategory::class)->find(self::ER_CATEGORY);
        self::assertInstanceOf(SportCategory::class, $categoryA);
        $em->persist((new SportCategory)->setId(self::B_CATEGORY)->setClubId(self::B_CLUB)->setSportId($categoryA->getSportId())
            ->setName('Catégorie B')->setMatchMinutes(80)->setWarmupMinutes(30));
        $em->persist((new Venue)->setId(self::B_VENUE)->setClubId(self::B_CLUB)->setSeasonId(self::B_SEASON)
            ->setName('Gymnase B')->setCanSplit(false)->setSource('manual'));
        $em->persist((new VenueTrainingSlot)->setId(self::erId('a7a1', 9))->setClubId(self::B_CLUB)->setSeasonId(self::B_SEASON)
            ->setVenueId(self::B_VENUE)->setDayOfWeek(2)->setStartTime(new DateTimeImmutable('18:00'))
            ->setDurationMinutes(90)->setCapacity(1));
        $em->persist((new Team)->setId(self::B_TEAM)->setClubId(self::B_CLUB)->setSeasonId(self::B_SEASON)
            ->setSportCategoryId(self::B_CATEGORY)->setPriorityTierId(1)->setName(self::B_TEAM_NAME)
            ->setSessionsPerWeek(1)->setIsActive(true));
        $em->flush();

        $planId = $provisioner->ensureSeasonPlanId(self::B_SEASON);
        self::assertIsString($planId);
        self::assertInstanceOf(SchedulePlan::class, $em->getRepository(SchedulePlan::class)->find($planId));
        $socle = (new Schedule)->setId(self::B_SOCLE)->setClubId(self::B_CLUB)->setSeasonId(self::B_SEASON)
            ->setName('Version B')->setStatus(ScheduleStatus::COMPLETED)->setSchedulePlanId($planId);
        $em->persist($socle);
        $provisioner->linkSchedule($socle);
        $em->flush();
        $em->persist((new ScheduleSlotTemplate)->setId(self::erId('5107', 99))->setClubId(self::B_CLUB)->setSeasonId(self::B_SEASON)
            ->setScheduleId(self::B_SOCLE)->setTeamId(self::B_TEAM)->setVenueId(self::B_VENUE)->setDayOfWeek(2)
            ->setStartTime(new DateTimeImmutable('18:00'))->setDurationMinutes(90)->setLockLevel(LockLevel::HARD)
            ->setLockOrigin(LockOrigin::MANUAL));
        $em->flush();
        self::assertTrue($provisioner->choose($socle), 'le socle de B doit pouvoir être pointé');
        $seasonB = $em->getRepository(Season::class)->find(self::B_SEASON);
        self::assertInstanceOf(Season::class, $seasonB);
        $seasonB->setLiveContextScheduleId(self::B_SOCLE);

        $em->persist((new Competition)->setId(self::erId('c0e9', 9))->setClubId(self::B_CLUB)->setSeasonId(self::B_SEASON)
            ->setTeamId(self::B_TEAM)->setName('Championnat B')->setCompetitionType(CompetitionType::CHAMPIONSHIP));
        $em->persist((new VenueMatchWindow)->setId(self::erId('a10d', 9))->setClubId(self::B_CLUB)->setSeasonId(self::B_SEASON)
            ->setVenueId(self::B_VENUE)->setDayOfWeek(6)->setStartTime(new DateTimeImmutable('13:00'))->setEndTime(new DateTimeImmutable('21:00')));
        $em->flush();
        $fixture = (new Fixture)->setId(self::B_FIXTURE);
        $fixture->setClubId(self::B_CLUB);
        $fixture->setSeasonId(self::B_SEASON);
        $fixture->setTeamId(self::B_TEAM);
        $fixture->setCompetitionId(self::erId('c0e9', 9));
        $fixture->setMatchDate(new DateTimeImmutable('2026-01-17'));
        $fixture->setHomeAway(FixtureHomeAway::HOME);
        $fixture->setOpponentLabel('Adversaire B');
        $em->persist($fixture);
        $em->flush();

        $this->scopeGucToClub(self::ER_CLUB);
    }

    /**
     * La saison suivante du club du décor, avec un socle dont la version terminée est chargée : elle a
     * une demande « Régénérer ». Rend l'id de son plan de socle.
     */
    private function seedSecondSeasonWithLoadedSocle(): string
    {
        $em = $this->erEm();
        $this->scopeGucToClub(self::ER_CLUB);
        $season = (new Season)->setId(self::ER_SEASON_2)->setClubId(self::ER_CLUB)->setName('2026-2027')
            ->setStartDate(new DateTimeImmutable('2026-08-01'))->setEndDate(new DateTimeImmutable('2027-07-15'))
            ->setStatus(SeasonStatus::DRAFT)->setTransitionData([]);
        $em->persist($season);
        $em->flush();
        $provisioner = self::getContainer()->get(SchedulePlanProvisioner::class);
        $provisioner->ensureSeasonPlan($season);
        $em->flush();

        $planId = $provisioner->ensureSeasonPlanId(self::ER_SEASON_2);
        self::assertIsString($planId);
        $version = (new Schedule)->setId(self::ER_SEASON_2_V1)->setClubId(self::ER_CLUB)->setSeasonId(self::ER_SEASON_2)
            ->setName('Version 1')->setStatus(ScheduleStatus::COMPLETED)->setSchedulePlanId($planId);
        $em->persist($version);
        $provisioner->linkSchedule($version);
        $em->flush();
        $season->setLiveContextScheduleId(self::ER_SEASON_2_V1);
        $em->flush();

        return $planId;
    }

    private function scheduleCachePool(): CacheItemPoolInterface
    {
        $pool = self::getContainer()->get('cache.schedule');
        self::assertInstanceOf(CacheItemPoolInterface::class, $pool);

        return $pool;
    }

    // ---- outil ----------------------------------------------------------------------------------

    private function tester(): CommandTester
    {
        $application = new Application(self::$kernel ?? self::bootKernel());

        return new CommandTester($application->find('app:engine-requests:record'));
    }

    /**
     * La commande construite comme le conteneur la construit (services résolus par type), seul le
     * paramètre `$environment` imposé.
     */
    private function commandIn(string $environment): EngineRequestsRecordCommand
    {
        $constructor = new ReflectionClass(EngineRequestsRecordCommand::class)->getConstructor();
        self::assertNotNull($constructor);
        $arguments = [];
        foreach ($constructor->getParameters() as $parameter) {
            if ('environment' === $parameter->getName()) {
                $arguments[] = $environment;
                continue;
            }
            $type = $parameter->getType();
            self::assertInstanceOf(ReflectionNamedType::class, $type, 'paramètre ' . $parameter->getName() . ' : un service typé');
            $arguments[] = self::getContainer()->get($type->getName());
        }

        $command = new EngineRequestsRecordCommand(...$arguments);
        $command->setApplication(new Application(self::$kernel ?? self::bootKernel()));

        return $command;
    }

    /** Le recorder construit comme le conteneur le construit (services résolus par type), seul son cache imposé. */
    private function recorderWithCache(TagAwareCacheInterface $cache): EngineRequestRecorder
    {
        $constructor = new ReflectionClass(EngineRequestRecorder::class)->getConstructor();
        self::assertNotNull($constructor);
        $arguments = [];
        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();
            self::assertInstanceOf(ReflectionNamedType::class, $type, 'paramètre ' . $parameter->getName() . ' : un service typé');
            $arguments[] = TagAwareCacheInterface::class === $type->getName() ? $cache : self::getContainer()->get($type->getName());
        }

        return new EngineRequestRecorder(...$arguments);
    }

    /** Un cache dont la purge par tag échoue, comme quand Redis est tombé. */
    private function cacheThatCannotPurge(): TagAwareCacheInterface
    {
        $cache = $this->createStub(TagAwareCacheInterface::class);
        $cache->method('invalidateTags')->willThrowException(new RuntimeException('Redis tombé'));

        return $cache;
    }

    /**
     * @param list<string> $clubs
     */
    private function recordOk(array $clubs): string
    {
        $out = $this->newOutDir();
        $tester = $this->tester();
        $code = $tester->execute(['--out' => $out, '--club' => $clubs]);
        self::assertSame(0, $code, 'enregistrement attendu sans erreur : ' . $tester->getDisplay());

        return $out;
    }

    private function newOutDir(): string
    {
        $dir = sys_get_temp_dir() . '/engine-requests-test-' . bin2hex(random_bytes(6)) . '/out';
        $this->outDirs[] = \dirname($dir);

        return $dir;
    }

    /** @return array<string, string> chemin relatif au dossier de sortie → contenu */
    private function allFiles(string $out): array
    {
        if (!is_dir($out)) {
            return [];
        }
        $files = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($out, FilesystemIterator::SKIP_DOTS)) as $file) {
            $path = (string) $file;
            $files[substr($path, \strlen($out) + 1)] = (string) file_get_contents($path);
        }
        ksort($files);

        return $files;
    }

    /** @return array<string, string> clé de demande → contenu, pour un club */
    private function clubFiles(string $out, string $clubId): array
    {
        $prefix = 'requests/club-' . $clubId . '/';
        $files = [];
        foreach ($this->allFiles($out) as $path => $content) {
            if (str_starts_with($path, $prefix)) {
                $files[substr($path, \strlen($prefix))] = $content;
            }
        }

        return $files;
    }

    /** @return array{requests: list<string>, skipped: list<array{scope: string, reason: string}>} */
    private function manifestOf(string $out, string $clubId): array
    {
        self::assertFileExists($out . '/manifest.json');
        $manifest = json_decode((string) file_get_contents($out . '/manifest.json'), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($manifest);
        self::assertIsArray($manifest['clubs'] ?? null);
        foreach ($manifest['clubs'] as $club) {
            if (\is_array($club) && $clubId === ($club['clubId'] ?? null)) {
                $requests = $club['requests'] ?? null;
                $skipped = $club['skipped'] ?? null;
                self::assertIsArray($requests);
                self::assertIsArray($skipped);
                $keys = [];
                foreach ($requests as $key) {
                    self::assertIsString($key, 'manifeste : une demande est une clé');
                    $keys[] = $key;
                }
                $skips = [];
                foreach ($skipped as $skip) {
                    self::assertIsArray($skip);
                    self::assertIsString($skip['scope'] ?? null, 'manifeste : une demande sautée nomme sa portée');
                    self::assertIsString($skip['reason'] ?? null, 'manifeste : une demande sautée nomme sa raison');
                    $skips[] = ['scope' => $skip['scope'], 'reason' => $skip['reason']];
                }

                return ['requests' => $keys, 'skipped' => $skips];
            }
        }

        self::fail('le manifeste ne cite pas le club ' . $clubId);
    }

    /** @return array<mixed> */
    private function reference(string $name): array
    {
        $reference = json_decode((string) file_get_contents(self::REFERENCE_DIR . '/' . $name . '.json'), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($reference);

        return $reference;
    }

    /**
     * @param array<string, string> $before
     * @param array<string, string> $after
     *
     * @return array{added: list<string>, removed: list<string>, changed: array<string, array{minus: list<string>, plus: list<string>}>}
     */
    private function treeDiff(array $before, array $after): array
    {
        $changed = [];
        foreach (array_intersect_key($before, $after) as $key => $content) {
            if ($content === $after[$key]) {
                continue;
            }
            $old = explode("\n", $content);
            $new = explode("\n", $after[$key]);
            $changed[$key] = ['minus' => array_values(array_diff($old, $new)), 'plus' => array_values(array_diff($new, $old))];
        }

        return [
            'added' => array_keys(array_diff_key($after, $before)),
            'removed' => array_keys(array_diff_key($before, $after)),
            'changed' => $changed,
        ];
    }

    /**
     * @param array<string, list<string>> $forbidden
     * @param array<string, string>       $files
     */
    private function assertNothingOf(array $forbidden, array $files): void
    {
        self::assertNotSame([], $files);
        foreach ($forbidden as $label => $needles) {
            foreach ($files as $key => $content) {
                foreach ([$key, $content] as $haystack) {
                    foreach ($needles as $needle) {
                        self::assertStringNotContainsString($needle, $haystack, \sprintf('%s : %s apparaît dans %s', $label, $needle, $key));
                    }
                }
            }
        }
    }

    /** Empreinte des tables que l'outil ne doit jamais écrire, sous la RLS du club du décor. */
    private function traceDigest(): string
    {
        $this->scopeGucToClub(self::ER_CLUB);
        $connection = $this->erEm()->getConnection();
        $digest = [];
        foreach (self::TRACE_TABLES as $table) {
            $digest[] = $table . '=' . $connection->fetchOne(\sprintf('SELECT md5(coalesce(string_agg(t::text, \'|\' ORDER BY t::text), \'\')) FROM %s t', $table));
        }

        return implode("\n", $digest);
    }

    /** @param array<string, mixed> $value */
    private function primeSocleCache(array $value): void
    {
        $this->purgeEngineRequestCache();
        $pool = self::getContainer()->get('cache.schedule');
        self::assertInstanceOf(TagAwareCacheInterface::class, $pool);
        $pool->get(ScheduleConstraintBuilder::cacheKey(self::ER_CLUB, self::ER_SEASON, self::ER_SOCLE_V1), static function (ItemInterface $item) use ($value): array {
            $item->tag(ScheduleConstraintBuilder::cacheTag(self::ER_CLUB));
            $item->expiresAfter(600);

            return $value;
        });
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            // Un lien (vers un dossier ou nulle part) se retire comme un fichier, sans suivre sa cible.
            $file->isDir() && !$file->isLink() ? rmdir((string) $file) : unlink((string) $file);
        }
        rmdir($dir);
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Command\PruneUnreadSnapshotFieldsCommand;
use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\Coach;
use App\Entity\Constraint;
use App\Entity\Schedule;
use App\Entity\Season;
use App\Entity\Team;
use App\Entity\TeamCoach;
use App\Entity\Venue;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\ConstraintFamily;
use App\Enum\ConstraintRuleType;
use App\Enum\ConstraintScope;
use App\Enum\ScheduleStatus;
use App\Enum\SeasonStatus;
use App\Enum\TeamCoachRole;
use App\Service\ClubGenerationLock;
use App\Service\ScheduleConstraintBuilder;
use App\Service\SchedulePlanProvisioner;
use App\Tests\ChoosesPlanVersionTrait;
use App\Tests\ProvisionsPeriodPlanTrait;
use App\Tests\TenantGucTrait;
use Closure;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostLoadEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * NR axes *planning lifecycle*, *generation pipeline* et *tenant isolation* : le contrat moteur
 * allégé (les champs que le solveur ne lit pas quittent l'envoi) et le tri des contraintes dans
 * l'empreinte changent l'empreinte de structure de TOUS les plannings.
 * `app:schedule:prune-unread-snapshot-fields` réaligne une seule fois les versions terminées :
 * aucun faux « périmé », ce qui était vraiment périmé le reste, une version en cours n'est jamais
 * touchée, et chaque club n'est lu et écrit que sous son propre contexte.
 *
 * L'ancien snapshot est FABRIQUÉ : envoi du VRAI builder, puis les clés retirées remises depuis la
 * liste de CE fichier ({@see self::LEGACY_KEYS}, recopiée des sérialiseurs de la recette d'avant),
 * jamais depuis la constante de la commande — sinon une clé oubliée dans la constante le serait aussi
 * dans le décor, et le test resterait vert. Le verrou de génération est une sonde programmable (seul
 * moyen de faire démarrer une génération ENTRE deux versions) ; le cache est le vrai pool, nettoyé
 * par tag, jamais par un vidage global de Redis.
 *
 * Patron de garde de migration de données (charte tests-par-parcours §2) : ce test protège le run
 * réel tant que la commande n'a pas tourné en prod. Jumeau de structure :
 * `NormalizeTeamCoachSnapshotsCommandTest`.
 */
#[Group('phase1')]
#[Group('integration')]
final class PruneUnreadSnapshotFieldsCommandTest extends KernelTestCase
{
    use ChoosesPlanVersionTrait;
    use ProvisionsPeriodPlanTrait;
    use TenantGucTrait;

    /**
     * Les champs que l'envoi portait avant l'allègement et que le moteur ne lit pas, avec une valeur
     * plausible. Écrits ici à la main : jamais lus sur la commande.
     */
    private const array LEGACY_KEYS = [
        'venues' => ['isExternal' => false, 'color' => '#3b82f6', 'latitude' => '45.7640', 'longitude' => '4.8357', 'source' => 'manual', 'externalRef' => null, 'parentVenueId' => null],
        'teams' => ['sportCategoryId' => self::CATEGORY_ID, 'ageMax' => null, 'gender' => null, 'level' => null, 'parentTeamId' => null],
        'coaches' => ['parentCoachId' => null],
        'constraints' => ['sortOrder' => 0],
    ];

    private const string CATEGORY_ID = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';

    private const string GUC_SQL = 'SELECT COALESCE(current_setting(\'app.club_id\', true), \'\')';

    private EntityManagerInterface $em;

    /** Répond pour chaque lecture du verrou : (clubId, rang de la lecture pour ce club, à partir de 1). */
    private ?Closure $lockProbe = null;

    /** @var array<string, int> */
    private array $lockReads = [];

    /** @var list<array{club: string, guc: string, access: string}> lectures et écritures de versions, avec le contexte de club de la connexion */
    private array $scheduleAccesses = [];

    /** @var list<string> clubs dont l'entrée de cache est à effacer en fin de test */
    private array $primedClubs = [];

    /**
     * Témoin du décor, et raison d'être de la commande : sans elle, une version d'avant l'allègement
     * paraît périmée alors que rien n'a changé.
     */
    public function testWithoutTheCommandAnOldVersionLooksStale(): void
    {
        $f = $this->seedLegacyVersion();

        self::assertNotSame($this->currentHash($f), $this->reload($f)->getSnapshotHash());
    }

    /** Une version de saison d'avant l'allègement, structure inchangée, n'est plus périmée après la commande ; le résumé sort en `[OK]`. */
    public function testAnOldSeasonVersionIsNoLongerStale(): void
    {
        $f = $this->seedLegacyVersion();

        $tester = $this->runCommand();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertMatchesRegularExpression('/\[OK\] [1-9]\d* schedule\(s\) pruned\./', $this->display($tester));
        $version = $this->reload($f);
        self::assertSame(ScheduleConstraintBuilder::structureHash($version->getSnapshotData()), $version->getSnapshotHash(), 'empreinte recalculée depuis le snapshot');
        self::assertSame($this->currentHash($f), $version->getSnapshotHash(), 'structure inchangée : plus de bannière « vos données ont changé »');
    }

    /** Variante période : un plan de période suit la même règle que le socle. */
    public function testAnOldPeriodVersionIsNoLongerStale(): void
    {
        $f = $this->seedLegacyPeriodVersion();

        $this->runCommand()->assertCommandIsSuccessful();

        self::assertSame($this->currentHash($f), $this->reload($f)->getSnapshotHash());
    }

    /** Seules les clés retirées partent : le snapshot réaligné est exactement l'envoi d'aujourd'hui, noms, âge minimal, jour de match et saison compris. */
    public function testOnlyTheUnreadKeysLeaveTheSnapshot(): void
    {
        $f = $this->seedLegacyVersion();

        $this->runCommand()->assertCommandIsSuccessful();

        $snapshot = $this->reload($f)->getSnapshotData();
        self::assertSame($f['fresh'], $snapshot, 'rien d\'autre que les clés retirées ne bouge');
        self::assertSame($f['seasonId'], $snapshot['seasonId']);
        self::assertArrayHasKey('name', $snapshot['venues'][0]);
        foreach (['name', 'ageMin', 'matchDay'] as $key) {
            self::assertArrayHasKey($key, $snapshot['teams'][0], \sprintf('teams[0].%s reste', $key));
        }
        foreach (self::LEGACY_KEYS as $object => $keys) {
            foreach ($snapshot[$object] as $row) {
                foreach (array_keys($keys) as $key) {
                    self::assertArrayNotHasKey($key, $row, \sprintf('%s.%s retiré du snapshot', $object, $key));
                }
            }
        }
    }

    /** Une structure vraiment changée depuis la génération reste périmée : on ne recopie jamais l'empreinte courante. */
    public function testAVersionWhoseStructureReallyChangedStaysStale(): void
    {
        $f = $this->seedLegacyVersion();
        $this->scopeGucToClub($f['clubId']);
        $this->persistTeam($f['clubId'], $f['seasonId'], 'Nouvelle');
        $this->em->flush();

        $this->runCommand()->assertCommandIsSuccessful();

        $version = $this->reload($f);
        self::assertSame(ScheduleConstraintBuilder::structureHash($version->getSnapshotData()), $version->getSnapshotHash());
        self::assertArrayNotHasKey('sportCategoryId', $version->getSnapshotData()['teams'][0]);
        self::assertNotSame($this->currentHash($f), $version->getSnapshotHash(), 'une équipe ajoutée depuis : le planning reste périmé');
    }

    /**
     * L'ordre des contraintes lu en base n'entre plus dans l'empreinte : une version sans aucune clé
     * retirée, dont les contraintes sont rangées autrement, reçoit l'empreinte triée et n'est pas
     * périmée.
     */
    public function testAVersionWithConstraintsInAnotherOrderGetsTheSortedHash(): void
    {
        $f = $this->seedClub();
        $fresh = $this->freshPayload($f);
        self::assertGreaterThanOrEqual(2, \count($fresh['constraints']), 'témoin : au moins deux contraintes à ranger');
        $reordered = $fresh;
        $reordered['constraints'] = array_reverse($fresh['constraints']);
        self::assertNotSame($fresh['constraints'][0], $reordered['constraints'][0], 'témoin : l\'ordre diffère vraiment');
        $id = $this->version($f, $reordered, 'empreinte-ancienne');

        $this->runCommand()->assertCommandIsSuccessful();

        $version = $this->reloadId($f, $id);
        self::assertSame(ScheduleConstraintBuilder::structureHash($version->getSnapshotData()), $version->getSnapshotHash());
        self::assertSame($this->currentHash($f), $version->getSnapshotHash(), 'même structure, autre ordre : pas périmé');
    }

    /**
     * Une version de comblement porte des épingles greffées avant le hachage, que l'empreinte
     * courante ne regreffe pas : la commande les garde, et la version sort avec la même relation à
     * l'empreinte courante qu'avant (égale avant ⇔ égale après).
     */
    public function testAGapFillingVersionKeepsItsPinsAndItsRelationToTheCurrentHash(): void
    {
        $f = $this->seedClub();
        $payload = $this->freshPayload($f);
        $payload['slotTemplates'][] = [
            'id' => 'pin-comblement-1',
            'teamId' => $payload['teams'][0]['id'],
            'venueId' => $payload['venues'][0]['id'],
            'coachId' => null,
            'dayOfWeek' => 2,
            'startTime' => '19:00',
            'durationMinutes' => 90,
            'lockLevel' => 'HARD',
            'coachLateToleranceMinutes' => 0,
            'pendingConstraintSuggestion' => null,
        ];
        $wasEqual = ScheduleConstraintBuilder::structureHash($payload) === $this->currentHash($f);
        $legacy = $this->legacySnapshot($payload);
        $id = $this->version($f, $legacy, ScheduleConstraintBuilder::structureHash($legacy));

        $this->runCommand()->assertCommandIsSuccessful();

        $version = $this->reloadId($f, $id);
        self::assertSame($payload['slotTemplates'], $version->getSnapshotData()['slotTemplates'], 'les épingles du comblement restent');
        self::assertSame($wasEqual, $version->getSnapshotHash() === $this->currentHash($f), 'même relation à l\'empreinte courante qu\'avant');
    }

    /** `--dry-run` compte, sans écrire ni purger. */
    public function testDryRunNeitherWritesNorPurges(): void
    {
        $f = $this->seedLegacyVersion();
        $this->primeCache($f);
        $before = $this->reload($f);
        [$snapshot, $hash, $doctrineVersion] = [$before->getSnapshotData(), $before->getSnapshotHash(), $before->getVersion()];

        $tester = $this->runCommand(['--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertMatchesRegularExpression('/\b[1-9]\d* schedule\(s\) would be pruned \(dry-run\)\./', $this->display($tester));
        $after = $this->reload($f);
        self::assertSame($snapshot, $after->getSnapshotData());
        self::assertSame($hash, $after->getSnapshotHash());
        self::assertSame($doctrineVersion, $after->getVersion());
        self::assertTrue($this->cacheIsPrimed($f), 'en dry-run le cache reste');
    }

    /** Idempotente : un second passage ne compte rien, n'écrit rien, sort en `[OK]` avec `SUCCESS`. */
    public function testASecondRunTouchesNothing(): void
    {
        $f = $this->seedLegacyVersion();
        $this->runCommand()->assertCommandIsSuccessful();
        $doctrineVersion = $this->reload($f)->getVersion();
        $hash = $this->reload($f)->getSnapshotHash();

        $second = $this->runCommand();

        self::assertSame(Command::SUCCESS, $second->getStatusCode(), $second->getDisplay());
        self::assertStringContainsString('[OK] 0 schedule(s) pruned.', $this->display($second));
        self::assertSame($doctrineVersion, $this->reload($f)->getVersion());
        self::assertSame($hash, $this->reload($f)->getSnapshotHash());
    }

    /** Voie automatique (a) : un club en génération est sauté et signalé, les autres sont écrits, le résumé sort en `[WARNING]` avec `FAILURE`. */
    public function testAClubInGenerationIsSkippedAndSignalledWhileOthersAreWritten(): void
    {
        $busy = $this->seedLegacyVersion();
        $free = $this->seedLegacyVersion();
        $busyBefore = $this->reload($busy);
        [$busyHash, $busyDoctrineVersion] = [$busyBefore->getSnapshotHash(), $busyBefore->getVersion()];
        $this->lockProbe = static fn (string $clubId): bool => $clubId === $busy['clubId'];

        $tester = $this->runCommand();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString(\sprintf('Club %s skipped: a generation is running — rerun later.', $busy['clubId']), $this->display($tester));
        self::assertMatchesRegularExpression('/\[WARNING\] \d+ schedule\(s\) pruned\./', $this->display($tester));
        self::assertStringNotContainsString('[OK]', $this->display($tester));
        self::assertSame($busyHash, $this->reload($busy)->getSnapshotHash(), 'la version du club en génération n\'est pas réécrite');
        self::assertSame($busyDoctrineVersion, $this->reload($busy)->getVersion());
        self::assertTrue($this->isPruned($free, $free['scheduleId']), 'l\'autre club est traité');
    }

    /** Voie automatique (b) : la relance, verrou relâché, n'écrit que le club sauté et sort en `SUCCESS`. */
    public function testARerunOnceTheLockIsReleasedWritesOnlyTheSkippedClub(): void
    {
        $busy = $this->seedLegacyVersion();
        $free = $this->seedLegacyVersion();
        $this->lockProbe = static fn (string $clubId): bool => $clubId === $busy['clubId'];
        $this->runCommand();
        $freeDoctrineVersion = $this->reload($free)->getVersion();

        $this->lockProbe = null;
        $tester = $this->runCommand();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('[OK] 1 schedule(s) pruned.', $this->display($tester));
        self::assertTrue($this->isPruned($busy, $busy['scheduleId']), 'le club sauté est écrit');
        self::assertSame($freeDoctrineVersion, $this->reload($free)->getVersion(), 'le club déjà traité n\'est pas réécrit');
    }

    /** Voie automatique (c) : le verrou est relu avant CHAQUE version ; une génération qui démarre entre deux versions fait sauter la suivante, la relance termine. */
    public function testAGenerationStartingBetweenTwoVersionsSkipsTheNextOneAndARerunFinishes(): void
    {
        $f = $this->seedLegacyVersion();
        $other = $this->version($f, $this->reload($f)->getSnapshotData(), 'empreinte-ancienne');
        $ids = [$f['scheduleId'], $other];
        $this->lockProbe = static fn (string $clubId, int $read): bool => $clubId === $f['clubId'] && $read >= 2;

        $tester = $this->runCommand();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString(\sprintf('Club %s skipped: a generation is running — rerun later.', $f['clubId']), $this->display($tester));
        self::assertCount(1, $this->prunedAmong($f, $ids), 'une version écrite avant le démarrage, l\'autre sautée');

        $this->lockProbe = null;
        $this->runCommand()->assertCommandIsSuccessful();
        self::assertCount(2, $this->prunedAmong($f, $ids), 'rejouée, la commande termine');
    }

    /** Voie automatique (d) : une version en échec est nommée, `FAILURE` ; ce qui est écrit le reste, et un second passage finit le travail. */
    public function testAFailedVersionIsNamedAndARerunFinishesTheWork(): void
    {
        $f = $this->seedLegacyVersion();
        $other = $this->version($f, $this->reload($f)->getSnapshotData(), 'empreinte-ancienne');
        $ids = [$f['scheduleId'], $other];
        $this->lockProbe = static function (string $clubId, int $read) use ($f): bool {
            if ($clubId === $f['clubId'] && 2 === $read) {
                throw new RuntimeException('panne simulée');
            }

            return false;
        };

        $tester = $this->runCommand();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        $pruned = $this->prunedAmong($f, $ids);
        self::assertCount(1, $pruned, 'flush par version : la première version reste écrite');
        $failed = array_values(array_diff($ids, $pruned))[0];
        self::assertStringContainsString(\sprintf('Schedule %s (club %s) failed: panne simulée', $failed, $f['clubId']), $this->display($tester));

        $this->lockProbe = null;
        $this->runCommand()->assertCommandIsSuccessful();
        self::assertCount(2, $this->prunedAmong($f, $ids), 'rejouée, la commande termine');
    }

    /**
     * Voie automatique (e) : la purge du cache a lieu AVANT le premier passage ET à la fin. La sonde
     * du verrou, lue pendant le traitement, voit le cache déjà vide, puis y remet un payload (ce que
     * ferait un écran ouvert) que la purge finale doit emporter.
     */
    public function testTheCacheIsPurgedBeforeAndAfterTheVersions(): void
    {
        $f = $this->seedLegacyVersion();
        $this->primeCache($f);
        $emptyDuringRun = null;
        $this->lockProbe = function (string $clubId, int $read) use ($f, &$emptyDuringRun): bool {
            if ($clubId === $f['clubId'] && 1 === $read) {
                $emptyDuringRun = !$this->cacheIsPrimed($f);
                $this->primeCache($f);
            }

            return false;
        };

        $this->runCommand()->assertCommandIsSuccessful();

        self::assertTrue($emptyDuringRun, 'purge avant le traitement');
        self::assertFalse($this->cacheIsPrimed($f), 'purge après le traitement');
    }

    /**
     * Voie automatique (f) : `--max-seconds=0` s'arrête avant la première version, le dit, sort en
     * `[WARNING]` avec `FAILURE` ; la relance sans l'option va au bout.
     */
    public function testAnElapsedDeadlineStopsBeforeAnyVersionAndARerunWithoutItFinishes(): void
    {
        $f = $this->seedLegacyVersion();
        $before = $this->reload($f);
        [$hash, $doctrineVersion] = [$before->getSnapshotHash(), $before->getVersion()];

        $tester = $this->runCommand(['--max-seconds' => '0']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode(), $tester->getDisplay());
        self::assertMatchesRegularExpression('/Stopped after \d+ s: time limit reached — rerun to finish\./', $this->display($tester));
        self::assertMatchesRegularExpression('/\[WARNING\] 0 schedule\(s\) pruned\./', $this->display($tester));
        self::assertSame($hash, $this->reload($f)->getSnapshotHash(), 'aucune version écrite');
        self::assertSame($doctrineVersion, $this->reload($f)->getVersion());

        $rerun = $this->runCommand();

        self::assertSame(Command::SUCCESS, $rerun->getStatusCode(), $rerun->getDisplay());
        self::assertSame($this->currentHash($f), $this->reload($f)->getSnapshotHash(), 'relancée sans limite, la commande termine');
    }

    /**
     * Voie automatique (g) : une version en cours (`PENDING`, `GENERATING`) ou un brouillon, même SANS
     * verrou posé, n'est ni lue pour écriture ni comptée : snapshot, empreinte, `updatedAt` et numéro
     * de version Doctrine inchangés. Passées en échec, la relance les traite.
     */
    public function testAVersionInProgressIsNeverTouchedEvenWithoutALock(): void
    {
        $this->runCommand(); // pré-passage : les versions d'autres décors ne faussent pas le compte
        $f = $this->seedClub();
        $legacy = $this->legacySnapshot($this->freshPayload($f));
        $ids = [];
        foreach ([ScheduleStatus::PENDING, ScheduleStatus::GENERATING, ScheduleStatus::DRAFT] as $status) {
            $ids[$status->value] = $this->version($f, $legacy, ScheduleConstraintBuilder::structureHash($legacy), $status);
        }
        $before = [];
        foreach ($ids as $status => $id) {
            $version = $this->reloadId($f, $id);
            $before[$status] = [$version->getSnapshotData(), $version->getSnapshotHash(), $version->getUpdatedAt()->format('Y-m-d H:i:s.u'), $version->getVersion()];
        }

        $tester = $this->runCommand();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('[OK] 0 schedule(s) pruned.', $this->display($tester), 'une version en cours n\'est pas comptée');
        foreach ($ids as $status => $id) {
            $after = $this->reloadId($f, $id);
            self::assertSame($before[$status], [$after->getSnapshotData(), $after->getSnapshotHash(), $after->getUpdatedAt()->format('Y-m-d H:i:s.u'), $after->getVersion()], \sprintf('version %s intacte', $status));
        }

        foreach ($ids as $id) {
            $this->reloadId($f, $id)->setStatus(ScheduleStatus::FAILED);
            $this->em->flush();
        }
        $rerun = $this->runCommand();

        self::assertStringContainsString(\sprintf('[OK] %d schedule(s) pruned.', \count($ids)), $this->display($rerun), 'passées en échec, elles sont traitées');
        foreach ($ids as $id) {
            self::assertTrue($this->isPruned($f, $id));
        }
    }

    /**
     * Abus, isolation (T6) : la commande traite tous les clubs sans personne devant ; la version d'un
     * club n'est lue et écrite que sous le contexte de CE club (`app.club_id`, que la RLS lit).
     */
    public function testEachClubVersionIsReadAndWrittenOnlyUnderItsOwnContext(): void
    {
        $a = $this->seedLegacyVersion();
        $b = $this->seedLegacyVersion();
        $this->clearGuc();
        $this->recordTenantContextOfScheduleAccesses();

        $this->runCommand()->assertCommandIsSuccessful();

        foreach ([$a, $b] as $f) {
            $own = array_values(array_filter($this->scheduleAccesses, static fn (array $r): bool => $r['club'] === $f['clubId']));
            self::assertContains('write', array_column($own, 'access'), 'témoin : la version du club est bien écrite');
            foreach ($own as $record) {
                self::assertSame($f['clubId'], $record['guc'], \sprintf('%s d\'une version du club %s sous le contexte « %s »', $record['access'], $f['clubId'], $record['guc']));
            }
        }
    }

    /**
     * Abus, isolation (T6) : le contexte de club est relâché même quand une version échoue sur le
     * premier club traité, et même quand l'échéance arrête la commande — la connexion ne garde
     * jamais le contexte d'un club après la commande.
     */
    public function testTheClubContextIsReleasedAfterAFailureAndAfterTheDeadline(): void
    {
        $a = $this->seedLegacyVersion();
        $b = $this->seedLegacyVersion();
        $hashes = [$this->reload($a)->getSnapshotHash(), $this->reload($b)->getSnapshotHash()];
        $this->lockProbe = static function (): bool {
            throw new RuntimeException('panne simulée');
        };
        $this->clearGuc();

        $failed = $this->runCommand();

        self::assertSame(Command::FAILURE, $failed->getStatusCode());
        self::assertStringContainsString('failed: panne simulée', $this->display($failed));
        self::assertSame('', $this->currentGuc(), 'contexte relâché après l\'échec');
        self::assertSame($hashes, [$this->reload($a)->getSnapshotHash(), $this->reload($b)->getSnapshotHash()], 'une version en échec arrête la commande');

        $this->lockProbe = null;
        $this->clearGuc();
        $stopped = $this->runCommand(['--max-seconds' => '0']);

        self::assertSame(Command::FAILURE, $stopped->getStatusCode());
        self::assertSame('', $this->currentGuc(), 'contexte relâché après l\'échéance');
    }

    /**
     * T7 : un envoi mis en cache par le code d'avant (clé sans version de contrat, champs retirés
     * dedans) n'est jamais resservi — la clé du cache porte la version du contrat.
     */
    public function testAPayloadCachedByThePreviousCodeIsNeverServedAgain(): void
    {
        $f = $this->seedClub();
        $legacy = $this->legacySnapshot($this->freshPayload($f));
        $this->em->clear();
        $this->scopeGucToClub($f['clubId']);
        $previousKey = \sprintf('club.%s.season.%s.version.%s.schedule_input', $f['clubId'], $f['seasonId'], $this->loadedVersionOf($f) ?? 'none');
        $item = $this->cachePool()->getItem($previousKey);
        $item->set($legacy);
        $item->tag(ScheduleConstraintBuilder::cacheTag($f['clubId']));
        $this->cachePool()->save($item);
        $this->primedClubs[] = $f['clubId'];
        self::assertTrue($this->cachePool()->hasItem($previousKey), 'témoin : l\'envoi de l\'ancien code est en cache');

        $payload = self::getContainer()->get(ScheduleConstraintBuilder::class)->buildForClubSeason($f['clubId'], $f['seasonId']);

        foreach (self::LEGACY_KEYS as $object => $keys) {
            foreach ($payload[$object] as $row) {
                foreach (array_keys($keys) as $key) {
                    self::assertArrayNotHasKey($key, $row, \sprintf('%s.%s : l\'envoi de l\'ancien code a été resservi', $object, $key));
                }
            }
        }
    }

    protected function setUp(): void
    {
        self::bootKernel();
        // Avant tout service qui dépend du verrou : un service déjà instancié ne se remplace plus.
        self::getContainer()->set(ClubGenerationLock::class, new class($this->lockRead(...)) extends ClubGenerationLock {
            public function __construct(private readonly Closure $read)
            {
                parent::__construct('redis://unused');
            }

            public function isGenerating(string $clubId): bool
            {
                return ($this->read)($clubId);
            }
        });
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        foreach (array_unique($this->primedClubs) as $clubId) {
            $this->cachePool()->invalidateTags([ScheduleConstraintBuilder::cacheTag($clubId)]);
        }
        parent::tearDown();
    }

    private function lockRead(string $clubId): bool
    {
        $this->lockReads[$clubId] = ($this->lockReads[$clubId] ?? 0) + 1;

        return $this->lockProbe instanceof Closure ? (bool) ($this->lockProbe)($clubId, $this->lockReads[$clubId]) : false;
    }

    /** @param array<string, mixed> $options */
    private function runCommand(array $options = []): CommandTester
    {
        $this->lockReads = [];
        $tester = new CommandTester(self::getContainer()->get(PruneUnreadSnapshotFieldsCommand::class));
        $tester->execute($options);

        return $tester;
    }

    /** La sortie, retours à la ligne des blocs de `SymfonyStyle` aplatis. */
    private function display(CommandTester $tester): string
    {
        return (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
    }

    private function currentGuc(): string
    {
        return (string) $this->em->getConnection()->fetchOne(self::GUC_SQL);
    }

    /**
     * Note, pour chaque lecture et chaque écriture d'une version, le club de la version et le
     * contexte de club posé sur la connexion à cet instant.
     */
    private function recordTenantContextOfScheduleAccesses(): void
    {
        $record = function (object $entity, string $access): void {
            if ($entity instanceof Schedule) {
                $this->scheduleAccesses[] = ['club' => $entity->getClubId(), 'guc' => $this->currentGuc(), 'access' => $access];
            }
        };
        $this->em->getEventManager()->addEventListener([Events::postLoad, Events::preUpdate], new class($record) {
            public function __construct(private readonly Closure $record) {}

            public function postLoad(PostLoadEventArgs $args): void
            {
                ($this->record)($args->getObject(), 'read');
            }

            public function preUpdate(PreUpdateEventArgs $args): void
            {
                ($this->record)($args->getObject(), 'write');
            }
        });
    }

    /**
     * @param array{clubId: string, seasonId: string, planId: string} $f
     * @param list<string>                                            $ids
     *
     * @return list<string> les versions dont le snapshot a perdu les clés retirées
     */
    private function prunedAmong(array $f, array $ids): array
    {
        return array_values(array_filter($ids, fn (string $id): bool => $this->isPruned($f, $id)));
    }

    /** @param array{clubId: string, seasonId: string, planId: string} $f */
    private function isPruned(array $f, string $id): bool
    {
        $teams = $this->reloadId($f, $id)->getSnapshotData()['teams'] ?? [];
        self::assertNotEmpty($teams, 'témoin : la version porte des équipes');

        return !\array_key_exists('sportCategoryId', $teams[0]);
    }

    /**
     * L'empreinte de structure telle que l'écran la compare au `snapshotHash`.
     *
     * @param array{clubId: string, seasonId: string, planId: string} $f
     */
    private function currentHash(array $f): string
    {
        $this->em->clear();
        $this->scopeGucToClub($f['clubId']);
        $this->cachePool()->invalidateTags([ScheduleConstraintBuilder::cacheTag($f['clubId'])]);
        $this->primedClubs[] = $f['clubId'];
        $hash = self::getContainer()->get(SchedulePlanProvisioner::class)->structureHashOfPlan($f['planId']);
        self::assertIsString($hash, 'le plan doit avoir une empreinte');

        return $hash;
    }

    /**
     * L'envoi d'aujourd'hui pour la saison du décor.
     *
     * @param array{clubId: string, seasonId: string, planId: string} $f
     *
     * @return array<string, mixed>
     */
    private function freshPayload(array $f): array
    {
        $this->em->clear();
        $this->scopeGucToClub($f['clubId']);
        $this->cachePool()->invalidateTags([ScheduleConstraintBuilder::cacheTag($f['clubId'])]);
        $this->primedClubs[] = $f['clubId'];
        $payload = self::getContainer()->get(ScheduleConstraintBuilder::class)->buildForClubSeason($f['clubId'], $f['seasonId']);
        $this->cachePool()->invalidateTags([ScheduleConstraintBuilder::cacheTag($f['clubId'])]);

        return $payload;
    }

    /** @param array{clubId: string, seasonId: string, planId: string, scheduleId: string} $f */
    private function reload(array $f): Schedule
    {
        return $this->reloadId($f, $f['scheduleId']);
    }

    /** @param array{clubId: string, seasonId: string, planId: string} $f */
    private function reloadId(array $f, string $id): Schedule
    {
        $this->em->clear();
        $this->scopeGucToClub($f['clubId']);
        $schedule = $this->em->getRepository(Schedule::class)->find($id);
        self::assertInstanceOf(Schedule::class, $schedule);

        return $schedule;
    }

    private function cachePool(): TagAwareAdapterInterface
    {
        $pool = self::getContainer()->get('cache.schedule');
        self::assertInstanceOf(TagAwareAdapterInterface::class, $pool);

        return $pool;
    }

    /**
     * Met en cache, à la clé du code courant, un envoi de l'ANCIEN code, tagué comme le fait le builder.
     *
     * @param array{clubId: string, seasonId: string, planId: string} $f
     */
    private function primeCache(array $f): void
    {
        $item = $this->cachePool()->getItem(ScheduleConstraintBuilder::cacheKey($f['clubId'], $f['seasonId'], $this->loadedVersionOf($f)));
        $item->set(['teams' => [self::LEGACY_KEYS['teams']]]);
        $item->tag(ScheduleConstraintBuilder::cacheTag($f['clubId']));
        $this->cachePool()->save($item);
        $this->primedClubs[] = $f['clubId'];
    }

    /**
     * La clé du payload porte la version chargée : celle que le builder résout lui-même.
     *
     * @param array{clubId: string, seasonId: string, planId: string} $f
     */
    private function loadedVersionOf(array $f): ?string
    {
        return self::getContainer()->get(SchedulePlanProvisioner::class)->lockSourceScheduleIdOfPlan($f['planId']);
    }

    /** @param array{clubId: string, seasonId: string, planId: string} $f */
    private function cacheIsPrimed(array $f): bool
    {
        return $this->cachePool()->hasItem(ScheduleConstraintBuilder::cacheKey($f['clubId'], $f['seasonId'], $this->loadedVersionOf($f)));
    }

    /** @return array{clubId: string, seasonId: string, planId: string, scheduleId: string, fresh: array<string, mixed>} */
    private function seedLegacyVersion(): array
    {
        $f = $this->seedClub();
        $fresh = $this->freshPayload($f);
        $snapshot = $this->legacySnapshot($fresh);

        return [...$f, 'scheduleId' => $this->version($f, $snapshot, ScheduleConstraintBuilder::structureHash($snapshot)), 'fresh' => $fresh];
    }

    /** @return array{clubId: string, seasonId: string, planId: string, scheduleId: string} */
    private function seedLegacyPeriodVersion(): array
    {
        $f = $this->seedClub();
        $entry = (new CalendarEntry)->setClubId($f['clubId'])->setSeasonId($f['seasonId'])
            ->setKind(CalendarEntryKind::PERIOD)->setPeriodType(CalendarEntryPeriodType::CLOSURE)->setTitle('Fermeture')
            ->setStartDate(new DateTimeImmutable('2025-12-20'))->setEndDate(new DateTimeImmutable('2026-01-04'));
        $this->em->persist($entry);
        $planId = $this->planIdOf($entry);
        $payload = self::getContainer()->get(ScheduleConstraintBuilder::class)->buildForPeriodPlan($f['clubId'], $f['seasonId'], $planId, $entry);
        $snapshot = $this->legacySnapshot($payload);
        $schedule = (new Schedule)->setClubId($f['clubId'])->setSeasonId($f['seasonId'])
            ->setName('Période')->setStatus(ScheduleStatus::COMPLETED)
            ->setSnapshotData($snapshot)->setSnapshotHash(ScheduleConstraintBuilder::structureHash($snapshot));
        $this->linkSeededSchedule($schedule, $entry->getId());
        $this->em->flush();

        return ['clubId' => $f['clubId'], 'seasonId' => $f['seasonId'], 'planId' => $planId, 'scheduleId' => $schedule->getId()];
    }

    /**
     * Le snapshot qu'écrivait le code d'avant l'allègement : chaque objet reprend les clés retirées
     * de {@see self::LEGACY_KEYS} ; une contrainte au format v2 (`scope`) reprend son `sortOrder`.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function legacySnapshot(array $payload): array
    {
        foreach (self::LEGACY_KEYS as $object => $keys) {
            self::assertNotEmpty($payload[$object] ?? [], \sprintf('témoin : l\'envoi porte au moins un objet %s', $object));
            foreach ($payload[$object] as $index => $row) {
                if ('constraints' === $object && !\array_key_exists('scope', $row)) {
                    continue;
                }
                foreach ($keys as $key => $value) {
                    if (!\array_key_exists($key, $row)) {
                        $payload[$object][$index][$key] = $value;
                    }
                }
            }
        }

        return $payload;
    }

    /** @return array{clubId: string, seasonId: string, planId: string} */
    private function seedClub(): array
    {
        $uid = uniqid('', true);
        $club = (new Club)->setName('Prune Club')->setSlug('prune-' . $uid)
            ->setTimezone('Europe/Paris')->setLocale('fr')->setOnboardingCompleted(true)
            ->setFfbbClubCode('PRU' . strtoupper(substr(md5($uid), 0, 8)));
        $this->em->persist($club);
        $this->em->flush();
        $this->scopeGucToClub($club->getId());

        $season = (new Season)->setClubId($club->getId())->setName('2025-2026')
            ->setStartDate(new DateTimeImmutable('2025-09-01'))->setEndDate(new DateTimeImmutable('2026-06-30'))
            ->setStatus(SeasonStatus::ACTIVE);
        $this->em->persist($season);
        $this->em->flush();

        $this->em->persist((new Venue)->setClubId($club->getId())->setSeasonId($season->getId())->setName('Gymnase Nord')
            ->setCanSplit(false)->setSource('manual'));
        $team = $this->persistTeam($club->getId(), $season->getId(), 'Seniors A');
        $coach = (new Coach)->setClubId($club->getId())->setSeasonId($season->getId())->setFirstName('Karim')->setLastName('Benali');
        $this->em->persist($coach);
        $this->em->flush();
        $this->em->persist((new TeamCoach)->setClubId($club->getId())->setSeasonId($season->getId())
            ->setTeamId($team->getId())->setCoachId($coach->getId())->setRole(TeamCoachRole::MAIN));
        foreach (['Pas le dimanche' => [7], 'Pas le samedi' => [6]] as $name => $days) {
            $this->em->persist((new Constraint)->setClubId($club->getId())->setSeasonId($season->getId())->setName($name)
                ->setScope(ConstraintScope::TEAM)->setScopeTargetId($team->getId())
                ->setFamily(ConstraintFamily::DAY)->setRuleType(ConstraintRuleType::PREFERRED)->setConfig(['forbiddenDays' => $days]));
        }
        $this->em->flush();

        return ['clubId' => $club->getId(), 'seasonId' => $season->getId(), 'planId' => $this->seasonPlanIdOf($season)];
    }

    private function persistTeam(string $clubId, string $seasonId, string $name): Team
    {
        $team = (new Team)->setClubId($clubId)->setSeasonId($seasonId)->setName($name)
            ->setSportCategoryId(self::CATEGORY_ID)->setPriorityTierId(1);
        $this->em->persist($team);

        return $team;
    }

    /**
     * @param array{clubId: string, seasonId: string, planId: string} $f
     * @param array<string, mixed>                                    $snapshot
     */
    private function version(array $f, array $snapshot, ?string $hash, ScheduleStatus $status = ScheduleStatus::COMPLETED): string
    {
        $this->em->clear();
        $this->scopeGucToClub($f['clubId']);
        $schedule = (new Schedule)->setClubId($f['clubId'])->setSeasonId($f['seasonId'])
            ->setName('Version')->setStatus($status)
            ->setSnapshotData($snapshot)->setSnapshotHash($hash);
        $this->linkSeededSchedule($schedule);
        $this->em->flush();

        return $schedule->getId();
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Command\NormalizeTeamCoachSnapshotsCommand;
use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\Coach;
use App\Entity\Schedule;
use App\Entity\Season;
use App\Entity\Team;
use App\Entity\TeamCoach;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * NR axe *planning lifecycle* : retirer « coach obligatoire » du payload change l'empreinte de
 * structure de TOUS les plannings. `app:schedule:normalize-team-coach-snapshots` réaligne une seule
 * fois les versions existantes : aucun faux « périmé », et ce qui était vraiment périmé le reste.
 *
 * L'ancien snapshot est simulé (le builder ne produit plus ces clés une fois le retrait fait) :
 * snapshot du VRAI builder, puis `severity` et `metadata.isRequired` remis à leur place d'origine.
 * Le verrou de génération est remplacé par une sonde programmable, seul moyen de faire démarrer une
 * génération ENTRE deux versions. Le cache, lui, est le vrai pool : nettoyage par clé, jamais de
 * vidage global de Redis.
 *
 * Patron de garde de migration de données (charte tests-par-parcours §2) : ce test protège le run
 * réel tant que la commande n'a pas tourné en prod, et part à la passe de réduction ensuite.
 */
#[Group('phase1')]
#[Group('integration')]
final class NormalizeTeamCoachSnapshotsCommandTest extends KernelTestCase
{
    use ChoosesPlanVersionTrait;
    use ProvisionsPeriodPlanTrait;
    use TenantGucTrait;

    private EntityManagerInterface $em;

    /** Répond pour chaque lecture du verrou : (clubId, rang de la lecture pour ce club, à partir de 1). */
    private ?Closure $lockProbe = null;

    /** @var array<string, int> */
    private array $lockReads = [];

    /** @var list<array{string, string}> clubId, saisonId dont l'entrée de cache est à effacer en fin de test */
    private array $primedCaches = [];

    /** @return iterable<string, array{array<string, mixed>, ?string}> */
    public static function versionsWithoutLegacyKeys(): iterable
    {
        yield 'snapshot vide, empreinte nulle (version en attente)' => [[], null];
        yield 'snapshot sans contraintes' => [['teams' => [], 'venues' => []], 'empreinte-ancienne'];
        yield 'contraintes sans lien coach' => [['constraints' => [['id' => 'c-1', 'scope' => 'TEAM', 'family' => 'DAY']]], 'empreinte-ancienne'];
        yield 'lien coach déjà sans les deux clés' => [['constraints' => [[
            'id' => 'team-coach:1',
            'teamId' => 't',
            'type' => 'TEAM_COACH',
            'value' => 'c',
            'metadata' => ['coachId' => 'c', 'role' => 'MAIN'],
        ]]], 'empreinte-ancienne'];
    }

    /**
     * Témoin du décor, et raison d'être de la commande : sans elle, une version d'avant le retrait
     * paraît périmée alors que rien n'a changé.
     */
    public function testWithoutTheCommandAnOldVersionLooksStale(): void
    {
        $f = $this->seedLegacyVersion();

        self::assertNotSame($this->currentHash($f), $this->reload($f)->getSnapshotHash());
    }

    /** Une version d'avant le retrait, structure inchangée, n'est plus périmée après la commande. */
    public function testAnOldVersionIsNoLongerStale(): void
    {
        $f = $this->seedLegacyVersion();

        $tester = $this->runCommand();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertMatchesRegularExpression('/\b\d+ schedule\(s\) normalized\./', $this->display($tester));
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

    /** Seul le lien coach perd ses deux clés, l'ordre des autres reste : `severity` d'un lien coach-joueur est un signal vivant du moteur. */
    public function testOnlyTheTeamCoachLinkLosesItsKeys(): void
    {
        $f = $this->seedClubWithLink();
        $player = ['id' => 'cp-1', 'teamId' => 't', 'type' => 'COACH_PLAYER_UNAVAILABILITY', 'severity' => 'SOFT', 'metadata' => ['isActive' => false]];
        $rule = ['id' => 'c-1', 'scope' => 'TEAM', 'severity' => 'HARD', 'metadata' => ['isRequired' => true]];
        $severityOnly = ['id' => 'tc-1', 'type' => 'TEAM_COACH', 'severity' => 'HARD', 'value' => 'a', 'metadata' => ['coachId' => 'a', 'role' => 'MAIN']];
        $flagOnly = ['id' => 'tc-2', 'type' => 'TEAM_COACH', 'value' => 'b', 'metadata' => ['coachId' => 'b', 'isRequired' => false, 'role' => 'ASSISTANT']];
        $snapshot = ['constraints' => [$player, $rule, $severityOnly, $flagOnly], 'teams' => []];
        $id = $this->version($f, $snapshot, 'empreinte-ancienne');

        $this->runCommand()->assertCommandIsSuccessful();

        $constraints = $this->reloadId($f, $id)->getSnapshotData()['constraints'];
        self::assertSame($player, $constraints[0], 'le lien coach-joueur garde sa severity');
        self::assertSame($rule, $constraints[1], 'une autre contrainte n\'est pas touchée');
        self::assertSame(['id' => 'tc-1', 'type' => 'TEAM_COACH', 'value' => 'a', 'metadata' => ['coachId' => 'a', 'role' => 'MAIN']], $constraints[2]);
        self::assertSame(['id' => 'tc-2', 'type' => 'TEAM_COACH', 'value' => 'b', 'metadata' => ['coachId' => 'b', 'role' => 'ASSISTANT']], $constraints[3]);
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
        self::assertArrayNotHasKey('severity', $this->teamCoachLink($version));
        self::assertNotSame($this->currentHash($f), $version->getSnapshotHash(), 'une équipe ajoutée depuis : le planning reste périmé');
    }

    /** La commande purge le cache d'elle-même : un payload de l'ancien code en cache ne crée pas de faux périmé. */
    public function testALegacyPayloadInCacheIsPurgedWithoutAnyOtherGesture(): void
    {
        $f = $this->seedLegacyVersion();
        $this->primeCache($f);

        $this->runCommand()->assertCommandIsSuccessful();

        self::assertFalse($this->cacheIsPrimed($f), 'le cache de l\'ancien code est parti');
        self::assertSame($this->currentHash($f, purge: false), $this->reload($f)->getSnapshotHash());
    }

    /** Un club sans aucune version a aussi un payload en cache : il est purgé. */
    public function testAClubWithoutAnyVersionHasItsCachePurged(): void
    {
        $f = $this->seedClubWithLink();
        $this->primeCache($f);

        $this->runCommand()->assertCommandIsSuccessful();

        self::assertFalse($this->cacheIsPrimed($f));
    }

    /**
     * La purge a lieu AVANT le premier passage ET à la fin : la sonde du verrou, lue pendant le
     * traitement, voit le cache déjà vide, puis y remet un payload (ce que ferait un écran ouvert)
     * que la purge finale doit emporter.
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

    /** `--dry-run` compte, sans écrire ni purger. */
    public function testDryRunNeitherWritesNorPurges(): void
    {
        $f = $this->seedLegacyVersion();
        $this->primeCache($f);
        $before = $this->reload($f);
        [$snapshot, $hash, $doctrineVersion] = [$before->getSnapshotData(), $before->getSnapshotHash(), $before->getVersion()];

        $tester = $this->runCommand(['--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertMatchesRegularExpression('/\b[1-9]\d* schedule\(s\) would be normalized \(dry-run\)\./', $this->display($tester));
        $after = $this->reload($f);
        self::assertSame($snapshot, $after->getSnapshotData());
        self::assertSame($hash, $after->getSnapshotHash());
        self::assertSame($doctrineVersion, $after->getVersion());
        self::assertTrue($this->cacheIsPrimed($f), 'en dry-run le cache reste');
    }

    /** Idempotente : un second passage ne compte rien et n'écrit rien. */
    public function testASecondRunTouchesNothing(): void
    {
        $f = $this->seedLegacyVersion();
        $this->runCommand()->assertCommandIsSuccessful();
        $doctrineVersion = $this->reload($f)->getVersion();
        $hash = $this->reload($f)->getSnapshotHash();

        $second = $this->runCommand();

        $second->assertCommandIsSuccessful();
        self::assertStringContainsString('0 schedule(s) normalized.', $this->display($second));
        self::assertSame($doctrineVersion, $this->reload($f)->getVersion());
        self::assertSame($hash, $this->reload($f)->getSnapshotHash());
    }

    /**
     * On n'écrit que si une clé a été retirée : ces versions gardent leur empreinte, même ancienne,
     * et ne passent pas par une écriture (compteur de version Doctrine inchangé).
     *
     * @param array<string, mixed> $snapshot
     */
    #[DataProvider('versionsWithoutLegacyKeys')]
    public function testAVersionWithoutLegacyKeysIsLeftUntouched(array $snapshot, ?string $hash): void
    {
        $f = $this->seedClubWithLink();
        $id = $this->version($f, $snapshot, $hash);
        $before = $this->reloadId($f, $id);
        [$data, $doctrineVersion] = [$before->getSnapshotData(), $before->getVersion()];

        $this->runCommand()->assertCommandIsSuccessful();

        $after = $this->reloadId($f, $id);
        self::assertSame($hash, $after->getSnapshotHash());
        self::assertSame($data, $after->getSnapshotData());
        self::assertSame($doctrineVersion, $after->getVersion());
    }

    /** Un club en génération est sauté et signalé, code retour d'échec ; un autre club est quand même traité. */
    public function testAClubInGenerationIsSkippedAndSignalledWhileOthersAreWritten(): void
    {
        $busy = $this->seedLegacyVersion();
        $free = $this->seedLegacyVersion();
        $busyHash = $this->reload($busy)->getSnapshotHash();
        $this->lockProbe = static fn (string $clubId): bool => $clubId === $busy['clubId'];

        $tester = $this->runCommand();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString(\sprintf('Club %s skipped: a generation is running — rerun later.', $busy['clubId']), $this->display($tester));
        self::assertSame($busyHash, $this->reload($busy)->getSnapshotHash(), 'la version du club en génération n\'est pas réécrite');
        self::assertArrayHasKey('severity', $this->teamCoachLink($this->reload($busy)));
        self::assertArrayNotHasKey('severity', $this->teamCoachLink($this->reload($free)), 'l\'autre club est traité');
    }

    /** Le verrou est relu avant CHAQUE version : une génération qui démarre entre deux versions fait sauter la suivante. */
    public function testAGenerationStartingBetweenTwoVersionsSkipsTheNextOne(): void
    {
        $f = $this->seedLegacyVersion();
        $other = $this->version($f, $this->reload($f)->getSnapshotData(), 'empreinte-ancienne');
        $this->lockProbe = static fn (string $clubId, int $read): bool => $clubId === $f['clubId'] && $read >= 2;

        $tester = $this->runCommand();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString(\sprintf('Club %s skipped: a generation is running — rerun later.', $f['clubId']), $this->display($tester));
        self::assertCount(1, $this->normalizedAmong($f, [$f['scheduleId'], $other]), 'une version écrite avant le démarrage, l\'autre sautée');
    }

    /** Une version en échec est nommée, code retour d'échec ; ce qui est déjà écrit le reste, et un second passage finit le travail. */
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
        $normalized = $this->normalizedAmong($f, $ids);
        self::assertCount(1, $normalized, 'flush par version : la première version reste écrite');
        $failed = array_values(array_diff($ids, $normalized))[0];
        self::assertStringContainsString(\sprintf('Schedule %s (club %s) failed: panne simulée', $failed, $f['clubId']), $this->display($tester));

        $this->lockProbe = null;
        $this->runCommand()->assertCommandIsSuccessful();
        self::assertCount(2, $this->normalizedAmong($f, $ids), 'rejouée, la commande termine');
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
        foreach ($this->primedCaches as [$clubId, $seasonId]) {
            $this->cachePool()->deleteItem(ScheduleConstraintBuilder::cacheKey($clubId, $seasonId));
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
        $tester = new CommandTester(self::getContainer()->get(NormalizeTeamCoachSnapshotsCommand::class));
        $tester->execute($options);

        return $tester;
    }

    /** La sortie, retours à la ligne des blocs de `SymfonyStyle` aplatis. */
    private function display(CommandTester $tester): string
    {
        return (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
    }

    /**
     * @param array{clubId: string, seasonId: string, planId: string} $f
     * @param list<string>                                            $ids
     *
     * @return list<string> les versions dont le lien coach a perdu ses clés
     */
    private function normalizedAmong(array $f, array $ids): array
    {
        return array_values(array_filter($ids, fn (string $id): bool => !\array_key_exists('severity', $this->teamCoachLink($this->reloadId($f, $id)))));
    }

    /** @return array<string, mixed> */
    private function teamCoachLink(Schedule $version): array
    {
        foreach ($version->getSnapshotData()['constraints'] ?? [] as $constraint) {
            if ('TEAM_COACH' === ($constraint['type'] ?? null)) {
                return $constraint;
            }
        }
        self::fail('la version ne porte aucun lien coach');
    }

    /**
     * L'empreinte de structure telle que l'écran la compare au `snapshotHash`.
     *
     * @param array{clubId: string, seasonId: string, planId: string} $f
     */
    private function currentHash(array $f, bool $purge = true): string
    {
        $this->em->clear();
        $this->scopeGucToClub($f['clubId']);
        if ($purge) {
            $this->cachePool()->deleteItem(ScheduleConstraintBuilder::cacheKey($f['clubId'], $f['seasonId']));
        }
        $this->primedCaches[] = [$f['clubId'], $f['seasonId']];
        $hash = self::getContainer()->get(SchedulePlanProvisioner::class)->structureHashOfPlan($f['planId']);
        self::assertIsString($hash, 'le plan doit avoir une empreinte');

        return $hash;
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
     * Met en cache un payload de l'ANCIEN code, tagué comme le fait le builder.
     *
     * @param array{clubId: string, seasonId: string} $f
     */
    private function primeCache(array $f): void
    {
        $item = $this->cachePool()->getItem(ScheduleConstraintBuilder::cacheKey($f['clubId'], $f['seasonId']));
        $item->set(['constraints' => [$this->legacyLink(['id' => 'x', 'teamId' => 't', 'value' => 'c', 'metadata' => ['coachId' => 'c', 'role' => 'MAIN']])]]);
        $item->tag(ScheduleConstraintBuilder::cacheTag($f['clubId']));
        $this->cachePool()->save($item);
        $this->primedCaches[] = [$f['clubId'], $f['seasonId']];
    }

    /** @param array{clubId: string, seasonId: string} $f */
    private function cacheIsPrimed(array $f): bool
    {
        return $this->cachePool()->hasItem(ScheduleConstraintBuilder::cacheKey($f['clubId'], $f['seasonId']));
    }

    /** @return array{clubId: string, seasonId: string, planId: string, scheduleId: string} */
    private function seedLegacyVersion(): array
    {
        $f = $this->seedClubWithLink();
        $this->cachePool()->deleteItem(ScheduleConstraintBuilder::cacheKey($f['clubId'], $f['seasonId']));
        $payload = self::getContainer()->get(ScheduleConstraintBuilder::class)->buildForClubSeason($f['clubId'], $f['seasonId']);
        $this->primedCaches[] = [$f['clubId'], $f['seasonId']];
        $snapshot = $this->legacySnapshot($payload);

        return [...$f, 'scheduleId' => $this->version($f, $snapshot, ScheduleConstraintBuilder::structureHash($snapshot))];
    }

    /** @return array{clubId: string, seasonId: string, planId: string, scheduleId: string} */
    private function seedLegacyPeriodVersion(): array
    {
        $f = $this->seedClubWithLink();
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
     * Le snapshot que l'ancien code aurait écrit : chaque lien coach reprend ses deux clés, à leur place.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function legacySnapshot(array $payload): array
    {
        $found = false;
        foreach ($payload['constraints'] as $index => $constraint) {
            if ('TEAM_COACH' === ($constraint['type'] ?? null)) {
                $payload['constraints'][$index] = $this->legacyLink($constraint);
                $found = true;
            }
        }
        self::assertTrue($found, 'témoin : le payload porte bien un lien coach');

        return $payload;
    }

    /**
     * @param array<string, mixed> $link
     *
     * @return array<string, mixed>
     */
    private function legacyLink(array $link): array
    {
        return [
            'id' => $link['id'],
            'teamId' => $link['teamId'],
            'type' => 'TEAM_COACH',
            'severity' => 'HARD',
            'value' => $link['value'],
            'metadata' => ['coachId' => $link['metadata']['coachId'], 'role' => $link['metadata']['role'], 'isRequired' => true],
        ];
    }

    /** @return array{clubId: string, seasonId: string, planId: string} */
    private function seedClubWithLink(): array
    {
        $uid = uniqid('', true);
        $club = (new Club)->setName('Normalize Club')->setSlug('normalize-' . $uid)
            ->setTimezone('Europe/Paris')->setLocale('fr')->setOnboardingCompleted(true)
            ->setFfbbClubCode('NTC' . strtoupper(substr(md5($uid), 0, 8)));
        $this->em->persist($club);
        $this->em->flush();
        $this->scopeGucToClub($club->getId());

        $season = (new Season)->setClubId($club->getId())->setName('2025-2026')
            ->setStartDate(new DateTimeImmutable('2025-09-01'))->setEndDate(new DateTimeImmutable('2026-06-30'))
            ->setStatus(SeasonStatus::ACTIVE);
        $this->em->persist($season);
        $this->em->flush();

        $team = $this->persistTeam($club->getId(), $season->getId(), 'Seniors A');
        $coach = (new Coach)->setClubId($club->getId())->setSeasonId($season->getId())->setFirstName('Karim')->setLastName('Benali');
        $this->em->persist($coach);
        $this->em->flush();
        $this->em->persist((new TeamCoach)->setClubId($club->getId())->setSeasonId($season->getId())
            ->setTeamId($team->getId())->setCoachId($coach->getId())->setRole(TeamCoachRole::MAIN));
        $this->em->flush();

        return ['clubId' => $club->getId(), 'seasonId' => $season->getId(), 'planId' => $this->seasonPlanIdOf($season)];
    }

    private function persistTeam(string $clubId, string $seasonId, string $name): Team
    {
        $team = (new Team)->setClubId($clubId)->setSeasonId($seasonId)->setName($name)
            ->setSportCategoryId('cccccccc-cccc-4ccc-8ccc-cccccccccccc')->setPriorityTierId(1);
        $this->em->persist($team);

        return $team;
    }

    /**
     * @param array{clubId: string, seasonId: string, planId: string} $f
     * @param array<string, mixed>                                    $snapshot
     */
    private function version(array $f, array $snapshot, ?string $hash): string
    {
        $this->em->clear();
        $this->scopeGucToClub($f['clubId']);
        $schedule = (new Schedule)->setClubId($f['clubId'])->setSeasonId($f['seasonId'])
            ->setName('Version')->setStatus(ScheduleStatus::COMPLETED)
            ->setSnapshotData($snapshot)->setSnapshotHash($hash);
        $this->linkSeededSchedule($schedule);
        $this->em->flush();

        return $schedule->getId();
    }
}

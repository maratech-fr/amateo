<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\MatchPlacementRun;
use App\Entity\Schedule;
use App\Entity\ScheduleSlotTemplate;
use App\Enum\ScheduleStatus;
use App\Message\GenerateScheduleMessage;
use App\Message\PlaceMatchesMessage;
use App\MessageHandler\GenerateScheduleHandler;
use App\MessageHandler\PlaceMatchesHandler;
use App\Service\ClubGenerationLock;
use App\Service\DiagnosticMessageBuilder;
use App\Service\EngineClient;
use App\Service\MatchPlacementLock;
use App\Service\MatchPlacementPayloadBuilder;
use App\Service\MatchPlacementProgressPublisher;
use App\Service\MatchPlacementResultApplier;
use App\Service\MoveSlotService;
use App\Service\OutputCreditLedger;
use App\Service\PeriodConstraintSelector;
use App\Service\PlacementRunEmailBuilder;
use App\Service\PlacementRunFailureNotifier;
use App\Service\RequestIdContext;
use App\Service\ScheduleConstraintBuilder;
use App\Service\ScheduleDiagnosticsRecorder;
use App\Service\SchedulePlanProvisioner;
use App\Service\ScheduleProgressPublisher;
use App\Service\ScheduleResultImporter;
use App\Service\SolverMetricsMapper;
use App\Service\StructureSnapshotter;
use App\Service\TeamCaseOccupancyGuard;
use App\Service\TenantConnectionContext;
use DateTimeImmutable;
use PHPUnit\Framework\Assert;
use Psr\Clock\ClockInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mercure\HubInterface;

/**
 * Les trois rails qui composent une demande au moteur, joués comme en production sur un moteur
 * simulé qui capture le corps reçu : la génération (handler réel), le placement des matchs (handler
 * réel) et le verdict d'un déplacement (`MoveSlotService`, essai sans écriture). Puis la comparaison
 * du corps reçu à son fichier de référence (`tests/Fixtures/engine-requests/`).
 *
 * Partagé par `Integration/EngineRequestReferenceTest` (le décor écrit par les entités, sur le schéma
 * du jour) et `Integration/Migration/Lot4MigrationChainTest` (le même décor écrit en SQL dans le
 * schéma d'avant le lot 4, puis toutes les migrations jouées) : les deux comparent aux MÊMES fichiers.
 *
 * Capture du décor en SQL (`LOT4_DECOR_CAPTURE=1`, une fois, sur le code d'avant le lot 4) : au
 * moment où un rail va composer, les lignes que le test vient d'écrire sont relevées (colonne système
 * `xmin` postérieure au début de la transaction du test) et rangées, à la comparaison, dans la section
 * de leur référence de `tests/Fixtures/lot-4/decor-avant.sql`.
 *
 * S'emploie AVEC `EngineRequestDecorTrait` (identifiants du décor, horloge, cache).
 */
trait EngineRequestRunnerTrait
{
    private const string REFERENCE_DIR = __DIR__ . '/Fixtures/engine-requests';

    private const string LOT4_DECOR_FILE = __DIR__ . '/Fixtures/lot-4/decor-avant.sql';

    /** Le verdict que le moteur simulé rend à un déplacement : accepté. */
    private const string ER_VALIDATE_ACCEPT = '{"valid":true,"violations":[],"metrics":{"solver_version":"cp-sat","nb_variables":0,"nb_constraints":0,"wall_time_ms":0}}';

    /** Le décor relevé au dernier rail joué, en attente de sa référence (capture seulement). */
    private ?string $pendingDecorCapture = null;

    /** @return array<string, string> nom de la référence → SQL de sa section */
    private static function readDecorSections(): array
    {
        $sections = [];
        $current = null;
        foreach (explode("\n", (string) file_get_contents(self::LOT4_DECOR_FILE)) as $line) {
            if (str_starts_with($line, '-- @reference ')) {
                $current = substr($line, \strlen('-- @reference '));
                $sections[$current] = '';

                continue;
            }
            if (null === $current || '' === $line) {
                continue;
            }
            $sections[$current] .= ('' === $sections[$current] ? '' : "\n") . $line;
        }

        return $sections;
    }

    /**
     * Le handler de génération réel, avec un `EngineClient` réel sur un moteur simulé qui capture le
     * corps reçu et renvoie, comme le vrai, les verrous HARD reçus.
     *
     * @return array{body: array<string, mixed>, schedule: Schedule}
     */
    private function runGeneration(string $scheduleId, ?string $sourceScheduleId = null, ?string $fillSourceScheduleId = null): array
    {
        $em = $this->erEm();
        $em->flush();
        $this->captureDecorIfAsked();
        $em->clear();
        $this->clearGuc();
        $this->purgeEngineRequestCache();

        $captured = null;
        $engine = new MockHttpClient(static function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = json_decode((string) ($options['body'] ?? ''), true, 512, \JSON_THROW_ON_ERROR);
            $pins = array_values(array_filter(
                \is_array($captured) && \is_array($captured['slotTemplates'] ?? null) ? $captured['slotTemplates'] : [],
                static fn (mixed $pin): bool => \is_array($pin) && 'HARD' === ($pin['lockLevel'] ?? null),
            ));

            return new MockResponse(json_encode(['status' => 'completed', 'score' => 0, 'slots' => $pins, 'diagnostics' => []], \JSON_THROW_ON_ERROR), ['http_code' => 200]);
        });

        $container = static::getContainer();
        $hub = $this->createMock(HubInterface::class);
        $hub->method('publish')->willReturn('id');
        $handler = new GenerateScheduleHandler(
            $em,
            $container->get(ScheduleConstraintBuilder::class),
            $container->get(ScheduleResultImporter::class),
            new EngineClient($engine, new RequestIdContext),
            new ScheduleProgressPublisher($hub),
            new ScheduleDiagnosticsRecorder($em, $container->get(DiagnosticMessageBuilder::class)),
            new SolverMetricsMapper,
            $container->get(ClubGenerationLock::class),
            $container->get(TenantConnectionContext::class),
            $container->get(StructureSnapshotter::class),
            $container->get(SchedulePlanProvisioner::class),
            $container->get(OutputCreditLedger::class),
        );
        $handler(new GenerateScheduleMessage(
            scheduleId: $scheduleId,
            clubId: self::ER_CLUB,
            sourceScheduleId: $sourceScheduleId,
            fillSourceScheduleId: $fillSourceScheduleId,
        ));

        Assert::assertIsArray($captured, 'le moteur simulé doit avoir reçu une demande');
        $this->scopeGucToClub(self::ER_CLUB);
        $em->clear();
        $schedule = $em->getRepository(Schedule::class)->find($scheduleId);
        Assert::assertInstanceOf(Schedule::class, $schedule);
        Assert::assertSame(ScheduleStatus::COMPLETED, $schedule->getStatus(), 'la génération simulée doit aboutir');

        return ['body' => $captured, 'schedule' => $schedule];
    }

    /**
     * Le handler de placement réel (worker), `EngineClient` réel sur un moteur simulé qui capture.
     *
     * @param array{from: string, to: string}|null $window
     *
     * @return array<string, mixed>
     */
    private function runPlacement(?array $window): array
    {
        $em = $this->erEm();
        $em->flush();
        $this->captureDecorIfAsked();
        $run = new MatchPlacementRun(self::ER_CLUB, self::ER_SEASON, self::ER_USER, new DateTimeImmutable(self::ER_TODAY));
        $em->persist($run);
        $em->flush();
        $em->clear();
        $this->clearGuc();

        $container = static::getContainer();
        $lock = $container->get(MatchPlacementLock::class);
        $token = $lock->acquire(self::ER_CLUB, 120);
        Assert::assertIsString($token, 'le verrou de placement du club de référence doit être libre');

        $captured = null;
        $engine = new MockHttpClient(static function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = json_decode((string) ($options['body'] ?? ''), true, 512, \JSON_THROW_ON_ERROR);

            return new MockResponse(json_encode(['status' => 'completed', 'placements' => [], 'unplaced' => [], 'diagnostics' => [], 'metrics' => ['budget_seconds' => 35]], \JSON_THROW_ON_ERROR), ['http_code' => 200]);
        });
        $hub = $this->createMock(HubInterface::class);
        $hub->method('publish')->willReturn('id');
        $mailer = $this->createMock(MailerInterface::class);
        $handler = new PlaceMatchesHandler(
            $container->get(TenantConnectionContext::class),
            $em,
            $lock,
            $container->get(MatchPlacementPayloadBuilder::class),
            new EngineClient($engine, new RequestIdContext),
            $container->get(MatchPlacementResultApplier::class),
            new MatchPlacementProgressPublisher($hub),
            $container->get(OutputCreditLedger::class),
            $container->get(ClockInterface::class),
            $mailer,
            $container->get(PlacementRunEmailBuilder::class),
            new PlacementRunFailureNotifier($em, $mailer, $container->get(PlacementRunEmailBuilder::class), new NullLogger),
            new NullLogger,
        );

        try {
            $handler(new PlaceMatchesMessage(
                runId: $run->getId(),
                clubId: self::ER_CLUB,
                seasonId: self::ER_SEASON,
                weeksCount: 1,
                lockToken: $token,
                window: $window,
            ));
        } finally {
            $lock->release(self::ER_CLUB, $token);
        }
        $this->scopeGucToClub(self::ER_CLUB);

        Assert::assertIsArray($captured, 'le moteur simulé doit avoir reçu une demande de placement');

        return $captured;
    }

    /**
     * Le verdict d'un déplacement de séance (`POST /validate-assignments`), en ESSAI : le service réel
     * compose la demande, le moteur simulé l'accepte, rien n'est écrit. `$target` : [jour, heure, gymnase].
     *
     * @param array{0: int, 1: string, 2: string} $target
     *
     * @return array<string, mixed>
     */
    private function runMoveValidation(string $slotId, array $target): array
    {
        $em = $this->erEm();
        $em->flush();
        $this->captureDecorIfAsked();
        $em->clear();
        $this->purgeEngineRequestCache();
        $this->scopeGucToClub(self::ER_CLUB);

        $captured = null;
        $engine = new MockHttpClient(static function (string $method, string $url, array $options) use (&$captured): MockResponse {
            Assert::assertStringEndsWith('/validate-assignments', $url, 'un déplacement se juge par /validate-assignments');
            $captured = json_decode((string) ($options['body'] ?? ''), true, 512, \JSON_THROW_ON_ERROR);

            return new MockResponse(self::ER_VALIDATE_ACCEPT, ['http_code' => 200]);
        });

        $container = static::getContainer();
        $hub = $this->createMock(HubInterface::class);
        $hub->method('publish')->willReturn('id');
        $service = new MoveSlotService(
            $em,
            $container->get(ClubGenerationLock::class),
            $container->get(ScheduleConstraintBuilder::class),
            $container->get(SchedulePlanProvisioner::class),
            new EngineClient($engine, new RequestIdContext),
            new ScheduleProgressPublisher($hub),
            new NullLogger,
            new TeamCaseOccupancyGuard($em),
            $container->get(PeriodConstraintSelector::class),
        );

        $slot = $em->getRepository(ScheduleSlotTemplate::class)->find($slotId);
        Assert::assertInstanceOf(ScheduleSlotTemplate::class, $slot, 'la séance à déplacer doit exister');
        [$day, $time, $venueId] = $target;
        $verdict = $service->move($slot, $day, new DateTimeImmutable($time), $venueId, dryRun: true);

        Assert::assertTrue($verdict['valid'], 'le moteur simulé accepte le déplacement');
        Assert::assertIsArray($captured, 'le moteur simulé doit avoir reçu une demande de verdict');

        return $captured;
    }

    /**
     * Compare le corps reçu au fichier de référence (ou le capture, sous `ENGINE_REQUEST_CAPTURE=1`).
     *
     * @param array<string, mixed> $body
     */
    private function assertReference(string $name, array $body): void
    {
        $this->assertEveryUuidIsFixed($body, $name);
        $path = self::REFERENCE_DIR . '/' . $name . '.json';

        if ('1' === getenv('ENGINE_REQUEST_CAPTURE')) {
            if (!is_dir(self::REFERENCE_DIR)) {
                mkdir(self::REFERENCE_DIR, 0o775, true);
            }
            file_put_contents($path, json_encode($body, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION | \JSON_THROW_ON_ERROR) . "\n");
        }
        if (null !== $this->pendingDecorCapture) {
            $this->writeDecorSection($name, $this->pendingDecorCapture);
            $this->pendingDecorCapture = null;
        }

        Assert::assertFileExists($path, \sprintf('référence %s absente : la capturer avec ENGINE_REQUEST_CAPTURE=1, sur un code dont EngineRequestOrderIsStableTest est vert', $name));
        $reference = json_decode((string) file_get_contents($path), true, 512, \JSON_THROW_ON_ERROR);
        Assert::assertSame($reference, $body, \sprintf('la demande %s n\'est plus celle de la référence', $name));
    }

    /**
     * Relève, en SQL rejouable, chaque ligne que la transaction du test a écrite (ou réécrite) :
     * `xmin` d'une ligne écrite dans la transaction, ou dans un de ses points de sauvegarde, n'est
     * jamais antérieur à l'identifiant de la transaction. Lu sur la connexion applicative, club posé :
     * c'est elle qui a écrit le décor, une autre connexion ne verrait rien d'une transaction ouverte.
     */
    private function captureDecorIfAsked(): void
    {
        if ('1' !== getenv('LOT4_DECOR_CAPTURE')) {
            return;
        }
        $connection = $this->erEm()->getConnection();
        $xid = ((int) $connection->fetchOne('SELECT txid_current()')) % 4294967296;
        $tables = $connection->fetchFirstColumn(
            'SELECT table_name FROM information_schema.tables WHERE table_schema = \'public\' AND table_type = \'BASE TABLE\' AND table_name <> \'doctrine_migration_versions\' ORDER BY table_name',
        );
        $lines = [];
        foreach ($tables as $table) {
            $rows = $connection->fetchFirstColumn(\sprintf(
                'SELECT row_to_json(t)::text FROM public.%1$s t WHERE t.xmin::text::bigint >= %2$d ORDER BY row_to_json(t)::text',
                $connection->quoteSingleIdentifier((string) $table),
                $xid,
            ));
            foreach ($rows as $row) {
                $lines[] = \sprintf(
                    'INSERT INTO public.%1$s OVERRIDING SYSTEM VALUE SELECT * FROM json_populate_record(NULL::public.%1$s, %2$s);',
                    $connection->quoteSingleIdentifier((string) $table),
                    $connection->quote((string) $row),
                );
            }
        }
        Assert::assertNotSame([], $lines, 'la capture du décor doit relever au moins une ligne');
        $this->pendingDecorCapture = implode("\n", $lines);
    }

    /** Range (ou remplace) la section d'une référence dans `decor-avant.sql`, sections triées par nom. */
    private function writeDecorSection(string $name, string $sql): void
    {
        $sections = is_file(self::LOT4_DECOR_FILE) ? self::readDecorSections() : [];
        $sections[$name] = $sql;
        ksort($sections);

        $out = "-- Décor des quinze demandes de référence, écrit dans le schéma d'avant le lot 4 (schema-avant.sql).\n"
            . "-- Une section par référence de tests/Fixtures/engine-requests/, chargée seule dans une base neuve.\n"
            . "-- Capturé une fois (LOT4_DECOR_CAPTURE=1) sur le code d'avant le lot 4 ; ne se recapture jamais.\n";
        foreach ($sections as $section => $body) {
            $out .= "\n-- @reference " . $section . "\n" . $body . "\n";
        }
        file_put_contents(self::LOT4_DECOR_FILE, $out);
    }
}

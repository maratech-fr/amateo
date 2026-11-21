<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler;

use App\Command\PurgeExportsCommand;
use App\Entity\Club;
use App\Entity\Schedule;
use App\Entity\Season;
use App\Entity\SubscriptionPlan;
use App\Entity\Venue;
use App\Enum\ScheduleStatus;
use App\Enum\SeasonStatus;
use App\Message\ExportPdfMessage;
use App\MessageHandler\ExportPdfHandler;
use App\Service\PdfGenerator;
use App\Service\SchedulePlanProvisioner;
use App\Service\SeasonResolver;
use App\Tests\BuildsServiceWithDoublesTrait;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use ReflectionClassConstant;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

/**
 * Abus « plusieurs sorties sur un crédit » et « fichier livré remplacé par un rendu refusé » — le
 * PDF du planning prend son crédit quand le fichier est LIVRÉ, jamais à la demande, et d'un seul
 * coup strict : le rendu s'écrit sous un nom temporaire impossible à deviner, puis le crédit pris
 * (sans jamais dépasser le pool), le fichier final remplacé, le statut « prêt » posé ensemble. Pool
 * vidé entre la demande et la livraison : le rendu temporaire est supprimé, le fichier final et
 * l'URL déjà livrés ne bougent pas, le statut revient à « prêt » s'il y a une URL livrée, à
 * « échec » sinon.
 *
 * Témoins : le CONTENU des fichiers sur disque (le faux worker de rendu écrit « rendu n°K »), les
 * noms que le worker a reçus, le compteur du club, le statut et l'URL du planning. Chaque cas joue
 * deux demandes à la suite sur un club à qui il reste un crédit (9 sur 10), comme deux onglets
 * dont les messages attendent tous deux en file avant que le premier rendu ne finisse (un seul
 * consommateur de file). Le parcours du gestionnaire :
 * `un-credit-se-prend-quand-la-sortie-est-obtenue.feature` (« Avec un seul crédit… »).
 */
#[Group('integration')]
final class PdfCreditIsTakenOnDeliveryTest extends KernelTestCase
{
    use BuildsServiceWithDoublesTrait;
    use TenantGucTrait;

    private EntityManagerInterface $em;

    /** @var list<string> les noms de fichier reçus par le worker de rendu, dans l'ordre */
    private array $renderedNames = [];

    private bool $workerFails = false;

    /** Vrai : le hub Mercure lève à chaque publication (panne Mercure). */
    private bool $hubFails = false;

    /** @var list<string> les plannings créés : leurs fichiers sont retirés en fin de test */
    private array $scheduleIds = [];

    /** Le fichier livré prend un crédit ; un rendu en échec n'en prend aucun et ne laisse aucun fichier. */
    public function testADeliveredPdfTakesOneCreditAndAFailedRenderNone(): void
    {
        [$club, $season] = $this->seedClub(credits: 3);
        $delivered = $this->seedSchedule($club->getId(), $season);
        $failed = $this->seedSchedule($club->getId(), $season);

        $this->export($club->getId(), $delivered);
        self::assertSame('completed', $this->pdfStatusOf($club->getId(), $delivered));
        self::assertSame(4, $this->creditsOf($club->getId()), 'le PDF livré prend un crédit');
        self::assertSame('%PDF rendu n°1', $this->finalContent($delivered, 'all'));

        $this->workerFails = true;
        $this->export($club->getId(), $failed);
        self::assertSame('failed', $this->pdfStatusOf($club->getId(), $failed), 'témoin : le rendu a échoué');
        self::assertSame(4, $this->creditsOf($club->getId()), 'un rendu en échec ne prend aucun crédit');
        self::assertSame([], $this->filesOf($failed), 'un rendu en échec ne laisse aucun fichier');
    }

    /** Un crédit, deux plannings : seul le premier est livré, le second échoue sans fichier, un crédit pris. */
    public function testOneCreditTwoSchedulesOnlyTheFirstIsDelivered(): void
    {
        [$club, $season] = $this->seedClub(credits: 9);
        $first = $this->seedSchedule($club->getId(), $season);
        $second = $this->seedSchedule($club->getId(), $season);

        $this->export($club->getId(), $first);
        $this->export($club->getId(), $second);

        self::assertSame('completed', $this->pdfStatusOf($club->getId(), $first));
        self::assertSame('%PDF rendu n°1', $this->finalContent($first, 'all'));
        self::assertSame(2, \count($this->renderedNames), 'témoin : le second rendu a bien été tenté');
        self::assertSame('failed', $this->pdfStatusOf($club->getId(), $second), 'pool vidé à la livraison, aucune URL livrée : échec');
        self::assertNull($this->pdfUrlOf($club->getId(), $second), 'aucune URL pour un rendu refusé');
        self::assertSame([], $this->filesOf($second), 'le rendu refusé ne laisse aucun fichier, temporaire compris');
        self::assertSame(10, $this->creditsOf($club->getId()), 'un seul crédit pris, jamais au-delà du pool');
    }

    /**
     * Un crédit, le même planning sous deux périmètres : le premier reste livré et téléchargeable, le
     * second n'a jamais de fichier ; le statut revient à « prêt » sur l'URL déjà livrée.
     */
    public function testOneCreditTwoScopesTheDeliveredOneStays(): void
    {
        [$club, $season, $venueId] = $this->seedClub(credits: 9);
        $schedule = $this->seedSchedule($club->getId(), $season);

        $this->export($club->getId(), $schedule);
        $deliveredUrl = $this->pdfUrlOf($club->getId(), $schedule);
        $this->export($club->getId(), $schedule, $venueId);

        self::assertSame(2, \count($this->renderedNames), 'témoin : le second rendu a bien été tenté');
        self::assertSame('completed', $this->pdfStatusOf($club->getId(), $schedule), 'une URL livrée existe : le statut revient à « prêt »');
        self::assertSame($deliveredUrl, $this->pdfUrlOf($club->getId(), $schedule), 'l\'URL livrée n\'est pas touchée');
        self::assertStringEndsWith('-all.pdf', (string) $deliveredUrl);
        self::assertSame('%PDF rendu n°1', $this->finalContent($schedule, 'all'), 'le premier fichier reste téléchargeable, inchangé');
        self::assertSame([$this->finalName($schedule, 'all')], $this->filesOf($schedule), 'aucun fichier pour le second périmètre, aucun temporaire');
        self::assertSame(10, $this->creditsOf($club->getId()));
    }

    /**
     * Un crédit, le même planning sous le même périmètre : le fichier livré n'est PAS remplacé par le
     * rendu refusé (son contenu est celui du premier rendu), le second écran reçoit ce même fichier.
     */
    public function testOneCreditSameScopeTheDeliveredFileIsNotReplaced(): void
    {
        [$club, $season] = $this->seedClub(credits: 9);
        $schedule = $this->seedSchedule($club->getId(), $season);

        $this->export($club->getId(), $schedule);
        $deliveredUrl = $this->pdfUrlOf($club->getId(), $schedule);
        $this->export($club->getId(), $schedule);

        self::assertSame(2, \count($this->renderedNames), 'témoin : le second rendu a bien été tenté');
        self::assertSame('%PDF rendu n°1', $this->finalContent($schedule, 'all'), 'le fichier livré n\'est pas remplacé par un rendu refusé');
        self::assertSame('completed', $this->pdfStatusOf($club->getId(), $schedule));
        self::assertSame($deliveredUrl, $this->pdfUrlOf($club->getId(), $schedule), 'le second écran reçoit le même fichier');
        self::assertSame([$this->finalName($schedule, 'all')], $this->filesOf($schedule), 'aucun temporaire laissé');
        self::assertSame(10, $this->creditsOf($club->getId()), 'un seul crédit pour les deux demandes');
    }

    /**
     * Le rendu ne s'écrit jamais sous le nom final : sous un nom temporaire, le nom final suivi de
     * `-tmp` et de 32 caractères hexadécimaux aléatoires (impossible à deviner, différent à chaque
     * rendu), que le motif de la purge reconnaît (un temporaire orphelin finit purgé). Hors pool :
     * une offre payante livre aussi par ce chemin.
     */
    public function testTheRenderIsWrittenUnderAnUnguessableTemporaryName(): void
    {
        [$club, $season] = $this->seedClub(credits: 0, planCode: 'essentiel');
        $schedule = $this->seedSchedule($club->getId(), $season);

        $this->export($club->getId(), $schedule);
        $this->export($club->getId(), $schedule);

        self::assertCount(2, $this->renderedNames);
        $pattern = \sprintf('/^schedule-%s-all-tmp[0-9a-f]{32}\.pdf$/', preg_quote($schedule, '/'));
        foreach ($this->renderedNames as $name) {
            self::assertMatchesRegularExpression($pattern, $name, 'le worker écrit sous un nom temporaire, jamais sous le nom final');
            self::assertSame(1, preg_match((string) new ReflectionClassConstant(PurgeExportsCommand::class, 'RENDER_PATTERN')->getValue(), $name), 'le motif de la purge reconnaît un temporaire');
        }
        self::assertNotSame($this->renderedNames[0], $this->renderedNames[1], 'chaque rendu a son propre nom aléatoire');
        self::assertSame('%PDF rendu n°2', $this->finalContent($schedule, 'all'), 'une offre non bridée livre chaque rendu');
        self::assertSame([$this->finalName($schedule, 'all')], $this->filesOf($schedule), 'livré = renommé : aucun temporaire ne reste');
        self::assertSame(0, $this->creditsOf($club->getId()), 'une offre payante n\'est jamais décomptée');
    }

    /**
     * Mercure en panne à la publication de fin : la demande coûte UN crédit et le message est
     * acquitté du premier coup — sinon Messenger le rejoue (`messenger.yaml`, transport `async`,
     * `max_retries: 3`) et chaque reprise refait le rendu et reprend un crédit. Le worker est joué
     * comme Messenger le joue : une exception qui sort du handler vaut une nouvelle tentative.
     */
    public function testAMercureOutageAfterDeliveryCostsOneCreditAndAcksTheMessage(): void
    {
        [$club, $season] = $this->seedClub(credits: 3);
        $schedule = $this->seedSchedule($club->getId(), $season);
        $this->hubFails = true;

        $attempts = $this->exportAsMessengerWould($club->getId(), $schedule);

        self::assertSame(1, $attempts, 'le message est acquitté au premier passage : une panne Mercure n\'est pas une raison de rejouer');
        self::assertCount(1, $this->renderedNames, 'le rendu n\'est fait qu\'une fois');
        self::assertSame(4, $this->creditsOf($club->getId()), 'une demande, un crédit, même Mercure en panne');
        self::assertSame('completed', $this->pdfStatusOf($club->getId(), $schedule), 'le fichier est livré : le statut en base dit « prêt », l\'écran converge au sondage');
        self::assertSame('%PDF rendu n°1', $this->finalContent($schedule, 'all'));
    }

    /**
     * Redélivrance du MÊME message après livraison (worker tué entre le commit et l'acquittement) :
     * le planning est déjà « prêt » sous ce périmètre, rien n'est rendu de nouveau, aucun crédit
     * n'est repris, le fichier livré reste celui du premier rendu. Le club a encore des crédits :
     * c'est la garde qui protège, pas le pool plein. Jumeaux sur les rails de solve :
     * `RedeliveredGenerationTest::testARedeliveredCompletedScheduleTakesNoSecondCredit`,
     * `RedeliveredPlacementTest::testASolvedRunRedeliveredTakesNoSecondCredit`.
     */
    public function testARedeliveredDeliveredPdfTakesNoSecondCredit(): void
    {
        [$club, $season] = $this->seedClub(credits: 3);
        $schedule = $this->seedSchedule($club->getId(), $season);

        $requestId = $this->export($club->getId(), $schedule);
        self::assertSame(4, $this->creditsOf($club->getId()), 'témoin : la première livraison prend son crédit');
        $deliveredUrl = $this->pdfUrlOf($club->getId(), $schedule);

        $this->redeliver($club->getId(), $schedule, $requestId);

        self::assertCount(1, $this->renderedNames, 'la redélivrance ne refait pas le rendu');
        self::assertSame(4, $this->creditsOf($club->getId()), 'la redélivrance d\'un PDF livré ne reprend jamais un crédit');
        self::assertSame('completed', $this->pdfStatusOf($club->getId(), $schedule));
        self::assertSame($deliveredUrl, $this->pdfUrlOf($club->getId(), $schedule));
        self::assertSame('%PDF rendu n°1', $this->finalContent($schedule, 'all'), 'le fichier livré n\'est pas remplacé');
    }

    /**
     * Deux demandes VRAIMENT coup sur coup (les deux gestes de l'écran avant que le worker ne traite
     * le premier message, comme en file réelle) sur le même planning, deux périmètres, offre payante :
     * les deux fichiers sont rendus et livrés. Le second message trouve le planning déjà « prêt »
     * (le premier vient de livrer) : ce n'est pas une redélivrance, une garde qui ne lirait que le
     * statut le sauterait à tort. Le pendant de {@see testARedeliveredDeliveredPdfTakesNoSecondCredit}.
     */
    public function testTwoQueuedRequestsOnTwoScopesAreBothDeliveredForAPaidClub(): void
    {
        [$club, $season, $venueId] = $this->seedClub(credits: 0, planCode: 'essentiel');
        $schedule = $this->seedSchedule($club->getId(), $season);

        $first = $this->request($club->getId(), $schedule);
        $second = $this->request($club->getId(), $schedule);
        $this->work($club->getId(), $schedule, null, $first);
        $this->em->clear();
        $this->work($club->getId(), $schedule, $venueId, $second);

        self::assertCount(2, $this->renderedNames, 'les deux demandes sont rendues');
        self::assertSame('%PDF rendu n°1', $this->finalContent($schedule, 'all'), 'le premier périmètre est livré');
        self::assertSame('%PDF rendu n°2', $this->finalContent($schedule, substr($venueId, 0, 8)), 'le second périmètre est livré aussi');
        self::assertSame('completed', $this->pdfStatusOf($club->getId(), $schedule));
        self::assertStringEndsWith('-' . substr($venueId, 0, 8) . '.pdf', (string) $this->pdfUrlOf($club->getId(), $schedule), 'l\'URL est celle de la dernière livraison');
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->renderedNames = [];
        $this->workerFails = false;
        $this->hubFails = false;
        $this->scheduleIds = [];
        if (!is_dir(PdfGenerator::outputDir())) {
            @mkdir(PdfGenerator::outputDir(), 0o775, true);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->scheduleIds as $id) {
            foreach ($this->filesOf($id) as $name) {
                @unlink(PdfGenerator::outputDir() . '/' . $name);
            }
        }
        parent::tearDown();
    }

    /** Le geste de l'écran (le contrôleur pose « en attente »), puis le worker traite le message ; rend l'identifiant de la demande. */
    private function export(string $clubId, string $scheduleId, ?string $venueId = null): string
    {
        $requestId = $this->request($clubId, $scheduleId);
        $this->work($clubId, $scheduleId, $venueId, $requestId);

        return $requestId;
    }

    /** Le geste de l'écran, puis le worker traite le message comme Messenger : rejoué tant qu'il lève, 1 + 3 reprises au plus. */
    private function exportAsMessengerWould(string $clubId, string $scheduleId): int
    {
        $requestId = $this->request($clubId, $scheduleId);
        for ($attempt = 1; $attempt <= 4; ++$attempt) {
            try {
                $this->work($clubId, $scheduleId, null, $requestId);

                return $attempt;
            } catch (Throwable) {
                $this->em->clear();
            }
        }

        return $attempt - 1;
    }

    /** Le même message relu par le worker, sans nouveau geste de l'écran (worker tué avant l'acquittement). */
    private function redeliver(string $clubId, string $scheduleId, string $requestId): void
    {
        $this->em->clear();
        $this->work($clubId, $scheduleId, null, $requestId);
    }

    /** Le contrôleur pose « en attente » avant de mettre le message en file, sous un nouvel identifiant de demande. */
    private function request(string $clubId, string $scheduleId): string
    {
        $this->scopeGucToClub($clubId);
        $this->em->getConnection()->executeStatement('UPDATE schedule SET pdf_export_status = \'pending\' WHERE id = :id', ['id' => $scheduleId]);
        $this->em->clear();
        $this->clearGuc();

        return bin2hex(random_bytes(16));
    }

    private function work(string $clubId, string $scheduleId, ?string $venueId, string $requestId): void
    {
        $worker = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $payload = json_decode((string) $options['body'], true, flags: \JSON_THROW_ON_ERROR);
            \assert(\is_array($payload) && \is_string($payload['filename'] ?? null));
            $this->renderedNames[] = $payload['filename'];
            if ($this->workerFails) {
                return new MockResponse((string) json_encode(['success' => false, 'error' => 'rendu impossible (simulé)']));
            }
            file_put_contents(PdfGenerator::outputDir() . '/' . basename($payload['filename']), '%PDF rendu n°' . \count($this->renderedNames));

            return new MockResponse((string) json_encode(['success' => true]));
        });
        $hub = $this->createMock(HubInterface::class);
        $hub->method('publish')->willReturnCallback(fn (): string => $this->hubFails ? throw new RuntimeException('Mercure injoignable (simulé)') : 'id');

        $pdf = $this->buildWithDoubles(PdfGenerator::class, [HttpClientInterface::class => $worker, EntityManagerInterface::class => $this->em]);
        $handler = $this->buildWithDoubles(ExportPdfHandler::class, [PdfGenerator::class => $pdf, HubInterface::class => $hub, EntityManagerInterface::class => $this->em]);

        $handler(new ExportPdfMessage(scheduleId: $scheduleId, clubId: $clubId, venueId: $venueId, requestId: $requestId));
    }

    private function finalName(string $scheduleId, string $scope): string
    {
        return \sprintf('schedule-%s-%s.pdf', $scheduleId, $scope);
    }

    private function finalContent(string $scheduleId, string $scope): ?string
    {
        $path = PdfGenerator::outputDir() . '/' . $this->finalName($scheduleId, $scope);
        clearstatcache();

        return is_file($path) ? (string) file_get_contents($path) : null;
    }

    /** @return list<string> les fichiers du répertoire de rendus qui appartiennent à ce planning */
    private function filesOf(string $scheduleId): array
    {
        clearstatcache();
        $names = array_map('basename', glob(PdfGenerator::outputDir() . '/schedule-' . $scheduleId . '-*') ?: []);
        sort($names);

        return $names;
    }

    private function pdfStatusOf(string $clubId, string $scheduleId): ?string
    {
        return $this->scheduleOf($clubId, $scheduleId)->getPdfExportStatus();
    }

    private function pdfUrlOf(string $clubId, string $scheduleId): ?string
    {
        return $this->scheduleOf($clubId, $scheduleId)->getPdfExportUrl();
    }

    private function scheduleOf(string $clubId, string $scheduleId): Schedule
    {
        $this->scopeGucToClub($clubId);
        $this->em->clear();
        $schedule = $this->em->getRepository(Schedule::class)->find($scheduleId);
        self::assertInstanceOf(Schedule::class, $schedule);
        $this->clearGuc();

        return $schedule;
    }

    private function creditsOf(string $clubId): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT output_credits_used FROM club WHERE id = :id', ['id' => $clubId]);
    }

    /**
     * Un club (Découverte non démo par défaut : `plan_id` NULL), sa saison courante et un gymnase
     * (pour l'export d'un seul périmètre).
     *
     * @return array{0: Club, 1: Season, 2: string}
     */
    private function seedClub(int $credits, ?string $planCode = null): array
    {
        $uid = uniqid('', true);
        $club = new Club;
        $club->setName('Pdf Club ' . $uid);
        $club->setSlug('pdf-credit-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setIsDemo(false);
        $club->setOutputCreditsUsed($credits);
        $this->em->persist($club);
        $this->em->flush();

        $year = SeasonResolver::seasonYear(new DateTimeImmutable('today'));
        if (null !== $planCode) {
            $plan = $this->em->getRepository(SubscriptionPlan::class)->findOneBy(['code' => $planCode]);
            \assert($plan instanceof SubscriptionPlan);
            $club->setPlanId($plan->getId());
            $club->setPaidSeasonYear($year);
            $this->em->flush();
        }

        $this->scopeGucToClub($club->getId());
        $season = new Season;
        $season->setClubId($club->getId());
        $season->setName($year . '-' . ($year + 1));
        $season->setStartDate(new DateTimeImmutable($year . '-08-01'));
        $season->setEndDate(new DateTimeImmutable(($year + 1) . '-07-15'));
        $season->setStatus(SeasonStatus::ACTIVE);
        $season->setTransitionData([]);
        $this->em->persist($season);

        $venue = new Venue;
        $venue->setClubId($club->getId());
        $venue->setSeasonId($season->getId());
        $venue->setName('Gymnase Nord');
        $venue->setSource('manual');
        $this->em->persist($venue);
        $this->em->flush();
        $this->clearGuc();

        return [$club, $season, $venue->getId()];
    }

    private function seedSchedule(string $clubId, Season $season): string
    {
        $this->scopeGucToClub($clubId);
        $provisioner = self::getContainer()->get(SchedulePlanProvisioner::class);
        $schedule = new Schedule;
        $schedule->setClubId($clubId);
        $schedule->setSeasonId($season->getId());
        $schedule->setName('Planning à exporter');
        $schedule->setStatus(ScheduleStatus::COMPLETED);
        $schedule->setSchedulePlanId($provisioner->ensureSeasonPlanId($season->getId()));
        $this->em->persist($schedule);
        $this->em->flush();
        $provisioner->linkSchedule($schedule);
        $schedule->setStatus(ScheduleStatus::COMPLETED);
        $this->em->flush();
        $this->em->clear();
        $this->clearGuc();
        $this->scheduleIds[] = $schedule->getId();

        return $schedule->getId();
    }
}

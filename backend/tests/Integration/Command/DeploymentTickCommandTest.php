<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Command\DeploymentTickCommand;
use App\Command\HealthAlertCommand;
use App\Enum\MatchPlacementRunStatus;
use App\Enum\ScheduleStatus;
use App\Mail\EmailTemplateRenderer;
use App\Service\GitHubDeployClient;
use App\Service\MailFrom;
use App\Service\ProductIdentity;
use App\Tests\Double\GitHubDeployHttpClientStub;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;
use Symfony\Component\Uid\Uuid;

/**
 * `app:deployment:tick` — la MACHINE À ÉTATS de la « mise en prod programmée » (PR b), battue
 * toutes les minutes par le cron-runner, horloge figée (MockClock). Les promesses gardées :
 *
 *  - AVANT H : rien ne bouge ; un déploiement ANNULÉ n'est jamais ranimé ;
 *  - à H, si une GÉNÉRATION (schedule GENERATING) ou un PLACEMENT (match_placement_run RUNNING) est
 *    en cours — PEU IMPORTE le club, lu par la connexion ADMIN cross-tenant (P4-348) — le départ est
 *    RETARDÉ : statut `scheduled`, une NOTE posée, zéro dispatch ; plafonné à H +10 min (`WAIT_CAP`),
 *    au-delà on déploie malgré l'activité (+ note de plafond) ;
 *  - l'activité retombée, dispatch du workflow, statut DISPATCHED ; l'heure de retour est RE-DATÉE
 *    au dispatch réel (now + fenêtre d'origine), et c'est cette valeur qui part en `maintenance_until` ;
 *  - À H, si le tag a disparu ou que la CI est redevenue rouge → FAILED + e-mail, SANS dispatch ;
 *  - GitHub INJOIGNABLE à H (tags en 4xx, ou panne de transport) → traité comme un échec de
 *    dispatch : RÉESSAYÉ (jamais confondu avec un tag disparu), puis FAILED « GitHub est resté
 *    injoignable » après la deadline (= scheduledAt + WAIT_CAP + DISPATCH_RETRY), zéro dispatch ;
 *  - run GitHub `success` → SUCCEEDED + e-mail ; run `failure` → FAILED + e-mail disant la
 *    MAINTENANCE LAISSÉE (décision fondateur : pas de rollback auto v1) ;
 *  - un dispatch qui échoue est RÉESSAYÉ jusqu'à scheduledAt + WAIT_CAP + DISPATCH_RETRY, puis FAILED ;
 *  - la NOTE de départ (attente/plafond) est reportée dans les e-mails de succès/échec.
 *
 * Contrat FIXÉ pour NEO (P4-348/350) : `WAIT_CAP_MINUTES = 10`, colonne `dispatch_note` (TEXT NULL,
 * migration à la main), l'activité comptée par la connexion `admin` (COUNT schedule GENERATING +
 * match_placement_run RUNNING, PENDING non compté), re-datage de `expected_return_at` au dispatch.
 *
 * L'e-mail suit le patron {@see HealthAlertCommand} : destinataires = superadmins
 * ACTIFS. Mailer mocké (enregistre to/subject/body). Client GitHub réel sur le double HTTP
 * ({@see GitHubDeployHttpClientStub}) : aucune requête ne part vers api.github.com.
 *
 * Contrat FIXÉ pour NEO : commande `app:deployment:tick`, constructeur
 * `(ManagerRegistry $registry, GitHubDeployClient $client, MailerInterface $mailer,
 * MailFrom $mailFrom, ProductIdentity $productIdentity, ClockInterface $clock)` ; la commande
 * lit/écrit `scheduled_deployment` via la connexion par DÉFAUT (entité Doctrine), les
 * destinataires via la connexion `admin` (patron HealthAlertCommand).
 */
#[Group('phase1')]
#[Group('integration')]
final class DeploymentTickCommandTest extends KernelTestCase
{
    private const string NOW = '2026-10-19T01:00:00+02:00';

    private Connection $db;

    /** @var list<string> */
    private array $sentTo = [];

    /** @var list<string> */
    private array $sentBodies = [];

    /** @var list<Email> les e-mails CAPTÉS entiers (en-têtes compris), pour l'assertion du bouton CTA. */
    private array $sentEmails = [];

    private ?string $adminId = null;

    private string $adminEmail;

    /**
     * Ids des lignes TENANT seedées via la connexion admin (GENERATING / RUNNING), à purger.
     *
     * @var list<string>
     */
    private array $seededScheduleIds = [];

    /** @var list<string> */
    private array $seededRunIds = [];

    /** @var list<string> */
    private array $seededPlanIds = [];

    public function testBeforeTheWindowNothingHappens(): void
    {
        $id = $this->seedDeployment('scheduled', scheduledAt: '2026-10-19T02:00:00+02:00');

        $this->tickAt(self::NOW);

        self::assertSame('scheduled', $this->statusOf($id), 'avant H, le déploiement attend');
        self::assertSame([], $this->sentTo, 'aucun e-mail avant H');
        self::assertSame(0, GitHubDeployHttpClientStub::$dispatchCount, 'aucun dispatch avant H');
    }

    public function testACancelledDeploymentIsNeverRevived(): void
    {
        $id = $this->seedDeployment('cancelled', scheduledAt: '2026-10-19T00:30:00+02:00');

        $this->tickAt(self::NOW);

        self::assertSame('cancelled', $this->statusOf($id), 'un annulé reste annulé, même après H');
        self::assertSame([], $this->sentTo);
        self::assertSame(0, GitHubDeployHttpClientStub::$dispatchCount);
    }

    public function testAVanishedTagAtTickFailsWithMailAndNoDispatch(): void
    {
        GitHubDeployHttpClientStub::$tags = []; // le tag a disparu entre la programmation et H
        $id = $this->seedDeployment('scheduled', scheduledAt: '2026-10-19T00:59:00+02:00');

        $this->tickAt(self::NOW);

        self::assertSame('failed', $this->statusOf($id), 'tag disparu à H → FAILED');
        self::assertContains($this->adminEmail, $this->sentTo, 'un e-mail prévient du refus');
        self::assertSame(0, GitHubDeployHttpClientStub::$dispatchCount, 'aucun dispatch quand la précondition a cassé');
    }

    public function testARedCiAtTickFailsWithMailAndNoDispatch(): void
    {
        GitHubDeployHttpClientStub::$tags = [['name' => 'v1.0.0', 'sha' => 'head0000']];
        GitHubDeployHttpClientStub::$ciConclusion = 'failure'; // la CI est redevenue rouge
        $id = $this->seedDeployment('scheduled', scheduledAt: '2026-10-19T00:59:00+02:00');

        $this->tickAt(self::NOW);

        self::assertSame('failed', $this->statusOf($id), 'CI rouge à H → FAILED');
        self::assertContains($this->adminEmail, $this->sentTo);
        self::assertSame(0, GitHubDeployHttpClientStub::$dispatchCount);
    }

    public function testPreconditionsMetDispatchesTheWorkflowAndMovesToDispatched(): void
    {
        GitHubDeployHttpClientStub::$tags = [['name' => 'v1.0.0', 'sha' => 'head0000']];
        GitHubDeployHttpClientStub::$ciConclusion = 'success';
        GitHubDeployHttpClientStub::$runId = 555000111;
        $id = $this->seedDeployment('scheduled', scheduledAt: '2026-10-19T00:59:00+02:00');

        $this->tickAt(self::NOW);

        self::assertSame('dispatched', $this->statusOf($id), 'précondition OK → DISPATCHED');
        self::assertSame(1, GitHubDeployHttpClientStub::$dispatchCount, 'le workflow a été dispatché une fois');
        $row = $this->rowOf($id);
        self::assertNotNull($row['dispatched_at'], 'dispatched_at est horodaté');
        self::assertSame(555000111, (int) $row['github_run_id'], 'le run resolu est mémorisé');
    }

    public function testADispatchedRunThatSucceedsIsMarkedSucceededAndMails(): void
    {
        GitHubDeployHttpClientStub::$runConclusion = 'success';
        $id = $this->seedDeployment('dispatched', scheduledAt: '2026-10-19T00:55:00+02:00', githubRunId: 555000111);

        $this->tickAt(self::NOW);

        self::assertSame('succeeded', $this->statusOf($id), 'run success → SUCCEEDED');
        self::assertContains($this->adminEmail, $this->sentTo, 'un e-mail de succès part aux superadmins');
    }

    public function testTheDeploymentEmailCarriesAConsoleCtaWithAnAbsoluteUrl(): void
    {
        // PROMESSE (D1) : l'e-mail de transition porte un bouton « Ouvrir la console » vers une URL
        // ABSOLUE de la console (plus « Console : /admin » relatif, inutilisable en client mail).
        // ROUGE tant que le producteur ne pose pas l'en-tête CTA ni l'URL absolue.
        GitHubDeployHttpClientStub::$runConclusion = 'success';
        $id = $this->seedDeployment('dispatched', scheduledAt: '2026-10-19T00:55:00+02:00', githubRunId: 555000111);

        $this->tickAt(self::NOW);

        self::assertSame('succeeded', $this->statusOf($id));
        self::assertNotSame([], $this->sentEmails, 'un e-mail de transition a bien été émis');
        $email = $this->sentEmails[array_key_last($this->sentEmails)];
        $headers = $email->getHeaders();
        self::assertTrue(
            $headers->has(EmailTemplateRenderer::CTA_URL_HEADER),
            'ROUGE tant que l\'e-mail de déploiement ne pose pas le bouton console',
        );
        self::assertSame('Ouvrir la console', $headers->getHeaderBody(EmailTemplateRenderer::CTA_LABEL_HEADER));
        self::assertMatchesRegularExpression('~^https?://\S+/admin$~', (string) $headers->getHeaderBody(EmailTemplateRenderer::CTA_URL_HEADER), 'la console en URL ABSOLUE');
    }

    public function testADispatchedRunThatFailsIsMarkedFailedAndMailsThatMaintenanceIsLeftOn(): void
    {
        GitHubDeployHttpClientStub::$runConclusion = 'failure';
        $id = $this->seedDeployment('dispatched', scheduledAt: '2026-10-19T00:55:00+02:00', githubRunId: 555000111);

        $this->tickAt(self::NOW);

        self::assertSame('failed', $this->statusOf($id), 'run failure → FAILED');
        self::assertContains($this->adminEmail, $this->sentTo);
        $body = strtolower(implode("\n", $this->sentBodies));
        self::assertStringContainsString('maintenance', $body, 'l\'e-mail d\'échec dit que la maintenance reste posée (pas de rollback auto v1)');
    }

    /**
     * Cas (d) — la deadline de réessai d'un dispatch en échec est DÉCALÉE par le plafond d'attente :
     * scheduledAt + WAIT_CAP (10) + DISPATCH_RETRY (10) = +20 min. À +11 (ancienne borne +10), on
     * réessaie ENCORE ; ce n'est qu'au-delà de +20 que l'échec devient FAILED.
     */
    public function testADispatchFailureIsRetriedUntilTheCapPlusRetryDeadlineThenFails(): void
    {
        GitHubDeployHttpClientStub::$tags = [['name' => 'v1.0.0', 'sha' => 'head0000']];
        GitHubDeployHttpClientStub::$ciConclusion = 'success';
        GitHubDeployHttpClientStub::$dispatchFails = true;
        $id = $this->seedDeployment('scheduled', scheduledAt: self::NOW);

        // H +11 min : AVANT l'ancienne borne +10 on réessayait ; la nouvelle deadline étant +20,
        // on réessaie encore ici (le plafond d'attente a repoussé la deadline de dispatch).
        $this->tickAt('2026-10-19T01:11:00+02:00');
        self::assertSame('scheduled', $this->statusOf($id), 'avant scheduledAt + 10 + 10, un dispatch en échec est réessayé');
        self::assertSame([], $this->sentTo, 'pas encore d\'e-mail : on réessaie');

        // H +21 min : la deadline (scheduledAt + 10 + 10) est passée → FAILED + e-mail.
        $this->tickAt('2026-10-19T01:21:00+02:00');
        self::assertSame('failed', $this->statusOf($id), 'après scheduledAt + 10 + 10, l\'échec de dispatch devient FAILED');
        self::assertContains($this->adminEmail, $this->sentTo);
        $body = strtolower(implode("\n", $this->sentBodies));
        self::assertStringContainsString('injoignable', $body, 'l\'e-mail d\'échec dit que GitHub est resté injoignable');
        self::assertStringContainsString('maintenance', $body, 'et que la maintenance n\'a pas été posée');
    }

    /**
     * Cas (a) — à H, une GÉNÉRATION en cours (même pour un AUTRE club : lu cross-tenant par la
     * connexion admin) retarde le départ : statut `scheduled`, une note posée, ZÉRO dispatch.
     */
    public function testAGeneratingScheduleMakesTheTickWaitWithANote(): void
    {
        GitHubDeployHttpClientStub::$tags = [['name' => 'v1.0.0', 'sha' => 'head0000']];
        GitHubDeployHttpClientStub::$ciConclusion = 'success';
        $this->seedGeneratingSchedule(); // un club TIERS, pas celui qui déploie
        $id = $this->seedDeployment('scheduled', scheduledAt: '2026-10-19T00:59:00+02:00');

        $this->tickAt(self::NOW);

        self::assertSame('scheduled', $this->statusOf($id), 'une génération en cours retarde le départ');
        self::assertSame(0, GitHubDeployHttpClientStub::$dispatchCount, 'rien n\'est dispatché tant qu\'une activité tourne');
        self::assertSame([], $this->sentTo, 'un simple retard n\'alerte pas les superadmins');
        $note = $this->noteOf($id);
        self::assertStringContainsString('Départ retardé', (string) $note, 'une note explique le retard');
        self::assertStringContainsString('01:09', (string) $note, 'la note annonce le plafond d\'attente (scheduledAt + 10 min)');
    }

    /** « Placement RUNNING compte aussi » : un run de placement en cours retarde le départ, comme une génération. */
    public function testARunningPlacementAlsoMakesTheTickWait(): void
    {
        GitHubDeployHttpClientStub::$tags = [['name' => 'v1.0.0', 'sha' => 'head0000']];
        GitHubDeployHttpClientStub::$ciConclusion = 'success';
        $this->seedRunningPlacement();
        $id = $this->seedDeployment('scheduled', scheduledAt: '2026-10-19T00:59:00+02:00');

        $this->tickAt(self::NOW);

        self::assertSame('scheduled', $this->statusOf($id), 'un placement RUNNING retarde le départ aussi');
        self::assertSame(0, GitHubDeployHttpClientStub::$dispatchCount);
    }

    /**
     * Cas (b) — l'activité retombée, le tick dispatche ET re-date l'heure de retour au dispatch
     * RÉEL (now + fenêtre d'origine) ; c'est cette valeur re-datée qui part en `maintenance_until`.
     */
    public function testClearedActivityDispatchesAndRedatesTheExpectedReturn(): void
    {
        GitHubDeployHttpClientStub::$tags = [['name' => 'v1.0.0', 'sha' => 'head0000']];
        GitHubDeployHttpClientStub::$ciConclusion = 'success';
        GitHubDeployHttpClientStub::$runId = 555000111;
        // scheduledAt = NOW -5 min, fenêtre d'origine = 60 min (expected_return seedé à scheduledAt +60).
        $id = $this->seedDeployment('scheduled', scheduledAt: '2026-10-19T00:55:00+02:00');

        $this->tickAt(self::NOW);

        self::assertSame('dispatched', $this->statusOf($id), 'sans activité, le tick dispatche');
        self::assertSame(1, GitHubDeployHttpClientStub::$dispatchCount);
        // Re-datage : expected_return_at = now (01:00) + fenêtre (60) = 02:00, pas la valeur seedée (01:55).
        $expected = new DateTimeImmutable('2026-10-19T02:00:00+02:00');
        self::assertSame(
            $expected->getTimestamp(),
            new DateTimeImmutable((string) $this->rowOf($id)['expected_return_at'])->getTimestamp(),
            'l\'heure de retour est re-datée à now + fenêtre d\'origine',
        );
        $inputs = GitHubDeployHttpClientStub::$lastDispatchInputs;
        self::assertIsArray($inputs);
        self::assertSame(
            $expected->getTimestamp(),
            new DateTimeImmutable((string) $inputs['maintenance_until'])->getTimestamp(),
            'maintenance_until = l\'heure de retour RE-DATÉE (pas la valeur programmée)',
        );
    }

    /**
     * Cas (c) — au-delà du plafond d'attente (now > scheduledAt + WAIT_CAP), le tick déploie MALGRÉ
     * une activité encore en cours, en posant une note de plafond.
     */
    public function testPastTheWaitingCapTheTickDispatchesDespiteActivityWithANote(): void
    {
        GitHubDeployHttpClientStub::$tags = [['name' => 'v1.0.0', 'sha' => 'head0000']];
        GitHubDeployHttpClientStub::$ciConclusion = 'success';
        $this->seedGeneratingSchedule(); // l'activité tourne toujours
        // scheduledAt = NOW -15 min → now (01:00) > scheduledAt (00:45) + 10 = 00:55 : plafond atteint.
        $id = $this->seedDeployment('scheduled', scheduledAt: '2026-10-19T00:45:00+02:00');

        $this->tickAt(self::NOW);

        self::assertSame('dispatched', $this->statusOf($id), 'passé le plafond, on déploie malgré l\'activité');
        self::assertSame(1, GitHubDeployHttpClientStub::$dispatchCount);
        self::assertStringContainsString('Plafond', (string) $this->noteOf($id), 'une note dit que le plafond a forcé le départ');
    }

    /**
     * Cas (e) — GitHub INJOIGNABLE à H par panne de TRANSPORT (réseau) : traité comme un échec de
     * dispatch RÉESSAYABLE (jamais confondu avec un tag disparu), puis FAILED « GitHub est resté
     * injoignable » après la deadline, et JAMAIS un dispatch.
     */
    public function testAGitHubOutageAtTickRetriesThenFailsWithoutDispatch(): void
    {
        GitHubDeployHttpClientStub::$tags = [['name' => 'v1.0.0', 'sha' => 'head0000']];
        GitHubDeployHttpClientStub::$transportFails = true; // api.github.com injoignable (réseau)
        $id = $this->seedDeployment('scheduled', scheduledAt: self::NOW);

        // H +5 min : injoignable → on réessaie, rien n'est abandonné ni dispatché.
        $this->tickAt('2026-10-19T01:05:00+02:00');
        self::assertSame('scheduled', $this->statusOf($id), 'GitHub injoignable à H → on réessaie (pas un tag disparu)');
        self::assertSame(0, GitHubDeployHttpClientStub::$dispatchCount, 'aucun dispatch quand GitHub est injoignable');
        self::assertSame([], $this->sentTo);

        // H +21 min : deadline (scheduledAt + 10 + 10) dépassée → FAILED « injoignable », toujours 0 dispatch.
        $this->tickAt('2026-10-19T01:21:00+02:00');
        self::assertSame('failed', $this->statusOf($id));
        self::assertSame(0, GitHubDeployHttpClientStub::$dispatchCount, 'un échec « injoignable » n\'a jamais dispatché');
        self::assertContains($this->adminEmail, $this->sentTo);
        self::assertStringContainsString('injoignable', strtolower(implode("\n", $this->sentBodies)));
    }

    /**
     * Cas (e bis) — un `GET /tags` qui répond 4xx (jeton révoqué, dépôt renommé) est « injoignable »,
     * PAS un tag disparu : le tick RÉESSAIE (reste `scheduled`), là où un tags VIDE-mais-réussi abandonne
     * immédiatement ({@see self::testAVanishedTagAtTickFailsWithMailAndNoDispatch}).
     */
    public function testAFailingTagsReadAtTickIsTreatedAsUnreachableNotVanished(): void
    {
        GitHubDeployHttpClientStub::$tagsFailStatus = 403; // réponse d'ERREUR (≠ liste vide)
        $id = $this->seedDeployment('scheduled', scheduledAt: '2026-10-19T00:59:00+02:00');

        $this->tickAt(self::NOW);

        self::assertSame('scheduled', $this->statusOf($id), 'un tags en 4xx est injoignable → réessai, jamais un abandon sec');
        self::assertSame(0, GitHubDeployHttpClientStub::$dispatchCount);
        self::assertSame([], $this->sentTo, 'un réessai ne mail pas encore');
    }

    /** Cas (f) — la note de départ (attente/plafond) est reportée dans l'e-mail (ici, de succès). */
    public function testTheDispatchNoteIsCarriedIntoTheEmails(): void
    {
        GitHubDeployHttpClientStub::$runConclusion = 'success';
        $note = 'Plafond d’attente atteint : déploiement déclenché malgré une activité encore en cours.';
        $id = $this->seedDeployment('dispatched', scheduledAt: '2026-10-19T00:55:00+02:00', githubRunId: 555000111, dispatchNote: $note);

        $this->tickAt(self::NOW);

        self::assertSame('succeeded', $this->statusOf($id));
        self::assertStringContainsString($note, implode("\n", $this->sentBodies), 'la note de départ est reportée dans l\'e-mail');
    }

    /**
     * Cas (g) — ABUS : le tick qui attend LIT l'activité cross-tenant (connexion admin) mais n'ÉCRIT
     * JAMAIS dans les données tenant. Les lignes d'un AUTRE club (GENERATING, RUNNING) sont
     * byte-identiques après le passage — le tick ne les a jamais touchées.
     */
    public function testTheWaitingTickReadsTenantsButNeverWritesThem(): void
    {
        GitHubDeployHttpClientStub::$tags = [['name' => 'v1.0.0', 'sha' => 'head0000']];
        GitHubDeployHttpClientStub::$ciConclusion = 'success';
        $scheduleId = $this->seedGeneratingSchedule();
        $runId = $this->seedRunningPlacement();
        $id = $this->seedDeployment('scheduled', scheduledAt: '2026-10-19T00:59:00+02:00');

        $this->tickAt(self::NOW);

        self::assertSame('scheduled', $this->statusOf($id), 'le tick a bien ATTENDU (preuve qu\'il a LU l\'activité)');
        self::assertSame(0, GitHubDeployHttpClientStub::$dispatchCount);
        self::assertSame('GENERATING', $this->admin()->fetchOne('SELECT status FROM schedule WHERE id = :id', ['id' => $scheduleId]), 'la génération d\'un autre club n\'est jamais mutée');
        self::assertSame('RUNNING', $this->admin()->fetchOne('SELECT status FROM match_placement_run WHERE id = :id', ['id' => $runId]), 'le placement d\'un autre club n\'est jamais muté');
    }

    protected function setUp(): void
    {
        self::bootKernel();
        GitHubDeployHttpClientStub::reset();
        $this->sentTo = [];
        $this->sentBodies = [];
        $this->sentEmails = [];
        $this->seededScheduleIds = [];
        $this->seededRunIds = [];
        $this->seededPlanIds = [];
        $registry = self::getContainer()->get(ManagerRegistry::class);
        \assert($registry instanceof ManagerRegistry);
        $this->db = $registry->getConnection();
        $this->adminEmail = 'tick-admin-' . bin2hex(random_bytes(3)) . '@example.test';
        $this->seedSuperAdmin($this->adminEmail);
    }

    protected function tearDown(): void
    {
        // Les lignes TENANT seedées via la connexion admin ne sont PAS sous DAMA : purge explicite.
        foreach ($this->seededScheduleIds as $id) {
            $this->admin()->executeStatement('DELETE FROM schedule WHERE id = :id', ['id' => $id]);
        }
        foreach ($this->seededRunIds as $id) {
            $this->admin()->executeStatement('DELETE FROM match_placement_run WHERE id = :id', ['id' => $id]);
        }
        foreach ($this->seededPlanIds as $id) {
            // La FK schedule.schedule_plan_id est ON DELETE CASCADE : purger le plan emporte sa génération.
            $this->admin()->executeStatement('DELETE FROM schedule_plan WHERE id = :id', ['id' => $id]);
        }
        if (null !== $this->adminId) {
            $this->admin()->executeStatement('DELETE FROM super_admin WHERE id = :id', ['id' => $this->adminId]);
        }
        parent::tearDown();
    }

    private function tickAt(string $now): void
    {
        $registry = self::getContainer()->get(ManagerRegistry::class);
        \assert($registry instanceof ManagerRegistry);
        $mailer = $this->recordingMailer();
        $client = new GitHubDeployClient(new GitHubDeployHttpClientStub, 'github_pat_tick', 'maratech-fr/amateo');

        $command = new DeploymentTickCommand(
            $registry,
            $client,
            $mailer,
            self::getContainer()->get(MailFrom::class),
            self::getContainer()->get(ProductIdentity::class),
            new MockClock(new DateTimeImmutable($now)),
            'https://app.example.test',
        );

        new CommandTester($command)->execute([]);
    }

    private function seedDeployment(string $status, string $scheduledAt, ?int $githubRunId = null, ?string $dispatchNote = null): string
    {
        $id = Uuid::v4()->toRfc4122();
        $scheduled = new DateTimeImmutable($scheduledAt);
        // `dispatch_note` n'est ajouté QUE si demandé : les cas qui ne s'y intéressent pas restent
        // indépendants de la colonne (que NEO ajoute par migration), seuls les cas de note sont rouges.
        $noteColumn = null === $dispatchNote ? '' : ', dispatch_note';
        $noteValue = null === $dispatchNote ? '' : ', :note';
        $params = [
            'id' => $id,
            'version' => 'v1.0.0',
            'scheduled' => $scheduled->format('Y-m-d H:i:sP'),
            'ret' => $scheduled->modify('+60 minutes')->format('Y-m-d H:i:sP'),
            'status' => $status,
            'by' => $this->adminId,
            'dispatched' => 'dispatched' === $status ? $scheduled->modify('+1 minute')->format('Y-m-d H:i:sP') : null,
            'run' => $githubRunId,
        ];
        if (null !== $dispatchNote) {
            $params['note'] = $dispatchNote;
        }
        $this->db->executeStatement(
            'INSERT INTO scheduled_deployment (id, version, scheduled_at, expected_return_at, status, created_by, dispatched_at, github_run_id' . $noteColumn . ', created_at, updated_at)'
            . ' VALUES (:id, :version, :scheduled, :ret, :status, :by, :dispatched, :run' . $noteValue . ', NOW(), NOW())',
            $params,
            ['run' => ParameterType::INTEGER],
        );

        return $id;
    }

    /** Une GÉNÉRATION en cours d'un club TIERS (connexion admin, hors DAMA) : la source du retard. */
    private function seedGeneratingSchedule(): string
    {
        $club = Uuid::v4()->toRfc4122();
        $season = Uuid::v4()->toRfc4122();
        // `schedule.schedule_plan_id` est NOT NULL + FK → un plan minimal d'abord (purgé en cascade).
        $planId = Uuid::v4()->toRfc4122();
        $this->admin()->executeStatement(
            'INSERT INTO schedule_plan (id, created_at, updated_at, club_id, season_id, type, name, start_date, end_date)'
            . ' VALUES (:id, NOW(), NOW(), :club, :season, :type, :name, NOW(), NOW())',
            ['id' => $planId, 'club' => $club, 'season' => $season, 'type' => 'SEASON', 'name' => 'tick plan'],
        );
        $this->seededPlanIds[] = $planId;

        $id = Uuid::v4()->toRfc4122();
        $this->admin()->executeStatement(
            'INSERT INTO schedule (id, created_at, updated_at, club_id, season_id, name, status, solver_seed, snapshot_data, schedule_plan_id, version_number)'
            . ' VALUES (:id, NOW(), NOW(), :club, :season, :name, :status, 0, :snap, :plan, 1)',
            [
                'id' => $id,
                'club' => $club,
                'season' => $season,
                'name' => 'tick activity',
                'status' => ScheduleStatus::GENERATING->value,
                'snap' => '{}',
                'plan' => $planId,
            ],
        );
        $this->seededScheduleIds[] = $id;

        return $id;
    }

    /** Un PLACEMENT de matchs en cours d'un club tiers (connexion admin, hors DAMA). */
    private function seedRunningPlacement(): string
    {
        $id = Uuid::v4()->toRfc4122();
        $this->admin()->executeStatement(
            'INSERT INTO match_placement_run (id, club_id, requested_by_user_id, status, created_at)'
            . ' VALUES (:id, :club, :user, :status, NOW())',
            [
                'id' => $id,
                'club' => Uuid::v4()->toRfc4122(),
                'user' => Uuid::v4()->toRfc4122(),
                'status' => MatchPlacementRunStatus::RUNNING->value,
            ],
        );
        $this->seededRunIds[] = $id;

        return $id;
    }

    private function noteOf(string $id): ?string
    {
        $note = $this->db->fetchOne('SELECT dispatch_note FROM scheduled_deployment WHERE id = :id', ['id' => $id]);

        return \is_string($note) ? $note : null;
    }

    private function statusOf(string $id): string
    {
        return (string) $this->db->fetchOne('SELECT status FROM scheduled_deployment WHERE id = :id', ['id' => $id]);
    }

    /** @return array<string, mixed> */
    private function rowOf(string $id): array
    {
        $row = $this->db->fetchAssociative('SELECT * FROM scheduled_deployment WHERE id = :id', ['id' => $id]);
        self::assertIsArray($row);

        return $row;
    }

    private function recordingMailer(): MailerInterface
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->method('send')->willReturnCallback(function (RawMessage $message): void {
            if ($message instanceof Email) {
                foreach ($message->getTo() as $address) {
                    $this->sentTo[] = $address->getAddress();
                }
                $this->sentBodies[] = (string) $message->getTextBody();
                $this->sentEmails[] = $message;
            }
        });

        return $mailer;
    }

    private function seedSuperAdmin(string $email): void
    {
        $this->adminId = Uuid::v4()->toRfc4122();
        $this->admin()->executeStatement(
            'INSERT INTO super_admin (id, email, password_hash, totp_secret, enabled, created_at) VALUES (:id, :email, :hash, :secret, TRUE, NOW())',
            ['id' => $this->adminId, 'email' => $email, 'hash' => 'x', 'secret' => 'x'],
        );
    }

    private function admin(): Connection
    {
        $connection = self::getContainer()->get(ManagerRegistry::class)->getConnection('admin');
        \assert($connection instanceof Connection);

        return $connection;
    }
}

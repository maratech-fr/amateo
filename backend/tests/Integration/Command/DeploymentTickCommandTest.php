<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Command\DeploymentTickCommand;
use App\Command\HealthAlertCommand;
use App\Enum\MatchPlacementRunStatus;
use App\Enum\ScheduleStatus;
use App\Mail\EmailTemplateRenderer;
use App\Service\DeploymentGate;
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
 *  - le run suivi est SON run : retrouvé par date de création GitHub après la borne persistée
 *    `run_lookup_after` (= création du run précédent), jamais le run d'avant ; introuvable après
 *    10 min → FAILED ; une lecture GitHub en erreur pendant le suivi est réessayée en silence,
 *    bornée (10 min pour la recherche, expected_return_at + 30 min pour le suivi) ;
 *  - le VERDICT du run se lit dans ses jobs et steps (`DeployRunOutcome`) : l'e-mail dit
 *    « hors service » SEULEMENT quand la maintenance a été posée et la bascule a échoué ; prod à jour
 *    avec `release` non recalée ou page de maintenance restée = SUCCEEDED avec réserve ;
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

    /** Heure H des cas de recherche du run (rejoue l'incident du 2026-11-04, 03:00 Paris). */
    private const string H = '2026-11-04T03:00:00+01:00';

    /** Le run `workflow_dispatch` PRÉCÉDENT (créé la veille, conclu en échec) : listé au snapshot. */
    private const int R0 = 41549603515;

    private const string R0_CREATED = '2026-11-03T02:00:03Z';

    /** Le run créé PAR notre dispatch, 3 s après H (horloge GitHub). */
    private const int R1 = 41764290102;

    private const string TEXT_APP_IN_SERVICE = 'La mise en production programmée a échoué avant la bascule : la maintenance n’a pas été posée, l’application est restée en service sur la version précédente.';

    private const string TEXT_RUN_NOT_FOUND = 'Le déploiement a été déclenché, mais son exécution GitHub n’a pas été retrouvée après 10 minutes : vérifiez l’onglet Actions de GitHub avant toute reprogrammation. L’état de la maintenance est inconnu.';

    private const string NOTE_RELEASE_NOT_MOVED = 'Étape annexe en échec : la branche release n’a pas été recalée sur la version déployée. Recalez-la à la main (doc de déploiement, § « Branche `release` et correctif rapide (hotfix) »).';

    private Connection $db;

    /** @var list<string> */
    private array $sentSubjects = [];

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

    /**
     * Préconditions OK → dispatch unique, statut DISPATCHED horodaté. Quel run est rattaché (et
     * quand) est la promesse de {@see self::testADispatchWhoseRunIsNotListedYetStaysDispatchedThenAttachesItOnTheNextTick}.
     */
    public function testPreconditionsMetDispatchesTheWorkflowAndMovesToDispatched(): void
    {
        GitHubDeployHttpClientStub::$tags = [['name' => 'v1.0.0', 'sha' => 'head0000']];
        GitHubDeployHttpClientStub::$ciConclusion = 'success';
        $id = $this->seedDeployment('scheduled', scheduledAt: '2026-10-19T00:59:00+02:00');

        $this->tickAt(self::NOW);

        self::assertSame('dispatched', $this->statusOf($id), 'précondition OK → DISPATCHED');
        self::assertSame(1, GitHubDeployHttpClientStub::$dispatchCount, 'le workflow a été dispatché une fois');
        $row = $this->rowOf($id);
        self::assertNotNull($row['dispatched_at'], 'dispatched_at est horodaté');
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

    /**
     * D2 règle 5 — la bascule a échoué APRÈS la pose de la maintenance (Raise success · SSH deploy
     * failure · Clear skipped) : FAILED, et l'e-mail dit que la maintenance RESTE posée (pas de
     * rollback auto). Seul cas où « hors service » est vrai.
     */
    public function testAFailedRollUnderMaintenanceSaysMaintenanceStays(): void
    {
        GitHubDeployHttpClientStub::$runConclusion = 'failure';
        GitHubDeployHttpClientStub::$jobs = $this->jobs(
            build: 'success',
            roll: 'failure',
            steps: ['Raise the maintenance window' => 'success', 'SSH deploy' => 'failure', 'Clear the maintenance window' => 'skipped'],
            release: 'skipped',
        );
        $id = $this->seedDeployment('dispatched', scheduledAt: '2026-10-19T00:55:00+02:00', githubRunId: 555000111);

        $this->tickAt(self::NOW);

        self::assertSame('failed', $this->statusOf($id), 'bascule ratée → FAILED');
        self::assertContains('🔴 Mise en production en échec', $this->subjectsWithoutProduct());
        self::assertStringContainsString(
            'La mise en production programmée a échoué pendant la bascule : la maintenance reste posée, l’application est hors service tant qu’un rollback manuel n’a pas été fait.',
            implode("\n", $this->sentBodies),
        );
    }

    /**
     * D2 règle 6 — le build a échoué : le job de bascule est `skipped`, la maintenance n'a jamais été
     * posée. FAILED, et l'e-mail dit que l'application est RESTÉE en service (jamais « hors service »).
     */
    public function testABuildFailureSaysTheAppStayedInService(): void
    {
        GitHubDeployHttpClientStub::$runConclusion = 'failure';
        GitHubDeployHttpClientStub::$jobs = $this->jobs(build: 'failure', roll: 'skipped', steps: [], release: 'skipped');
        $id = $this->seedDeployment('dispatched', scheduledAt: '2026-10-19T00:55:00+02:00', githubRunId: 555000111);

        $this->tickAt(self::NOW);

        self::assertSame('failed', $this->statusOf($id));
        self::assertContains('🔴 Mise en production en échec', $this->subjectsWithoutProduct());
        $bodies = implode("\n", $this->sentBodies);
        self::assertStringContainsString(self::TEXT_APP_IN_SERVICE, $bodies);
        self::assertStringNotContainsString('hors service', $bodies, 'un build raté n\'a jamais posé la maintenance');
    }

    /** D2 règle 7 — run annulé avant tout job (jobs vides) : FAILED « restée en service ». */
    public function testARunCancelledBeforeAnyJobSaysTheAppStayedInService(): void
    {
        GitHubDeployHttpClientStub::$runConclusion = 'cancelled';
        GitHubDeployHttpClientStub::$jobs = [];
        $id = $this->seedDeployment('dispatched', scheduledAt: '2026-10-19T00:55:00+02:00', githubRunId: 555000111);

        $this->tickAt(self::NOW);

        self::assertSame('failed', $this->statusOf($id));
        $bodies = implode("\n", $this->sentBodies);
        self::assertStringContainsString(self::TEXT_APP_IN_SERVICE, $bodies);
        self::assertStringNotContainsString('hors service', $bodies);
    }

    /**
     * D2 règle 3 + Q1=a — la prod est à jour mais `release` n'a pas été recalée (job release en
     * échec, cas du run du 2026-11-03) : SUCCEEDED, e-mail 🟢 « une étape annexe a échoué », la
     * réserve est AJOUTÉE à la note existante avec le séparateur « · » (le <p> console ignore \n),
     * et rien ne dit « hors service ».
     */
    public function testAReleaseJobFailureIsASuccessWithAReserve(): void
    {
        GitHubDeployHttpClientStub::$runConclusion = 'failure';
        GitHubDeployHttpClientStub::$jobs = $this->jobs(
            build: 'success',
            roll: 'success',
            steps: ['Raise the maintenance window' => 'success', 'SSH deploy' => 'success', 'Clear the maintenance window' => 'success'],
            release: 'failure',
        );
        $departure = 'Plafond d’attente atteint : déploiement déclenché malgré une activité encore en cours.';
        $id = $this->seedDeployment('dispatched', scheduledAt: '2026-10-19T00:55:00+02:00', githubRunId: 555000111, dispatchNote: $departure);

        $this->tickAt(self::NOW);

        self::assertSame('succeeded', $this->statusOf($id), 'la prod est à jour : le déploiement a RÉUSSI');
        self::assertContains('🟢 Mise en production réussie — une étape annexe a échoué', $this->subjectsWithoutProduct());
        self::assertSame($departure . ' · ' . self::NOTE_RELEASE_NOT_MOVED, $this->noteOf($id), 'la réserve s\'ajoute à la note de départ, séparée par « · »');
        $bodies = implode("\n", $this->sentBodies);
        self::assertStringContainsString('La mise en production programmée a réussi : l’application est de nouveau en service.', $bodies);
        self::assertStringContainsString(self::NOTE_RELEASE_NOT_MOVED, $bodies, 'la réserve est reportée dans l\'e-mail');
        self::assertStringNotContainsString('hors service', $bodies);
    }

    /**
     * D2 règle 4 — SSH deploy a réussi mais « Clear the maintenance window » a échoué : la nouvelle
     * version tourne, seule la page de maintenance est restée. SUCCEEDED, e-mail 🟢 dédié qui dit de
     * retirer maintenance.on et de NE PAS faire de rollback ; jamais « hors service ».
     */
    public function testAFailedClearAfterASuccessfulSshDeployIsASuccessThatSaysToRemoveTheMaintenancePage(): void
    {
        GitHubDeployHttpClientStub::$runConclusion = 'failure';
        GitHubDeployHttpClientStub::$jobs = $this->jobs(
            build: 'success',
            roll: 'failure',
            steps: ['Raise the maintenance window' => 'success', 'SSH deploy' => 'success', 'Clear the maintenance window' => 'failure'],
            release: 'skipped',
        );
        $id = $this->seedDeployment('dispatched', scheduledAt: '2026-10-19T00:55:00+02:00', githubRunId: 555000111);

        $this->tickAt(self::NOW);

        self::assertSame('succeeded', $this->statusOf($id), 'la version déployée est saine : SUCCEEDED');
        self::assertContains('🟢 Mise en production réussie — la page de maintenance est restée affichée', $this->subjectsWithoutProduct());
        $bodies = implode("\n", $this->sentBodies);
        self::assertStringContainsString(
            'La mise en production programmée a réussi : la nouvelle version est en service sur le serveur, mais la page de maintenance n’a pas pu être retirée. Retirez-la à la main (supprimer le fichier maintenance.on, doc de déploiement, § « Maintenance planifiée »). Ne faites pas de rollback : la version déployée est saine.',
            $bodies,
        );
        self::assertStringNotContainsString('hors service', $bodies);
        self::assertStringContainsString(
            'La page de maintenance est restée affichée après un déploiement réussi : retirez le fichier maintenance.on.',
            (string) $this->noteOf($id),
        );
    }

    /**
     * D1 — le run n'est pas encore listé au tick du dispatch : DISPATCHED sans run (jamais le run
     * d'avant), la borne `run_lookup_after` = `created_at` du dernier run AVANT dispatch (horloge
     * GitHub) est persistée ; au tick suivant, le run créé après la borne est rattaché. Zéro e-mail.
     */
    public function testADispatchWhoseRunIsNotListedYetStaysDispatchedThenAttachesItOnTheNextTick(): void
    {
        $id = $this->dispatchAtH();

        $row = $this->rowOf($id);
        self::assertSame('dispatched', $row['status']);
        self::assertNull($row['github_run_id'], 'le run d\'avant n\'est JAMAIS rattaché au nouveau dispatch');
        self::assertArrayHasKey('run_lookup_after', $row, 'colonne run_lookup_after (borne de recherche persistée)');
        self::assertNotNull($row['run_lookup_after']);
        self::assertSame(
            new DateTimeImmutable(self::R0_CREATED)->getTimestamp(),
            new DateTimeImmutable((string) $row['run_lookup_after'])->getTimestamp(),
            'la borne est la date de création du run précédent, lue chez GitHub',
        );

        GitHubDeployHttpClientStub::$deployRuns = [$this->r1(), $this->r0()];
        $this->tickAt('2026-11-04T03:01:00+01:00');

        self::assertSame('dispatched', $this->statusOf($id), 'run rattaché, encore en cours');
        self::assertSame(self::R1, (int) $this->rowOf($id)['github_run_id'], 'le run créé après la borne est rattaché');
        self::assertSame([], $this->sentTo, 'aucun e-mail pendant la recherche du run');
    }

    /**
     * D1 — incident du 2026-11-04 : seul le run PRÉCÉDENT (en échec) est listé pendant plusieurs
     * ticks. Sa conclusion ne décide JAMAIS de l'e-mail : le déploiement reste DISPATCHED, silencieux.
     */
    public function testTheConclusionOfAnOlderRunNeverDecidesTheMail(): void
    {
        GitHubDeployHttpClientStub::$runConclusion = 'failure'; // toute lecture de conclusion dirait « échec »
        $id = $this->dispatchAtH();

        $this->tickAt('2026-11-04T03:01:00+01:00');
        $this->tickAt('2026-11-04T03:02:00+01:00');

        self::assertSame('dispatched', $this->statusOf($id));
        self::assertNull($this->rowOf($id)['github_run_id']);
        self::assertSame([], $this->sentTo, 'la conclusion du run d\'avant ne produit aucun e-mail');
    }

    /** D1-bis — deux runs créés après la borne : le plus ANCIEN est retenu, et la note le dit. */
    public function testTwoRunsAfterTheDispatchKeepTheEarliestAndSayIt(): void
    {
        $id = $this->dispatchAtH();

        GitHubDeployHttpClientStub::$deployRuns = [
            ['id' => 41764299999, 'created_at' => '2026-11-04T02:00:20Z', 'conclusion' => null],
            $this->r1(),
            $this->r0(),
        ];
        $this->tickAt('2026-11-04T03:01:00+01:00');

        self::assertSame(self::R1, (int) $this->rowOf($id)['github_run_id'], 'le premier run après la borne est retenu');
        self::assertStringContainsString(
            'Plusieurs exécutions GitHub ont démarré après le déclenchement : la première a été retenue. Vérifiez l’onglet Actions.',
            (string) $this->noteOf($id),
        );
    }

    /**
     * D1 — le snapshot du run précédent vit dans le bloc des préconditions : une erreur GitHub
     * sur cette lecture est RÉESSAYÉE comme une précondition illisible — reste `scheduled`, zéro
     * dispatch, zéro e-mail, la commande ne plante pas.
     */
    public function testAGitHubErrorOnTheRunSnapshotIsRetriedLikeAnyPreconditionRead(): void
    {
        GitHubDeployHttpClientStub::$tags = [['name' => 'v1.0.0', 'sha' => 'head0000']];
        GitHubDeployHttpClientStub::$deployRunsFailStatus = 502;
        $id = $this->seedDeployment('scheduled', scheduledAt: self::H);

        $exit = $this->tickAt(self::H);

        self::assertSame(0, $exit, 'le tick ne plante pas');
        self::assertSame('scheduled', $this->statusOf($id), 'snapshot illisible → réessai au tick suivant');
        self::assertSame(0, GitHubDeployHttpClientStub::$dispatchCount, 'aucun dispatch sans snapshot du run précédent');
        self::assertSame([], $this->sentTo);
    }

    /**
     * D1-ter + Q4=a — run toujours introuvable 10 min après le dispatch : FAILED, e-mail « n’a pas
     * été retrouvée après 10 minutes », rien sur « hors service » ; avant la borne le gel reste
     * posé, après il est LEVÉ.
     */
    public function testARunStillUnknownTenMinutesAfterDispatchFails(): void
    {
        $id = $this->dispatchAtH();

        $this->tickAt('2026-11-04T03:09:00+01:00');
        self::assertSame('dispatched', $this->statusOf($id), 'avant 10 min, on cherche encore');
        self::assertSame([], $this->sentTo);
        self::assertTrue($this->gateAt('2026-11-04T03:09:00+01:00')->isFrozen(), 'tant qu\'on cherche, les guichets restent gelés');

        $this->tickAt('2026-11-04T03:11:00+01:00');
        self::assertSame('failed', $this->statusOf($id));
        $bodies = implode("\n", $this->sentBodies);
        self::assertStringContainsString(self::TEXT_RUN_NOT_FOUND, $bodies);
        self::assertStringNotContainsString('hors service', $bodies);
        self::assertFalse($this->gateAt('2026-11-04T03:11:00+01:00')->isFrozen(), 'Q4=a : le gel est levé à l\'échec « introuvable »');
    }

    /**
     * D8 — une ERREUR GitHub pendant la recherche du run est réessayée en silence ; la borne de cette
     * phase reste RUN_LOOKUP_MINUTES (10) depuis le dispatch : passé ce délai, FAILED « introuvable ».
     */
    public function testAGitHubErrorWhileLookingForTheRunIsRetriedThenFailsAtTenMinutes(): void
    {
        $id = $this->dispatchAtH();
        GitHubDeployHttpClientStub::$deployRunsFailStatus = 503;

        $exit = $this->tickAt('2026-11-04T03:05:00+01:00');
        self::assertSame(0, $exit, 'le tick ne plante pas');
        self::assertSame('dispatched', $this->statusOf($id), 'erreur de lecture → réessai silencieux');
        self::assertSame([], $this->sentTo);

        $this->tickAt('2026-11-04T03:11:00+01:00');
        self::assertSame('failed', $this->statusOf($id));
        self::assertStringContainsString(self::TEXT_RUN_NOT_FOUND, implode("\n", $this->sentBodies));
    }

    /**
     * D8 — run conclu `success` mais jobs illisibles : silence jusqu'à expected_return_at + 30 min,
     * puis SUCCEEDED « détail non vérifié » — jamais « a échoué ».
     */
    public function testUnreadableJobsOnASuccessfulRunEndAsASuccessNotVerifiedAfterTheGrace(): void
    {
        GitHubDeployHttpClientStub::$runConclusion = 'success';
        GitHubDeployHttpClientStub::$jobsFailStatus = 500;
        // expected_return_at = 01:55 (scheduledAt + 60) → borne 02:25.
        $id = $this->seedDeployment('dispatched', scheduledAt: '2026-10-19T00:55:00+02:00', githubRunId: 555000111);

        $this->tickAt('2026-10-19T02:00:00+02:00');
        self::assertSame('dispatched', $this->statusOf($id), 'avant la borne, jobs illisibles → réessai silencieux');
        self::assertSame([], $this->sentTo);

        $this->tickAt('2026-10-19T02:26:00+02:00');
        self::assertSame('succeeded', $this->statusOf($id));
        self::assertContains('🟢 Mise en production réussie — détail non vérifié', $this->subjectsWithoutProduct());
        $bodies = implode("\n", $this->sentBodies);
        self::assertStringContainsString(
            'La mise en production programmée a réussi d’après GitHub, mais le détail des étapes n’a pas pu être lu : vérifiez dans l’onglet Actions que la branche release a bien été recalée.',
            $bodies,
        );
        self::assertStringNotContainsString('a échoué', $bodies);
    }

    /** D8 — run conclu en échec mais jobs illisibles : silence jusqu'à la borne, puis FAILED indéterminé. */
    public function testUnreadableJobsOnAFailedRunEndUndeterminedAfterTheGrace(): void
    {
        GitHubDeployHttpClientStub::$runConclusion = 'failure';
        GitHubDeployHttpClientStub::$jobsFailStatus = 500;
        $id = $this->seedDeployment('dispatched', scheduledAt: '2026-10-19T00:55:00+02:00', githubRunId: 555000111);

        $this->tickAt('2026-10-19T02:00:00+02:00');
        self::assertSame('dispatched', $this->statusOf($id));
        self::assertSame([], $this->sentTo);

        $this->tickAt('2026-10-19T02:26:00+02:00');
        self::assertSame('failed', $this->statusOf($id));
        $bodies = implode("\n", $this->sentBodies);
        self::assertStringContainsString(
            'La mise en production programmée a échoué. L’état de la maintenance n’a pas pu être déterminé : vérifiez l’onglet Actions de GitHub avant tout geste.',
            $bodies,
        );
        self::assertStringNotContainsString('hors service', $bodies);
    }

    /**
     * D8 + Q4=a — run figé `in_progress` (conclusion nulle) : avant expected_return_at + 30 il reste
     * DISPATCHED et les guichets gelés ; après, FAILED « n’a pas rendu de verdict » et gel LEVÉ.
     */
    public function testARunStillInProgressPastTheGraceIsUndeterminedAndSaysSo(): void
    {
        GitHubDeployHttpClientStub::$runStatus = 'in_progress';
        $id = $this->seedDeployment('dispatched', scheduledAt: '2026-10-19T00:55:00+02:00', githubRunId: 555000111);

        $this->tickAt('2026-10-19T02:20:00+02:00');
        self::assertSame('dispatched', $this->statusOf($id));
        self::assertSame([], $this->sentTo);
        self::assertTrue($this->gateAt('2026-10-19T02:20:00+02:00')->isFrozen(), 'avant la borne, le gel reste posé');

        $this->tickAt('2026-10-19T02:26:00+02:00');
        self::assertSame('failed', $this->statusOf($id));
        self::assertStringContainsString(
            'Le déploiement n’a pas rendu de verdict dans le délai prévu : son exécution GitHub est toujours en cours ou bloquée. L’état de la maintenance est inconnu : vérifiez l’onglet Actions de GitHub avant toute reprogrammation.',
            implode("\n", $this->sentBodies),
        );
        self::assertFalse($this->gateAt('2026-10-19T02:26:00+02:00')->isFrozen(), 'Q4=a : gel levé');
    }

    /**
     * D8 — GitHub injoignable pendant le suivi d'un run rattaché : le tick ne plante pas, le
     * déploiement reste DISPATCHED, aucun e-mail (réessai au tick suivant).
     */
    public function testAGitHubOutageWhilePollingLeavesTheDeploymentDispatchedAndSilent(): void
    {
        GitHubDeployHttpClientStub::$transportFails = true;
        $id = $this->seedDeployment('dispatched', scheduledAt: '2026-10-19T00:55:00+02:00', githubRunId: 555000111);

        $exit = $this->tickAt(self::NOW);

        self::assertSame(0, $exit, 'le tick ne plante pas (avant : GitHubDeployException non rattrapée)');
        self::assertSame('dispatched', $this->statusOf($id));
        self::assertSame([], $this->sentTo);
    }

    /**
     * D8 — la conclusion n'a jamais pu être lue (GitHub injoignable) jusqu'à expected_return_at +
     * 30 min : FAILED indéterminé, l'e-mail renvoie à l'onglet Actions sans rien affirmer de la
     * maintenance. Décision du plan sans test nommé au tableau v3 : ajoutée.
     */
    public function testAConclusionNeverReadPastTheGraceIsUndetermined(): void
    {
        GitHubDeployHttpClientStub::$transportFails = true;
        $id = $this->seedDeployment('dispatched', scheduledAt: '2026-10-19T00:55:00+02:00', githubRunId: 555000111);

        $exit = $this->tickAt('2026-10-19T02:26:00+02:00');

        self::assertSame(0, $exit);
        self::assertSame('failed', $this->statusOf($id));
        $bodies = implode("\n", $this->sentBodies);
        self::assertStringContainsString('L’état de la maintenance', $bodies);
        self::assertStringContainsString('vérifiez l’onglet Actions de GitHub avant', $bodies);
        self::assertStringNotContainsString('hors service', $bodies);
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
        $this->sentSubjects = [];
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

    /**
     * À H, préconditions vertes, seul le run PRÉCÉDENT R0 est listé : le tick dispatche. Rend l'id
     * du déploiement, DISPATCHED.
     */
    private function dispatchAtH(): string
    {
        GitHubDeployHttpClientStub::$tags = [['name' => 'v1.0.0', 'sha' => 'head0000']];
        GitHubDeployHttpClientStub::$ciConclusion = 'success';
        GitHubDeployHttpClientStub::$deployRuns = [$this->r0()];
        $id = $this->seedDeployment('scheduled', scheduledAt: self::H);

        $this->tickAt(self::H);

        self::assertSame(1, GitHubDeployHttpClientStub::$dispatchCount, 'le workflow a été dispatché à H');
        self::assertSame('dispatched', $this->statusOf($id));
        self::assertSame([], $this->sentTo, 'un dispatch ne mail pas');

        return $id;
    }

    /** @return array{id: int, created_at: string, conclusion: ?string} */
    private function r0(): array
    {
        return ['id' => self::R0, 'created_at' => self::R0_CREATED, 'conclusion' => 'failure'];
    }

    /** @return array{id: int, created_at: string, conclusion: ?string} */
    private function r1(): array
    {
        return ['id' => self::R1, 'created_at' => '2026-11-04T02:00:03Z', 'conclusion' => null];
    }

    /**
     * Les jobs d'un run tels que GitHub les rend : build, bascule (nom évalué) avec ses steps,
     * recalage de `release`.
     *
     * @param array<string, string> $steps nom du step => conclusion
     *
     * @return list<array{name: string, conclusion: ?string, steps: list<array{name: string, conclusion: ?string}>}>
     */
    private function jobs(string $build, string $roll, array $steps, string $release): array
    {
        $rollSteps = [];
        foreach ($steps as $name => $conclusion) {
            $rollSteps[] = ['name' => $name, 'conclusion' => $conclusion];
        }

        return [
            ['name' => 'Build & push prod images', 'conclusion' => $build, 'steps' => []],
            ['name' => 'Roll the VM to v1.0.0', 'conclusion' => $roll, 'steps' => $rollSteps],
            ['name' => 'Fast-forward `release` to the deployed commit', 'conclusion' => $release, 'steps' => []],
        ];
    }

    /** Le gel des guichets vu à l'instant donné (horloge figée). */
    private function gateAt(string $now): DeploymentGate
    {
        $registry = self::getContainer()->get(ManagerRegistry::class);
        \assert($registry instanceof ManagerRegistry);

        return new DeploymentGate($registry, new MockClock(new DateTimeImmutable($now)));
    }

    /**
     * Les sujets des e-mails captés, préfixe « {Produit} — » retiré.
     *
     * @return list<string>
     */
    private function subjectsWithoutProduct(): array
    {
        $productIdentity = self::getContainer()->get(ProductIdentity::class);
        \assert($productIdentity instanceof ProductIdentity);
        $prefix = $productIdentity->name() . ' — ';

        return array_map(
            static fn (string $subject): string => str_starts_with($subject, $prefix) ? substr($subject, \strlen($prefix)) : $subject,
            $this->sentSubjects,
        );
    }

    private function tickAt(string $now): int
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

        return new CommandTester($command)->execute([]);
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
                $this->sentSubjects[] = (string) $message->getSubject();
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

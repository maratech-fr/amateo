<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\AdminJob\AdminJobCatalog;
use App\Storage\FeedbackScreenshotStorage;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Uid\Uuid;

/**
 * Chantier F PR4 — la RÉTENTION du canal de signalement (RGPD), portée par
 * `app:feedback:purge` (cron-runner, inscrit au catalogue des jobs d'exploitation).
 *
 * Deux promesses, une seule commande (horloge applicative, `ClockInterface`) :
 *  - le TEXTE (message) et le CONTEXTE d'un signalement traité sont purgés 30 jours
 *    après « traité » (`treated_at`), mais la LIGNE SURVIT (stats QoS intactes :
 *    topic / status / dates conservés) avec un marqueur `purged_at` ;
 *  - la CAPTURE (blob hors table) est purgée au PREMIER des deux : J+90 après le
 *    dépôt (`created_at`) OU J+30 après « traité ». La parade de contournement :
 *    un `untreat` (retour `treated_at` NULL) ne prolonge JAMAIS au-delà de J+90 et
 *    ne ressuscite rien.
 *
 * `feedback` est FORCE RLS : on sème et on lit par la connexion `admin` (hors DAMA).
 * On ouvre une transaction dessus, on vide la table pour un décor déterministe, et on
 * `rollBack` au teardown — rien de committé ne fuit (patron FeedbackDigestCommandTest).
 * Les captures stockées sur disque sont nettoyées explicitement au teardown.
 *
 * ⚠ Le temps est contrôlé en DATANT les lignes (relativement à « maintenant » que la
 * commande lit via l'horloge applicative), jamais par un FLUSHALL ni un reset global —
 * patron DemoPurgeStaleCommandTest.
 */
#[Group('integration')]
final class FeedbackPurgeCommandTest extends KernelTestCase
{
    private Connection $admin;

    private FeedbackScreenshotStorage $screenshots;

    private CommandTester $tester;

    private bool $inTransaction = false;

    /** @var list<string> captures stockées à nettoyer */
    private array $storedScreenshotIds = [];

    /**
     * PROMESSE : 30 jours après « traité », le MESSAGE et le CONTEXTE sont détruits, mais
     * la ligne survit — `purged_at` posé, `topic`/`status`/`created_at`/`treated_at` intacts
     * (les stats QoS continuent de compter ce signalement).
     *
     * Falsifiable : sans purge, le message « secret historique » reste en base (ROUGE).
     */
    public function testTreatedTextAndContextArePurgedAfterThirtyDays(): void
    {
        $createdAt = $this->isoAgo('40 days');
        $treatedAt = $this->isoAgo('31 days');
        $id = $this->seedFeedback(
            status: 'treated',
            createdAt: $createdAt,
            treatedAt: $treatedAt,
            message: 'secret historique à oublier',
            context: ['screen' => 'planning', 'snapshot' => ['weeks' => [1, 2]]],
        );

        self::assertSame(Command::SUCCESS, $this->tester->execute([]), $this->tester->getDisplay());

        $row = $this->rowOf($id);
        self::assertSame('', $row['message'], 'le message est purgé (texte détruit)');
        self::assertNull($row['context'], 'le contexte est purgé');
        self::assertNotNull($row['purged_at'], 'un marqueur de purge est posé');
        // La ligne survit à l'identique pour les stats QoS.
        self::assertSame('treated', $row['status'], 'le statut survit (QoS)');
        self::assertSame('bug', $row['topic'], 'le topic survit (QoS)');
        self::assertNotNull($row['treated_at'], 'la date de traitement survit (QoS)');
    }

    /**
     * Témoin (anti-vide) : AVANT J+30 après traité, le texte RESTE. Sans ce témoin, « le texte
     * est purgé » serait vrai d'une table vidée en entier — la borne de 30 jours doit tenir.
     */
    public function testTreatedTextSurvivesBeforeThirtyDays(): void
    {
        $id = $this->seedFeedback(status: 'treated', createdAt: $this->isoAgo('20 days'), treatedAt: $this->isoAgo('10 days'), message: 'encore frais, à garder');

        self::assertSame(Command::SUCCESS, $this->tester->execute([]), $this->tester->getDisplay());

        $row = $this->rowOf($id);
        self::assertSame('encore frais, à garder', $row['message'], 'avant J+30, le texte reste intact');
        self::assertNull($row['purged_at'], 'et aucun marqueur de purge');
    }

    /**
     * PROMESSE : la capture d'un signalement traité est détruite 30 jours après « traité »
     * (branche « treated+30 », atteinte avant J+90 du dépôt). Le blob part du stockage, le
     * pointeur `has_screenshot` repasse à faux.
     *
     * Falsifiable : sans purge, le blob reste lisible et `has_screenshot` reste vrai (ROUGE).
     */
    public function testTreatedScreenshotIsGoneAfterThirtyDays(): void
    {
        $id = $this->seedFeedback(status: 'treated', createdAt: $this->isoAgo('40 days'), treatedAt: $this->isoAgo('31 days'), hasScreenshot: true);
        $this->storeScreenshot($id);

        self::assertSame(Command::SUCCESS, $this->tester->execute([]), $this->tester->getDisplay());

        self::assertNull($this->screenshots->read($id), 'la capture est détruite 30 j après traité');
        self::assertFalse((bool) $this->rowOf($id)['has_screenshot'], 'le pointeur has_screenshot repasse à faux');
    }

    /**
     * ABUS NOMMÉ (contournement de rétention) : un signalement DÉPOSÉ il y a > 90 jours puis
     * remis en non-traité (`treated_at` NULL) ne doit PAS voir sa capture survivre — la branche
     * « created+90 » la détruit quel que soit le statut. Un `untreat` ne prolonge JAMAIS la
     * rétention au-delà de J+90.
     *
     * Falsifiable deux fois : (a) sans la branche created+90, le blob du signalement non-traité
     * à 91 j survit (ROUGE) ; (b) le témoin à 80 j prouve que ce n'est pas « tout blob non-traité »
     * qui part, mais bien la borne des 90 jours.
     */
    public function testUntreatDoesNotExtendRetentionPastNinetyDays(): void
    {
        // Déposé il y a 91 j, remis en non-traité (treated_at NULL) : capture à détruire.
        $old = $this->seedFeedback(status: 'untreated', createdAt: $this->isoAgo('91 days'), treatedAt: null, hasScreenshot: true);
        $this->storeScreenshot($old);
        // Témoin : déposé il y a 80 j, non-traité : AVANT J+90, la capture survit.
        $recent = $this->seedFeedback(status: 'untreated', createdAt: $this->isoAgo('80 days'), treatedAt: null, hasScreenshot: true);
        $this->storeScreenshot($recent);

        self::assertSame(Command::SUCCESS, $this->tester->execute([]), $this->tester->getDisplay());

        self::assertNull($this->screenshots->read($old), 'un untreat ne prolonge pas la rétention au-delà de J+90');
        self::assertFalse((bool) $this->rowOf($old)['has_screenshot']);
        self::assertNotNull($this->screenshots->read($recent), 'à 80 j, la capture survit (borne des 90 jours, pas « tout non-traité »)');
    }

    /**
     * PROMESSE (idempotence + anti-résurrection) : rejouer la purge sur une ligne DÉJÀ purgée ne
     * ré-écrit rien, et un `untreat` (statut non-traité sur une ligne purgée) ne ressuscite jamais
     * le texte détruit.
     *
     * Falsifiable : une purge qui ne filtrerait pas `purged_at IS NULL` ré-estamperait la date de
     * purge à chaque run (date qui bouge) — ici elle est figée.
     */
    public function testPurgeIsIdempotentAndUntreatNeverResurrectsPurgedText(): void
    {
        $purgedAt = $this->isoAgo('5 days');
        // Déjà purgée (message vide, purged_at posé) ET remise en non-traité.
        $id = $this->seedFeedback(status: 'untreated', createdAt: $this->isoAgo('100 days'), treatedAt: null, message: '', purgedAt: $purgedAt);

        self::assertSame(Command::SUCCESS, $this->tester->execute([]), $this->tester->getDisplay());

        $row = $this->rowOf($id);
        self::assertSame('', $row['message'], 'le texte purgé n\'est jamais ressuscité');
        self::assertSame(
            new DateTimeImmutable($purgedAt)->format('Y-m-d'),
            new DateTimeImmutable((string) $row['purged_at'])->format('Y-m-d'),
            'la date de purge ne bouge pas (purge idempotente)',
        );
    }

    /**
     * PROMESSE (décision fondateur #3) : la purge est une commande INSCRITE au catalogue des jobs
     * d'exploitation, planifiée quotidiennement — pas un script oublié hors catalogue.
     *
     * Falsifiable : tant que la clé `purge-feedback` n'est pas au catalogue, find() rend null (ROUGE).
     */
    public function testThePurgeIsRegisteredInTheAdminJobCatalog(): void
    {
        $catalog = self::getContainer()->get(AdminJobCatalog::class);
        self::assertInstanceOf(AdminJobCatalog::class, $catalog);

        $definition = $catalog->find('purge-feedback');
        self::assertNotNull($definition, 'la purge des signalements est inscrite au catalogue des jobs');
        self::assertSame('app:feedback:purge', $definition->command);
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $registry = self::getContainer()->get(ManagerRegistry::class);
        \assert($registry instanceof ManagerRegistry);
        $connection = $registry->getConnection('admin');
        \assert($connection instanceof Connection);
        $this->admin = $connection;
        $this->admin->beginTransaction();
        $this->inTransaction = true;
        $this->admin->executeStatement('DELETE FROM feedback');

        $storage = self::getContainer()->get(FeedbackScreenshotStorage::class);
        \assert($storage instanceof FeedbackScreenshotStorage);
        $this->screenshots = $storage;

        $this->tester = new CommandTester(new Application(self::$kernel)->find('app:feedback:purge'));
    }

    protected function tearDown(): void
    {
        foreach ($this->storedScreenshotIds as $id) {
            $this->screenshots->delete($id);
        }
        if ($this->inTransaction) {
            $this->admin->rollBack();
            $this->inTransaction = false;
        }
        parent::tearDown();
    }

    /** « il y a N » exprimé en ISO 8601 avec fuseau — relatif au « maintenant » que lit la commande. */
    private function isoAgo(string $interval): string
    {
        return new DateTimeImmutable('now')->modify('-' . $interval)->format(DateTimeImmutable::ATOM);
    }

    /** @param array<string, mixed>|null $context */
    private function seedFeedback(
        string $status,
        string $createdAt,
        ?string $treatedAt,
        string $message = 'message du signalement',
        ?array $context = null,
        bool $hasScreenshot = false,
        ?string $purgedAt = null,
    ): string {
        $id = Uuid::v4()->toRfc4122();
        $this->admin->executeStatement(
            'INSERT INTO feedback (id, version, club_id, user_id, topic, message, context, status, treated_at, created_at, has_screenshot, purged_at)'
            . ' VALUES (:id, 1, :club, :user, :topic, :message, :context, :status, :treated, :created, :shot, :purged)',
            [
                'id' => $id,
                'club' => Uuid::v4()->toRfc4122(),
                'user' => Uuid::v4()->toRfc4122(),
                'topic' => 'bug',
                'message' => $message,
                'context' => null === $context ? null : json_encode($context, \JSON_THROW_ON_ERROR),
                'status' => $status,
                'treated' => $treatedAt,
                'created' => $createdAt,
                'shot' => $hasScreenshot,
                'purged' => $purgedAt,
            ],
            ['shot' => ParameterType::BOOLEAN],
        );

        return $id;
    }

    private function storeScreenshot(string $id): void
    {
        $this->screenshots->store($id, 'capture-' . $id);
        $this->storedScreenshotIds[] = $id;
    }

    /** @return array<string, mixed> */
    private function rowOf(string $id): array
    {
        $row = $this->admin->fetchAssociative(
            'SELECT message, context, status, topic, treated_at, created_at, has_screenshot, purged_at FROM feedback WHERE id = :id',
            ['id' => $id],
        );
        self::assertIsArray($row, 'le signalement survit (la ligne n\'est jamais supprimée par la purge)');

        return $row;
    }
}

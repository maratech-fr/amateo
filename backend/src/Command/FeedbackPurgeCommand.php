<?php

declare(strict_types=1);

namespace App\Command;

use App\Storage\FeedbackScreenshotStorage;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Chantier F PR4 — la RÉTENTION du canal de signalement (RGPD), inscrite au catalogue des
 * jobs d'exploitation (daily). Deux promesses, une seule commande, horloge applicative :
 *
 *  1. TEXTE + CONTEXTE d'un signalement TRAITÉ sont détruits 30 jours après « traité »
 *     (`treated_at`). La LIGNE survit — `purged_at` posé, `topic`/`status`/dates conservés
 *     (les stats QoS continuent de compter ce signalement). Filtre `purged_at IS NULL` →
 *     purge idempotente, un `untreat` ne ressuscite jamais un texte détruit.
 *  2. La CAPTURE (blob hors table, {@see FeedbackScreenshotStorage}) part au PREMIER des
 *     deux : J+90 après le dépôt (`created_at`) OU J+30 après « traité ». Un `untreat`
 *     (retour `treated_at` NULL) ne prolonge JAMAIS la rétention au-delà de J+90 — la
 *     branche created+90 détruit la capture quel que soit le statut.
 *
 * Lecture CROSS-TENANT : `feedback` est FORCE RLS, l'amateo_app sans GUC ne verrait rien —
 * on écrit par la connexion `admin` (porte admin_all), en SQL paramétré (patron
 * FeedbackDigestCommand / PurgeAuditLogCommand).
 */
#[AsCommand(
    name: 'app:feedback:purge',
    description: 'Purge expired feedback content: treated text/context after 30 days (row kept), and screenshots after created+90 or treated+30 days. Runs daily (cron-runner).',
)]
final class FeedbackPurgeCommand extends Command
{
    /** Jours après « traité » au-delà desquels le texte et le contexte d'un signalement traité sont détruits. */
    private const int TEXT_RETENTION_DAYS = 30;

    /** Jours après le dépôt au-delà desquels la capture part, quel que soit le statut (borne haute, anti-contournement). */
    private const int SCREENSHOT_CREATED_RETENTION_DAYS = 90;

    /** Jours après « traité » au-delà desquels la capture d'un signalement traité part. */
    private const int SCREENSHOT_TREATED_RETENTION_DAYS = 30;

    public function __construct(
        private readonly ManagerRegistry $registry,
        private readonly FeedbackScreenshotStorage $screenshots,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $now = DateTimeImmutable::createFromInterface($this->clock->now());
        $connection = $this->connection();

        $purgedText = $this->purgeTreatedText($connection, $now);
        $purgedScreenshots = $this->purgeScreenshots($connection, $now);

        $io->success(\sprintf(
            'Purge des signalements : %d texte(s)/contexte(s) détruit(s), %d capture(s) supprimée(s).',
            $purgedText,
            $purgedScreenshots,
        ));

        return Command::SUCCESS;
    }

    /**
     * TEXTE + CONTEXTE des signalements traités au-delà de la rétention, LIGNE conservée.
     * `purged_at IS NULL` rend la purge idempotente : une ligne déjà purgée n'est jamais
     * ré-estampée (date figée), et un `untreat` ne ressuscite rien (le texte reste vide).
     */
    private function purgeTreatedText(Connection $connection, DateTimeImmutable $now): int
    {
        $cutoff = $now->modify(\sprintf('-%d days', self::TEXT_RETENTION_DAYS));

        return (int) $connection->executeStatement(
            <<<'SQL'
                UPDATE feedback
                SET message = '', context = NULL, purged_at = :now, version = version + 1
                WHERE status = :treated AND treated_at < :cutoff AND purged_at IS NULL
                SQL,
            [
                'now' => $now->format(DateTimeImmutable::ATOM),
                'treated' => 'treated',
                'cutoff' => $cutoff->format(DateTimeImmutable::ATOM),
            ],
        );
    }

    /**
     * CAPTURES au-delà de la rétention : J+90 après le dépôt OU J+30 après « traité ». Le blob
     * vit hors table → on détruit chaque blob par id, puis on remet `has_screenshot` à faux
     * (idempotent : une ligne déjà à faux ne re-matche jamais).
     */
    private function purgeScreenshots(Connection $connection, DateTimeImmutable $now): int
    {
        $createdCutoff = $now->modify(\sprintf('-%d days', self::SCREENSHOT_CREATED_RETENTION_DAYS));
        $treatedCutoff = $now->modify(\sprintf('-%d days', self::SCREENSHOT_TREATED_RETENTION_DAYS));

        /** @var list<string> $ids */
        $ids = $connection->fetchFirstColumn(
            <<<'SQL'
                SELECT id FROM feedback
                WHERE has_screenshot = TRUE
                  AND (created_at < :createdCutoff OR (treated_at IS NOT NULL AND treated_at < :treatedCutoff))
                SQL,
            [
                'createdCutoff' => $createdCutoff->format(DateTimeImmutable::ATOM),
                'treatedCutoff' => $treatedCutoff->format(DateTimeImmutable::ATOM),
            ],
        );

        foreach ($ids as $id) {
            $this->screenshots->delete($id);
            $connection->executeStatement(
                'UPDATE feedback SET has_screenshot = :no, version = version + 1 WHERE id = :id',
                ['no' => false, 'id' => $id],
                ['no' => ParameterType::BOOLEAN],
            );
        }

        return \count($ids);
    }

    private function connection(): Connection
    {
        $connection = $this->registry->getConnection('admin');
        \assert($connection instanceof Connection);

        return $connection;
    }
}

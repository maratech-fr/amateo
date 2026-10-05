<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\ResetDemoBcclMessage;
use App\Service\ClubMailboxPurgerInterface;
use App\Service\DemoResetRunnerInterface;
use App\Service\DemoResetTracker;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Throwable;

/**
 * Le reset ASYNCHRONE de la démo BCCL (BCK-35, patron {@see PlaceMatchesHandler}).
 *
 * Trois garanties du patron :
 *  - **Issue terminale TOUJOURS posée** (try/finally) : succès ⇒ statut `succeeded` +
 *    horloge simulée remise à null + boîte aux lettres vidée ; échec ⇒ statut `failed`.
 *    La console ne reste jamais figée sur « en cours » (le verrou a aussi son TTL).
 *  - **Verrou pris par le contrôleur, RENDU ici** — `DemoResetTracker::finish()` pose
 *    l'issue puis relâche le verrou par compare-and-delete du token porté par le message.
 *  - **Connexion ADMIN (amateo_owner, hors RLS)** : aucune requête HTTP dans le worker,
 *    donc aucun GUC posé ; la résolution du club démo + le vidage de boîte traversent la
 *    frontière tenant par cette connexion (comme le contrôleur le faisait avant).
 *
 * L'exception est ACQUITTÉE (jamais rejouée) : un reset échoué est un fait d'exploitation,
 * pas un message à rejouer — relancer referait tourner un seed de ~600 s pour échouer pareil.
 */
#[AsMessageHandler]
final class ResetDemoBcclHandler
{
    public function __construct(
        private readonly DemoResetRunnerInterface $resetRunner,
        private readonly DemoResetTracker $tracker,
        private readonly ManagerRegistry $managerRegistry,
        private readonly ClubMailboxPurgerInterface $mailboxPurger,
        #[Autowire(param: 'app.demo_bccl_email')]
        private readonly string $bcclEmail,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function __invoke(ResetDemoBcclMessage $message): void
    {
        $succeeded = false;
        try {
            // Purge + re-seed du club BCCL (sous-processus `app:demo:seed`). Décision
            // fondateur : au succès, la date simulée revient à aujourd'hui et la boîte aux
            // lettres interceptée pendant la démo précédente est vidée.
            $this->resetRunner->run();
            $this->clearClockAndMailbox();
            $succeeded = true;
        } catch (Throwable $exception) {
            $this->logger?->error('Réinitialisation de la démo BCCL échouée', ['exception' => $exception]);
        } finally {
            // Issue terminale posée + verrou relâché, quoi qu'il arrive.
            $this->tracker->finish($message->getLockToken(), $succeeded);
        }
    }

    /**
     * Remet `simulated_today` à null sur le club démo BCCL et vide sa boîte aux lettres.
     * Club résolu SERVEUR depuis l'adhésion du compte `demo-bccl@` (jamais depuis le
     * message), filtré `is_demo = TRUE` — un vrai club ne passe jamais par ce chemin.
     */
    private function clearClockAndMailbox(): void
    {
        $email = strtolower($this->bcclEmail);
        $connection = $this->connection();
        $clubId = $connection->fetchOne(
            'SELECT c.id FROM club c'
            . ' JOIN club_user cu ON cu.club_id = c.id'
            . ' JOIN app_user u ON u.id = cu.user_id'
            . ' WHERE u.email = :email AND c.is_demo = TRUE AND cu.deactivated_at IS NULL'
            . ' ORDER BY c.created_at DESC LIMIT 1',
            ['email' => $email],
        );
        if (!\is_string($clubId)) {
            return;
        }

        $connection->executeStatement(
            'UPDATE club SET simulated_today = NULL WHERE id = :id AND is_demo = TRUE',
            ['id' => $clubId],
        );
        $this->mailboxPurger->purge($clubId);
    }

    private function connection(): Connection
    {
        $connection = $this->managerRegistry->getConnection('admin');
        \assert($connection instanceof Connection);

        return $connection;
    }
}
